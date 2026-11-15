<?php
// ============================================================
// FERIAS DO SAF PRA TELA PREVISAO (coberturas/previsao.html)
// ============================================================
// Mesmo JSON do PFM (lerCoberturaSaf, db.php): todas as aprovadas, com precisa_cobertura. Gestor e
// Preposto so veem ferias dos proprios centros, como em dados.php.
//
// cobertura: 'volante', 'vaga' ("Vaga em aberto" ou vazio no SAF), 'interna' ("Cobertura interna") ou 'transferencia' (quem cobre
// ainda vai ser transferido a volante; volante_matricula e a do SAF e transferencia_link o link, ver PREFIXO_TRANSFERENCIA em db.php)
// ou 'emprestimo' (outro contrato emprestou quem cobre; volante_matricula e a do SAF e substituto_matricula/substituto_nome quem vai
// para o posto de origem dele, ver PREFIXO_EMPRESTIMO em db.php).
// volante_matricula vazia com cobertura='volante' = nome do SAF sem cadastro no CGV.
//
// Alterar cobertura (RH/Admin e Gestor, antes de a ferias comecar; depois do inicio incluir quem cobre uma vaga em aberto ou uma
// "nao precisa de cobertura", ou trocar quem cobre, sempre com "a_partir_de" - mudancaDepoisDoInicio): o CGV nao grava no SAF. Grava
// um pedido em <pasta do arquivo do SAF>/pedidos e o SAF aplica em ate 1 minuto, com as mesmas regras do agendamento
// (aplicar-pedidos-cgv.php no SAF). O resultado volta em pedidos/resultados e vira aviso no sino de quem pediu.
//   ?acao=volantes&id=N     -> volantes disponiveis pra essa ferias (contrato, centros autorizados e datas livres); ferias em
//                              andamento: &a_partir_de=AAAA-MM-DD (sem ele, hoje)
//   POST ?acao=alterar      -> {id, precisa_cobertura, cobertura: volante|interna|vaga|transferencia|emprestimo, volante_matricula, motivo,
//                               volante_nome (transferencia e emprestimo), link (transferencia), posto_substituido,
//                               substituto_matricula e substituto_nome (emprestimo), a_partir_de (ferias em andamento)}
//   POST ?acao=nota         -> {matriculas: [...], texto} nota da aba Volantes Ociosos (texto vazio apaga)
//   POST ?acao=nota_ferias  -> {ids: [...], texto} nota do Preposto nas ferias da aba Precisa de Cobertura (texto vazio apaga)
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../scripts/protheus-colaboradores-helpers.php';
require_once __DIR__ . '/../scripts/ociosidade-helpers.php'; // diasOcupadosVolantes, centrosVolantesOciosos, diasOciososVolantes

$pdo = conectarBanco();
$usuarioId = verificarSessao($pdo);
$papel = exigirPapel($pdo, $usuarioId, ['rh', 'administrador', 'gestor', 'preposto']);
header('Content-Type: application/json');

// Link da transferencia (PREFIXO_TRANSFERENCIA, db.php) vindo do usuario ou do SAF: so http/https com host, ate 240, sem
// espaco, aspas, < >, crase, barra invertida ou caractere de controle. '' = invalido. A tela mostra o link so como texto
// (campo e title), nunca como HTML. Mesma regra do SAF (aplicar-pedidos-cgv.php).
function linkTransferenciaValido(string $link): string {
    $link = trim($link);
    if ($link === '' || strlen($link) > 240 || preg_match('/[\s"\'<>`\\\\\x00-\x1f\x7f]/', $link)) return '';
    $partes = filter_var($link, FILTER_VALIDATE_URL) === false ? false : parse_url($link);
    if (!$partes || !in_array(strtolower($partes['scheme'] ?? ''), ['http', 'https'], true) || empty($partes['host'])) return '';
    return $link;
}

// exit nao passa pelo finally: solta aqui a trava do pedido, se houver (soltar trava que nao e sua nao faz nada).
function erroPrevisao(string $msg, int $status = 400): void {
    global $pdo, $nomeTrava;
    if (isset($nomeTrava)) $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$nomeTrava]);
    http_response_code($status);
    echo json_encode(['error' => $msg]);
    exit;
}

// Todas as aprovadas: as que precisam de cobertura vao pra aba Com Cobertura, as outras pra Sem Cobertura (tela).
$todas = feriasSafComVolante($pdo, $avisoSaf, $arquivoSaf);
// Posto de origem do emprestado e trecho de quem cobriu antes de uma troca so ocupam (e pagam): nao sao ferias.
$ferias = array_values(array_filter($todas, fn($f) => empty($f['posto_origem']) && empty($f['trecho_anterior'])));

// Uma leitura so de centros_custo: volantes (codigosCentrosVolante) e intermitentes (aba Volantes Ociosos).
$centrosCusto = $pdo->query('SELECT codigo, descricao FROM centros_custo')->fetchAll();
$codigosVolante = array_column(array_filter($centrosCusto, fn($c) => centroCustoEhVolante((string) $c['codigo'], (string) ($c['descricao'] ?? ''))), 'codigo');
$centros = in_array($papel, ['gestor', 'preposto'], true) ? centrosCustoLiberados($pdo, $usuarioId, $papel) : null;
if ($centros !== null) {
    // Gestor tambem ve as ferias dos volantes do proprio grupo (pool "Todos" = qualquer volante), como em ferias-volantes.php.
    $poolFerias = $papel === 'gestor' ? (coberturaGestorLiberada($pdo, $usuarioId) ?? $codigosVolante) : [];
    $ferias = array_filter($ferias, fn($f) => in_array(trim((string) ($f['centro_custo'] ?? '')), array_merge($centros, $poolFerias), true));
}
// Ferias de volante: Gestor so as do proprio pool, mesmo com "Todos os contratos" (igual a aba Volantes Ociosos).
$poolGestorFerias = $papel === 'gestor' ? coberturaGestorLiberada($pdo, $usuarioId) : null;
if ($poolGestorFerias !== null) {
    $ferias = array_filter($ferias, fn($f) => !in_array($cc = trim((string) ($f['centro_custo'] ?? '')), $codigosVolante, true) || in_array($cc, $poolGestorFerias, true));
}

// Sem "contratos_cobertura" no arquivo = SAF anterior, que ainda nao aplica pedidos: so visualizacao (idem banco sem a migracao).
$podeAlterar = in_array($papel, ['rh', 'administrador', 'gestor'], true) && isset($arquivoSaf['contratos_cobertura'])
    && tabelaExiste($pdo, 'pedidos_cobertura_saf');
$hoje = date('Y-m-d');
// SAF que ja aplica pedido de transferencia avisa no arquivo; o mais antigo recusaria o pedido. A mesma versao do SAF trouxe a
// cobertura incluida depois do inicio (a_partir_de): sem o aviso, ferias comecada fica so para visualizar (CGV publicado antes).
$aceitaTransferencia = $podeAlterar && !empty($arquivoSaf['aceita_transferencia']);
// Idem o colaborador emprestado (SAF que ja aplica o pedido com o substituto do posto de origem).
$aceitaEmprestimo = $podeAlterar && !empty($arquivoSaf['aceita_emprestimo']);
// Idem trocar quem cobre uma ferias ja comecada (SAF que guarda o trecho de quem sai, coberturas_anteriores).
$aceitaTroca = $podeAlterar && !empty($arquivoSaf['aceita_troca']);

