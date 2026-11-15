<?php
// Sincroniza os NOMES de centro de custo <- Protheus (CTT010 = Grupo 01, CTT020 = Grupo 02),
// via SQL Server direto. Roda no container "sync" (docker-compose.yml), 3h, apos o sync de
// colaboradores.
//
// Usado por formatarCentroCusto() (backend/filtro.js) para exibir "Nome - Codigo"; cai para o
// codigo cru se o nome ainda nao foi sincronizado. So le o Protheus; so faz upsert em
// centros_custo (nunca apaga).

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado - script de linha de comando.');
}

require_once __DIR__ . '/../api/db.php';

function centrosCustoLog(string $msg): void {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n");
}

if (empty(PROTHEUS_DB_HOST)) {
    centrosCustoLog('PROTHEUS_DB_HOST nao configurada - nada a sincronizar (defina as 6 variaveis PROTHEUS_DB_* no .env pra ativar).');
    exit(0);
}

centrosCustoLog('Iniciando sincronizacao dos nomes de centro de custo (Protheus -> CGV)...');

try {
    $protheusPdo = conectarProtheusDb();
} catch (Throwable $e) {
    centrosCustoLog('Falha ao conectar no Protheus: ' . $e->getMessage());
    registrarErroSyncStatus(conectarBanco(), 'centros_custo', $e->getMessage());
    exit(1);
}
centrosCustoLog('Conectado ao Protheus.');

try {
    $stmt = $protheusPdo->query(
        "SELECT RTRIM(CTT_CUSTO) AS CODIGO, RTRIM(CTT_DESC01) AS NOME FROM CTT010
          WHERE D_E_L_E_T_ <> '*' AND RTRIM(CTT_CUSTO) <> ''
         UNION ALL
         SELECT RTRIM(CTT_CUSTO) AS CODIGO, RTRIM(CTT_DESC01) AS NOME FROM CTT020
          WHERE D_E_L_E_T_ <> '*' AND RTRIM(CTT_CUSTO) <> ''"
    );
    $centros = $stmt->fetchAll();
} catch (Throwable $e) {
    centrosCustoLog('Falha ao consultar centros de custo no Protheus: ' . $e->getMessage());
    registrarErroSyncStatus(conectarBanco(), 'centros_custo', $e->getMessage());
    exit(1);
}
centrosCustoLog(count($centros) . ' centro(s) de custo encontrado(s) no Protheus (CTT010 Grupo 01 + CTT020 Grupo 02).');

// Marca tem_colaborador: centros com pelo menos um funcionario ativo (SRA010/SRA020, mesma
// condicao do sync de colaboradores). Evita categorias puramente contabeis (ex: "DIVIDENDOS")
// no seletor de Gerenciar Usuarios. Falha aqui nao aborta a sincronizacao dos nomes.
$centrosComColaborador = [];
try {
    $stmtAtivos = $protheusPdo->query(
        "SELECT DISTINCT RTRIM(RA_CC) AS CC FROM SRA010 WHERE D_E_L_E_T_ <> '*' AND RA_DEMISSA = ''
         UNION
         SELECT DISTINCT RTRIM(RA_CC) FROM SRA020 WHERE D_E_L_E_T_ <> '*' AND RA_DEMISSA = ''"
    );
    foreach ($stmtAtivos as $linha) {
        $cc = strtoupper(trim((string) $linha['CC']));
        if ($cc !== '') $centrosComColaborador[$cc] = true;
    }
    centrosCustoLog(count($centrosComColaborador) . ' centro(s) de custo com pelo menos um colaborador ativo no Protheus.');
} catch (Throwable $e) {
    centrosCustoLog('Falha ao consultar quais centros tem colaborador ativo (nao interrompe a rodada, tem_colaborador fica como estava): ' . $e->getMessage());
}

$pdo = conectarBanco();
$stmtUpsert = $pdo->prepare(
    'INSERT INTO centros_custo (codigo, descricao, tem_colaborador, atualizado_em) VALUES (?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE descricao = VALUES(descricao), tem_colaborador = VALUES(tem_colaborador), atualizado_em = NOW()'
);

$sincronizados = 0;
$semNome = 0;
foreach ($centros as $c) {
    $codigo = (string) $c['CODIGO'];
    $nome = trim((string) $c['NOME']);
    if ($nome === '') { $semNome++; continue; } // sem descricao no Protheus - mantem o que ja tinha (ou codigo cru)
    $temColaborador = isset($centrosComColaborador[strtoupper(trim($codigo))]) ? 1 : 0;
    try {
        $stmtUpsert->execute([$codigo, $nome, $temColaborador]);
        $sincronizados++;
    } catch (Throwable $e) {
        // Erro pontual neste centro - nao aborta o resto, tenta de novo amanha.
        centrosCustoLog("Erro ao gravar centro de custo $codigo (nao interrompe a rodada): " . $e->getMessage());
    }
}

registrarSucessoSyncStatus($pdo, 'centros_custo');
centrosCustoLog("Sincronizacao concluida: $sincronizados centro(s) sincronizado(s), $semNome sem descricao no Protheus (ignorado(s)).");
