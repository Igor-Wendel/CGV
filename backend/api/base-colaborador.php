<?php
// ============================================================
// BASE DO VOLANTE
//
// Volante alocado num contrato (centro de custo) por um periodo - "Nova Base" no painel do
// colaborador (colaboradores/funcionarios.html). 1 linha por dia em colaborador_bases,
// 1 base por volante por dia (trava no banco).
//
// Regras:
// - A base nova SUBSTITUI a anterior nos dias que se cruzam.
// - Periodo com inicio E fim obrigatorios; nao pode cair em dia de ferias do volante (arquivo do SAF).
// - Quem vence num mesmo dia: cobertura/ferias do SAF > capacitacao > base. Nao bloqueia: o dia de base
//   continua gravado, so "nao vale" (campo "sobreposta" na listagem).
// - Nao entra no PFM (pfm-calculo.php so le solicitacoes_cobertura).
//
// GET  ?acao=listar                    -> { bases: [...] } (escopo por papel)
// POST ?acao=salvar  { matricula, inicio, fim (AAAA-MM-DD), centro_custo }
// POST ?acao=remover { matricula, inicio, fim } -> tira a base nesses dias
// POST ?acao=ausencia { id }           -> Preposto: volante nao compareceu hoje
// ============================================================
require_once __DIR__ . '/db.php';

$pdo = conectarBanco();
$usuarioId = verificarSessao($pdo);
header('Content-Type: application/json');

function erroBase(string $msg, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['error' => $msg]);
    exit;
}

if (!tabelaExiste($pdo, 'colaborador_bases')) {
    erroBase('Tabela de base ainda nao existe - rode ferramentas/migrar-banco.php.', 500);
}

$acao = $_GET['acao'] ?? '';
$papel = papelDoUsuario($pdo, $usuarioId);
$corpo = json_decode(file_get_contents('php://input'), true) ?: [];

// Gestor so mexe/ve volante do proprio grupo ("Cobertura (quem cobre)"; o incluido pelo "Adicionar colaborador" tambem pelo
// centro de destino, sqlColaboradorNoPool) ou base nos contratos dele. null = irrestrito.
function gestorAlcancaVolante($pdo, $usuarioId, string $matricula): bool {
    $pool = coberturaGestorLiberada($pdo, $usuarioId);
    if ($pool === null) return true;
    if (count($pool) === 0) return false;
    $stmt = $pdo->prepare('SELECT 1 FROM colaboradores WHERE matricula = ? AND ' . sqlColaboradorNoPool(count($pool)) . ' LIMIT 1');
    $stmt->execute(array_merge([$matricula], $pool, $pool));
    return (bool) $stmt->fetchColumn();
}