// Ferias ja comecada (e ainda nao acabada): o que da para mudar, sempre a partir de um dia (faixaAPartirDe). Mesma regra em
// aplicar-pedidos-cgv.php (SAF). 'incluir' = vaga em aberto ou "nao precisa de cobertura" ganham quem cobre (volante, transferencia
// ou emprestado; decisao do usuario, 2026-11-05/06), e a vaga em aberto tambem pode virar "nao precisa" (decisao do usuario,
// 2026-11-09); 'trocar' = quem cobre troca por outra pessoa, por Vaga em aberto ou por "nao precisa" e fica com os dias ate a
// vespera (decisao do usuario, 2026-11-09); null = so visualizar (Cobertura interna, ou ainda nao comecou). Ferias do
// proprio volante (centro de volante, que nao pede cobertura) fica de fora. "Nao precisa" so com o SAF que ja aceita isso ($safNovo:
// o mesmo que manda aceita_emprestimo) e a troca so com o que manda aceita_troca: CGV publicado antes do SAF nao oferece um pedido
// que o SAF recusaria.
function mudancaDepoisDoInicio(array $f, string $hoje, array $codigosVolante, bool $safNovo, bool $aceitaTroca): ?string {
    if ($f['ferias_inicio'] > $hoje || $f['ferias_fim'] < $hoje) return null;
    if (($f['precisa_cobertura'] ?? 'Sim') !== 'Sim') return $safNovo && !in_array(trim((string) ($f['centro_custo'] ?? '')), $codigosVolante, true) ? 'incluir' : null;
    $volante = trim((string) ($f['volante'] ?? ''));
    if (in_array($volante, ['', 'Vaga em aberto'], true)) return 'incluir';
    return $aceitaTroca && $volante !== 'Cobertura interna' ? 'trocar' : null;
}
// Faixa do "A partir de": do periodo do PFM ainda aberto ate o fim das ferias (troca programada). O PFM do mes M vai de 20/(M-1) a
// 19/M e aceita ajuste ate o dia 3 de M+1: o periodo mais antigo ainda aberto comeca no dia 20 do mes anterior (ate o dia 3, no dia
// 20 de 2 meses atras). Ex.: de 04/12 a 03/01 o minimo e 20/11; em 03/12, 20/10. Troca: nao antes de quem cobre agora comecar
// (cobertura_inicio); vaga que veio de uma troca: nao antes do fim do ultimo trecho de quem cobria (esses dias sao dele). Troca
// programada (quem cobre ainda nao comecou) pode ser refeita para antes (decisao do usuario, 2026-11-09): ate o inicio do trecho
// anterior colado nela, que o SAF encurta (ou desfaz), ou o dia seguinte ao ultimo trecho. SAF anterior (sem aceita_troca,
// $ateOFim = false) so aceita ate hoje.
function faixaAPartirDe(array $f, string $hoje, bool $troca, bool $ateOFim): array {
    $ultimo = ['desde' => '', 'ate' => ''];
    foreach (is_array($f['coberturas_anteriores'] ?? null) ? $f['coberturas_anteriores'] : [] as $t) {
        if (is_array($t) && (string) ($t['ate'] ?? '') > $ultimo['ate']) $ultimo = ['desde' => (string) ($t['desde'] ?? ''), 'ate' => (string) $t['ate']];
    }
    $depois = $ultimo['ate'] !== '' ? date('Y-m-d', strtotime("{$ultimo['ate']} +1 day")) : '';
    $comeco = !$troca ? $depois : ($f['cobertura_inicio'] <= $hoje ? $f['cobertura_inicio'] : ($depois === $f['cobertura_inicio'] ? $ultimo['desde'] : $depois));
    return [max($f['ferias_inicio'], inicioPeriodoPfmAberto($hoje), $comeco), $ateOFim ? $f['ferias_fim'] : $hoje]; // db.php
}

// Volantes que podem cobrir essa ferias: dos centros autorizados pro contrato (Ajuste Tabelas, vindo do SAF), ativos
// do inicio ao fim (o dia do desligamento ainda conta, como no PFM) e sem nenhum dia ocupado nas datas (diasOcupadosVolantes;
// o SAF reconfere ao aplicar). Gestor: so os do proprio grupo de volantes (coberturaGestorLiberada, null = todos).
// null = contrato nao aceita cobertura.
// $inicio: primeiro dia da cobertura (o inicio da ferias, ou o "a partir de" de quem entra depois do inicio).
// $cobrindoOutra (troca cruzada, decisao do usuario 2026-11-09: 3 passos, com aviso): os volantes que so ficaram de fora por cobrir
// outras ferias do SAF nesses dias, com de quem e ate quando (o painel mostra sem deixar escolher, com o caminho: trocar la primeiro).
function volantesDisponiveisPrevisao(PDO $pdo, array $f, array $todas, array $contratos, ?array $poolGestor, string $inicio, ?array &$cobrindoOutra = null): ?array {
    $cobrindoOutra = [];
    $centrosVolante = $contratos[trim((string) ($f['centro_custo'] ?? ''))] ?? null;
    if ($centrosVolante === null) return null;
    if ($poolGestor !== null) $centrosVolante = array_values(array_intersect($centrosVolante, $poolGestor));
    if (!$centrosVolante) return [];
    $ocupado = diasOcupadosVolantes($pdo, $todas, $inicio, false, (int) $f['id'], (string) $f['matricula']);
    $cobrindo = []; // matricula => [id das ferias => [de quem, ate]] das outras ferias do SAF que ele cobre nesses dias
    foreach ($todas as $o) {
        $vol = (string) ($o['matricula_vol'] ?? '');
        if ($vol === '' || (int) ($o['id'] ?? 0) === (int) $f['id'] || $o['cobertura_inicio'] > $f['ferias_fim'] || $o['ferias_fim'] < $inicio) continue;
        $id = (int) ($o['id'] ?? 0);
        $cobrindo[$vol][$id] = ['id' => $id, 'ferias_de' => (string) ($o['ferias_titular'] ?? $o['nome'] ?? ''), 'ate' => max($cobrindo[$vol][$id]['ate'] ?? '', $o['ferias_fim'])];
    }
    $semCoberturas = null; // dias ocupados sem as coberturas de ferias do SAF: so calcula se alguem estiver cobrindo outra
    $stmt = $pdo->prepare('SELECT matricula, nome, centro_custo, funcao, data_admissao, desligamento FROM colaboradores WHERE centro_custo IN ('
        . implode(',', array_fill(0, count($centrosVolante), '?')) . ') ORDER BY nome');
    $stmt->execute(array_values($centrosVolante));
    $iso = fn($d) => ($x = DateTime::createFromFormat('!d/m/Y', (string) $d)) ? $x->format('Y-m-d') : '';
    $choca = function (array $dias) use ($f, $inicio): bool {
        foreach ($dias as [$ini, $fim]) if ($ini <= $f['ferias_fim'] && $fim >= $inicio) return true;
        return false;
    };
    $lista = [];
    foreach ($stmt->fetchAll() as $c) {
        $matricula = (string) $c['matricula'];
        $admissao = $iso($c['data_admissao']);
        $saida = $iso($c['desligamento']);
        if (isset($lista[$matricula]) || $matricula === (string) $f['matricula']
            || ($admissao && $admissao > $inicio) || ($saida && $saida < $f['ferias_fim'])) continue;
        $pessoa = ['matricula' => $matricula, 'nome' => $c['nome'], 'centro_custo' => $c['centro_custo'], 'funcao' => $c['funcao']];
        if (!$choca($ocupado[$matricula] ?? [])) {
            $lista[$matricula] = $pessoa;
        } elseif (isset($cobrindo[$matricula])) {
            $semCoberturas ??= diasOcupadosVolantes($pdo, array_map(fn($o) => ['matricula_vol' => null, 'volante_matricula' => '', 'posto_origem' => false] + $o, $todas),
                $inicio, false, (int) $f['id'], (string) $f['matricula']);
            if (!$choca($semCoberturas[$matricula] ?? [])) $cobrindoOutra[$matricula] = $pessoa + ['cobrindo' => array_values($cobrindo[$matricula])];
        }
    }
    $cobrindoOutra = array_values($cobrindoOutra);
    return array_values($lista);
}

