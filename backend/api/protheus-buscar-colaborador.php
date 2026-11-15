<?php
// ============================================================
// BUSCA DE COLABORADOR DIRETO NO PROTHEUS (por matricula ou nome)
// Uso: protheus-buscar-colaborador.php?termo=<matricula ou nome>
// ============================================================
// Usado pelo modal "Adicionar Colaborador" do CDV (colaboradores/funcionarios.html): o sync
// automatico so traz quem ja esta em centro volante; esta busca cobre quem AINDA NAO aparece no CDV
// mas e funcionario ativo no Protheus, em TODOS os centros de custo.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../scripts/protheus-colaboradores-helpers.php';

$pdo = conectarBanco();
$usuarioId = verificarSessao($pdo);
$papel = exigirPapel($pdo, $usuarioId, ['rh', 'administrador', 'gestor']);
header('Content-Type: application/json');

function erro($msg, $status = 400) {
    http_response_code($status);
    echo json_encode(['error' => $msg]);
    exit;
}

$termo = trim((string) ($_GET['termo'] ?? ''));
if (mb_strlen($termo) < 2) {
    erro('Digite ao menos 2 caracteres para buscar.');
}

if (empty(PROTHEUS_DB_HOST)) {
    erro('Conexao com o Protheus nao configurada neste ambiente.', 503);
}

try {
    $protheusPdo = conectarProtheusDb();
} catch (Throwable $e) {
    error_log('Falha ao conectar no Protheus (busca de colaborador): ' . $e->getMessage());
    erro('Nao foi possivel conectar ao Protheus.', 502);
}

// Matricula casa por prefixo; nome por trecho em qualquer posicao (o mesmo termo tenta as duas).
// UPPER() nos dois lados: a collation do Protheus e case-sensitive.
$termoMat = strtoupper($termo) . '%';
$termoNome = '%' . strtoupper($termo) . '%';

try {
    // TOP 20 (SQL Server) na query INTEIRA (UNION ALL numa subconsulta), pra nao trazer milhares de
    // linhas pela rede do ERP antes do corte.
    $stmt = $protheusPdo->prepare(
        "SELECT TOP 20 * FROM (
         SELECT RTRIM(A.RA_FILIAL) AS FILIAL, RTRIM(A.RA_CC) AS CC, RTRIM(A.RA_MAT) AS MAT,
                RTRIM(A.RA_NOMECMP) AS NOME, RTRIM(A.RA_ADMISSA) AS ADMISSAO,
                A.RA_SALARIO AS SALARIO, A.RA_XSLBINT AS SAL_B_INTERM,
                RTRIM(A.RA_CODFUNC) AS FUNCAO_CODIGO, RTRIM(J.RJ_DESC) AS FUNCAO,
                RTRIM(C.CTT_DESC01) AS CC_DESC
         FROM SRA010 A
         LEFT JOIN SRJ010 J ON J.RJ_FUNCAO = A.RA_CODFUNC AND J.D_E_L_E_T_ <> '*'
         LEFT JOIN CTT010 C ON C.CTT_CUSTO = A.RA_CC AND C.D_E_L_E_T_ <> '*'
         WHERE A.D_E_L_E_T_ <> '*' AND A.RA_DEMISSA = ''
           AND (UPPER(A.RA_MAT) LIKE ? OR UPPER(A.RA_NOMECMP) LIKE ?)
         UNION ALL
         SELECT RTRIM(A.RA_FILIAL), RTRIM(A.RA_CC), RTRIM(A.RA_MAT),
                RTRIM(A.RA_NOMECMP), RTRIM(A.RA_ADMISSA),
                A.RA_SALARIO, A.RA_XSLBINT,
                RTRIM(A.RA_CODFUNC), RTRIM(J.RJ_DESC),
                RTRIM(C.CTT_DESC01)
         FROM SRA020 A
         LEFT JOIN SRJ010 J ON J.RJ_FUNCAO = A.RA_CODFUNC AND J.D_E_L_E_T_ <> '*'
         LEFT JOIN CTT020 C ON C.CTT_CUSTO = A.RA_CC AND C.D_E_L_E_T_ <> '*'
         WHERE A.D_E_L_E_T_ <> '*' AND A.RA_DEMISSA = ''
           AND (UPPER(A.RA_MAT) LIKE ? OR UPPER(A.RA_NOMECMP) LIKE ?)
         ) AS resultado"
    );
    $stmt->execute([$termoMat, $termoNome, $termoMat, $termoNome]);
    $linhas = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('Falha ao buscar colaborador no Protheus: ' . $e->getMessage());
    erro('Falha ao consultar o Protheus.', 502);
}

// Salario (mesmo fallback do sync para o intermitente, protheusColabSalarioEAdicionais) so para RH/Administrador: a busca acha
// qualquer ativo do Protheus. Do Gestor, o servidor pega o salario no Protheus ao gravar a linha nova (dados.php).
$verSalario = in_array($papel, ['rh', 'administrador'], true);
$resultados = [];
foreach ($linhas as $l) {
    $filial = ltrim((string) $l['FILIAL'], '0');
    if ($filial === '') $filial = '0';

    $resultados[] = [
        'filial' => $filial,
        'centro_custo' => strtoupper(trim((string) $l['CC'])),
        'centro_custo_nome' => trim((string) ($l['CC_DESC'] ?? '')),
        'matricula' => trim((string) $l['MAT']),
        'nome' => trim((string) $l['NOME']),
        'funcao' => trim((string) $l['FUNCAO']),
        'data_admissao' => protheusColabFormatarData($l['ADMISSAO']),
    ] + ($verSalario ? ['salario' => protheusColabSalarioEAdicionais($l)['salario']] : []);
}

echo json_encode(['dados' => $resultados]);
