<?php
// ============================================================
// DASHBOARD - metricas das solicitacoes de cobertura (coberturas/dashboard.html), pros 3 papeis:
// RH ve todos os contratos; Preposto so o proprio centro de custo (igualdade direta); Gestor os
// titulares cujo centro e COBERTO por um dos centros dele ("Cobertura (quem cobre)", ver
// condicaoCentroTitular). Preposto nao recebe os cartoes "Quem mais cobriu dias" e "Quem mais
// ficou de base"; o resto e igual pros 3.
//
// Fonte: solicitacoes_cobertura (1 linha por dia; matricula_vol vazio = pendente,
// 'SEM_COBERTURA' = marcado sem cobertura, outro valor = coberto por aquele volante). "Quem mais cobriu
// dias" e "Quem mais ficou de base" somam tambem as coberturas de ferias do SAF.
// Uso: dashboard.php?acao=resumo&data_inicio=DD/MM/AAAA&data_fim=DD/MM/AAAA&centro_custo[]=...&funcao[]=...
// ============================================================
require_once __DIR__ . '/db.php';

$pdo = conectarBanco();
$usuarioId = verificarSessao($pdo);
header('Content-Type: application/json');

$papel = exigirPapel($pdo, $usuarioId, ['rh', 'administrador', 'gestor', 'preposto']);

// RH/Administrador tem acesso irrestrito (nunca tem linha em usuario_centros_custo); null aqui
// e o mesmo "sem filtro" do resto do sistema.
function centrosCustoDoUsuario($pdo, $usuarioId, $papel): ?array {
    return in_array($papel, ['rh', 'administrador'], true) ? null : centrosCustoLiberados($pdo, $usuarioId, $papel);
}

function erro($msg, $status = 400) {
    http_response_code($status);
    echo json_encode(['error' => $msg]);
    exit;
}

// Filtra o TITULAR (quem se ausentou, coluna $colunaCentro) pelo centro - igualdade direta pra qualquer papel.
function condicaoCentroTitular(array $centrosFiltro, string $colunaCentro): string {
    $marcadores = implode(',', array_fill(0, count($centrosFiltro), '?'));
    return "$colunaCentro IN ($marcadores)";
}

function dashParaTimestamp(string $dataBr): ?int {
    if (!preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dataBr, $m)) return null;
    [, $dia, $mes, $ano] = $m;
    if (!checkdate((int) $mes, (int) $dia, (int) $ano)) return null;
    return mktime(0, 0, 0, (int) $mes, (int) $dia, (int) $ano);
}

// Dias vizinhos da MESMA solicitacao - a regra de mesmaSolicitacao em coberturas/solicitacao.html (decisao do usuario, 2026-11-12:
// o Dashboard conta como a lista): mesmo id da solicitacao (lote_id) quando ha, inclusive em vaga; linha antiga sem ele, vaga de
// desligamento/afastamento = dias seguidos, as outras = mesmo login e criada com ate 2 min.
function mesmaSolicitacaoDash(array $a, array $b): bool {
    if (!empty($a['lote_id']) || !empty($b['lote_id'])) return (string) $a['lote_id'] === (string) $b['lote_id'];
    if (in_array($a['motivo'], ['desligamento', 'afastamento_indeterminado'], true)) return true;
    $ta = $a['criado_em'] ? strtotime((string) $a['criado_em']) : null;
    $tb = $b['criado_em'] ? strtotime((string) $b['criado_em']) : null;
    if ($ta === null || $tb === null) return $ta === $tb;
    return (string) $a['criado_por_login'] === (string) $b['criado_por_login'] && abs($ta - $tb) <= 120;
}

