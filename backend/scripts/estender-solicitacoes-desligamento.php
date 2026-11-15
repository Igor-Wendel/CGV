<?php
// Extensao diaria das vagas de Desligamento em aberto (container "sync", 3h).
// A logica fica em estender-vagas-helpers.php; aqui so os 4 valores que mudam.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado - script de linha de comando.');
}

require_once __DIR__ . '/estender-vagas-helpers.php';

estenderVagasEmAberto('solicitacoes_desligamento', 'desligamento', 'estender_desligamento', 'desligamento');
