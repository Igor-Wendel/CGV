<?php
// ============================================================
// SAUDE DA CADEIA NOTURNA (docker-compose.yml, container "sync", 3h) e do BACKUP diario (container
// "backup", 4h, docker/backup-db.sh), pro FRONTEND (alert() pro RH/Administrador, ver filtro.js).
// Mostra a ultima tentativa que FALHOU (com o motivo). "Nao rodou" so vira aviso acima de 72h
// (LIMITE_HORAS_ATRASO).
//
// Uso: saude-sync.php (endpoint unico, sem acao)
// ============================================================
require_once __DIR__ . '/db.php';

$pdo = conectarBanco();
verificarSessaoAdmin($pdo); // RH ou Administrador - mesmo espirito do aviso ser so pra quem administra o sistema
header('Content-Type: application/json');

// So ERRO aparece no aviso: rotina que rodou e falhou, com o motivo. "Nao rodou" (computador
// desligado as 3h) nao e aviso: a recuperacao ao ligar (scripts/status-rotina.php) roda a cadeia.
// Atraso so conta acima de 72h: ai e algo quebrado que nem grava o proprio erro (container
// parado, banco fora do ar).
const LIMITE_HORAS_ATRASO = 72;

// O backup grava o proprio resultado em sync_status (chave 'backup', docker/backup-db.sh) e entra
// so AQUI, fora de rotinasNoturnasConfiguradas (db.php): la, o "atrasada" de status-rotina.php
// rodaria a cadeia noturna inteira so porque o backup atrasou. Ambiente sem o servico de backup
// nunca grava a chave = sem aviso (nunca rodou nao e erro, abaixo).
$rotulos = rotinasNoturnasConfiguradas() + ['backup' => 'Backup diário do banco de dados'];

if (!tabelaExiste($pdo, 'sync_status')) {
    echo json_encode(['problemas' => []]);
    exit;
}

// Motivo em portugues pros erros que ja conhecemos. O texto tecnico original
// vai junto (detalhe_tecnico) so no arquivo baixado pelo OK. Erro novo, sem
// traducao, aparece com a mensagem original mesmo.
function motivoDoErroRotina(string $chave, string $mensagem): string {
    if (in_array($chave, ['colaboradores', 'centros_custo'], true)
        && preg_match('/Adaptive Server|Unable to connect|Login timeout|dblib/i', $mensagem)) {
        return 'Não foi possível conectar ao Protheus (servidor fora do ar, ou sem rede/VPN até ele).';
    }
    if (preg_match('/\[2002\]|Connection refused|server has gone away/i', $mensagem)) {
        return 'Não foi possível conectar ao banco de dados do CGV.';
    }
    if (preg_match('/mais de 10 min em andamento/i', $mensagem)) {
        return 'A rodada esperou 10 minutos por outra sincronização que não terminou (pode estar travada) e foi pulada.';
    }
    if (preg_match('/lock ocupado|ja estava em andamento/i', $mensagem)) {
        return 'A rodada foi pulada porque outra sincronização ainda estava em andamento.';
    }
    if (preg_match('/pasta de backup|arquivo de backup|disco cheio/i', $mensagem)) {
        return 'Não foi possível gravar o backup (disco cheio ou sem permissão na pasta de backups).';
    }
    return $mensagem;
}

function dataHoraBr(string $mysql): string {
    return date('d/m/Y \à\s H:i', strtotime($mysql));
}

$marcadores = implode(',', array_fill(0, count($rotulos), '?'));
$stmt = $pdo->prepare("SELECT chave, executado_em, ultimo_erro_em, mensagem_erro FROM sync_status WHERE chave IN ($marcadores)");
$stmt->execute(array_keys($rotulos));
$porChave = [];
foreach ($stmt->fetchAll() as $linha) {
    $porChave[$linha['chave']] = $linha;
}

