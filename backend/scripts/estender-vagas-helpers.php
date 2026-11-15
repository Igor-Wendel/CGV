<?php
// Extensao diaria de vaga em aberto, compartilhada por estender-solicitacoes-desligamento.php
// e estender-solicitacoes-afastamento.php.
//
// Vaga sem fim_definido_em "cresce": a cada dia sem Fim definido (automatico ou manual),
// adiciona em solicitacoes_cobertura as linhas que faltam ate hoje (mesmos dados da linha
// original, sem matricula_vol), repondo os dias perdidos se a rotina ficou parada. Isso faz
// a coluna FIM mostrar "-" e a solicitacao aparecer "em aberto ha X dias". Com
// fim_definido_em a vaga para de crescer e o fechamento automatico
// (fechar-solicitacoes-vencidas.php) conta os 3 dias a partir da ultima linha.
//
// O centro de custo fica o da abertura, de proposito: cada linha nova copia
// filial/centro_custo/nome/funcao da linha ancora. A vaga pertence ao posto, nao a pessoa;
// se o titular for transferido, a vaga nao pode sumir do Preposto que a abriu. So o retrato
// de salario/adicionais e relido a cada dia (snapshotTitularSolicitacao; fora do cadastro, o da ancora).
//
// $tabelaVaga/$motivo/$chaveSync vem de constantes dos 2 scripts chamadores, nunca de
// entrada externa - por isso $tabelaVaga pode ir direto no SQL.

require_once __DIR__ . '/../api/db.php';

function estenderVagasEmAberto(string $tabelaVaga, string $motivo, string $chaveSync, string $rotulo): void {
    $log = fn(string $msg) => fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n");

    $log("Iniciando extensao diaria de vagas de $rotulo em aberto...");

    $pdo = conectarBanco();

    // Registro em sync_status (lido por verificar-saude-sync.php e saude-sync.php).
    try {
        // So vagas sem Fim definido; se a ultima linha ja e de hoje, nao faz nada
        // (evita duplicar se o cron rodar 2x no dia).
        $stmt = $pdo->prepare(
            "SELECT v.solicitacao_cobertura_id, sc.filial, sc.centro_custo, sc.matricula, sc.nome, sc.funcao,
                    MAX(STR_TO_DATE(sc2.data, '%d/%m/%Y')) AS ultima_data
               FROM `$tabelaVaga` v
               JOIN solicitacoes_cobertura sc ON sc.id = v.solicitacao_cobertura_id
               JOIN solicitacoes_cobertura sc2 ON sc2.matricula = sc.matricula AND sc2.motivo = ?
              WHERE v.fim_definido_em IS NULL
              GROUP BY v.solicitacao_cobertura_id, sc.filial, sc.centro_custo, sc.matricula, sc.nome, sc.funcao"
        );
        $stmt->execute([$motivo]);
        $vagas = $stmt->fetchAll();

        $estendidas = 0;
        $diasCriados = 0;
        // A observacao do Preposto vem da ancora no PROPRIO INSERT (nao do SELECT acima): editada no meio da rodada
        // (acao=salvar_observacao do dados.php), o dia novo ja sai com a nota nova. Quem abriu a vaga, a escala e o id da solicitacao
        // (lote_id, novoLoteSolicitacao em db.php) tambem vem da ancora. Retrato: o relido do cadastro (abaixo); o que vier vazio dele
        // (titular fora do cadastro, como o desligado buscado no Protheus) fica com o da ancora, gravado na abertura (snapshotTitularVaga).
        $stmtInsert = $pdo->prepare(
            "INSERT INTO solicitacoes_cobertura (filial, centro_custo, matricula, nome, funcao, data, motivo, matricula_vol, nome_vol,
                                                  titular_salario, titular_periculosidade, titular_insalubridade_grau, observacao,
                                                  criado_por_login, criado_por_papel, escala, lote_id)
             SELECT ?, ?, ?, ?, ?, ?, ?, '', '', COALESCE(?, titular_salario), COALESCE(?, titular_periculosidade),
                    COALESCE(?, titular_insalubridade_grau), observacao, criado_por_login, criado_por_papel, escala, lote_id
               FROM solicitacoes_cobertura WHERE id = ?"
        );
        $hoje = new DateTime('today');
        foreach ($vagas as $vaga) {
            $ultimaData = $vaga['ultima_data'] ? new DateTime($vaga['ultima_data']) : null;
            if ($ultimaData !== null && $ultimaData >= $hoje) {
                continue; // ja tem linha de hoje (ou de uma data futura, por seguranca) - nao duplica
            }
            // Retrato do titular no dia de hoje (ver schema.sql): cada dia estendido usa o
            // salario/adicional atual, nao o da abertura da vaga.
            $retratoTitular = snapshotTitularSolicitacao($pdo, $vaga['matricula']);
            // Repoe os dias em que a rotina nao rodou (do dia seguinte a ultima linha ate hoje),
            // usando o retrato de hoje. Sem linha nenhuma, so hoje.
            $dia = $ultimaData !== null ? (clone $ultimaData)->modify('+1 day') : clone $hoje;
            for (; $dia <= $hoje; $dia->modify('+1 day')) {
                $stmtInsert->execute([
                    $vaga['filial'], $vaga['centro_custo'], $vaga['matricula'], $vaga['nome'], $vaga['funcao'],
                    $dia->format('d/m/Y'), $motivo,
                    $retratoTitular['titular_salario'], $retratoTitular['titular_periculosidade'], $retratoTitular['titular_insalubridade_grau'],
                    $vaga['solicitacao_cobertura_id'],
                ]);
                $diasCriados++;
            }
            $estendidas++;
        }

        $log("$estendidas vaga(s) de $rotulo estendida(s), $diasCriados dia(s) criado(s) ate hoje.");
        registrarSucessoSyncStatus($pdo, $chaveSync);
    } catch (Throwable $e) {
        $log('ERRO: ' . $e->getMessage());
        registrarErroSyncStatus($pdo, $chaveSync, $e->getMessage());
    }
    $log('Extensao diaria concluida.');
}
