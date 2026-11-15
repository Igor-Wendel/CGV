<?php
// ============================================================
// AUSENCIA DE COBERTURA
//
// O Preposto marca, no proprio dia, que o volante designado nao compareceu (botao "Ausencia de
// cobertura" na ficha do volante, colaboradores/funcionarios.html). So aquele dia vira
// SEM_COBERTURA; quem faltou fica em ausencia_* na linha e Gestores/RH recebem aviso
// (destinatariosCobertura em db.php).
//
// Nao mexe em fechado_em (o prazo de correcao segue contando do fim do periodo). Desfazer: RH/Gestor
// redesigna o MESMO volante pela correcao de Fechadas (cobertura-solicitacao.php).
//
// POST { id, matricula_vol } - se o volante foi trocado nesse meio tempo, recusa (409).
// POST { ferias_titular, matricula_vol } - cobertura de ferias do SAF: nao tem linha no CGV, entao a
// ausencia cria a linha do dia (motivo ferias, SEM_COBERTURA). O PFM nao paga esse dia e o RH pode
// designar outra pessoa ou desfazer normalmente.
// ============================================================
require_once __DIR__ . '/db.php';

$pdo = conectarBanco();
$usuarioId = verificarSessao($pdo);
header('Content-Type: application/json');

exigirPapel($pdo, $usuarioId, ['preposto']);

function erroAusencia(string $msg, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') erroAusencia('Metodo invalido.', 405);

$corpo = json_decode(file_get_contents('php://input'), true);
$id = (int) ($corpo['id'] ?? 0);
$matriculaVista = trim((string) ($corpo['matricula_vol'] ?? ''));
$titularFerias = trim((string) ($corpo['ferias_titular'] ?? ''));
if (($id <= 0 && $titularFerias === '') || $matriculaVista === '') erroAusencia('Informe a cobertura e o volante.');

$centrosPermitidos = centrosCustoLiberados($pdo, $usuarioId, 'preposto'); // null = todos

if ($titularFerias !== '') {
    $hoje = date('Y-m-d');
    $dataHoje = date('d/m/Y');
    $ferias = null;
    foreach (feriasSafComVolante($pdo, $avisoSaf) as $f) {
        if ((string) $f['matricula'] === $titularFerias && $f['matricula_vol'] === $matriculaVista
            && $hoje >= $f['cobertura_inicio'] && $hoje <= $f['ferias_fim']) $ferias = $f;
    }
    if (!$ferias) erroAusencia('A cobertura de férias de hoje não foi encontrada. Recarregue a pagina.', 409);
    $centro = trim((string) ($ferias['centro_custo'] ?? ''));
    if ($centrosPermitidos !== null && !in_array($centro, $centrosPermitidos, true)) {
        erroAusencia('Esta cobertura esta fora do seu centro de custo.', 403);
    }
    $stmtColab = $pdo->prepare('SELECT nome, funcao FROM colaboradores WHERE matricula = ? LIMIT 1');
    $stmtColab->execute([$matriculaVista]);
    $nomeVol = (string) ($stmtColab->fetch()['nome'] ?? '');
    $stmtColab->execute([$titularFerias]);
    $funcaoTitular = ($ferias['funcao'] ?? '') ?: (string) ($stmtColab->fetch()['funcao'] ?? ''); // o SAF nao tem a do Grupo 02
    // Posto de origem de um emprestado (linhasPostoOrigemSaf, db.php): quem faltou e o substituto, e o emprestado nao esta de ferias
    // (cobre as de outra pessoa) - a linha leva "(posto de origem)" no nome e os avisos dizem de quem e o posto.
    $postoOrigem = !empty($ferias['posto_origem']);
    $nomeLinha = $postoOrigem ? mb_substr("{$ferias['nome']} (posto de origem)", 0, 150) : (string) ($ferias['nome'] ?? '');
    $deQuem = $postoOrigem ? "o posto de {$ferias['nome']} (emprestado para as férias de {$ferias['ferias_titular']})" : "as férias de {$ferias['nome']}";

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM solicitacoes_cobertura WHERE ausencia_matricula_vol = ? AND data = ? AND matricula = ? FOR UPDATE');
        $stmt->execute([$matriculaVista, $dataHoje, $titularFerias]);
        if ($stmt->fetchColumn()) {
            $pdo->rollBack();
            erroAusencia('Esta ausência já foi registrada.', 409);
        }
        $retrato = snapshotTitularSolicitacao($pdo, $titularFerias);
        $pdo->prepare(
            "INSERT INTO solicitacoes_cobertura (filial, centro_custo, matricula, nome, funcao, data, motivo, matricula_vol, nome_vol, fechado_em,
                                                 titular_salario, titular_periculosidade, titular_insalubridade_grau,
                                                 ausencia_matricula_vol, ausencia_nome_vol, ausencia_em)
             VALUES (?, ?, ?, ?, ?, ?, 'ferias', 'SEM_COBERTURA', 'SEM COBERTURA', NOW(), ?, ?, ?, ?, ?, NOW())"
        )->execute([$ferias['filial'] ?? '', $centro, $titularFerias, $nomeLinha, $funcaoTitular, $dataHoje,
            $retrato['titular_salario'], $retrato['titular_periculosidade'], $retrato['titular_insalubridade_grau'], $matriculaVista, $nomeVol]);
        $novoId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Falha ao marcar ausencia em cobertura de ferias: ' . $e->getMessage());
        erroAusencia('Nao foi possivel marcar a ausencia de cobertura.', 500);
    }
    $mensagem = "AUSÊNCIA: $nomeVol não compareceu para cobrir $deQuem ("
        . rotuloCentroCusto($pdo, $centro) . ") em $dataHoje. O dia ficou como SEM COBERTURA.";
    criarNotificacoes($pdo, destinatariosCobertura($pdo, $centro, $matriculaVista), 'ausencia', $mensagem, $novoId, $usuarioId);
    registrarAuditoria($pdo, $usuarioId, 'ausencia_de_cobertura', [
        'tabela' => 'solicitacoes_cobertura',
        'quantidade' => 1,
        'detalhe' => "$nomeVol ($matriculaVista) nao compareceu para cobrir $deQuem em $dataHoje (id $novoId)",
    ]);
    echo json_encode(['success' => true]);
    exit;
}

