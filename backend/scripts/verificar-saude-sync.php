<?php
// Checagem de atraso das sincronizacoes externas (Protheus/USC), que rodam 1x ao dia (3h) no
// container "sync". Roda em loop curto (a cada hora, ver docker-compose.yml) e checa se faz
// tempo demais sem sucesso. O alerta e um log destacado em STDERR (docker compose logs sync);
// com e-mail no futuro, troque a chamada de alertarAtrasoSync().
//
// Limite de 72h, o mesmo do aviso do login (saude-sync.php): como status-rotina.php recupera
// rodada perdida ao ligar, atraso so importa quando algo esta quebrado. So checa chave com
// integracao configurada (PROTHEUS_DB_HOST/USC_API_URL). Notifica so na transicao para
// atrasado (sync_status.notificado_atraso_em), nao a cada hora.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado - script de linha de comando.');
}

require_once __DIR__ . '/../api/db.php';

function saudeSyncLog(string $msg): void {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n");
}

function alertarAtrasoSync(string $titulo, string $mensagem): void {
    // Sem e-mail no CGV: alerta em log destacado (>>> ... <<<) para nao passar despercebido.
    saudeSyncLog(">>> ALERTA: $titulo <<<");
    saudeSyncLog($mensagem);
}

const LIMITE_HORAS_ATRASO = 72;

$pdo = conectarBanco();

if (!tabelaExiste($pdo, 'sync_status')) {
    saudeSyncLog('Tabela sync_status ainda nao existe - rode ferramentas/migrar-banco.php. Nada a checar por enquanto.');
    exit(0);
}

$chavesConfiguradas = [];
if (!empty(PROTHEUS_DB_HOST)) {
    $chavesConfiguradas['colaboradores'] = 'Sincronizacao de colaboradores volantes (Protheus)';
    $chavesConfiguradas['centros_custo'] = 'Sincronizacao de centros de custo (Protheus)';
}
if (!empty(USC_API_URL)) $chavesConfiguradas['usuarios'] = 'Sincronizacao de usuarios (directory USC)';

// Passos locais da cadeia noturna (docker-compose.yml): sem integracao externa, sempre monitorados.
$chavesConfiguradas['estender_desligamento'] = 'Extensao diaria de vagas de desligamento em aberto';
$chavesConfiguradas['estender_afastamento'] = 'Extensao diaria de vagas de afastamento em aberto';
$chavesConfiguradas['fechar_vencidas'] = 'Fechamento automatico de solicitacoes vencidas (3 dias)';
if ((string) SAF_COBERTURA_JSON !== '') $chavesConfiguradas['cortar_ferias'] = 'Corte de cobertura por ferias do SAF';
if ((string) SAF_COBERTURA_JSON !== '') $chavesConfiguradas['ociosidade'] = 'Foto diaria dos Volantes Ociosos (Historico de observacao)';

foreach ($chavesConfiguradas as $chave => $rotulo) {
    $stmt = $pdo->prepare('SELECT executado_em, notificado_atraso_em FROM sync_status WHERE chave = ?');
    $stmt->execute([$chave]);
    $linha = $stmt->fetch();

    $ultimaExecucao = $linha['executado_em'] ?? null;
    $horasDesdeUltimaExecucao = $ultimaExecucao ? (time() - strtotime($ultimaExecucao)) / 3600 : null;
    $atrasado = $horasDesdeUltimaExecucao === null || $horasDesdeUltimaExecucao > LIMITE_HORAS_ATRASO;

    if (!$atrasado) {
        saudeSyncLog("$chave: em dia (ultima execucao $ultimaExecucao).");
        // Recuperou de um atraso anterior - libera pra notificar de novo se atrasar outra vez.
        if ($linha && $linha['notificado_atraso_em']) {
            $pdo->prepare('UPDATE sync_status SET notificado_atraso_em = NULL WHERE chave = ?')->execute([$chave]);
        }
        continue;
    }

    if ($linha && $linha['notificado_atraso_em']) {
        saudeSyncLog("$chave: atrasado, mas ja notificado anteriormente (notificado_atraso_em={$linha['notificado_atraso_em']}) - nao repete.");
        continue;
    }

    $mensagem = $ultimaExecucao
        ? "$rotulo nao termina com sucesso ha mais de " . LIMITE_HORAS_ATRASO . " horas (ultima vez: $ultimaExecucao)."
        : "$rotulo nunca terminou com sucesso desde que o sistema foi implantado.";
    $mensagem .= ' Verifique os logs do container "sync" (docker compose logs sync) para entender o motivo.';

    alertarAtrasoSync("CGV: $rotulo esta atrasada", $mensagem);

    // INSERT...ON DUPLICATE cobre tambem "nunca rodou" (linha ainda nao existe).
    $pdo->prepare('INSERT INTO sync_status (chave, notificado_atraso_em) VALUES (?, NOW())
                    ON DUPLICATE KEY UPDATE notificado_atraso_em = NOW()')
        ->execute([$chave]);
}
