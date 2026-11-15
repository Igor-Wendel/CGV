<?php
// ============================================================
// ENDPOINT GENERICO DE DADOS (substitui o download/upload de .xlsx)
// Uso: dados.php?acao=listar|salvar|remover&tabela=<nome>
// ============================================================
require_once __DIR__ . '/db.php';

$pdo = conectarBanco();
$usuarioId = verificarSessao($pdo); // exige usuario de verdade logado (token de sessao)
header('Content-Type: application/json');


// Allowlist: tabelas e colunas permitidas (nunca aceitar nome de tabela/coluna do cliente
// sem checar aqui, pra evitar SQL injection via identificador).
$TABELAS = [
    'colaboradores' => [
        'chave' => 'id',
        'colunas' => ['origem', 'filial', 'centro_custo', 'matricula', 'nome', 'funcao', 'funcao_codigo',
                      'data_admissao', 'salario', 'motivo_inclusao', 'centro_destino', 'inicio_aviso', 'fim_aviso',
                      'transferencia', 'experiencia1', 'experiencia2', 'desligamento',
                      'ferias_inicio', 'ferias_fim', 'email', 'contato', 'observacao'],
    ],
    'solicitacoes_cobertura' => [
        'chave' => 'id',
        'colunas' => ['filial', 'centro_custo', 'matricula', 'nome', 'funcao', 'data',
                      'motivo', 'matricula_vol', 'nome_vol', 'observacao', 'escala'],
    ],
    'contratos_notificacao' => [
        'chave' => 'id',
        'colunas' => ['contrato', 'responsavel', 'telefone', 'localizacao'],
    ],
    'vt_valores' => [
        'chave' => 'matricula',
        'colunas' => ['nome', 'valor_vt', 'optante'],
    ],
    'colaborador_capacitacoes' => [
        'chave' => 'id',
        'colunas' => ['matricula', 'data'],
    ],
    'centros_custo' => [
        'chave' => 'codigo',
        'colunas' => ['codigo', 'descricao'],
    ],
    // Somente leitura pelo frontend (coberturas/solicitacao.html); toda escrita passa por
    // desligamento-confirmacao.php. Escopado por centro de custo (ver $TABELAS_COM_ESCOPO_CENTRO_CUSTO).
    'solicitacoes_desligamento' => [
        'chave' => 'solicitacao_cobertura_id',
        'colunas' => ['centro_custo', 'matricula_titular', 'aberta_em', 'fim_definido_em',
                      'substituto_sugerido_matricula', 'substituto_sugerido_nome'],
    ],
    // Tabela-irma de solicitacoes_desligamento, pra vaga de Afastamento (ver afastamento-confirmacao.php).
    'solicitacoes_afastamento' => [
        'chave' => 'solicitacao_cobertura_id',
        'colunas' => ['centro_custo', 'matricula_titular', 'aberta_em', 'fim_definido_em',
                      'substituto_sugerido_matricula', 'substituto_sugerido_nome'],
    ],
    // "usuarios" fica de fora de proposito: login.php cuida dela, pra nunca devolver o hash da senha.
];

function erro($msg, $status = 400) {
    http_response_code($status);
    echo json_encode(['error' => $msg]);
    exit;
}

$tabelaNome = $_GET['tabela'] ?? '';
if (!isset($TABELAS[$tabelaNome])) {
    erro('Tabela nao permitida: ' . $tabelaNome);
}
$tabelaInfo = $TABELAS[$tabelaNome];
$chave = $tabelaInfo['chave'];
$colunasPermitidas = $tabelaInfo['colunas'];

$acao = $_GET['acao'] ?? '';

// Tabelas so de LEITURA neste endpoint generico: escrita so por endpoint dedicado (ex:
// desligamento-confirmacao.php confere janela de datas, idempotencia e grava auditoria). Sem este
// bloqueio, o acesso de leitura do Preposto liberaria acao=salvar/remover/salvar_lote direto,
// pulando essas regras. Bloqueado pra TODO mundo (RH/Gestor inclusos); so acao=listar.
$TABELAS_SOMENTE_LEITURA = ['solicitacoes_desligamento', 'solicitacoes_afastamento'];
if (in_array($tabelaNome, $TABELAS_SOMENTE_LEITURA, true)
    && in_array($acao, ['salvar', 'salvar_lote', 'remover'], true)) {
    erro('Tabela somente leitura.', 403);
}

// ------------------------------------------------------------
// PERMISSAO POR PAPEL
// ------------------------------------------------------------
// Papeis que acessam cada tabela. vt_valores (Beneficios/Intermitentes) e RH-only; colaboradores,
// colaborador_capacitacoes e solicitacoes_cobertura tambem liberam preposto; contratos_notificacao
// e RH/Gestor-only. Tabela sem entrada aqui cai no padrao ['rh', 'gestor'].
// Tabela sem entrada aqui cai no padrao ['rh', 'gestor'].
$PAPEIS_POR_TABELA = [
    'colaboradores'            => ['rh', 'administrador', 'gestor', 'preposto'],
    'colaborador_capacitacoes' => ['rh', 'administrador', 'gestor', 'preposto'],
    'solicitacoes_cobertura'   => ['rh', 'administrador', 'gestor', 'preposto'],
    'contratos_notificacao'    => ['rh', 'administrador', 'gestor'],
    'vt_valores'               => ['rh', 'administrador'],
    'centros_custo'            => ['rh', 'administrador', 'gestor', 'preposto'],
    'solicitacoes_desligamento' => ['rh', 'administrador', 'gestor', 'preposto'],
    'solicitacoes_afastamento' => ['rh', 'administrador', 'gestor', 'preposto'],
];
exigirPapel($pdo, $usuarioId, $PAPEIS_POR_TABELA[$tabelaNome] ?? ['rh', 'administrador', 'gestor']);

$papel = papelDoUsuario($pdo, $usuarioId);

// Preposto pode LISTAR, CRIAR (Nova Solicitacao) e, dentro de 24h da abertura, CANCELAR
// (acao=remover) uma linha de solicitacoes_cobertura - nunca EDITAR uma existente. O bloqueio de
// edicao (chave/id presente) fica em acao=salvar e salvar_lote, depois de calcular $temChave, pra
// nao impedir a criacao. O "cancelar dentro de 24h" e aplicado no bloco acao=remover.

// Gestor e Preposto so enxergam/editam colaboradores (Preposto) e solicitacoes de cobertura
// (Gestor) dos centros de custo que o RH liberou (usuario_centros_custo); as duas tabelas tem
// coluna centro_custo. null = tabela ainda nao existe (migracao nao rodou) - tratado como
// IRRESTRITO de proposito (ver centrosCustoLiberados em db.php).
$TABELAS_COM_ESCOPO_CENTRO_CUSTO = ['colaboradores', 'solicitacoes_cobertura', 'solicitacoes_desligamento', 'solicitacoes_afastamento'];
$centrosPermitidos = null;
if (in_array($tabelaNome, $TABELAS_COM_ESCOPO_CENTRO_CUSTO, true) && in_array($papel, ['gestor', 'preposto'], true)) {
    $centrosPermitidos = centrosCustoLiberados($pdo, $usuarioId, $papel);
}

// Pool de volante/intermitente que um Gestor administra ("Cobertura (quem cobre)", ver
// schema.sql) - so Gestor; escopa 'colaboradores' e o lado VOLANTE de 'solicitacoes_cobertura'.
// O lado titular continua em $centrosPermitidos. O equivalente do Preposto
// (preposto_centro_cobertura) e so informativo (ver condicaoCentroTitular, dashboard.php).
$coberturaGestor = ($papel === 'gestor') ? coberturaGestorLiberada($pdo, $usuarioId) : null;

// Escopo de ESCRITA (salvar/salvar_lote/remover): igual $centrosPermitidos, mas troca por
// $coberturaGestor quando a tabela e 'colaboradores' e o papel e Gestor (como acao=listar).
// Centralizado aqui pra nao divergir entre as checagens.
$centrosPermitidosEscrita = ($tabelaNome === 'colaboradores' && $papel === 'gestor') ? $coberturaGestor : $centrosPermitidos;

// Colaboradores: RH e Gestor so veem volante/intermitente (+ origem=MANUAL e quem tem motivo de inclusao); Preposto e o
// OPOSTO, so ve quem NAO e volante/intermitente (a "Equipe" dele; os volantes aparecem na tabela
// de Volantes/cobertura). Sem o filtro, RH veria tambem os centros "normais" importados so pra
// Gestor/Preposto (ver sync-protheus-colaboradores.php).
$centrosVolante = null;
if ($tabelaNome === 'colaboradores') {
    require_once __DIR__ . '/../scripts/protheus-colaboradores-helpers.php';
    $centrosVolante = [];
    $stmtCC = $pdo->query('SELECT codigo, descricao FROM centros_custo');
    foreach ($stmtCC->fetchAll() as $linhaCC) {
        if (centroCustoEhVolante((string) $linhaCC['codigo'], (string) ($linhaCC['descricao'] ?? ''))) {
            $centrosVolante[] = $linhaCC['codigo'];
        }
    }
}
// true = so mostra volante/intermitente (RH/Gestor); false = so mostra quem
// NAO e volante/intermitente (Preposto). origem=MANUAL e quem tem motivo de inclusao sempre
// passam, dos dois lados (ver condicaoVolanteOuManual).
$exigirVolante = in_array($papel, ['rh', 'administrador', 'gestor'], true);

// "escopo=irrestrito" (so RH/Gestor - papel checado de novo aqui) desliga o filtro de tipo: usado
// na busca de colaborador de coberturas/solicitacao.html, onde RH/Gestor podem escolher QUALQUER
// colaborador pra cobrir ou notificar. Preposto ignora o parametro: a busca dele fica restrita a
// propria Equipe.
if ($tabelaNome === 'colaboradores' && ($_GET['escopo'] ?? '') === 'irrestrito' && $exigirVolante) {
    $centrosVolante = null;
}

// Confere se uma linha (pela chave primaria) pertence a um dos centros liberados - usada em
// salvar/remover pra impedir um Gestor de mexer fora do escopo chamando a API direto (o filtro
// do listar so esconde da tela).
// Em 'colaboradores', o incluido (SQL_COLABORADOR_INCLUIDO, db.php) tambem conta pelo centro volante de destino (centro_destino):
// e por ele que o Gestor com esse centro na Cobertura ve e mexe na linha. So o Gestor - o destino e da Cobertura dele.
function linhaDentroDoEscopo($pdo, $tabelaNome, $chave, $valorChave, array $centrosPermitidos): bool {
    return linhasDentroDoEscopo($pdo, $tabelaNome, $chave, [$valorChave], $centrosPermitidos)[$valorChave] ?? false;
}

// Mesma checagem de linhaDentroDoEscopo, em LOTE (1 query pra varios valores). Devolve
// [valorChave => bool] com TODOS os valores pedidos; os inexistentes ficam false.
function linhasDentroDoEscopo($pdo, string $tabelaNome, string $chave, array $valoresChave, array $centrosPermitidos): array {
    $resultado = array_fill_keys($valoresChave, false);
    if (count($valoresChave) === 0) return $resultado;
    $marcadores = implode(',', array_fill(0, count($valoresChave), '?'));
    $destino = $tabelaNome === 'colaboradores' && ($GLOBALS['papel'] ?? '') === 'gestor'
        ? ', IF(' . SQL_COLABORADOR_INCLUIDO . ', centro_destino, NULL) AS destino' : '';
    $stmt = $pdo->prepare("SELECT `$chave` AS chave_valor, centro_custo$destino FROM `$tabelaNome` WHERE `$chave` IN ($marcadores)");
    $stmt->execute($valoresChave);
    foreach ($stmt->fetchAll() as $linha) {
        $resultado[$linha['chave_valor']] = in_array($linha['centro_custo'], $centrosPermitidos, true)
            || in_array($linha['destino'] ?? null, $centrosPermitidos, true);
    }
    return $resultado;
}

