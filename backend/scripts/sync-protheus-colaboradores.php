<?php
// Sincroniza colaboradores <- Protheus via SQL Server direto (SRA010/SRJ010 = Grupo 01;
// SRA020/SRJ010 = Grupo 02; a funcao do Grupo 02 nao esta na SRJ020). Roda no container "sync" (docker-compose.yml), 3h.
//
// Escopo: so os 8 campos da importacao manual de planilha (filial, centro de custo, matricula,
// nome, funcao, codigo da funcao, data de admissao, salario) mais os adicionais/CPF; os demais
// campos de "colaboradores" sao manuais e nunca sao tocados. Entram colaboradores de centro
// "volante" (ver centroCustoEhVolante) e de qualquer centro liberado a algum preposto/gestor
// (usuario_centros_custo). So mexe em origem='IMPORTADO'; a linha MANUAL ("Adicionar colaborador") da matricula que
// chega vira IMPORTADO no mesmo id, com o motivo da inclusao.
//
// Salario: intermitente tem RA_SALARIO=0; nesse caso usa RA_XSLBINT (salario base
// intermitente, campo customizado do Protheus).
//
// Matricula IMPORTADO ausente da lista desta rodada: busca a data real de desligamento
// (RA_DEMISSA) e grava em colaboradores.desligamento; a linha nunca e apagada. Quem sumiu
// por reclassificacao de centro (continua ativo, RA_DEMISSA vazio) NAO e tocado e fica
// orfao na tabela local ate limpeza manual.
//
// Erro geral (conexao, query de cadastro) aborta a rodada antes de escrever. Erro pontual
// numa matricula e logado e tentado de novo na proxima rodada.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado - script de linha de comando.');
}

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/protheus-colaboradores-helpers.php';
require_once __DIR__ . '/retrato-solicitacoes-helpers.php';

function protheusColabLog(string $msg): void {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n");
}

if (empty(PROTHEUS_DB_HOST)) {
    protheusColabLog('PROTHEUS_DB_HOST nao configurada - nada a sincronizar (defina as 6 variaveis PROTHEUS_DB_* no .env pra ativar).');
    exit(0);
}

// Trava de concorrencia: este script roda pelo cron (container "sync") e em segundo plano pelo
// container "app" ao liberar centro de custo novo (dispararSyncColaboradoresEmSegundoPlano em
// login.php). Sao containers separados (sem pasta em comum no servidor) e o app roda como
// www-data, sem escrita na pasta do codigo: por isso a trava fica no banco (GET_LOCK nesta
// conexao, usada ate o fim) e e solta sozinha quando a conexao fecha (fim, erro fatal, kill ou
// timeout). Com outra rodada em andamento, ESPERA ate 10 min e roda depois (uma rodada normal leva
// segundos): pular perdia o centro liberado logo depois de outro (Usuarios, varios em seguida), que
// a rodada que ja corria nao tinha lido, e acendia o aviso de falha para o RH. Mais de 10 min = a
// outra travou: pula e avisa.
$pdo = conectarBanco();
if ($pdo->query("SELECT GET_LOCK('cgv_sync_colaboradores', 600)")->fetchColumn() != 1) {
    protheusColabLog('Outra execucao deste script esta em andamento ha mais de 10 min (lock ocupado) - saindo sem fazer nada, pra nao duplicar/corromper dado em paralelo.');
        // Registra em sync_status; o aviso some sozinho quando a outra rodada conclui com sucesso.
    registrarErroSyncStatus($pdo, 'colaboradores', 'Rodada pulada: outra sincronizacao de colaboradores ficou mais de 10 min em andamento (lock ocupado).');
    exit(0);
}

protheusColabLog('Iniciando sincronizacao de colaboradores volantes (Protheus -> CGV)...');

try {
    $protheusPdo = conectarProtheusDb();
} catch (Throwable $e) {
    protheusColabLog('Falha ao conectar no Protheus: ' . $e->getMessage());
    registrarErroSyncStatus($pdo, 'colaboradores', $e->getMessage());
    exit(1);
}
protheusColabLog('Conectado ao Protheus.');

// Lido antes desta rodada gravar o proprio sucesso: indica falha desde a ultima vez
// (ver reconciliarRetratoAposFalha).
$sucessoAnteriorSync = tabelaExiste($pdo, 'sync_status')
    ? ($pdo->query("SELECT executado_em FROM sync_status WHERE chave = 'colaboradores'")->fetchColumn() ?: null)
    : null;

