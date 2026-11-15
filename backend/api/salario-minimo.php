<?php
// ============================================================
// SALARIO MINIMO VIGENTE - fonte oficial: Banco Central, SGS serie 1619. Pre-preenche o modal de
// salario minimo do PFM (fopag/pfm.js); o usuario sempre confirma ou corrige. API publica, sem
// chave; passa pelo backend pra pagina nao depender de um dominio externo no navegador e pra tratar
// falha num lugar so.
// ============================================================
require_once __DIR__ . '/db.php';

$pdo = conectarBanco();
verificarSessao($pdo); // qualquer papel logado - a pagina que usa isto (PFM) ja e RH-only
header('Content-Type: application/json');

$url = 'https://api.bcb.gov.br/dados/serie/bcdata.sgs.1619/dados/ultimos/1?formato=json';

$ctx = stream_context_create(['http' => ['timeout' => 5]]);
$resposta = @file_get_contents($url, false, $ctx);

if ($resposta === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Nao foi possivel consultar o salario minimo no Banco Central agora.']);
    exit;
}

$dados = json_decode($resposta, true);
if (!is_array($dados) || count($dados) === 0 || !isset($dados[0]['valor'])) {
    http_response_code(502);
    echo json_encode(['error' => 'Resposta inesperada do Banco Central.']);
    exit;
}

echo json_encode([
    'valor' => (float) $dados[0]['valor'],
    'data_referencia' => $dados[0]['data'] ?? null,
]);
