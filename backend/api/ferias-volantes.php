<?php
// ============================================================
// FERIAS DO SAF PRA TELA FUNCIONARIOS (colaboradores/funcionarios.html)
// ============================================================
// Ferias que tocam o mes atual, lidas do JSON do SAF (feriasSafComVolante, db.php): chip amarelo de
// quem cobre e de quem esta de ferias, calendario do volante e tabela Volantes do Preposto.
// Preposto ve as dos proprios centros; Gestor tambem as do proprio pool de volantes (dados.php). Pool
// "Todos" (null) = qualquer volante, mas nao os titulares comuns fora dos centros dele.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../scripts/protheus-colaboradores-helpers.php';

$pdo = conectarBanco();
$usuarioId = verificarSessao($pdo);
$papel = exigirPapel($pdo, $usuarioId, ['rh', 'administrador', 'gestor', 'preposto']);
header('Content-Type: application/json');

$mesInicio = date('Y-m-01');
$mesFim = date('Y-m-t');
$ferias = array_filter(feriasSafComVolante($pdo, $avisoSaf), fn($f) => $f['ferias_fim'] >= $mesInicio && $f['ferias_inicio'] <= $mesFim);

$volantes = [];
$matriculasVol = array_values(array_unique(array_filter(array_column($ferias, 'matricula_vol'))));
if ($matriculasVol) {
    $stmt = $pdo->prepare('SELECT matricula, nome, centro_custo, funcao FROM colaboradores WHERE matricula IN (' . implode(',', array_fill(0, count($matriculasVol), '?')) . ')');
    $stmt->execute($matriculasVol);
    foreach ($stmt->fetchAll() as $c) $volantes[$c['matricula']] = $c;
}

$centros = in_array($papel, ['gestor', 'preposto'], true) ? centrosCustoLiberados($pdo, $usuarioId, $papel) : null;
$pool = $papel === 'gestor' ? coberturaGestorLiberada($pdo, $usuarioId) : [];
if ($centros !== null) {
    $todosVolantes = $pool === null ? codigosCentrosVolante($pdo) : null;
    $ferias = array_filter($ferias, function ($f) use ($centros, $pool, $volantes, $todosVolantes) {
        $centro = trim((string) ($f['centro_custo'] ?? ''));
        if (in_array($centro, $centros, true)) return true;
        if ($todosVolantes !== null) return $f['matricula_vol'] !== null || in_array($centro, $todosVolantes, true);
        return in_array($centro, $pool, true) || in_array($volantes[$f['matricula_vol']]['centro_custo'] ?? null, $pool, true);
    });
}

echo json_encode(['dados' => array_map(fn($f) => [
    'matricula' => $f['matricula'],
    'nome' => $f['nome'],
    'centro_custo' => trim((string) ($f['centro_custo'] ?? '')),
    'ferias_inicio' => $f['ferias_inicio'],
    'ferias_fim' => $f['ferias_fim'],
    'cobertura_inicio' => $f['cobertura_inicio'],
    'matricula_vol' => $f['matricula_vol'],
    'vol_nome' => $volantes[$f['matricula_vol']]['nome'] ?? '',
    'vol_centro_custo' => $volantes[$f['matricula_vol']]['centro_custo'] ?? '',
    'vol_funcao' => $volantes[$f['matricula_vol']]['funcao'] ?? '',
    // Colaborador emprestado com substituto (linhasPostoOrigemSaf, db.php): o substituto cobre o posto de origem do emprestado
    // ("matricula"/"nome" = o emprestado, que nao esta de ferias).
    'posto_origem' => !empty($f['posto_origem']),
    // Trecho de quem cobria antes de uma troca de quem cobre (feriasSafComVolante, db.php): ferias_inicio..ferias_fim = os dias dele;
    // o titular nao esta "de ferias" so nesse trecho (a linha das ferias diz isso).
    'trecho_anterior' => !empty($f['trecho_anterior']),
], array_values($ferias)), 'aviso_saf' => $avisoSaf]);