// As regras de permissao/integridade de solicitacoes_cobertura, compartilhadas por acao=salvar
// e acao=salvar_lote (uma copia so, pra nao divergir). Lanca RuntimeException com a mensagem e o
// status HTTP em getCode(); acao=salvar chama erro(), salvar_lote relanca como
// Exception("registro $i: ...") e desfaz o lote inteiro. Linha existente ($temChave) e recusada logo no inicio (item 11 da
// analise de 2026-11-12): dai para baixo so passa linha nova.
function validarSolicitacaoCobertura($pdo, $papel, string $tabelaNome, array $campos, bool $temChave, ?array $centrosPermitidosEscrita): void {
    if ($tabelaNome !== 'solicitacoes_cobertura') return;

    // Preposto nunca EDITA uma linha existente (so cria, ou cancela em acao=remover).
    // $temChave presente = o cliente mandou um id, ou seja, pede um UPDATE.
    if ($papel === 'preposto' && $temChave) {
        throw new RuntimeException('Preposto nao pode editar uma cobertura ja existente - apenas cancelar, ate 24h apos a abertura.', 403);
    }

    // Preposto NUNCA diz quem cobriu (exclusivo de RH/Gestor, cobertura-solicitacao.php): sem esta
    // checagem ele poderia se auto-designar. String vazia continua permitida (e o que a Nova
    // Solicitacao manda).
    if ($papel === 'preposto') {
        foreach (['matricula_vol', 'nome_vol'] as $colunaVol) {
            if (isset($campos[$colunaVol]) && trim((string) $campos[$colunaVol]) !== '') {
                throw new RuntimeException('Preposto nao pode definir quem cobriu a solicitacao.', 403);
            }
        }
    }

    // Linha existente e quem cobre nao mudam pela API generica, em NENHUM papel (item 11 da analise de 2026-11-12): designar e
    // corrigir so pela tela (cobertura-solicitacao.php: prazo do PFM, conflito, trava e Log); cancelar so por acao=remover.
    if ($temChave) {
        throw new RuntimeException('Uma solicitação existente não é editada por aqui: quem cobre se define na tela de Solicitações.', 403);
    }
    foreach (['matricula_vol', 'nome_vol'] as $colunaVol) {
        if (isset($campos[$colunaVol]) && trim((string) $campos[$colunaVol]) !== '') {
            throw new RuntimeException('Quem cobre se define na tela de Solicitações (Designar).', 403);
        }
    }
    // Motivos que se abrem por aqui (item 5 da analise de 2026-11-12): so os da Nova Solicitacao comum. Vaga de desligamento/afastamento
    // tem endpoint proprio (o cron estende pela ancora), ferias so nasce da ausencia (ausencia-cobertura.php) e servico extra/afastamento
    // ficaram so nas linhas antigas: abertos pela API, fugiriam das regras de cada um.
    if (!in_array($campos['motivo'] ?? null, ['falta', 'atestado'], true)) {
        throw new RuntimeException('Motivo inválido: por aqui só Falta ou Atestado (Desligamento e Afastamento abrem a própria vaga).', 400);
    }

    // Observacao do Preposto: texto (ou null), so na criacao, so do Preposto e ate 300 caracteres; depois, so pelo
    // acao=salvar_observacao (o Preposto, na mesma janela do cancelar). array_key_exists: um null num UPDATE tambem apagaria a nota.
    if (array_key_exists('observacao', $campos)) {
        $observacao = $campos['observacao'];
        if ($observacao !== null && !is_string($observacao)) {
            throw new RuntimeException('Observacao invalida.', 400);
        }
        if ($papel !== 'preposto' && trim((string) $observacao) !== '') {
            throw new RuntimeException('So o Preposto escreve a observacao da solicitacao.', 403);
        }
        if (mb_strlen((string) $observacao) > 300) {
            throw new RuntimeException('A observacao passa de 300 caracteres.', 400);
        }
    }

    // Escala (ESCALAS_SOLICITACAO, db.php): obrigatoria quando o Preposto abre, so os codigos da lista e nunca muda depois -
    // nem o Preposto corrige (decisao do usuario, 2026-11-11). A paridade do inicio e conferida no lote/salvar (o 1o dia).
    if (($papel === 'preposto' || ($campos['escala'] ?? null) !== null) && !escalaSolicitacaoValida($campos['escala'] ?? null)) {
        throw new RuntimeException('Selecione a escala (se o campo Escala não aparece, atualize a página com F5).', 400);
    }

    // Gestor restrito: o centro_custo de destino precisa estar no escopo dele, mesmo chamando a API direto.
    if ($centrosPermitidosEscrita !== null && isset($campos['centro_custo']) && !in_array($campos['centro_custo'], $centrosPermitidosEscrita, true)) {
        throw new RuntimeException('Centro de custo fora do seu escopo.', 403);
    }
    // Titular do centro enviado (titularDoCentro, db.php): antes do 409 de ferias abaixo, que mostraria as ferias de qualquer matricula.
    if (in_array($papel, ['preposto', 'gestor'], true)
        && !titularDoCentro($pdo, (string) ($campos['matricula'] ?? ''), (string) ($campos['centro_custo'] ?? ''))) {
        throw new RuntimeException('Esse colaborador não é do centro de custo informado (atualize a página com F5).', 403);
    }

    // Linha NOVA (Nova Solicitacao: Falta/Atestado) so de hoje em diante (dataBrAntesDeHoje, db.php).
    if (isset($campos['data']) && dataBrAntesDeHoje((string) $campos['data'])) {
        throw new RuntimeException('Não é possível abrir uma solicitação com data anterior a hoje.', 400);
    }
    // Dia de ferias do titular no SAF (feriasSafDoTitularNoDia, db.php): nao se abre solicitacao.
    if (isset($campos['matricula'], $campos['data'])
        && ($periodo = feriasSafDoTitularNoDia((string) $campos['matricula'], (string) $campos['data']))) {
        throw new RuntimeException("Este colaborador está de férias de $periodo (SAF): não é possível abrir solicitação nos dias de férias.", 409);
    }

    // "1 linha por dia" por titular: nunca 2 solicitacoes (qualquer motivo) pro mesmo colaborador
    // no mesmo dia (existeSolicitacaoNoDia em db.php). So confere quando matricula E data estao no
    // payload.
    if (isset($campos['matricula'], $campos['data'])) {
        if (existeSolicitacaoNoDia($pdo, (string) $campos['matricula'], (string) $campos['data'])) {
            throw new RuntimeException('Este colaborador ja tem uma solicitacao registrada nesse dia.', 409);
        }
    }

}

// Monta "(centro_custo IN/NOT IN (...volante...) OR incluido)" pra listar colaboradores ($centrosVolante/$exigirVolante) e
// devolve SQL + parametros na ordem do bind. Incluido = origem=MANUAL ou com motivo de inclusao ("Adicionar colaborador" de
// quem ja veio do Protheus, ver acao=salvar): entra dos dois lados.
function condicaoVolanteOuManual(array $centrosVolante, bool $somenteVolante): array {
    $incluido = SQL_COLABORADOR_INCLUIDO;
    if (count($centrosVolante) === 0) {
        // Nenhum centro volante conhecido: o lado "so volante" so tem os incluidos; o lado "so NAO volante" mostra tudo.
        return $somenteVolante ? [$incluido, []] : ["1=1", []];
    }
    $marcadores = implode(',', array_fill(0, count($centrosVolante), '?'));
    $operador = $somenteVolante ? 'IN' : 'NOT IN';
    return ["(centro_custo $operador ($marcadores) OR $incluido)", $centrosVolante];
}

// Periodo do aviso (DD/MM/AAAA) com as regras da tela (Adicionar e lapis): as 2 datas reais, fim nao antes do inicio e, quando o
// fim e novo, de hoje em diante. Usado no salvar com id, no ja_existia e na inclusao nova; erro 400 com a mensagem para a tela.
function validarPeriodoAviso($inicio, $fim, bool $fimDeHojeEmDiante): void {
    $data = function ($v) {
        $d = is_string($v) ? DateTime::createFromFormat('!d/m/Y', $v) : false;
        return $d && $d->format('d/m/Y') === $v ? $d : null;
    };
    $di = $data($inicio);
    $df = $data($fim);
    if (!$di) erro('Informe o inicio do aviso (DD/MM/AAAA).');
    if (!$df) erro('Informe o fim do aviso (DD/MM/AAAA).');
    if ($df < $di) erro('O fim do aviso nao pode ser antes do inicio.');
    if ($fimDeHojeEmDiante && $df < new DateTime('today')) erro('O fim do aviso nao pode ser uma data que ja passou.');
}

