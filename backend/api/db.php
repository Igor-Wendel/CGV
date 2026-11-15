<?php
// Conexao PDO unica, reutilizada por dados.php e login.php.
// Configuracao: com DB_HOST no ambiente (container/Swarm), TUDO vem do
// ambiente/Docker secrets; sem DB_HOST, config.local.php (se existir) ou
// config.php - o ambiente local de sempre.

// Le <NOME>_FILE (caminho de um Docker secret) e cai pra variavel <NOME> direta; devolve false
// quando nao ha valor, igual getenv().
function valorDeEnv($nome) {
    $caminho = getenv($nome . '_FILE');
    if ($caminho !== false && $caminho !== '') {
        if (!is_readable($caminho)) {
            error_log("valorDeEnv: {$nome}_FILE aponta pra '$caminho', que nao e legivel.");
            return false;
        }
        $conteudo = file_get_contents($caminho);
        if ($conteudo === false) return false;
        // Sem o rtrim, o \n do fim do arquivo viraria parte da senha.
        return rtrim($conteudo, "\r\n");
    }
    return getenv($nome);
}

// Toda constante de configuracao usada no codigo, com o valor padrao (vale
// tanto pra variavel ausente no ambiente quanto pra config.php antigo).
$configPadrao = [
    'DB_HOST' => '', 'DB_PORT' => '3306', 'DB_NAME' => '', 'DB_USER' => '', 'DB_PASS' => '',
    'PROTHEUS_DB_HOST' => '', 'PROTHEUS_DB_PORT' => '1433', 'PROTHEUS_DB_NAME' => '',
    'PROTHEUS_DB_DOMAIN' => '', 'PROTHEUS_DB_USER' => '', 'PROTHEUS_DB_PASSWORD' => '',
    'SMTP_HOST' => '', 'SMTP_PORT' => '465', 'SMTP_USER' => '', 'SMTP_PASSWORD' => '',
    'SMTP_FROM_NOME' => 'CGV - Controle Geral de Volantes',
    'LDAP_HOST' => '', 'LDAP_PORT' => '389', 'LDAP_DOMAIN' => '',
    'LDAP_BIND_DN' => '', 'LDAP_BIND_PASSWORD' => '', 'LDAP_BASE_DN' => '',
    // AD/Samba: objectCategory tira os computadores e userAccountControl tira as contas desativadas.
    'LDAP_FILTRO' => '(&(objectCategory=person)(objectClass=user)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))',
    'USC_API_URL' => '', 'USC_API_TOKEN' => '',
    'CGV_ALLOWED_ORIGIN' => '',
    'SAF_COBERTURA_JSON' => '',
];

if (getenv('DB_HOST')) {
    foreach (array_keys($configPadrao) as $nome) {
        $valor = valorDeEnv($nome);
        if ($valor !== false && $valor !== '') define($nome, $valor);
    }
} else {
    require_once (file_exists(__DIR__ . '/config.local.php') ? __DIR__ . '/config.local.php' : __DIR__ . '/config.php');
}
foreach ($configPadrao as $nome => $padrao) {
    if (!defined($nome)) define($nome, $padrao);
}
unset($configPadrao, $nome, $valor, $padrao);

// CORS fechado por padrao: o frontend chama a API na MESMA origem. So manda
// o cabecalho quando CGV_ALLOWED_ORIGIN estiver definida e bater com a Origin.
if (CGV_ALLOWED_ORIGIN !== '' && ($_SERVER['HTTP_ORIGIN'] ?? '') === CGV_ALLOWED_ORIGIN) {
    header('Access-Control-Allow-Origin: ' . CGV_ALLOWED_ORIGIN);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Session-Token');
    header('Vary: Origin');
}

// Preflight OPTIONS: responde vazio e encerra, sem exigir sessao nem banco.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Excecao nao tratada num endpoint: detalhe so no log, JSON generico pro cliente.
if (PHP_SAPI !== 'cli') {
    set_exception_handler(function (Throwable $e) {
        error_log('Excecao nao tratada em ' . ($_SERVER['REQUEST_URI'] ?? '?') . ': ' . $e);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Erro interno inesperado. Tente novamente em instantes.']);
        exit;
    });
}

// Fixa o fuso do PHP: "hoje" (validacoes de data, ex: Nova Solicitacao nao abre data anterior a hoje - dataBrAntesDeHoje)
// nao pode depender do fuso do servidor (pode ser UTC). Este e o unico arquivo incluido por todo endpoint.
date_default_timezone_set('America/Sao_Paulo');

// Tentativas de conexao e base do backoff exponencial (ms): 5 tentativas com esperas de 1s, 2s, 4s
// e 8s. No Swarm a 1a conexao costuma chegar antes do MariaDB aceitar; cobre tambem restart do banco.
if (!defined('DB_RETRY_TENTATIVAS')) {
    define('DB_RETRY_TENTATIVAS', max(1, (int) (getenv('DB_RETRY_TENTATIVAS') ?: 5)));
}
if (!defined('DB_RETRY_BASE_MS')) {
    define('DB_RETRY_BASE_MS', max(1, (int) (getenv('DB_RETRY_BASE_MS') ?: 1000)));
}

function conectarBanco() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $opcoes = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        // Sem timeout, uma tentativa contra host que engole pacote fica presa.
        PDO::ATTR_TIMEOUT => 5,
    ];
    for ($tentativa = 1; $tentativa <= DB_RETRY_TENTATIVAS; $tentativa++) {
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opcoes);
            // O fuso do PHP nao afeta o MySQL: sem fixar o fuso da sessao, NOW() saia 3h a frente de Brasilia.
            // Fixa por conexao.
            $pdo->exec("SET time_zone = '-03:00'");
            return $pdo;
        } catch (PDOException $e) {
            // O detalhe (host, banco, motivo) so vai pro log do servidor; nao vazar infraestrutura pro cliente.
            error_log('Falha ao conectar no banco (tentativa ' . $tentativa . '/' . DB_RETRY_TENTATIVAS . '): ' . $e->getMessage());
            $pdo = null;
            if ($tentativa < DB_RETRY_TENTATIVAS) {
                usleep(DB_RETRY_BASE_MS * 1000 * (2 ** ($tentativa - 1)));
            }
        }
    }

    // Script de linha de comando: codigo de saida diferente de 0, pra cadeia
    // noturna perceber a falha ("exit;" sem argumento sairia com 0).
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Falha ao conectar no banco apos ' . DB_RETRY_TENTATIVAS . " tentativas (detalhe no log).\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Falha ao conectar no banco. Tente novamente em instantes.']);
    exit;
}

// Conexao com o SQL Server do Protheus (FreeTDS/pdo_dblib), usada pelos scripts de sync e por
// buscas pontuais (dados.php, protheus-buscar-colaborador.php). SEM cache "static" (diferente de
// conectarBanco()). A excecao sobe pro chamador, que decide a resposta.
function conectarProtheusDb(): PDO {
    if (empty(PROTHEUS_DB_HOST) || empty(PROTHEUS_DB_USER)) {
        throw new RuntimeException('PROTHEUS_DB_HOST/PROTHEUS_DB_USER nao configurados.');
    }
    $dsn = 'dblib:host=' . PROTHEUS_DB_HOST . ':' . PROTHEUS_DB_PORT . ';dbname=' . PROTHEUS_DB_NAME . ';charset=UTF-8';
    $usuario = (PROTHEUS_DB_DOMAIN ? PROTHEUS_DB_DOMAIN . '\\' : '') . PROTHEUS_DB_USER;
    return new PDO($dsn, $usuario, PROTHEUS_DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 30,
    ]);
}

// Le o JSON de ferias que o SAF exporta a cada 3 min (SAF_COBERTURA_JSON); o CGV nao acessa o banco
// do SAF. Formato: {"gerado_em": ISO, "dados": [{"matricula", "ferias_inicio": "AAAA-MM-DD",
// "ferias_fim": "AAAA-MM-DD", "volante": NOME, "volante_matricula", "precisa_cobertura", ...}]}, so
// aprovadas. Arquivo ausente/ilegivel/invalido ou com mais de 24h: devolve [] e $aviso recebe o
// motivo (tela: "aviso_saf"). Usada por pfm-calculo.php, previsao-ferias.php e feriasSafComVolante.
function lerCoberturaSaf(?string &$aviso, ?array &$arquivo = null): array {
    $aviso = null;
    $caminho = (string) SAF_COBERTURA_JSON;
    $conteudo = ($caminho !== '' && is_readable($caminho)) ? file_get_contents($caminho) : false;
    $json = $conteudo !== false ? json_decode($conteudo, true) : null;
    $geradoEm = is_array($json) ? strtotime((string) ($json['gerado_em'] ?? '')) : false;

    if ($caminho === '') {
        $motivo = 'a integração com o SAF não está configurada neste ambiente';
    } elseif ($conteudo === false) {
        $motivo = 'o arquivo de cobertura do SAF não foi encontrado';
    } elseif (!is_array($json) || !is_array($json['dados'] ?? null) || !$geradoEm) {
        $motivo = 'o arquivo de cobertura do SAF está inválido';
    } elseif (time() - $geradoEm > 24 * 3600) {
        $motivo = 'o arquivo de cobertura do SAF está desatualizado (gerado em ' . date('d/m/Y H:i', $geradoEm) . ')';
    } else {
        $motivo = null;
    }
    if ($motivo !== null) {
        error_log("cobertura de ferias do SAF nao lida ($caminho) - $motivo");
        $aviso = "As férias do SAF não foram consideradas: $motivo.";
        return [];
    }
    $arquivo = $json;
    return $json['dados'];
}

// TRANSFERENCIA (so pelo CGV, coberturas/previsao.html): quem cobre ainda vai ser transferido a volante. Mesmo contrato do
// SAF (aplicar-pedidos-cgv.php): volante = nome, volante_matricula e volante_motivo = PREFIXO_TRANSFERENCIA . link.
const PREFIXO_TRANSFERENCIA = 'TRANSFERENCIA: ';
function ehTransferenciaSaf(array $f): bool {
    return !in_array(trim((string) ($f['volante'] ?? '')), ['', 'Vaga em aberto', 'Cobertura interna'], true)
        && ($f['precisa_cobertura'] ?? 'Sim') === 'Sim' && str_starts_with((string) ($f['volante_motivo'] ?? ''), PREFIXO_TRANSFERENCIA);
}

// COLABORADOR EMPRESTADO (so pelo CGV, coberturas/previsao.html): outro contrato emprestou quem cobre. Mesmo contrato do SAF
// (aplicar-pedidos-cgv.php): volante = nome, volante_matricula e volante_motivo = PREFIXO_EMPRESTIMO; com "o posto precisara ser
// substituido", substituto_matricula/substituto_nome = quem vai para o posto de origem do emprestado (linhasPostoOrigemSaf).
const PREFIXO_EMPRESTIMO = 'EMPRESTADO';
function ehEmprestimoSaf(array $f): bool {
    return !in_array(trim((string) ($f['volante'] ?? '')), ['', 'Vaga em aberto', 'Cobertura interna'], true)
        && ($f['precisa_cobertura'] ?? 'Sim') === 'Sim' && (string) ($f['volante_motivo'] ?? '') === PREFIXO_EMPRESTIMO;
}

// lerCoberturaSaf + "matricula_vol" (null = sem volante, nome sem cadastro no CGV ou transferencia de quem ainda nao esta no
// cadastro) + "cobertura_inicio": a cobertura so conta a partir de "cobertura_desde" (o "a partir de" de quem entrou depois do
// inicio, senao o dia da aprovacao; vazio = arquivo antigo, vale a ferias inteira). No fim, as linhas do posto de origem dos
// colaboradores emprestados com substituto (linhasPostoOrigemSaf, "posto_origem" = true).
// Troca de quem cobre depois do inicio (coberturas_anteriores do SAF, aplicar-pedidos-cgv.php): cada trecho de quem cobriu antes vira
// uma linha a mais, como o posto de origem - as datas do trecho em ferias_inicio..ferias_fim e "trecho_anterior" = true. Paga e
// ocupa quem cobriu nesses dias (PFM, Previsao, conflito ao designar, corte); nao e outra ferias (as listas de ferias pulam). A linha
// das ferias segue com quem cobre agora, de cobertura_desde (o dia da troca). Arquivo de SAF anterior: sem trechos.
function feriasSafComVolante(PDO $pdo, ?string &$aviso, ?array &$arquivo = null): array {
    $dataValida = function ($data): bool {
        $dia = DateTime::createFromFormat('!Y-m-d', (string) $data);
        return $dia !== false && $dia->format('Y-m-d') === $data;
    };
    $ferias = array_filter(lerCoberturaSaf($aviso, $arquivo), function ($f) use ($dataValida) {
        if (is_array($f) && $dataValida($f['ferias_inicio'] ?? null) && $dataValida($f['ferias_fim'] ?? null)) return true;
        error_log('ferias do SAF ignorada, data invalida: ' . json_encode($f));
        return false;
    });
    foreach ($ferias as $f) {
        foreach (is_array($f['coberturas_anteriores'] ?? null) ? $f['coberturas_anteriores'] : [] as $t) {
            if (!is_array($t) || !$dataValida($t['desde'] ?? null) || !$dataValida($t['ate'] ?? null) || $t['desde'] > min($t['ate'], $f['ferias_fim'])) continue;
            $ferias[] = ['trecho_anterior' => true, 'ferias_inicio' => $t['desde'], 'ferias_fim' => min($t['ate'], $f['ferias_fim']), 'precisa_cobertura' => 'Sim',
                'volante' => (string) ($t['volante'] ?? ''), 'volante_matricula' => (string) ($t['volante_matricula'] ?? ''),
                'volante_motivo' => (string) ($t['volante_motivo'] ?? ''), 'substituto_matricula' => (string) ($t['substituto_matricula'] ?? ''),
                'substituto_nome' => (string) ($t['substituto_nome'] ?? ''), 'cobertura_desde' => $t['desde'], 'coberturas_anteriores' => []] + $f;
        }
    }
    // "Nao precisa" com volante antigo preenchido nao e cobertura: nao paga, nao corta, nao ocupa.
    $comVolante = array_filter($ferias, fn($f) => ($f['precisa_cobertura'] ?? 'Sim') === 'Sim'
        && !in_array(trim((string) ($f['volante'] ?? '')), ['', 'Vaga em aberto', 'Cobertura interna'], true));
    $matriculas = matriculasVolanteSaf($pdo, $comVolante);
    foreach ($ferias as $i => &$f) {
        $f['matricula_vol'] = $matriculas[$i] ?? null;
        $f['cobertura_inicio'] = max($f['ferias_inicio'], $dataValida($f['cobertura_desde'] ?? null) ? $f['cobertura_desde'] : '');
    }
    unset($f);
    return array_merge(array_values($ferias), linhasPostoOrigemSaf($pdo, $ferias));
}