// Agrupa linhas (1 por dia, ordenadas por matricula/motivo/data) em "solicitacoes": dias consecutivos com a mesma matricula, motivo
// e escala, da mesma solicitacao (mesmaSolicitacaoDash) - as mesmas linhas da lista de Solicitacoes; quebra quando muda
// matricula/motivo/escala/solicitacao ou ha salto > 1 dia.
function agruparSolicitacoes(array $linhas): array {
    $grupos = [];
    $indiceAtual = -1;
    $anterior = null;
    foreach ($linhas as $linha) {
        $ts = dashParaTimestamp($linha['data']);
        if ($ts === null) continue;
        $mesmaChave = $indiceAtual >= 0
            && $grupos[$indiceAtual]['matricula'] === $linha['matricula']
            && $grupos[$indiceAtual]['motivo'] === $linha['motivo']
            && (string) $anterior['escala'] === (string) $linha['escala']
            && mesmaSolicitacaoDash($anterior, $linha);
        $contigua = $mesmaChave && ($ts - $grupos[$indiceAtual]['ultimo_ts']) === 86400;
        $anterior = $linha;
        if ($contigua) {
            $grupos[$indiceAtual]['dias'][] = $linha;
            $grupos[$indiceAtual]['ultimo_ts'] = $ts;
        } else {
            $grupos[] = [
                'matricula' => $linha['matricula'], 'nome' => $linha['nome'], 'funcao' => $linha['funcao'],
                'motivo' => $linha['motivo'], 'primeiro_ts' => $ts, 'ultimo_ts' => $ts, 'dias' => [$linha],
            ];
            $indiceAtual++;
        }
    }
    return $grupos;
}

// pendente (sem volante ainda) | coberto (volante de verdade) | sem_cobertura (marcador literal
// 'SEM_COBERTURA') - mesma constante de coberturas/solicitacao.html.
function classificarDia(array $linha): string {
    $vol = trim((string) ($linha['matricula_vol'] ?? ''));
    if ($vol === '') return 'pendente';
    // FOLGA DO TITULAR (db.php): SEM COBERTURA de dia que ninguem precisava cobrir - fora da glosa e das contas de dias.
    if ($vol === 'SEM_COBERTURA') return trim((string) ($linha['nome_vol'] ?? '')) === NOME_FOLGA_TITULAR ? 'folga' : 'sem_cobertura';
    return 'coberto';
}

$acao = $_GET['acao'] ?? '';

