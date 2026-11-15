<?php
// ============================================================
// ENDPOINT DE USUARIOS/LOGIN (separado do dados.php genérico de proposito,
// pra nunca devolver o hash da senha pro cliente).
// Uso: login.php?acao=login|listar|listar_diretorio|salvar|remover
// ============================================================
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

function erro($msg, $status = 400) {
    http_response_code($status);
    echo json_encode(['error' => $msg]);
    exit;
}

// Politica minima de senha (so pra usuario novo ou troca de senha; contas existentes com senha
// mais fraca continuam funcionando). 8 caracteres reduz senha trivial sem regra complexa demais.
function validarForcaSenha($senha) {
    if (mb_strlen($senha) < 8) {
        erro('Senha precisa ter pelo menos 8 caracteres.');
    }
}

$acao = $_GET['acao'] ?? '';
$pdo = conectarBanco();

// O login em si nao exige sessao; as demais acoes (painel de usuarios) exigem sessao valida de
// RH/Administrador.
if ($acao === 'login') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    $usuario = trim($corpo['usuario'] ?? '');
    $senha = (string) ($corpo['senha'] ?? '');
    if ($usuario === '' || $senha === '') erro('Usuario e senha sao obrigatorios');

    // Usuario normalizado (minusculo) e a CHAVE do limite de tentativas ("Admin"/"ADMIN" = mesmo
    // bloqueio); limites em db.php.
    $usuarioNormalizado = mb_strtolower($usuario);
    $minutosRestantes = minutosBloqueioLogin($pdo, $usuarioNormalizado);
    if ($minutosRestantes > 0) {
        erro("Muitas tentativas de login. Tente novamente em $minutosRestantes minuto(s).", 429);
    }

    $stmt = $pdo->prepare('SELECT id, usuario, senha_hash FROM usuarios WHERE LOWER(usuario) = LOWER(?)');
    $stmt->execute([$usuario]);
    $linha = $stmt->fetch();

    // senha_hash='' = conta do directory USC: valida via LDAP (password_verify devolve false pra hash
    // vazio, de proposito).
    $senhaValida = $linha
        ? ($linha['senha_hash'] !== '' ? password_verify($senha, $linha['senha_hash']) : autenticarViaLDAP($linha['usuario'], $senha))
        : false;

    if (!$senhaValida) {
        registrarTentativaLogin($pdo, $usuarioNormalizado, false);
        erro('Usuario ou senha invalidos', 401);
    }
    registrarTentativaLogin($pdo, $usuarioNormalizado, true);

    // 1 sessao ativa por usuario: novo login substitui a anterior (ver criarSessao) em vez de
    // recusar, pra nao trancar quem fechou a aba sem Sair.
    $token = criarSessao($pdo, $linha['id']);

    $papel = papelDoUsuario($pdo, $linha['id']);
    $papeisExtra = papeisExtraDoUsuario($pdo, $linha['id']);

    registrarAuditoria($pdo, $linha['id'], 'login', ['detalhe' => "papel: $papel"]);

    echo json_encode([
        'success' => true,
        'usuario' => $linha['usuario'],
        'papel'   => $papel,
        // Perfil multiplo (alternarPerfilAtivo, db.php): vazio pra quase todo mundo; o frontend usa pra
        // decidir se mostra a opcao de alternar perfil.
        'papeisExtra' => $papeisExtra,
        // isAdmin = RH OU Administrador (acesso total, ver verificarSessaoAdmin); index.html usa pra
        // abrir o painel de usuarios.
        'isAdmin' => in_array($papel, ['rh', 'administrador'], true),
        'token' => $token,
    ]);
    exit;
}

// Troca o papel ativo NESTA sessao (alternarPerfilAtivo, db.php) - qualquer usuario logado; a
// funcao valida que o papel e um que a pessoa tem.
if ($acao === 'alternar_perfil') {
    $usuarioIdSessao = verificarSessao($pdo);
    $corpo = json_decode(file_get_contents('php://input'), true);
    $papelDesejado = trim((string) ($corpo['papel'] ?? ''));
    if ($papelDesejado === '') erro('Parametro "papel" obrigatorio.');

    $papelAplicado = alternarPerfilAtivo($pdo, $usuarioIdSessao, $papelDesejado);
    registrarAuditoria($pdo, $usuarioIdSessao, 'alternar_perfil', ['detalhe' => "papel ativo: $papelAplicado"]);

    echo json_encode(['success' => true, 'papel' => $papelAplicado]);
    exit;
}