$problemas = [];
foreach ($rotulos as $chave => $rotulo) {
    $linha = $porChave[$chave] ?? null;
    $executadoEm = $linha['executado_em'] ?? null;
    $ultimoErroEm = $linha['ultimo_erro_em'] ?? null;

    // Erro mais recente que o ultimo sucesso = a ultima tentativa falhou.
    $erroRecente = $ultimoErroEm !== null
        && ($executadoEm === null || strtotime($ultimoErroEm) > strtotime($executadoEm));

    if ($erroRecente) {
        $mensagem = (string) ($linha['mensagem_erro'] ?? '');
        $motivo = $mensagem !== '' ? motivoDoErroRotina($chave, $mensagem) : 'Sem motivo registrado.';
        $problemas[] = [
            'chave' => $chave,
            'rotulo' => $rotulo,
            'detalhe' => 'Falhou em ' . dataHoraBr($ultimoErroEm) . ". Motivo: $motivo",
            'detalhe_tecnico' => $motivo !== $mensagem ? $mensagem : null,
        ];
        continue;
    }

    $horasDesdeSucesso = $executadoEm ? (time() - strtotime($executadoEm)) / 3600 : null;
    if ($horasDesdeSucesso !== null && $horasDesdeSucesso > LIMITE_HORAS_ATRASO) {
        $problemas[] = [
            'chave' => $chave,
            'rotulo' => $rotulo,
            'detalhe' => 'Não roda com sucesso há mais de ' . LIMITE_HORAS_ATRASO . ' horas (última vez: '
                . dataHoraBr($executadoEm) . ') e não registrou erro. Motivo provável: o serviço '
                . ($chave === 'backup' ? "do backup (container 'backup')" : "das rotinas (container 'sync')")
                . ' está parado ou o banco de dados esteve fora do ar.',
            'detalhe_tecnico' => null,
        ];
    }
    // Nunca rodou (banco novo) nao e erro: a recuperacao ao ligar ja roda.
}

// Aviso de conferencia (nao e uma rotina que "atrasa"): depois de uma falha
// de sync, salario/adicional de alguma solicitacao mudou no Protheus sem
// data confirmada e foi regravado com o valor atual (ver
// backend/scripts/retrato-solicitacoes-helpers.php). Fica visivel por 72h a
// partir do registro - cobre um fim de semana - e some sozinho.
$stmtAviso = $pdo->query(
    "SELECT mensagem_erro FROM sync_status
      WHERE chave = 'retrato_solicitacoes' AND ultimo_erro_em >= NOW() - INTERVAL 72 HOUR"
);
$avisoRetrato = $stmtAviso->fetchColumn();
if ($avisoRetrato !== false && $avisoRetrato !== null) {
    $problemas[] = [
        'chave' => 'retrato_solicitacoes',
        'rotulo' => 'Conferência de salário/adicionais em solicitações',
        'detalhe' => $avisoRetrato,
    ];
}

// Passo 4 do sync de colaboradores: a consulta da demissao de quem esta com aviso falhou (ninguem saiu da lista por fim de aviso
// naquela rodada). Some quando a consulta volta a funcionar (executado_em mais novo).
$avisos = $pdo->query(
    "SELECT ultimo_erro_em, mensagem_erro FROM sync_status
      WHERE chave = 'avisos_colaboradores' AND ultimo_erro_em IS NOT NULL AND (executado_em IS NULL OR ultimo_erro_em > executado_em)"
)->fetch();
if ($avisos) {
    $mensagem = (string) $avisos['mensagem_erro'];
    $motivo = motivoDoErroRotina('colaboradores', $mensagem);
    $problemas[] = [
        'chave' => 'avisos_colaboradores',
        'rotulo' => 'Fim de aviso dos colaboradores incluídos (Protheus)',
        'detalhe' => 'Falhou em ' . dataHoraBr($avisos['ultimo_erro_em']) . ". Motivo: $motivo",
        'detalhe_tecnico' => $motivo !== $mensagem ? $mensagem : null,
    ];
}

echo json_encode(['problemas' => $problemas]);