// Colaborador emprestado com substituto (ehEmprestimoSaf): quem vai para o posto de origem do emprestado cobre esse posto nas
// mesmas datas. Vira uma linha a mais no formato das ferias, com o emprestado no lugar do titular (filial, centro e funcao dele
// no cadastro do CGV) e "posto_origem" = true: ocupa o substituto (Previsao, conflito ao designar, corte) e paga os dias dele no
// posto de origem (PFM e Dashboard, diasCoberturaFeriasSaf). Nao e ferias: a Previsao nao lista e o emprestado nao fica "de
// ferias". Mesmo "id" das ferias (o painel ignora as duas juntas ao trocar a cobertura); substituto so pela matricula do SAF.
function linhasPostoOrigemSaf(PDO $pdo, array $ferias): array {
    $comSubstituto = array_filter($ferias, fn($f) => ehEmprestimoSaf($f) && trim((string) ($f['substituto_matricula'] ?? '')) !== '');
    if (!$comSubstituto) return [];
    $mats = [];
    foreach ($comSubstituto as $f) array_push($mats, trim((string) $f['volante_matricula']), trim((string) $f['substituto_matricula']));
    $mats = array_values(array_unique($mats));
    $stmt = $pdo->prepare('SELECT matricula, filial, centro_custo, funcao, desligamento FROM colaboradores WHERE matricula IN ('
        . implode(',', array_fill(0, count($mats), '?')) . ')');
    $stmt->execute($mats);
    $cadastro = []; // matricula => linha (a ativa ganha do cadastro antigo da mesma matricula)
    foreach ($stmt->fetchAll() as $c) {
        if (!isset($cadastro[$c['matricula']]) || trim((string) $c['desligamento']) === '') $cadastro[$c['matricula']] = $c;
    }
    $linhas = [];
    foreach ($comSubstituto as $f) {
        $emprestado = $cadastro[trim((string) $f['volante_matricula'])] ?? [];
        $substituto = trim((string) $f['substituto_matricula']);
        $linhas[] = [
            'id' => $f['id'] ?? null,
            'posto_origem' => true,
            'ferias_titular' => (string) ($f['nome'] ?? ''), // de quem sao as ferias que o emprestado cobre (textos)
            'filial' => (string) ($emprestado['filial'] ?? ''),
            'centro_custo' => (string) ($emprestado['centro_custo'] ?? ''),
            'matricula' => trim((string) $f['volante_matricula']),
            'nome' => (string) ($f['volante'] ?? ''),
            'funcao' => (string) ($emprestado['funcao'] ?? ''),
            'ferias_inicio' => $f['cobertura_inicio'],
            'ferias_fim' => $f['ferias_fim'],
            'precisa_cobertura' => 'Sim',
            'volante' => (string) ($f['substituto_nome'] ?? ''),
            'volante_matricula' => $substituto,
            'volante_motivo' => '',
            'cobertura_desde' => $f['cobertura_inicio'],
            'cobertura_inicio' => $f['cobertura_inicio'],
            'matricula_vol' => isset($cadastro[$substituto]) ? $substituto : null,
        ];
    }
    return $linhas;
}

// Pasta onde o CGV deixa os pedidos de alteracao de cobertura pro SAF (mesmo volume do arquivo; previsao-ferias.php).
function pastaPedidosCoberturaSaf(): string {
    return dirname((string) SAF_COBERTURA_JSON) . '/pedidos';
}

