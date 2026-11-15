<?php
// ============================================================
// CALCULO DO PFM (Volantes e Intermitentes) - fopag/pfm-volantes.html e
// fopag/pfm-intermitente.html
// ============================================================
// Calculo 100% automatico, direto do banco:
//
// - Quem cobriu quem vem de solicitacoes_cobertura (1 linha por dia: o periodo e so um WHERE
//   data BETWEEN).
// - Cobertura de FERIAS soma junto: vem do JSON exportado pelo SAF (SAF_COBERTURA_JSON), aprovadas
//   + com cobertura, recortada pro periodo 20-19 e expandida dia a dia no formato de
//   solicitacoes_cobertura (diasCoberturaFeriasSaf, db.php). Arquivo ausente/invalido/antigo so deixa
//   essa parte de fora (aviso_saf na resposta).
// - Periculosidade/insalubridade do TITULAR AUSENTE (colaboradores.periculosidade/
//   insalubridade_grau, do sync Protheus) decidem se quem COBRIU tem direito ao adicional,
//   proporcional aos dias cobertos.
// - "Volante" vs "Intermitente" e decidido pelo NOME do centro de custo de quem cobriu
//   (centroCustoEhIntermitente, scripts/protheus-colaboradores-helpers.php).
// - Dia em que quem cobriu estava ausente como titular (falta, atestado ou afastamento dele) nao e
//   pago a ele, na linha do CGV e na ferias do SAF (aviso_saf; revisao de 2026-11-13).
//
// Uso: pfm-calculo.php?ano=2026&mes=9&tipo=volante&salario_minimo=1518
// (mes 1-12; "mes 9" = periodo 20/08 a 19/09, ver calcularPeriodoPfm)
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../scripts/protheus-colaboradores-helpers.php';

$pdo = conectarBanco();
$usuarioId = verificarSessaoAdmin($pdo); // RH-only, mesmo espirito da guarda em pfm-volantes.html/pfm-intermitente.html
header('Content-Type: application/json');

function erroPfm($msg, $status = 400) {
    http_response_code($status);
    echo json_encode(['error' => $msg]);
    exit;
}

$ano = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT);
$mes = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT);
$tipo = $_GET['tipo'] ?? '';
$salarioMinimo = filter_input(INPUT_GET, 'salario_minimo', FILTER_VALIDATE_FLOAT);

if (!$ano || $ano < 2000 || $ano > 2100) erroPfm('Ano invalido.');
if (!$mes || $mes < 1 || $mes > 12) erroPfm('Mes invalido.');
if (!in_array($tipo, ['volante', 'intermitente'], true)) erroPfm('Tipo invalido (use volante ou intermitente).');
if ($salarioMinimo === false || $salarioMinimo === null || $salarioMinimo <= 0) erroPfm('Salario minimo invalido.');

// Periodo de folha: dia 20 do mes anterior ate dia 19 do mes selecionado
// (ex: mes=9/ano=2026 -> 20/08/2026 a 19/09/2026).
function calcularPeriodoPfm(int $ano, int $mes): array {
    $mesAnterior = $mes - 1;
    $anoAnterior = $ano;
    if ($mesAnterior < 1) { $mesAnterior = 12; $anoAnterior--; }
    $inicio = sprintf('%04d-%02d-20', $anoAnterior, $mesAnterior);
    $fim = sprintf('%04d-%02d-19', $ano, $mes);
    return [$inicio, $fim];
}

[$dataInicio, $dataFim] = calcularPeriodoPfm($ano, $mes);

// 1) Coberturas (1 linha/dia) do periodo com volante designado. "data" e texto DD/MM/AAAA, entao
// STR_TO_DATE (um BETWEEN na string quebraria na virada de mes/ano).
// titular_salario/titular_periculosidade/titular_insalubridade_grau/vol_salario: retrato congelado
// (ver schema.sql), usado EM VEZ do valor atual de colaboradores. Linhas de FERIAS do SAF nao tem
// retrato: a agregacao cai no valor atual pra elas.
$stmtCob = $pdo->prepare(
    "SELECT matricula AS titular_mat, nome AS titular_nome, matricula_vol AS vol_mat, data,
            titular_salario, titular_periculosidade, titular_insalubridade_grau, vol_salario
       FROM solicitacoes_cobertura
      WHERE STR_TO_DATE(data, '%d/%m/%Y') BETWEEN ? AND ?
        AND matricula_vol IS NOT NULL AND matricula_vol <> ''"
);
$stmtCob->execute([$dataInicio, $dataFim]);
$coberturas = $stmtCob->fetchAll();

