<?php
// Foto diaria da aba Volantes Ociosos (coberturas/previsao.html) para o Historico de observacao (pedido do usuario, 2026-11-09):
// grava em volante_ociosidade os dias em que cada volante da aba ficou ocioso, pela mesma regra da tela (diasOciososVolantes,
// ociosidade-helpers.php). Recalcular o passado na hora daria errado: o cadastro nao guarda desde quando a pessoa e volante, a
// ocupacao de vaga so e projetada enquanto a vaga esta aberta e o SAF so manda 13 meses.
//
// Container "sync", de hora em hora (junto do corte de ferias). Grava de onde a ultima rodada com sucesso parou ate ONTEM (hoje
// ainda pode mudar): o primeiro sucesso do dia grava ontem; dia perdido (SAF fora, servidor desligado) e gravado na volta, com os
// dados de agora. Primeira rodada: so ontem.
// Dado alterado depois da foto (decisao do usuario, 2026-11-09): toda rodada refaz a conta dos dias do PFM que ainda aceita ajuste
// (inicioPeriodoPfmAberto, db.php) e TIRA o dia gravado de quem agora esta ocupado nele - ex.: incluido numa ferias em andamento
// "a partir de" um dia passado, ou designado numa linha que ainda estava sem volante. Nunca ACRESCENTA dia: vaga fechada perde a
// ocupacao projetada (diasOcupadosVolantes) e criaria ocioso falso. So mexe em quem continua na aba (quem saiu guarda o que tinha).
// So grava com o arquivo do SAF de ate 2h (como o corte): sem as ferias do SAF todo volante cobrindo ferias pareceria ocioso e o
// erro ficaria no historico.
//
// Uso: php registrar-ociosidade.php                    (grava os dias que faltam e tira os que passaram a ter cobertura)
//      php registrar-ociosidade.php --desde=AAAA-MM-DD  (tambem os dias desde essa data, ex. preencher o passado uma vez: dias
//                                                       recalculados com os dados de agora, entao aproximados)

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado - script de linha de comando.');
}

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/ociosidade-helpers.php';

function ociosidadeLog(string $msg): void {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n");
}

$pdo = conectarBanco();
if ((string) SAF_COBERTURA_JSON === '') {
    ociosidadeLog('Integracao com o SAF nao configurada neste ambiente - nada a fazer.');
    exit(0);
}
if (!tabelaExiste($pdo, 'volante_ociosidade')) {
    ociosidadeLog('Tabela volante_ociosidade ainda nao existe - rode ferramentas/migrar-banco.php.');
    exit(0);
}

try {
    $ontem = date('Y-m-d', strtotime('-1 day'));
    $desde = '';
    foreach ($argv as $arg) if (str_starts_with($arg, '--desde=')) $desde = substr($arg, 8);
    if ($desde !== '' && (!($d = DateTime::createFromFormat('!Y-m-d', $desde)) || $d->format('Y-m-d') !== $desde)) {
        ociosidadeLog("--desde invalido: $desde (use AAAA-MM-DD).");
        exit(1);
    }
    // A rodada com sucesso no dia D gravou ate D-1: falta de D em diante. sync_status em horario de Brasilia (conectarBanco).
    $stmt = $pdo->prepare("SELECT DATE(executado_em) FROM sync_status WHERE chave = 'ociosidade'");
    $stmt->execute();
    $marcador = $stmt->fetchColumn() ?: $ontem;
    $de = $desde !== '' ? min($desde, $marcador) : $marcador; // primeiro dia a GRAVAR (> ontem: nenhum)
    $janela = inicioPeriodoPfmAberto(date('Y-m-d'));         // primeiro dia a CONFERIR (so tira)

    $todas = feriasSafComVolante($pdo, $avisoSaf);
    if ($avisoSaf === null && time() - (int) filemtime((string) SAF_COBERTURA_JSON) > 2 * 3600) {
        $avisoSaf = 'o arquivo de cobertura do SAF tem mais de 2h';
    }
    if ($avisoSaf !== null) {
        ociosidadeLog("Nada gravado: $avisoSaf");
        registrarErroSyncStatus($pdo, 'ociosidade', $avisoSaf);
        exit(0);
    }

    $centrosCusto = $pdo->query('SELECT codigo, descricao FROM centros_custo')->fetchAll();
    $codigosVolante = array_column(array_filter($centrosCusto, fn($c) => centroCustoEhVolante((string) $c['codigo'], (string) ($c['descricao'] ?? ''))), 'codigo');
    $ociosos = diasOciososVolantes($pdo, $todas, centrosVolantesOciosos($pdo, 'rh', 0, $centrosCusto, $codigosVolante), min($de, $janela), $ontem);

    // Dia gravado da janela em que o volante (ainda na aba) agora esta ocupado.
    $livres = array_map('array_flip', $ociosos);
    $stmt = $pdo->prepare('SELECT matricula, dia FROM volante_ociosidade WHERE dia BETWEEN ? AND ?');
    $stmt->execute([$janela, $ontem]);
    $tirar = array_filter($stmt->fetchAll(), fn($l) => isset($livres[(string) $l['matricula']]) && !isset($livres[(string) $l['matricula']][$l['dia']]));

    $grava = $pdo->prepare('INSERT IGNORE INTO volante_ociosidade (matricula, dia) VALUES (?, ?)');
    $apaga = $pdo->prepare('DELETE FROM volante_ociosidade WHERE matricula = ? AND dia = ?');
    $gravados = 0;
    $pdo->beginTransaction();
    foreach ($ociosos as $matricula => $dias) {
        foreach ($dias as $dia) if ($dia >= $de) $gravados += $grava->execute([(string) $matricula, $dia]) ? $grava->rowCount() : 0;
    }
    foreach ($tirar as $l) $apaga->execute([(string) $l['matricula'], $l['dia']]);
    $pdo->commit();
    ociosidadeLog("$gravados dia(s) ocioso(s) gravado(s)" . ($de <= $ontem ? " de $de a $ontem" : ' (dias ate ontem ja gravados)')
        . '; ' . count($tirar) . " retirado(s) por ter passado a ter cobertura (conferido de $janela a $ontem).");
    registrarSucessoSyncStatus($pdo, 'ociosidade');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ociosidadeLog('ERRO: ' . $e->getMessage());
    registrarErroSyncStatus($pdo, 'ociosidade', $e->getMessage());
    exit(1);
}
