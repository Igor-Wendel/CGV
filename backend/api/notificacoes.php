<?php
// ============================================================
// CAIXA DE NOTIFICACAO DO USUARIO LOGADO
//
// O aviso fica guardado (tabela notificacoes) ate a pessoa abrir qualquer
// pagina - o sino (backend/filtro.js, carregarNotificacoesServidor) busca os
// nao lidos aqui, mais novo em cima, e o X marca como lido (nao volta mais,
// nem em outro computador). Quem grava os avisos: ausencia-cobertura.php e
// notificarCancelamentoDeCobertura (db.php).
//
// GET              -> { notificacoes: [{ id, tipo, mensagem, criado_em }] }
// POST ?acao=lida  { id } -> { success }
// ============================================================
require_once __DIR__ . '/db.php';

$pdo = conectarBanco();
$usuarioId = verificarSessao($pdo);
header('Content-Type: application/json');

// Banco ainda sem a migracao: sino segue funcionando sem os avisos do
// servidor, em vez de dar erro em toda pagina.
if (!tabelaExiste($pdo, 'notificacoes')) {
    echo json_encode(['notificacoes' => []]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_GET['acao'] ?? '') !== 'lida') {
        http_response_code(400);
        echo json_encode(['error' => 'Acao invalida.']);
        exit;
    }
    $corpo = json_decode(file_get_contents('php://input'), true);
    // usuario_id na condicao: ninguem marca como lido o aviso de outra pessoa.
    $pdo->prepare('UPDATE notificacoes SET lida_em = NOW() WHERE id = ? AND usuario_id = ? AND lida_em IS NULL')
        ->execute([(int) ($corpo['id'] ?? 0), $usuarioId]);
    echo json_encode(['success' => true]);
    exit;
}

// Respostas do SAF aos pedidos de cobertura (Previsao) viram aviso aqui, sem precisar abrir a Previsao.
try {
    conferirPedidosCoberturaSaf($pdo);
} catch (Throwable $e) {
    error_log('conferir pedidos do SAF falhou: ' . $e->getMessage());
}

$stmt = $pdo->prepare(
    'SELECT id, tipo, mensagem, criado_em FROM notificacoes
      WHERE usuario_id = ? AND lida_em IS NULL
      ORDER BY criado_em DESC, id DESC
      LIMIT 100'
);
$stmt->execute([$usuarioId]);
echo json_encode(['notificacoes' => $stmt->fetchAll()]);
