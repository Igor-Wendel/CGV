<?php
// ============================================================
// DESIGNAR VOLANTE direto em linhas de solicitacoes_cobertura, pela grade do RH
// (coberturas/solicitacao.html). Endpoint proprio (fora do dados.php) por causa da regra: um
// volante nao pode cobrir duas coisas no mesmo dia (mesma regra de cobertura-gestor.php, aqui
// verificada contra solicitacoes_cobertura).
// Uso: cobertura-solicitacao.php?acao=designar
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../scripts/protheus-colaboradores-helpers.php'; // codigosCentrosVolante

$pdo = conectarBanco();
$usuarioId = verificarSessao($pdo);
header('Content-Type: application/json');

// RH gerencia esta grade; o Gestor tem tela propria, mas tambem e aceito aqui.
$papel = exigirPapel($pdo, $usuarioId, ['rh', 'administrador', 'gestor']);

// Gestor: designacao valida se o TITULAR da linha esta no Centro de Custo dele OU o VOLANTE e
// do pool dele (Cobertura/quem cobre) - mesma checagem de dados.php ($coberturaGestor). O
// redirecionamento da tela nao protege a API.
$centrosPermitidos = ($papel === 'gestor') ? centrosCustoLiberados($pdo, $usuarioId, 'gestor') : null;
$coberturaGestor = ($papel === 'gestor') ? coberturaGestorLiberada($pdo, $usuarioId) : null;

function erro($msg, $status = 400) {
    http_response_code($status);
    echo json_encode(['error' => $msg]);
    exit;
}

if (($_GET['acao'] ?? '') !== 'designar') {
    erro('Acao invalida.');
}

$corpo = json_decode(file_get_contents('php://input'), true);
$ids = array_values(array_filter(array_map('intval', (array) ($corpo['ids'] ?? [])), fn($v) => $v > 0));
$matriculaVol = trim((string) ($corpo['matricula_vol'] ?? ''));

if (count($ids) === 0) erro('Selecione ao menos um dia.');
if ($matriculaVol === '') erro('Selecione um colaborador para a cobertura.');
// FOLGA DO TITULAR (NOME_FOLGA_TITULAR, db.php): dia de folga da escala do titular. Grava como SEM COBERTURA com esse nome - o resto
// desta tela (conflito, prazo, ferias do SAF) e o PFM tratam como SEM COBERTURA; so a glosa do Dashboard o deixa de fora.
$folgaTitular = $matriculaVol === 'FOLGA_TITULAR';
if ($folgaTitular) $matriculaVol = 'SEM_COBERTURA';

$marcadoresIds = implode(',', array_fill(0, count($ids), '?'));
// Trava por volante (item 6 da analise de 2026-11-12, como a trava por titular da abertura de vaga): 2 abas designando a mesma pessoa
// em postos diferentes no mesmo dia passavam juntas pela checagem de conflito abaixo. A segunda espera e ve o conflito. A conexao
// fechada no fim da requisicao solta. SEM_COBERTURA nao e pessoa.
if ($matriculaVol !== 'SEM_COBERTURA') {
    $stmtTrava = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $stmtTrava->execute(['designar_vol_' . $matriculaVol]);
    if ($stmtTrava->fetchColumn() != 1) erro('Outra designação dessa pessoa está sendo gravada agora - tente de novo em instantes.', 409);
    // E a trava de titular da abertura (travarTitulares, db.php, revisao de 2026-11-13): uma falta/vaga dela sendo aberta agora espera
    // ou e vista pelo N2 abaixo - sem isso as duas passavam juntas e o PFM pagaria ela e quem a cobrisse.
    $stmtTrava->execute(['vaga_titular_' . $matriculaVol]);
    if ($stmtTrava->fetchColumn() != 1) erro('Uma solicitação dessa pessoa está sendo aberta agora - tente de novo em instantes.', 409);
}
$stmt = $pdo->prepare("SELECT id, matricula, nome, data, matricula_vol, nome_vol, fechado_em, centro_custo, ausencia_matricula_vol, ausencia_nome_vol FROM solicitacoes_cobertura WHERE id IN ($marcadoresIds)");
$stmt->execute($ids);
$linhas = $stmt->fetchAll();

if (count($linhas) !== count($ids)) erro('Uma ou mais linhas selecionadas nao foram encontradas.', 404);