// 1b) Cobertura de FERIAS (SAF) do mesmo periodo, no mesmo formato -
// somada junto pra entrar na mesma agregacao abaixo. Dia do titular pago pelas ferias tira a linha do CGV dele.
$diasFeriasSaf = diasCoberturaFeriasSaf($pdo, $dataInicio, $dataFim, $avisoSaf);
$coberturas = array_merge(semDiasDeFeriasSaf($coberturas, $diasFeriasSaf, $foraFerias), $diasFeriasSaf);

if (count($coberturas) === 0) {
    echo json_encode([
        'periodo' => ['inicio' => $dataInicio, 'fim' => $dataFim],
        'tipo' => $tipo,
        'dados' => [],
        'aviso_saf' => $avisoSaf,
    ]);
    exit;
}

// 2) Busca em lote todos os colaboradores envolvidos (titulares + quem
// cobriu), com o nome do centro de custo junto (pra decidir intermitente).
$matriculas = [];
foreach ($coberturas as $c) {
    $matriculas[$c['titular_mat']] = true;
    $matriculas[$c['vol_mat']] = true;
}
foreach (array_keys($foraFerias) as $m) $matriculas[$m] = true; // pro aviso, so do tipo desta pagina (abaixo)
$matriculas = array_keys($matriculas);
$placeholders = implode(',', array_fill(0, count($matriculas), '?'));

$stmtColab = $pdo->prepare(
    "SELECT co.matricula, co.filial, co.centro_custo, co.nome, co.funcao, co.salario,
            co.periculosidade, co.insalubridade_grau, cc.descricao AS cc_nome
       FROM colaboradores co
       LEFT JOIN centros_custo cc ON cc.codigo = co.centro_custo
      WHERE co.matricula IN ($placeholders)"
);
$stmtColab->execute($matriculas);

$colabPorMatricula = [];
foreach ($stmtColab->fetchAll() as $c) {
    // Mesma matricula pode ter mais de uma linha (historico de filial) -
    // fica com a primeira encontrada, suficiente pros dados usados aqui.
    if (isset($colabPorMatricula[$c['matricula']])) continue;
    $colabPorMatricula[$c['matricula']] = $c;
}
$avisoSaf = avisoForaPorFeriasSaf($avisoSaf, array_filter($foraFerias, fn($m) => isset($colabPorMatricula[$m])
    && (centroCustoEhIntermitente((string) ($colabPorMatricula[$m]['cc_nome'] ?? '')) ? 'intermitente' : 'volante') === $tipo, ARRAY_FILTER_USE_KEY));

// 3) Agrega por quem cobriu (vol_mat), filtrando pelo tipo pedido (a categoria de quem cobriu
// decide a pagina). Periculosidade e insalubridade sao mutuamente exclusivas: se um titular tiver
// as duas num dia (erro de cadastro), esse dia NAO paga nenhuma e entra na lista de conflitos pra
// corrigir no Protheus. Chave titular+data evita alerta repetido.
$conflitosAdicional = [];
$titularSemRetrato = []; // "matricula - nome" => dias fora do calculo (aviso ao RH, abaixo)
$volanteSemSalario = []; // idem, quem cobriu sem salario: dias sem a diferenca (aviso ao RH, abaixo)
$volanteAusente = []; // idem, quem cobriu num dia em que estava ausente como titular: dias fora do calculo (aviso ao RH, abaixo)

// Falta, atestado ou afastamento de quem esta designado como volante no mesmo dia (aberta depois de designado: o aviso VOLANTE AUSENTE
// pede a troca): ele nao trabalhou, entao o dia nao e pago a ele - antes o PFM pagava ele e quem o cobriu (revisao de 2026-11-13).
// Servico extra e desligamento nao contam (ele segue trabalhando), nem 'ferias': essa linha so guarda a falta de quem cobre as ferias,
// e no posto de origem de um emprestado o titular dela e o proprio emprestado, que esta trabalhando.
$stmtAusentes = $pdo->prepare(
    "SELECT DISTINCT matricula, data FROM solicitacoes_cobertura
      WHERE STR_TO_DATE(data, '%d/%m/%Y') BETWEEN ? AND ? AND motivo IN ('falta', 'atestado', 'afastamento', 'afastamento_indeterminado')"
);
$stmtAusentes->execute([$dataInicio, $dataFim]);
$ausenteComoTitular = [];
foreach ($stmtAusentes->fetchAll() as $a) $ausenteComoTitular[$a['matricula'] . '|' . $a['data']] = true;