// Resultado dos pedidos que o SAF ja aplicou (pedidos/resultados): grava em pedidos_cobertura_saf e avisa quem pediu.
// Pedido sem resposta em 15 minutos (SAF parado ou em versao antiga) vence: sai da fila, quem pediu e avisado e a
// ferias fica liberada para outro pedido. Se o SAF ja pegou o arquivo (renomeado para .lendo), espera o resultado dele.
// Chamado pela Previsao e pelo sino (notificacoes.php), entao o aviso chega em qualquer pagina.
function conferirPedidosCoberturaSaf(PDO $pdo): void {
    if ((string) SAF_COBERTURA_JSON === '' || !tabelaExiste($pdo, 'pedidos_cobertura_saf')) return;
    $pasta = pastaPedidosCoberturaSaf();
    $stmtResolver = $pdo->prepare('UPDATE pedidos_cobertura_saf SET aplicado = ?, mensagem = ?, resolvido_em = NOW() WHERE id = ? AND resolvido_em IS NULL');
    $pedidos = $pdo->query('SELECT id, arquivo, resumo, usuario_id, avisar_ids, criado_em < NOW() - INTERVAL 15 MINUTE AS vencido
                              FROM pedidos_cobertura_saf WHERE resolvido_em IS NULL')->fetchAll();
    foreach ($pedidos as $p) {
        $caminho = "$pasta/resultados/{$p['arquivo']}";
        if (!is_file($caminho)) {
            $pedido = "$pasta/{$p['arquivo']}";
            // Vence se ainda nao foi reservado pelo SAF, ou se sumiu sem resposta (falha ao gravar, CGV parado no envio).
            // A resposta e conferida de novo por ultimo: o SAF grava a resposta antes de apagar o .lendo.
            if ($p['vencido'] && (@unlink($pedido) || (!file_exists($pedido) && !file_exists("$pedido.lendo") && !is_file($caminho)))) {
                $stmtResolver->execute([0, 'Sem resposta do SAF em 15 minutos.', $p['id']]);
                if ($stmtResolver->rowCount()) {
                    criarNotificacoes($pdo, [(int) $p['usuario_id']], 'pedido_saf',
                        "SEM RESPOSTA DO SAF: {$p['resumo']}. Nada foi alterado; confira na Previsão e envie de novo se precisar.", null, null);
                }
            }
            continue;
        }
        $r = json_decode((string) file_get_contents($caminho), true);
        if (!is_array($r) || !isset($r['ok'])) continue; // ainda sendo gravado
        $ok = !empty($r['ok']);
        $stmtResolver->execute([$ok ? 1 : 0, mb_substr((string) ($r['mensagem'] ?? ''), 0, 300), $p['id']]);
        if ($stmtResolver->rowCount()) {
            // "mudou" = false: o pedido era igual ao que ja estava no SAF (SAF anterior nao manda o campo = mudou).
            $mensagem = !$ok ? "ALTERAÇÃO RECUSADA PELO SAF: {$p['resumo']}. " . ($r['mensagem'] ?? '')
                : (($r['mudou'] ?? true) === false ? "SEM MUDANÇA NO SAF: {$p['resumo']} (já estava assim)." : "COBERTURA ALTERADA NO SAF: {$p['resumo']}.");
            criarNotificacoes($pdo, [(int) $p['usuario_id']], 'pedido_saf', mb_substr($mensagem, 0, 500), null, null);
            // Aplicado e mudou: os Gestores do grupo de quem saiu ou entrou (volante e substituto; gravados no pedido) tambem sabem.
            if ($ok && ($r['mudou'] ?? true) !== false && trim((string) $p['avisar_ids']) !== '') {
                criarNotificacoes($pdo, array_map('intval', explode(',', (string) $p['avisar_ids'])), 'pedido_saf',
                    mb_substr("TROCA COM VOLANTE DO SEU GRUPO: {$p['resumo']}.", 0, 500), null, (int) $p['usuario_id']);
            }
        }
        @unlink($caminho);
    }
}

// Dias que cada volante cobre de ferias do SAF no periodo (ISO), 1 linha por dia, no formato de
// solicitacoes_cobertura: base unica do PFM, dos meses do PFM e do Dashboard. Um registro por dia: dia que
// o volante ja tem no CGV em outro posto fica com o CGV, dia em que ele faltou nao conta, nem dia antes de cobertura_inicio
// (feriasSafComVolante) ou depois do desligamento. A linha do CGV do proprio titular no dia pago aqui sai do calculo (semDiasDeFeriasSaf). Volante ou titular sem cadastro no CGV ficam de fora e entram em $aviso
// (menos a transferencia: quem ainda vai ser transferido fora do cadastro e esperado, fica de fora sem aviso). Dia do posto de origem de
// um emprestado (linhasPostoOrigemSaf): "emprestado_para" = de quem sao as ferias que ele cobre (null nos outros dias).
function diasCoberturaFeriasSaf(PDO $pdo, string $dataInicio, string $dataFim, ?string &$aviso): array {
    $ferias = feriasSafComVolante($pdo, $aviso);

    $stmt = $pdo->prepare(
        "SELECT matricula, matricula_vol, ausencia_matricula_vol, data FROM solicitacoes_cobertura
          WHERE STR_TO_DATE(data, '%d/%m/%Y') BETWEEN ? AND ?"
    );
    $stmt->execute([$dataInicio, $dataFim]);
    $diaOcupado = [];
    foreach ($stmt->fetchAll() as $l) {
        // Linha pendente (matricula_vol vazio) nao ocupa ninguem. Linha do proprio titular nas ferias tambem nao: a
        // ferias tem prioridade e essa linha sai do calculo (semDiasDeFeriasSaf).
        if ((string) $l['matricula_vol'] !== '') $diaOcupado[$l['matricula_vol'] . '|' . $l['data']][$l['matricula']] = true;
        if ((string) $l['ausencia_matricula_vol'] !== '') $diaOcupado[$l['ausencia_matricula_vol'] . '|' . $l['data']][''] = true;
    }
    // Volante ocupado nesse dia por outra linha do CGV que nao seja do proprio titular dessas ferias.
    // Por referencia: o loop abaixo marca os dias que ja pagou.
    $ocupado = function (string $volante, string $titular, string $data) use (&$diaOcupado): bool {
        return array_diff_key($diaOcupado[$volante . '|' . $data] ?? [], [$titular => true]) !== [];
    };

    // Matricula => ultimo dia dela no CGV ('' = ativa). A mesma matricula pode ter mais de uma linha.
    $doPeriodo = array_filter($ferias, fn($f) => $f['ferias_fim'] >= $dataInicio && $f['cobertura_inicio'] <= $dataFim);
    $matriculas = array_values(array_unique(array_merge(array_column($doPeriodo, 'matricula'), array_filter(array_column($doPeriodo, 'matricula_vol')))));
    $ultimoDiaColab = [];
    if ($matriculas) {
        $stmtColab = $pdo->prepare('SELECT matricula, desligamento FROM colaboradores WHERE matricula IN (' . implode(',', array_fill(0, count($matriculas), '?')) . ')');
        $stmtColab->execute($matriculas);
        foreach ($stmtColab->fetchAll() as $c) {
            $saida = DateTime::createFromFormat('!d/m/Y', (string) $c['desligamento']);
            $saida = $saida ? $saida->format('Y-m-d') : '';
            $atual = $ultimoDiaColab[$c['matricula']] ?? null;
            $ultimoDiaColab[$c['matricula']] = ($atual === '' || $saida === '') ? '' : max((string) $atual, $saida);
        }
    }

    $naoEncontrados = [];
    $titularesSemCadastro = 0;
    $linhas = [];
    foreach ($doPeriodo as $f) {
        if (($f['precisa_cobertura'] ?? 'Sim') !== 'Sim') continue; // "Nao precisa": nada a pagar nem a avisar
        if (!$f['matricula_vol']) {
            $nome = trim((string) ($f['volante'] ?? ''));
            // Transferencia fora do cadastro do CGV e esperado (a pessoa ainda nao e volante): fica de fora sem aviso. Idem o
            // emprestado e o substituto do posto de origem (qualquer colaborador).
            if (!in_array($nome, ['', 'Vaga em aberto', 'Cobertura interna'], true) && !ehTransferenciaSaf($f) && !ehEmprestimoSaf($f)
                && empty($f['posto_origem'])) $naoEncontrados[$nome] = true;
            continue;
        }
        if (!isset($ultimoDiaColab[$f['matricula']])) {
            $titularesSemCadastro++;
            continue;
        }
        $dia = new DateTime(max($f['cobertura_inicio'], $dataInicio));
        $ultimoDia = new DateTime(min(array_filter([$f['ferias_fim'], $dataFim, $ultimoDiaColab[$f['matricula_vol']] ?? ''])));
        for (; $dia <= $ultimoDia; $dia->modify('+1 day')) {
            $data = $dia->format('d/m/Y');
            // + a matricula do SAF, quando vier: readmitido tem linhas do CGV na matricula antiga
            $matriculaSaf = trim((string) ($f['volante_matricula'] ?? ''));
            $titular = (string) $f['matricula'];
            if ($ocupado($f['matricula_vol'], $titular, $data) || ($matriculaSaf !== '' && $ocupado($matriculaSaf, $titular, $data))) continue;
            $linhas[] = ['titular_mat' => $titular, 'vol_mat' => $f['matricula_vol'], 'data' => $data,
                'emprestado_para' => !empty($f['posto_origem']) ? (string) ($f['ferias_titular'] ?? '') : null,
                'centro_custo' => trim((string) ($f['centro_custo'] ?? ''))]; // contrato do titular (filtro do Dashboard)
            $diaOcupado[$f['matricula_vol'] . '|' . $data][''] = true; // duas ferias do SAF no mesmo dia (matricula antiga e nova) pagam uma vez
        }
    }
    if ($naoEncontrados) {
        $aviso = trim(($aviso ?? '') . ' Volante do SAF sem cadastro no CGV, dias fora do cálculo: ' . implode(', ', array_keys($naoEncontrados)) . '.');
    }
    if ($titularesSemCadastro) {
        $aviso = trim(($aviso ?? '') . " $titularesSemCadastro férias com titular sem cadastro no CGV ficaram fora do cálculo.");
    }
    return $linhas;
}

// Linhas do CGV sem os dias do titular que a ferias do SAF ja paga (diasCoberturaFeriasSaf): a ferias tem prioridade.
// $fora: volante => dias de linha designada que ficaram de fora (a linha continua na tela de Solicitacoes; aviso em
// avisoForaPorFeriasSaf). Volante que e o mesmo que a ferias paga nao conta: ele recebe o dia pelo SAF.
function semDiasDeFeriasSaf(array $linhasCgv, array $diasFerias, ?array &$fora = null): array {
    $dias = []; // titular|data => volante que a ferias paga
    foreach ($diasFerias as $d) $dias[$d['titular_mat'] . '|' . $d['data']] = $d['vol_mat'];
    $resto = [];
    $fora = [];
    foreach ($linhasCgv as $l) {
        $pagoPelaFerias = $dias[$l['titular_mat'] . '|' . $l['data']] ?? null;
        if ($pagoPelaFerias === null) $resto[] = $l;
        elseif (!in_array($l['vol_mat'], ['SEM_COBERTURA', $pagoPelaFerias], true)) $fora[$l['vol_mat']] = ($fora[$l['vol_mat']] ?? 0) + 1;
    }
    return $resto;
}

// Acrescenta a $aviso quem ficou de fora em semDiasDeFeriasSaf.
function avisoForaPorFeriasSaf(?string $aviso, array $fora): ?string {
    if (!$fora) return $aviso;
    return trim(($aviso ?? '') . ' Cobertura do CGV em dia de férias do titular ficou fora do cálculo (a férias do SAF tem prioridade): '
        . implode(', ', array_map(fn($m, $n) => "$m ($n dia" . ($n > 1 ? 's' : '') . ')', array_keys($fora), $fora)) . '.');
}

// Motivo pra nao designar o colaborador nesses dias (dd/mm/aaaa): esta de ferias ou cobrindo ferias no SAF. null = livre.
function conflitoFeriasSaf(PDO $pdo, string $matricula, array $datas): ?string {
    $dias = array_map(fn($d) => implode('-', array_reverse(explode('/', $d))), $datas);
    foreach (feriasSafComVolante($pdo, $aviso) as $f) {
        // O emprestado nao esta de ferias; o trecho de quem cobriu antes e um pedaco da mesma ferias (a linha dela ja confere).
        $propria = (string) $f['matricula'] === $matricula && empty($f['posto_origem']) && empty($f['trecho_anterior']);
        if (!$propria && $f['matricula_vol'] !== $matricula) continue;
        $inicio = $propria ? $f['ferias_inicio'] : $f['cobertura_inicio'];
        foreach ($dias as $d) {
            if ($d < $inicio || $d > $f['ferias_fim']) continue;
            $periodo = date('d/m/Y', strtotime($inicio)) . ' a ' . date('d/m/Y', strtotime($f['ferias_fim']));
            return $propria ? "Esse colaborador esta de ferias de $periodo." : (!empty($f['posto_origem'])
                ? "Esse colaborador esta cobrindo o posto de {$f['nome']} (emprestado para as ferias de {$f['ferias_titular']}) de $periodo."
                : "Esse colaborador esta cobrindo as ferias de {$f['nome']} de $periodo.");
        }
    }
    return null;
}

// Ferias do SAF do proprio titular que pegam esse dia (dd/mm/aaaa): "dd/mm/aaaa a dd/mm/aaaa", ou null. Nao se abre solicitacao em dia
// de ferias (decisao do usuario, 2026-11-12): a ferias ja tem a cobertura dela no SAF e o PFM da prioridade a ela (semDiasDeFeriasSaf).
// Sem o arquivo do SAF (lerCoberturaSaf devolve []) nao ha como conferir e passa. Le o arquivo uma vez por requisicao.
function feriasSafDoTitularNoDia(string $matricula, string $dataBr): ?string {
    static $ferias = null;
    $ferias ??= lerCoberturaSaf($aviso);
    $dia = implode('-', array_reverse(explode('/', trim($dataBr))));
    foreach ($ferias as $f) {
        if (!is_array($f) || (string) ($f['matricula'] ?? '') !== $matricula) continue;
        [$inicio, $fim] = [(string) ($f['ferias_inicio'] ?? ''), (string) ($f['ferias_fim'] ?? '')];
        if ($inicio !== '' && $inicio <= $dia && $dia <= $fim) return date('d/m/Y', strtotime($inicio)) . ' a ' . date('d/m/Y', strtotime($fim));
    }
    return null;
}

// Matricula no CGV do volante de cada ferias do SAF (mesmas chaves de $ferias; null = nao encontrado).
// Vale a MATRICULA que o SAF manda (volante_matricula), se estiver ativa no CGV. Desligada: troca so pela ativa
// de mesmo nome e mesmo CPF (readmitido/transferido); nome igual sozinho pode ser outra pessoa. Vazia (solicitacao
// antiga de homonimo) ou sem cadastro cai no nome normalizado (maiusculo/sem espaco extra), preferindo o ativo.
// Transferencia e emprestimo (ehTransferenciaSaf/ehEmprestimoSaf) valem so pela matricula do SAF (desligada: a ativa de mesmo CPF, ou ela
// mesma, e o PFM paga ate a saida como ao volante comum); fora do cadastro = null: o nome pegaria um homonimo.
function matriculasVolanteSaf(PDO $pdo, array $ferias): array {
    if (count($ferias) === 0) return [];

    $desligado = function ($data): bool {
        $dia = DateTime::createFromFormat('!d/m/Y', (string) $data);
        return $dia !== false && $dia <= new DateTime('today');
    };

    $nomesVolante = [];
    foreach ($ferias as $f) {
        $nomesVolante[strtoupper(trim((string) ($f['volante'] ?? '')))] = true;
    }
    $nomesVolante = array_keys($nomesVolante);
    $marcadores = implode(',', array_fill(0, count($nomesVolante), '?'));
    $stmtNomes = $pdo->prepare(
        "SELECT matricula, UPPER(TRIM(nome)) AS nome_norm, desligamento, cpf FROM colaboradores
          WHERE UPPER(TRIM(nome)) IN ($marcadores)"
    );
    $stmtNomes->execute($nomesVolante);
    $porNome = [];
    foreach ($stmtNomes->fetchAll() as $c) {
        $porNome[$c['nome_norm']][] = ['matricula' => $c['matricula'], 'saiu' => $desligado($c['desligamento']), 'cpf' => trim((string) $c['cpf'])];
    }

    $matsVolante = array_values(array_unique(array_filter(array_map(fn($f) => trim((string) ($f['volante_matricula'] ?? '')), $ferias))));
    $ativa = []; // matricula => tem linha ativa (o sync marca desligamento e nao apaga a linha antiga)
    $cpfDe = [];
    if ($matsVolante) {
        $stmtMats = $pdo->prepare('SELECT matricula, desligamento, cpf FROM colaboradores WHERE matricula IN (' . implode(',', array_fill(0, count($matsVolante), '?')) . ')');
        $stmtMats->execute($matsVolante);
        foreach ($stmtMats->fetchAll() as $c) {
            $ativa[$c['matricula']] = ($ativa[$c['matricula']] ?? false) || !$desligado($c['desligamento']);
            if (trim((string) $c['cpf']) !== '') $cpfDe[$c['matricula']] = trim((string) $c['cpf']); // linha antiga sem CPF nao apaga o da outra
        }
    }

    return array_map(function ($f) use ($ativa, $cpfDe, $porNome) {
        $matSaf = trim((string) ($f['volante_matricula'] ?? ''));
        $mesmoNome = $porNome[strtoupper(trim((string) ($f['volante'] ?? '')))] ?? [];
        $ativos = array_values(array_filter($mesmoNome, fn($c) => !$c['saiu']));
        if ($ativa[$matSaf] ?? false) return $matSaf;
        if (isset($ativa[$matSaf])) {
            $cpf = $cpfDe[$matSaf] ?? '';
            foreach ($ativos as $c) if ($cpf !== '' && $c['cpf'] === $cpf) return $c['matricula'];
            return $matSaf;
        }
        if (ehTransferenciaSaf($f) || ehEmprestimoSaf($f)) return null; // so a matricula do SAF: a pessoa pode nao estar no cadastro e o nome pegaria um homonimo
        return ($ativos[0] ?? $mesmoNome[0] ?? [])['matricula'] ?? null; // nome duplicado - fica com o 1o ativo
    }, $ferias);
}

// Sessao = janela curta renovada por um "pulso" (login.php?acao=heartbeat, a cada 20s via
// filtro.js) enquanto uma aba estiver aberta. Fechar a aba para os pulsos e a sessao expira sozinha
// (o navegador nao diferencia fechar de navegar entre paginas). 90s sobrevive a troca de pagina e a
// ate 4 pulsos perdidos.
const SESSAO_DURACAO_SEGUNDOS = 90;

// Confere por requisicao se a coluna existe (o codigo funciona antes e depois de
// ferramentas/migrar-banco.php). Compartilhada por dados.php e login.php.
function colunaExiste(PDO $pdo, string $tabela, string $coluna): bool {
    static $cache = [];
    $chave = "$tabela.$coluna";
    if (isset($cache[$chave])) return $cache[$chave];

    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$tabela, $coluna]);
        return $cache[$chave] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$chave] = false;
    }
}

// Limite de tentativas de login (forca bruta): 5 erradas numa janela de 15min bloqueiam por mais
// 15min.
const LOGIN_MAX_TENTATIVAS = 5;
const LOGIN_JANELA_MINUTOS = 15;
const LOGIN_BLOQUEIO_MINUTOS = 15;

// Minutos que faltam pro usuario tentar de novo (0 = liberado). Tabela inexistente (migracao nao
// rodou) = nunca bloqueado.
function minutosBloqueioLogin($pdo, $usuarioNormalizado) {
    if (!tabelaExiste($pdo, 'login_tentativas')) return 0;
    $stmt = $pdo->prepare('SELECT bloqueado_ate FROM login_tentativas WHERE usuario = ?');
    $stmt->execute([$usuarioNormalizado]);
    $linha = $stmt->fetch();
    if (!$linha || !$linha['bloqueado_ate']) return 0;
    $restante = strtotime($linha['bloqueado_ate']) - time();
    return $restante > 0 ? (int) ceil($restante / 60) : 0;
}