// Colaborador emprestado e quem vai para o posto de origem: ativo no cadastro do CGV durante toda a cobertura (quem nao esta no
// cadastro, o SAF confere) e sem ferias nem outra cobertura nas datas (diasOcupadosVolantes). Recusa com erroPrevisao.
function conferirDisponivelEmprestimoPrevisao(PDO $pdo, string $matricula, string $nome, array $f, array $todas, string $aPartirDe, string $hoje, bool $emprestado = false): void {
    $stmt = $pdo->prepare('SELECT centro_custo, data_admissao, desligamento FROM colaboradores WHERE matricula = ?');
    $stmt->execute([$matricula]);
    $iso = fn($d) => ($x = DateTime::createFromFormat('!d/m/Y', (string) $d)) ? $x->format('Y-m-d') : '';
    foreach ($stmt->fetchAll() as $c) {
        $saida = $iso($c['desligamento']);
        if ((!$saida || $saida >= $hoje) && ($iso($c['data_admissao']) > $aPartirDe || ($saida && $saida < $f['ferias_fim']))) {
            erroPrevisao("$nome não está ativo(a) durante toda a cobertura (admissão depois do início dela ou desligamento antes do fim das férias).", 409);
        }
        // Emprestado vem de OUTRO contrato (decisao do usuario, 2026-11-09): colega do mesmo contrato do titular e Cobertura interna. Pessoa
        // so do Protheus (fora do cadastro do CGV): o SAF confere (aplicar-pedidos-cgv.php).
        if ($emprestado && (!$saida || $saida >= $hoje) && trim((string) $c['centro_custo']) === trim((string) ($f['centro_custo'] ?? ''))) {
            erroPrevisao("$nome é do mesmo contrato do titular: use Cobertura interna.", 409);
        }
    }
    foreach (diasOcupadosVolantes($pdo, $todas, $aPartirDe, false, (int) $f['id'], (string) $f['matricula'])[$matricula] ?? [] as [$ini, $fim]) {
        if ($ini <= $f['ferias_fim'] && $fim >= $aPartirDe) erroPrevisao("$nome já tem férias ou outra cobertura nessas datas.", 409);
    }
}

// Resultado dos pedidos que o SAF ja aplicou vira aviso no sino de quem pediu (e libera a ferias pra outro pedido).
conferirPedidosCoberturaSaf($pdo);

$acao = $_GET['acao'] ?? '';

// Nota da aba Volantes Ociosos (pedido do usuario, 2026-11-09): quem ve a aba grava, so nos volantes que ela mostra para ele. 1 nota por
// volante POR MES (o mes aberto na Previsao, para o Historico de observacao): a nova substitui a do mesmo mes; texto vazio apaga.
if ($acao === 'nota') {
    if ($papel === 'preposto') erroPrevisao('Sem permissão.', 403);
    if (!tabelaExiste($pdo, 'volante_notas_mes')) erroPrevisao('Tabela de notas ainda não existe - rode ferramentas/migrar-banco.php.', 500);
    $corpo = json_decode(file_get_contents('php://input'), true) ?: [];
    $texto = trim((string) ($corpo['texto'] ?? ''));
    $mes = is_scalar($corpo['mes'] ?? null) ? (string) $corpo['mes'] : '';
    $matriculas = array_values(array_unique(array_filter(array_map(fn($m) => trim((string) $m), (array) ($corpo['matriculas'] ?? [])), 'strlen')));
    if (!$matriculas || count($matriculas) > 500 || mb_strlen($texto) > 500 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) erroPrevisao('Nota inválida.');
    $centrosNota = centrosVolantesOciosos($pdo, $papel, (int) $usuarioId, $centrosCusto, $codigosVolante) ?: [''];
    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT matricula) FROM colaboradores WHERE matricula IN (' . implode(',', array_fill(0, count($matriculas), '?'))
        . ') AND centro_custo IN (' . implode(',', array_fill(0, count($centrosNota), '?')) . ')');
    $stmt->execute(array_merge($matriculas, $centrosNota));
    if ((int) $stmt->fetchColumn() !== count($matriculas)) erroPrevisao('Algum desses volantes não está na sua aba Volantes Ociosos. Atualize a página.', 403);
    $em = $pdo->query('SELECT NOW()')->fetchColumn();
    $grava = $texto === '' ? $pdo->prepare('DELETE FROM volante_notas_mes WHERE matricula = ? AND mes = ?')
        : $pdo->prepare('INSERT INTO volante_notas_mes (matricula, mes, texto, atualizado_em, usuario_id) VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE texto = VALUES(texto), atualizado_em = VALUES(atualizado_em), usuario_id = VALUES(usuario_id)');
    $pdo->beginTransaction();
    foreach ($matriculas as $m) $grava->execute($texto === '' ? [$m, $mes] : [$m, $mes, $texto, $em, $usuarioId]);
    $pdo->commit();
    // Matriculas antes do texto: registrarAuditoria corta o detalhe em 500 caracteres, e o que nao pode sumir do Logs e para quem foi.
    $mesBr = substr($mes, 5, 2) . '/' . substr($mes, 0, 4);
    registrarAuditoria($pdo, $usuarioId, 'nota_volante_ocioso', ['quantidade' => count($matriculas),
        'detalhe' => 'Matricula ' . implode(', ', $matriculas) . " - $mesBr - " . ($texto === '' ? 'Nota apagada' : "Nota: $texto")]);
    $stmt = $pdo->prepare('SELECT usuario FROM usuarios WHERE id = ?');
    $stmt->execute([$usuarioId]);
    echo json_encode(['success' => true, 'em' => $em, 'por' => (string) $stmt->fetchColumn()]);
    exit;
}
// Nota do Preposto na aba Ferias - Precisa de Cobertura (pedido do usuario, 2026-11-13): mesma logica da nota dos Volantes Ociosos
// (botao direito, multi selecao), 1 por ferias do SAF, so nas que a aba mostra para ele; texto vazio apaga. RH/Admin/Gestor veem
// (icone, mouse e painel da ferias) e ela vai na mensagem do WhatsApp para o colaborador ("Nota:" embaixo do Contrato).
if ($acao === 'nota_ferias') {
    if ($papel !== 'preposto') erroPrevisao('Sem permissão.', 403);
    if (!tabelaExiste($pdo, 'ferias_saf_notas')) erroPrevisao('Tabela de notas ainda não existe - rode ferramentas/migrar-banco.php.', 500);
    $corpo = json_decode(file_get_contents('php://input'), true) ?: [];
    $texto = trim((string) ($corpo['texto'] ?? ''));
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($corpo['ids'] ?? [])))));
    if (!$ids || count($ids) > 500 || mb_strlen($texto) > 500) erroPrevisao('Nota inválida.');
    $daAba = [];
    foreach ($ferias as $f) if (($f['precisa_cobertura'] ?? 'Sim') === 'Sim' && !empty($f['id'])) $daAba[(int) $f['id']] = $f;
    $escolhidas = array_intersect_key($daAba, array_flip($ids));
    if (count($escolhidas) !== count($ids)) erroPrevisao('Alguma dessas férias não está na sua aba Férias - Precisa de Cobertura. Atualize a página.', 403);
    $em = $pdo->query('SELECT NOW()')->fetchColumn();
    $grava = $texto === '' ? $pdo->prepare('DELETE FROM ferias_saf_notas WHERE solicitacao_saf_id = ?')
        : $pdo->prepare('INSERT INTO ferias_saf_notas (solicitacao_saf_id, texto, atualizado_em, usuario_id) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE texto = VALUES(texto), atualizado_em = VALUES(atualizado_em), usuario_id = VALUES(usuario_id)');
    $pdo->beginTransaction();
    foreach ($ids as $id) $grava->execute($texto === '' ? [$id] : [$id, $texto, $em, $usuarioId]);
    $pdo->commit();
    registrarAuditoria($pdo, $usuarioId, 'nota_ferias_preposto', ['quantidade' => count($ids),
        'detalhe' => 'Ferias de ' . implode(', ', array_map(fn($f) => trim((string) ($f['matricula'] ?? '')) . ' (' . date('d/m/Y', strtotime($f['ferias_inicio'])) . ')', $escolhidas))
            . ' - ' . ($texto === '' ? 'Nota apagada' : "Nota: $texto")]);
    $stmt = $pdo->prepare('SELECT usuario FROM usuarios WHERE id = ?');
    $stmt->execute([$usuarioId]);
    echo json_encode(['success' => true, 'em' => $em, 'por' => (string) $stmt->fetchColumn()]);
    exit;
}
// Matriculas com Historico de observacao gravado (dia ocioso ou nota): SQL do UNION das tabelas que existem ('' = nenhuma).
function sqlMatriculasComHistorico(PDO $pdo): string {
    $tabelas = array_filter(['volante_ociosidade', 'volante_notas_mes'], fn($t) => tabelaExiste($pdo, $t));
    return implode(' UNION ', array_map(fn($t) => "SELECT matricula FROM $t", $tabelas));
}