if ($acao === 'listar') {
    // "vol=1&matricula=XXXX" (telinha de Volante do Preposto, abrirModalInfoVolantePreposto em
    // colaboradores/funcionarios.html): consulta pontual do CADASTRO (filial, admissao, salario) de UM
    // volante. Nao reusa o filtro normal (Preposto nao ve volante, e ele pode ser de outro centro): so
    // libera se esse matricula_vol cobre AGORA alguem de um centro do Preposto - nunca consulta livre
    // por matricula, que abriria o salario de qualquer colaborador.
    if ($tabelaNome === 'colaboradores' && $papel === 'preposto' && ($_GET['vol'] ?? '') === '1') {
        $matriculaVol = trim((string) ($_GET['matricula'] ?? ''));
        if ($matriculaVol === '') { echo json_encode(['dados' => []]); exit; }

        $centrosPermitidosVol = centrosCustoLiberados($pdo, $usuarioId, 'preposto');
        if ($centrosPermitidosVol !== null && count($centrosPermitidosVol) === 0) {
            echo json_encode(['dados' => []]);
            exit;
        }
        if ($centrosPermitidosVol !== null) {
            // "AGORA" de verdade: sem "AND data = hoje", quem cobriu o centro uma vez continuaria liberando
            // o cadastro pra sempre.
            $hoje = (new DateTime())->format('d/m/Y');
            $marcadoresVol = implode(',', array_fill(0, count($centrosPermitidosVol), '?'));
            // Ou de BASE hoje num contrato dele (colaborador_bases, ver base-colaborador.php).
            $condicaoBase = tabelaExiste($pdo, 'colaborador_bases')
                ? "OR EXISTS (SELECT 1 FROM colaborador_bases WHERE matricula = ? AND centro_custo IN ($marcadoresVol) AND data = ?)"
                : '';
            $stmtChecagem = $pdo->prepare(
                "SELECT 1 FROM solicitacoes_cobertura WHERE matricula_vol = ? AND centro_custo IN ($marcadoresVol) AND data = ?
                 $condicaoBase LIMIT 1"
            );
            $paramsChecagem = array_merge([$matriculaVol], $centrosPermitidosVol, [$hoje]);
            if ($condicaoBase !== '') $paramsChecagem = array_merge($paramsChecagem, $paramsChecagem);
            $stmtChecagem->execute($paramsChecagem);
            if ($stmtChecagem->fetchColumn() === false) {
                echo json_encode(['dados' => []]);
                exit;
            }
        }

        $stmt = $pdo->prepare('SELECT * FROM colaboradores WHERE matricula = ? LIMIT 1');
        $stmt->execute([$matriculaVol]);
        $linha = $stmt->fetch();
        echo json_encode(['dados' => $linha ? [$linha] : []]);
        exit;
    }

    // "desligados=1&centro_custo=XXXX" (Nova Solicitacao, Preposto, motivo "desligamento"): busca
    // colaboradores DESLIGADOS, em vez da busca normal (so ativos). Ignora os filtros de escopo/tipo
    // acima de proposito: quem desligou pode ser de qualquer contrato; o unico requisito e o centro
    // digitado ser ATIVO (mesma lista tem_colaborador=1 de login.php?acao=listar_centros_custo).
    //
    // Busca DIRETO no Protheus, nao na tabela local: ela so tem quem foi importado enquanto ativo, e
    // quem desligou antes de qualquer sync ficaria invisivel. RA_DEMISSA <> '' = desligado (mesmo
    // campo do sync). O NOT EXISTS exclui quem tem readmissao ATIVA (RA_DEMISSA='' na mesma matricula).
    if ($tabelaNome === 'colaboradores' && ($_GET['desligados'] ?? '') === '1') {
        $centroCustoDesligados = trim((string) ($_GET['centro_custo'] ?? ''));
        // "termo" (matricula ou nome): pesquisa por texto direto no Protheus, sem exigir centro exato.
        // centro_custo continua aceito so por compatibilidade (o frontend nao manda mais).
        $termoDesligados = trim((string) ($_GET['termo'] ?? ''));

        if ($centroCustoDesligados === '' && mb_strlen($termoDesligados) < 3) {
            // Sem centro exato NEM termo suficiente: evita puxar todo desligado do Protheus (defesa em
            // profundidade; o frontend so chama a partir de 3 caracteres).
            echo json_encode(['dados' => []]);
            exit;
        }

        // Sem centro exato: restringe aos centros do proprio usuario (ver centrosCustoLiberados).
        $centrosParaDesligados = null;
        if ($centroCustoDesligados !== '') {
            $stmtValido = $pdo->prepare('SELECT 1 FROM centros_custo WHERE codigo = ? AND tem_colaborador = 1');
            $stmtValido->execute([$centroCustoDesligados]);
            if ($stmtValido->fetchColumn() === false) {
                erro('Centro de custo invalido ou sem colaborador ativo no momento.');
            }
            $centrosParaDesligados = [$centroCustoDesligados];
        } else {
            $centrosParaDesligados = centrosCustoLiberados($pdo, $usuarioId, $papel); // null = irrestrito
        }
        if ($centrosParaDesligados !== null && count($centrosParaDesligados) === 0) {
            echo json_encode(['dados' => []]);
            exit;
        }

        if (empty(PROTHEUS_DB_HOST)) {
            erro('Integracao com o Protheus nao configurada - nao e possivel buscar desligados.', 503);
        }

        $condicaoCc = '';
        $paramsCc = [];
        if ($centrosParaDesligados !== null) {
            $marcadoresCc = implode(',', array_fill(0, count($centrosParaDesligados), '?'));
            $condicaoCc = "AND RTRIM(A.RA_CC) IN ($marcadoresCc)";
            $paramsCc = $centrosParaDesligados;
        }

        $condicaoTermo = '';
        $paramsTermo = [];
        if ($termoDesligados !== '') {
            $condicaoTermo = 'AND (RTRIM(A.RA_MAT) LIKE ? OR RTRIM(A.RA_NOMECMP) LIKE ?)';
            $curinga = '%' . $termoDesligados . '%';
            $paramsTermo = [$curinga, $curinga];
        }

        try {
            $protheusPdo = conectarProtheusDb();
            // NOT EXISTS cruzado: a matricula e compartilhada entre os 2 grupos (mesmo colaborador), entao
            // cada lado da UNION confere readmissao ATIVA tambem no OUTRO grupo (transferido nao aparece
            // como desligado).
            $stmtDeslig = $protheusPdo->prepare(
                "SELECT RTRIM(A.RA_FILIAL) AS FILIAL, RTRIM(A.RA_CC) AS CC, RTRIM(A.RA_MAT) AS MAT,
                        RTRIM(A.RA_NOMECMP) AS NOME, RTRIM(A.RA_ADMISSA) AS ADMISSAO, RTRIM(A.RA_DEMISSA) AS DEMISSAO,
                        RTRIM(A.RA_CODFUNC) AS FUNCAO_CODIGO, RTRIM(J.RJ_DESC) AS FUNCAO
                 FROM SRA010 A
                 LEFT JOIN SRJ010 J ON J.RJ_FUNCAO = A.RA_CODFUNC AND J.D_E_L_E_T_ <> '*'
                 WHERE A.D_E_L_E_T_ <> '*' AND A.RA_DEMISSA <> '' $condicaoCc $condicaoTermo
                   AND NOT EXISTS (SELECT 1 FROM SRA010 A2 WHERE A2.RA_MAT = A.RA_MAT AND A2.D_E_L_E_T_ <> '*' AND A2.RA_DEMISSA = '')
                   AND NOT EXISTS (SELECT 1 FROM SRA020 B2 WHERE B2.RA_MAT = A.RA_MAT AND B2.D_E_L_E_T_ <> '*' AND B2.RA_DEMISSA = '')
                 UNION ALL
                 SELECT RTRIM(A.RA_FILIAL), RTRIM(A.RA_CC), RTRIM(A.RA_MAT),
                        RTRIM(A.RA_NOMECMP), RTRIM(A.RA_ADMISSA), RTRIM(A.RA_DEMISSA),
                        RTRIM(A.RA_CODFUNC), RTRIM(J.RJ_DESC)
                 FROM SRA020 A
                 LEFT JOIN SRJ010 J ON J.RJ_FUNCAO = A.RA_CODFUNC AND J.D_E_L_E_T_ <> '*'
                 WHERE A.D_E_L_E_T_ <> '*' AND A.RA_DEMISSA <> '' $condicaoCc $condicaoTermo
                   AND NOT EXISTS (SELECT 1 FROM SRA020 A2 WHERE A2.RA_MAT = A.RA_MAT AND A2.D_E_L_E_T_ <> '*' AND A2.RA_DEMISSA = '')
                   AND NOT EXISTS (SELECT 1 FROM SRA010 B2 WHERE B2.RA_MAT = A.RA_MAT AND B2.D_E_L_E_T_ <> '*' AND B2.RA_DEMISSA = '')
                 ORDER BY DEMISSAO DESC"
            );
            $stmtDeslig->execute(array_merge($paramsCc, $paramsTermo, $paramsCc, $paramsTermo));
            $linhasProtheus = $stmtDeslig->fetchAll();
        } catch (Throwable $e) {
            error_log('Falha ao consultar o Protheus (desligados): ' . $e->getMessage());
            erro('Falha ao consultar o Protheus. Tente novamente em instantes.', 502);
        }

        // Uma matricula pode ter varios desligamentos; com ORDER BY DEMISSAO DESC o primeiro por
        // matricula e o mais recente.
        $vistos = [];
        $dados = [];
        foreach ($linhasProtheus as $l) {
            if (isset($vistos[$l['MAT']])) continue;
            $vistos[$l['MAT']] = true;
            $dados[] = [
                'filial' => $l['FILIAL'],
                'centro_custo' => $l['CC'],
                'matricula' => $l['MAT'],
                'nome' => $l['NOME'],
                'funcao' => $l['FUNCAO'],
                'funcao_codigo' => $l['FUNCAO_CODIGO'],
                'data_admissao' => protheusColabFormatarData($l['ADMISSAO']),
                'desligamento' => protheusColabFormatarData($l['DEMISSAO']),
            ];
        }
        // Teto de seguranca: nunca devolver lista gigante (ex: termo comum tipo "SILVA").
        echo json_encode(['dados' => array_slice($dados, 0, 20)]);
        exit;
    }

    // 'colaboradores' escopa DIFERENTE das outras 3 tabelas: Preposto usa $centrosPermitidos, Gestor
    // usa $coberturaGestor (pool de volante) - $centrosPermitidosEscrita ja resolve a troca. Por isso
    // fica fora do bloco generico abaixo.
    if ($tabelaNome === 'colaboradores') {
        // $centrosVolante null = "escopo=irrestrito" (desliga tambem o filtro de TIPO).
        // condicaoVolanteOuManual exige array, entao este desvio evita TypeError.
        if ($centrosVolante === null) {
            $sqlTipo = '1=1';
            $paramsTipo = [];
        } else {
            [$sqlTipo, $paramsTipo] = condicaoVolanteOuManual($centrosVolante, $exigirVolante);
        }

        if ($centrosPermitidosEscrita !== null) {
            if (count($centrosPermitidosEscrita) === 0) {
                echo json_encode(['dados' => []]);
                exit;
            }
            $marcadoresCol = implode(',', array_fill(0, count($centrosPermitidosEscrita), '?'));
            // Dentro do escopo de centro dele, so volante/intermitente (Gestor) ou so NAO volante
            // (Preposto) - ver $centrosVolante/$exigirVolante. Gestor: + o incluido cujo centro volante de destino e da Cobertura dele.
            $porDestino = $papel === 'gestor' ? ' OR (' . SQL_COLABORADOR_INCLUIDO . " AND centro_destino IN ($marcadoresCol))" : '';
            $stmt = $pdo->prepare("SELECT * FROM `colaboradores` WHERE (centro_custo IN ($marcadoresCol) AND $sqlTipo)$porDestino");
            $stmt->execute(array_merge($centrosPermitidosEscrita, $paramsTipo, $porDestino ? $centrosPermitidosEscrita : []));
        } else {
            // RH/Administrador, ou Gestor/Preposto com escopo 'TODOS' (vira null): sem escopo de centro,
            // so o filtro de tipo (que tambem pode estar desligado).
            $stmt = $pdo->prepare("SELECT * FROM `colaboradores` WHERE $sqlTipo");
            $stmt->execute($paramsTipo);
        }
        echo json_encode(['dados' => $stmt->fetchAll()]);
        exit;
    }

    if ($centrosPermitidos !== null) {
        if (count($centrosPermitidos) === 0) {
            echo json_encode(['dados' => []]);
            exit;
        }
        $marcadores = implode(',', array_fill(0, count($centrosPermitidos), '?'));

        if ($tabelaNome === 'solicitacoes_cobertura') {
            // Linha de cobertura relevante se o TITULAR esta no escopo dele ($centrosPermitidos) OU o
            // VOLANTE (matricula_vol) e do POOL dele ($coberturaGestor, Gestor; Preposto nao tem pool e reusa
            // $centrosPermitidos, que nunca bate nada extra). Sem o 2o lado, o Gestor nao via a cobertura
            // feita pelo proprio volante pra um titular de fora.
            // pode_cancelar: so faz sentido pro Preposto, mas calcular sempre e barato. NOW() do MySQL,
            // nunca o relogio do PHP/navegador.
            // Gestor com grupo "todos" (null: "Todos os contratos" ou RH junto, coberturaGestorLiberada) = todos os volantes, como na
            // Previsao; [] (nenhum grupo) continua so o lado titular.
            require_once __DIR__ . '/../scripts/protheus-colaboradores-helpers.php';
            // Preposto: so o lado titular - pelo lado volante ele recebia a solicitacao de OUTRO centro (com os salarios do retrato)
            // quando alguem da Equipe dele era alocado como volante (ex: incluido pelo "Adicionar colaborador").
            $poolVolanteLado = ($papel === 'gestor') ? ($coberturaGestor ?? codigosCentrosVolante($pdo)) : [];
            if ($poolVolanteLado === null || count($poolVolanteLado) === 0) {
                // Preposto, ou Gestor sem Cobertura configurada (RH/Administrador nao chegam aqui): sem pool pra cruzar,
                // so o lado titular importa.
                $stmt = $pdo->prepare(
                    "SELECT *, (criado_em IS NOT NULL AND criado_em >= NOW() - INTERVAL 24 HOUR) AS pode_cancelar
                       FROM `solicitacoes_cobertura`
                      WHERE centro_custo IN ($marcadores)"
                );
                $stmt->execute($centrosPermitidos);
            } else {
                // O incluido com centro de destino no grupo do Gestor tambem conta (sqlColaboradorNoPool, db.php).
                $condicaoPool = sqlColaboradorNoPool(count($poolVolanteLado));
                $paramsPool = array_merge($poolVolanteLado, $poolVolanteLado);
                // Ausencia de cobertura (ausencia-cobertura.php): o dia virou SEM_COBERTURA, mas o Gestor do
                // grupo do volante que faltou continua vendo.
                $temAusencia = colunaExiste($pdo, 'solicitacoes_cobertura', 'ausencia_matricula_vol');
                $condicaoAusencia = $temAusencia
                    ? "OR ausencia_matricula_vol IN (SELECT matricula FROM colaboradores WHERE $condicaoPool)"
                    : '';
                $stmt = $pdo->prepare(
                    "SELECT *, (criado_em IS NOT NULL AND criado_em >= NOW() - INTERVAL 24 HOUR) AS pode_cancelar
                       FROM `solicitacoes_cobertura`
                      WHERE centro_custo IN ($marcadores)
                         OR matricula_vol IN (SELECT matricula FROM colaboradores WHERE $condicaoPool)
                         $condicaoAusencia"
                );
                $stmt->execute(array_merge($centrosPermitidos, $paramsPool, $temAusencia ? $paramsPool : []));
            }
        } else {
            $stmt = $pdo->prepare("SELECT * FROM `$tabelaNome` WHERE centro_custo IN ($marcadores)");
            $stmt->execute($centrosPermitidos);
        }
    } elseif ($tabelaNome === 'solicitacoes_cobertura') {
        // Mesmo pode_cancelar dos 2 ramos acima: Preposto com escopo IRRESTRITO (usuario_centros_custo
        // = "TODOS") cai direto neste SELECT *, que nao tem a coluna.
        $stmt = $pdo->query(
            "SELECT *, (criado_em IS NOT NULL AND criado_em >= NOW() - INTERVAL 24 HOUR) AS pode_cancelar
               FROM `solicitacoes_cobertura`"
        );
    } else {
        $stmt = $pdo->query("SELECT * FROM `$tabelaNome`");
    }
    $dados = $stmt->fetchAll();
    // Nota do Preposto so para quem cuida do posto (centro do titular): o Gestor que ve a linha so porque o volante e do pool dele
    // nao recebe o texto, que pode ser de atestado (LGPD).
    if ($tabelaNome === 'solicitacoes_cobertura' && $papel === 'gestor' && $centrosPermitidos !== null) {
        $centrosDoPosto = array_flip($centrosPermitidos);
        foreach ($dados as &$linhaNota) {
            if (!isset($centrosDoPosto[$linhaNota['centro_custo']])) {
                $linhaNota['observacao'] = $linhaNota['observacao_editada_por'] = $linhaNota['observacao_editada_em'] = null;
            }
        }
        unset($linhaNota);
    }
    // Prazo de correcao de cada linha coberta, calculado aqui (15 dias apos o ultimo dia do periodo e nunca depois do dia 3 do
    // fechamento do PFM - dentroDoPrazoDeCorrecao em db.php); cobertura-solicitacao.php usa a MESMA funcao.
    if ($tabelaNome === 'solicitacoes_cobertura' && count($dados) > 0) {
        $fimPeriodo = fimDosPeriodos($pdo, array_column($dados, 'matricula'));
        $hoje = $pdo->query('SELECT CURDATE()')->fetchColumn();
        $inicioPfmLista = inicioPeriodoPfmAberto((string) $hoje);
        foreach ($dados as &$linhaDados) {
            $diaLinha = (string) dataBrParaIso((string) $linhaDados['data']);
            $linhaDados['pode_corrigir'] = dentroDoPrazoDeCorrecao(
                $fimPeriodo[(int) $linhaDados['id']] ?? null, $linhaDados['fechado_em'] ?? null, $hoje, $diaLinha ?: null
            );
            // O RH/Administrador pode cancelar este dia (as mesmas regras de acao=remover): a tela oferece o cancelar enquanto o PFM
            // aceita ajuste, mesmo depois dos 15 dias da correcao.
            $volLinha = trim((string) ($linhaDados['matricula_vol'] ?? ''));
            $linhaDados['pode_cancelar_rh'] = !in_array($linhaDados['motivo'], ['desligamento', 'afastamento_indeterminado', 'ferias'], true)
                && ($volLinha === '' || $volLinha === 'SEM_COBERTURA')
                && trim((string) ($linhaDados['ausencia_matricula_vol'] ?? '')) === ''
                && $diaLinha !== '' && $diaLinha >= $inicioPfmLista;
        }
        unset($linhaDados);
    }
    echo json_encode(['dados' => $dados]);
    exit;
}