$pdo->beginTransaction();
try {
    // FOR UPDATE: uma correcao do Gestor na mesma linha espera esta terminar (e vice-versa).
    $stmt = $pdo->prepare(
        "SELECT id, centro_custo, nome, data, matricula_vol, nome_vol,
                data = DATE_FORMAT(CURDATE(), '%d/%m/%Y') AS eh_hoje
           FROM solicitacoes_cobertura WHERE id = ? FOR UPDATE"
    );
    $stmt->execute([$id]);
    $linha = $stmt->fetch();

    if (!$linha) {
        $pdo->rollBack();
        erroAusencia('Cobertura nao encontrada. Recarregue a pagina.', 404);
    }
    if ($centrosPermitidos !== null && !in_array($linha['centro_custo'], $centrosPermitidos, true)) {
        $pdo->rollBack();
        erroAusencia('Esta cobertura esta fora do seu centro de custo.', 403);
    }
    if (!(int) $linha['eh_hoje']) {
        $pdo->rollBack();
        erroAusencia('So e possivel marcar ausencia de cobertura no dia de hoje.', 409);
    }
    $volAtual = trim((string) $linha['matricula_vol']);
    if ($volAtual === '' || $volAtual === 'SEM_COBERTURA' || $volAtual !== $matriculaVista) {
        $pdo->rollBack();
        erroAusencia('A cobertura deste dia foi alterada por outra pessoa. Recarregue a pagina.', 409);
    }

    $pdo->prepare(
        "UPDATE solicitacoes_cobertura
            SET matricula_vol = 'SEM_COBERTURA', nome_vol = 'SEM COBERTURA', vol_salario = NULL,
                ausencia_nome_vol = IF(COALESCE(ausencia_matricula_vol, '') = '', ?, ausencia_nome_vol),
                ausencia_matricula_vol = COALESCE(NULLIF(ausencia_matricula_vol, ''), ?), ausencia_em = NOW()
          WHERE id = ?"
    )->execute([$linha['nome_vol'], $volAtual, $id]); // 2a falta no mesmo dia mantem a 1a: quem faltou primeiro segue sem receber
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Falha ao marcar ausencia de cobertura: ' . $e->getMessage());
    erroAusencia('Nao foi possivel marcar a ausencia de cobertura.', 500);
}

$mensagem = "AUSÊNCIA: {$linha['nome_vol']} não compareceu para cobrir {$linha['nome']} ("
    . rotuloCentroCusto($pdo, (string) $linha['centro_custo']) . ") em {$linha['data']}. O dia ficou como SEM COBERTURA.";
criarNotificacoes($pdo, destinatariosCobertura($pdo, (string) $linha['centro_custo'], $volAtual), 'ausencia', $mensagem, $id, $usuarioId);

registrarAuditoria($pdo, $usuarioId, 'ausencia_de_cobertura', [
    'tabela' => 'solicitacoes_cobertura',
    'quantidade' => 1,
    'detalhe' => "{$linha['nome_vol']} ($volAtual) nao compareceu para cobrir {$linha['nome']} em {$linha['data']} (id $id)",
]);

echo json_encode(['success' => true]);