// ---------- 1. Cadastro ativo (SRA010+SRJ010 = Grupo 01; SRA020+SRJ010 = Grupo 02) ----------
try {
    $stmt = $protheusPdo->query(
        "SELECT RTRIM(A.RA_FILIAL) AS FILIAL, RTRIM(A.RA_CC) AS CC, RTRIM(A.RA_MAT) AS MAT,
                RTRIM(A.RA_NOMECMP) AS NOME, RTRIM(A.RA_ADMISSA) AS ADMISSAO,
                A.RA_SALARIO AS SALARIO, A.RA_XSLBINT AS SAL_B_INTERM,
                RTRIM(A.RA_CODFUNC) AS FUNCAO_CODIGO, RTRIM(J.RJ_DESC) AS FUNCAO,
                RTRIM(C.CTT_DESC01) AS CC_DESC, '01' AS GRUPO,
                RTRIM(A.RA_ADCPERI) AS ADCPERI, RTRIM(A.RA_ADCINS) AS ADCINS,
                RTRIM(A.RA_CIC) AS CPF
         FROM SRA010 A
         LEFT JOIN SRJ010 J ON J.RJ_FUNCAO = A.RA_CODFUNC AND J.D_E_L_E_T_ <> '*'
         LEFT JOIN CTT010 C ON C.CTT_CUSTO = A.RA_CC AND C.D_E_L_E_T_ <> '*'
         WHERE A.D_E_L_E_T_ <> '*' AND A.RA_DEMISSA = ''
         UNION ALL
         SELECT RTRIM(A.RA_FILIAL), RTRIM(A.RA_CC), RTRIM(A.RA_MAT),
                RTRIM(A.RA_NOMECMP), RTRIM(A.RA_ADMISSA),
                A.RA_SALARIO, A.RA_XSLBINT,
                RTRIM(A.RA_CODFUNC), RTRIM(J.RJ_DESC),
                RTRIM(C.CTT_DESC01), '02',
                RTRIM(A.RA_ADCPERI), RTRIM(A.RA_ADCINS),
                RTRIM(A.RA_CIC)
         FROM SRA020 A
         LEFT JOIN SRJ010 J ON J.RJ_FUNCAO = A.RA_CODFUNC AND J.D_E_L_E_T_ <> '*'
         LEFT JOIN CTT020 C ON C.CTT_CUSTO = A.RA_CC AND C.D_E_L_E_T_ <> '*'
         WHERE A.D_E_L_E_T_ <> '*' AND A.RA_DEMISSA = ''"
    );
    $ativosProtheus = $stmt->fetchAll();
} catch (Throwable $e) {
    protheusColabLog('Falha ao consultar cadastro ativo no Protheus: ' . $e->getMessage());
    registrarErroSyncStatus($pdo, 'colaboradores', $e->getMessage());
    exit(1);
}
protheusColabLog(count($ativosProtheus) . ' colaborador(es) ativo(s) no Protheus (SRA010 Grupo 01 + SRA020 Grupo 02).');

// Colisao de matricula entre os dois grupos: se ativa nos dois, nenhum e processado.
$gruposPorMatricula = [];
foreach ($ativosProtheus as $cad) {
    $gruposPorMatricula[$cad['MAT']][$cad['GRUPO']] = true;
}
$matriculasColidindo = [];
foreach ($gruposPorMatricula as $mat => $grupos) {
    if (count($grupos) > 1) $matriculasColidindo[] = $mat;
}
if ($matriculasColidindo) {
    protheusColabLog('Matricula(s) ativa(s) em MAIS DE UM grupo/empresa - nao processadas nesta rodada: ' . implode(', ', $matriculasColidindo));
}

// Centros liberados a algum preposto/gestor entram tambem (uniao com o filtro de volante),
// para o Preposto ver a equipe inteira do centro dele. 'TODOS' e sentinela (acesso irrestrito,
// ver centrosCustoLiberados em db.php): fica fora da lista de codigos, mas se alguem o tem
// liberado, importa-se a empresa inteira (tratado logo abaixo). Centro que sair da lista
// deixa as pessoas orfas na tabela local (ver topo do arquivo).
$centrosLiberadosPrepostoGestor = [];
$temUsuarioComTodosLiberado = false;
if (tabelaExiste($pdo, 'usuario_centros_custo')) {
    $stmtCentrosLiberados = $pdo->query(
        "SELECT DISTINCT UPPER(TRIM(centro_custo)) AS cc FROM usuario_centros_custo WHERE centro_custo <> 'TODOS'"
    );
    $centrosLiberadosPrepostoGestor = array_flip($stmtCentrosLiberados->fetchAll(PDO::FETCH_COLUMN));

    $stmtTemTodos = $pdo->query("SELECT COUNT(*) FROM usuario_centros_custo WHERE centro_custo = 'TODOS'");
    $temUsuarioComTodosLiberado = ((int) $stmtTemTodos->fetchColumn()) > 0;
}
if ($temUsuarioComTodosLiberado) {
    protheusColabLog('Ha usuario com centro de custo "TODOS" liberado - importando TODO colaborador ativo (nao so volante/intermitente).');
}

// Filtra volante OU centro liberado (ou "TODOS" liberado), ignora colisoes. A colisao e
// conferida por count em $gruposPorMatricula, nao por in_array: o PHP coage chave numerica-string
// para int e o in_array estrito deixaria colisao passar.
$volantesAtivos = [];
foreach ($ativosProtheus as $cad) {
    if (count($gruposPorMatricula[$cad['MAT']]) > 1) continue;
    $eVolante = centroCustoEhVolante((string) $cad['CC'], (string) ($cad['CC_DESC'] ?? ''));
    $eCentroLiberado = $temUsuarioComTodosLiberado
        || isset($centrosLiberadosPrepostoGestor[strtoupper(trim((string) $cad['CC']))]);
    if (!$eVolante && !$eCentroLiberado) continue;
    $volantesAtivos[$cad['MAT']] = $cad;
}
protheusColabLog(count($volantesAtivos) . ' colaborador(es) volante(s)/de centro liberado a preposto-gestor apos filtro de centro de custo.');