if ($acao === 'salvar') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    if (!is_array($corpo)) erro('Corpo invalido, esperado um objeto JSON');

    // So aceita colunas que estao na allowlist (mais a chave primaria, se vier)
    $campos = [];
    foreach ($corpo as $coluna => $valor) {
        if ($coluna === $chave || in_array($coluna, $colunasPermitidas, true)) {
            $campos[$coluna] = $valor;
        }
    }

    $temChave = isset($campos[$chave]) && $campos[$chave] !== '' && $campos[$chave] !== null;

    // Retrato congelado (ver schema.sql): titular e quem abriu so na criacao; vol_salario sempre que
    // matricula_vol vier no payload.
    if ($tabelaNome === 'solicitacoes_cobertura') {
        if (!$temChave && isset($campos['matricula'])) {
            $campos = array_merge($campos, snapshotTitularSolicitacao($pdo, (string) $campos['matricula']));
        }
        if (!$temChave) {
            $campos = array_merge($campos, quemAbriuSolicitacao($pdo, $usuarioId, $papel));
            $campos['lote_id'] = novoLoteSolicitacao(); // id da solicitacao (db.php), so do servidor
        }
        // Mesma trava por titular da criacao em lote (travarTitulares, db.php); a conexao fecha no fim da requisicao e solta.
        if (!$temChave && isset($campos['matricula']) && travarTitulares($pdo, [(string) $campos['matricula']]) === null) {
            erro('Outra solicitação deste colaborador está sendo gravada agora - tente de novo em instantes.', 409);
        }
    }

    // Regras de permissao/integridade de solicitacoes_cobertura (validarSolicitacaoCobertura),
    // compartilhadas com acao=salvar_lote.
    try {
        validarSolicitacaoCobertura($pdo, $papel, $tabelaNome, $campos, $temChave, $centrosPermitidosEscrita);
    } catch (RuntimeException $e) {
        erro($e->getMessage(), (int) $e->getCode());
    }
    // Linha nova avulsa = solicitacao de 1 dia: esse dia e o inicio (escala 12x36 par/impar, db.php).
    if ($tabelaNome === 'solicitacoes_cobertura' && !$temChave && ($msgParidade = erroParidadeEscala($campos['escala'] ?? null, (string) ($campos['data'] ?? '')))) {
        erro($msgParidade);
    }

    // Motivo da inclusao, datas do aviso e centro de destino sao do RH/Gestor ("Adicionar colaborador" e lapis da ficha): o
    // Preposto (que nao inclui ninguem) nao os grava nem chamando a API direto - poria a pessoa da Equipe dele na lista de um Gestor.
    if ($tabelaNome === 'colaboradores' && $papel === 'preposto') {
        unset($campos['motivo_inclusao'], $campos['centro_destino'], $campos['inicio_aviso'], $campos['fim_aviso']);
    }
    if ($tabelaNome === 'colaboradores') {
        foreach (['motivo_inclusao', 'centro_destino', 'inicio_aviso', 'fim_aviso'] as $c) {
            if (array_key_exists($c, $campos) && !is_string($campos[$c])) erro("Valor invalido em $c.");
        }
    }

    // Centro de destino ("Adicionar colaborador" e lapis da ficha): so centro volante/intermitente e, pro Gestor, so da Cobertura
    // dele - senao ele daria a linha a outra gestao (ou a ninguem) chamando a API direto.
    if ($tabelaNome === 'colaboradores' && trim((string) ($campos['centro_destino'] ?? '')) !== '') {
        $campos['centro_destino'] = strtoupper(trim((string) $campos['centro_destino']));
        if (!in_array($campos['centro_destino'], codigosCentrosVolante($pdo), true)) erro('O centro de destino precisa ser um centro de volante ou intermitente.');
        if ($papel === 'gestor' && $coberturaGestor !== null && !in_array($campos['centro_destino'], $coberturaGestor, true)) {
            erro('Este centro de destino nao esta na sua Cobertura.', 403);
        }
    }

    // Colaborador ja existente (UPDATE pela ficha; motivo, datas e destino so vem dos lapis, nunca do retrato da linha):
    // - Gestor/Preposto so mexem no proprio escopo, como no remover e na observacao (sem isto a API alterava qualquer colaborador,
    //   inclusive o salario que entra no retrato do PFM);
    // - os dados do Protheus ficam de fora em qualquer linha (importada: sao do sync; manual: vieram da busca do Adicionar) - o
    //   retrato antigo desfazia o que o sync atualizou, e trocar o centro de uma linha manual a passava a outra gestao;
    // - a observacao tem caminho proprio com concorrencia (salvar_observacao_segura) e a origem e do sistema (o sync converte
    //   MANUAL em IMPORTADO): as duas ficam de fora.
    $auditoriaAviso = null;
    if ($temChave && $tabelaNome === 'colaboradores') {
        if ($centrosPermitidosEscrita !== null && !linhaDentroDoEscopo($pdo, 'colaboradores', 'id', $campos[$chave], $centrosPermitidosEscrita)) {
            erro('Este colaborador esta fora do seu centro de custo.', 403);
        }
        foreach (['filial', 'centro_custo', 'matricula', 'nome', 'funcao', 'funcao_codigo', 'data_admissao', 'salario'] as $c) unset($campos[$c]);
        unset($campos['observacao'], $campos['origem']);
        // Lapis da ficha (datas do aviso, centro de destino):
        // - linha sem motivo = inclusao ja encerrada (o sync fechou o aviso, ou Remover) numa ficha aberta antes: nao regrava nada;
        // - datas com as regras do Adicionar (validarPeriodoAviso; "de hoje em diante" so quando o fim muda);
        // - fim mudou: a resposta ao aviso vencido (aviso_situacao), que era da data antiga, sai no mesmo UPDATE e, sobre um aviso
        //   vencido ja marcado, fica na auditoria como no painel.
        if (array_intersect(['inicio_aviso', 'fim_aviso', 'centro_destino'], array_keys($campos))) {
            $st = $pdo->prepare('SELECT matricula, nome, motivo_inclusao, inicio_aviso, fim_aviso, aviso_situacao FROM colaboradores WHERE id = ?');
            $st->execute([$campos[$chave]]);
            $antes = $st->fetch();
            if ($antes && trim((string) $antes['motivo_inclusao']) === '') {
                erro('A inclusao deste colaborador ja foi encerrada (ex: fim do aviso). Recarregue a pagina.', 409);
            }
            if ($antes && (array_key_exists('inicio_aviso', $campos) || array_key_exists('fim_aviso', $campos))) {
                $fimMudou = array_key_exists('fim_aviso', $campos) && $campos['fim_aviso'] !== (string) $antes['fim_aviso'];
                validarPeriodoAviso($campos['inicio_aviso'] ?? (string) $antes['inicio_aviso'], $campos['fim_aviso'] ?? (string) $antes['fim_aviso'], $fimMudou);
                if ($fimMudou) {
                    $campos['aviso_situacao'] = null;
                    if ((string) $antes['aviso_situacao'] !== '') {
                        $auditoriaAviso = "{$antes['matricula']} - {$antes['nome']}: aviso vencido em {$antes['fim_aviso']} ({$antes['aviso_situacao']}); "
                            . "fim alterado para {$campos['fim_aviso']} pela ficha";
                    }
                }
            }
        }
    }

    if ($temChave) {
        // UPDATE por chave (id, ou matricula no caso de vt_valores)
        $valorChave = $campos[$chave];
        unset($campos[$chave]);
        if (count($campos) === 0) erro('Nenhum campo para atualizar');

        $sets = [];
        $params = [];
        foreach ($campos as $col => $val) {
            $sets[] = "`$col` = ?";
            $params[] = $val;
        }
        $params[] = $valorChave;

        $sql = "UPDATE `$tabelaNome` SET " . implode(', ', $sets) . " WHERE `$chave` = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($auditoriaAviso) {
            registrarAuditoria($pdo, $usuarioId, 'aviso_vencido', ['tabela' => 'colaboradores', 'quantidade' => 1, 'detalhe' => $auditoriaAviso]);
        }

        echo json_encode(['success' => true, $chave => $valorChave]);
    } else {
        // INSERT (ou UPSERT quando a chave e string, ex: matricula em vt_valores)
        if (count($campos) === 0) erro('Nenhum campo para inserir');
        // Incluir colaborador e do RH/Gestor (o "+" de Colaboradores/CDV; o Preposto nao tem o botao).
        if ($tabelaNome === 'colaboradores' && $papel === 'preposto') erro('O Preposto nao inclui colaborador.', 403);
        // Aviso: as mesmas regras de datas da tela (inclusive no ja_existia abaixo).
        if ($tabelaNome === 'colaboradores' && stripos($campos['motivo_inclusao'] ?? '', 'aviso') !== false) {
            validarPeriodoAviso($campos['inicio_aviso'] ?? '', $campos['fim_aviso'] ?? '', true);
        }

        // "Adicionar colaborador" (origem MANUAL) de quem ja esta no cadastro - veio do Protheus, e a matricula e unica desde
        // 2026-09, entao o INSERT falhava: grava so o motivo da inclusao na linha que existe (aviso com as datas; devolvido/
        // transferencia na observacao, somada a que ja houver). Os dados do Protheus continuam com o sync, e a lista do RH/Gestor
        // mostra quem tem motivo (condicaoVolanteOuManual).
        if ($tabelaNome === 'colaboradores' && ($campos['origem'] ?? '') === 'MANUAL' && trim((string) ($campos['matricula'] ?? '')) !== '') {
            $stmt = $pdo->prepare('SELECT id, origem, centro_custo, matricula, nome, fim_aviso, aviso_situacao, desligamento, '
                . SQL_COLABORADOR_INCLUIDO . ' AS incluido FROM colaboradores WHERE matricula = ?');
            $stmt->execute([trim((string) $campos['matricula'])]);
            $existente = $stmt->fetch();
            if ($existente) {
                $idExistente = (int) $existente['id'];
                // Linha ja desligada (o sync encerrou o aviso, ou Remover - ver acao=remover) e de quem a busca traz ativo de novo:
                // e livre, sem "outra gestao", e o desligamento antigo sai no mesmo UPDATE.
                $desligadoEm = DateTime::createFromFormat('!d/m/Y', (string) $existente['desligamento']);
                $desligada = $desligadoEm && $desligadoEm <= new DateTime('today');
                // Quem esta em centro volante/intermitente e volante, mesmo tendo entrado por um motivo: ja esta na tabela (o
                // desligamento dele e na aba Situacao). E o incluido por outra gestao (fora do escopo do Gestor, nem pelo centro de
                // destino) nao muda de dono por aqui.
                if (in_array($existente['centro_custo'], codigosCentrosVolante($pdo), true)) {
                    erro('Este colaborador e volante/intermitente e ja esta na tabela de colaboradores.', 409);
                }
                if ($existente['incluido'] && !$desligada && $centrosPermitidosEscrita !== null
                    && !linhaDentroDoEscopo($pdo, 'colaboradores', 'id', $idExistente, $centrosPermitidosEscrita)) {
                    erro('Este colaborador ja foi incluido por outra gestao.', 403);
                }
                $obs = trim((string) ($campos['observacao'] ?? ''));
                $pdo->prepare("UPDATE colaboradores SET motivo_inclusao = ?, centro_destino = NULLIF(?, ''), inicio_aviso = ?, fim_aviso = ?,
                                      aviso_situacao = NULL, desligamento = IF(?, '', desligamento),
                                      observacao = IF(? = '', observacao, CONCAT_WS(' | ', NULLIF(observacao, ''), ?)) WHERE id = ?")
                    ->execute([(string) ($campos['motivo_inclusao'] ?? ''), (string) ($campos['centro_destino'] ?? ''),
                               (string) ($campos['inicio_aviso'] ?? ''), (string) ($campos['fim_aviso'] ?? ''), (int) $desligada,
                               $obs, $obs, $idExistente]);
                // Adicionar de novo resolve o aviso vencido ja marcado (PENDENTE/AGUARDANDO): fica na auditoria como no painel.
                if ((string) $existente['aviso_situacao'] !== '') {
                    registrarAuditoria($pdo, $usuarioId, 'aviso_vencido', [
                        'tabela' => 'colaboradores',
                        'quantidade' => 1,
                        'detalhe' => "{$existente['matricula']} - {$existente['nome']}: aviso vencido em {$existente['fim_aviso']} "
                            . "({$existente['aviso_situacao']}); adicionado de novo com o motivo " . (string) ($campos['motivo_inclusao'] ?? ''),
                    ]);
                }
                // A linha como ficou no banco: a tela usa ela inteira (e-mail, contato, experiencia...), senao a ficha montada so com
                // os dados da busca apagaria esses campos na proxima edicao.
                $stmt = $pdo->prepare('SELECT * FROM colaboradores WHERE id = ?');
                $stmt->execute([$idExistente]);
                echo json_encode(['success' => true, 'id' => $idExistente, 'ja_existia' => true, 'origem' => $existente['origem'],
                                  'registro' => $stmt->fetch()]);
                exit;
            }
        }

        // Linha nova do Gestor: o salario vem do Protheus pela matricula, no servidor (cadastroProtheusPorMatricula, db.php, o mesmo
        // calculo da busca) - o do navegador virava vol_salario no PFM, e a busca nao manda mais salario ao Gestor. Protheus fora ou
        // sem a matricula: recusa (abaixo). RH/Administrador continuam com o da busca.
        if ($tabelaNome === 'colaboradores' && !in_array($papel, ['rh', 'administrador'], true)) {
            $cadastroProtheus = cadastroProtheusPorMatricula(trim((string) ($campos['matricula'] ?? '')));
            // Protheus configurado que nao respondeu (ou sem a matricula): recusa (recomendacao da 2a analise de 2026-11-12). A linha MANUAL
            // ficaria com o salario vazio para sempre (o sync so o atualiza se ela virar IMPORTADO) e, cobrindo alguem, o PFM pagaria a
            // diferenca inteira. Sem Protheus configurado (ambiente local) segue vazio, como antes.
            if ($cadastroProtheus === null && !empty(PROTHEUS_DB_HOST)) {
                erro('Não foi possível confirmar essa matrícula no Protheus agora (fora do ar ou sem cadastro) - tente de novo em instantes ou peça ao RH para incluir.', 503);
            }
            $campos['salario'] = $cadastroProtheus['salario'] ?? null;
        }

        $colunasSql = array_map(fn($c) => "`$c`", array_keys($campos));
        $marcadores = array_fill(0, count($campos), '?');

        // criado_em = NOW() do MySQL (nao vem do cliente; fora da allowlist) - base da janela de 24h
        // do cancelar do Preposto.
        if ($tabelaNome === 'solicitacoes_cobertura' && $chave === 'id') {
            $colunasSql[] = '`criado_em`';
            $marcadores[] = 'NOW()';
        }

        if ($chave === 'id') {
            $sql = "INSERT INTO `$tabelaNome` (" . implode(', ', $colunasSql) . ") VALUES (" . implode(', ', $marcadores) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($campos));
            $idNovo = $pdo->lastInsertId();
            // Solicitacao de 1 dia: Log por motivo e aviso se comeca hoje/amanha (db.php), como no salvar_lote.
            if ($tabelaNome === 'solicitacoes_cobertura') {
                registrarAuditoriaSolicitacao($pdo, $usuarioId, 'abrir', [$campos]);
                avisarSolicitacaoUrgente($pdo, $usuarioId, [$campos]);
                avisarTitularCobrindoNoDia($pdo, $usuarioId, [$campos]);
            }
            echo json_encode(['success' => true, 'id' => $idNovo]);
        } else {
            // Tabelas com chave textual (vt_valores: matricula) fazem upsert
            $atualizacoes = array_map(fn($c) => "`$c` = VALUES(`$c`)", array_keys($campos));
            $sql = "INSERT INTO `$tabelaNome` (" . implode(', ', $colunasSql) . ") VALUES (" . implode(', ', $marcadores) . ")
                    ON DUPLICATE KEY UPDATE " . implode(', ', $atualizacoes);
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($campos));
            echo json_encode(['success' => true]);
        }
    }
    exit;
}

