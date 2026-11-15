<?php
// ============================================================
// LOGS DO SISTEMA (logs.html) - so Administrador.
// ============================================================
// So LE a tabela "auditoria" (ver registrarAuditoria em db.php); nao muda como o sistema registra
// acoes.
//
// Uso: logs.php?acao=listar&usuario=...&papel=...&data_inicio=DD/MM/AAAA&data_fim=DD/MM/AAAA
// ============================================================
require_once __DIR__ . '/db.php';

$pdo = conectarBanco();
$usuarioId = verificarSessaoAdministrador($pdo); // SO Administrador - nem RH ve esta tela
header('Content-Type: application/json');

function erroLogs($msg, $status = 400) {
    http_response_code($status);
    echo json_encode(['error' => $msg]);
    exit;
}

// Nome amigavel da tabela afetada (auditoria.tabela) - so pra compor a
// frase de "N registro(s) de X" no detalhe enriquecido abaixo.
const TABELAS_AMIGAVEIS = [
    'vt_valores' => 'Vale Transporte',
    'solicitacoes_cobertura' => 'cobertura',
    'solicitacoes_desligamento' => 'desligamento',
    'solicitacoes_afastamento' => 'afastamento',
    'preposto_solicitacoes' => 'solicitacao do Preposto',
    'colaborador_bases' => 'base de volante',
    'preposto_solicitacoes_respostas' => 'resposta de cobertura (Gestor)',
    'centros_custo' => 'centro de custo',
    'colaboradores' => 'colaborador',
];

// Troca "titular 000001"/"volante 000002"/"substituto sugerido 000003"/"matricula 000003" pelo
// NOME do colaborador (quando ha cadastro) e "centro 24AP001" pela descricao do centro de custo.
// So enriquecimento pra EXIBIR: auditoria.detalhe nunca muda. Matricula sem cadastro fica como estava.
function enriquecerDetalhe(?string $detalhe, array $colabPorMatricula, array $centroPorCodigo): string {
    if (!$detalhe) return '';

    // Lista longa de ids e ruido pro usuario: a quantidade ja aparece separada (ver montarLinha).
    $detalhe = preg_replace('/\bids\s+[\d,]+,?\s*/i', '', $detalhe);

    $detalhe = preg_replace_callback(
        '/\b(titular|volante|matricula|substituto sugerido)\s+(\d+)\b/i',
        function ($m) use ($colabPorMatricula) {
            $nome = $colabPorMatricula[$m[2]] ?? null;
            return $nome ? "{$m[1]} {$nome}" : $m[0];
        },
        $detalhe
    );

    $detalhe = preg_replace_callback(
        '/\bcentro\s+([A-Za-z0-9]+)\b/i',
        function ($m) use ($centroPorCodigo) {
            $desc = $centroPorCodigo[$m[1]] ?? null;
            return $desc ? "centro {$desc} ({$m[1]})" : $m[0];
        },
        $detalhe
    );

    // "fim 2026-09-08" -> "fim 08/09/2026" (DD/MM/AAAA, formato do resto do sistema; o texto bruto
    // guarda ISO).
    $detalhe = preg_replace_callback(
        '/\b(\d{4})-(\d{2})-(\d{2})\b/',
        fn ($m) => "{$m[3]}/{$m[2]}/{$m[1]}",
        $detalhe
    );

    return trim($detalhe, " ,");
}

// Ex: "3 registro(s) de Vale Transporte - ...": junta o "o que" (tabela/quantidade) com o detalhe
// enriquecido. Sem tabela nem detalhe, devolve '-'.
function montarDetalheCompleto(array $linha, array $colabPorMatricula, array $centroPorCodigo): string {
    $partes = [];
    if (!empty($linha['tabela'])) {
        $amigavel = TABELAS_AMIGAVEIS[$linha['tabela']] ?? $linha['tabela'];
        $partes[] = ($linha['quantidade'] ? $linha['quantidade'] . ' registro(s) de ' : '') . $amigavel;
    }
    $detalheEnriquecido = enriquecerDetalhe($linha['detalhe'] ?? null, $colabPorMatricula, $centroPorCodigo);
    if ($detalheEnriquecido !== '') $partes[] = $detalheEnriquecido;
    return count($partes) > 0 ? implode(' — ', $partes) : '-';
}

$acao = $_GET['acao'] ?? '';

if ($acao === 'listar') {
    $usuarioFiltro = trim((string) ($_GET['usuario'] ?? ''));
    $papelFiltro = trim((string) ($_GET['papel'] ?? ''));
    $dataInicioBr = trim((string) ($_GET['data_inicio'] ?? ''));
    $dataFimBr = trim((string) ($_GET['data_fim'] ?? ''));

    $condicoes = [];
    $params = [];

    if ($usuarioFiltro !== '') {
        $condicoes[] = 'a.usuario LIKE ?';
        $params[] = '%' . $usuarioFiltro . '%';
    }
    if ($papelFiltro !== '') {
        $condicoes[] = 'u.papel = ?';
        $params[] = $papelFiltro;
    }
    if ($dataInicioBr !== '') {
        $dataInicioIso = DateTime::createFromFormat('d/m/Y', $dataInicioBr);
        if (!$dataInicioIso) erroLogs('data_inicio invalida (use DD/MM/AAAA).');
        $condicoes[] = 'a.em >= ?';
        $params[] = $dataInicioIso->format('Y-m-d') . ' 00:00:00';
    }
    if ($dataFimBr !== '') {
        $dataFimIso = DateTime::createFromFormat('d/m/Y', $dataFimBr);
        if (!$dataFimIso) erroLogs('data_fim invalida (use DD/MM/AAAA).');
        $condicoes[] = 'a.em <= ?';
        $params[] = $dataFimIso->format('Y-m-d') . ' 23:59:59';
    }

    $where = count($condicoes) > 0 ? ('WHERE ' . implode(' AND ', $condicoes)) : '';

    // Limite de 2000 linhas; quem quiser mais reduz o periodo.
    $stmt = $pdo->prepare(
        "SELECT a.id, a.em, a.usuario_id, a.usuario, a.acao, a.tabela, a.quantidade, a.detalhe, a.ip, u.papel
           FROM auditoria a
           LEFT JOIN usuarios u ON u.id = a.usuario_id
           $where
       ORDER BY a.em DESC
          LIMIT 2000"
    );
    $stmt->execute($params);
    $linhas = $stmt->fetchAll();

    // Mapas em lote (1 query cada, nao 1 por linha) pra resolver matricula
    // -> nome e centro de custo -> descricao no enriquecimento abaixo.
    $colabPorMatricula = $pdo->query(
        'SELECT matricula, nome FROM colaboradores WHERE matricula IS NOT NULL AND matricula <> \'\''
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $centroPorCodigo = $pdo->query(
        'SELECT codigo, descricao FROM centros_custo WHERE descricao IS NOT NULL AND descricao <> \'\''
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($linhas as &$linha) {
        $linha['detalhe_completo'] = montarDetalheCompleto($linha, $colabPorMatricula, $centroPorCodigo);
    }
    unset($linha);

    echo json_encode(['dados' => $linhas]);
    exit;
}

erroLogs('Acao invalida: ' . $acao);