// Certa: apaga a linha (reseta o contador). Errada: incrementa; se a ultima tentativa foi ha mais
// de LOGIN_JANELA_MINUTOS, recomeca do zero (janela deslizante). So chamada com o usuario NAO
// bloqueado (login.php confere antes), senao o bloqueio se renovaria sem fim.
function registrarTentativaLogin($pdo, $usuarioNormalizado, $sucesso) {
    if (!tabelaExiste($pdo, 'login_tentativas')) return;

    if ($sucesso) {
        $pdo->prepare('DELETE FROM login_tentativas WHERE usuario = ?')->execute([$usuarioNormalizado]);
        return;
    }

    $stmt = $pdo->prepare('SELECT tentativas, ultima_tentativa FROM login_tentativas WHERE usuario = ?');
    $stmt->execute([$usuarioNormalizado]);
    $linha = $stmt->fetch();

    $dentroDaJanela = $linha && (time() - strtotime($linha['ultima_tentativa'])) <= LOGIN_JANELA_MINUTOS * 60;
    $tentativas = ($dentroDaJanela ? (int) $linha['tentativas'] : 0) + 1;
    $bloqueadoAte = $tentativas >= LOGIN_MAX_TENTATIVAS
        ? date('Y-m-d H:i:s', time() + LOGIN_BLOQUEIO_MINUTOS * 60)
        : null;

    $pdo->prepare('
        INSERT INTO login_tentativas (usuario, tentativas, ultima_tentativa, bloqueado_ate)
        VALUES (?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE tentativas = VALUES(tentativas), ultima_tentativa = NOW(), bloqueado_ate = VALUES(bloqueado_ate)
    ')->execute([$usuarioNormalizado, $tentativas, $bloqueadoAte]);
}

// Registro de quando cada sincronizacao externa (Protheus/USC) terminou com sucesso ou falhou de
// verdade (erro fatal, nao os erros pontuais por matricula); lido por
// scripts/verificar-saude-sync.php pra avisar se alguma ficar mais de 26h sem sucesso. Tolera
// tabela inexistente.
function registrarSucessoSyncStatus($pdo, string $chave): void {
    if (!tabelaExiste($pdo, 'sync_status')) return;
    $pdo->prepare(
        'INSERT INTO sync_status (chave, executado_em) VALUES (?, NOW())
         ON DUPLICATE KEY UPDATE executado_em = NOW()'
    )->execute([$chave]);
}

// Rotinas da cadeia noturna (docker-compose.yml, container "sync") que gravam em sync_status,
// chave => rotulo. As 2 do Protheus so contam com PROTHEUS_DB_HOST, a de usuarios so com
// USC_API_URL; as outras 3 sao locais. O backup do banco e servico proprio e nao entra. Usado
// pelo aviso do login (saude-sync.php) e por scripts/status-rotina.php.
function rotinasNoturnasConfiguradas(): array {
    $rotinas = [];
    if (!empty(PROTHEUS_DB_HOST)) {
        $rotinas['colaboradores'] = 'Sincronização de colaboradores volantes (Protheus)';
        $rotinas['centros_custo'] = 'Sincronização de centros de custo (Protheus)';
    }
    if (!empty(USC_API_URL)) {
        $rotinas['usuarios'] = 'Sincronização de usuários (directory USC)';
    }
    $rotinas['estender_desligamento'] = 'Extensão diária de vagas de desligamento em aberto';
    $rotinas['estender_afastamento'] = 'Extensão diária de vagas de afastamento em aberto';
    $rotinas['fechar_vencidas'] = 'Fechamento automático de solicitações vencidas';
    if ((string) SAF_COBERTURA_JSON !== '') $rotinas['cortar_ferias'] = 'Corte de cobertura por férias do SAF';
    if ((string) SAF_COBERTURA_JSON !== '') $rotinas['ociosidade'] = 'Foto diária dos Volantes Ociosos (Histórico de observação)';
    return $rotinas;
}

// $limite: erro comum e cortado em 500 bytes; so o aviso de conferencia do retrato (lista de
// casos, ASCII) pede mais (coluna TEXT).
function registrarErroSyncStatus($pdo, string $chave, string $mensagem, int $limite = 500): void {
    if (!tabelaExiste($pdo, 'sync_status')) return;
    $pdo->prepare(
        'INSERT INTO sync_status (chave, ultimo_erro_em, mensagem_erro) VALUES (?, NOW(), ?)
         ON DUPLICATE KEY UPDATE ultimo_erro_em = NOW(), mensagem_erro = VALUES(mensagem_erro)'
    )->execute([$chave, substr($mensagem, 0, $limite)]);
}

function gerarTokenSessao() {
    return bin2hex(random_bytes(32));
}

// So o HASH do token fica no banco: se o banco vazar, o valor la nao serve pra se passar por
// um usuario logado.
function hashTokenSessao($token) {
    return hash('sha256', $token);
}

// Cria uma sessao nova substituindo qualquer anterior (1 sessao ativa por usuario). Devolve o
// token ORIGINAL (so esse vai pro cliente; o banco guarda o hash).
function criarSessao($pdo, $usuarioId) {
    $pdo->prepare('DELETE FROM sessoes WHERE usuario_id = ?')->execute([$usuarioId]);

    $token = gerarTokenSessao();
    $expiraEm = date('Y-m-d H:i:s', time() + SESSAO_DURACAO_SEGUNDOS);
    $pdo->prepare('INSERT INTO sessoes (token_hash, usuario_id, expira_em) VALUES (?, ?, ?)')
        ->execute([hashTokenSessao($token), $usuarioId, $expiraEm]);

    return $token;
}

// Confere o cabecalho X-Session-Token (hasheando pra comparar com o banco).
// Encerra a requisicao com 401 se estiver ausente, invalido ou expirado. Se
// valido, renova a expiracao (sliding window) e devolve o id do usuario.
function verificarSessao($pdo) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $token = '';
    foreach ($headers as $nome => $valor) {
        if (strtolower($nome) === 'x-session-token') {
            $token = $valor;
            break;
        }
    }

    $falhar = function () {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Sessao ausente, invalida ou expirada']);
        exit;
    };

    if ($token === '') $falhar();
    $hash = hashTokenSessao($token);

    $stmt = $pdo->prepare('SELECT usuario_id, expira_em FROM sessoes WHERE token_hash = ?');
    $stmt->execute([$hash]);
    $linha = $stmt->fetch();

    if (!$linha || strtotime($linha['expira_em']) < time()) $falhar();

    $novaExpiracao = date('Y-m-d H:i:s', time() + SESSAO_DURACAO_SEGUNDOS);
    $pdo->prepare('UPDATE sessoes SET expira_em = ? WHERE token_hash = ?')->execute([$novaExpiracao, $hash]);

    return (int) $linha['usuario_id'];
}

// Apaga a sessao explicitamente (botao de sair chamando o backend). Nao
// falha se o token ja nao existir mais.
function encerrarSessao($pdo, $token) {
    if ($token === '') return;
    $pdo->prepare('DELETE FROM sessoes WHERE token_hash = ?')->execute([hashTokenSessao($token)]);
}

// ============================================================
// PAPEIS DE USUARIO
// ============================================================
// Papeis: RH, Gestor, Preposto e Administrador (usuarios.papel). normalizarPapel traduz valores
// do modelo antigo (admin/operador/leitura) que ainda estejam na coluna e e compartilhada com
// login.php (acao=listar); papelDoUsuario tolera a coluna "papel" inexistente (o codigo funciona
// antes e depois de ferramentas/migrar-banco.php).
function normalizarPapel(?string $papel): ?string {
    static $mapa = ['admin' => 'rh', 'operador' => 'gestor', 'leitura' => 'gestor'];
    return ($papel !== null && isset($mapa[$papel])) ? $mapa[$papel] : $papel;
}

function papelDoUsuario($pdo, $usuarioId) {
    static $cache = [];
    if (array_key_exists($usuarioId, $cache)) return $cache[$usuarioId];

    $linha = null;
    $papel = null;

    try {
        $stmt = $pdo->prepare('SELECT usuario, papel FROM usuarios WHERE id = ?');
        $stmt->execute([$usuarioId]);
        $linha = $stmt->fetch();
        $papel = normalizarPapel($linha['papel'] ?? null);
    } catch (PDOException $e) {
        // Coluna "papel" ainda nao existe - segue no modo antigo.
        $stmt = $pdo->prepare('SELECT usuario FROM usuarios WHERE id = ?');
        $stmt->execute([$usuarioId]);
        $linha = $stmt->fetch();
    }

    if (!$linha) return $cache[$usuarioId] = null;

    if (!$papel) {
        $papel = strtolower($linha['usuario']) === 'admin' ? 'rh' : 'gestor';
    }

    // Perfil multiplo (usuario_papeis_extra, ver alternarPerfilAtivo): um papel_ativo da sessao
    // diferente do principal manda (botao "alternar perfil", filtro.js). NULL cai no papel principal.
    if (colunaExiste($pdo, 'sessoes', 'papel_ativo')) {
        $stmt = $pdo->prepare('SELECT papel_ativo FROM sessoes WHERE usuario_id = ?');
        $stmt->execute([$usuarioId]);
        $papelAtivo = $stmt->fetchColumn();
        // So um papel que a pessoa ainda tem: quem perdeu o RH com a sessao aberta volta pro principal.
        if ($papelAtivo && ($papelAtivo === $papel || in_array($papelAtivo, papeisExtraDoUsuario($pdo, $usuarioId), true))) $papel = $papelAtivo;
    }

    return $cache[$usuarioId] = $papel;
}

// Papeis ALEM do principal que a pessoa tem (usuario_papeis_extra, ate 2); vazio se so tem o
// principal ou a tabela nao existe. Usado pelo frontend (opcao de alternar) e por
// alternarPerfilAtivo (valida o papel).
function papeisExtraDoUsuario($pdo, $usuarioId): array {
    if (!tabelaExiste($pdo, 'usuario_papeis_extra')) return [];
    $stmt = $pdo->prepare('SELECT papel FROM usuario_papeis_extra WHERE usuario_id = ? ORDER BY papel');
    $stmt->execute([$usuarioId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Conjunto de papeis do cadastro (principal + extras), pra regra de "Todos os contratos" travado quando ha RH
// (usuarios.html, calcularEstadoCamposEscopo): centrosCustoLiberados e coberturaGestorLiberada.
function papeisDoUsuario($pdo, $usuarioId): array {
    static $cache = [];
    if (isset($cache[$usuarioId])) return $cache[$usuarioId];
    $stmt = $pdo->prepare('SELECT papel FROM usuarios WHERE id = ?');
    $stmt->execute([$usuarioId]);
    return $cache[$usuarioId] = array_values(array_filter(array_merge([normalizarPapel($stmt->fetchColumn() ?: '')], papeisExtraDoUsuario($pdo, $usuarioId))));
}

// Troca o papel ativo NESTA sessao (nao mexe no cadastro); so aceita o principal ou um extra
// dele. Devolve o papel realmente aplicado.
function alternarPerfilAtivo($pdo, $usuarioId, $papelDesejado): string {
    $papeisExtra = papeisExtraDoUsuario($pdo, $usuarioId);

    // papelDoUsuario() pode devolver o papel_ativo da sessao; o principal de cadastro e sempre
    // usuarios.papel, lido direto.
    $stmt = $pdo->prepare('SELECT papel FROM usuarios WHERE id = ?');
    $stmt->execute([$usuarioId]);
    $papelCadastro = normalizarPapel($stmt->fetchColumn() ?: null);

    $permitidos = array_values(array_filter(array_merge([$papelCadastro], $papeisExtra)));
    if (!in_array($papelDesejado, $permitidos, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Voce nao tem esse perfil.']);
        exit;
    }

    if (colunaExiste($pdo, 'sessoes', 'papel_ativo')) {
        $pdo->prepare('UPDATE sessoes SET papel_ativo = ? WHERE usuario_id = ?')
            ->execute([$papelDesejado, $usuarioId]);
    }

    return $papelDesejado;
}

// Confere se uma tabela existe (como colunaExiste: tolera rodar antes da migracao).
function tabelaExiste(PDO $pdo, string $tabela): bool {
    static $cache = [];
    if (isset($cache[$tabela])) return $cache[$tabela];

    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$tabela]);
        return $cache[$tabela] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$tabela] = false;
    }
}

// Centros de custo liberados a um usuario Gestor ou Preposto (mesmo escopo, muda so quais tabelas
// cada um acessa - ver dados.php). null = tabela ainda nao existe (migracao nao rodou) -
// IRRESTRITO de proposito, pra nao trancar todo mundo na janela entre publicar e migrar. Array
// (mesmo vazio) = fonte de verdade; vazio = RH ainda nao liberou nenhum centro.
// $papel: qual das 2 listas ler (usuario_centros_custo.papel, ver schema.sql): quem e Gestor E
// Preposto tem uma linha por papel. Preposto pede 'preposto' (seu time); Gestor pede 'gestor'
// (solicitacoes que atende).
function centrosCustoLiberados($pdo, $usuarioId, string $papel): ?array {
    static $cache = [];
    $chave = $usuarioId . '|' . $papel;
    if (array_key_exists($chave, $cache)) return $cache[$chave];

    if (!tabelaExiste($pdo, 'usuario_centros_custo')) {
        return $cache[$chave] = null;
    }
    // RH + Gestor + Preposto: a tela de Usuarios trava o Centro de Custo em "Todos os contratos" (e nao grava a lista
    // do Gestor); vale o que a tela mostra.
    $papeis = papeisDoUsuario($pdo, $usuarioId);
    if (!array_diff(['rh', 'gestor', 'preposto'], $papeis)) {
        return $cache[$chave] = null;
    }

    $stmt = $pdo->prepare('SELECT centro_custo FROM usuario_centros_custo WHERE usuario_id = ? AND papel = ?');
    $stmt->execute([$usuarioId, $papel]);
    $centros = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // "TODOS" (usuarios.html, "Todos os contratos") e sentinela, nao um centro real: trata igual a
    // null (sem filtro, inclusive centros criados depois).
    if (in_array('TODOS', $centros, true)) {
        return $cache[$chave] = null;
    }

    return $cache[$chave] = $centros;
}

// Irma de centrosCustoLiberados() pro OUTRO escopo do Gestor: o pool de volante/intermitente que
// ele administra ("Cobertura (quem cobre)", gestor_centro_cobertura - ver schema.sql). Define
// quais COLABORADORES aparecem, nao quais solicitacoes. Retorno: null = irrestrito ("Todos os
// contratos" ou tabela inexistente), array (mesmo vazio) = lista real.
function coberturaGestorLiberada($pdo, $usuarioId): ?array {
    static $cache = [];
    if (array_key_exists($usuarioId, $cache)) return $cache[$usuarioId];

    if (!tabelaExiste($pdo, 'gestor_centro_cobertura')) {
        return $cache[$usuarioId] = null;
    }
    // Quem tambem e RH: a tela de Usuarios trava a Cobertura em "Todos os contratos" (RH ja e irrestrito) e, com
    // Preposto junto, nem grava a lista do Gestor; vale o que a tela mostra.
    if (in_array('rh', papeisDoUsuario($pdo, $usuarioId), true)) {
        return $cache[$usuarioId] = null;
    }

    $stmt = $pdo->prepare('SELECT centro_custo_cobre FROM gestor_centro_cobertura WHERE usuario_id = ?');
    $stmt->execute([$usuarioId]);
    $centros = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('TODOS', $centros, true)) {
        return $cache[$usuarioId] = null;
    }

    return $cache[$usuarioId] = $centros;
}