// Salva a Observacao com controle de concorrencia OTIMISTA: UPDATE atomico de UMA coluna,
// condicionado ao valor que o cliente tinha ao abrir (compare-and-swap com <=> null-safe). O
// acao=salvar generico faz UPSERT da linha inteira, e um snapshot antigo sobrescreveria campos
// de outra pessoa. 0 linhas afetadas = alguem mudou nesse meio-tempo; o cliente recebe o valor
// atual em vez de sobrescrever.
if ($acao === 'salvar_observacao_segura') {
    if ($tabelaNome !== 'colaboradores') erro('Acao valida so pra tabela colaboradores.');
    $corpo = json_decode(file_get_contents('php://input'), true);
    if (!is_array($corpo)) erro('Corpo invalido, esperado um objeto JSON');

    $id = (int) ($corpo['id'] ?? 0);
    $valorNovo = (string) ($corpo['valor_novo'] ?? '');
    $valorEsperado = (string) ($corpo['valor_esperado'] ?? '');
    if ($id <= 0) erro('Parametro "id" obrigatorio.');

    if ($centrosPermitidosEscrita !== null && !linhaDentroDoEscopo($pdo, 'colaboradores', 'id', $id, $centrosPermitidosEscrita)) {
        erro('Este colaborador esta fora do seu centro de custo.', 403);
    }

    // COALESCE(observacao,''): NULL e '' contam como "nao tem nada", senao o 1o save viraria
    // falso-conflito (NULL <=> '' e falso).
    $stmt = $pdo->prepare("UPDATE colaboradores SET observacao = ? WHERE id = ? AND COALESCE(observacao, '') <=> ?");
    $stmt->execute([$valorNovo, $id, $valorEsperado]);

    if ($stmt->rowCount() === 0) {
        // Nao gravou: id inexistente ou alguem mudou a observacao depois que o cliente abriu o modal.
        // Devolve o valor ATUAL pro cliente decidir.
        $stmtAtual = $pdo->prepare('SELECT observacao FROM colaboradores WHERE id = ?');
        $stmtAtual->execute([$id]);
        $valorAtual = $stmtAtual->fetchColumn();
        if ($valorAtual === false) erro('Colaborador nao encontrado.', 404);
        http_response_code(409);
        echo json_encode(['success' => false, 'conflito' => true, 'valor_atual' => $valorAtual]);
        exit;
    }

    echo json_encode(['success' => true]);
    exit;
}