// Historico de observacao (botao direito ou botao da aba Volantes Ociosos, pedido do usuario, 2026-11-09): meses com dia ocioso
// gravado pela foto diaria (volante_ociosidade, registrar-ociosidade.php: so dias que ja passaram) e/ou com nota, do mais recente ao
// mais antigo. Mesmo acesso da nota (so os volantes que a aba mostra para quem pede); RH/Admin tambem quem saiu da aba (transferido,
// desligado) e tem historico - o Gestor nao: o pool antigo dele nao fica guardado.
if ($acao === 'historico') {
    if ($papel === 'preposto') erroPrevisao('Sem permissão.', 403);
    $matricula = is_scalar($_GET['matricula'] ?? null) ? trim((string) $_GET['matricula']) : '';
    $centrosHistorico = centrosVolantesOciosos($pdo, $papel, (int) $usuarioId, $centrosCusto, $codigosVolante) ?: [''];
    $stmt = $pdo->prepare('SELECT 1 FROM colaboradores WHERE matricula = ? AND centro_custo IN (' . implode(',', array_fill(0, count($centrosHistorico), '?')) . ')');
    $stmt->execute(array_merge([$matricula], $centrosHistorico));
    $pode = $matricula !== '' && $stmt->fetchColumn();
    if (!$pode && $matricula !== '' && in_array($papel, ['rh', 'administrador'], true) && ($sql = sqlMatriculasComHistorico($pdo))) {
        $stmt = $pdo->prepare("SELECT 1 FROM ($sql) h WHERE h.matricula = ? LIMIT 1");
        $stmt->execute([$matricula]);
        $pode = (bool) $stmt->fetchColumn();
    }
    if (!$pode) erroPrevisao('Esse volante não está na sua aba Volantes Ociosos. Atualize a página.', 403);
    $meses = []; // 'AAAA-MM' => ['dias' => [...], 'nota' => [...]]
    if (tabelaExiste($pdo, 'volante_ociosidade')) {
        $stmt = $pdo->prepare('SELECT dia FROM volante_ociosidade WHERE matricula = ? ORDER BY dia');
        $stmt->execute([$matricula]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $dia) $meses[substr($dia, 0, 7)]['dias'][] = $dia;
    }
    if (tabelaExiste($pdo, 'volante_notas_mes')) {
        $stmt = $pdo->prepare('SELECT n.mes, n.texto, n.atualizado_em, u.usuario FROM volante_notas_mes n LEFT JOIN usuarios u ON u.id = n.usuario_id WHERE n.matricula = ?');
        $stmt->execute([$matricula]);
        foreach ($stmt->fetchAll() as $n) $meses[$n['mes']]['nota'] = ['texto' => $n['texto'], 'em' => $n['atualizado_em'], 'por' => (string) $n['usuario']];
    }
    krsort($meses, SORT_STRING);
    echo json_encode(['meses' => array_map(fn($mes, $m) => ['mes' => $mes, 'dias' => $m['dias'] ?? [], 'nota' => $m['nota'] ?? null], array_keys($meses), $meses)]);
    exit;
}
if ($acao === 'volantes' || $acao === 'alterar') {
    if (!$podeAlterar) erroPrevisao('Alteração de cobertura indisponível para o seu perfil ou nesta versão do SAF.', 403);
    $corpo = $acao === 'alterar' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
    $texto = fn(string $campo): string => is_scalar($corpo[$campo] ?? null) ? trim((string) $corpo[$campo]) : ''; // array etc. = vazio
    $id = (int) ($acao === 'alterar' ? ($corpo['id'] ?? 0) : ($_GET['id'] ?? 0));
    $f = null;
    foreach ($ferias as $item) if ((int) ($item['id'] ?? 0) === $id) $f = $item;
    if (!$f) erroPrevisao('Férias não encontradas.', 404);
    // "FERIAS DE VOLANTE" (volante de ferias, sem pedir cobertura): a tela nao deixa clicar e a API tambem nao aceita.
    if (($f['precisa_cobertura'] ?? 'Sim') !== 'Sim' && in_array(trim((string) ($f['centro_custo'] ?? '')), $codigosVolante, true)) {
        erroPrevisao('Férias de volante não têm cobertura para alterar.', 409);
    }
    $emAndamento = $f['ferias_inicio'] <= $hoje;
    if ($f['ferias_fim'] < $hoje) erroPrevisao('As férias já terminaram: a cobertura não pode mais ser alterada.', 409);
    $mudanca = $emAndamento ? mudancaDepoisDoInicio($f, $hoje, $codigosVolante, $aceitaEmprestimo, $aceitaTroca) : null;
    $troca = $mudanca === 'trocar';
    if ($emAndamento && !$mudanca) erroPrevisao(mudancaDepoisDoInicio($f, $hoje, $codigosVolante, $aceitaEmprestimo, true) === 'trocar'
        ? 'A versão do SAF em uso ainda não aceita trocar quem cobre depois do início das férias.'
        : 'As férias já começaram: só dá para incluir quem cobre quando estão como vaga em aberto ou "não precisa de cobertura", ou trocar quem cobre.', 409);
    if ($emAndamento && !$aceitaTransferencia) erroPrevisao('A versão do SAF em uso ainda não aceita incluir a cobertura depois do início das férias.', 409);
    // Depois do inicio: so quem cobre; na troca tambem Vaga em aberto, e na troca e na vaga em aberto "nao precisa de cobertura"
    // (pedidos do usuario, 2026-11-09). Ja "nao precisa": so incluir quem cobre.
    if ($acao === 'alterar' && $emAndamento && (empty($corpo['precisa_cobertura']) ? ($f['precisa_cobertura'] ?? 'Sim') !== 'Sim'
            : !in_array($texto('cobertura'), $troca ? ['volante', 'transferencia', 'emprestimo', 'vaga'] : ['volante', 'transferencia', 'emprestimo'], true))) {
        erroPrevisao($troca ? 'Depois do início das férias quem cobre só pode ser trocado por outra pessoa (volante, transferência ou colaborador emprestado), por Vaga em aberto ou por "não precisa de cobertura".'
            : 'Depois do início das férias só dá para escolher quem cobre: um volante, uma transferência ou um colaborador emprestado'
                . (($f['precisa_cobertura'] ?? 'Sim') === 'Sim' ? ' (ou marcar "não precisa de cobertura").' : '.'), 409);
    }
    // Vaga em aberto em andamento -> "nao precisa" saiu junto com a troca: SAF sem aceita_troca recusaria (P7, 2026-11-10).
    if ($acao === 'alterar' && $emAndamento && empty($corpo['precisa_cobertura']) && !$aceitaTroca) {
        erroPrevisao('A versão do SAF em uso ainda não aceita passar para "não precisa de cobertura" depois do início das férias.', 409);
    }
    // Primeiro dia da cobertura. Depois do inicio: o "a partir de" do pedido ou da lista (sem ele, hoje: o padrao da tela).
    $aPartirGet = is_scalar($_GET['a_partir_de'] ?? null) ? trim((string) $_GET['a_partir_de']) : ''; // vazio ou array = hoje
    $aPartirDe = !$emAndamento ? $f['ferias_inicio'] : ($acao === 'alterar' ? $texto('a_partir_de') : ($aPartirGet !== '' ? $aPartirGet : $hoje));
    if ($emAndamento) {
        [$minimo, $maximo] = faixaAPartirDe($f, $hoje, $troca, $aceitaTroca);
        // Lista de volantes sem dia escolhido: hoje, ou o dia em que quem cobre comeca (troca programada; o painel abre nele), dentro da faixa.
        if ($acao === 'volantes' && $aPartirGet === '') $aPartirDe = min($maximo, max($minimo, $hoje, $troca ? $f['cobertura_inicio'] : ''));
        $dia = DateTime::createFromFormat('!Y-m-d', $aPartirDe);
        if (!$dia || $dia->format('Y-m-d') !== $aPartirDe || $aPartirDe < $minimo || $aPartirDe > $maximo) {
            erroPrevisao('Escolha o "A partir de" entre ' . date('d/m/Y', strtotime($minimo)) . ' e ' . date('d/m/Y', strtotime($maximo)) . '.');
        }
    }
    $poolGestor = $papel === 'gestor' ? coberturaGestorLiberada($pdo, $usuarioId) : null;
    $candidatos = volantesDisponiveisPrevisao($pdo, $f, $todas, (array) $arquivoSaf['contratos_cobertura'], $poolGestor, $aPartirDe, $cobrindoOutra);

    if ($acao === 'volantes') {
        // Ferias que o perfil nao ve (Gestor, outro contrato): vai sem o nome de quem esta de ferias.
        $visiveis = array_column($ferias, 'id', 'id');
        foreach ($cobrindoOutra as &$v) $v['cobrindo'] = array_map(fn($c) => ['ferias_de' => isset($visiveis[$c['id']]) ? $c['ferias_de'] : '', 'ate' => $c['ate']], $v['cobrindo']);
        unset($v);
        echo json_encode(['contrato_aceita' => $candidatos !== null, 'volantes' => $candidatos ?? [], 'cobrindo_outra' => $cobrindoOutra, 'aceita_transferencia' => $aceitaTransferencia,
            'aceita_emprestimo' => $aceitaEmprestimo,
            'centros_volante' => $aceitaTransferencia ? $codigosVolante : []]); // a busca da transferencia esconde quem ja e volante
        exit;
    }

    $precisa = !empty($corpo['precisa_cobertura']);
    $cobertura = $texto('cobertura');
    $volante = '';
    $volanteMatricula = '';
    $link = '';
    $substitutoMatricula = '';
    $substitutoNome = '';
    $soLink = false; // depois do inicio, so o link da mesma transferencia (abaixo)
    $soSubstituto = false; // depois do inicio, so o substituto do mesmo emprestado (abaixo)
    if ($precisa) {
        if ($candidatos === null) erroPrevisao('Esse contrato não está em "Contratos que precisam de cobertura" no SAF.', 409);
        if ($cobertura === 'interna') $volante = 'Cobertura interna';
        elseif ($cobertura === 'vaga') $volante = 'Vaga em aberto';
        elseif ($cobertura === 'volante') {
            foreach ($candidatos as $c) if ($c['matricula'] === trim((string) ($corpo['volante_matricula'] ?? ''))) [$volante, $volanteMatricula] = [$c['nome'], $c['matricula']];
            if ($volanteMatricula === '') erroPrevisao($emAndamento
                ? 'Esse volante não está livre desde ' . date('d/m/Y', strtotime($aPartirDe)) . ' (outra cobertura, férias, admitido depois ou desligado antes do fim): escolha outro volante ou um "A partir de" mais recente.'
                : 'Esse volante não está disponível para essas férias. Atualize a página e escolha de novo.', 409);
        } elseif ($cobertura === 'transferencia') {
            // Colaborador ativo que ainda nao e volante (o SAF reconfere no cadastro dele): aqui o formato, o titular, se ja e
            // volante no cadastro do CGV, o link e as datas ocupadas.
            if (!$aceitaTransferencia) erroPrevisao('A versão do SAF em uso ainda não aceita transferência.', 409);
            $volanteMatricula = $texto('volante_matricula');
            if (preg_match('/^\d{1,10}$/', $volanteMatricula)) $volanteMatricula = str_pad(ltrim($volanteMatricula, '0'), 6, '0', STR_PAD_LEFT);
            $volante = mb_substr(trim(preg_replace('/\s+/u', ' ', $texto('volante_nome'))), 0, 120);
            $link = linkTransferenciaValido($texto('link'));
            if (!preg_match('/^\d{6}$/', $volanteMatricula) || $volanteMatricula === '000000' || $volante === '') erroPrevisao('Escolha quem vai cobrir (será transferido a volante).');
            if ($volanteMatricula === trim((string) $f['matricula'])) erroPrevisao('O próprio titular não pode cobrir as férias dele.');
            // So quem ainda nao e volante (volante se escolhe na lista: respeita o pool do Gestor e o Ajuste Tabelas). A propria
            // transferencia ja gravada passa (corrigir so o link depois que a pessoa foi lotada num volante), como no SAF.
            $mesmaTransferencia = ehTransferenciaSaf($f) && trim((string) ($f['volante_matricula'] ?? '')) === $volanteMatricula;
            // Depois do inicio, a mesma transferencia e so a correcao do link (decisao do usuario, 2026-11-10): o SAF grava so o link, sem
            // trecho nem aviso, entao as checagens de quem passa a cobrir (outras coberturas, "ja cobre", posto ja coberto) nao valem.
            $soLink = $troca && $mesmaTransferencia;
            $stmt = $pdo->prepare('SELECT centro_custo, data_admissao, desligamento FROM colaboradores WHERE matricula = ?');
            $stmt->execute([$volanteMatricula]);
            $iso = fn($d) => ($x = DateTime::createFromFormat('!d/m/Y', (string) $d)) ? $x->format('Y-m-d') : '';
            foreach ($stmt->fetchAll() as $c) {
                $saida = $iso($c['desligamento']);
                if (!$saida || $saida >= $hoje) { // linha ativa no cadastro do CGV (o dia do desligamento ainda conta)
                    if (!$mesmaTransferencia && in_array(trim((string) $c['centro_custo']), $codigosVolante, true)) {
                        erroPrevisao('Essa pessoa já é volante: escolha na lista de volantes.', 409);
                    }
                    if ($iso($c['data_admissao']) > $aPartirDe || ($saida && $saida < $f['ferias_fim'])) {
                        erroPrevisao('Essa pessoa não está ativa durante toda a cobertura (admissão depois do início dela ou desligamento antes do fim das férias).', 409);
                    }
                }
            }
            if ($link === '') erroPrevisao('Informe um link de transferência válido: começando com http:// ou https://, sem espaços, até 240 caracteres.');
            if (!$soLink) foreach (diasOcupadosVolantes($pdo, $todas, $aPartirDe, false, (int) $f['id'], (string) $f['matricula'])[$volanteMatricula] ?? [] as [$ini, $fim]) {
                if ($ini <= $f['ferias_fim'] && $fim >= $aPartirDe) erroPrevisao('Essa pessoa já tem férias ou outra cobertura nessas datas.', 409);
            }
        } elseif ($cobertura === 'emprestimo') {
            // Outro contrato emprestou quem cobre: qualquer colaborador ativo (ate volante de outro pool). Com "o posto precisara ser
            // substituido", tambem quem vai para o posto de origem dele. O SAF reconfere no cadastro de cada um.
            if (!$aceitaEmprestimo) erroPrevisao('A versão do SAF em uso ainda não aceita colaborador emprestado.', 409);
            $pessoa = function (string $campoMat, string $campoNome) use ($texto): array {
                $mat = $texto($campoMat);
                if (preg_match('/^\d{1,10}$/', $mat)) $mat = str_pad(ltrim($mat, '0'), 6, '0', STR_PAD_LEFT);
                $nome = mb_substr(trim(preg_replace('/\s+/u', ' ', $texto($campoNome))), 0, 120);
                return preg_match('/^\d{6}$/', $mat) && $mat !== '000000' && $nome !== '' ? [$mat, $nome] : ['', ''];
            };
            [$volanteMatricula, $volante] = $pessoa('volante_matricula', 'volante_nome');
            if ($volanteMatricula === '') erroPrevisao('Escolha quem vem emprestado.');
            if (!empty($corpo['posto_substituido'])) {
                [$substitutoMatricula, $substitutoNome] = $pessoa('substituto_matricula', 'substituto_nome');
                if ($substitutoMatricula === '') erroPrevisao('Escolha quem vamos enviar ao posto de origem.');
            }
            if ($volanteMatricula === trim((string) $f['matricula'])) erroPrevisao('O próprio titular não pode cobrir as férias dele.');
            if ($substitutoMatricula === trim((string) $f['matricula'])) erroPrevisao('O titular das férias não pode ir para o posto de origem.');
            if ($substitutoMatricula === $volanteMatricula) erroPrevisao('Quem vai para o posto de origem tem que ser outra pessoa, não o próprio emprestado.');
            // Depois do inicio, o mesmo emprestado com outro substituto (decisao do usuario, 2026-11-11): so o posto de origem muda; o SAF
            // guarda os dias com o substituto anterior num trecho, como numa troca.
            $soSubstituto = $troca && ehEmprestimoSaf($f) && trim((string) ($f['volante_matricula'] ?? '')) === $volanteMatricula
                && trim((string) ($f['substituto_matricula'] ?? '')) !== $substitutoMatricula;
            conferirDisponivelEmprestimoPrevisao($pdo, $volanteMatricula, $volante, $f, $todas, $aPartirDe, $hoje, true);
            if ($substitutoMatricula !== '') conferirDisponivelEmprestimoPrevisao($pdo, $substitutoMatricula, $substitutoNome, $f, $todas, $aPartirDe, $hoje);
        } else erroPrevisao('Escolha quem cobre as férias.');
        // Troca: quem ja cobre nao troca por ele mesmo (o SAF tambem recusa).
        $atualVol = $troca ? (string) ($f['matricula_vol'] ?: trim((string) ($f['volante_matricula'] ?? ''))) : '';
        if ($troca && !$soLink && !$soSubstituto && $volanteMatricula !== '' && in_array($volanteMatricula, [$atualVol, trim((string) ($f['volante_matricula'] ?? ''))], true)) {
            erroPrevisao("$volante já cobre estas férias: escolha outra pessoa ou Vaga em aberto.", 409);
        }
        // Depois do inicio: dia ja passado (ou hoje) em que o posto do titular foi coberto no CGV por outra pessoa. O PFM passaria
        // esse dia pra quem entra agora (semDiasDeFeriasSaf, db.php) e quem trabalhou perderia. De amanha em diante quem resolve e
        // o corte (cortar-coberturas-ferias.php tira a outra pessoa da linha do titular, com aviso); o que ja passou fica. Na troca,
        // a linha que o corte passou para quem cobria as ferias muda de dono junto (sao os dias da troca); Vaga em aberto nao paga
        // ninguem nesses dias, entao nada a conferir.
        if ($emAndamento && $volante !== 'Vaga em aberto' && !$soLink) {
            // Troca programada refeita para antes: os dias do trecho anterior colado nela tambem mudam de dono (o SAF encurta o trecho).
            $encurtado = '';
            if ($troca && $aPartirDe < $f['cobertura_inicio']) {
                foreach (is_array($f['coberturas_anteriores'] ?? null) ? $f['coberturas_anteriores'] : [] as $t) {
                    if (is_array($t) && date('Y-m-d', strtotime(($t['ate'] ?? '') . ' +1 day')) === $f['cobertura_inicio']) $encurtado = trim((string) ($t['volante_matricula'] ?? ''));
                }
            }
            $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(nome_vol, ''), matricula_vol) AS quem, DATE_FORMAT(STR_TO_DATE(data, '%d/%m/%Y'), '%Y-%m-%d') AS dia
                                     FROM solicitacoes_cobertura WHERE matricula = ? AND matricula_vol NOT IN ('', 'SEM_COBERTURA', ?, ?, ?)
                                      AND STR_TO_DATE(data, '%d/%m/%Y') BETWEEN ? AND ? ORDER BY STR_TO_DATE(data, '%d/%m/%Y') DESC LIMIT 1");
            // Emprestimo com substituto: o posto de origem do emprestado (as linhas dele no CGV) segue a mesma regra, com o substituto.
            $postos = [['O posto do titular', $f['matricula'], $volanteMatricula]];
            if ($substitutoMatricula !== '') $postos[] = ["O posto de origem de $volante", $volanteMatricula, $substitutoMatricula];
            foreach ($postos as [$posto, $dono, $quemCobre]) {
                $stmt->execute([$dono, $quemCobre, $atualVol, $encurtado, $aPartirDe, $hoje]);
                if ($l = $stmt->fetch()) {
                    $depois = date('Y-m-d', strtotime("{$l['dia']} +1 day"));
                    erroPrevisao("$posto já foi coberto no CGV por {$l['quem']} em " . date('d/m/Y', strtotime($l['dia'])) . ': '
                        . ($depois > $f['ferias_fim'] ? 'não há mais dias destas férias para incluir a cobertura.'
                            : ($depois > $hoje ? 'só dá para incluir a cobertura a partir de amanhã.' : 'escolha um "A partir de" de ' . date('d/m/Y', strtotime($depois)) . ' em diante.')), 409);
                }
            }
        }
    }
    $motivo = $link !== '' ? $link : ($volante === 'Cobertura interna' ? mb_substr($texto('motivo'), 0, 255) : '');

    $pasta = pastaPedidosCoberturaSaf();
    if (!is_dir($pasta)) erroPrevisao('A fila de alterações do SAF ainda não está disponível. Tente de novo em alguns minutos.', 503);
    if (!is_writable($pasta)) {
        error_log("previsao-ferias: sem permissao de escrita em $pasta (volume do SAF montado so para leitura?)");
        erroPrevisao('O CGV não tem permissão para gravar na pasta do SAF. Avise a TI: o volume do SAF está montado só para leitura no CGV.', 503);
    }

    // Trava por ferias: dois usuarios ao mesmo tempo nao mandam dois pedidos. Linha no banco antes do arquivo: o SAF
    // nunca aplica um pedido sem rastro no CGV.
    $nomeTrava = 'cgv_pedido_saf_' . $id;
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $stmt->execute([$nomeTrava]);
    if ((int) $stmt->fetchColumn() !== 1) erroPrevisao('Outra pessoa está alterando essas férias agora. Tente de novo.', 409);
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM pedidos_cobertura_saf WHERE solicitacao_saf_id = ? AND resolvido_em IS NULL');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn()) erroPrevisao('Já existe uma alteração dessas férias aguardando o SAF. Espere ela ser aplicada.', 409);
        $stmt = $pdo->prepare('SELECT usuario FROM usuarios WHERE id = ?');
        $stmt->execute([$usuarioId]);
        $usuario = (string) $stmt->fetchColumn();
        $arquivo = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.json';
        $periodo = date('d/m/Y', strtotime($f['ferias_inicio'])) . ' a ' . date('d/m/Y', strtotime($f['ferias_fim']));
        $resumo = "férias de {$f['nome']} ($periodo): " . ($soLink ? "link da transferência de $volante corrigido"
            : ($soSubstituto ? "substituto no posto de origem de $volante: " . ($substitutoNome ?: 'nenhum') . ' a partir de ' . date('d/m/Y', strtotime($aPartirDe))
                . ' (no lugar de ' . (trim((string) ($f['substituto_nome'] ?? '')) ?: 'nenhum') . ')'
            : ($precisa ? $volante . ($link !== '' ? ' (transferência)' : '')
                    . ($cobertura === 'emprestimo' ? ' (emprestado' . ($substitutoNome !== '' ? "; vai para o posto de origem: $substitutoNome" : '') . ')' : '')
                : 'não precisa de cobertura')
            . ($emAndamento ? ' a partir de ' . date('d/m/Y', strtotime($aPartirDe)) : '')
            . ($troca ? ' (no lugar de ' . trim((string) $f['volante']) . ')' : '')));
        // Gestores do grupo de quem cobre agora e de quem entra (volante e substituto): avisados quando o SAF aplicar
        // (conferirPedidosCoberturaSaf). So o link da transferencia nao muda ninguem.
        $avisar = [];
        if (!$soLink) {
            $atualCobre = !in_array(trim((string) ($f['volante'] ?? '')), ['', 'Vaga em aberto', 'Cobertura interna'], true)
                ? (string) ($f['matricula_vol'] ?: trim((string) ($f['volante_matricula'] ?? ''))) : '';
            $pessoas = [$atualCobre, trim((string) ($f['substituto_matricula'] ?? '')), $precisa ? $volanteMatricula : '', $substitutoMatricula];
            foreach (array_unique(array_filter($pessoas)) as $m) $avisar = array_merge($avisar, gestoresDoGrupoDoVolante($pdo, $m));
        }
        $pdo->prepare('INSERT INTO pedidos_cobertura_saf (solicitacao_saf_id, arquivo, resumo, usuario_id, criado_em, avisar_ids) VALUES (?, ?, ?, ?, NOW(), ?)')
            ->execute([$id, $arquivo, mb_substr($resumo, 0, 300), $usuarioId, $avisar ? implode(',', array_slice(array_unique($avisar), 0, 60)) : null]);
        $idPedido = (int) $pdo->lastInsertId();
        $pedido = ['id' => $id, 'precisa_cobertura' => $precisa, 'volante' => $volante, 'volante_matricula' => $volanteMatricula,
            'volante_motivo' => $motivo, 'usuario' => $usuario, 'enviado_em' => date('c')] + ($link !== '' ? ['transferencia' => true] : [])
            + ($precisa && $cobertura === 'emprestimo' ? ['emprestimo' => true, 'substituto_matricula' => $substitutoMatricula, 'substituto_nome' => $substitutoNome] : [])
            + ($emAndamento ? ['a_partir_de' => $aPartirDe] : []);
        // .tmp + rename: o SAF so le *.json, nunca um arquivo pela metade.
        if (file_put_contents("$pasta/$arquivo.tmp", json_encode($pedido, JSON_UNESCAPED_UNICODE)) === false || !rename("$pasta/$arquivo.tmp", "$pasta/$arquivo")) {
            @unlink("$pasta/$arquivo.tmp");
            $pdo->prepare('DELETE FROM pedidos_cobertura_saf WHERE id = ?')->execute([$idPedido]);
            erroPrevisao('Não foi possível enviar a alteração ao SAF.', 500);
        }
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$nomeTrava]);
    }
    registrarAuditoria($pdo, $usuarioId, 'pedido_cobertura_saf', ['tabela' => 'pedidos_cobertura_saf', 'quantidade' => 1, 'detalhe' => "Pedido ao SAF: $resumo"]);
    echo json_encode(['success' => true]);
    exit;
}

