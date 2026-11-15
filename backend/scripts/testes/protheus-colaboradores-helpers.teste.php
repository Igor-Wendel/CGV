<?php
// Testes standalone (sem framework - projeto nao tem Composer/PHPUnit) das
// funcoes puras usadas pela sincronizacao de colaboradores. Rodar direto:
// php backend/scripts/testes/protheus-colaboradores-helpers.teste.php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado - script de linha de comando.');
}

require_once __DIR__ . '/../protheus-colaboradores-helpers.php';

$falhas = 0;
function verificar(bool $condicao, string $descricao): void {
    global $falhas;
    if ($condicao) {
        echo "OK     - $descricao\n";
    } else {
        echo "FALHOU - $descricao\n";
        $falhas++;
    }
}

// centroCustoEhVolante
verificar(centroCustoEhVolante('01V001') === true, "centro com 'V' e volante");
verificar(centroCustoEhVolante('01V019') === false, "01V019 (ADM PREPOSTOS) tem 'V' mas e excecao - nao e volante");
verificar(centroCustoEhVolante('  01v019  ', 'ADM PREPOSTOS') === false, "excecao vale mesmo com nome preenchido e espacos/minusculo");
verificar(centroCustoEhVolante('01A036', 'CONTRATO INTERMITENTE - DF') === true, "nome com 'intermitente' e volante mesmo sem V no codigo");
verificar(centroCustoEhVolante('01a057', 'contrato intermitente - ba') === true, "match de nome e case-insensitive");
verificar(centroCustoEhVolante('21MT007', 'ESCRITORIO CENTRAL') === false, "sem V no codigo e sem 'intermitente' no nome nao e volante");
verificar(centroCustoEhVolante('21MT007') === false, "centro operacional sem V e sem nome informado nao e volante");
verificar(centroCustoEhVolante('') === false, "centro vazio nao e volante");
verificar(centroCustoEhVolante('', 'CONTRATO INTERMITENTE - DF') === false, "codigo vazio nunca e volante, mesmo com nome batendo");

// protheusColabFormatarData
verificar(protheusColabFormatarData('20230615') === '15/06/2023', "AAAAMMDD valido vira DD/MM/AAAA");
verificar(protheusColabFormatarData('') === '', "vazio devolve vazio");
verificar(protheusColabFormatarData(null) === '', "null devolve vazio");
verificar(protheusColabFormatarData('data-invalida') === '', "valor invalido devolve vazio, nao quebra");

// Round-trip: DateTime::createFromFormat('Ymd', ...) "conserta" mes/dia fora
// do range em vez de rejeitar - confere que a validacao pega isso.
verificar(protheusColabFormatarData('20261332') === '', "mes 13 (invalido) devolve vazio, nao uma data errada");
verificar(protheusColabFormatarData('00000000') === '', "tudo zero devolve vazio, nao estoura o VARCHAR(10)");
verificar(protheusColabFormatarData('20230229') === '', "29/02 em ano NAO bissexto (2023) devolve vazio");
verificar(protheusColabFormatarData('20240229') === '29/02/2024', "29/02 em ano bissexto (2024) e valido normalmente");

// protheusColabSalarioEAdicionais (as regras do sync)
verificar(protheusColabSalarioEAdicionais(['SALARIO' => '2500.50', 'SAL_B_INTERM' => '0', 'ADCPERI' => '2', 'ADCINS' => '1'])
    === ['salario' => 2500.5, 'periculosidade' => 1, 'insalubridade_grau' => 0], "salario do RA_SALARIO; ADCPERI 2 = periculosidade; ADCINS 1 = sem insalubridade");
verificar(protheusColabSalarioEAdicionais(['SALARIO' => '0', 'SAL_B_INTERM' => '1800', 'ADCPERI' => '1', 'ADCINS' => '4'])
    === ['salario' => 1800.0, 'periculosidade' => 0, 'insalubridade_grau' => 3], "intermitente (RA_SALARIO 0) usa RA_XSLBINT; ADCINS 4 = grau 3");
verificar(protheusColabSalarioEAdicionais(['SALARIO' => null, 'SAL_B_INTERM' => null, 'ADCPERI' => '', 'ADCINS' => '9'])
    === ['salario' => null, 'periculosidade' => 0, 'insalubridade_grau' => 0], "sem nenhum salario = null; ADCINS inesperado = 0");
verificar(protheusColabSalarioEAdicionais(['SALARIO' => '1500', 'SAL_B_INTERM' => '0'])
    === ['salario' => 1500.0, 'periculosidade' => 0, 'insalubridade_grau' => 0], "linha da busca do Adicionar, sem as colunas dos adicionais");

// protheusColabCadastroPorMatricula, com SQLite em memoria no lugar do Protheus: o cadastro ativo, senao o desligamento mais novo
if (!extension_loaded('pdo_sqlite')) {
    echo "PULADO - protheusColabCadastroPorMatricula (sem pdo_sqlite)\n";
} else {
    $protheus = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach (['SRA010', 'SRA020'] as $t) {
        $protheus->exec("CREATE TABLE $t (RA_MAT TEXT, RA_SALARIO REAL, RA_XSLBINT REAL, RA_ADCPERI TEXT, RA_ADCINS TEXT, RA_DEMISSA TEXT, D_E_L_E_T_ TEXT)");
    }
    $cadastro = fn(string $t, array $v) => $protheus->prepare("INSERT INTO $t VALUES (?, ?, ?, ?, ?, ?, ?)")->execute($v);
    $cadastro('SRA010', ['000101', 1000, 0, '1', '1', '20240110', ' ']);
    $cadastro('SRA020', ['000101', 1200, 0, '2', '1', '20250305', ' ']);
    $cadastro('SRA010', ['000101', 9999, 0, '1', '4', '20990101', '*']);
    verificar(protheusColabCadastroPorMatricula($protheus, '000101') === ['salario' => 1200.0, 'periculosidade' => 1, 'insalubridade_grau' => 0],
        "desligado nos 2 grupos: vale o desligamento mais novo (linha apagada nao conta)");
    $cadastro('SRA010', ['000101', 1300, 0, '1', '3', '', ' ']);
    verificar(protheusColabCadastroPorMatricula($protheus, '000101') === ['salario' => 1300.0, 'periculosidade' => 0, 'insalubridade_grau' => 2],
        "readmitido: o cadastro ativo ganha dos desligamentos");
    verificar(protheusColabCadastroPorMatricula($protheus, '999999') === null, "matricula sem cadastro no Protheus devolve null");
}

if ($falhas > 0) {
    echo "\n$falhas teste(s) falharam.\n";
    exit(1);
}
echo "\nTodos os testes passaram.\n";
exit(0);