if ($acao === 'resumo') {
    $centrosPermitidos = centrosCustoDoUsuario($pdo, $usuarioId, $papel);
    if ($centrosPermitidos !== null && count($centrosPermitidos) === 0) {
        echo json_encode(['dados' => null]);
        exit;
    }
    // Guarda herdada: condicaoCentroTitular nao usa mais preposto_centro_cobertura; RH/Administrador so seguem se a tabela existir.
    if (!in_array($papel, ['preposto', 'gestor'], true) && !tabelaExiste($pdo, 'preposto_centro_cobertura')) {
        echo json_encode(['dados' => null]);
        exit;
    }

    // Periodo do filtro - default: ultimos 30 dias (inclui hoje).
    $hoje = date('Y-m-d');
    $dataInicioFiltro = trim((string) ($_GET['data_inicio'] ?? ''));
    $dataFimFiltro = trim((string) ($_GET['data_fim'] ?? ''));
    $tsFiltroInicio = $dataInicioFiltro !== '' ? dashParaTimestamp($dataInicioFiltro) : strtotime('-29 days', strtotime($hoje));
    $tsFiltroFim = $dataFimFiltro !== '' ? dashParaTimestamp($dataFimFiltro) : strtotime($hoje);
    if ($tsFiltroInicio === null || $tsFiltroFim === null || $tsFiltroFim < $tsFiltroInicio) {
        erro('Periodo de filtro invalido.');
    }
    $filtroInicioBr = date('d/m/Y', $tsFiltroInicio);
    $filtroFimBr = date('d/m/Y', $tsFiltroFim);

    // Filtro opcional por centro(s) de custo especificos - precisa ser um
    // subconjunto do que o usuario ja pode ver (nunca amplia o escopo).
    $centrosFiltro = $_GET['centro_custo'] ?? [];
    if (!is_array($centrosFiltro)) $centrosFiltro = [$centrosFiltro];
    $centrosFiltro = array_values(array_filter(array_map('trim', $centrosFiltro), fn($c) => $c !== ''));
    if ($centrosPermitidos !== null) {
        $centrosFiltro = $centrosFiltro
            ? array_values(array_intersect($centrosFiltro, $centrosPermitidos))
            : $centrosPermitidos;
    }
    if ($centrosPermitidos !== null && count($centrosFiltro) === 0) {
        echo json_encode(['dados' => null]);
        exit;
    }
    // Gestor/Preposto: SEMPRE filtra (e a restricao de verdade do escopo
    // deles). RH: so filtra se escolheu algum centro especifico no picker.
    $aplicarFiltroCentro = $centrosPermitidos !== null || count($centrosFiltro) > 0;

    // Filtro opcional por funcao (so afeta os rankings - titular e volante).
    $funcaoFiltro = $_GET['funcao'] ?? [];
    if (!is_array($funcaoFiltro)) $funcaoFiltro = [$funcaoFiltro];
    $funcaoFiltro = array_values(array_filter(array_map('trim', $funcaoFiltro), fn($f) => $f !== ''));

    // Linhas (1 por dia) do lado do TITULAR no periodo/centro/funcao filtrado - salario vem de colaboradores (so usado pra glosa).
    $condicoes = ["STR_TO_DATE(sc.data, '%d/%m/%Y') BETWEEN ? AND ?"];
    $parametros = [date('Y-m-d', $tsFiltroInicio), date('Y-m-d', $tsFiltroFim)];
    if ($aplicarFiltroCentro) {
        $condicoes[] = condicaoCentroTitular($centrosFiltro, 'sc.centro_custo');
        $parametros = array_merge($parametros, $centrosFiltro);
    }
    if (count($funcaoFiltro) > 0) {
        $marcadoresFuncao = implode(',', array_fill(0, count($funcaoFiltro), '?'));
        $condicoes[] = "sc.funcao IN ($marcadoresFuncao)";
        $parametros = array_merge($parametros, $funcaoFiltro);
    }
    // COALESCE(sc.titular_salario, c.salario): retrato congelado na solicitacao (ver schema.sql),
    // com fallback pro salario ATUAL so pras linhas sem retrato.
    $stmt = $pdo->prepare(
        "SELECT sc.centro_custo, sc.matricula, sc.nome, sc.funcao, sc.data, sc.motivo, sc.matricula_vol, sc.nome_vol,
                sc.escala, sc.lote_id, sc.criado_em, sc.criado_por_login,
                COALESCE(sc.titular_salario, c.salario) AS salario
           FROM solicitacoes_cobertura sc
           LEFT JOIN colaboradores c ON c.matricula = sc.matricula
          WHERE " . implode(' AND ', $condicoes) . "
       ORDER BY sc.matricula, sc.motivo, STR_TO_DATE(sc.data, '%d/%m/%Y')"
    );
    $stmt->execute($parametros);
    $linhasTitular = $stmt->fetchAll();

    // ---- Metricas por DIA (nao precisam de agrupamento em solicitacoes) ----
    $diasContagem = ['coberto' => 0, 'sem_cobertura' => 0, 'pendente' => 0];
    $glosaPorCentro = [];
    $diasPorTitular = []; // matricula -> ['nome'=>.., 'funcao'=>.., 'dias'=>N]
    // "Quem mais se ausentou" conta so falta e atestado (decisao do usuario, 2026-11-09): servico extra, afastamento e
    // desligamento ficam fora, e linha de ferias = o volante faltou (ausencia-cobertura.php), nao o titular.
    $motivosAusencia = ['falta', 'atestado'];
    foreach ($linhasTitular as $l) {
        $tipo = classificarDia($l);
        if ($tipo === 'folga') continue; // folga do titular: nao e dia sem cobertura, nem ausencia, nem glosa
        $diasContagem[$tipo]++;

        if (in_array($l['motivo'], $motivosAusencia, true)) {
            $diasPorTitular[$l['matricula']]['nome'] = $l['nome'];
            $diasPorTitular[$l['matricula']]['funcao'] = $l['funcao'] ?: '-';
            $diasPorTitular[$l['matricula']]['dias'] = ($diasPorTitular[$l['matricula']]['dias'] ?? 0) + 1;
        }

        if ($tipo === 'sem_cobertura' && $l['centro_custo']) {
            $centro = $l['centro_custo'];
            $funcao = $l['funcao'] ?: '-';
            $diaria = ((float) $l['salario']) / 30;
            if (!isset($glosaPorCentro[$centro])) {
                $glosaPorCentro[$centro] = ['valor' => 0.0, 'matriculas' => [], 'funcoes' => []];
            }
            $glosaPorCentro[$centro]['valor'] += $diaria;
            $glosaPorCentro[$centro]['matriculas'][$l['matricula']] = true;
            if (!isset($glosaPorCentro[$centro]['funcoes'][$funcao])) {
                $glosaPorCentro[$centro]['funcoes'][$funcao] = ['valor' => 0.0, 'dias' => 0];
            }
            $glosaPorCentro[$centro]['funcoes'][$funcao]['valor'] += $diaria;
            $glosaPorCentro[$centro]['funcoes'][$funcao]['dias']++;
        }
    }

    // ---- Metricas por SOLICITACAO (status/motivo/dia da semana precisam
    // do agrupamento em periodos continuos) ----
    $grupos = agruparSolicitacoes($linhasTitular);
    $statusContagem = ['aberta' => 0, 'parcial' => 0, 'fechada' => 0];
    // "Solicitacoes": conta a SOLICITACAO inteira (1 por grupo), nao cada dia: fechada com cobertura
    // (pelo menos 1 dia coberto), fechada sem cobertura (todos resolvidos, nenhum coberto) ou aberta
    // (algum dia pendente).
    $solicitacoesContagem = ['fechada_com_cobertura' => 0, 'fechada_sem_cobertura' => 0, 'aberta' => 0];
    $motivoContagem = ['falta' => 0, 'atestado' => 0, 'servico_extra' => 0, 'afastamento' => 0, 'afastamento_indeterminado' => 0, 'desligamento' => 0];
    $diasSemana = array_fill(0, 7, 0); // 0=Domingo .. 6=Sabado (date('w'))
    $quantidadePorTitular = []; // matricula -> numero de solicitacoes (grupos), pra "Quem mais se ausentou"
    foreach ($grupos as $g) {
        $diasSemana[(int) date('w', $g['primeiro_ts'])]++;
        if (isset($motivoContagem[$g['motivo']])) $motivoContagem[$g['motivo']]++;
        if (in_array($g['motivo'], $motivosAusencia, true)) $quantidadePorTitular[$g['matricula']] = ($quantidadePorTitular[$g['matricula']] ?? 0) + 1;

        // Folga do titular conta como dia resolvido sem cobertura (a solicitacao nao fica "aberta" por ela).
        $tipos = array_map(fn($l) => classificarDia($l) === 'folga' ? 'sem_cobertura' : classificarDia($l), $g['dias']);
        if (!in_array('coberto', $tipos, true) && !in_array('sem_cobertura', $tipos, true)) {
            $statusContagem['aberta']++;
        } elseif (in_array('pendente', $tipos, true)) {
            $statusContagem['parcial']++;
        } else {
            $statusContagem['fechada']++;
        }

        if (in_array('pendente', $tipos, true)) {
            $solicitacoesContagem['aberta']++;
        } elseif (in_array('coberto', $tipos, true)) {
            $solicitacoesContagem['fechada_com_cobertura']++;
        } else {
            $solicitacoesContagem['fechada_sem_cobertura']++;
        }
    }

    // "Quem mais se ausentou" - soma de dias no filtro, a partir de 1 dia (decisao do usuario, 2026-11-09; com 4 dias ou mais um
    // filtro curto ficava sempre vazio), sem limite de quantidade (a lista do cartao rola).
    $topAusencias = array_map(
        fn($matricula, $info) => [
            'matricula' => $matricula, 'nome' => $info['nome'], 'funcao' => $info['funcao'], 'dias' => $info['dias'],
            'quantidade' => $quantidadePorTitular[$matricula] ?? 0,
        ],
        array_keys($diasPorTitular),
        $diasPorTitular
    );
    usort($topAusencias, fn($a, $b) => $b['dias'] <=> $a['dias']);

    // ---- Cartoes exclusivos de quem designa cobertura (RH/Gestor). "Glosa por posto" fica fora
    // (bloco proprio abaixo): Preposto tambem ve a glosa dos proprios contratos. ----
    $avisoSaf = null;
    $topCobriram = [];
    $quemFicouBase = [];
    $topGlosa = [];
    $totalGlosa = 0.0;

    if ($papel !== 'preposto') {
        // Os 2 cartoes sao dos VOLANTES: os da aba Volantes Ociosos (centro de volante sem intermitente; do Gestor so o pool dele,
        // centrosVolantesOciosos) ativos no periodo, com a funcao do filtro. O filtro de contrato vale do lado do TITULAR: "Quem mais
        // cobriu dias" conta so as coberturas feitas para os contratos filtrados. Antes o centro do volante era comparado com os
        // centros de titular (o Gestor nunca via ninguem) e "Quem mais ficou sem fazer cobertura" listava a equipe comum ou, do RH sem
        // filtro, o cadastro inteiro (intermitentes e desligados inclusive).
        require_once __DIR__ . '/../scripts/ociosidade-helpers.php';
        $inicioIso = date('Y-m-d', $tsFiltroInicio);
        $fimIso = date('Y-m-d', $tsFiltroFim);
        $centrosCustoTodos = $pdo->query('SELECT codigo, descricao FROM centros_custo')->fetchAll();
        $centrosVolante = centrosVolantesOciosos($pdo, $papel, (int) $usuarioId, $centrosCustoTodos,
            array_column(array_filter($centrosCustoTodos, fn($c) => centroCustoEhVolante((string) $c['codigo'], (string) ($c['descricao'] ?? ''))), 'codigo'));
        $volantesDash = []; // matricula => nome e funcao
        if ($centrosVolante) {
            $condicoesPop = ['centro_custo IN (' . implode(',', array_fill(0, count($centrosVolante), '?')) . ')'];
            $paramsPop = $centrosVolante;
            if (count($funcaoFiltro) > 0) {
                $condicoesPop[] = 'funcao IN (' . implode(',', array_fill(0, count($funcaoFiltro), '?')) . ')';
                $paramsPop = array_merge($paramsPop, $funcaoFiltro);
            }
            $stmtPop = $pdo->prepare('SELECT matricula, nome, funcao, data_admissao, desligamento FROM colaboradores WHERE ' . implode(' AND ', $condicoesPop));
            $stmtPop->execute($paramsPop);
            $iso = fn($d) => ($x = DateTime::createFromFormat('!d/m/Y', (string) $d)) ? $x->format('Y-m-d') : '';
            foreach ($stmtPop->fetchAll() as $c) {
                if (($iso($c['data_admissao']) ?: $inicioIso) > $fimIso || ($iso($c['desligamento']) ?: $fimIso) < $inicioIso) continue; // fora do periodo
                $volantesDash[(string) $c['matricula']] = ['nome' => $c['nome'], 'funcao' => $c['funcao'] ?: '-'];
            }
        }

        // "Quem mais cobriu dias": coberturas do CGV + de ferias do SAF no periodo, so as dos contratos filtrados.
        $condicoesVol = [
            "STR_TO_DATE(sc.data, '%d/%m/%Y') BETWEEN ? AND ?",
            "sc.matricula_vol IS NOT NULL", "sc.matricula_vol <> ''", "sc.matricula_vol <> 'SEM_COBERTURA'",
        ];
        $parametrosVol = [$inicioIso, $fimIso];
        if ($aplicarFiltroCentro) {
            $condicoesVol[] = condicaoCentroTitular($centrosFiltro, 'sc.centro_custo');
            $parametrosVol = array_merge($parametrosVol, $centrosFiltro);
        }
        $stmtVol = $pdo->prepare("SELECT sc.matricula AS titular_mat, sc.data, sc.matricula_vol AS vol_mat FROM solicitacoes_cobertura sc WHERE "
            . implode(' AND ', $condicoesVol));
        $stmtVol->execute($parametrosVol);
        // Dia do titular pago pelas ferias do SAF nao conta a linha do CGV dele (a ferias tem prioridade).
        $diasFeriasSaf = diasCoberturaFeriasSaf($pdo, $inicioIso, $fimIso, $avisoSaf);
        $linhasCgv = semDiasDeFeriasSaf($stmtVol->fetchAll(), $diasFeriasSaf, $foraFerias);
        $avisoSaf = avisoForaPorFeriasSaf($avisoSaf, $foraFerias);
        $diasPorVolante = [];
        foreach ($linhasCgv as $linha) $diasPorVolante[$linha['vol_mat']] = ($diasPorVolante[$linha['vol_mat']] ?? 0) + 1;
        foreach ($diasFeriasSaf as $d) {
            if ($aplicarFiltroCentro && !in_array($d['centro_custo'] ?? '', $centrosFiltro, true)) continue; // contrato do titular das ferias
            $diasPorVolante[$d['vol_mat']] = ($diasPorVolante[$d['vol_mat']] ?? 0) + 1;
        }
        foreach ($volantesDash as $matricula => $v) {
            if (!empty($diasPorVolante[$matricula])) $topCobriram[] = ['matricula' => (string) $matricula] + $v + ['dias' => $diasPorVolante[$matricula]];
        }
        usort($topCobriram, fn($a, $b) => $b['dias'] <=> $a['dias']);

        // "Quem mais ficou sem fazer cobertura": os dias livres de cada um no periodo, a conta da aba Volantes Ociosos
        // (diasOciososVolantes: sem cobertura, ferias, ausencia nem vaga em aberto; antes da admissao e depois do desligamento nao
        // contam). Nao depende do contrato filtrado: dia livre e livre para qualquer contrato.
        $ociosos = $volantesDash ? diasOciososVolantes($pdo, feriasSafComVolante($pdo, $avisoOciosos), $centrosVolante, $inicioIso, $fimIso) : [];
        foreach ($volantesDash as $matricula => $v) {
            $quemFicouBase[] = ['matricula' => (string) $matricula] + $v + ['dias' => count($ociosos[$matricula] ?? [])];
        }
        usort($quemFicouBase, fn($a, $b) => $b['dias'] <=> $a['dias']);
    }

    // Glosa por posto: ja acumulada por centro na primeira passagem (linhasTitular, escopada por
    // papel via condicaoCentroTitular); falta so o rotulo (Nome - Codigo, formatarCentroCusto no
    // frontend) e ordenar. Roda pra TODOS os papeis.
    $descricoesCentro = [];
    if (count($glosaPorCentro) > 0 && tabelaExiste($pdo, 'centros_custo')) {
        $codigosGlosa = array_keys($glosaPorCentro);
        $marcadoresDesc = implode(',', array_fill(0, count($codigosGlosa), '?'));
        $stmtDesc = $pdo->prepare("SELECT codigo, descricao FROM centros_custo WHERE codigo IN ($marcadoresDesc)");
        $stmtDesc->execute($codigosGlosa);
        foreach ($stmtDesc->fetchAll() as $d) $descricoesCentro[$d['codigo']] = $d['descricao'];
    }
    foreach ($glosaPorCentro as $centro => $info) {
        $funcoesGlosa = [];
        foreach ($info['funcoes'] as $funcao => $fInfo) {
            $funcoesGlosa[] = ['funcao' => $funcao, 'dias' => $fInfo['dias'], 'valor' => round($fInfo['valor'], 2)];
        }
        usort($funcoesGlosa, fn($a, $b) => $b['valor'] <=> $a['valor']);
        $topGlosa[] = [
            'centro_custo' => $centro,
            'rotulo' => isset($descricoesCentro[$centro]) ? "{$descricoesCentro[$centro]} - $centro" : $centro,
            'valor' => round($info['valor'], 2),
            'colaboradores' => count($info['matriculas']),
            'funcoes' => $funcoesGlosa,
        ];
    }
    usort($topGlosa, fn($a, $b) => $b['valor'] <=> $a['valor']);
    $totalGlosa = round(array_sum(array_column($topGlosa, 'valor')), 2);

    echo json_encode(['dados' => [
        'periodo' => ['data_inicio' => $filtroInicioBr, 'data_fim' => $filtroFimBr],
        'total_solicitacoes' => count($grupos),
        'status_contagem' => $statusContagem,
        'solicitacoes_contagem' => $solicitacoesContagem,
        'motivo_contagem' => $motivoContagem,
        'dias_semana' => $diasSemana,
        'top_cobriram' => $topCobriram,
        'top_ausencias' => $topAusencias,
        'quem_ficou_base' => $quemFicouBase,
        'top_glosa' => $topGlosa,
        'total_glosa' => $totalGlosa,
        'aviso_saf' => $avisoSaf,
    ]]);
    exit;
}