$pendentes = [];
if ($podeAlterar) {
    // Vencido (15 min) ja saiu daqui em conferirPedidosCoberturaSaf; o que sobra e o que o SAF ainda vai responder.
    foreach ($pdo->query('SELECT solicitacao_saf_id, MAX(criado_em) AS em FROM pedidos_cobertura_saf WHERE resolvido_em IS NULL GROUP BY solicitacao_saf_id')->fetchAll() as $p) {
        $pendentes[(int) $p['solicitacao_saf_id']] = $p['em'];
    }
}

// Quem cobriu antes de uma troca (trechos de feriasSafComVolante), por ferias: a tabela mostra um embaixo do outro, cada um com o periodo.
$trechos = [];
foreach ($todas as $t) if (!empty($t['trecho_anterior'])) $trechos[(int) ($t['id'] ?? 0)][] = $t;
$colaboradores = [];
$encontradas = array_values(array_unique(array_filter(array_merge(array_column($ferias, 'matricula_vol'), array_column(array_merge([], ...array_values($trechos)), 'matricula_vol')))));
if ($encontradas) {
    $stmt = $pdo->prepare('SELECT matricula, nome, centro_custo, funcao FROM colaboradores WHERE matricula IN (' . implode(',', array_fill(0, count($encontradas), '?')) . ')');
    $stmt->execute($encontradas);
    foreach ($stmt->fetchAll() as $c) $colaboradores[$c['matricula']] = $c;
}