$agregados = [];
foreach ($coberturas as $c) {
    $vol = $colabPorMatricula[$c['vol_mat']] ?? null;
    if (!$vol) continue; // SEM_COBERTURA (nao paga ninguem) ou quem cobriu sem cadastro

    $volEhIntermitente = centroCustoEhIntermitente((string) ($vol['cc_nome'] ?? ''));
    $tipoVol = $volEhIntermitente ? 'intermitente' : 'volante';
    if ($tipoVol !== $tipo) continue;

    if (isset($ausenteComoTitular[$c['vol_mat'] . '|' . $c['data']])) {
        $chaveAviso = trim($c['vol_mat'] . ' - ' . ($vol['nome'] ?? ''), ' -');
        $volanteAusente[$chaveAviso] = ($volanteAusente[$chaveAviso] ?? 0) + 1;
        continue;
    }

    // Titular fora do cadastro (ex.: desligado buscado ao vivo no Protheus e nunca importado): do titular o PFM so usa o nome e o
    // retrato (salario/adicionais), entao vale o gravado na propria linha (snapshotTitularVaga, db.php). Sem retrato o dia fica fora
    // do calculo, mas entra no aviso ao RH (antes era descartado sem aviso).
    $titular = $colabPorMatricula[$c['titular_mat']] ?? null;
    if (!$titular) {
        if (($c['titular_salario'] ?? null) === null && ($c['titular_periculosidade'] ?? null) === null && ($c['titular_insalubridade_grau'] ?? null) === null) {
            $chaveAviso = trim($c['titular_mat'] . ' - ' . ($c['titular_nome'] ?? ''), ' -');
            $titularSemRetrato[$chaveAviso] = ($titularSemRetrato[$chaveAviso] ?? 0) + 1;
            continue;
        }
        $titular = ['nome' => $c['titular_nome'] ?? $c['titular_mat'], 'salario' => null, 'periculosidade' => null, 'insalubridade_grau' => null];
    }

    // Retrato congelado NA LINHA ($c), com fallback pro valor atual ($titular/$vol) so quando nao
    // existe (linha de ferias do SAF).
    $periculosidadeDoDia = $c['titular_periculosidade'] ?? $titular['periculosidade'];
    $insalubridadeDoDia = $c['titular_insalubridade_grau'] ?? $titular['insalubridade_grau'];
    $salarioTitularDoDia = $c['titular_salario'] ?? $titular['salario'];
    // Retrato 0,00 (gravado antes da revisao de 2026-11-13, quando o cadastro estava sem salario) vale como "sem retrato": usa o cadastro.
    $salarioVolDoDia = (float) ($c['vol_salario'] ?? 0) > 0 ? $c['vol_salario'] : $vol['salario'];

    $mat = $c['vol_mat'];
    if (!isset($agregados[$mat])) {
        $agregados[$mat] = [
            'filial' => $vol['filial'],
            'cc' => $vol['centro_custo'],
            'mat' => $mat,
            'nome' => $vol['nome'],
            'funcao' => $vol['funcao'],
            'salario' => (float) $salarioVolDoDia,
            'dias_peric' => 0,
            'dias_insal_min' => 0,
            'dias_insal_med' => 0,
            'dias_insal_max' => 0,
            'soma_dif' => 0.0,
        ];
    }

    $periculosidadeAtiva = (int) $periculosidadeDoDia === 1;
    $grauInsal = (int) $insalubridadeDoDia;

    if ($periculosidadeAtiva && $grauInsal > 0) {
        $chaveConflito = $c['titular_mat'] . '|' . $c['data'];
        if (!isset($conflitosAdicional[$chaveConflito])) {
            $conflitosAdicional[$chaveConflito] = [
                'titular_mat' => $c['titular_mat'],
                'titular_nome' => $titular['nome'],
                'data' => $c['data'],
            ];
        }
    } else {
        if ($periculosidadeAtiva) {
            $agregados[$mat]['dias_peric']++;
        }
        if ($grauInsal === 1) $agregados[$mat]['dias_insal_min']++;
        elseif ($grauInsal === 2) $agregados[$mat]['dias_insal_med']++;
        elseif ($grauInsal === 3) $agregados[$mat]['dias_insal_max']++;
    }

    // Diferenca de salario: so quando quem cobriu ganha MENOS que o titular ausente, proporcional ao
    // dia coberto.
    // Quem cobriu sem salario no cadastro: a diferenca seria o salario inteiro do titular. Fica fora e vai para o aviso ao RH.
    $salarioTitular = (float) $salarioTitularDoDia;
    $salarioVol = (float) $salarioVolDoDia;
    if ($salarioVol <= 0) {
        $chaveAviso = trim($mat . ' - ' . ($vol['nome'] ?? ''), ' -');
        $volanteSemSalario[$chaveAviso] = ($volanteSemSalario[$chaveAviso] ?? 0) + 1;
    } elseif ($salarioTitular > $salarioVol) {
        $agregados[$mat]['soma_dif'] += ($salarioTitular - $salarioVol) / 30;
    }
}
if ($volanteSemSalario) {
    $avisoSaf = trim(($avisoSaf ?? '') . ' Quem cobriu sem salário no cadastro (diferença fora do cálculo até corrigir o salário): '
        . implode(', ', array_map(fn($t, $n) => "$t ($n dia" . ($n > 1 ? 's' : '') . ')', array_keys($volanteSemSalario), $volanteSemSalario)) . '.');
}
if ($volanteAusente) {
    $avisoSaf = trim(($avisoSaf ?? '') . ' Quem cobriu em dia em que estava ausente como titular (falta, atestado ou afastamento), dias fora do cálculo até trocar quem cobre: '
        . implode(', ', array_map(fn($t, $n) => "$t ($n dia" . ($n > 1 ? 's' : '') . ')', array_keys($volanteAusente), $volanteAusente)) . '.');
}
// No aviso_saf: e o aviso da resposta que a tela mostra no sino e no toast (fopag/pfm.js).
if ($titularSemRetrato) {
    $avisoSaf = trim(($avisoSaf ?? '') . ' Titular sem cadastro no CGV e sem salário/adicionais gravados na solicitação, dias fora do cálculo: '
        . implode(', ', array_map(fn($t, $n) => "$t ($n dia" . ($n > 1 ? 's' : '') . ')', array_keys($titularSemRetrato), $titularSemRetrato)) . '.');
}

