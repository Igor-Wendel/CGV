<?php
// ============================================================
// VALOR PADRAO DO VALE ALIMENTACAO (Beneficios > Intermitentes)
//
// Fica na tabela "configuracoes" (chave 'valor_va_padrao'); o RH altera pela gaveta da propria
// tela (lapis ao lado de VALE ALIMENTACAO).
//
// E so o valor PADRAO: vale pra toda linha da planilha que nao tiver um
// unitario proprio digitado no modo de edicao da tabela.
//
// GET  -> { valor }
// POST { valor, valor_anterior } -> { valor }
//   valor_anterior = o que a tela mostrava quando a pessoa clicou no lapis.
//   Se outro usuario alterou nesse meio tempo, recusa (409) em vez de sobrescrever.
// ============================================================
require_once __DIR__ . '/db.php';

const CHAVE_VALOR_VA = 'valor_va_padrao';
const VALOR_VA_INICIAL = 35.07; // valor de antes da tela existir - so pra banco ainda sem a migracao

$pdo = conectarBanco();
$usuarioId = verificarSessaoAdmin($pdo); // RH ou Administrador, como o resto de Beneficios
header('Content-Type: application/json');

function erroValorVa(string $msg, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['error' => $msg]);
    exit;
}

function moedaVa(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}

$temTabela = tabelaExiste($pdo, 'configuracoes');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $valor = VALOR_VA_INICIAL;
    if ($temTabela) {
        $stmt = $pdo->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
        $stmt->execute([CHAVE_VALOR_VA]);
        $gravado = $stmt->fetchColumn();
        if ($gravado !== false) $valor = (float) $gravado;
    }
    echo json_encode(['valor' => $valor]);
    exit;
}

if (!$temTabela) erroValorVa('Tabela de configuracoes ainda nao existe - rode ferramentas/migrar-banco.php.', 500);

$corpo = json_decode(file_get_contents('php://input'), true);
if (!is_array($corpo) || !is_numeric($corpo['valor'] ?? null) || !is_numeric($corpo['valor_anterior'] ?? null)) {
    erroValorVa('Informe "valor" e "valor_anterior" numericos.');
}
$novo = round((float) $corpo['valor'], 2);
$esperado = round((float) $corpo['valor_anterior'], 2);
if ($novo <= 0) erroValorVa('O valor do Vale Alimentacao precisa ser maior que zero.');

$pdo->beginTransaction();
try {
    // FOR UPDATE: dois salvamentos ao mesmo tempo entram em fila aqui - o
    // segundo ja le o valor gravado pelo primeiro e cai na recusa abaixo.
    $stmt = $pdo->prepare('SELECT valor FROM configuracoes WHERE chave = ? FOR UPDATE');
    $stmt->execute([CHAVE_VALOR_VA]);
    $gravado = $stmt->fetchColumn();
    $atual = $gravado === false ? VALOR_VA_INICIAL : (float) $gravado;

    if (abs($atual - $esperado) >= 0.005) {
        $pdo->rollBack();
        erroValorVa('O valor do Vale Alimentacao foi alterado por outra pessoa (agora ' . moedaVa($atual)
            . '). Recarregue a pagina antes de alterar de novo.', 409);
    }

    $pdo->prepare(
        'INSERT INTO configuracoes (chave, valor, atualizado_em, atualizado_por) VALUES (?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_em = NOW(), atualizado_por = VALUES(atualizado_por)'
    )->execute([CHAVE_VALOR_VA, number_format($novo, 2, '.', ''), $usuarioId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    erroValorVa('Nao foi possivel salvar o valor do Vale Alimentacao.', 500);
}

if (abs($novo - $atual) >= 0.005) {
    registrarAuditoria($pdo, $usuarioId, 'alterar_valor_vale_alimentacao', [
        'tabela' => 'configuracoes',
        'detalhe' => 'Valor padrao do Vale Alimentacao: ' . moedaVa($atual) . ' -> ' . moedaVa($novo),
    ]);
}

echo json_encode(['valor' => $novo]);