// Gestor restrito: volante do proprio pool cobre qualquer titular; senao o titular de cada
// linha precisa estar no Centro de Custo dele.
if ($centrosPermitidos !== null) {
    $volanteDoProprioPool = false;
    if ($matriculaVol !== 'SEM_COBERTURA') {
        // Pool 'TODOS' (null) = qualquer volante, mas tem que ser volante (nao qualquer colaborador). O incluido pelo "Adicionar
        // colaborador" conta tambem pelo centro volante de destino (o Gestor desse destino aloca a pessoa).
        $stmtCentroVol = $pdo->prepare('SELECT centro_custo, IF(' . SQL_COLABORADOR_INCLUIDO . ', centro_destino, NULL) AS destino
                                          FROM colaboradores WHERE matricula = ? LIMIT 1');
        $stmtCentroVol->execute([$matriculaVol]);
        $vol = $stmtCentroVol->fetch();
        $poolVol = $coberturaGestor ?? codigosCentrosVolante($pdo);
        $volanteDoProprioPool = $vol && (in_array($vol['centro_custo'], $poolVol, true) || in_array($vol['destino'], $poolVol, true));
    }

    if (!$volanteDoProprioPool) {
        foreach ($linhas as $linha) {
            if (!in_array($linha['centro_custo'], $centrosPermitidos, true)) {
                erro('Uma ou mais solicitacoes selecionadas estao fora do seu centro de custo.', 403);
            }
        }
    }
}

// Desligado nao cobre depois do desligamento (desligamentoDoColaborador, db.php); antes dele continua valendo (correcao do PFM). Depois
// da checagem de escopo: senao um Gestor de fora descobria a data de desligamento de qualquer matricula.
$desligamentoVol = $matriculaVol !== 'SEM_COBERTURA' ? desligamentoDoColaborador($pdo, $matriculaVol) : null;
foreach ($linhas as $linha) {
    $dataLinha = DateTime::createFromFormat('!d/m/Y', (string) $linha['data']);
    if ($desligamentoVol && $dataLinha && $dataLinha > $desligamentoVol) {
        erro('Este colaborador tem desligamento em ' . $desligamentoVol->format('d/m/Y') . ' e nao pode cobrir depois dessa data.', 409);
    }
}

// Linha fechada so pode ser CORRIGIDA ate 15 dias depois do fim do periodo (ou do fechamento, se depois) e nunca depois do dia 3
// do fechamento do PFM daquele dia - ver dentroDoPrazoDeCorrecao em db.php; dados.php manda o mesmo calculo (pode_corrigir) pra
// tela. Linha aberta: designar volante so em dia de periodo do PFM ainda aberto (decisao do usuario, 2026-11-12); fechar como SEM
// COBERTURA continua liberado (nao paga ninguem).
$fimPeriodo = fimDosPeriodos($pdo, array_column($linhas, 'matricula'));
$hoje = $pdo->query('SELECT CURDATE()')->fetchColumn();
$inicioPfm = inicioPeriodoPfmAberto((string) $hoje);
foreach ($linhas as $linha) {
    $diaLinha = dataBrParaIso((string) $linha['data']);
    $jaTemCobertura = trim((string) $linha['matricula_vol']) !== '';
    if (!$jaTemCobertura) {
        if ($matriculaVol !== 'SEM_COBERTURA' && $diaLinha !== null && $diaLinha < $inicioPfm) {
            erro("O dia {$linha['data']} é de um período do PFM já fechado (ajustes até o dia 3): não dá mais para designar volante nele.", 409);
        }
        continue;
    }

    if (!dentroDoPrazoDeCorrecao($fimPeriodo[(int) $linha['id']] ?? null, $linha['fechado_em'], $hoje, $diaLinha)) {
        erro('Esta solicitação passou do prazo de correção (15 dias após o último dia do período, e nunca depois do dia 3 do fechamento do PFM) e não pode mais ser corrigida.', 409);
    }
}

// 'SEM_COBERTURA' = volante "fantasma": fecha a solicitacao sem designar ninguem (RH nao achou
// volante). Nao existe em colaboradores, entao pula a validacao normal e o conflito de agenda.
$ausenciasSaf = []; // id da linha => [matricula, nome] do volante das ferias do SAF que nao cobriu o dia (ver abaixo)
if ($matriculaVol === 'SEM_COBERTURA') {
    $nomeVol = $folgaTitular ? NOME_FOLGA_TITULAR : 'SEM COBERTURA';
} else {
    $stmtVol = $pdo->prepare('SELECT nome FROM colaboradores WHERE matricula = ? LIMIT 1');
    $stmtVol->execute([$matriculaVol]);
    $nomeVol = $stmtVol->fetchColumn();
    if ($nomeVol === false) erro('Colaborador nao encontrado.', 404);

    // Ninguem cobre duas coisas no mesmo dia: confere se o volante ja tem linha (de outra solicitacao) nesses dias.
    $datas = array_values(array_unique(array_column($linhas, 'data')));
    $marcadoresDatas = implode(',', array_fill(0, count($datas), '?'));
    $stmtConflito = $pdo->prepare(
        "SELECT matricula, nome, data FROM solicitacoes_cobertura
          WHERE matricula_vol = ? AND data IN ($marcadoresDatas) AND id NOT IN ($marcadoresIds)
          LIMIT 1"
    );
    $stmtConflito->execute(array_merge([$matriculaVol], $datas, $ids));
    $conflito = $stmtConflito->fetch();
    if ($conflito) {
        erro(
            "Esse colaborador ja esta cobrindo {$conflito['nome']} em {$conflito['data']} - " .
            'nao pode fazer mais de uma cobertura no mesmo dia.',
            409
        );
    }
    // Nem cobre no dia em que ele mesmo esta ausente como titular (falta, atestado, afastamento, ferias...: N2 da analise de
    // 2026-11-12). Servico extra e reforco do posto, o titular dele esta trabalhando; desligamento e a vaga do POSTO - quem cumpre aviso
    // como volante segue trabalhando ate a saida, barrada acima por desligamentoDoColaborador (revisao de 2026-11-13).
    $stmtAusente = $pdo->prepare(
        "SELECT motivo, data FROM solicitacoes_cobertura
          WHERE matricula = ? AND data IN ($marcadoresDatas) AND motivo NOT IN ('servico_extra', 'desligamento')
          LIMIT 1"
    );
    $stmtAusente->execute(array_merge([$matriculaVol], $datas));
    if ($ausente = $stmtAusente->fetch()) {
        erro("Esse colaborador está com solicitação de " . rotuloMotivoSolicitacao((string) $ausente['motivo'])
            . " em {$ausente['data']} (ausente como titular) e não pode cobrir nesse dia.", 409);
    }
    // Vaga de afastamento dele ainda aberta (sem Fim) ate o maior dia pedido: o cron so cria as linhas dela ate hoje, entao o dia
    // futuro passava acima e o PFM depois tirava o dia dele, sem aviso a ninguem (revisao de 2026-11-13).
    $maiorDiaPedido = max(array_map(fn($d) => ($x = DateTime::createFromFormat('!d/m/Y', (string) $d)) ? $x->format('Y-m-d') : '', $datas));
    $stmtVagaAberta = $pdo->prepare("SELECT a.data FROM solicitacoes_afastamento v JOIN solicitacoes_cobertura a ON a.id = v.solicitacao_cobertura_id
                                      WHERE v.matricula_titular = ? AND v.fim_definido_em IS NULL AND STR_TO_DATE(a.data, '%d/%m/%Y') <= ? LIMIT 1");
    $stmtVagaAberta->execute([$matriculaVol, $maiorDiaPedido]);
    if ($vagaAbertaDesde = $stmtVagaAberta->fetchColumn()) {
        erro("Esse colaborador está com vaga de afastamento aberta desde $vagaAbertaDesde (sem Fim definido) e não pode cobrir: "
            . 'defina o Fim da vaga antes.', 409);
    }
    // Redesignar o MESMO volante que faltou desfaz a ausencia, mesmo sendo dia de ferias que ele cobre.
    $desfazAusencia = count(array_filter($linhas, fn($l) => $l['ausencia_matricula_vol'] !== $matriculaVol)) === 0;
    $conflitoFerias = $desfazAusencia ? null : conflitoFeriasSaf($pdo, $matriculaVol, $datas);
    if ($conflitoFerias !== null) erro($conflitoFerias, 409);

    // Dia em que as ferias do SAF ja pagam o posto desse titular a outro volante (diasCoberturaFeriasSaf: a mesma conta do PFM).
    // Designar outra pessoa aqui quer dizer que o volante das ferias nao cobriu esse dia: com a confirmacao do usuario
    // (confirmar_ausencia_saf), a linha registra a falta dele (ausencia_*, como ausencia-cobertura.php), o SAF deixa de pagar o
    // dia e o PFM paga quem foi designado. Sem a confirmacao: 409 com a pergunta (a tela pergunta e manda de novo). Redesignar o
    // proprio volante das ferias desfaz a falta (abaixo). SEM_COBERTURA nao passa por aqui.
    $isoDatas = array_map(fn($d) => implode('-', array_reverse(explode('/', $d))), $datas);
    $pagoPeloSaf = [];
    $emprestadoPara = []; // dia do posto de origem de um emprestado: de quem sao as ferias que ele cobre (aviso de ausencia abaixo)
    foreach (diasCoberturaFeriasSaf($pdo, min($isoDatas), max($isoDatas), $avisoSafIgnorado) as $d) {
        $pagoPeloSaf[$d['titular_mat'] . '|' . $d['data']] = $d['vol_mat'];
        if ($d['emprestado_para'] !== null) $emprestadoPara[$d['titular_mat'] . '|' . $d['data']] = $d['emprestado_para'];
    }
    foreach ($linhas as $linha) {
        $volSaf = $pagoPeloSaf[$linha['matricula'] . '|' . $linha['data']] ?? null;
        if ($volSaf === null || $volSaf === $matriculaVol) continue;
        // A linha guarda uma ausencia so: a de outra pessoa ja registrada nao pode ser apagada pela do volante das ferias.
        if (trim((string) $linha['ausencia_matricula_vol']) !== '') {
            $quem = trim((string) $linha['ausencia_nome_vol']) ?: $linha['ausencia_matricula_vol'];
            erro("Em {$linha['data']} essa linha já tem a ausência de $quem registrada (cada linha guarda uma ausência só): corrija essa linha antes.", 409);
        }
        $stmtVol->execute([$volSaf]);
        $ausenciasSaf[(int) $linha['id']] = [$volSaf, (string) ($stmtVol->fetchColumn() ?: $volSaf), $linha['data'],
            $emprestadoPara[$linha['matricula'] . '|' . $linha['data']] ?? null];
    }
    // A falta so se registra de dia que ja chegou (a de amanha ninguem sabe; o corte deixa o posto com o volante das ferias) e
    // de periodo do PFM que ainda aceita ajuste (inicioPeriodoPfmAberto, db.php).
    $inicioPfm = inicioPeriodoPfmAberto((string) $hoje);
    foreach ($ausenciasSaf as [, $nomeAusente, $dataBr]) {
        $dia = implode('-', array_reverse(explode('/', $dataBr)));
        if ($dia > $hoje) erro("Em $dataBr o posto desse titular está com o volante das férias do SAF ($nomeAusente): a falta dele só pode ser registrada no dia ou depois.", 409);
        if ($dia < $inicioPfm) erro("Em $dataBr o posto desse titular foi coberto pelas férias do SAF ($nomeAusente) e esse período do PFM já fechou (ajustes até o dia 3): não dá mais para registrar a falta dele.", 409);
    }
    if ($ausenciasSaf && empty($corpo['confirmar_ausencia_saf'])) {
        $nomes = implode(', ', array_unique(array_column($ausenciasSaf, 1)));
        $dias = implode(', ', array_unique(array_column($ausenciasSaf, 2)));
        http_response_code(409);
        echo json_encode([
            'error' => "Em $dias o posto desse titular está coberto pelas férias do SAF ($nomes).",
            'confirmar_ausencia_saf' => "Em $dias o posto desse titular está coberto pelas férias do SAF ($nomes).\n\n"
                . "Designar $nomeVol registra que $nomes não cobriu esse(s) dia(s): ele não recebe por eles e o PFM paga $nomeVol.\n\nConfirmar?",
        ]);
        exit;
    }
}

// fechado_em = quando a linha fechou pela PRIMEIRA vez (designacao ou fechamento automatico,
// fechar-solicitacoes-vencidas.php); conta pro prazo de correcao. COALESCE: uma correcao nao
// "fecha de novo", senao empurraria o prazo (ele conta do fim do periodo).
// vol_salario: retrato congelado do salario de quem cobriu (ver schema.sql), pro PFM de mes
// antigo nao usar o salario atual.
$volSalario = snapshotVolSalario($pdo, $matriculaVol);
$stmtUpdate = $pdo->prepare("UPDATE solicitacoes_cobertura SET matricula_vol = ?, nome_vol = ?, vol_salario = ?, fechado_em = COALESCE(fechado_em, NOW()) WHERE id IN ($marcadoresIds)");
$stmtUpdate->execute(array_merge([$matriculaVol, $nomeVol, $volSalario], $ids));
// Falta do volante das ferias do SAF confirmada acima: fica registrada na linha (o SAF nao paga o dia, ver diasCoberturaFeriasSaf).
$stmtAusenciaSaf = $pdo->prepare('UPDATE solicitacoes_cobertura SET ausencia_matricula_vol = ?, ausencia_nome_vol = ?, ausencia_em = NOW() WHERE id = ?');
foreach ($ausenciasSaf as $idLinha => [$matAusente, $nomeAusente]) $stmtAusenciaSaf->execute([$matAusente, $nomeAusente, $idLinha]);
// Mesmo aviso da ausencia marcada pelo Preposto (ausencia-cobertura.php), para os mesmos destinatarios.
$linhaPorId = array_column($linhas, null, 'id');
foreach ($ausenciasSaf as $idLinha => [$matAusente, $nomeAusente, $dataBr, $paraFerias]) {
    $l = $linhaPorId[$idLinha];
    // Posto de origem de um emprestado: quem faltou e o substituto, e o titular da linha nao esta de ferias (cobre as de $paraFerias).
    $deQuem = $paraFerias === null ? "as férias de {$l['nome']}" : "o posto de {$l['nome']} (emprestado para as férias de $paraFerias)";
    criarNotificacoes($pdo, destinatariosCobertura($pdo, (string) $l['centro_custo'], $matAusente), 'ausencia',
        "AUSÊNCIA: $nomeAusente não cobriu $deQuem (" . rotuloCentroCusto($pdo, (string) $l['centro_custo'])
        . ") em $dataBr. Registrado ao designar $nomeVol, que recebe o dia no lugar dele.", (int) $idLinha, $usuarioId);
}

// Ausencia de cobertura (ausencia-cobertura.php): redesignar o MESMO volante que faltou desfaz o
// registro e limpa os avisos nao lidos; designar OUTRO mantem o registro de quem faltou.
$stmtDesfazer = $pdo->prepare(
    "SELECT id FROM solicitacoes_cobertura WHERE id IN ($marcadoresIds) AND ausencia_matricula_vol = ?"
);
$stmtDesfazer->execute(array_merge($ids, [$matriculaVol]));
$idsDesfeitos = $stmtDesfazer->fetchAll(PDO::FETCH_COLUMN);
if (count($idsDesfeitos) > 0) {
    $marcadoresDesfeitos = implode(',', array_fill(0, count($idsDesfeitos), '?'));
    $pdo->prepare(
        "UPDATE solicitacoes_cobertura SET ausencia_matricula_vol = NULL, ausencia_nome_vol = NULL, ausencia_em = NULL
          WHERE id IN ($marcadoresDesfeitos)"
    )->execute($idsDesfeitos);
    $pdo->prepare(
        "DELETE FROM notificacoes WHERE tipo = 'ausencia' AND lida_em IS NULL AND referencia_id IN ($marcadoresDesfeitos)"
    )->execute($idsDesfeitos);
}

// Quem cobria antes (correcao): "antes: volante X (2 dia(s)), em aberto (1 dia(s))" - responde uma contestacao do PFM (item 6).
$rotuloCobertura = fn(string $vol, string $nome) => $vol === '' ? 'em aberto'
    : ($vol === 'SEM_COBERTURA' ? ($nome === NOME_FOLGA_TITULAR ? NOME_FOLGA_TITULAR : 'SEM COBERTURA') : "volante $vol");
$rotuloNovo = $rotuloCobertura($matriculaVol, (string) $nomeVol);
$antes = [];
foreach ($linhas as $linha) {
    $rotuloAntes = $rotuloCobertura(trim((string) $linha['matricula_vol']), trim((string) $linha['nome_vol']));
    if ($rotuloAntes === $rotuloNovo) continue;
    $antes[$rotuloAntes] = ($antes[$rotuloAntes] ?? 0) + 1;
}
$textoAntes = implode(', ', array_map(fn($r, $n) => "$r ($n dia(s))", array_keys($antes), $antes));

registrarAuditoria($pdo, $usuarioId, 'designar_cobertura_solicitacao', [
    'tabela' => 'solicitacoes_cobertura',
    'quantidade' => count($ids),
    'detalhe' => "ids " . implode(',', $ids) . ', volante ' . ($folgaTitular ? NOME_FOLGA_TITULAR : $matriculaVol)
        . ($textoAntes !== '' ? " (antes: $textoAntes)" : '')
        . (count($idsDesfeitos) > 0 ? ' (ausencia desfeita nos ids ' . implode(',', $idsDesfeitos) . ')' : '')
        . ($ausenciasSaf ? ' (falta do volante das ferias do SAF registrada nos ids ' . implode(',', array_keys($ausenciasSaf)) . ')' : ''),
]);

// ausencias_saf: id => {matricula, nome} de quem faltou (a tela mostra "(ausência: ...)" sem recarregar)
echo json_encode(['success' => true, 'ausencias_saf' => array_map(fn($a) => ['matricula' => $a[0], 'nome' => $a[1]], $ausenciasSaf)]);