// Observacao do Preposto numa solicitacao de cobertura (painel da linha em coberturas/solicitacao.html): so ele, e so enquanto
// ainda pode cancelar - as mesmas regras do cancelar. Dia comum: criado_em de TODOS os dias nas ultimas 24h (acao=remover).
// Vaga de desligamento/afastamento: 24h da abertura e sem Fim, pela ancora (acaoCancelar dos endpoints da vaga), gravando em
// todos os dias que o cancelar apagaria; os que o cron ainda vai criar copiam da ancora (estender-vagas-helpers.php).
if ($acao === 'salvar_observacao') {
    if ($tabelaNome !== 'solicitacoes_cobertura' || $papel !== 'preposto') erro('So o Preposto muda a observacao da solicitacao.', 403);
    $corpo = json_decode(file_get_contents('php://input'), true);
    if (!is_array($corpo)) erro('Corpo invalido, esperado um objeto JSON');
    $ids = is_array($corpo['ids'] ?? null) ? array_values(array_unique(array_map('intval', $corpo['ids']))) : [];
    if (count($ids) === 0 || min($ids) <= 0) erro('Parametro "ids" obrigatorio.');
    // Obrigatorio (texto; vazio ou null apaga a nota): sem a chave, um cliente com defeito apagaria a nota sem querer.
    if (!array_key_exists('observacao', $corpo) || ($corpo['observacao'] !== null && !is_string($corpo['observacao']))) {
        erro('Parametro "observacao" obrigatorio (texto).');
    }
    $observacao = trim((string) $corpo['observacao']);
    if (mb_strlen($observacao) > 300) erro('A observacao passa de 300 caracteres.');

    if ($centrosPermitidosEscrita !== null
        && in_array(false, linhasDentroDoEscopo($pdo, $tabelaNome, 'id', $ids, $centrosPermitidosEscrita), true)) {
        erro('Este registro esta fora do seu centro de custo.', 403);
    }

    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT matricula, motivo, (criado_em IS NOT NULL AND criado_em >= NOW() - INTERVAL 24 HOUR) AS na_janela
           FROM solicitacoes_cobertura WHERE id IN ($marcadores)"
    );
    $stmt->execute($ids);
    $linhas = $stmt->fetchAll();
    if (count($linhas) !== count($ids)) erro('Solicitacao nao encontrada.', 404);

    $tabelasVaga = ['desligamento' => 'solicitacoes_desligamento', 'afastamento_indeterminado' => 'solicitacoes_afastamento'];
    $motivo = $linhas[0]['motivo'];
    $matricula = $linhas[0]['matricula'];
    foreach ($linhas as $linha) {
        if ($linha['motivo'] !== $motivo || $linha['matricula'] !== $matricula) erro('Os dias enviados sao de solicitacoes diferentes.');
    }

    if (!isset($tabelasVaga[$motivo])) {
        foreach ($linhas as $linha) {
            if (!$linha['na_janela']) erro('So e possivel mudar a observacao ate 24h apos a abertura da solicitacao.', 403);
        }
        $onde = "id IN ($marcadores)";
        $paramsOnde = $ids;
        $detalhe = "titular $matricula, ids " . implode(',', $ids);
    } else {
        $ancora = (int) ($corpo['ancora'] ?? 0);
        if (!in_array($ancora, $ids, true)) erro('Parametro "ancora" invalido.');
        $stmtVaga = $pdo->prepare(
            "SELECT v.matricula_titular, v.fim_definido_em, (v.aberta_em >= NOW() - INTERVAL 24 HOUR) AS na_janela,
                    sc.data AS data_inicio
               FROM `{$tabelasVaga[$motivo]}` v
               JOIN solicitacoes_cobertura sc ON sc.id = v.solicitacao_cobertura_id
              WHERE v.solicitacao_cobertura_id = ?"
        );
        $stmtVaga->execute([$ancora]);
        $vaga = $stmtVaga->fetch();
        if (!$vaga) erro('Vaga nao encontrada.', 404);
        if ($vaga['fim_definido_em'] !== null) erro('Esta vaga ja teve o Fim definido.', 409);
        if (!$vaga['na_janela']) erro('So e possivel mudar a observacao ate 24h apos a abertura da solicitacao.', 403);
        // Os mesmos dias que o cancelar da vaga apaga. id >= ancora: uma vaga antiga do mesmo titular e motivo com dias depois do
        // inicio desta (ex.: fechada antes de comecar) foi criada antes, entao fica de fora.
        $onde = "matricula = ? AND motivo = ? AND id >= ? AND STR_TO_DATE(data, '%d/%m/%Y') >= STR_TO_DATE(?, '%d/%m/%Y')";
        $paramsOnde = [$vaga['matricula_titular'], $motivo, $ancora, $vaga['data_inicio']];
        $detalhe = "titular {$vaga['matricula_titular']}, vaga $ancora";
    }

    // Quem mudou a nota e quando ("editada por" nos paineis); gravar o mesmo texto nao conta. Antes de "observacao = ?": o MySQL
    // aplica o SET da esquerda para a direita, entao as comparacoes veem o texto antigo.
    $valorObservacao = $observacao === '' ? null : $observacao;
    $quemEditou = quemAbriuSolicitacao($pdo, $usuarioId, $papel)['criado_por_login'];
    $stmt = $pdo->prepare("UPDATE solicitacoes_cobertura
                              SET observacao_editada_por = IF(observacao <=> ?, observacao_editada_por, ?),
                                  observacao_editada_em = IF(observacao <=> ?, observacao_editada_em, NOW()),
                                  observacao = ?
                            WHERE $onde");
    $stmt->execute(array_merge([$valorObservacao, $quemEditou, $valorObservacao, $valorObservacao], $paramsOnde));
    // "titular X" vira o nome e "ids ..." some na tela de Logs (enriquecerDetalhe, logs.php).
    registrarAuditoria($pdo, $usuarioId, 'observacao_solicitacao', [
        'tabela' => 'solicitacoes_cobertura',
        'quantidade' => $stmt->rowCount(),
        'detalhe' => mb_substr($detalhe, 0, 500),
    ]);
    echo json_encode(['success' => true]);
    exit;
}

// Grava varios registros numa unica requisicao e transacao: ou grava tudo, ou nao grava nada.
const LOTE_MAXIMO = 500;

if ($acao === 'salvar_lote') {
    // Colaboradores em lote so pelo RH: nenhuma tela de Gestor/Preposto usa, e o lote nao tem as travas do salvar acima.
    if ($tabelaNome === 'colaboradores' && in_array($papel, ['gestor', 'preposto'], true)) erro('Colaboradores em lote so pelo RH.', 403);
    $corpo = json_decode(file_get_contents('php://input'), true);
    $registros = $corpo['registros'] ?? null;

    if (!is_array($registros)) erro('Parametro "registros" obrigatorio (array)');
    if (count($registros) === 0) {
        echo json_encode(['success' => true, 'ids' => [], 'total' => 0]);
        exit;
    }
    if (count($registros) > LOTE_MAXIMO) {
        erro('Lote grande demais: maximo de ' . LOTE_MAXIMO . ' registros por chamada.');
    }

    // Escala 12x36 par/impar (db.php): o INICIO de cada titular entre as linhas novas do lote (o 1o dia) cai na paridade dela, e
    // todas as linhas novas do titular tem a mesma escala (a tela manda uma so; chamada direta a API nao mistura).
    if ($tabelaNome === 'solicitacoes_cobertura') {
        $inicioPorTitular = [];
        $escalaPorTitular = [];
        foreach ($registros as $r) {
            if (!is_array($r) || !empty($r[$chave]) || !is_scalar($r['matricula'] ?? null) || !is_scalar($r['data'] ?? null)) continue;
            $titular = (string) $r['matricula'];
            $escalaLinha = $r['escala'] ?? null;
            if (array_key_exists($titular, $escalaPorTitular) && $escalaPorTitular[$titular] !== $escalaLinha) {
                erro('Todos os dias da solicitação precisam ter a mesma escala.');
            }
            $escalaPorTitular[$titular] = $escalaLinha;
            $dataBr = trim((string) $r['data']);
            $dia = DateTime::createFromFormat('!d/m/Y', $dataBr);
            if ($dia && (!isset($inicioPorTitular[$titular]) || $dia < $inicioPorTitular[$titular][0])) {
                $inicioPorTitular[$titular] = [$dia, $escalaLinha, $dataBr];
            }
        }
        foreach ($inicioPorTitular as [, $escalaInicio, $dataInicioBr]) {
            if ($msgParidade = erroParidadeEscala($escalaInicio, $dataInicioBr)) erro($msgParidade);
        }
    }

    // Trava por titular das linhas novas (N3 da analise de 2026-11-12, travarTitulares em db.php): 2 abas ou 2 cliques ao mesmo
    // tempo nao passam juntos pelo "1 linha por dia" - o segundo espera e cai no 409 normal. Solta depois do commit ou com a conexao.
    $travasLote = [];
    if ($tabelaNome === 'solicitacoes_cobertura') {
        $titularesNovos = [];
        foreach ($registros as $r) {
            if (is_array($r) && empty($r[$chave]) && is_scalar($r['matricula'] ?? null)) $titularesNovos[] = (string) $r['matricula'];
        }
        $travasLote = travarTitulares($pdo, $titularesNovos);
        if ($travasLote === null) erro('Outra solicitação deste colaborador está sendo gravada agora - tente de novo em instantes.', 409);
    }

    $pdo->beginTransaction();
    try {
        $ids = [];
        // Reaproveita o statement por "forma" (conjunto de colunas): numa importacao o prepare
        // acontece uma vez so.
        $preparados = [];
        $quemAbriuLote = null;
        $lotePorTitular = []; // id da solicitacao (novoLoteSolicitacao, db.php): 1 por titular da requisicao
        $linhasNovasSolicitacao = []; // pro Log por solicitacao e o aviso de urgente depois do commit

        foreach ($registros as $i => $registro) {
            if (!is_array($registro)) throw new Exception("registro $i nao e um objeto");

            $campos = [];
            foreach ($registro as $coluna => $valor) {
                if ($coluna === $chave || in_array($coluna, $colunasPermitidas, true)) {
                    $campos[$coluna] = $valor;
                }
            }
            if (count($campos) === 0) throw new Exception("registro $i sem nenhuma coluna valida");

            $temChave = isset($campos[$chave]) && $campos[$chave] !== '' && $campos[$chave] !== null;

            // Mesmo retrato congelado do acao=salvar (quem abriu: 1 consulta pro lote todo).
            if ($tabelaNome === 'solicitacoes_cobertura') {
                if (!$temChave && isset($campos['matricula'])) {
                    $campos = array_merge($campos, snapshotTitularSolicitacao($pdo, (string) $campos['matricula']));
                }
                if (!$temChave) {
                    $campos = array_merge($campos, $quemAbriuLote ??= quemAbriuSolicitacao($pdo, $usuarioId, $papel));
                    $campos['lote_id'] = $lotePorTitular[(string) ($campos['matricula'] ?? '')] ??= novoLoteSolicitacao();
                    $linhasNovasSolicitacao[] = $campos;
                }
            }

            // Mesmas regras do acao=salvar (validarSolicitacaoCobertura); relanca como
            // Exception("registro $i: ...") pro catch abaixo, que desfaz o lote inteiro.
            try {
                validarSolicitacaoCobertura($pdo, $papel, $tabelaNome, $campos, $temChave, $centrosPermitidosEscrita);
            } catch (RuntimeException $e) {
                throw new Exception("registro $i: " . $e->getMessage());
            }

            if ($temChave) {
                $valorChave = $campos[$chave];
                unset($campos[$chave]);
                if (count($campos) === 0) { $ids[] = $valorChave; continue; }

                $forma = 'u|' . implode(',', array_keys($campos));
                if (!isset($preparados[$forma])) {
                    $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($campos)));
                    $preparados[$forma] = $pdo->prepare("UPDATE `$tabelaNome` SET $sets WHERE `$chave` = ?");
                }
                $preparados[$forma]->execute(array_merge(array_values($campos), [$valorChave]));
                $ids[] = $valorChave;
            } else {
                $forma = 'i|' . implode(',', array_keys($campos));
                if (!isset($preparados[$forma])) {
                    $colunasNomes = array_keys($campos);
                    // Mesmo criado_em = NOW() do INSERT de acao=salvar.
                    if ($tabelaNome === 'solicitacoes_cobertura' && $chave === 'id') {
                        $colunasNomes[] = 'criado_em';
                    }
                    $colunasSql = implode(', ', array_map(fn($c) => $c === 'criado_em' ? '`criado_em`' : "`$c`", $colunasNomes));
                    $marcadoresPartes = array_map(fn($c) => $c === 'criado_em' ? 'NOW()' : '?', $colunasNomes);
                    $marcadores = implode(', ', $marcadoresPartes);
                    if ($chave === 'id') {
                        $preparados[$forma] = $pdo->prepare(
                            "INSERT INTO `$tabelaNome` ($colunasSql) VALUES ($marcadores)"
                        );
                    } else {
                        $atualizacoes = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", array_keys($campos)));
                        $preparados[$forma] = $pdo->prepare(
                            "INSERT INTO `$tabelaNome` ($colunasSql) VALUES ($marcadores)
                             ON DUPLICATE KEY UPDATE $atualizacoes"
                        );
                    }
                }
                $preparados[$forma]->execute(array_values($campos));
                $ids[] = $chave === 'id' ? $pdo->lastInsertId() : ($registro[$chave] ?? null);
            }
        }

        $pdo->commit();
        liberarTravas($pdo, $travasLote);

        if ($tabelaNome === 'solicitacoes_cobertura') {
            // Uma entrada no Log por solicitacao, com o motivo na acao (abrir_solicitacao_falta...), e o aviso de urgente (db.php).
            foreach (solicitacoesDasLinhas($linhasNovasSolicitacao) as $solicitacao) {
                registrarAuditoriaSolicitacao($pdo, $usuarioId, 'abrir', $solicitacao);
                avisarSolicitacaoUrgente($pdo, $usuarioId, $solicitacao);
                avisarTitularCobrindoNoDia($pdo, $usuarioId, $solicitacao);
            }
        } else {
            registrarAuditoria($pdo, $usuarioId, 'gravar_lote', [
                'tabela' => $tabelaNome,
                'quantidade' => count($ids),
            ]);
        }

        echo json_encode([
            'success' => true,
            'ids' => $ids,
            'total' => count($ids),
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        // O detalhe do SQL so vai pro log; o cliente recebe a posicao do registro que quebrou, sem
        // expor a estrutura do banco.
        error_log('Falha no lote em "' . $tabelaNome . '": ' . $e->getMessage());
        // Erro do banco vira mensagem generica; as validacoes deste arquivo tem mensagem propria,
        // segura de mostrar.
        $detalhe = ($e instanceof PDOException)
            ? 'o banco recusou a gravacao (detalhe no log do servidor)'
            : $e->getMessage();
        erro('Falha ao gravar o lote - NENHUM registro foi salvo. Motivo: ' . $detalhe, 500);
    }
    exit;
}