// Colaborador incluido pelo "Adicionar colaborador" (origem MANUAL ou com motivo da inclusao, ver dados.php acao=salvar).
const SQL_COLABORADOR_INCLUIDO = "(origem = 'MANUAL' OR COALESCE(motivo_inclusao, '') <> '')";

// WHERE de "colaborador do grupo de volantes" (Cobertura do Gestor): pelo centro de custo ou, se incluido, pelo centro volante de
// destino - o Gestor desse destino ve e aloca a pessoa. $n centros; os parametros vao 2 vezes (array_merge($pool, $pool)).
function sqlColaboradorNoPool(int $n): string {
    $marcadores = implode(',', array_fill(0, $n, '?'));
    return "(centro_custo IN ($marcadores) OR (" . SQL_COLABORADOR_INCLUIDO . " AND centro_destino IN ($marcadores)))";
}

// Desligamento do colaborador (colaboradores.desligamento, DD/MM/AAAA: o do Protheus ou o previsto da aba Situacao), ou null. Base
// e cobertura nao caem depois dele - o desligado continua no banco (o PFM precisa dos dias que ele cobriu), mas nao e mais alocado.
function desligamentoDoColaborador($pdo, string $matricula): ?DateTime {
    $stmt = $pdo->prepare("SELECT desligamento FROM colaboradores WHERE matricula = ? AND COALESCE(desligamento, '') <> '' LIMIT 1");
    $stmt->execute([$matricula]);
    return DateTime::createFromFormat('!d/m/Y', (string) $stmt->fetchColumn()) ?: null;
}

// Primeiro dia do periodo do PFM mais antigo que ainda aceita ajuste: o PFM do mes M vai de 20/(M-1) a 19/M e aceita ajuste ate
// o dia 3 de M+1 (decisao do usuario, 2026-11-05) - do dia 4 em diante, dia 20 do mes anterior; do dia 1 ao 3, dia 20 de 2 meses
// atras. $hoje AAAA-MM-DD. Mesma regra no SAF (aplicar-pedidos-cgv.php).
function inicioPeriodoPfmAberto(string $hoje): string {
    $meses = (int) substr($hoje, 8, 2) <= 3 ? 2 : 1;
    return date('Y-m-20', strtotime(substr($hoje, 0, 8) . "01 -$meses month"));
}

// Nova Solicitacao (coberturas/solicitacao.html) so abre de hoje em diante, qualquer perfil (decisao do usuario, 2026-11-05;
// a regra existia no endpoint antigo do Preposto e se perdeu na unificacao das Solicitacoes). Data dd/mm/aaaa antes de hoje
// (fuso de Brasilia, fixado acima); data invalida = false (quem chama ja valida o formato).
function dataBrAntesDeHoje(string $dataBr): bool {
    $dia = DateTime::createFromFormat('!d/m/Y', trim($dataBr));
    return $dia !== false && $dia->format('Y-m-d') < date('Y-m-d');
}

// dd/mm/aaaa -> AAAA-MM-DD (null fora do formato), para comparar com inicioPeriodoPfmAberto e CURDATE().
function dataBrParaIso(string $dataBr): ?string {
    $dia = DateTime::createFromFormat('!d/m/Y', trim($dataBr));
    return $dia ? $dia->format('Y-m-d') : null;
}

// Escala da Nova Solicitacao (pedido do usuario, 2026-11-11): codigo gravado em solicitacoes_cobertura.escala => paridade do
// dia do mes em que a solicitacao pode COMECAR (0 = par, 1 = impar, null = qualquer dia). Rotulos e calendario na tela
// (ESCALAS_NOVA_SOLIC, coberturas/solicitacao.html). Obrigatoria quando o Preposto abre e nunca muda depois.
const ESCALAS_SOLICITACAO = [
    '5x2' => null,
    '12x36_diurno_par' => 0,
    '12x36_diurno_impar' => 1,
    '12x36_noturno_par' => 0,
    '12x36_noturno_impar' => 1,
];

function escalaSolicitacaoValida($escala): bool {
    return is_string($escala) && array_key_exists($escala, ESCALAS_SOLICITACAO);
}

// null = inicio (dd/mm/aaaa) na paridade da escala 12x36 par/impar (ou escala sem paridade); senao a mensagem de erro.
function erroParidadeEscala($escala, string $dataInicioBr): ?string {
    $paridade = escalaSolicitacaoValida($escala) ? ESCALAS_SOLICITACAO[$escala] : null;
    if ($paridade === null || (int) substr(trim($dataInicioBr), 0, 2) % 2 === $paridade) return null;
    return 'Com essa escala, a solicitação só pode começar em dia ' . ($paridade === 1 ? 'ímpar' : 'par') . '.';
}