// Escala = turno do Protheus (RA_TNOTRAB + descricao na SR6010). Funcao vem junto porque o SAF nao
// tem a do Grupo 02: SR6 e SRJ do Grupo 02 estao nas tabelas 010. Consulta ao vivo so dos titulares
// da lista; ativo sobrescreve cadastro antigo da mesma matricula (RA_DEMISSA vazio vem por ultimo).
// Protheus fora do ar so deixa as colunas vazias.
function dadosProtheus(array $matriculas): array {
    if (count($matriculas) === 0) return [];
    $dados = [];
    try {
        $protheus = conectarProtheusDb();
        foreach (array_chunk($matriculas, 1000) as $lote) {
            $marcadores = implode(',', array_fill(0, count($lote), '?'));
            $sql = fn($sra) => "SELECT RTRIM(A.RA_MAT) AS MAT, A.RA_DEMISSA AS DEMISSA, LTRIM(RTRIM(R.R6_DESC)) AS ESCALA, LTRIM(RTRIM(J.RJ_DESC)) AS FUNCAO
                                  FROM $sra A
                                  LEFT JOIN SR6010 R ON R.R6_TURNO = A.RA_TNOTRAB AND R.D_E_L_E_T_ <> '*'
                                  LEFT JOIN SRJ010 J ON J.RJ_FUNCAO = A.RA_CODFUNC AND J.D_E_L_E_T_ <> '*'
                                 WHERE A.D_E_L_E_T_ <> '*' AND A.RA_MAT IN ($marcadores)";
            $stmt = $protheus->prepare($sql('SRA010') . ' UNION ALL ' . $sql('SRA020') . ' ORDER BY DEMISSA DESC');
            $stmt->execute(array_merge($lote, $lote));
            foreach ($stmt->fetchAll() as $l) $dados[$l['MAT']] = $l;
        }
    } catch (Throwable $e) {
        error_log('previsao-ferias: escala nao consultada no Protheus - ' . $e->getMessage());
    }
    return $dados;
}