// ------------------------------------------------------------
if ($acao === 'listar') {
    exigirPapel($pdo, $usuarioId, ['rh', 'administrador', 'gestor', 'preposto']);

    $onde = '1 = 1';
    $params = [];
    if ($papel === 'preposto') {
        $centros = centrosCustoLiberados($pdo, $usuarioId, 'preposto');
        if ($centros !== null) {
            if (count($centros) === 0) { echo json_encode(['bases' => []]); exit; }
            $onde = 'b.centro_custo IN (' . implode(',', array_fill(0, count($centros), '?')) . ')';
            $params = $centros;
        }
    } elseif ($papel === 'gestor') {
        $centros = centrosCustoLiberados($pdo, $usuarioId, 'gestor');
        $pool = coberturaGestorLiberada($pdo, $usuarioId);
        if ($centros !== null && $pool !== null) {
            $partes = [];
            if (count($centros) > 0) {
                $partes[] = 'b.centro_custo IN (' . implode(',', array_fill(0, count($centros), '?')) . ')';
                $params = array_merge($params, $centros);
            }
            if (count($pool) > 0) {
                $partes[] = 'b.matricula IN (SELECT matricula FROM colaboradores WHERE ' . sqlColaboradorNoPool(count($pool)) . ')';
                $params = array_merge($params, $pool, $pool);
            }
            if (count($partes) === 0) { echo json_encode(['bases' => []]); exit; }
            $onde = '(' . implode(' OR ', $partes) . ')';
        }
    }

    // sobreposta: o dia de base "nao vale" se o volante tem cobertura (de QUALQUER centro - o
    // Preposto da base nao a enxerga) ou capacitacao nesse dia.
    $stmt = $pdo->prepare(
        "SELECT b.id, b.matricula, b.nome, b.data, b.centro_custo, b.ausencia_em,
                CASE
                  WHEN EXISTS (SELECT 1 FROM solicitacoes_cobertura sc
                                WHERE sc.matricula_vol = b.matricula AND sc.data = b.data
                                  AND sc.matricula_vol <> 'SEM_COBERTURA') THEN 'cobertura'
                  WHEN EXISTS (SELECT 1 FROM colaborador_capacitacoes cp
                                WHERE cp.matricula = b.matricula AND cp.data = b.data) THEN 'capacitacao'
                  ELSE NULL
                END AS sobreposta,
                b.data = DATE_FORMAT(CURDATE(), '%d/%m/%Y') AS eh_hoje
           FROM colaborador_bases b
          WHERE $onde"
    );
    $stmt->execute($params);
    $bases = $stmt->fetchAll();

    // Ferias do SAF (o proprio volante ou quem ele cobre) tira do dia de base, em qualquer centro.
    $feriasPorMatricula = [];
    foreach (feriasSafComVolante($pdo, $avisoSaf) as $f) {
        $feriasPorMatricula[(string) $f['matricula']][] = [$f['ferias_inicio'], $f['ferias_fim']];
        if ($f['matricula_vol']) $feriasPorMatricula[(string) $f['matricula_vol']][] = [$f['cobertura_inicio'], $f['ferias_fim']];
    }
    foreach ($bases as &$b) {
        if ($b['sobreposta']) continue;
        $dia = implode('-', array_reverse(explode('/', $b['data'])));
        foreach ($feriasPorMatricula[(string) $b['matricula']] ?? [] as [$inicio, $fim]) {
            if ($dia >= $inicio && $dia <= $fim) $b['sobreposta'] = 'ferias';
        }
    }
    unset($b);
    echo json_encode(['bases' => $bases]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') erroBase('Metodo invalido.', 405);

// ------------------------------------------------------------
if ($acao === 'salvar') {
    exigirPapel($pdo, $usuarioId, ['rh', 'administrador', 'gestor']);

    $matricula = trim((string) ($corpo['matricula'] ?? ''));
    $centro = strtoupper(trim((string) ($corpo['centro_custo'] ?? '')));
    $inicio = DateTime::createFromFormat('!Y-m-d', (string) ($corpo['inicio'] ?? ''));
    $fim = DateTime::createFromFormat('!Y-m-d', (string) ($corpo['fim'] ?? ''));

    if ($matricula === '') erroBase('Colaborador nao informado.');
    if ($centro === '') erroBase('Escolha o contrato da base.');
    if (!$inicio || !$fim) erroBase('Informe a data de inicio e de fim da base.');
    if ($fim < $inicio) erroBase('A data de fim nao pode ser antes do inicio.');
    if ($inicio->diff($fim)->days > 366) erroBase('O periodo da base pode ter no maximo 1 ano.');

    // So contrato com colaborador ativo no Protheus (mesma lista da busca na tela).
    $stmtCc = $pdo->prepare('SELECT descricao FROM centros_custo WHERE codigo = ? AND tem_colaborador = 1 LIMIT 1');
    $stmtCc->execute([$centro]);
    if ($stmtCc->fetchColumn() === false) erroBase('Contrato nao encontrado entre os contratos com colaborador ativo.', 404);

    $stmtColab = $pdo->prepare('SELECT nome FROM colaboradores WHERE matricula = ? ORDER BY id LIMIT 1');
    $stmtColab->execute([$matricula]);
    $colab = $stmtColab->fetch();
    if (!$colab) erroBase('Colaborador nao encontrado.', 404);
    if ($papel === 'gestor' && !gestorAlcancaVolante($pdo, $usuarioId, $matricula)) {
        erroBase('Este colaborador nao faz parte do seu grupo de volantes.', 403);
    }
    $desligamento = desligamentoDoColaborador($pdo, $matricula);
    if ($desligamento && $fim > $desligamento) {
        erroBase('O colaborador tem desligamento em ' . $desligamento->format('d/m/Y') . ' - a base nao pode passar dessa data.', 409);
    }

    // Ferias bloqueia (nao da pra alocar quem esta de ferias).
    foreach (feriasSafComVolante($pdo, $avisoSaf) as $f) {
        // Posto de origem do emprestado e trecho de quem cobria antes de uma troca nao sao ferias (a ferias e a linha dela).
        if (!empty($f['posto_origem']) || !empty($f['trecho_anterior']) || (string) $f['matricula'] !== (string) $matricula) continue;
        if ($inicio->format('Y-m-d') <= $f['ferias_fim'] && $fim->format('Y-m-d') >= $f['ferias_inicio']) {
            erroBase('O colaborador esta de ferias de ' . date('d/m/Y', strtotime($f['ferias_inicio'])) . ' a '
                . date('d/m/Y', strtotime($f['ferias_fim'])) . ' - a base nao pode cair nesse periodo.', 409);
        }
    }

    $dias = [];
    for ($d = clone $inicio; $d <= $fim; $d->modify('+1 day')) $dias[] = $d->format('d/m/Y');
    $marcadores = implode(',', array_fill(0, count($dias), '?'));

    $pdo->beginTransaction();
    try {
        // A base nova substitui a anterior nesses dias (regra do usuario).
        $stmtAntes = $pdo->prepare("SELECT data, centro_custo FROM colaborador_bases WHERE matricula = ? AND data IN ($marcadores) FOR UPDATE");
        $stmtAntes->execute(array_merge([$matricula], $dias));
        $substituidos = $stmtAntes->fetchAll();

        $pdo->prepare("DELETE FROM colaborador_bases WHERE matricula = ? AND data IN ($marcadores)")
            ->execute(array_merge([$matricula], $dias));
        $stmtIns = $pdo->prepare(
            'INSERT INTO colaborador_bases (matricula, nome, data, centro_custo, criado_em, criado_por) VALUES (?, ?, ?, ?, NOW(), ?)'
        );
        foreach ($dias as $dia) $stmtIns->execute([$matricula, $colab['nome'], $dia, $centro, $usuarioId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Falha ao salvar base: ' . $e->getMessage());
        erroBase('Nao foi possivel salvar a base.', 500);
    }

    $outrosContratos = count(array_filter($substituidos, fn($s) => $s['centro_custo'] !== $centro));
    registrarAuditoria($pdo, $usuarioId, 'salvar_base', [
        'tabela' => 'colaborador_bases',
        'quantidade' => count($dias),
        'detalhe' => "matricula $matricula, centro $centro, " . $inicio->format('d/m/Y') . ' a ' . $fim->format('d/m/Y')
            . ($outrosContratos ? ", $outrosContratos dia(s) substituido(s) de outra base" : ''),
    ]);
    echo json_encode(['success' => true, 'dias' => count($dias), 'substituidos_outro_contrato' => $outrosContratos]);
    exit;
}

// ------------------------------------------------------------
// Mesmo jeito de escolher que o "salvar": seleciona o periodo no calendario
// e tira a base dos dias dele (os dias sem base no meio sao ignorados).
if ($acao === 'remover') {
    exigirPapel($pdo, $usuarioId, ['rh', 'administrador', 'gestor']);
    $matricula = trim((string) ($corpo['matricula'] ?? ''));
    $inicio = DateTime::createFromFormat('!Y-m-d', (string) ($corpo['inicio'] ?? ''));
    $fim = DateTime::createFromFormat('!Y-m-d', (string) ($corpo['fim'] ?? ''));
    if ($matricula === '' || !$inicio || !$fim || $fim < $inicio) erroBase('Informe o colaborador e o periodo.');
    if ($inicio->diff($fim)->days > 366) erroBase('O periodo pode ter no maximo 1 ano.');
    if ($papel === 'gestor' && !gestorAlcancaVolante($pdo, $usuarioId, $matricula)) {
        erroBase('Este colaborador nao faz parte do seu grupo de volantes.', 403);
    }
    $dias = [];
    for ($d = clone $inicio; $d <= $fim; $d->modify('+1 day')) $dias[] = $d->format('d/m/Y');
    $marcadores = implode(',', array_fill(0, count($dias), '?'));
    $stmt = $pdo->prepare("DELETE FROM colaborador_bases WHERE matricula = ? AND data IN ($marcadores)");
    $stmt->execute(array_merge([$matricula], $dias));
    $removidos = $stmt->rowCount();
    if ($removidos > 0) {
        registrarAuditoria($pdo, $usuarioId, 'remover_base', [
            'tabela' => 'colaborador_bases',
            'quantidade' => $removidos,
            'detalhe' => "matricula $matricula, " . $inicio->format('d/m/Y') . ' a ' . $fim->format('d/m/Y'),
        ]);
    }
    echo json_encode(['success' => true, 'removidos' => $removidos]);
    exit;
}

// ------------------------------------------------------------
// Ausencia na base: mesmo espirito da ausencia de cobertura (ausencia-
// cobertura.php) - so o dia de hoje, so o Preposto do contrato da base.
// Desfazer = RH/Gestor salvar a base de novo nesse dia (a linha e recriada
// sem a ausencia).
if ($acao === 'ausencia') {
    exigirPapel($pdo, $usuarioId, ['preposto']);
    $id = (int) ($corpo['id'] ?? 0);
    $centros = centrosCustoLiberados($pdo, $usuarioId, 'preposto');

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "SELECT id, matricula, nome, data, centro_custo, ausencia_em,
                data = DATE_FORMAT(CURDATE(), '%d/%m/%Y') AS eh_hoje
           FROM colaborador_bases WHERE id = ? FOR UPDATE"
    );
    $stmt->execute([$id]);
    $linha = $stmt->fetch();
    $recusa = null;
    if (!$linha) $recusa = ['Dia de base nao encontrado. Recarregue a pagina.', 404];
    elseif ($centros !== null && !in_array($linha['centro_custo'], $centros, true)) $recusa = ['Esta base esta fora do seu centro de custo.', 403];
    elseif (!(int) $linha['eh_hoje']) $recusa = ['So e possivel marcar ausencia no dia de hoje.', 409];
    elseif ($linha['ausencia_em'] !== null) $recusa = ['A ausencia deste dia ja foi registrada.', 409];
    if ($recusa) {
        $pdo->rollBack();
        erroBase($recusa[0], $recusa[1]);
    }
    $pdo->prepare('UPDATE colaborador_bases SET ausencia_em = NOW() WHERE id = ?')->execute([$id]);
    $pdo->commit();

    $nome = $linha['nome'] ?: $linha['matricula'];
    criarNotificacoes(
        $pdo,
        destinatariosCobertura($pdo, (string) $linha['centro_custo'], (string) $linha['matricula']),
        'ausencia',
        "AUSÊNCIA NA BASE: $nome não compareceu à base em " . rotuloCentroCusto($pdo, (string) $linha['centro_custo'])
            . " em {$linha['data']}.",
        null,
        $usuarioId
    );
    registrarAuditoria($pdo, $usuarioId, 'ausencia_de_cobertura', [
        'tabela' => 'colaborador_bases',
        'quantidade' => 1,
        'detalhe' => "$nome ({$linha['matricula']}) nao compareceu a base no centro {$linha['centro_custo']} em {$linha['data']}",
    ]);
    echo json_encode(['success' => true]);
    exit;
}

erroBase('Acao invalida.');