// "1 linha por dia" e o modelo de solicitacoes_cobertura: um titular nunca deve ter 2
// solicitacoes (qualquer motivo) na mesma data. So barra solicitacao NOVA; duplicatas historicas
// nao sao tocadas. $ignorarId: exclui uma linha (update dela nao conflita consigo mesma).
function existeSolicitacaoNoDia($pdo, string $matricula, string $data, $ignorarId = null): bool {
    $sql = 'SELECT 1 FROM solicitacoes_cobertura WHERE matricula = ? AND data = ?';
    $params = [$matricula, $data];
    if ($ignorarId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $ignorarId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() !== false;
}

// Trava nomeada por titular, a MESMA da abertura de vaga (desligamento/afastamento-confirmacao.php): quem cria linha do mesmo
// titular espera a vez, e o "1 linha por dia" (existeSolicitacaoNoDia) nao passa em 2 abas ao mesmo tempo. Em ordem: 2 lotes nunca
// se travam em cruz. Devolve as chaves travadas (liberarTravas) ou null se alguma nao veio em 5s (solta as que pegou). A conexao
// fechada tambem solta.
function travarTitulares($pdo, array $matriculas): ?array {
    $matriculas = array_values(array_unique(array_map('strval', $matriculas)));
    sort($matriculas, SORT_STRING);
    $travadas = [];
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    foreach ($matriculas as $matricula) {
        $stmt->execute(['vaga_titular_' . $matricula]);
        if ($stmt->fetchColumn() != 1) {
            liberarTravas($pdo, $travadas);
            return null;
        }
        $travadas[] = 'vaga_titular_' . $matricula;
    }
    return $travadas;
}

function liberarTravas($pdo, array $chaves): void {
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    foreach ($chaves as $chave) $stmt->execute([$chave]);
}

// Retrato de salario/periculosidade/insalubridade do TITULAR, congelado quando a linha de
// solicitacoes_cobertura e criada: pfm-calculo.php e dashboard.php (glosa) nao devem usar o valor
// ATUAL ao recalcular um mes antigo. Devolve os 3 campos prontos pra mesclar no $campos de um
// INSERT (chaves = colunas).
function snapshotTitularSolicitacao($pdo, string $matricula): array {
    $stmt = $pdo->prepare('SELECT salario, periculosidade, insalubridade_grau FROM colaboradores WHERE matricula = ? LIMIT 1');
    $stmt->execute([$matricula]);
    $colab = $stmt->fetch();
    if (!$colab) {
        return ['titular_salario' => null, 'titular_periculosidade' => null, 'titular_insalubridade_grau' => null];
    }
    return [
        'titular_salario' => $colab['salario'],
        'titular_periculosidade' => $colab['periculosidade'],
        'titular_insalubridade_grau' => $colab['insalubridade_grau'],
    ];
}

// O mesmo retrato na abertura da vaga de desligamento/afastamento: titular fora do cadastro (desligado buscado ao vivo no Protheus,
// nunca importado; so no Desligamento - a de Afastamento exige titularDoCentro) recebe o do Protheus pela matricula. Sem Protheus fica vazio, e o PFM deixa o dia fora com aviso (pfm-calculo.php).
// A extensao diaria da vaga copia o da ancora (estender-vagas-helpers.php).
function snapshotTitularVaga($pdo, string $matricula): array {
    $retrato = snapshotTitularSolicitacao($pdo, $matricula);
    if ($retrato['titular_periculosidade'] !== null) return $retrato; // esta no cadastro (la a periculosidade e NOT NULL)
    $p = cadastroProtheusPorMatricula($matricula);
    return $p === null ? $retrato
        : ['titular_salario' => $p['salario'], 'titular_periculosidade' => $p['periculosidade'], 'titular_insalubridade_grau' => $p['insalubridade_grau']];
}

// Salario e adicionais de UMA matricula direto no Protheus (protheusColabCadastroPorMatricula), ou null quando o Protheus nao esta
// configurado, nao responde ou nao tem a matricula (vai para o error_log). Usada no "Adicionar colaborador" do Gestor (dados.php) e
// em snapshotTitularVaga.
function cadastroProtheusPorMatricula(string $matricula): ?array {
    require_once __DIR__ . '/../scripts/protheus-colaboradores-helpers.php';
    try {
        $cadastro = protheusColabCadastroPorMatricula(conectarProtheusDb(), $matricula);
    } catch (Throwable $e) {
        error_log("Salario/adicionais da matricula $matricula nao consultados no Protheus (ficam vazios): " . $e->getMessage());
        return null;
    }
    if ($cadastro === null) error_log("Matricula $matricula sem cadastro no Protheus (salario/adicionais ficam vazios).");
    return $cadastro;
}

// Quem abriu a solicitacao ("Aberta por" no painel de coberturas/solicitacao.html): login e papel ATIVO de quem cria a linha,
// gravados no INSERT como retrato (nao mudam se o usuario trocar de papel ou sair). Nunca vem do cliente: dados.php e os endpoints
// da vaga mesclam no INSERT; o cron das vagas copia da ancora (estender-vagas-helpers.php).
function quemAbriuSolicitacao($pdo, $usuarioId, string $papel): array {
    $stmt = $pdo->prepare('SELECT usuario FROM usuarios WHERE id = ?');
    $stmt->execute([$usuarioId]);
    $login = $stmt->fetchColumn();
    return ['criado_por_login' => $login === false ? null : (string) $login, 'criado_por_papel' => $papel];
}

// Id da solicitacao (solicitacoes_cobertura.lote_id, coluna da migracao 3): os dias abertos de uma vez levam o mesmo valor e a tela
// junta por ele (mesmaSolicitacao, coberturas/solicitacao.html; agruparSolicitacoes, dashboard.php). Gerado so no servidor: dados.php
// (um por titular da requisicao) e a ancora da vaga, que o cron copia. Linha antiga/ausencia de ferias do SAF fica NULL.
function novoLoteSolicitacao(): string {
    return date('YmdHis') . '-' . bin2hex(random_bytes(3));
}

// Mesmo retrato, do salario de quem COBRIU (matricula_vol): calculado sempre que matricula_vol
// e definido/trocado (o volante so e conhecido depois). 'SEM_COBERTURA'/vazio = null.
function snapshotVolSalario($pdo, string $matriculaVol): ?float {
    $matriculaVol = trim($matriculaVol);
    if ($matriculaVol === '' || $matriculaVol === 'SEM_COBERTURA') return null;
    $stmt = $pdo->prepare('SELECT salario FROM colaboradores WHERE matricula = ? LIMIT 1');
    $stmt->execute([$matriculaVol]);
    $salario = $stmt->fetchColumn();
    // Sem salario no cadastro (vazio ou 0, ex.: intermitente sem RA_XSLBINT): null, e nao 0,00 - congelado em 0 o PFM pagaria o salario
    // inteiro do titular como diferenca; null usa o cadastro de quando for corrigido (revisao de 2026-11-13).
    return $salario !== false && $salario !== null && (float) $salario > 0 ? (float) $salario : null;
}

// ============================================================
// PRAZO DE CORRECAO DE UMA LINHA JA COBERTA
// ============================================================
// RH/Gestor corrigem quem cobriu ate 15 dias depois do ULTIMO dia do periodo da linha (periodo =
// dias seguidos do mesmo titular com o mesmo motivo, como na aba Abertas de
// coberturas/solicitacao.html - que tambem separa por escala e por solicitacao, mesmaSolicitacao; aqui nao, o prazo so fica maior). Se o fechamento foi DEPOIS do fim do periodo (designacao
// atrasada, fechamento automatico), conta do fechamento - vale o que vier por ultimo. Vaga de
// desligamento/afastamento aberta cresce todo dia e fica corrigivel enquanto aberta.

// id => 'AAAA-MM-DD' do ultimo dia do periodo de cada linha dos titulares pedidos (todas as
// linhas deles, nao so as que o usuario enxerga - o prazo e o mesmo pra todo papel).
function fimDosPeriodos($pdo, array $matriculas): array {
    $matriculas = array_values(array_unique(array_filter(array_map('strval', $matriculas), fn($m) => $m !== '')));
    if (count($matriculas) === 0) return [];

    $marcadores = implode(',', array_fill(0, count($matriculas), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, matricula, motivo, DATE_FORMAT(STR_TO_DATE(data, '%d/%m/%Y'), '%Y-%m-%d') AS dia
           FROM solicitacoes_cobertura
          WHERE matricula IN ($marcadores)
          ORDER BY matricula, motivo, STR_TO_DATE(data, '%d/%m/%Y')"
    );
    $stmt->execute($matriculas);

    $fins = [];
    $grupo = [];
    $chaveAnterior = null;
    $diaAnterior = null;
    foreach ($stmt->fetchAll() as $linha) {
        if ($linha['dia'] === null) continue; // data fora do formato dd/mm/aaaa
        $chave = $linha['matricula'] . '|' . $linha['motivo'];
        // <= dia seguinte: o mesmo dia repetido (duplicata historica) tambem continua o periodo
        $continua = $chave === $chaveAnterior
            && $linha['dia'] <= date('Y-m-d', strtotime($diaAnterior . ' +1 day'));
        if (!$continua) {
            foreach ($grupo as $id) $fins[$id] = $diaAnterior;
            $grupo = [];
        }
        $grupo[] = (int) $linha['id'];
        $chaveAnterior = $chave;
        $diaAnterior = $linha['dia'];
    }
    foreach ($grupo as $id) $fins[$id] = $diaAnterior;

    return $fins;
}

// $hoje vem do MySQL (CURDATE(), conexao em -03:00; o PHP tambem roda em America/Sao_Paulo, ver o inicio deste arquivo).
// $dia (AAAA-MM-DD da linha): o PFM do periodo dele so aceita ajuste ate o dia 3 do mes seguinte (inicioPeriodoPfmAberto) - vale o
// que vencer primeiro, os 15 dias ou o fechamento da folha (decisao do usuario, 2026-11-12).
function dentroDoPrazoDeCorrecao(?string $fimPeriodo, ?string $fechadoEm, string $hoje, ?string $dia = null): bool {
    if ($dia !== null && $dia < inicioPeriodoPfmAberto($hoje)) return false;
    $limite = null;
    foreach ([$fimPeriodo, $fechadoEm ? substr($fechadoEm, 0, 10) : null] as $base) {
        if (!$base) continue;
        $candidato = date('Y-m-d', strtotime($base . ' +15 days'));
        if ($limite === null || $candidato > $limite) $limite = $candidato;
    }
    return $limite !== null && $hoje <= $limite;
}

// ============================================================
// CAIXA DE NOTIFICACAO (tabela notificacoes, ver backend/schema.sql)
// ============================================================
// Destinatarios de um aviso de cobertura: todo RH/Administrador (ve tudo), os Gestores do centro
// do titular e os Gestores cujo grupo de volantes ("Cobertura (quem cobre)",
// gestor_centro_cobertura) inclui o volante - pelo centro dele ou, se incluido pelo "Adicionar colaborador", pelo centro de
// destino. Gestor como perfil extra (usuario_papeis_extra) conta. 'TODOS' = irrestrito, mesmo sentinela de centrosCustoLiberados.
function destinatariosCobertura($pdo, string $centroTitular, string $matriculaVol): array {
    $ehGestor = "(u.papel = 'gestor' OR EXISTS (SELECT 1 FROM usuario_papeis_extra e WHERE e.usuario_id = u.id AND e.papel = 'gestor'))";

    $ids = $pdo->query("SELECT id FROM usuarios WHERE papel IN ('rh', 'administrador')")->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare(
        "SELECT u.id FROM usuarios u
          WHERE $ehGestor
            AND EXISTS (SELECT 1 FROM usuario_centros_custo c
                         WHERE c.usuario_id = u.id AND c.papel = 'gestor' AND c.centro_custo IN (?, 'TODOS'))"
    );
    $stmt->execute([$centroTitular]);
    $ids = array_merge($ids, $stmt->fetchAll(PDO::FETCH_COLUMN), gestoresDoGrupoDoVolante($pdo, $matriculaVol));

    return array_values(array_unique(array_map('intval', $ids)));
}

// Gestores cujo grupo de volantes (gestor_centro_cobertura) inclui essa pessoa - pelo centro dela ou, se incluida pelo "Adicionar
// colaborador", pelo centro de destino. Gestor como perfil extra conta; 'TODOS' = todos os grupos. [] = fora do cadastro.
function gestoresDoGrupoDoVolante($pdo, string $matriculaVol): array {
    // Aviso sem volante (solicitacao urgente, "vence amanha"): sem isto, uma linha de colaborador com matricula vazia mandava o
    // aviso de todos os centros aos Gestores do grupo dela (revisao de 2026-11-13).
    if (trim($matriculaVol) === '') return [];
    $stmtCentroVol = $pdo->prepare('SELECT centro_custo, IF(' . SQL_COLABORADOR_INCLUIDO . ', centro_destino, NULL) AS destino
                                      FROM colaboradores WHERE matricula = ? LIMIT 1');
    $stmtCentroVol->execute([$matriculaVol]);
    $vol = $stmtCentroVol->fetch();
    if (!$vol) return [];
    $stmt = $pdo->prepare(
        "SELECT u.id FROM usuarios u
          WHERE (u.papel = 'gestor' OR EXISTS (SELECT 1 FROM usuario_papeis_extra e WHERE e.usuario_id = u.id AND e.papel = 'gestor'))
            AND EXISTS (SELECT 1 FROM gestor_centro_cobertura g
                         WHERE g.usuario_id = u.id AND g.centro_custo_cobre IN (?, ?, 'TODOS'))"
    );
    $stmt->execute([$vol['centro_custo'], (string) ($vol['destino'] ?? '')]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// Grava 1 aviso por destinatario (menos quem fez a acao). Como
// registrarAuditoria, NUNCA lanca excecao: aviso que falhou vai pro log do
// servidor, a acao de verdade (ja gravada) nao pode voltar com erro por isso.
function criarNotificacoes($pdo, array $usuarioIds, string $tipo, string $mensagem, ?int $referenciaId, $excetoUsuarioId): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO notificacoes (usuario_id, tipo, mensagem, referencia_id, criado_em) VALUES (?, ?, ?, ?, NOW())'
        );
        foreach ($usuarioIds as $id) {
            if ((int) $id === (int) $excetoUsuarioId) continue;
            $stmt->execute([$id, $tipo, mb_substr($mensagem, 0, 500), $referenciaId]);
        }
    } catch (Throwable $e) {
        error_log('Falha ao gravar notificacao (' . $tipo . '): ' . $e->getMessage());
    }
}

// "Nome - Codigo" do centro de custo (mesmo formato das telas) pro texto
// dos avisos - o codigo sozinho nao diz nada pra quem le.
function rotuloCentroCusto($pdo, string $codigo): string {
    $stmt = $pdo->prepare('SELECT descricao FROM centros_custo WHERE codigo = ? LIMIT 1');
    $stmt->execute([$codigo]);
    $descricao = trim((string) $stmt->fetchColumn());
    return $descricao !== '' ? "$descricao - $codigo" : $codigo;
}

// Preposto cancelou uma solicitacao que ja tinha volante: avisa os mesmos destinatarios da
// ausencia. Chamar ANTES do DELETE, com as linhas ainda no banco. Um aviso por titular+volante,
// com os dias dele.
function notificarCancelamentoDeCobertura($pdo, array $ids, $usuarioId): void {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (count($ids) === 0) return;

    try {
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT centro_custo, matricula, nome, data, matricula_vol, nome_vol
               FROM solicitacoes_cobertura
              WHERE id IN ($marcadores) AND matricula_vol IS NOT NULL AND matricula_vol NOT IN ('', 'SEM_COBERTURA')
              ORDER BY STR_TO_DATE(data, '%d/%m/%Y')"
        );
        $stmt->execute($ids);
        $grupos = [];
        foreach ($stmt->fetchAll() as $linha) {
            $chave = $linha['matricula'] . '|' . $linha['matricula_vol'];
            $grupos[$chave] ??= ['linha' => $linha, 'datas' => []];
            $grupos[$chave]['datas'][] = $linha['data'];
        }
        if (count($grupos) === 0) return;

        $stmtUsuario = $pdo->prepare('SELECT usuario FROM usuarios WHERE id = ?');
        $stmtUsuario->execute([$usuarioId]);
        $quem = $stmtUsuario->fetchColumn() ?: 'O Preposto';

        foreach ($grupos as $grupo) {
            $linha = $grupo['linha'];
            $datas = $grupo['datas'];
            $dias = count($datas) === 1 ? "em {$datas[0]}" : "de {$datas[0]} a " . end($datas);
            $mensagem = "CANCELAMENTO: $quem cancelou a solicitação de {$linha['nome']} ("
                . rotuloCentroCusto($pdo, (string) $linha['centro_custo']) . ") $dias, que tinha cobertura de {$linha['nome_vol']}.";
            criarNotificacoes(
                $pdo,
                destinatariosCobertura($pdo, (string) $linha['centro_custo'], (string) $linha['matricula_vol']),
                'cancelamento', $mensagem, null, $usuarioId
            );
        }
    } catch (Throwable $e) {
        error_log('Falha ao avisar cancelamento de cobertura: ' . $e->getMessage());
    }
}

// Rotulo do motivo nos avisos (o mesmo da coluna MOTIVO, ROTULOS_MOTIVO em coberturas/solicitacao.html).
function rotuloMotivoSolicitacao(string $motivo): string {
    $rotulos = ['falta' => 'FALTA', 'atestado' => 'ATESTADO', 'servico_extra' => 'SERVIÇO EXTRA', 'afastamento' => 'AFASTAMENTO',
                'afastamento_indeterminado' => 'AFASTAMENTO', 'desligamento' => 'DESLIGAMENTO', 'ferias' => 'FÉRIAS'];
    return $rotulos[$motivo] ?? mb_strtoupper($motivo);
}

// "em 13/11/2026" ou "de 13/11/2026 a 15/11/2026" (datas dd/mm/aaaa em qualquer ordem).
function textoDiasSolicitacao(array $datas): string {
    usort($datas, fn($a, $b) => strcmp((string) dataBrParaIso($a), (string) dataBrParaIso($b)));
    return count($datas) === 1 ? "em {$datas[0]}" : "de {$datas[0]} a " . end($datas);
}

// Linhas (colunas do banco) por solicitacao: o lote_id quando ha, senao titular+motivo (linha antiga).
function solicitacoesDasLinhas(array $linhas): array {
    $grupos = [];
    foreach ($linhas as $linha) {
        $grupos[!empty($linha['lote_id']) ? 'l|' . $linha['lote_id'] : 'm|' . ($linha['matricula'] ?? '') . '|' . ($linha['motivo'] ?? '')][] = $linha;
    }
    return array_values($grupos);
}

// Log por solicitacao (pedido do usuario, 2026-11-12: achar a falta e o atestado no Log como as vagas): acao
// abrir_/cancelar_solicitacao_<motivo> com titular, centro, dias, escala, observacao e o id da solicitacao (auditoria.lote_id).
// $linhas: os dias de UMA solicitacao (solicitacoesDasLinhas). Nunca lanca excecao (registrarAuditoria).
function registrarAuditoriaSolicitacao($pdo, $usuarioId, string $verbo, array $linhas, string $extra = ''): void {
    if (!$linhas) return;
    $linha = $linhas[0] + ['matricula' => '', 'centro_custo' => '', 'motivo' => ''];
    $datas = array_map('strval', array_column($linhas, 'data'));
    $motivo = substr(preg_replace('/[^a-z_]/', '', strtolower((string) $linha['motivo'])), 0, 30) ?: 'outro';
    // 'afastamento' (atestado de mais de 15 dias) ganha nome proprio: abrir/cancelar_solicitacao_afastamento ja e da VAGA de afastamento
    // (afastamento-confirmacao.php) e o filtro do Log misturaria os dois.
    if ($motivo === 'afastamento') $motivo = 'afastamento_atestado';
    $observacao = trim((string) ($linha['observacao'] ?? ''));
    registrarAuditoria($pdo, $usuarioId, "{$verbo}_solicitacao_$motivo", [
        'tabela' => 'solicitacoes_cobertura',
        'quantidade' => count($linhas),
        'lote_id' => $linha['lote_id'] ?? null,
        'detalhe' => "titular {$linha['matricula']}, centro {$linha['centro_custo']}, dia(s) " . substr(textoDiasSolicitacao($datas ?: ['-']), 3)
            . (count($datas) > 1 ? ' (' . count($datas) . ' dias)' : '')
            . (!empty($linha['escala']) ? ", escala {$linha['escala']}" : '')
            . ($extra !== '' ? ", $extra" : '')
            . ($observacao !== '' ? ', obs: ' . mb_substr($observacao, 0, 200) : ''),
    ]);
}

// Solicitacao que comeca hoje ou amanha (N1 da analise de 2026-11-12): aviso no sino de quem designa (RH/Administrador e Gestores
// do centro, destinatariosCobertura) para o posto nao ficar descoberto ate alguem abrir a lista. As que comecam depois entram no
// aviso "vence amanha" (fechar-solicitacoes-vencidas.php) se ninguem designar - aviso de toda abertura encheria o sino.
function avisarSolicitacaoUrgente($pdo, $usuarioId, array $linhas): void {
    try {
        $amanha = date('Y-m-d', strtotime('+1 day'));
        $inicio = min(array_map(fn($l) => (string) dataBrParaIso((string) ($l['data'] ?? '')), $linhas));
        if ($inicio === '' || $inicio > $amanha) return;
        $linha = $linhas[0] + ['nome' => '', 'centro_custo' => '', 'motivo' => ''];
        $stmt = $pdo->prepare('SELECT usuario FROM usuarios WHERE id = ?');
        $stmt->execute([$usuarioId]);
        $quem = $stmt->fetchColumn() ?: 'O Preposto';
        $mensagem = "NOVA SOLICITAÇÃO: $quem abriu " . rotuloMotivoSolicitacao((string) $linha['motivo']) . " de {$linha['nome']} ("
            . rotuloCentroCusto($pdo, (string) $linha['centro_custo']) . ') ' . textoDiasSolicitacao(array_column($linhas, 'data'))
            . ', ainda sem cobertura.';
        criarNotificacoes($pdo, destinatariosCobertura($pdo, (string) $linha['centro_custo'], ''), 'nova_solicitacao', $mensagem, null, $usuarioId);
    } catch (Throwable $e) {
        error_log('Falha ao avisar solicitacao urgente: ' . $e->getMessage());
    }
}

// FOLGA DO TITULAR (revisao de 2026-11-12, glosa da folga): dia de folga da escala do titular, em que ninguem precisava cobrir. Fica
// gravado como SEM COBERTURA - nao paga ninguem, e conflito, prazo, cancelar e PFM tratam igual - so com este nome, que tira o dia da
// glosa e das contas de dias do Dashboard (dashboard.php). A tela manda o pincel 'FOLGA_TITULAR' (cobertura-solicitacao.php).
const NOME_FOLGA_TITULAR = 'FOLGA DO TITULAR';

// Prepostos do centro (usuario_centros_custo papel preposto, 'TODOS' conta), so quem ainda tem o papel (principal ou extra).
function prepostosDoCentro($pdo, string $centro): array {
    $stmt = $pdo->prepare("SELECT c.usuario_id FROM usuario_centros_custo c JOIN usuarios u ON u.id = c.usuario_id
                            WHERE c.papel = 'preposto' AND c.centro_custo IN (?, 'TODOS')
                              AND (u.papel = 'preposto' OR EXISTS (SELECT 1 FROM usuario_papeis_extra e WHERE e.usuario_id = u.id AND e.papel = 'preposto'))");
    $stmt->execute([$centro]);
    return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

// Titular de solicitacao nova aberta pelo Preposto/Gestor e do centro enviado, no cadastro (revisao de 2026-11-13). Pela API, sem isso,
// abria-se falta com a matricula de qualquer pessoa no proprio centro: VOLANTE AUSENTE falso nos postos que ela cobre, o N2 barrando
// designa-la e o 409 de ferias mostrando as ferias dela. A tela manda o centro do proprio cadastro (Nova Solicitacao).
function titularDoCentro($pdo, string $matricula, string $centro): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM colaboradores WHERE matricula = ? AND centro_custo = ? LIMIT 1');
    $stmt->execute([$matricula, $centro]);
    return (bool) $stmt->fetchColumn();
}

// Dias da vaga depois do Fim escolhido no Definir Fim (desligamento/afastamento-confirmacao.php): o cron estende a vaga ate o dia
// em que o Fim e definido, e esses dias nao existiram. Apaga os que ninguem cobriu nem guardam ausencia - senao venciam como SEM
// COBERTURA e, na de afastamento, contavam como ausencia do titular no PFM e no N2 (revisao de 2026-11-13). Linhas da vaga = do
// titular, com o motivo dela, depois da ancora (so uma vaga aberta por titular). Devolve quantas apagou.
function apagarDiasDaVagaDepoisDoFim($pdo, string $titular, string $motivo, int $ancora, string $fimIso): int {
    $stmt = $pdo->prepare("DELETE FROM solicitacoes_cobertura
                            WHERE matricula = ? AND motivo = ? AND id > ? AND STR_TO_DATE(data, '%d/%m/%Y') > ?
                              AND COALESCE(matricula_vol, '') = '' AND COALESCE(ausencia_matricula_vol, '') = ''");
    $stmt->execute([$titular, $motivo, $ancora, $fimIso]);
    return $stmt->rowCount();
}

// RH/Administrador cancela a solicitacao (dados.php, acao=remover) ou a vaga (desligamento/afastamento-confirmacao.php, acao=cancelar)
// aberta por engano e vista depois das 24h do Preposto (itens 7 e 4 das analises de 2026-11-12). Nao sai se algum dia tiver:
// - volante (o PFM paga quem esta designado: corrigir para SEM COBERTURA antes);
// - ausencia registrada (ausencia-cobertura.php ou falta do volante das ferias do SAF ao designar): a linha e a prova de que ele nao
//   cobriu - apagada, o PFM voltaria a pagar quem faltou. Desfazer = designar de novo quem faltou;
// - dia de periodo do PFM ja fechado (ajuste ate o dia 3, inicioPeriodoPfmAberto).
// $linhas: colunas data, motivo, matricula_vol, nome_vol, ausencia_matricula_vol, ausencia_nome_vol. Devolve [mensagem, status] ou null.
function impedimentoCancelamentoRh(array $linhas, string $hoje): ?array {
    if ($impedimento = impedimentoAusenciaNaLinha($linhas, 'Para desfazer a ausência, designe de novo quem faltou.')) return $impedimento;
    $inicioPfm = inicioPeriodoPfmAberto($hoje);
    foreach ($linhas as $l) {
        $vol = trim((string) ($l['matricula_vol'] ?? ''));
        if ($vol !== '' && $vol !== 'SEM_COBERTURA') {
            return ["Em {$l['data']} essa solicitação tem cobertura de {$l['nome_vol']}: se ninguém trabalhou, corrija esse dia para SEM COBERTURA antes de cancelar (o PFM paga quem está designado).", 409];
        }
        if ((string) dataBrParaIso((string) $l['data']) < $inicioPfm) {
            return ["O dia {$l['data']} é de um período do PFM já fechado (ajustes até o dia 3): não dá mais para cancelar.", 409];
        }
    }
    return null;
}

// Linha com ausencia registrada (ausencia-cobertura.php ou falta do volante das ferias do SAF ao designar) ou de ferias: prova de que
// o volante nao cobriu - apagada, o PFM voltaria a pagar quem faltou. Vale para o RH e tambem para o cancelar do Preposto nas 24h
// (revisao de 2026-11-13). $comoDesfazer completa a mensagem. Devolve [mensagem, status] ou null.
function impedimentoAusenciaNaLinha(array $linhas, string $comoDesfazer): ?array {
    foreach ($linhas as $l) {
        if (($l['motivo'] ?? '') === 'ferias' || trim((string) ($l['ausencia_matricula_vol'] ?? '')) !== '') {
            $quemFaltou = trim((string) ($l['ausencia_nome_vol'] ?? '')) ?: trim((string) ($l['ausencia_matricula_vol'] ?? ''));
            return ["Em {$l['data']} essa linha guarda a ausência de " . ($quemFaltou ?: 'quem cobria') . ' e não é cancelada: '
                . "apagar faria o PFM voltar a pagar quem faltou. $comoDesfazer", 409];
        }
    }
    return null;
}

// RH/Administrador cancelou (impedimentoCancelamentoRh): aviso no sino dos Prepostos do centro, com o motivo - quem abriu ve que sumiu
// e por que. $linhas: os dias de UMA solicitacao ou vaga (nome, centro_custo, motivo, data). Nunca lanca excecao.
function avisarPrepostoCancelamentoRh($pdo, $usuarioId, array $linhas, string $motivoCancelamento): void {
    try {
        if (!$linhas) return;
        $linha = $linhas[0] + ['nome' => '', 'centro_custo' => '', 'motivo' => ''];
        $stmt = $pdo->prepare('SELECT usuario FROM usuarios WHERE id = ?');
        $stmt->execute([$usuarioId]);
        $quem = $stmt->fetchColumn() ?: 'O RH';
        criarNotificacoes($pdo, prepostosDoCentro($pdo, (string) $linha['centro_custo']), 'cancelamento',
            "CANCELADA PELO RH: $quem cancelou " . rotuloMotivoSolicitacao((string) $linha['motivo']) . " de {$linha['nome']} ("
            . rotuloCentroCusto($pdo, (string) $linha['centro_custo']) . ') ' . textoDiasSolicitacao(array_column($linhas, 'data'))
            . ". Motivo: $motivoCancelamento", null, $usuarioId);
    } catch (Throwable $e) {
        error_log('Falha ao avisar o Preposto do cancelamento do RH: ' . $e->getMessage());
    }
}

// Solicitacao nova de quem esta designado como volante nesses dias (N2 ao contrario, revisao de 2026-11-12): a cobertura do outro
// posto continua no nome dele, e o PFM nao paga a ele os dias em que esta ausente como titular (pfm-calculo.php, revisao de 2026-11-13)
// - o posto fica sem pagamento ate alguem trocar. Aviso no sino de quem designa naquele posto (destinatariosCobertura), um por posto,
// e um por ferias do SAF que ele cobre nesses dias (troca pela Previsao). Servico extra nao conta (ele esta trabalhando), nem
// desligamento: a vaga e do POSTO, e quem cumpre aviso como volante segue trabalhando ate a saida, que o designar
// (desligamentoDoColaborador) e o passo 4e do sync ja barram. Vaga de afastamento: do 1o dia em diante. $linhas: os dias de UMA
// solicitacao (matricula, nome, motivo, data). Nunca lanca excecao.
function avisarTitularCobrindoNoDia($pdo, $usuarioId, array $linhas): void {
    try {
        if (!$linhas) return;
        $linha = $linhas[0] + ['matricula' => '', 'nome' => '', 'motivo' => ''];
        $matricula = trim((string) $linha['matricula']);
        if ($matricula === '' || in_array($linha['motivo'], ['servico_extra', 'desligamento'], true)) return;
        $datas = array_values(array_unique(array_map('strval', array_column($linhas, 'data'))));
        if (!$datas) return;
        $isoDatas = array_map(fn($d) => (string) dataBrParaIso($d), $datas);
        $daquiEmDiante = $linha['motivo'] === 'afastamento_indeterminado' ? min($isoDatas) : null; // vaga: do 1o dia em diante
        if ($daquiEmDiante !== null) {
            $stmt = $pdo->prepare("SELECT centro_custo, matricula, nome, data FROM solicitacoes_cobertura
                                    WHERE matricula_vol = ? AND STR_TO_DATE(data, '%d/%m/%Y') >= ?
                                    ORDER BY STR_TO_DATE(data, '%d/%m/%Y')");
            $stmt->execute([$matricula, $daquiEmDiante]);
        } else {
            $marcadores = implode(',', array_fill(0, count($datas), '?'));
            $stmt = $pdo->prepare("SELECT centro_custo, matricula, nome, data FROM solicitacoes_cobertura
                                    WHERE matricula_vol = ? AND data IN ($marcadores)
                                    ORDER BY STR_TO_DATE(data, '%d/%m/%Y')");
            $stmt->execute(array_merge([$matricula], $datas));
        }
        $abriram = 'VOLANTE AUSENTE: abriram ' . rotuloMotivoSolicitacao((string) $linha['motivo']) . " de {$linha['nome']}, que ";
        $porPosto = [];
        foreach ($stmt->fetchAll() as $c) $porPosto[$c['matricula'] . '|' . $c['centro_custo']][] = $c;
        foreach ($porPosto as $cobertas) {
            $c = $cobertas[0];
            criarNotificacoes($pdo, destinatariosCobertura($pdo, (string) $c['centro_custo'], $matricula), 'ausencia',
                $abriram . "está designado para cobrir {$c['nome']} (" . rotuloCentroCusto($pdo, (string) $c['centro_custo']) . ') '
                . textoDiasSolicitacao(array_column($cobertas, 'data')) . '. Troque quem cobre esse posto: o PFM não paga a ele os dias em que está ausente.',
                null, $usuarioId);
        }
        // Ferias do SAF que ele cobre nesses dias (feriasSafComVolante: a ferias, o trecho de quem cobriu antes e o posto de origem de
        // um emprestado). A troca e pela Previsao (pedido ao SAF).
        foreach (feriasSafComVolante($pdo, $avisoSaf) as $f) {
            if (($f['matricula_vol'] ?? null) !== $matricula) continue;
            if ($daquiEmDiante !== null) {
                $de = max($daquiEmDiante, $f['cobertura_inicio']);
                $dias = $de <= $f['ferias_fim'] ? array_unique([$de, $f['ferias_fim']]) : [];
            } else {
                $dias = array_filter($isoDatas, fn($d) => $d >= $f['cobertura_inicio'] && $d <= $f['ferias_fim']);
            }
            if (!$dias) continue;
            $centro = trim((string) ($f['centro_custo'] ?? ''));
            $deQuem = !empty($f['posto_origem']) ? "o posto de {$f['nome']} (emprestado para as férias de {$f['ferias_titular']})" : "as férias de {$f['nome']}";
            criarNotificacoes($pdo, destinatariosCobertura($pdo, $centro, $matricula), 'ausencia',
                $abriram . "cobre $deQuem pelo SAF (" . rotuloCentroCusto($pdo, $centro) . ') '
                . textoDiasSolicitacao(array_map(fn($d) => date('d/m/Y', strtotime($d)), $dias))
                . '. Troque quem cobre na Previsão: o PFM não paga a ele os dias em que está ausente.', null, $usuarioId);
        }
    } catch (Throwable $e) {
        error_log('Falha ao avisar do titular designado como volante: ' . $e->getMessage());
    }
}

// Encerra a requisicao com 403 se o papel do usuario nao estiver na lista.
function exigirPapel($pdo, $usuarioId, array $permitidos) {
    $papel = papelDoUsuario($pdo, $usuarioId);

    if (!in_array($papel, $permitidos, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Seu perfil de acesso (' . ($papel ?? 'indefinido') .
                       ') nao permite esta acao.',
        ]);
        exit;
    }

    return $papel;
}

// Como verificarSessao(), mas so RH/Administrador (gerenciamento de usuarios). Administrador
// tem o mesmo acesso total de RH.
function verificarSessaoAdmin($pdo) {
    $usuarioId = verificarSessao($pdo);
    exigirPapel($pdo, $usuarioId, ['rh', 'administrador']);
    return $usuarioId;
}

// Como verificarSessao(), mas so deixa passar Administrador (nem RH) -
// usado so pela pagina de Logs (backend/api/logs.php), que e a UNICA
// diferenca de acesso entre os dois papeis.
function verificarSessaoAdministrador($pdo) {
    $usuarioId = verificarSessao($pdo);
    exigirPapel($pdo, $usuarioId, ['administrador']);
    return $usuarioId;
}

// ============================================================
// LOG DE AUDITORIA
// ============================================================
// Registra quem fez o que e quando. NUNCA lanca excecao: se a tabela nao existe (migracao nao
// rodada) ou o INSERT falha, o problema vai pro log do servidor e a operacao segue.
function registrarAuditoria($pdo, $usuarioId, $acao, array $extra = []) {
    try {
        $usuario = null;
        try {
            $stmt = $pdo->prepare('SELECT usuario FROM usuarios WHERE id = ?');
            $stmt->execute([$usuarioId]);
            $usuario = $stmt->fetchColumn() ?: null;
        } catch (Throwable $e) { /* nome e opcional no registro */ }

        // Atras de proxy/CDN o IP real vem no X-Forwarded-For (primeiro da lista)
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip) $ip = substr(trim(explode(',', $ip)[0]), 0, 45);

        $stmt = $pdo->prepare(
            'INSERT INTO auditoria
                (em, usuario_id, usuario, acao, tabela, quantidade, lote_id, detalhe, ip)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $usuarioId,
            $usuario,
            $acao,
            $extra['tabela'] ?? null,
            $extra['quantidade'] ?? null,
            $extra['lote_id'] ?? null,
            isset($extra['detalhe']) ? mb_substr((string) $extra['detalhe'], 0, 500) : null,
            $ip,
        ]);
    } catch (Throwable $e) {
        error_log('Auditoria nao registrada (a operacao seguiu): ' . $e->getMessage());
    }
}

// ============================================================
// DIRECTORY USC + LDAP (login.php, backend/scripts/sync-usuarios-usc.php)
// ============================================================
// Consulta a API do directory USC - lista de usuarios corporativos de onde
// a pagina Usuarios escolhe quem adicionar. Devolve null em qualquer falha
// (rede, HTTP != 200, JSON invalido) - quem chama decide o que fazer (nunca
// interpreta null como "lista vazia", que apagaria todo mundo em
// sync-usuarios-usc.php). Aceita tanto {"dados": [...]} quanto um array
// direto de objetos.
function buscarUsuariosDiretorioUSC(): ?array {
    $ch = curl_init(USC_API_URL);
    $headers = ['Accept: application/json'];
    if (!empty(USC_API_TOKEN)) {
        $headers[] = 'Authorization: Bearer ' . USC_API_TOKEN;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resposta = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);
    if ($resposta === false || $status !== 200) {
        error_log("buscarUsuariosDiretorioUSC: falha ao consultar o directory USC (status=$status, curl_error=$erroCurl)");
        return null;
    }
    $decodificado = json_decode($resposta, true);
    $lista = is_array($decodificado) && isset($decodificado['dados']) && is_array($decodificado['dados'])
        ? $decodificado['dados']
        : (is_array($decodificado) ? $decodificado : null);
    if ($lista === null) {
        error_log('buscarUsuariosDiretorioUSC: resposta do directory USC nao e um JSON reconhecivel.');
        return null;
    }
    $usuarios = [];
    foreach ($lista as $item) {
        if (!is_array($item) || !isset($item['usuario']) || trim((string) $item['usuario']) === '') continue;
        $usuarios[] = [
            'usuario' => trim((string) $item['usuario']),
            'nome' => isset($item['nome']) ? trim((string) $item['nome']) : '',
            'email' => isset($item['email']) ? trim((string) $item['email']) : '',
        ];
    }
    return $usuarios;
}

// Base DN derivada do dominio ("empresa.com.br" -> "dc=empresa,dc=com,dc=br")
// quando LDAP_BASE_DN nao for informado.
function dnDoDominio($dominio): ?string {
    $partes = array_filter(explode('.', (string) $dominio), 'strlen');
    if (!$partes) return null;
    return implode(',', array_map(fn($p) => 'dc=' . $p, $partes));
}

// Conta de servico em UPN ("svc-cgv" vira "svc-cgv@dominio") ou DN completo.
function ldapBindDn(): string {
    $conta = (string) LDAP_BIND_DN;
    if (strpos($conta, '=') !== false || strpos($conta, '@') !== false) return $conta;
    return $conta . '@' . LDAP_DOMAIN;
}

// Listar o diretorio exige conta de servico (bind anonimo nao le o AD);
// autenticar um usuario so precisa de LDAP_HOST/LDAP_DOMAIN.
function ldapDiretorioConfigurado(): bool {
    return !empty(LDAP_HOST) && !empty(LDAP_DOMAIN)
        && !empty(LDAP_BIND_DN) && (string) LDAP_BIND_PASSWORD !== '';
}

// Lista do diretorio via LDAP (conta de servico), no mesmo formato de
// buscarUsuariosDiretorioUSC(): [['usuario','nome','email'], ...] ou null em
// qualquer falha (nunca lista vazia por erro).
function buscarUsuariosDiretorioLdap(): ?array {
    if (!ldapDiretorioConfigurado()) return null;
    if (!function_exists('ldap_connect')) {
        error_log('buscarUsuariosDiretorioLdap: extensao php-ldap nao instalada nesta imagem.');
        return null;
    }
    $base = !empty(LDAP_BASE_DN) ? LDAP_BASE_DN : dnDoDominio(LDAP_DOMAIN);
    if (!$base) {
        error_log('buscarUsuariosDiretorioLdap: sem LDAP_BASE_DN e sem LDAP_DOMAIN utilizavel.');
        return null;
    }

    $conexao = @ldap_connect(LDAP_HOST, (int) LDAP_PORT);
    if (!$conexao) {
        error_log('buscarUsuariosDiretorioLdap: nao foi possivel iniciar a conexao (LDAP_HOST invalido?).');
        return null;
    }
    ldap_set_option($conexao, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conexao, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($conexao, LDAP_OPT_NETWORK_TIMEOUT, 15);

    if (!@ldap_bind($conexao, ldapBindDn(), LDAP_BIND_PASSWORD)) {
        error_log('buscarUsuariosDiretorioLdap: bind da conta de servico falhou: ' . ldap_error($conexao));
        @ldap_unbind($conexao);
        return null;
    }

    $usuarios = [];
    $vistos = [];
    $cookie = '';
    $paginas = 0;
    do {
        // Paginado: sem isso o AD corta em ~1000 entradas e devolve o pedaco como se fosse tudo.
        $controles = [['oid' => LDAP_CONTROL_PAGEDRESULTS, 'value' => ['size' => 500, 'cookie' => $cookie]]];
        $resultado = @ldap_search(
            $conexao, $base, LDAP_FILTRO,
            ['uid', 'samaccountname', 'cn', 'displayname', 'mail'],
            0, 0, 0, LDAP_DEREF_NEVER, $controles
        );
        if ($resultado === false) {
            error_log('buscarUsuariosDiretorioLdap: busca falhou (base=' . $base . '): ' . ldap_error($conexao));
            @ldap_unbind($conexao);
            return null;
        }
        $entradas = @ldap_get_entries($conexao, $resultado);
        if ($entradas === false) {
            error_log('buscarUsuariosDiretorioLdap: nao foi possivel ler as entradas: ' . ldap_error($conexao));
            @ldap_unbind($conexao);
            return null;
        }
        for ($i = 0; $i < (int) $entradas['count']; $i++) {
            $entrada = $entradas[$i];
            $login = trim((string) ($entrada['uid'][0] ?? ($entrada['samaccountname'][0] ?? '')));
            if ($login === '' || isset($vistos[strtolower($login)])) continue;
            $vistos[strtolower($login)] = true;
            $usuarios[] = [
                'usuario' => $login,
                'nome' => trim((string) ($entrada['displayname'][0] ?? ($entrada['cn'][0] ?? ''))),
                'email' => trim((string) ($entrada['mail'][0] ?? '')),
            ];
        }
        $cookie = '';
        $resposta = [];
        if (@ldap_parse_result($conexao, $resultado, $codigo, $matched, $mensagem, $referencias, $resposta)) {
            $cookie = $resposta[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'] ?? '';
        }
        $paginas++;
    } while ($cookie !== '' && $cookie !== null && $paginas < 100);

    @ldap_unbind($conexao);
    return $usuarios;
}

// Fonte da lista do diretorio (login.php): API USC quando USC_API_URL estiver
// configurada; senao, LDAP com conta de servico. null = falha ou nao configurado.
function diretorioConfigurado(): bool {
    return !empty(USC_API_URL) || ldapDiretorioConfigurado();
}

function buscarUsuariosDiretorio(): ?array {
    if (!empty(USC_API_URL)) return buscarUsuariosDiretorioUSC();
    if (ldapDiretorioConfigurado()) return buscarUsuariosDiretorioLdap();
    return null;
}

// Autentica via LDAP bind contra o AD - usada em login.php quando
// usuarios.senha_hash === '' (conta do directory USC, sem senha local).
// UPN ("usuario@dominio") - o AD aceita esse formato de bind direto, sem
// precisar montar um DN completo. Nunca guarda a senha, so confere o bind.
function autenticarViaLDAP($usuario, $senha): bool {
    if (empty(LDAP_HOST) || empty(LDAP_DOMAIN) || $senha === '') return false;
    if (!function_exists('ldap_connect')) {
        error_log('autenticarViaLDAP: extensao php-ldap nao instalada nesta imagem.');
        return false;
    }

    // ldap_connect() aceita host simples (usa LDAP_PORT) ou URI completa
    // "ldaps://..." (TLS implicito).
    $conexao = @ldap_connect(LDAP_HOST, (int) LDAP_PORT);
    if (!$conexao) {
        error_log('autenticarViaLDAP: nao foi possivel iniciar a conexao LDAP (LDAP_HOST invalido?).');
        return false;
    }
    ldap_set_option($conexao, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conexao, LDAP_OPT_REFERRALS, 0);
    // Timeout curto - login nao pode ficar preso esperando um dominio fora do ar.
    ldap_set_option($conexao, LDAP_OPT_NETWORK_TIMEOUT, 10);

    $principal = $usuario . '@' . LDAP_DOMAIN;
    $bindOk = @ldap_bind($conexao, $principal, $senha);
    @ldap_unbind($conexao);
    return $bindOk === true;
}