// ---------- 2. Upsert por matricula, so em origem='IMPORTADO' ----------
$inseridos = 0;
$atualizados = 0;
$comErro = 0;
$manuaisConvertidas = 0;

foreach ($volantesAtivos as $mat => $cad) {
    try {
        $filial = ltrim((string) $cad['FILIAL'], '0');
        if ($filial === '') $filial = '0';
        $centroCusto = strtoupper(trim((string) $cad['CC']));
        $nome = trim((string) $cad['NOME']);
        $funcao = trim((string) $cad['FUNCAO']);
        $funcaoCodigo = trim((string) $cad['FUNCAO_CODIGO']);
        $dataAdmissao = protheusColabFormatarData($cad['ADMISSAO']);
                // Intermitente tem RA_SALARIO=0; usa RA_XSLBINT (salario base de referencia).
        $salarioBase = $cad['SALARIO'] !== null ? (float) $cad['SALARIO'] : 0.0;
        $salBaseInterm = $cad['SAL_B_INTERM'] !== null ? (float) $cad['SAL_B_INTERM'] : 0.0;
        $salario = $salarioBase > 0 ? $salarioBase : ($salBaseInterm > 0 ? $salBaseInterm : null);

                // RA_ADCPERI 1=nao/2=sim; RA_ADCINS 1=nao/2=minima/3=media/4=maxima. Guardado como grau 0-3
                // (0 = nao tem), usado pelo calculo do PFM.
        $periculosidade = ((string) ($cad['ADCPERI'] ?? '')) === '2' ? 1 : 0;
        $insalubridadeGrau = max(0, ((int) ($cad['ADCINS'] ?? 0)) - 1);
        if ($insalubridadeGrau > 3) $insalubridadeGrau = 0; // valor inesperado - trata como "nao tem"

                // CPF (RA_CIC): vem com 11 digitos ou formatado; normaliza e formata, senao guarda vazio.
        $cpfDigitos = preg_replace('/\D/', '', (string) ($cad['CPF'] ?? ''));
        $cpf = strlen($cpfDigitos) === 11
            ? substr($cpfDigitos, 0, 3) . '.' . substr($cpfDigitos, 3, 3) . '.' . substr($cpfDigitos, 6, 3) . '-' . substr($cpfDigitos, 9, 2)
            : '';

                // Linha MANUAL dessa matricula ("Adicionar colaborador"; a matricula e unica): vira a IMPORTADO no mesmo id. O
                // Protheus passa a mandar nos dados dela e o motivo da inclusao, a observacao e o contato continuam (antes era
                // apagada e a pessoa sumia da lista do RH). Em transacao com o UPDATE/INSERT abaixo.
        $pdo->beginTransaction();

        $stmtManualDuplicada = $pdo->prepare(
            "UPDATE colaboradores SET origem = 'IMPORTADO' WHERE matricula = ? AND origem = 'MANUAL'"
        );
        $stmtManualDuplicada->execute([(string) $mat]);
        $convertida = $stmtManualDuplicada->rowCount() > 0;
        if ($convertida) {
            $manuaisConvertidas++;
        }

        $stmtBusca = $pdo->prepare(
            "SELECT id, desligamento FROM colaboradores WHERE matricula = ? AND origem = 'IMPORTADO' ORDER BY id ASC LIMIT 1"
        );
        $stmtBusca->execute([(string) $mat]);
        $existente = $stmtBusca->fetch();
        $idExistente = $existente ? $existente['id'] : false;

        if ($idExistente) {
            // Ativo no Protheus com um desligamento que nao vale mais - senao a tela o escondia como desligado:
            // - readmitido com a mesma matricula: o desligamento anterior a admissao atual sai;
            // - linha MANUAL convertida agora com desligamento ja vencido (o Remover de quem tinha cobertura so desliga, ver dados.php
            //   acao=remover): a pessoa esta ativa, sai tambem.
            // O desligamento previsto (data futura, aba Situacao) fica.
            $admissao = DateTime::createFromFormat('!d/m/Y', $dataAdmissao);
            $desligadoEm = DateTime::createFromFormat('!d/m/Y', (string) $existente['desligamento']);
            $desligamento = $desligadoEm && (($admissao && $desligadoEm < $admissao) || ($convertida && $desligadoEm <= new DateTime('today')))
                ? '' : $existente['desligamento'];
                        // COALESCE(NULLIF(?, ''), coluna) em nome/funcao/data_admissao: valor vazio vindo do
                        // Protheus (leitura falha, JOIN sem funcao, data fora do formato) nao apaga o valor bom
                        // ja salvo (possivelmente corrigido pelo RH).
            $pdo->prepare(
                "UPDATE colaboradores SET filial = ?, centro_custo = ?,
                        nome = COALESCE(NULLIF(?, ''), nome),
                        funcao = COALESCE(NULLIF(?, ''), funcao),
                        funcao_codigo = ?,
                        data_admissao = COALESCE(NULLIF(?, ''), data_admissao),
                        salario = ?,
                        periculosidade = ?,
                        insalubridade_grau = ?,
                        cpf = COALESCE(NULLIF(?, ''), cpf),
                        desligamento = ?
                  WHERE id = ?"
            )->execute([$filial, $centroCusto, $nome, $funcao, $funcaoCodigo, $dataAdmissao, $salario, $periculosidade, $insalubridadeGrau, $cpf,
                        $desligamento, $idExistente]);
            // Quem entrou pelo "Adicionar colaborador" e ja esta num centro de volante e volante: o motivo (transferencia/devolucao
            // concluida, ou aviso), as datas do aviso e o centro de destino saem (fica na lista por ser volante, no centro real, mesmo
            // que outro que o escolhido; o desligamento do volante e na aba Situacao).
            if (centroCustoEhVolante($centroCusto, (string) ($cad['CC_DESC'] ?? ''))) {
                $pdo->prepare("UPDATE colaboradores SET motivo_inclusao = '', centro_destino = NULL, inicio_aviso = '', fim_aviso = '',
                                      aviso_situacao = NULL WHERE id = ? AND COALESCE(motivo_inclusao, '') <> ''")->execute([$idExistente]);
            }
            $atualizados++;
        } else {
            $pdo->prepare(
                "INSERT INTO colaboradores
                    (origem, filial, centro_custo, matricula, nome, funcao, funcao_codigo, data_admissao, salario, periculosidade, insalubridade_grau, cpf)
                 VALUES ('IMPORTADO', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$filial, $centroCusto, (string) $mat, $nome, $funcao, $funcaoCodigo, $dataAdmissao, $salario, $periculosidade, $insalubridadeGrau, $cpf]);
            $inseridos++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
                // Erro pontual nesta matricula: nao aborta a rodada. O rollBack so ocorre se houver
                // transacao aberta (a excecao pode vir antes do beginTransaction).
        if ($pdo->inTransaction()) $pdo->rollBack();
        protheusColabLog("Erro ao processar matricula $mat (nao interrompe a rodada): " . $e->getMessage());
        $comErro++;
    }
}
protheusColabLog("$inseridos inserido(s), $atualizados atualizado(s), $comErro com erro, "
    . "$manuaisConvertidas linha(s) MANUAL convertida(s) em IMPORTADO (motivo da inclusao mantido).");

// ---------- 3. Desligamento: conhecido (IMPORTADO) mas sumiu da lista de ativos-volante ----------
// Quem ainda tem cadastro ativo no Protheus (em qualquer centro, $gruposPorMatricula do passo 1) nao e desligado: na transferencia
// para um centro que o CGV nao importa, o Protheus fecha o cadastro antigo com demissao, mas a pessoa continua ativa. A linha
// passa para a filial e o centro novos, e sai da lista de volantes sozinha (fica so se tiver motivo de inclusao).
// Entra tambem a linha MANUAL com motivo de inclusao que nao e aviso (devolvido/transferencia; o aviso e do passo 4): demitida em
// todos os cadastros, ganha o desligamento como a IMPORTADO (antes ficava sem, designavel e paga, e o passo 4e nao a via). Ativa
// em outro centro, fica como esta: o sync nao muda o centro da linha manual.
$stmtConhecidos = $pdo->query("SELECT id, origem, matricula, desligamento, filial, centro_custo, cpf FROM colaboradores
    WHERE origem = 'IMPORTADO' OR (origem = 'MANUAL' AND COALESCE(motivo_inclusao, '') <> '' AND motivo_inclusao NOT LIKE '%aviso%')");
$conhecidos = $stmtConhecidos->fetchAll();
$cadastroAtivo = array_column($ativosProtheus, null, 'MAT');
// A mesma matricula pode ser de outra pessoa no outro grupo (SRA010/SRA020): com os dois CPFs, CPF diferente = nao e ela ativa
// (revisao do MR, 2026-11-11). Sem CPF de um dos lados, vale a matricula, como antes.
$soDigitos = fn($cpf) => preg_replace('/\D/', '', (string) $cpf);
$outraPessoa = fn(string $mat, $cpfCgv) => isset($cadastroAtivo[$mat]) && strlen($soDigitos($cpfCgv)) === 11
    && strlen($soDigitos($cadastroAtivo[$mat]['CPF'] ?? '')) === 11 && $soDigitos($cpfCgv) !== $soDigitos($cadastroAtivo[$mat]['CPF']);

$sumidos = [];
$centrosAtualizados = 0;
foreach ($conhecidos as $c) {
    if (isset($volantesAtivos[$c['matricula']])) continue;
    if (!isset($gruposPorMatricula[$c['matricula']]) || (count($gruposPorMatricula[$c['matricula']]) === 1 && $outraPessoa($c['matricula'], $c['cpf']))) {
        $sumidos[] = $c;
        continue;
    }
    if (count($gruposPorMatricula[$c['matricula']]) > 1) continue; // ativa nos 2 grupos: nao processada (passo 1)
    if ($c['origem'] === 'MANUAL') continue; // ativa em outro centro: a linha manual fica como esta
    $cad = $cadastroAtivo[$c['matricula']];
    $filialNova = ltrim((string) $cad['FILIAL'], '0');
    if ($filialNova === '') $filialNova = '0';
    $centroNovo = strtoupper(trim((string) $cad['CC']));
    if ($filialNova === (string) $c['filial'] && $centroNovo === (string) $c['centro_custo']) continue;
    try {
        $pdo->prepare('UPDATE colaboradores SET filial = ?, centro_custo = ? WHERE id = ?')->execute([$filialNova, $centroNovo, $c['id']]);
        protheusColabLog("Matricula {$c['matricula']}: ativa no Protheus em centro nao importado - centro {$c['centro_custo']} -> $centroNovo.");
        $centrosAtualizados++;
    } catch (Throwable $e) {
        protheusColabLog("Erro ao atualizar o centro da matricula {$c['matricula']} (nao interrompe a rodada): " . $e->getMessage());
    }
}
protheusColabLog("$centrosAtualizados colaborador(es) ativo(s) em centro nao importado com o centro atualizado.");

$desligados = 0;
if ($sumidos) {
    $matriculasSumidas = array_column($sumidos, 'matricula');
    $placeholders = implode(',', array_fill(0, count($matriculasSumidas), '?'));
    try {
        $stmtDemissao = $protheusPdo->prepare(
            "SELECT RTRIM(RA_MAT) AS MAT, RTRIM(RA_DEMISSA) AS DEMISSAO FROM SRA010
              WHERE RA_MAT IN ($placeholders) AND D_E_L_E_T_ <> '*'
             UNION ALL
             SELECT RTRIM(RA_MAT), RTRIM(RA_DEMISSA) FROM SRA020
              WHERE RA_MAT IN ($placeholders) AND D_E_L_E_T_ <> '*'"
        );
        $stmtDemissao->execute(array_merge($matriculasSumidas, $matriculasSumidas));
        // A demissao mais nova da matricula (AAAAMMDD compara como texto), como no passo 4: com 2 cadastros fechados (transferido
        // e depois demitido), a ordem do UNION ALL nao e garantida.
        $demissoesPorMatricula = [];
        foreach ($stmtDemissao as $linha) {
            if ($linha['DEMISSAO'] !== '' && $linha['DEMISSAO'] > ($demissoesPorMatricula[$linha['MAT']] ?? '')) {
                $demissoesPorMatricula[$linha['MAT']] = $linha['DEMISSAO'];
            }
        }
        $demissoesPorMatricula = array_map('protheusColabFormatarData', $demissoesPorMatricula);

        foreach ($sumidos as $c) {
            try {
                $dataReal = $demissoesPorMatricula[$c['matricula']] ?? null;
                if ($dataReal === null) continue; // nao achou no Protheus - nao mexe
                if ($dataReal === $c['desligamento']) continue; // ja esta com essa data, evita update ruidoso
                $pdo->prepare("UPDATE colaboradores SET desligamento = ? WHERE id = ?")
                    ->execute([$dataReal, $c['id']]);
                $desligados++;
            } catch (Throwable $e) {
                // Erro pontual nesta matricula - nao aborta o resto, tenta de novo amanha.
                protheusColabLog("Erro ao marcar desligamento da matricula {$c['matricula']} (nao interrompe a rodada): " . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        protheusColabLog('Falha ao consultar desligamentos no Protheus (nao interrompe a rodada, so pula esta etapa): ' . $e->getMessage());
    }
}
protheusColabLog("$desligados colaborador(es) marcado(s) como desligado(s).");

// ---------- 4. Aviso ("Adicionar colaborador"): data do Protheus e aviso vencido ----------
// fim_aviso e "DD/MM/AAAA" (texto): comparacao em PHP. "Ativo" = sem demissao em nenhum dos 2 grupos ($gruposPorMatricula do
// passo 1; na transferencia de filial o Protheus fecha o cadastro antigo com demissao e abre outro ativo, entao a demissao so
// vale sem nenhum cadastro ativo).
// 4a. Ja demitido no Protheus: o fim do aviso passa a ser a data da demissao (a correta), venca ou nao.
// 4b. Vencido e demitido (demissao CONFIRMADA no Protheus): a linha nunca e apagada - o PFM ja calculado precisa dela para os dias
//     que a pessoa cobriu. A MANUAL ganha o desligamento (a tela esconde desligado; a IMPORTADO ganhou no passo 3) e as duas perdem
//     o aviso (motivo, datas). A MANUAL mantem o centro de destino: o Gestor desse destino continua vendo as coberturas que alocou
//     (correcao do PFM); desligada, ela nao e mais alocada (desligamentoDoColaborador, db.php).
// Aviso sem fim (dado antigo ou gravado direto pela API) tambem entra: demitido, o fim vira a data da demissao.
// 4c. Vencido e ainda ativo, ou sem nenhum cadastro no Protheus: fica PENDENTE (aviso_situacao) e a tela de Colaboradores pergunta
//     ao RH/Gestor se mantem e aguarda o Protheus (AGUARDANDO, nao pergunta mais) ou altera a data (dados.php acao=aviso_vencido).
// Consulta da demissao falhou: quem nao esta ativo fica como esta ate a proxima rodada (nada sai pela data digitada) e o aviso do
// login mostra a falha (chave avisos_colaboradores, saude-sync.php) ate a consulta voltar a funcionar.
$avisos = $pdo->query(
    "SELECT id, TRIM(matricula) AS matricula, COALESCE(fim_aviso, '') AS fim_aviso, origem, aviso_situacao, cpf FROM colaboradores
      WHERE motivo_inclusao LIKE '%aviso%'"
)->fetchAll();

// Ativo = com cadastro sem demissao e, com os dois CPFs, da mesma pessoa (mesma matricula no outro grupo pode ser outra pessoa).
$ativoAviso = fn(array $a) => isset($gruposPorMatricula[$a['matricula']])
    && !(count($gruposPorMatricula[$a['matricula']]) === 1 && $outraPessoa($a['matricula'], $a['cpf']));
$avisosDemitidos = array_values(array_filter($avisos, fn($a) => !$ativoAviso($a)));
$demissaoAviso = [];
$demissaoConsultada = true;
if ($avisosDemitidos) {
    $mats = array_column($avisosDemitidos, 'matricula');
    $ph = implode(',', array_fill(0, count($mats), '?'));
    try {
        $stmtDem = $protheusPdo->prepare(
            "SELECT RTRIM(RA_MAT) AS MAT, MAX(RTRIM(RA_DEMISSA)) AS DEMISSAO FROM (
                 SELECT RA_MAT, RA_DEMISSA FROM SRA010 WHERE RA_MAT IN ($ph) AND D_E_L_E_T_ <> '*' AND RA_DEMISSA <> ''
                 UNION ALL
                 SELECT RA_MAT, RA_DEMISSA FROM SRA020 WHERE RA_MAT IN ($ph) AND D_E_L_E_T_ <> '*' AND RA_DEMISSA <> ''
             ) D GROUP BY RTRIM(RA_MAT)"
        );
        $stmtDem->execute(array_merge($mats, $mats));
        foreach ($stmtDem as $l) $demissaoAviso[$l['MAT']] = protheusColabFormatarData($l['DEMISSAO']);
    } catch (Throwable $e) {
        $demissaoConsultada = false;
        protheusColabLog('Falha ao consultar a demissao de quem esta com aviso (nao interrompe a rodada; quem nao esta ativo no Protheus fica para a proxima): ' . $e->getMessage());
        registrarErroSyncStatus($pdo, 'avisos_colaboradores', 'Nao foi possivel consultar no Protheus a demissao de quem esta com aviso; '
            . 'ninguem saiu da lista por fim de aviso nesta rodada. ' . $e->getMessage());
    }
}
if ($demissaoConsultada) registrarSucessoSyncStatus($pdo, 'avisos_colaboradores');

$hojeAviso = new DateTime('today');
$avisosAlinhados = $avisosDesligados = $avisosPendentes = $avisosAdiados = 0;
foreach ($avisos as $a) {
    try {
        $aindaAtivo = $ativoAviso($a);
        if (!$aindaAtivo && !$demissaoConsultada) { $avisosAdiados++; continue; }
        $dataDemissao = $aindaAtivo ? '' : ($demissaoAviso[$a['matricula']] ?? '');
        if ($dataDemissao !== '' && $dataDemissao !== $a['fim_aviso']) {
            $pdo->prepare('UPDATE colaboradores SET fim_aviso = ?, aviso_situacao = NULL WHERE id = ?')->execute([$dataDemissao, $a['id']]);
            protheusColabLog("Matricula {$a['matricula']}: fim do aviso {$a['fim_aviso']} -> $dataDemissao (demissao no Protheus).");
            $a['fim_aviso'] = $dataDemissao;
            $avisosAlinhados++;
        }
        $fim = DateTime::createFromFormat('!d/m/Y', $a['fim_aviso']);
        if (!$fim || $fim >= $hojeAviso) continue;

        if ($dataDemissao !== '') {
            $pdo->prepare("UPDATE colaboradores SET desligamento = IF(origem = 'MANUAL', ?, desligamento), motivo_inclusao = '',
                                  centro_destino = IF(origem = 'MANUAL', centro_destino, NULL), inicio_aviso = '', fim_aviso = '', aviso_situacao = NULL WHERE id = ?")
                ->execute([$dataDemissao, $a['id']]);
            $avisosDesligados++;
        } elseif ((string) $a['aviso_situacao'] === '') {
            $pdo->prepare("UPDATE colaboradores SET aviso_situacao = 'PENDENTE' WHERE id = ?")->execute([$a['id']]);
            protheusColabLog("Matricula {$a['matricula']}: aviso vencido em {$a['fim_aviso']} e " . ($aindaAtivo ? 'ainda ativa' : 'sem nenhum cadastro')
                . ' no Protheus - pendente de resposta na tela de Colaboradores.');
            $avisosPendentes++;
        }
    } catch (Throwable $e) {
        protheusColabLog("Erro no aviso da matricula {$a['matricula']} (nao interrompe a rodada): " . $e->getMessage());
    }
}
protheusColabLog("Avisos: $avisosAlinhados data(s) alinhada(s) com a demissao do Protheus, $avisosDesligados desligado(s) por fim de aviso (linha mantida), "
    . "$avisosPendentes novo(s) pendente(s) (vencido sem demissao no Protheus)"
    . ($avisosAdiados ? ", $avisosAdiados sem conferencia nesta rodada (consulta da demissao falhou)" : '') . '.');

// ---------- 4d. Base de volante desligado (depois do passo 4: o 4b tambem desliga, a linha MANUAL) ----------
// Dias de base (colaborador_bases) posteriores ao desligamento saem, para o volante desligado
// nao aparecer ao Preposto. So quando nenhuma linha da matricula esta ativa (pode haver outra
// filial ativa).
if (tabelaExiste($pdo, 'colaborador_bases')) {
    try {
        $removidosBase = $pdo->exec(
            "DELETE b FROM colaborador_bases b
               JOIN (SELECT matricula, MAX(STR_TO_DATE(desligamento, '%d/%m/%Y')) AS deslig
                       FROM colaboradores
                      GROUP BY matricula
                     HAVING SUM(desligamento IS NULL OR desligamento = '') = 0) d ON d.matricula = b.matricula
              WHERE d.deslig IS NOT NULL AND STR_TO_DATE(b.data, '%d/%m/%Y') > d.deslig"
        );
        protheusColabLog("$removidosBase dia(s) de base removido(s) de volante(s) desligado(s).");
    } catch (Throwable $e) {
        protheusColabLog('Erro ao limpar base de desligados (nao interrompe a rodada): ' . $e->getMessage());
    }
}

// ---------- 4e. Volante desligado ainda designado (depois do 4d, com a mesma regra de quem esta desligado) ----------
// Volante com o desligamento JA chegado (o previsto da ficha espera o dia) que continua designado em dia DEPOIS dele: esses dias
// voltam para Abertas (mesmo UPDATE do corte das ferias, cortar-coberturas-ferias.php) e o PFM deixa de paga-los. So quando
// nenhuma linha da matricula esta ativa (data mais nova, como no 4d) e so em periodo do PFM que ainda aceita ajuste
// (inicioPeriodoPfmAberto, db.php): mes fechado fica como esta. Aviso no sino para os destinatarios do corte das ferias.
// Risco aceito (decisao do usuario, 2026-11-12): dia reaberto com 3 dias ou mais vira SEM COBERTURA no fechamento automatico
// logo depois (mesma cadeia das 3h); o RH corrige em Fechadas, no prazo.
try {
    $reabertos = reabrirCoberturasDeVolanteDesligado($pdo, 'protheusColabLog');
    protheusColabLog("{$reabertos['dias']} dia(s) de cobertura reaberto(s) de {$reabertos['volantes']} volante(s) desligado(s), "
        . "{$reabertos['avisos']} aviso(s) no sino.");
} catch (Throwable $e) {
    protheusColabLog('Erro ao reabrir a cobertura de volante desligado (nao interrompe a rodada): ' . $e->getMessage());
}

// Mesma lista de destinatariosCorte (cortar-coberturas-ferias.php): os da ausencia de cobertura (destinatariosCobertura, db.php)
// + o Preposto do centro do titular (so quem ainda tem o papel).
function destinatariosVolanteDesligado(PDO $pdo, string $centro, string $matriculaVol): array {
    return array_values(array_unique(array_merge(destinatariosCobertura($pdo, $centro, $matriculaVol), prepostosDoCentro($pdo, $centro))));
}

// Passo 4e (ver acima). Uma transacao por volante (FOR UPDATE nas linhas dele: uma designacao feita no meio nao e apagada) e um
// aviso + 1 registro no Log por titular e centro. Idempotente: a linha reaberta nao tem mais o volante. Devolve os totais.
function reabrirCoberturasDeVolanteDesligado(PDO $pdo, callable $log): array {
    $hoje = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();
    $inicioPfm = inicioPeriodoPfmAberto($hoje);
    $stmtDesligados = $pdo->prepare(
        "SELECT d.matricula, DATE_FORMAT(d.deslig, '%Y-%m-%d') AS deslig
           FROM (SELECT matricula, MAX(STR_TO_DATE(desligamento, '%d/%m/%Y')) AS deslig
                   FROM colaboradores
                  GROUP BY matricula
                 HAVING SUM(desligamento IS NULL OR desligamento = '') = 0) d
          WHERE d.deslig IS NOT NULL AND d.deslig <= ? AND d.matricula NOT IN ('', 'SEM_COBERTURA')
            AND EXISTS (SELECT 1 FROM solicitacoes_cobertura sc
                         WHERE sc.matricula_vol = d.matricula
                           AND STR_TO_DATE(sc.data, '%d/%m/%Y') > d.deslig AND STR_TO_DATE(sc.data, '%d/%m/%Y') >= ?)"
    );
    $stmtDesligados->execute([$hoje, $inicioPfm]);

    $resumo = ['volantes' => 0, 'dias' => 0, 'avisos' => 0];
    $limiteFechamento = date('Y-m-d', strtotime("$hoje -3 days")); // fechar-solicitacoes-vencidas.php fecha ate aqui
    foreach ($stmtDesligados->fetchAll() as $v) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "SELECT id, centro_custo, matricula, nome, data, nome_vol FROM solicitacoes_cobertura
                  WHERE matricula_vol = ? AND STR_TO_DATE(data, '%d/%m/%Y') > ? AND STR_TO_DATE(data, '%d/%m/%Y') >= ?
                  ORDER BY matricula, centro_custo, STR_TO_DATE(data, '%d/%m/%Y') FOR UPDATE"
            );
            $stmt->execute([$v['matricula'], $v['deslig'], $inicioPfm]);
            $linhas = $stmt->fetchAll();
            if (count($linhas) === 0) {
                $pdo->rollBack();
                continue;
            }
            $ids = array_map('intval', array_column($linhas, 'id'));
            $pdo->prepare("UPDATE solicitacoes_cobertura SET matricula_vol = '', nome_vol = '', vol_salario = NULL, fechado_em = NULL WHERE id IN ("
                . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $log("Erro ao reabrir a cobertura do volante desligado {$v['matricula']} (nao interrompe a rodada): " . $e->getMessage());
            continue;
        }
        $resumo['volantes']++;
        $resumo['dias'] += count($linhas);

        $desligBr = date('d/m/Y', strtotime($v['deslig']));
        $grupos = [];
        foreach ($linhas as $l) $grupos[$l['matricula'] . '|' . $l['centro_custo']][] = $l;
        foreach ($grupos as $doTitular) {
            try {
                $l = $doTitular[0];
                $datas = array_column($doTitular, 'data');
                $isos = array_map(fn($d) => implode('-', array_reverse(explode('/', $d))), $datas);
                $seguidos = true;
                for ($i = 1; $i < count($isos); $i++) {
                    if ($isos[$i] !== date('Y-m-d', strtotime($isos[$i - 1] . ' +1 day'))) $seguidos = false;
                }
                $periodo = count($datas) === 1 ? "em {$datas[0]}"
                    : ($seguidos ? "de {$datas[0]} a " . end($datas) : count($datas) . " dias entre {$datas[0]} e " . end($datas));
                $nomeVol = trim((string) $l['nome_vol']) !== '' ? trim((string) $l['nome_vol']) : $v['matricula'];
                $mensagem = "VOLANTE DESLIGADO: $nomeVol foi desligado(a) em $desligBr e estava designado(a) para cobrir {$l['nome']} ("
                    . rotuloCentroCusto($pdo, (string) $l['centro_custo']) . ") $periodo. Esses dias voltaram para Abertas: designe outra pessoa."
                    . ($isos[0] <= $limiteFechamento
                        ? ' Os de 3 dias atrás ou mais fecham como SEM COBERTURA no fechamento automático: corrija em Fechadas se alguém cobriu.' : '');
                criarNotificacoes($pdo, destinatariosVolanteDesligado($pdo, (string) $l['centro_custo'], (string) $v['matricula']),
                    'volante_desligado', $mensagem, null, null);
                registrarAuditoria($pdo, null, 'reabrir_cobertura_volante_desligado', [
                    'tabela' => 'solicitacoes_cobertura',
                    'quantidade' => count($datas),
                    'detalhe' => "volante {$v['matricula']}, titular {$l['matricula']}, centro {$l['centro_custo']}, desligado em $desligBr, $periodo, ids "
                        . implode(',', array_column($doTitular, 'id')),
                ]);
                $resumo['avisos']++;
            } catch (Throwable $e) {
                $log("Dias do volante desligado {$v['matricula']} reabertos, mas o aviso falhou: " . $e->getMessage());
            }
        }
    }
    return $resumo;
}

// ---------- 5. Retrato das solicitacoes criadas durante uma falha de sync ----------
// Nunca derruba a rodada: o cadastro ja foi sincronizado com sucesso acima.
// Se a conferencia em si falhar, avisa o RH/Administrador em vez de deixar
// passar em branco (na proxima rodada o intervalo ja nao conta mais como
// falha, entao ela nao seria refeita sozinha).
try {
    reconciliarRetratoAposFalha($pdo, $protheusPdo, $sucessoAnteriorSync, 'protheusColabLog');
} catch (Throwable $e) {
    protheusColabLog('Falha ao conferir o retrato das solicitacoes apos a falha de sync: ' . $e->getMessage());
    registrarErroSyncStatus($pdo, 'retrato_solicitacoes',
        "Nao foi possivel conferir salario/adicionais das solicitacoes criadas enquanto a sincronizacao esteve parada (desde $sucessoAnteriorSync). Confira manualmente.");
}

registrarSucessoSyncStatus($pdo, 'colaboradores');
protheusColabLog('Sincronizacao concluida.');
