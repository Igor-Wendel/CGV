<?php
// Apoio da cadeia noturna (docker-compose.yml, container "sync"). Dois usos:
//
// php status-rotina.php atrasada
//   Recuperacao ao ligar: exit 0 = alguma rotina esta ha mais de 24h sem sucesso (ou nunca
//   rodou) -> o container roda a cadeia na hora, sem esperar as 3h (o agendamento nao recupera
//   rodada perdida com o computador desligado). exit 1 = em dia ou banco inacessivel.
//
// php status-rotina.php falhou <chave> <codigo de saida> <limite em s>
//   Rotina que terminou com erro sem gravar o motivo (timeout, erro fatal): grava um motivo
//   em sync_status para aparecer no aviso do login. Nao sobrescreve erro que a propria
//   rotina ja gravou nesta execucao.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado - script de linha de comando.');
}

// Ao subir, o banco pode ainda estar terminando de iniciar - 7 tentativas
// do retry de conectarBanco() esperam ~1 min (1+2+4+8+16+32s) antes de
// desistir (exit 1).
define('DB_RETRY_TENTATIVAS', 7);
require_once __DIR__ . '/../api/db.php';

$pdo = conectarBanco();

$modo = $argv[1] ?? '';

if ($modo === 'atrasada') {
    if (!tabelaExiste($pdo, 'sync_status')) exit(1);
    $chaves = array_keys(rotinasNoturnasConfiguradas());
    $marcadores = implode(',', array_fill(0, count($chaves), '?'));
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM sync_status
          WHERE chave IN ($marcadores) AND executado_em >= NOW() - INTERVAL 24 HOUR"
    );
    $stmt->execute($chaves);
    // Menos rotinas em dia do que configuradas = alguma atrasada ou que nunca rodou.
    exit((int) $stmt->fetchColumn() < count($chaves) ? 0 : 1);
}

if ($modo === 'falhou') {
    $chave = (string) ($argv[2] ?? '');
    $codigo = (int) ($argv[3] ?? 0);
    $limite = (int) ($argv[4] ?? 0);
    if ($chave === '' || $codigo === 0 || !tabelaExiste($pdo, 'sync_status')) exit(0);

    // A rotina gravou o proprio erro durante esta execucao? (dentro do tempo
    // maximo dela + 1 min de folga)
    $stmt = $pdo->prepare(
        'SELECT 1 FROM sync_status WHERE chave = ? AND ultimo_erro_em >= NOW() - INTERVAL ? SECOND'
    );
    $stmt->execute([$chave, $limite + 60]);
    if ($stmt->fetchColumn()) exit(0);

    if ($codigo === 124) {
        $motivo = 'Passou do limite de ' . round($limite / 60) . ' minutos e foi interrompida.';
    } elseif ($codigo === 137) {
        $motivo = 'Foi encerrada à força (limite de tempo ou de memória).';
    } elseif ($codigo === 255) {
        $motivo = "Parou por um erro fatal do PHP (detalhe no log do container 'sync').";
    } else {
        $motivo = "Terminou com erro (código $codigo) sem registrar o motivo (detalhe no log do container 'sync').";
    }
    registrarErroSyncStatus($pdo, $chave, $motivo);
    fwrite(STDERR, "[status-rotina] $chave: $motivo\n");
    exit(0);
}

fwrite(STDERR, "Uso: php status-rotina.php atrasada | falhou <chave> <codigo> <limite_s>\n");
exit(1);