// Centros de custo pro filtro da tela: so os com pelo menos 1 solicitacao no periodo filtrado.
// RH: sem restricao de permissao; Gestor/Preposto: intersecao com os proprios centros liberados
// (condicaoCentroTitular).
if ($acao === 'centros_custo') {
    $centrosPermitidos = centrosCustoDoUsuario($pdo, $usuarioId, $papel);
    if ($centrosPermitidos !== null && count($centrosPermitidos) === 0) {
        echo json_encode(['dados' => []]);
        exit;
    }

    $hoje = date('Y-m-d');
    $dataInicioFiltro = trim((string) ($_GET['data_inicio'] ?? ''));
    $dataFimFiltro = trim((string) ($_GET['data_fim'] ?? ''));
    $tsFiltroInicio = $dataInicioFiltro !== '' ? dashParaTimestamp($dataInicioFiltro) : strtotime('-29 days', strtotime($hoje));
    $tsFiltroFim = $dataFimFiltro !== '' ? dashParaTimestamp($dataFimFiltro) : strtotime($hoje);
    if ($tsFiltroInicio === null || $tsFiltroFim === null || $tsFiltroFim < $tsFiltroInicio) {
        erro('Periodo de filtro invalido.');
    }

    $condicoes = ["STR_TO_DATE(data, '%d/%m/%Y') BETWEEN ? AND ?"];
    $params = [date('Y-m-d', $tsFiltroInicio), date('Y-m-d', $tsFiltroFim)];
    if ($centrosPermitidos !== null) {
        $condicoes[] = condicaoCentroTitular($centrosPermitidos, 'centro_custo');
        $params = array_merge($params, $centrosPermitidos);
    }
    $stmt = $pdo->prepare(
        "SELECT DISTINCT centro_custo FROM solicitacoes_cobertura
          WHERE " . implode(' AND ', $condicoes) . " AND centro_custo IS NOT NULL AND centro_custo <> ''
       ORDER BY centro_custo"
    );
    $stmt->execute($params);
    echo json_encode(['dados' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
    exit;
}

// Lista de funcoes pra popular o filtro de funcao - uniao das funcoes que
// de fato aparecem (titular ou volante) no periodo/centro filtrado, senao
// a lista fica cheia de opcao que nao muda nada ao selecionar.
if ($acao === 'funcoes') {
    $centrosPermitidos = centrosCustoDoUsuario($pdo, $usuarioId, $papel);
    if ($centrosPermitidos !== null && count($centrosPermitidos) === 0) {
        echo json_encode(['dados' => []]);
        exit;
    }
    // Guarda herdada: condicaoCentroTitular nao usa mais preposto_centro_cobertura; RH/Administrador so seguem se a tabela existir.
    if (!in_array($papel, ['preposto', 'gestor'], true) && !tabelaExiste($pdo, 'preposto_centro_cobertura')) {
        echo json_encode(['dados' => []]);
        exit;
    }

    $hoje = date('Y-m-d');
    $dataInicioFiltro = trim((string) ($_GET['data_inicio'] ?? ''));
    $dataFimFiltro = trim((string) ($_GET['data_fim'] ?? ''));
    $tsFiltroInicio = $dataInicioFiltro !== '' ? dashParaTimestamp($dataInicioFiltro) : strtotime('-29 days', strtotime($hoje));
    $tsFiltroFim = $dataFimFiltro !== '' ? dashParaTimestamp($dataFimFiltro) : strtotime($hoje);
    if ($tsFiltroInicio === null || $tsFiltroFim === null || $tsFiltroFim < $tsFiltroInicio) {
        erro('Periodo de filtro invalido.');
    }

    $centrosFiltro = $_GET['centro_custo'] ?? [];
    if (!is_array($centrosFiltro)) $centrosFiltro = [$centrosFiltro];
    $centrosFiltro = array_values(array_filter(array_map('trim', $centrosFiltro), fn($c) => $c !== ''));
    if ($centrosPermitidos !== null) {
        $centrosFiltro = $centrosFiltro
            ? array_values(array_intersect($centrosFiltro, $centrosPermitidos))
            : $centrosPermitidos;
        if (count($centrosFiltro) === 0) {
            echo json_encode(['dados' => []]);
            exit;
        }
    }
    $aplicarFiltroCentro = $centrosPermitidos !== null || count($centrosFiltro) > 0;

    $funcoes = [];

    $condicoesTit = ["STR_TO_DATE(data, '%d/%m/%Y') BETWEEN ? AND ?"];
    $paramsTit = [date('Y-m-d', $tsFiltroInicio), date('Y-m-d', $tsFiltroFim)];
    if ($aplicarFiltroCentro) {
        $condicoesTit[] = condicaoCentroTitular($centrosFiltro, 'centro_custo');
        $paramsTit = array_merge($paramsTit, $centrosFiltro);
    }
    $stmtTit = $pdo->prepare(
        "SELECT DISTINCT funcao FROM solicitacoes_cobertura
          WHERE " . implode(' AND ', $condicoesTit) . " AND funcao IS NOT NULL AND funcao <> ''"
    );
    $stmtTit->execute($paramsTit);
    foreach ($stmtTit->fetchAll(PDO::FETCH_COLUMN) as $f) $funcoes[$f] = true;

    if ($papel !== 'preposto') {
        $condicoesVol = [
            "STR_TO_DATE(sc.data, '%d/%m/%Y') BETWEEN ? AND ?",
            "sc.matricula_vol IS NOT NULL", "sc.matricula_vol <> ''", "sc.matricula_vol <> 'SEM_COBERTURA'",
        ];
        $paramsVol = [date('Y-m-d', $tsFiltroInicio), date('Y-m-d', $tsFiltroFim)];
        if ($aplicarFiltroCentro) {
            $marcadoresVol = implode(',', array_fill(0, count($centrosFiltro), '?'));
            $condicoesVol[] = "sc.centro_custo IN ($marcadoresVol)"; // contrato do titular, como nos cartoes
            $paramsVol = array_merge($paramsVol, $centrosFiltro);
        }
        $stmtVol = $pdo->prepare(
            "SELECT DISTINCT cv.funcao
               FROM solicitacoes_cobertura sc
               JOIN colaboradores cv ON cv.matricula = sc.matricula_vol
              WHERE " . implode(' AND ', $condicoesVol) . "
                AND cv.funcao IS NOT NULL AND cv.funcao <> ''"
        );
        $stmtVol->execute($paramsVol);
        foreach ($stmtVol->fetchAll(PDO::FETCH_COLUMN) as $f) $funcoes[$f] = true;
    }

    $listaFuncoes = array_keys($funcoes);
    sort($listaFuncoes, SORT_STRING | SORT_FLAG_CASE);
    echo json_encode(['dados' => $listaFuncoes]);
    exit;
}

erro('Acao invalida: ' . $acao);