if ($acao === 'remover') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    $chaves = $corpo['chaves'] ?? [];
    if (!is_array($chaves) || count($chaves) === 0) erro('Parametro "chaves" obrigatorio (array)');
    // Tirar colaborador da lista e do RH/Gestor (a tela do Preposto nao tem o Remover; nem chamando a API direto, que apagaria
    // colaborador importado do proprio centro, com o que so o CGV guarda dele).
    if ($tabelaNome === 'colaboradores' && $papel === 'preposto') erro('O Preposto nao remove colaborador.', 403);

    // Origem da exclusao opcional (ex: "exportar_e_limpar", "remover_duplicadas"): fica no log pra
    // diferenciar limpeza de periodo de exclusao manual.
    $contexto = isset($corpo['contexto']) ? substr((string) $corpo['contexto'], 0, 60) : null;

    // Gestor restrito: cada id precisa pertencer a um centro liberado (senao apagaria fora do
    // escopo chamando a API direto). 1 query pro lote (linhasDentroDoEscopo).
    if ($centrosPermitidosEscrita !== null) {
        $mapaEscopoRemover = linhasDentroDoEscopo($pdo, $tabelaNome, $chave, $chaves, $centrosPermitidosEscrita);
        foreach ($chaves as $chaveCandidata) {
            if (!($mapaEscopoRemover[$chaveCandidata] ?? false)) {
                erro('Este registro esta fora do seu centro de custo.', 403);
            }
        }
    }

    // Solicitacao: o Preposto cancela ate 24h da abertura (abaixo) e o RH/Administrador cancela com motivo a que foi aberta por
    // engano e vista depois (item 7 da analise de 2026-11-12) - so dias sem volante (o PFM paga quem esta designado: corrigir para
    // SEM COBERTURA antes) e de periodo do PFM ainda aberto (ajuste ate o dia 3). Gestor nao apaga: nenhuma tela usa e, pela API,
    // apagaria dias ja pagos (item 11).
    $linhasRemovidas = [];
    $motivoCancelamento = '';
    if ($tabelaNome === 'solicitacoes_cobertura') {
        if ($papel === 'gestor') erro('A solicitação é cancelada pelo Preposto (até 24h da abertura) ou pelo RH, com o motivo.', 403);
        // Transacao com FOR UPDATE ate o DELETE (commit abaixo): uma designacao que chegue no meio espera e nao tem o dia apagado
        // depois de conferido "sem volante". erro() sai sem commit e a conexao fechada desfaz.
        $pdo->beginTransaction();
        $marcadoresSol = implode(',', array_fill(0, count($chaves), '?'));
        $stmtSol = $pdo->prepare("SELECT id, centro_custo, matricula, nome, data, motivo, matricula_vol, nome_vol, escala, observacao, lote_id,
                                         ausencia_matricula_vol, ausencia_nome_vol
                                    FROM solicitacoes_cobertura WHERE id IN ($marcadoresSol) FOR UPDATE");
        $stmtSol->execute($chaves);
        $linhasRemovidas = $stmtSol->fetchAll();
        if (in_array($papel, ['rh', 'administrador'], true)) {
            $motivoCancelamento = trim(preg_replace('/\s+/', ' ', (string) ($corpo['motivo_cancelamento'] ?? '')));
            if (mb_strlen($motivoCancelamento) < 5) erro('Informe o motivo do cancelamento (pelo menos 5 caracteres).');
            if (mb_strlen($motivoCancelamento) > 200) erro('O motivo do cancelamento passa de 200 caracteres.');
            foreach ($linhasRemovidas as $l) {
                if (in_array($l['motivo'], ['desligamento', 'afastamento_indeterminado'], true)) {
                    erro('Vaga de desligamento/afastamento é cancelada pela própria vaga (painel de cobertura dela).', 403);
                }
            }
            // Sem volante, sem ausencia registrada e so periodo do PFM aberto (db.php).
            if ($impedimento = impedimentoCancelamentoRh($linhasRemovidas, date('Y-m-d'))) erro($impedimento[0], $impedimento[1]);
        }
    }

    // "remover" (cancelar) e, com o salvar_observacao, o unico caminho de escrita do Preposto numa linha ja criada de
    // solicitacoes_cobertura (nunca editar), e so dentro de 24h da criacao. NOW() do MySQL.
    if ($papel === 'preposto' && $tabelaNome === 'solicitacoes_cobertura') {
        $marcadoresJanela = implode(',', array_fill(0, count($chaves), '?'));
        $stmtJanela = $pdo->prepare(
            "SELECT COUNT(*) FROM `solicitacoes_cobertura`
              WHERE `$chave` IN ($marcadoresJanela)
                AND (criado_em IS NULL OR criado_em < NOW() - INTERVAL 24 HOUR)"
        );
        $stmtJanela->execute($chaves);
        if ((int) $stmtJanela->fetchColumn() > 0) {
            erro('So e possivel cancelar ate 24h apos a abertura da solicitacao.', 403);
        }

        // Vaga de desligamento/afastamento se cancela inteira pelo endpoint dela (acao=cancelar, 24h
        // contadas da abertura da VAGA); por aqui, a linha que o cron cria todo dia (criado_em sempre
        // recente) deixaria apagar um dia solto de vaga antiga.
        $stmtVaga = $pdo->prepare(
            "SELECT COUNT(*) FROM `solicitacoes_cobertura`
              WHERE `$chave` IN ($marcadoresJanela) AND motivo IN ('desligamento', 'afastamento_indeterminado')"
        );
        $stmtVaga->execute($chaves);
        if ((int) $stmtVaga->fetchColumn() > 0) {
            erro('Solicitacao de desligamento/afastamento e cancelada pela propria vaga.', 403);
        }

        // Linha com ausencia registrada nao sai nem nas 24h (revisao de 2026-11-13): e a prova de que o volante nao cobriu (db.php).
        if ($impedimento = impedimentoAusenciaNaLinha($linhasRemovidas, 'Fale com o RH.')) erro($impedimento[0], $impedimento[1]);

        // Ja tinha volante designado: avisa quem designou antes do DELETE, com as linhas ainda no banco.
        notificarCancelamentoDeCobertura($pdo, $chaves, $usuarioId);
    }

    // Colaborador que veio do Protheus e esta na lista so pelo motivo da inclusao ("Adicionar colaborador"): tirar da lista =
    // limpar o motivo. Apagar a linha so a faria voltar sem motivo no sync seguinte e sumir ate la das outras telas (Equipe do
    // Preposto, titulares).
    $motivoLimpo = 0;
    $coberturaSaf = []; // matricula => ultimo dia em que cobre ferias do SAF (inclusive trecho de troca e posto de origem)
    if ($tabelaNome === 'colaboradores') {
        // Linha MANUAL de quem cobre ferias do SAF (decisao do usuario, 2026-11-11): ainda cobrindo, o Remover recusa (o PFM pararia de
        // pagar e o SAF continuaria com a pessoa); cobertura ja terminada conta como historico abaixo (fica desligada de hoje).
        $avisoSaf = null;
        foreach (feriasSafComVolante($pdo, $avisoSaf) as $f) {
            if (($f['precisa_cobertura'] ?? 'Sim') !== 'Sim') continue;
            foreach ([$f['matricula_vol'] ?? '', $f['volante_matricula'] ?? '', $f['substituto_matricula'] ?? ''] as $m) {
                if (($m = trim((string) $m)) !== '') $coberturaSaf[$m] = max($coberturaSaf[$m] ?? '', (string) $f['ferias_fim']);
            }
        }
        $stmt = $pdo->prepare("SELECT matricula, nome FROM colaboradores WHERE origem = 'MANUAL' AND id IN (" . implode(',', array_fill(0, count($chaves), '?')) . ')');
        $stmt->execute($chaves);
        $manuais = $stmt->fetchAll();
        // Arquivo do SAF ausente, invalido ou vencido (integracao configurada): nao da para saber se cobre ferias - nao remove manual.
        if ($manuais && $avisoSaf !== null && (string) SAF_COBERTURA_JSON !== '') {
            erro('Não foi possível conferir agora as coberturas de férias do SAF (' . preg_replace('/^.*: /u', '', rtrim($avisoSaf, '.'))
                . '). Tente remover mais tarde.', 503);
        }
        if ($coberturaSaf) {
            foreach ($manuais as $l) {
                $ate = $coberturaSaf[trim((string) $l['matricula'])] ?? '';
                if ($ate >= date('Y-m-d')) {
                    erro("{$l['nome']} ainda cobre férias do SAF (até " . date('d/m/Y', strtotime($ate)) . '): troque quem cobre na Previsão antes de remover.', 409);
                }
            }
        }
        $stmt = $pdo->prepare("SELECT id FROM colaboradores WHERE origem = 'IMPORTADO' AND COALESCE(motivo_inclusao, '') <> '' AND id IN ("
            . implode(',', array_fill(0, count($chaves), '?')) . ')');
        $stmt->execute($chaves);
        $soMotivo = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($soMotivo) {
            $pdo->prepare("UPDATE colaboradores SET motivo_inclusao = '', centro_destino = NULL, inicio_aviso = '', fim_aviso = '', aviso_situacao = NULL WHERE id IN ("
                . implode(',', array_fill(0, count($soMotivo), '?')) . ')')->execute($soMotivo);
            $motivoLimpo = count($soMotivo);
            $chaves = array_values(array_filter($chaves, fn($k) => !in_array((int) $k, $soMotivo, true)));
        }

        // Linha MANUAL de quem ja cobriu, foi coberto ou teve base: nunca apagada (o PFM precisa do titular e de quem cobriu), como
        // no fim do aviso pelo sync - perde o motivo e o aviso, fica desligada de hoje (a tela esconde) e mantem o centro de destino
        // (o Gestor desse destino continua vendo as coberturas que alocou). Desligamento ja passado fica como esta.
        if ($chaves) {
            $temAusencia = colunaExiste($pdo, 'solicitacoes_cobertura', 'ausencia_matricula_vol');
            $historico = 'EXISTS (SELECT 1 FROM solicitacoes_cobertura s WHERE s.matricula = c.matricula OR s.matricula_vol = c.matricula'
                . ($temAusencia ? ' OR s.ausencia_matricula_vol = c.matricula' : '') . ')'
                . (tabelaExiste($pdo, 'colaborador_bases') ? ' OR EXISTS (SELECT 1 FROM colaborador_bases b WHERE b.matricula = c.matricula)' : '');
            $doSaf = array_keys($coberturaSaf); // cobertura do SAF ja terminada (a que nao terminou foi recusada acima)
            if ($doSaf) $historico .= ' OR c.matricula IN (' . implode(',', array_fill(0, count($doSaf), '?')) . ')';
            $stmt = $pdo->prepare("SELECT c.id, c.desligamento FROM colaboradores c WHERE c.origem = 'MANUAL' AND ($historico) AND c.id IN ("
                . implode(',', array_fill(0, count($chaves), '?')) . ')');
            $stmt->execute(array_merge(array_map('strval', $doSaf), $chaves));
            $hoje = new DateTime('today');
            $comHistorico = [];
            foreach ($stmt->fetchAll() as $l) {
                $deslig = DateTime::createFromFormat('!d/m/Y', (string) $l['desligamento']);
                $pdo->prepare("UPDATE colaboradores SET motivo_inclusao = '', inicio_aviso = '', fim_aviso = '', aviso_situacao = NULL,
                                      desligamento = ? WHERE id = ?")
                    ->execute([$deslig && $deslig <= $hoje ? $deslig->format('d/m/Y') : $hoje->format('d/m/Y'), $l['id']]);
                $comHistorico[] = (int) $l['id'];
            }
            $motivoLimpo += count($comHistorico);
            $chaves = array_values(array_filter($chaves, fn($k) => !in_array((int) $k, $comHistorico, true)));
        }
    }

    $removidos = 0;
    if ($chaves) {
        $marcadores = implode(',', array_fill(0, count($chaves), '?'));
        $sql = "DELETE FROM `$tabelaNome` WHERE `$chave` IN ($marcadores)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($chaves);
        $removidos = $stmt->rowCount();
    }
    if ($pdo->inTransaction()) $pdo->commit(); // a transacao da solicitacao (FOR UPDATE acima)

    if ($tabelaNome === 'solicitacoes_cobertura') {
        // Uma entrada por solicitacao, com o motivo na acao (cancelar_solicitacao_falta...) e, do RH, o motivo do cancelamento.
        foreach (solicitacoesDasLinhas($linhasRemovidas) as $solicitacao) {
            registrarAuditoriaSolicitacao($pdo, $usuarioId, 'cancelar', $solicitacao,
                $motivoCancelamento !== '' ? "motivo do cancelamento: $motivoCancelamento" : '');
            // Cancelada pelo RH: o Preposto do centro fica sabendo, com o motivo (db.php).
            if ($motivoCancelamento !== '') avisarPrepostoCancelamentoRh($pdo, $usuarioId, $solicitacao, $motivoCancelamento);
        }
    } else {
        registrarAuditoria($pdo, $usuarioId, 'remover', [
            'tabela' => $tabelaNome,
            'quantidade' => $removidos + $motivoLimpo,
            'detalhe' => ($contexto ? "origem: $contexto" : 'exclusao de registros')
                . ($motivoLimpo ? " ($motivoLimpo so tirado(s) da lista, sem apagar: motivo da inclusao limpo; manual com cobertura/base fica desligada)" : ''),
        ]);
    }

    echo json_encode(['success' => true, 'removidos' => $removidos + $motivoLimpo]);
    exit;
}

// ------------------------------------------------------------
// "ADICIONAR COLABORADOR": centros volante de destino e aviso vencido (so RH/Gestor)
// ------------------------------------------------------------
// Lista do campo "Centro de custo volante" (Adicionar e lapis da ficha): todo centro volante/intermitente; o Gestor so ve os da
// Cobertura dele (a mesma regra que acao=salvar confere).
if ($acao === 'centros_destino' && $tabelaNome === 'colaboradores') {
    if (!$exigirVolante) erro('Sem permissao.', 403);
    $stmt = $pdo->query('SELECT codigo, descricao FROM centros_custo ORDER BY descricao, codigo');
    $lista = [];
    foreach ($stmt->fetchAll() as $cc) {
        if (!centroCustoEhVolante((string) $cc['codigo'], (string) ($cc['descricao'] ?? ''))) continue;
        if ($papel === 'gestor' && $coberturaGestor !== null && !in_array($cc['codigo'], $coberturaGestor, true)) continue;
        $lista[] = $cc;
    }
    echo json_encode(['dados' => $lista]);
    exit;
}

// Painel do aviso vencido de quem ainda esta ativo no Protheus (aviso_situacao = PENDENTE, gravado pelo sync passo 4):
// "aguardar" = Manter e aguardar (AGUARDANDO: o painel nao pergunta mais; quando o Protheus lancar a demissao o sync acerta a
// data); "alterar" = fim novo, de hoje em diante (volta a valer; vencendo de novo, o painel volta). A 1a resposta vale para
// todos: o UPDATE so pega a linha ainda PENDENTE.
if ($acao === 'aviso_vencido' && $tabelaNome === 'colaboradores') {
    if (!$exigirVolante) erro('Sem permissao.', 403);
    $corpo = json_decode(file_get_contents('php://input'), true);
    $id = (int) ($corpo['id'] ?? 0);
    $resposta = (string) ($corpo['resposta'] ?? '');
    if ($id <= 0 || !in_array($resposta, ['aguardar', 'alterar'], true)) erro('Resposta invalida.');
    if ($centrosPermitidosEscrita !== null && !linhaDentroDoEscopo($pdo, 'colaboradores', 'id', $id, $centrosPermitidosEscrita)) {
        erro('Este colaborador esta fora do seu centro de custo.', 403);
    }
    $stmt = $pdo->prepare('SELECT matricula, nome, inicio_aviso, fim_aviso FROM colaboradores WHERE id = ?');
    $stmt->execute([$id]);
    $linha = $stmt->fetch();
    if (!$linha) erro('Colaborador nao encontrado.', 404);

    if ($resposta === 'alterar') {
        $novoFim = DateTime::createFromFormat('!d/m/Y', (string) ($corpo['fim_aviso'] ?? ''));
        $inicio = DateTime::createFromFormat('!d/m/Y', (string) $linha['inicio_aviso']);
        if (!$novoFim || $novoFim->format('d/m/Y') !== ($corpo['fim_aviso'] ?? '')) erro('Informe a nova data de fim do aviso.');
        if ($novoFim < new DateTime('today')) erro('O fim do aviso precisa ser de hoje em diante.');
        if ($inicio && $novoFim < $inicio) erro('O fim do aviso nao pode ser antes do inicio.');
        $upd = $pdo->prepare("UPDATE colaboradores SET fim_aviso = ?, aviso_situacao = NULL WHERE id = ? AND aviso_situacao = 'PENDENTE'");
        $upd->execute([$novoFim->format('d/m/Y'), $id]);
    } else {
        $upd = $pdo->prepare("UPDATE colaboradores SET aviso_situacao = 'AGUARDANDO' WHERE id = ? AND aviso_situacao = 'PENDENTE'");
        $upd->execute([$id]);
    }
    if ($upd->rowCount() === 0) erro('Outro usuario ja respondeu sobre este aviso.', 409);

    registrarAuditoria($pdo, $usuarioId, 'aviso_vencido', [
        'tabela' => 'colaboradores',
        'quantidade' => 1,
        'detalhe' => "{$linha['matricula']} - {$linha['nome']}: aviso vencido em {$linha['fim_aviso']} sem demissao no Protheus; "
            . ($resposta === 'alterar' ? 'fim alterado para ' . $novoFim->format('d/m/Y') : 'mantido, aguardando o Protheus'),
    ]);
    echo json_encode(['success' => true]);
    exit;
}

// ------------------------------------------------------------
// CONSULTAR O LOG DE AUDITORIA
// ------------------------------------------------------------
// So o Administrador, como a pagina de Logs (logs.php, que e o que a tela usa): pelo $PAPEIS_POR_TABELA acima qualquer papel
// (ate o Preposto, com tabela=colaboradores) lia as ultimas 500 linhas.
if ($acao === 'auditoria') {
    if ($papel !== 'administrador') erro('So o Administrador consulta a auditoria.', 403);
    try {
        $limite = min(max((int) ($_GET['limite'] ?? 100), 1), 500);
        $stmt = $pdo->prepare(
            'SELECT em, usuario, acao, tabela, quantidade, lote_id, detalhe
               FROM auditoria ORDER BY id DESC LIMIT ' . $limite
        );
        $stmt->execute();
        echo json_encode(['dados' => $stmt->fetchAll()]);
    } catch (Throwable $e) {
        // Sem a tabela ainda, devolve vazio em vez de erro.
        echo json_encode(['dados' => [], 'aviso' => 'Log de auditoria ainda nao criado no banco.']);
    }
    exit;
}

erro('Acao invalida: ' . $acao);
