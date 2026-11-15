<?php
// Funcoes puras (sem I/O) da sincronizacao de colaboradores, separadas para serem testadas
// sem banco (ver testes/protheus-colaboradores-helpers.teste.php).

// Centro com "V" no codigo e volante, exceto os desta lista (01V019 = "ADM PREPOSTOS", administrativo).
const PROTHEUS_COLAB_CENTROS_EXCLUIDOS = ['01V019'];

// Centro cujo NOME contem "intermitente" tambem e volante, mesmo sem "V" no codigo (ex: 01A036).
// Checar pelo nome evita manter lista fixa de codigos.
function centroCustoEhVolante(string $cc, string $nomeCc = ''): bool {
    $cc = strtoupper(trim($cc));
    if ($cc === '') return false;
    if (in_array($cc, PROTHEUS_COLAB_CENTROS_EXCLUIDOS, true)) return false;
    if (str_contains($cc, 'V')) return true;
    $nomeCc = strtoupper(trim($nomeCc));
    return $nomeCc !== '' && str_contains($nomeCc, 'INTERMITENTE');
}

// Codigos dos centros de custo de volantes/intermitentes.
function codigosCentrosVolante(PDO $pdo): array {
    $codigos = [];
    foreach ($pdo->query('SELECT codigo, descricao FROM centros_custo')->fetchAll() as $linha) {
        if (centroCustoEhVolante((string) $linha['codigo'], (string) ($linha['descricao'] ?? ''))) $codigos[] = $linha['codigo'];
    }
    return $codigos;
}

// Sub-fatia de centroCustoEhVolante(): diz se o centro e de INTERMITENTE (separa
// fopag/pfm-volantes.html de fopag/pfm-intermitente.html).
function centroCustoEhIntermitente(string $nomeCc): bool {
    $nomeCc = strtoupper(trim($nomeCc));
    return $nomeCc !== '' && str_contains($nomeCc, 'INTERMITENTE');
}

// "AAAAMMDD" (Protheus) -> "DD/MM/AAAA" (VARCHAR(10) em colaboradores). Devolve '' se vazio/invalido.
// createFromFormat "conserta" mes/dia fora do range (mes 13 rola pro ano seguinte); o
// round-trip garante que a data existe (ex: "20261332", "00000000" estourariam a coluna).
function protheusColabFormatarData(?string $s): string {
    if ($s === null || trim($s) === '') return '';
    $s = trim($s);
    $d = DateTime::createFromFormat('Ymd', $s);
    if (!$d || $d->format('Ymd') !== $s) return '';
    return $d->format('d/m/Y');
}

// Salario e adicionais de uma linha do Protheus (colunas SALARIO, SAL_B_INTERM, ADCPERI, ADCINS), com as regras do sync: intermitente
// tem RA_SALARIO = 0 e usa RA_XSLBINT (sem os dois, null); RA_ADCPERI 1=nao/2=sim; RA_ADCINS 1=nao/2=minima/3=media/4=maxima vira o
// grau 0-3 do PFM (valor inesperado = 0). Coluna que nao veio conta como vazia (a busca do Adicionar nao traz os adicionais).
function protheusColabSalarioEAdicionais(array $l): array {
    $salarioBase = (float) ($l['SALARIO'] ?? 0);
    $salBaseInterm = (float) ($l['SAL_B_INTERM'] ?? 0);
    $insalubridadeGrau = max(0, (int) ($l['ADCINS'] ?? 0) - 1);
    return [
        'salario' => $salarioBase > 0 ? $salarioBase : ($salBaseInterm > 0 ? $salBaseInterm : null),
        'periculosidade' => (string) ($l['ADCPERI'] ?? '') === '2' ? 1 : 0,
        'insalubridade_grau' => $insalubridadeGrau > 3 ? 0 : $insalubridadeGrau,
    ];
}

// Salario e adicionais de UMA matricula no Protheus (SRA010 + SRA020): o cadastro ativo ou, sem ativo, o do desligamento mais novo
// (o do desligado da busca da Nova Solicitacao, dados.php "desligados=1"). null = matricula sem cadastro. Quem chama conecta e trata
// a falha (cadastroProtheusPorMatricula, db.php).
function protheusColabCadastroPorMatricula(PDO $protheusPdo, string $matricula): ?array {
    $stmt = $protheusPdo->prepare(
        "SELECT RA_SALARIO AS SALARIO, RA_XSLBINT AS SAL_B_INTERM, RTRIM(RA_ADCPERI) AS ADCPERI, RTRIM(RA_ADCINS) AS ADCINS,
                RTRIM(RA_DEMISSA) AS DEMISSAO
           FROM SRA010 WHERE RA_MAT = ? AND D_E_L_E_T_ <> '*'
         UNION ALL
         SELECT RA_SALARIO, RA_XSLBINT, RTRIM(RA_ADCPERI), RTRIM(RA_ADCINS), RTRIM(RA_DEMISSA)
           FROM SRA020 WHERE RA_MAT = ? AND D_E_L_E_T_ <> '*'"
    );
    $stmt->execute([$matricula, $matricula]);
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // O ativo fica por ultimo; sem ativo, a demissao mais nova (AAAAMMDD compara como texto).
    usort($linhas, fn($a, $b) => [trim((string) $a['DEMISSAO']) === '', (string) $a['DEMISSAO']] <=> [trim((string) $b['DEMISSAO']) === '', (string) $b['DEMISSAO']]);
    return $linhas ? protheusColabSalarioEAdicionais(end($linhas)) : null;
}