// 4) Valores em R$: periculosidade e insalubridade seguem base/30 * dias * percentual; a BASE da
// periculosidade e o salario de quem cobriu, a da insalubridade e o salario minimo informado.
$dados = [];
foreach ($agregados as $item) {
    $valorPorDiaPeric = $item['salario'] / 30;
    $valorPeric = round($valorPorDiaPeric * $item['dias_peric'] * 0.30, 2);

    $valorPorDiaMin = $salarioMinimo / 30;
    $valorInsal = round(
        ($valorPorDiaMin * $item['dias_insal_min'] * 0.10)
        + ($valorPorDiaMin * $item['dias_insal_med'] * 0.20)
        + ($valorPorDiaMin * $item['dias_insal_max'] * 0.40),
        2
    );

    $valorDif = round($item['soma_dif'], 2);

    $dados[] = [
        'filial' => $item['filial'],
        'cc' => $item['cc'],
        'mat' => $item['mat'],
        'nome' => $item['nome'],
        'funcao' => $item['funcao'],
        'salario' => $item['salario'],
        'dias_peric' => $item['dias_peric'],
        'dias_insal_total' => $item['dias_insal_min'] + $item['dias_insal_med'] + $item['dias_insal_max'],
        'valor_peric' => $valorPeric,
        'valor_insal' => $valorInsal,
        'valor_dif' => $valorDif,
    ];
}

echo json_encode([
    'periodo' => ['inicio' => $dataInicio, 'fim' => $dataFim],
    'tipo' => $tipo,
    'dados' => $dados,
    'conflitos_adicional' => array_values($conflitosAdicional),
    'aviso_saf' => $avisoSaf,
]);
