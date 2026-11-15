<?php
// Backup do banco, para rodar por agendamento (cron).
// Gera um .sql.gz com estrutura + dados de todas as tabelas e mantem os N mais recentes.
// PHP puro com PDO de proposito: nao depende do binario "mysqldump".
//
// Rodar na mao: php ferramentas/backup-banco.php
//
// Os arquivos ficam em "backups/" na raiz do projeto, bloqueada no .htaccess: um dump tem
// todos os dados de folha e os hashes de senha.

// So linha de comando: pela URL qualquer um dispararia um dump.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Config, conexao (conectarBanco, com retry) e registro em sync_status (um disco cheio,
// causa provavel de falha aqui, fica visivel no alerta de saude).
require_once __DIR__ . '/../backend/api/db.php';

const QUANTOS_MANTER = 14;   // duas semanas de backups diarios
const PASTA_BACKUP   = __DIR__ . '/../backups';

function log_linha($msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

// Em linha de comando, conectarBanco() sai com codigo 1 (mensagem no
// STDERR) se o banco nao responder depois das tentativas.
$pdo = conectarBanco();

if (!is_dir(PASTA_BACKUP) && !mkdir(PASTA_BACKUP, 0700, true)) {
    log_linha('ERRO: nao consegui criar a pasta ' . PASTA_BACKUP);
    registrarErroSyncStatus($pdo, 'backup_banco', 'Nao consegui criar a pasta de backup (' . PASTA_BACKUP . ').');
    exit(1);
}

// Escreve primeiro num arquivo ".parcial": se o script morrer no meio
// (timeout, falta de espaco), nao fica um backup truncado se passando por
// completo. So no fim ele e renomeado para o nome definitivo.
$carimbo    = date('Y-m-d_His');
$destino    = PASTA_BACKUP . "/cgv_{$carimbo}.sql.gz";
$temporario = $destino . '.parcial';

$gz = gzopen($temporario, 'wb9');
if (!$gz) {
    log_linha('ERRO: nao consegui abrir o arquivo de destino para escrita.');
    registrarErroSyncStatus($pdo, 'backup_banco', 'Nao consegui abrir o arquivo de backup para escrita (disco cheio/sem permissao?).');
    exit(1);
}

function escrever($gz, $texto) {
    if (gzwrite($gz, $texto) === false) {
        throw new RuntimeException('falha ao gravar no arquivo de backup');
    }
}

$totalLinhas = 0;

try {
    escrever($gz, '-- Backup do banco ' . DB_NAME . "\n");
    escrever($gz, '-- Gerado em ' . date('d/m/Y H:i:s') . " por ferramentas/backup-banco.php\n");
    escrever($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    $tabelas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    log_linha('Tabelas encontradas: ' . count($tabelas));

    foreach ($tabelas as $tabela) {
        // Estrutura
        $criacao = $pdo->query("SHOW CREATE TABLE `$tabela`")->fetch(PDO::FETCH_ASSOC);
        escrever($gz, "\n-- ----------------------------\n-- Tabela: $tabela\n-- ----------------------------\n");
        escrever($gz, "DROP TABLE IF EXISTS `$tabela`;\n");
        escrever($gz, $criacao['Create Table'] . ";\n\n");

        // Dados, lidos de a pouco: fetch() num laco em vez de fetchAll(),
        // para nao carregar uma tabela inteira na memoria de uma vez.
        $stmt = $pdo->query("SELECT * FROM `$tabela`");
        $linhas = 0;
        while ($linha = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $colunas = implode(', ', array_map(fn($c) => "`$c`", array_keys($linha)));
            $valores = implode(', ', array_map(
                fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                array_values($linha)
            ));
            escrever($gz, "INSERT INTO `$tabela` ($colunas) VALUES ($valores);\n");
            $linhas++;
        }
        $totalLinhas += $linhas;
        log_linha(sprintf('  %-28s %6d linhas', $tabela, $linhas));
    }

    escrever($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);

    if (!rename($temporario, $destino)) {
        throw new RuntimeException('nao consegui renomear o arquivo temporario');
    }
} catch (Throwable $e) {
    gzclose($gz);
    @unlink($temporario);
    log_linha('ERRO: ' . $e->getMessage() . ' - backup DESCARTADO (nada pela metade foi mantido).');
    registrarErroSyncStatus($pdo, 'backup_banco', $e->getMessage());
    exit(1);
}

$tamanho = filesize($destino);
log_linha(sprintf('Backup pronto: %s (%.1f KB, %d linhas no total)',
    basename($destino), $tamanho / 1024, $totalLinhas));

// Um backup de 0 byte, ou quase, quase sempre significa que algo falhou
// silenciosamente - melhor gritar do que deixar passar por bom.
if ($tamanho < 512) {
    log_linha('ATENCAO: o arquivo saiu suspeitosamente pequeno. Confira o conteudo.');
    registrarErroSyncStatus($pdo, 'backup_banco', "Arquivo de backup saiu suspeitosamente pequeno ($tamanho bytes) - provavel falha silenciosa.");
    exit(1);
}

registrarSucessoSyncStatus($pdo, 'backup_banco');

// ------------------------------------------------------------
// Rotacao: mantem os mais recentes e apaga o excedente.
// ------------------------------------------------------------
$existentes = glob(PASTA_BACKUP . '/cgv_*.sql.gz');
rsort($existentes);   // o nome comeca com a data, entao ordem alfabetica = cronologica

$apagados = 0;
foreach (array_slice($existentes, QUANTOS_MANTER) as $velho) {
    if (unlink($velho)) $apagados++;
}

log_linha(sprintf('Backups guardados: %d (limite %d). Apagados: %d',
    min(count($existentes), QUANTOS_MANTER), QUANTOS_MANTER, $apagados));
