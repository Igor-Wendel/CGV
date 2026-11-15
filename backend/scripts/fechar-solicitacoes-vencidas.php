<?php
// Fechamento automatico de solicitacoes de cobertura vencidas (container "sync", 3h).
//
// Regra: cada dia em aberto que completa 3 dias sem ninguem preencher quem cobriu e fechado
// como "SEM COBERTURA" (o mesmo volante usado manualmente pelo RH, ver PINCEL_SEM_COBERTURA
// em coberturas/solicitacao.html). Vale por dia: numa solicitacao de 5 dias, cada dia fecha
// na propria data+3.
//
// fechado_em guarda a hora do fechamento; o frontend usa para destacar a janela de 15 dias
// de correcao (dentroDaJanelaDeEdicao).

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado - script de linha de comando.');
}

require_once __DIR__ . '/../api/db.php';

function fecharSolicVencidasLog(string $msg): void {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n");
}

fecharSolicVencidasLog('Iniciando fechamento automatico de solicitacoes vencidas (3 dias sem cobertura)...');

$pdo = conectarBanco();

// "data" e VARCHAR dd/mm/aaaa (1 linha por dia), por isso o STR_TO_DATE.
// Vagas de Desligamento/Afastamento ficam em aberto de proposito (crescem um dia por dia
// ate o Preposto definir o Fim, ver estender-vagas-helpers.php), entao ficam de fora
// enquanto fim_definido_em for NULL; depois seguem a regra normal dos 3 dias. So os dias DAQUELA vaga (id >= ancora, a regra do
// cancelar): os de uma vaga anterior do mesmo titular, ja com Fim, fecham normalmente (revisao de 2026-11-13).
$condicaoDiaAberto = "(sc.matricula_vol IS NULL OR sc.matricula_vol = '')
            AND NOT EXISTS (
                SELECT 1 FROM solicitacoes_desligamento sd
                 WHERE sd.matricula_titular = sc.matricula
                   AND sc.motivo = 'desligamento'
                   AND sc.id >= sd.solicitacao_cobertura_id
                   AND sd.fim_definido_em IS NULL
            )
            AND NOT EXISTS (
                SELECT 1 FROM solicitacoes_afastamento sa
                 WHERE sa.matricula_titular = sc.matricula
                   AND sc.motivo = 'afastamento_indeterminado'
                   AND sc.id >= sa.solicitacao_cobertura_id
                   AND sa.fim_definido_em IS NULL
            )";

// Registro em sync_status (lido por saude-sync.php).
try {
    $stmt = $pdo->prepare(
        "UPDATE solicitacoes_cobertura sc
            SET matricula_vol = 'SEM_COBERTURA', nome_vol = 'SEM COBERTURA', fechado_em = NOW()
          WHERE $condicaoDiaAberto
            AND STR_TO_DATE(sc.data, '%d/%m/%Y') <= DATE_SUB(CURDATE(), INTERVAL 3 DAY)"
    );
    $stmt->execute();

    fecharSolicVencidasLog($stmt->rowCount() . ' dia(s) de solicitacao fechado(s) automaticamente como SEM COBERTURA.');
    registrarSucessoSyncStatus($pdo, 'fechar_vencidas');
} catch (Throwable $e) {
    fecharSolicVencidasLog('ERRO: ' . $e->getMessage());
    registrarErroSyncStatus($pdo, 'fechar_vencidas', $e->getMessage());
}

// Aviso "vence amanha" (N1 da analise de 2026-11-12): os dias ainda sem ninguem que o fechamento de amanha vai fechar como SEM
// COBERTURA. 1 aviso por pessoa que designa (destinatariosCobertura: RH/Administrador ve todos, o Gestor so os centros dele) e
// so 1 por dia (a cadeia pode rodar de novo na recuperacao ao ligar). Falha aqui nao muda o resultado do fechamento.
try {
    $stmtVence = $pdo->prepare(
        "SELECT sc.centro_custo, sc.nome, sc.data FROM solicitacoes_cobertura sc
          WHERE $condicaoDiaAberto
            AND STR_TO_DATE(sc.data, '%d/%m/%Y') <= DATE_SUB(CURDATE(), INTERVAL 2 DAY)
          ORDER BY sc.centro_custo, sc.nome, STR_TO_DATE(sc.data, '%d/%m/%Y')"
    );
    $stmtVence->execute();
    $porCentro = [];
    foreach ($stmtVence->fetchAll() as $l) $porCentro[(string) $l['centro_custo']][(string) $l['nome']][] = substr((string) $l['data'], 0, 5);
    $porUsuario = [];
    foreach ($porCentro as $centro => $titulares) {
        $rotulo = rotuloCentroCusto($pdo, $centro);
        foreach (destinatariosCobertura($pdo, $centro, '') as $idUsuario) {
            foreach ($titulares as $nome => $dias) $porUsuario[$idUsuario][] = "$nome ($rotulo) " . implode(', ', $dias);
        }
    }
    $stmtJaAvisado = $pdo->prepare("SELECT 1 FROM notificacoes WHERE usuario_id = ? AND tipo = 'vence_amanha' AND criado_em >= CURDATE() LIMIT 1");
    $avisados = 0;
    foreach ($porUsuario as $idUsuario => $itens) {
        $stmtJaAvisado->execute([$idUsuario]);
        if ($stmtJaAvisado->fetchColumn()) continue;
        $lista = '';
        foreach ($itens as $i => $item) {
            if (mb_strlen($lista . $item) > 330) { $lista .= '; e mais ' . (count($itens) - $i); break; }
            $lista .= ($lista === '' ? '' : '; ') . $item;
        }
        criarNotificacoes($pdo, [$idUsuario], 'vence_amanha', 'VENCE AMANHÃ: ainda sem cobertura, fecham sozinhos como SEM COBERTURA na '
            . "madrugada de amanhã: $lista. Designe em Solicitações > Abertas.", null, null);
        $avisados++;
    }
    fecharSolicVencidasLog("Aviso 'vence amanha': " . count($porCentro) . " centro(s), $avisados pessoa(s) avisada(s).");
} catch (Throwable $e) {
    fecharSolicVencidasLog('ERRO no aviso vence amanha: ' . $e->getMessage());
}
fecharSolicVencidasLog('Fechamento automatico concluido.');