// Escala so aparece na aba Com Cobertura (que inclui as ferias de volante); das outras so a funcao, e so quando o SAF nao mandou.
$consultar = array_filter($ferias, fn($f) => ($f['precisa_cobertura'] ?? 'Sim') === 'Sim' || trim((string) ($f['funcao'] ?? '')) === ''
    || in_array(trim((string) ($f['centro_custo'] ?? '')), $codigosVolante, true));
$protheus = dadosProtheus(array_values(array_unique(array_map(fn($f) => trim((string) ($f['matricula'] ?? '')), $consultar))));

// Nota do Preposto por ferias (?acao=nota_ferias): quem ve a ferias ve a nota.
$notasFerias = [];
if (tabelaExiste($pdo, 'ferias_saf_notas')) {
    foreach ($pdo->query('SELECT n.solicitacao_saf_id, n.texto, n.atualizado_em, u.usuario FROM ferias_saf_notas n LEFT JOIN usuarios u ON u.id = n.usuario_id')->fetchAll() as $n) {
        $notasFerias[(int) $n['solicitacao_saf_id']] = ['texto' => $n['texto'], 'em' => $n['atualizado_em'], 'por' => (string) $n['usuario']];
    }
}

$dados = [];
foreach ($ferias as $f) {
    $volante = trim((string) ($f['volante'] ?? ''));
    $transferencia = ehTransferenciaSaf($f);
    $emprestimo = ehEmprestimoSaf($f);
    $doProtheus = $protheus[trim((string) ($f['matricula'] ?? ''))] ?? [];
    $colaborador = $colaboradores[$f['matricula_vol'] ?? ''] ?? null;
    $mudanca = $aceitaTransferencia && !empty($f['id']) ? mudancaDepoisDoInicio($f, $hoje, $codigosVolante, $aceitaEmprestimo, $aceitaTroca) : null;
    $dados[] = [
        'id' => (int) ($f['id'] ?? 0),
        'ferias_inicio' => $f['ferias_inicio'],
        'ferias_fim' => $f['ferias_fim'],
        'precisa_cobertura' => ($f['precisa_cobertura'] ?? 'Sim') === 'Sim', // sem o campo = arquivo de SAF anterior, que so exportava as "Sim"
        'titular_centro_custo' => $f['centro_custo'] ?? '',
        // Ferias do proprio volante: a tela mostra em Com Cobertura mesmo sem precisar (o SAF nao pede cobertura de volante)
        'titular_volante' => in_array(trim((string) ($f['centro_custo'] ?? '')), $codigosVolante, true),
        'titular_matricula' => $f['matricula'] ?? '',
        'titular_nome' => $f['nome'] ?? '',
        'titular_funcao' => ($f['funcao'] ?? '') ?: (string) ($doProtheus['FUNCAO'] ?? ''),
        'escala' => (string) ($doProtheus['ESCALA'] ?? ''),
        // "Nao precisa" com volante antigo preenchido conta como vaga (o painel nao mostra esse nome como atual)
        'cobertura' => $volante === 'Cobertura interna' ? 'interna'
            : (in_array($volante, ['', 'Vaga em aberto'], true) || ($f['precisa_cobertura'] ?? 'Sim') !== 'Sim' ? 'vaga' : ($transferencia ? 'transferencia' : ($emprestimo ? 'emprestimo' : 'volante'))),
        // Transferencia e emprestimo: sempre a matricula do SAF (a pessoa pode nao estar no cadastro do CGV).
        'volante_matricula' => $transferencia || $emprestimo ? trim((string) ($f['volante_matricula'] ?? '')) : ($colaborador['matricula'] ?? ''),
        // Emprestimo: quem vai para o posto de origem do emprestado (vazio = ninguem).
        'substituto_matricula' => $emprestimo ? trim((string) ($f['substituto_matricula'] ?? '')) : '',
        'substituto_nome' => $emprestimo ? trim((string) ($f['substituto_nome'] ?? '')) : '',
        'transferencia_link' => $transferencia ? linkTransferenciaValido(substr((string) $f['volante_motivo'], strlen(PREFIXO_TRANSFERENCIA))) : '',
        'volante_nome' => $colaborador['nome'] ?? $volante,
        'volante_centro_custo' => $colaborador['centro_custo'] ?? '',
        'volante_funcao' => $colaborador['funcao'] ?? '',
        'motivo_interna' => $volante === 'Cobertura interna' ? trim((string) ($f['volante_motivo'] ?? '')) : '',
        'editavel' => $podeAlterar && !empty($f['id']) && ($f['ferias_inicio'] > $hoje || $mudanca !== null)
            && (($f['precisa_cobertura'] ?? 'Sim') === 'Sim' || !in_array(trim((string) ($f['centro_custo'] ?? '')), $codigosVolante, true)),
        // Ferias em andamento que da para mudar: faixa do "A partir de" (o painel so deixa escolher quem cobre) e se e troca de quem cobre.
        'a_partir_faixa' => $mudanca ? faixaAPartirDe($f, $hoje, $mudanca === 'trocar', $aceitaTroca) : null,
        'troca' => $mudanca === 'trocar',
        'aceita_nao_precisa' => $aceitaTroca, // depois do inicio, passar para "nao precisa" (P7)
        'cobertura_inicio' => $f['cobertura_inicio'],
        // Quem cobriu antes de uma troca, na ordem: a tabela mostra cada um com o periodo, acima de quem cobre agora.
        'coberturas_anteriores' => array_map(function ($t) use ($colaboradores) {
            $c = $colaboradores[$t['matricula_vol'] ?? ''] ?? null;
            $especial = ehTransferenciaSaf($t) || ehEmprestimoSaf($t);
            return ['nome' => $c['nome'] ?? trim((string) ($t['volante'] ?? '')), 'matricula' => $especial ? trim((string) ($t['volante_matricula'] ?? '')) : ($c['matricula'] ?? ''),
                'centro_custo' => $c['centro_custo'] ?? '', 'funcao' => $c['funcao'] ?? '', 'desde' => $t['ferias_inicio'], 'ate' => $t['ferias_fim'],
                'rotulo' => ehTransferenciaSaf($t) ? 'transferência' : (ehEmprestimoSaf($t) ? 'emprestado' : '')];
        }, $trechos[(int) ($f['id'] ?? 0)] ?? []),
        'pendente_desde' => $pendentes[(int) ($f['id'] ?? 0)] ?? null,
        'nota' => $notasFerias[(int) ($f['id'] ?? 0)] ?? null,
    ];
}