// Encerra a sessao no servidor (botao de sair) - so exige o proprio token
// que esta sendo encerrado, nada mais.
if ($acao === 'logout') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    encerrarSessao($pdo, (string) ($corpo['token'] ?? ''));
    echo json_encode(['success' => true]);
    exit;
}

// Pulso do JS (filtro.js): so renova a expiracao da sessao (verificarSessao ja faz isso). Sem
// ele a sessao caduca (SESSAO_DURACAO_SEGUNDOS em db.php), e fechar a aba "desloga". Qualquer
// usuario logado.
if ($acao === 'heartbeat') {
    verificarSessao($pdo);
    echo json_encode(['success' => true]);
    exit;
}

// Acoes abaixo = painel de administracao de usuarios: exigem sessao valida de RH/Administrador.
$usuarioIdLogado = verificarSessaoAdmin($pdo);

// Impede o sistema ficar sem nenhum usuario RH ou Administrador (ninguem mais gerenciaria
// usuarios). Chamada sempre dentro de transacao do chamador (acao=remover): FOR UPDATE trava as
// linhas ate o commit, pra 2 remocoes concorrentes nao passarem juntas na checagem e zerarem o
// total.
function verificarNaoEhUltimoRH($pdo, $idsAfetados, $acaoDescricao) {
    $idsAfetados = is_array($idsAfetados) ? $idsAfetados : [$idsAfetados];
    // RH e Administrador contam juntos: o Administrador (que nunca e
    // excluido) tambem gerencia usuarios.
    $totalRH = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE papel IN ('rh', 'administrador') FOR UPDATE")->fetchColumn();
    $marcadores = implode(',', array_fill(0, count($idsAfetados), '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE papel IN ('rh', 'administrador') AND id IN ($marcadores)");
    $stmt->execute($idsAfetados);
    $rhAfetados = (int) $stmt->fetchColumn();
    if ($rhAfetados > 0 && $totalRH - $rhAfetados <= 0) {
        $pdo->rollBack();
        erro("Nao e possivel $acaoDescricao: precisa sobrar pelo menos 1 usuario RH ou Administrador no sistema.", 409);
    }
}

if ($acao === 'listar') {
    // Nunca devolve senha_hash pro cliente.
    $temPapel = colunaExiste($pdo, 'usuarios', 'papel');
    // senha_local: conta com senha propria no CGV (1) ou do directory USC,
    // que autentica por LDAP (0) - a tela usa pra oferecer "Alterar senha".
    $campos = ($temPapel ? 'id, usuario, papel' : 'id, usuario') . ", (senha_hash <> '') AS senha_local";
    $stmt = $pdo->query("SELECT $campos FROM usuarios ORDER BY usuario");
    $linhas = $stmt->fetchAll();

    // normalizarPapel traduz valor antigo (admin/operador/leitura); o fallback (sem coluna ou sem
    // papel gravado) segue papelDoUsuario() em db.php: "admin" vira rh, o resto gestor.
    foreach ($linhas as &$linha) {
        $linha['papel'] = normalizarPapel($linha['papel'] ?? null)
            ?: (strtolower($linha['usuario']) === 'admin' ? 'rh' : 'gestor');
    }
    unset($linha);

    // Papeis EXTRAS de cada usuario (usuario_papeis_extra, ate 2) - consulta agrupada em PHP, pro
    // picker de Perfil de usuarios.html marcar os checkboxes.
    $papeisExtraPorUsuario = [];
    if (tabelaExiste($pdo, 'usuario_papeis_extra')) {
        $stmt = $pdo->query('SELECT usuario_id, papel FROM usuario_papeis_extra ORDER BY papel');
        foreach ($stmt->fetchAll() as $linhaExtra) {
            $papeisExtraPorUsuario[$linhaExtra['usuario_id']][] = $linhaExtra['papel'];
        }
    }
    foreach ($linhas as &$linha) {
        $linha['papeis_extra'] = $papeisExtraPorUsuario[$linha['id']] ?? [];
    }
    unset($linha);

    // Centros de custo de cada Gestor (usuario_centros_custo): 1 consulta agrupada em PHP. Tabela
    // inexistente (migracao nao rodou) = centros_custo=[].
    // Gestor E Preposto: "centros_custo" e a lista do Preposto e "centros_custo_gestor" a do Gestor (usuarios.html mostra
    // um seletor pra cada); so um dos dois: "centros_custo" e a dele (o Gestor sozinho recebe a mesma nos dois campos).
    $porUsuario = [];
    if (tabelaExiste($pdo, 'usuario_centros_custo')) {
        $stmt = $pdo->query('SELECT usuario_id, papel, centro_custo FROM usuario_centros_custo ORDER BY centro_custo');
        foreach ($stmt->fetchAll() as $linhaCentro) {
            $porUsuario[$linhaCentro['usuario_id']][$linhaCentro['papel']][] = $linhaCentro['centro_custo'];
        }
    }
    foreach ($linhas as &$linha) {
        $doUsuario = $porUsuario[$linha['id']] ?? [];
        // Pelo papel que a pessoa TEM: linha de papel ja retirado nunca volta pra tela.
        $linha['centros_custo'] = $doUsuario[papelParaCentroCustoRecebido($linha['papel'], $linha['papeis_extra']) ?? ''] ?? [];
        $linha['centros_custo_gestor'] = $doUsuario['gestor'] ?? [];
    }
    unset($linha);

    // Centros de custo que COBREM um Preposto (preposto_centro_cobertura) -
    // lista independente da de cima, mesmo padrao de consulta agrupada.
    $porUsuarioCobre = [];
    if (tabelaExiste($pdo, 'preposto_centro_cobertura')) {
        $stmt = $pdo->query('SELECT usuario_id, centro_custo_cobre FROM preposto_centro_cobertura ORDER BY centro_custo_cobre');
        foreach ($stmt->fetchAll() as $linhaCobre) {
            $porUsuarioCobre[$linhaCobre['usuario_id']][] = $linhaCobre['centro_custo_cobre'];
        }
    }
    foreach ($linhas as &$linha) {
        $linha['centro_custo_cobre'] = $porUsuarioCobre[$linha['id']] ?? [];
    }
    unset($linha);

    // Pool de volante/intermitente do Gestor (gestor_centro_cobertura), no campo
    // "centro_custo_cobre_gestor" (nome diferente do do Preposto: quem tem os 2 papeis tem as 2 listas).
    $porUsuarioCoberturaGestor = [];
    if (tabelaExiste($pdo, 'gestor_centro_cobertura')) {
        $stmt = $pdo->query('SELECT usuario_id, centro_custo_cobre FROM gestor_centro_cobertura ORDER BY centro_custo_cobre');
        foreach ($stmt->fetchAll() as $linhaCoberturaGestor) {
            $porUsuarioCoberturaGestor[$linhaCoberturaGestor['usuario_id']][] = $linhaCoberturaGestor['centro_custo_cobre'];
        }
    }
    foreach ($linhas as &$linha) {
        $linha['centro_custo_cobre_gestor'] = $porUsuarioCoberturaGestor[$linha['id']] ?? [];
    }
    unset($linha);

    echo json_encode(['dados' => $linhas]);
    exit;
}

// Lista do diretorio (API USC, ou LDAP sem USC_API_URL) pro picker de
// "+ Novo usuario" - so quem AINDA NAO esta cadastrado (case-insensitive).
if ($acao === 'listar_diretorio') {
    if (!diretorioConfigurado()) {
        erro('Integracao com o diretorio de usuarios nao configurada (USC_API_URL ou LDAP_BIND_DN ausentes). Nao e possivel listar os usuarios.', 503);
    }
    $usuariosDiretorio = buscarUsuariosDiretorio();
    if ($usuariosDiretorio === null) {
        erro('O diretorio de usuarios nao respondeu. Tente novamente em instantes.', 502);
    }
    $jaCadastrados = array_map('mb_strtolower',
        $pdo->query('SELECT usuario FROM usuarios')->fetchAll(PDO::FETCH_COLUMN));
    $disponiveis = array_values(array_filter($usuariosDiretorio,
        fn($u) => !in_array(mb_strtolower($u['usuario']), $jaCadastrados, true)));
    echo json_encode(['dados' => $disponiveis]);
    exit;
}

$PAPEIS_VALIDOS = ['rh', 'administrador', 'gestor', 'preposto'];

// Sem acesso ao painel: popula o seletor de centro de custo/cobertura de Gestor/Preposto. Fonte:
// tabela centros_custo (centros ativos no Protheus, sync-protheus-centros-custo.php). O filtro de
// "so volante/intermitente" (seletor de Cobertura; exclusao no de Centro de Custo do Preposto) e
// feito no FRONTEND (usuarios.html). tem_colaborador=1: so centros com pelo menos 1 funcionario
// ATIVO no Protheus na ultima sync (sem categorias puramente contabeis, ex: "DIVIDENDOS").
if ($acao === 'listar_centros_custo') {
    $stmt = $pdo->query(
        "SELECT codigo FROM centros_custo WHERE tem_colaborador = 1 ORDER BY codigo"
    );
    echo json_encode(['dados' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
    exit;
}

// Decide de qual papel e a lista "centros_custo": com Gestor e Preposto juntos ela e do Preposto e a do Gestor vem
// em "centros_custo_gestor" (usuarios.html tem um seletor pra cada; ver usuario_centros_custo.papel em schema.sql).
// null = nem gestor nem preposto.
function papelParaCentroCustoRecebido(string $papel, array $papeisExtra): ?string {
    $todos = array_unique(array_merge([$papel], $papeisExtra));
    if (in_array('preposto', $todos, true)) return 'preposto';
    if (in_array('gestor', $todos, true)) return 'gestor';
    return null;
}

// Substitui os centros de custo de um Gestor/Preposto pelo conjunto informado (apaga e reinsere).
// Sem efeito se a tabela nao existe ou a lista nao e array. $papel: so apaga/reinsere a fatia
// DESSE papel (usuario_centros_custo.papel, ver schema.sql).
function sincronizarCentrosCusto($pdo, $usuarioId, $centrosCusto, string $papel) {
    if (!tabelaExiste($pdo, 'usuario_centros_custo') || !is_array($centrosCusto)) return;

    // Guarda o que o usuario tinha ANTES de apagar: se a lista nova trouxer centro novo, dispara a
    // sync com o Protheus agora (dispararSyncColaboradoresEmSegundoPlano), senao o centro recem-liberado
    // ficaria sem colaboradores ate a rodada das 3h.
    $stmtAntes = $pdo->prepare('SELECT UPPER(TRIM(centro_custo)) FROM usuario_centros_custo WHERE usuario_id = ? AND papel = ?');
    $stmtAntes->execute([$usuarioId, $papel]);
    $centrosAntes = array_flip($stmtAntes->fetchAll(PDO::FETCH_COLUMN));

    $pdo->prepare('DELETE FROM usuario_centros_custo WHERE usuario_id = ? AND papel = ?')->execute([$usuarioId, $papel]);

    $centrosCusto = array_values(array_unique(array_filter(
        array_map('trim', $centrosCusto),
        fn($c) => $c !== ''
    )));
    if (count($centrosCusto) === 0) return;

    $stmt = $pdo->prepare('INSERT INTO usuario_centros_custo (usuario_id, papel, centro_custo) VALUES (?, ?, ?)');
    $temCentroNovo = false;
    foreach ($centrosCusto as $centro) {
        $stmt->execute([$usuarioId, $papel, $centro]);
        if (!isset($centrosAntes[strtoupper($centro)])) $temCentroNovo = true;
    }

    if ($temCentroNovo) {
        dispararSyncColaboradoresEmSegundoPlano();
    }
}

// Roda sync-protheus-colaboradores.php em segundo plano (nao bloqueia o salvar). Seguro disparar
// aqui: so importa volante/intermitente ou centros liberados a preposto/gestor
// (centrosLiberadosPrepostoGestor no script), igual a sync diaria das 3h; so adianta.
// timeout 3600, o mesmo do container sync: uma rodada travada no Protheus seguraria a trava no
// banco e faria a das 3h pular toda noite.
function dispararSyncColaboradoresEmSegundoPlano() {
    $script = __DIR__ . '/../scripts/sync-protheus-colaboradores.php';
    if (!file_exists($script)) return;
    $cmd = 'timeout 3600 php ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
    exec($cmd);
}

// Mesmo padrao de sincronizarCentrosCusto (apaga e reinsere), pra "quem cobre este Preposto"
// (preposto_centro_cobertura). So quando o papel final e 'preposto'.
function sincronizarCentroCobertura($pdo, $usuarioId, $centroCustoCobre) {
    if (!tabelaExiste($pdo, 'preposto_centro_cobertura') || !is_array($centroCustoCobre)) return;

    $pdo->prepare('DELETE FROM preposto_centro_cobertura WHERE usuario_id = ?')->execute([$usuarioId]);

    $centroCustoCobre = array_values(array_unique(array_filter(
        array_map('trim', $centroCustoCobre),
        fn($c) => $c !== ''
    )));
    if (count($centroCustoCobre) === 0) return;

    $stmt = $pdo->prepare('INSERT INTO preposto_centro_cobertura (usuario_id, centro_custo_cobre) VALUES (?, ?)');
    foreach ($centroCustoCobre as $centro) {
        $stmt->execute([$usuarioId, $centro]);
    }
}

// Irma de sincronizarCentroCobertura() pro pool de volante/intermitente do Gestor
// (gestor_centro_cobertura, ver schema.sql): define quais COLABORADORES aparecem pra ele. So
// quando o papel final e 'gestor'.
function sincronizarCoberturaGestor($pdo, $usuarioId, $coberturaGestor) {
    if (!tabelaExiste($pdo, 'gestor_centro_cobertura') || !is_array($coberturaGestor)) return;

    $pdo->prepare('DELETE FROM gestor_centro_cobertura WHERE usuario_id = ?')->execute([$usuarioId]);

    $coberturaGestor = array_values(array_unique(array_filter(
        array_map('trim', $coberturaGestor),
        fn($c) => $c !== ''
    )));
    if (count($coberturaGestor) === 0) return;

    $stmt = $pdo->prepare('INSERT INTO gestor_centro_cobertura (usuario_id, centro_custo_cobre) VALUES (?, ?)');
    foreach ($coberturaGestor as $centro) {
        $stmt->execute([$usuarioId, $centro]);
    }
}

// Mesmo padrao (apaga e reinsere), pros papeis EXTRAS (usuario_papeis_extra, ate 2). So
// gestor/preposto entre si: 'rh' nunca e extra (quando a pessoa tem RH, ele e o principal).
function sincronizarPapeisExtra($pdo, $usuarioId, $papeisExtra) {
    if (!tabelaExiste($pdo, 'usuario_papeis_extra') || !is_array($papeisExtra)) return;

    $pdo->prepare('DELETE FROM usuario_papeis_extra WHERE usuario_id = ?')->execute([$usuarioId]);

    $papeisExtra = array_values(array_unique(array_intersect($papeisExtra, ['gestor', 'preposto'])));
    if (count($papeisExtra) === 0) return;

    $stmt = $pdo->prepare('INSERT INTO usuario_papeis_extra (usuario_id, papel) VALUES (?, ?)');
    foreach ($papeisExtra as $papel) {
        $stmt->execute([$usuarioId, $papel]);
    }
}

if ($acao === 'salvar') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    $id = $corpo['id'] ?? null;
    $usuario = trim($corpo['usuario'] ?? '');
    $senha = isset($corpo['senha']) ? (string) $corpo['senha'] : '';
    $papel = isset($corpo['papel']) ? trim($corpo['papel']) : '';
    // Outros papeis que a pessoa TAMBEM tem (usuario_papeis_extra). So gestor/preposto entre si; RH,
    // quando presente, e sempre o principal.
    $papeisExtra = is_array($corpo['papeis_extra'] ?? null)
        ? array_values(array_unique(array_map('trim', $corpo['papeis_extra'])))
        : [];
    $centrosCusto = $corpo['centros_custo'] ?? null;
    $centroCustoCobre = $corpo['centro_custo_cobre'] ?? null;
    $centroCustoCoberturaGestor = $corpo['centro_custo_cobre_gestor'] ?? null;
    // So com Gestor E Preposto (a lista "centros_custo" fica com o Preposto, ver papelParaCentroCustoRecebido).
    $centrosCustoGestor = $corpo['centros_custo_gestor'] ?? null;
    $gestorEPreposto = fn(string $p) => !array_diff(['gestor', 'preposto'], array_merge([$p], $papeisExtra));

    if ($usuario === '') erro('Campo "usuario" obrigatorio');
    if ($papel !== '' && !in_array($papel, $PAPEIS_VALIDOS, true)) {
        erro('Papel invalido. Use: ' . implode(', ', $PAPEIS_VALIDOS));
    }
    // Administrador e perfil UNICO: a tela ja impede, isto e a garantia no servidor.
    if ($papel === 'administrador' && count($papeisExtra) > 0) {
        erro('O perfil Administrador nao pode ser combinado com outro perfil.');
    }
    foreach ($papeisExtra as $extra) {
        if (!in_array($extra, ['gestor', 'preposto'], true)) {
            erro('Papel extra invalido. Use: gestor, preposto.');
        }
        if ($extra === $papel) {
            erro('Um perfil extra nao pode ser igual ao perfil principal.');
        }
    }

    // O centro de custo do Preposto nao pode estar entre os que cobrem ele (vale se Preposto e
    // principal ou extra). 'TODOS' fica fora da comparacao: e sentinela (usuarios.html, "Todos os
    // contratos") com significados DIFERENTES nas duas colunas (Centro de Custo: todos com
    // colaborador; Cobertura: so volante/intermitente), e RH+Preposto ou os 3 papeis travam as duas
    // colunas em "TODOS" (calcularEstadoCamposEscopo) - dispararia o erro por engano.
    if (($papel === 'preposto' || in_array('preposto', $papeisExtra, true)) && is_array($centrosCusto) && is_array($centroCustoCobre)) {
        $centrosNorm = array_filter(array_map('trim', $centrosCusto), fn($c) => $c !== 'TODOS');
        $cobreNorm = array_filter(array_map('trim', $centroCustoCobre), fn($c) => $c !== 'TODOS');
        $sobreposicao = array_intersect($centrosNorm, $cobreNorm);
        if (count($sobreposicao) > 0) {
            erro('O centro de custo do Preposto nao pode ser tambem um dos centros que cobrem ele: '
                . implode(', ', $sobreposicao));
        }
    }

    // Centro de custo (do Gestor ou do Preposto) nunca e de volante/intermitente: usuarios.html so oferece os outros
    // (centrosDisponiveisParaCampo), isto e a garantia no servidor. 'TODOS' passa (nao e volante pela regra).
    require_once __DIR__ . '/../scripts/protheus-colaboradores-helpers.php';
    $nomesCentros = $pdo->query('SELECT codigo, descricao FROM centros_custo')->fetchAll(PDO::FETCH_KEY_PAIR);
    $centrosVolante = array_unique(array_filter(
        array_merge(is_array($centrosCusto) ? $centrosCusto : [], is_array($centrosCustoGestor) ? $centrosCustoGestor : []),
        fn($c) => centroCustoEhVolante((string) $c, (string) ($nomesCentros[$c] ?? ''))
    ));
    if (count($centrosVolante) > 0) {
        erro('Centro de custo de volante/intermitente nao pode ser o Centro de custo do Gestor ou do Preposto (vai na Cobertura): '
            . implode(', ', $centrosVolante));
    }

    // Linha nova com o nome "admin" (oculto da lista): atualiza a senha do admin existente em vez de
    // inserir duplicado (usuario e UNIQUE). Outro nome existente cai no erro de duplicidade abaixo,
    // sem sobrescrever a senha de ninguem.
    if (!$id && strtolower($usuario) === 'admin') {
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE LOWER(usuario) = ?');
        $stmt->execute(['admin']);
        $existente = $stmt->fetch();
        if ($existente) $id = $existente['id'];
    }

    if (!$id) {
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE LOWER(usuario) = LOWER(?)');
        $stmt->execute([$usuario]);
        if ($stmt->fetch()) erro('Ja existe um usuario com esse nome.');
    }

    // Sem a coluna "papel" (migracao nao rodou), ignora o valor em vez de dar erro.
    $temPapel = colunaExiste($pdo, 'usuarios', 'papel');

    // RH pode rebaixar outro RH; o Administrador (unico e travado, abaixo) garante que sempre sobra
    // quem gerencie usuarios.

    // Conta Administrador UNICA e TRAVADA: so existe 1, ninguem cria outro, e ela nao pode ser
    // renomeada, trocar de perfil nem excluida (acao=remover). Unica mudanca permitida: a SENHA, so
    // pelo proprio Administrador. Chamada sem mudanca (a tela regrava todas as linhas) passa como
    // sucesso, sem tocar no banco.
    if ($temPapel) {
        $stmt = $pdo->query("SELECT id, usuario, senha_hash FROM usuarios WHERE papel = 'administrador' ORDER BY id LIMIT 1");
        $adminExistente = $stmt->fetch();
        $alvoEhAdmin = $adminExistente && $id && (int) $adminExistente['id'] === (int) $id;

        if ($alvoEhAdmin) {
            $mudaCadastro = strcasecmp($usuario, $adminExistente['usuario']) !== 0
                || ($papel !== '' && $papel !== 'administrador');
            if ($mudaCadastro || ($senha !== '' && (int) $usuarioIdLogado !== (int) $id)) {
                erro('A conta Administrador e travada: nao pode ser alterada.', 403);
            }
            if ($senha !== '') {
                // Conta do directory autentica por LDAP - gravar hash criaria uma senha paralela que o AD nao conhece.
                if ($adminExistente['senha_hash'] === '') {
                    erro('Esta conta e do dominio: a senha e gerenciada pelo Active Directory e nao pode ser definida aqui.', 409);
                }
                validarForcaSenha($senha);
                $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')
                    ->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
            }
            echo json_encode(['success' => true, 'id' => (int) $id]);
            exit;
        }
        if ($papel === 'administrador' && $adminExistente) {
            erro('Ja existe uma conta Administrador (' . $adminExistente['usuario'] . '). So pode haver uma.', 403);
        }
    }

    if ($id) {
        if ($senha !== '') {
            validarForcaSenha($senha);
            if ($temPapel && $papel !== '') {
                $stmt = $pdo->prepare('UPDATE usuarios SET usuario = ?, senha_hash = ?, papel = ? WHERE id = ?');
                $stmt->execute([$usuario, password_hash($senha, PASSWORD_DEFAULT), $papel, $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE usuarios SET usuario = ?, senha_hash = ? WHERE id = ?');
                $stmt->execute([$usuario, password_hash($senha, PASSWORD_DEFAULT), $id]);
            }
        } else {
            // Campo de senha deixado em branco = mantem a senha atual
            if ($temPapel && $papel !== '') {
                $stmt = $pdo->prepare('UPDATE usuarios SET usuario = ?, papel = ? WHERE id = ?');
                $stmt->execute([$usuario, $papel, $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE usuarios SET usuario = ? WHERE id = ?');
                $stmt->execute([$usuario, $id]);
            }
        }
        // Papeis extras, centro de custo e cobertura so sincronizam quando o papel principal veio na
        // chamada (edicao so de senha mantem tudo; o picker de Perfil manda os dois juntos).
        if ($temPapel && $papel !== '') {
            sincronizarPapeisExtra($pdo, $id, $papeisExtra);
            // So sincroniza Centro de custo se o papel final (principal ou extra) e gestor ou preposto.
            $papelParaCentro = papelParaCentroCustoRecebido($papel, $papeisExtra);
            if ($papelParaCentro !== null) {
                sincronizarCentrosCusto($pdo, $id, $centrosCusto, $papelParaCentro);
            }
            if ($gestorEPreposto($papel)) sincronizarCentrosCusto($pdo, $id, $centrosCustoGestor, 'gestor');
            // "Quem cobre este Preposto" so faz sentido se Preposto for um dos papeis.
            // Papel retirado: apaga as listas dele, senao continuariam valendo (ex.: aviso de corte de ferias,
            // destinatariosCorte em cortar-coberturas-ferias.php, le papel='preposto' direto da tabela).
            if ($papel === 'preposto' || in_array('preposto', $papeisExtra, true)) {
                sincronizarCentroCobertura($pdo, $id, $centroCustoCobre);
            } else {
                sincronizarCentrosCusto($pdo, $id, [], 'preposto');
                sincronizarCentroCobertura($pdo, $id, []);
            }
            // Pool de volante/intermitente do Gestor - so se Gestor for um dos papeis.
            if ($papel === 'gestor' || in_array('gestor', $papeisExtra, true)) {
                sincronizarCoberturaGestor($pdo, $id, $centroCustoCoberturaGestor);
            } else {
                sincronizarCentrosCusto($pdo, $id, [], 'gestor');
                sincronizarCoberturaGestor($pdo, $id, []);
            }
        }
        echo json_encode(['success' => true, 'id' => $id]);
    } else {
        // Usuario novo: SEM senha (directory USC, "+ Novo usuario do diretorio") reconfere no SERVIDOR
        // que o usuario esta no diretorio e insere com senha_hash=''; sem diretorio configurado (USC ou
        // LDAP) cai no erro de sempre. COM senha: caminho manual, local.
        if ($senha === '') {
            if (!diretorioConfigurado()) {
                erro('Senha obrigatoria para criar um novo usuario (integracao com o diretorio de usuarios nao configurada).');
            }
            $usuariosDiretorio = buscarUsuariosDiretorio();
            if ($usuariosDiretorio === null) {
                erro('O diretorio de usuarios nao respondeu. Tente novamente em instantes.', 502);
            }
            $noDiretorio = array_values(array_filter($usuariosDiretorio,
                fn($u) => mb_strtolower($u['usuario']) === mb_strtolower($usuario)));
            if (count($noDiretorio) === 0) {
                erro("\"$usuario\" nao esta na lista do diretorio de usuarios - selecione um usuario da lista.");
            }
            $senhaHash = ''; // sentinela: senha gerenciada pelo directory USC (LDAP), sem login local
        } else {
            validarForcaSenha($senha);
            $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
        }

        $papelFinal = $papel !== '' ? $papel : 'gestor';
        if ($temPapel) {
            $stmt = $pdo->prepare('INSERT INTO usuarios (usuario, senha_hash, papel) VALUES (?, ?, ?)');
            $stmt->execute([$usuario, $senhaHash, $papelFinal]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO usuarios (usuario, senha_hash) VALUES (?, ?)');
            $stmt->execute([$usuario, $senhaHash]);
        }
        $novoId = $pdo->lastInsertId();
        sincronizarPapeisExtra($pdo, $novoId, $papeisExtra);
        $papelParaCentroNovo = papelParaCentroCustoRecebido($papelFinal, $papeisExtra);
        if ($papelParaCentroNovo !== null) {
            sincronizarCentrosCusto($pdo, $novoId, $centrosCusto, $papelParaCentroNovo);
        }
        if ($gestorEPreposto($papelFinal)) sincronizarCentrosCusto($pdo, $novoId, $centrosCustoGestor, 'gestor');
        if ($papelFinal === 'preposto' || in_array('preposto', $papeisExtra, true)) {
            sincronizarCentroCobertura($pdo, $novoId, $centroCustoCobre);
        }
        if ($papelFinal === 'gestor' || in_array('gestor', $papeisExtra, true)) {
            sincronizarCoberturaGestor($pdo, $novoId, $centroCustoCoberturaGestor);
        }
        echo json_encode(['success' => true, 'id' => $novoId]);
    }
    exit;
}

if ($acao === 'remover') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    $ids = $corpo['ids'] ?? [];
    if (!is_array($ids) || count($ids) === 0) erro('Parametro "ids" obrigatorio (array)');

    // So bloqueia se a remocao deixar o sistema sem nenhum RH/Administrador (verificarNaoEhUltimoRH,
    // em transacao). Conta Administrador e travada - nunca excluida pela interface (ver acao=salvar).
    $marcadoresAdmin = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT 1 FROM usuarios WHERE papel = 'administrador' AND id IN ($marcadoresAdmin)");
    $stmt->execute($ids);
    if ($stmt->fetchColumn()) erro('A conta Administrador e travada: nao pode ser excluida.', 403);

    $pdo->beginTransaction();
    verificarNaoEhUltimoRH($pdo, $ids, 'excluir');

    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id IN ($marcadores)");
    $stmt->execute($ids);
    $removidos = $stmt->rowCount();
    $pdo->commit();

    echo json_encode(['success' => true, 'removidos' => $removidos]);
    exit;
}

erro('Acao invalida: ' . $acao);
