<?php
// ============================================================
// PERIODOS DISPONIVEIS DO PFM (Volantes e Intermitentes)
// ============================================================
// O seletor de periodo do PFM (fopag/pfm.js) so mostra ano/mes com cobertura registrada pro tipo;
// o calendario cresce sozinho conforme entram solicitacoes.
//
// Le as linhas de solicitacoes_cobertura com volante designado, calcula o periodo de folha (dia 20
// ao 19, como calcularPeriodoPfm em pfm-calculo.php) de cada uma, filtra pelo tipo de quem cobriu
// (centroCustoEhIntermitente) e devolve os (ano, mes) distintos, mais recente primeiro.
//
// Uso: pfm-periodos.php?tipo=volante
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../scripts/protheus-colaboradores-helpers.php';

$pdo = conectarBanco();
verificarSessaoAdmin($pdo); // RH-only, mesmo espirito do pfm-calculo.php
header('Content-Type: application/json');

$tipo = $_GET['tipo'] ?? '';
if (!in_array($tipo, ['volante', 'intermitente'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Tipo invalido (use volante ou intermitente).']);
    exit;
}

// Mesma regra de calcularPeriodoPfm (pfm-calculo.php), na direcao inversa:
// dado um dia, devolve o {ano, mes} do periodo de folha a que ele pertence (dia >= 20 conta pro
// mes seguinte).
function periodoDoDia(int $dia, int $mes, int $ano): array {
    if ($dia >= 20) {
        $mes++;
        if ($mes > 12) { $mes = 1; $ano++; }
    }
    return [$ano, $mes];
}

$stmt = $pdo->prepare(
    "SELECT sc.matricula AS titular_mat, sc.matricula_vol AS vol_mat, sc.data
       FROM solicitacoes_cobertura sc
      WHERE sc.matricula_vol IS NOT NULL AND sc.matricula_vol <> ''"
);
$stmt->execute();
// + as coberturas de ferias do SAF: mes so com ferias tambem precisa aparecer pra ser calculado.
$diasFeriasSaf = diasCoberturaFeriasSaf($pdo, '2000-01-01', '2100-12-31', $avisoSaf);
$coberturas = array_merge(semDiasDeFeriasSaf($stmt->fetchAll(), $diasFeriasSaf), $diasFeriasSaf);

if (count($coberturas) === 0) {
    echo json_encode(['periodos' => []]);
    exit;
}

$matriculasVol = array_values(array_unique(array_column($coberturas, 'vol_mat')));
$placeholders = implode(',', array_fill(0, count($matriculasVol), '?'));

$stmtColab = $pdo->prepare(
    "SELECT co.matricula, cc.descricao AS cc_nome
       FROM colaboradores co
       LEFT JOIN centros_custo cc ON cc.codigo = co.centro_custo
      WHERE co.matricula IN ($placeholders)"
);
$stmtColab->execute($matriculasVol);

$tipoPorMatricula = [];
foreach ($stmtColab->fetchAll() as $c) {
    if (isset($tipoPorMatricula[$c['matricula']])) continue;
    $ehIntermitente = centroCustoEhIntermitente((string) ($c['cc_nome'] ?? ''));
    $tipoPorMatricula[$c['matricula']] = $ehIntermitente ? 'intermitente' : 'volante';
}

$periodosEncontrados = [];
foreach ($coberturas as $c) {
    if (($tipoPorMatricula[$c['vol_mat']] ?? null) !== $tipo) continue;

    if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $c['data'], $m)) continue;
    [, $dia, $mes, $ano] = $m;
    [$anoPeriodo, $mesPeriodo] = periodoDoDia((int) $dia, (int) $mes, (int) $ano);

    $chave = "$anoPeriodo-$mesPeriodo";
    $periodosEncontrados[$chave] = ['ano' => $anoPeriodo, 'mes' => $mesPeriodo];
}

$periodos = array_values($periodosEncontrados);
usort($periodos, function ($a, $b) {
    return ($b['ano'] * 100 + $b['mes']) <=> ($a['ano'] * 100 + $a['mes']);
});

echo json_encode(['periodos' => $periodos]);
