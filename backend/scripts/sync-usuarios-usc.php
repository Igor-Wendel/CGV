<?php
// Sincroniza usuarios <- directory USC (container "sync", 3h, apos o sync de colaboradores).
// Sem USC_API_URL configurada, so loga e sai.
//
// Escopo: so usuarios criados a partir do directory (senha_hash = '', mesmo sentinela de
// login.php). Conta manual/legado nunca e tocada.
//
// Usuario do directory que sumiu da lista (comparacao case-insensitive) e removido de
// "usuarios", mas nunca o ultimo RH (fica so no log, exige intervencao manual). Falha de
// rede/HTTP/JSON nunca remove ninguem: loga e pula a rodada.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado - script de linha de comando.');
}

require_once __DIR__ . '/../api/db.php';

function usuariosUscLog(string $msg): void {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n");
}

if (empty(USC_API_URL)) {
    usuariosUscLog('USC_API_URL nao configurada - nada a sincronizar (defina no .env pra ativar).');
    exit(0);
}

usuariosUscLog('Iniciando sincronizacao de usuarios (directory USC -> CGV)...');

$pdo = conectarBanco();

$usuariosDiretorio = buscarUsuariosDiretorioUSC();
if ($usuariosDiretorio === null) {
    usuariosUscLog('Directory USC nao respondeu (falha de rede/HTTP/JSON) - rodada pulada, nunca remove ninguem por causa disso. Tenta de novo amanha.');
    registrarErroSyncStatus($pdo, 'usuarios', 'Directory USC nao respondeu (falha de rede/HTTP/JSON).');
    exit(0);
}

$loginsNoDiretorio = array_map(fn($u) => mb_strtolower($u['usuario']), $usuariosDiretorio);

$stmt = $pdo->query("SELECT id, usuario, papel FROM usuarios WHERE senha_hash = ''");
$candidatosRemocao = [];
foreach ($stmt->fetchAll() as $usuario) {
    if (!in_array(mb_strtolower($usuario['usuario']), $loginsNoDiretorio, true)) {
        $candidatosRemocao[] = $usuario;
    }
}

if (!$candidatosRemocao) {
    usuariosUscLog('Nenhum usuario pra remover - todos os cadastrados via directory continuam la.');
    registrarSucessoSyncStatus($pdo, 'usuarios');
    exit(0);
}

$totalRh = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE papel = 'rh'")->fetchColumn();
$removidos = 0;
$pulados = 0;
foreach ($candidatosRemocao as $usuario) {
    if ($usuario['papel'] === 'rh' && $totalRh <= 1) {
        usuariosUscLog("PULADO usuario=\"{$usuario['usuario']}\" (id={$usuario['id']}): e o ultimo RH do sistema, "
            . 'nao saiu do directory sozinho - precisa de intervencao manual antes de remover.');
        $pulados++;
        continue;
    }
    $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$usuario['id']]);
    if ($usuario['papel'] === 'rh') $totalRh--;
    usuariosUscLog("REMOVIDO usuario=\"{$usuario['usuario']}\" (id={$usuario['id']}, papel={$usuario['papel']}) - nao consta mais no directory USC.");
    $removidos++;
}

registrarSucessoSyncStatus($pdo, 'usuarios');
usuariosUscLog("Rodada concluida: $removidos removido(s), $pulados pulado(s) (ultimo RH), de " . count($candidatosRemocao) . ' candidato(s) no total.');