// Aba Volantes Ociosos (Preposto nao ve: null): volantes do perfil (centrosVolantesOciosos) com os dias em que NAO podem ser
// incluidos numa cobertura (diasOcupadosVolantes, com vagas), de ontem em diante (a tela so olha de hoje pra frente; ontem cobre
// fuso), e as notas de cada um por mes (?acao=nota), do mes atual em diante (mes passado a aba nao mostra).
$volantes = null;
if ($papel !== 'preposto') {
    $desde = date('Y-m-d', strtotime('-1 day'));
    $centrosVolante = centrosVolantesOciosos($pdo, $papel, (int) $usuarioId, $centrosCusto, $codigosVolante);
    $ocupado = diasOcupadosVolantes($pdo, $todas, $desde, true);
    $notas = []; // matricula => ['AAAA-MM' => nota]
    if (tabelaExiste($pdo, 'volante_notas_mes')) {
        $stmt = $pdo->prepare('SELECT n.matricula, n.mes, n.texto, n.atualizado_em, u.usuario FROM volante_notas_mes n
                                 LEFT JOIN usuarios u ON u.id = n.usuario_id WHERE n.mes >= ?');
        $stmt->execute([date('Y-m')]);
        foreach ($stmt->fetchAll() as $n) {
            $notas[$n['matricula']][$n['mes']] = ['texto' => $n['texto'], 'em' => $n['atualizado_em'], 'por' => (string) $n['usuario']];
        }
    }
    $volantes = [];
    if ($centrosVolante) {
        $stmt = $pdo->prepare('SELECT matricula, nome, centro_custo, funcao, data_admissao, desligamento FROM colaboradores WHERE centro_custo IN ('
            . implode(',', array_fill(0, count($centrosVolante), '?')) . ') ORDER BY nome');
        $stmt->execute($centrosVolante);
        $iso = fn($d) => ($x = DateTime::createFromFormat('!d/m/Y', (string) $d)) ? $x->format('Y-m-d') : '';
        foreach ($stmt->fetchAll() as $c) {
            $matricula = (string) $c['matricula'];
            if ($iso($c['desligamento']) !== '' && $iso($c['desligamento']) < $desde) continue; // desligado antes: nunca aparece
            if (isset($volantes[$matricula]) && $volantes[$matricula]['desligamento'] === '') continue; // ativo ganha do cadastro antigo
            $volantes[$matricula] = ['matricula' => $matricula, 'nome' => $c['nome'], 'centro_custo' => $c['centro_custo'], 'funcao' => $c['funcao'],
                'admissao' => $iso($c['data_admissao']), 'desligamento' => $iso($c['desligamento']), 'ocupado' => $ocupado[$matricula] ?? [],
                'notas' => $notas[$matricula] ?? null];
        }
    }
    // RH/Admin: quem saiu da aba (transferido, desligado) mas tem Historico de observacao, para a busca do historico (?acao=historico).
    if (in_array($papel, ['rh', 'administrador'], true)) {
        $exVolantes = [];
        if ($sql = sqlMatriculasComHistorico($pdo)) {
            foreach ($pdo->query("SELECT h.matricula, c.nome, c.centro_custo FROM ($sql) h LEFT JOIN colaboradores c ON c.matricula = h.matricula")->fetchAll() as $e) {
                if (isset($volantes[(string) $e['matricula']])) continue;
                $exVolantes[] = ['matricula' => (string) $e['matricula'], 'nome' => (string) ($e['nome'] ?? ''), 'centro_custo' => (string) ($e['centro_custo'] ?? '')];
            }
        }
    }
    $volantes = array_values($volantes);
}

echo json_encode(['dados' => $dados, 'volantes' => $volantes, 'ex_volantes' => $exVolantes ?? null, 'pode_alterar' => $podeAlterar, 'aviso_saf' => $avisoSaf]);
