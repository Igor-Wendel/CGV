<?php
// Aba Volantes Ociosos (coberturas/previsao.html): quais volantes entram nela, os dias em que cada um NAO pode entrar numa
// cobertura nova e os dias ociosos. Compartilhado por backend/api/previsao-ferias.php (a tela) e por registrar-ociosidade.php
// (a foto diaria do Historico de observacao). Quem inclui ja carregou backend/api/db.php.
require_once __DIR__ . '/protheus-colaboradores-helpers.php'; // centroCustoEhIntermitente

// Dias em que cada volante NAO pode entrar numa cobertura nova (matricula => [[inicio, fim], ...]), de $desde em diante.
// Mesma regra pra aba Volantes Ociosos e pro painel "Quem cobre". Ocupa: cobertura de ferias do SAF (qualquer contrato,
// do inicio da cobertura), as proprias ferias, e no CGV cobrir alguem, faltar numa cobertura ou estar ausente como titular.
// $comVagas: vaga aberta de desligamento/afastamento, inclusive as linhas ja designadas dela (so a aba). No painel nao
// conta: ferias tem prioridade e cortar-coberturas-ferias.php tira o volante das linhas do CGV nos dias da ferias.
// $ignorarFerias / $ignorarTitular (painel): a propria ferias e as linhas do CGV do proprio titular (falta, atestado nas
// ferias: e o mesmo posto, e o PFM paga o dia uma vez so - semDiasDeFeriasSaf) nao contam.
// $ate (opcional, AAAA-MM-DD): linhas do CGV so ate esse dia (Dashboard com periodo passado: nao le a tabela ate hoje).
function diasOcupadosVolantes(PDO $pdo, array $todas, string $desde, bool $comVagas, int $ignorarFerias = 0, string $ignorarTitular = '', string $ate = ''): array {
    $ocupado = [];
    $coberturasFerias = []; // matricula do volante => [inicio, fim] das coberturas de ferias, ate as ja acabadas (fim da vaga aberta)
    foreach ($todas as $o) {
        if ($ignorarFerias && (int) $o['id'] === $ignorarFerias) continue;
        // matricula_vol so vem com precisa_cobertura=Sim (feriasSafComVolante); transferencia de quem nao esta no cadastro do
        // CGV ocupa pela matricula do SAF (senao a mesma pessoa podia receber outra transferencia nas mesmas datas). Idem o emprestado
        // e o substituto do posto de origem (linha posto_origem de feriasSafComVolante).
        $vol = (string) ($o['matricula_vol'] ?: (ehTransferenciaSaf($o) || ehEmprestimoSaf($o) || !empty($o['posto_origem'])
            ? trim((string) ($o['volante_matricula'] ?? '')) : ''));
        if ($vol !== '') $coberturasFerias[$vol][] = [$o['cobertura_inicio'], $o['ferias_fim']];
        if ($o['ferias_fim'] < $desde) continue;
        $ocupado[(string) $o['matricula']][] = [$o['ferias_inicio'], $o['ferias_fim']];
        if ($vol !== '') $ocupado[$vol][] = [$o['cobertura_inicio'], $o['ferias_fim']];
    }
    $vagasAbertas = []; // "titular|motivo" das vagas abertas
    foreach (['solicitacoes_desligamento' => 'desligamento', 'solicitacoes_afastamento' => 'afastamento_indeterminado'] as $tabelaVaga => $motivoVaga) {
        if (!tabelaExiste($pdo, $tabelaVaga)) continue;
        foreach ($pdo->query("SELECT DISTINCT sc.matricula FROM `$tabelaVaga` v JOIN solicitacoes_cobertura sc ON sc.id = v.solicitacao_cobertura_id
                               WHERE v.fim_definido_em IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $m) $vagasAbertas["$m|$motivoVaga"] = true;
    }
    $stmt = $pdo->prepare("SELECT matricula, motivo, matricula_vol, ausencia_matricula_vol, DATE_FORMAT(STR_TO_DATE(data, '%d/%m/%Y'), '%Y-%m-%d') AS dia
                             FROM solicitacoes_cobertura WHERE STR_TO_DATE(data, '%d/%m/%Y') >= ? AND matricula <> ?"
                         . ($ate !== '' ? " AND STR_TO_DATE(data, '%d/%m/%Y') <= ?" : ''));
    $stmt->execute($ate !== '' ? [$desde, $ignorarTitular, $ate] : [$desde, $ignorarTitular]);
    foreach ($stmt->fetchAll() as $l) {
        if (!$comVagas && isset($vagasAbertas["{$l['matricula']}|{$l['motivo']}"])) continue; // painel: linha de vaga aberta nao ocupa
        foreach ([$l['matricula'], $l['matricula_vol'], $l['ausencia_matricula_vol']] as $m) {
            if ((string) $m !== '' && $m !== 'SEM_COBERTURA') $ocupado[(string) $m][] = [$l['dia'], $l['dia']];
        }
    }
    if (!$comVagas) return $ocupado;
    // Vaga aberta (sem Fim definido) ganha 1 linha por dia SEM volante (estender-vagas-helpers.php): quem esta na decisao
    // mais recente da vaga segue ocupado ate o fim do mes dessa decisao (mes do dia da linha ou do dia em que foi designada,
    // o que vier depois), enquanto a vaga estiver aberta. So linhas desta
    // vaga (do dia da abertura em diante: vaga antiga fechada do mesmo titular fica de fora). SEM_COBERTURA como decisao
    // mais recente libera o volante; cobertura de ferias encerra a vaga.
    foreach (['solicitacoes_desligamento' => 'desligamento', 'solicitacoes_afastamento' => 'afastamento_indeterminado'] as $tabelaVaga => $motivoVaga) {
        if (!tabelaExiste($pdo, $tabelaVaga)) continue;
        $stmt = $pdo->prepare("SELECT v.solicitacao_cobertura_id AS vaga, sc2.matricula_vol, STR_TO_DATE(sc2.data, '%d/%m/%Y') AS dia,
                                      GREATEST(STR_TO_DATE(sc2.data, '%d/%m/%Y'), COALESCE(DATE(sc2.fechado_em), '1000-01-01')) AS decidido
                                 FROM `$tabelaVaga` v
                                 JOIN solicitacoes_cobertura sc ON sc.id = v.solicitacao_cobertura_id
                                 JOIN solicitacoes_cobertura sc2 ON sc2.matricula = sc.matricula AND sc2.motivo = ?
                                      AND STR_TO_DATE(sc2.data, '%d/%m/%Y') >= STR_TO_DATE(sc.data, '%d/%m/%Y')
                                WHERE v.fim_definido_em IS NULL AND sc2.matricula_vol <> ''");
        $stmt->execute([$motivoVaga]);
        $ultimo = [];
        foreach ($stmt->fetchAll() as $l) if (!isset($ultimo[$l['vaga']]) || $l['dia'] > $ultimo[$l['vaga']]['dia']) $ultimo[$l['vaga']] = $l;
        foreach ($ultimo as $l) {
            if ($l['matricula_vol'] === 'SEM_COBERTURA') continue;
            // Cobertura de ferias que ainda nao tinha acabado no dia da designacao encerra a vaga na vespera dela (nunca antes da designacao).
            $fim = date('Y-m-t', strtotime($l['decidido']));
            foreach ($coberturasFerias[(string) $l['matricula_vol']] ?? [] as [$inicio, $fimCobertura]) {
                if ($fimCobertura >= $l['dia']) $fim = min($fim, max($l['dia'], date('Y-m-d', strtotime("$inicio -1 day"))));
            }
            $ocupado[(string) $l['matricula_vol']][] = [$l['dia'], $fim];
        }
    }
    return $ocupado;
}

// Centros dos volantes da aba Volantes Ociosos (e de quem pode receber nota nela): RH/Admin todos os volantes; Gestor o proprio pool.
// Intermitente fica de fora: parado nao custa (so recebe quando chamado).
function centrosVolantesOciosos(PDO $pdo, string $papel, int $usuarioId, array $centrosCusto, array $codigosVolante): array {
    $intermitentes = array_column(array_filter($centrosCusto, fn($c) => centroCustoEhIntermitente((string) ($c['descricao'] ?? ''))), 'codigo');
    $centros = array_values(array_diff($codigosVolante, $intermitentes));
    $pool = $papel === 'gestor' ? coberturaGestorLiberada($pdo, $usuarioId) : null;
    return $pool === null ? $centros : array_values(array_intersect($centros, $pool));
}

// Dias ociosos de cada volante dos $centros (centrosVolantesOciosos) de $de a $ate (AAAA-MM-DD): matricula do cadastro => [dia, ...]
// (sem normalizar: a mesma chave da nota), TODO volante dos centros, mesmo sem dia ocioso ([]). Mesma regra da aba (previsao.html):
// entre a admissao e o dia do desligamento, sem dia ocupado (diasOcupadosVolantes com as vagas abertas).
function diasOciososVolantes(PDO $pdo, array $todas, array $centros, string $de, string $ate): array {
    if (!$centros) return [];
    $ocupado = diasOcupadosVolantes($pdo, $todas, $de, true, 0, '', $ate);
    $stmt = $pdo->prepare('SELECT matricula, data_admissao, desligamento FROM colaboradores WHERE centro_custo IN ('
        . implode(',', array_fill(0, count($centros), '?')) . ')');
    $stmt->execute(array_values($centros));
    $iso = fn($d) => ($x = DateTime::createFromFormat('!d/m/Y', (string) $d)) ? $x->format('Y-m-d') : '';
    $ociosos = [];
    foreach ($stmt->fetchAll() as $c) {
        $matricula = (string) $c['matricula'];
        [$admissao, $saida] = [$iso($c['data_admissao']), $iso($c['desligamento'])];
        $ociosos[$matricula] = [];
        if (($saida && $saida < $de) || ($admissao && $admissao > $ate)) continue; // fora do periodo inteiro
        // Dias ocupados num conjunto, cortados no periodo: o laco era dias x intervalos e, com 1-2 anos no filtro do Dashboard,
        // ficava lento (revisao de 2026-11-13). Mesmo resultado.
        $ocupadoNoDia = [];
        foreach ($ocupado[$matricula] ?? [] as [$ini, $fim]) {
            for ($dia = max($ini, $de); $dia <= min($fim, $ate); $dia = date('Y-m-d', strtotime("$dia +1 day"))) $ocupadoNoDia[$dia] = true;
        }
        for ($dia = $de; $dia <= $ate; $dia = date('Y-m-d', strtotime("$dia +1 day"))) {
            if (($admissao && $dia < $admissao) || ($saida && $dia > $saida) || isset($ocupadoNoDia[$dia])) continue;
            $ociosos[$matricula][] = $dia;
        }
    }
    return $ociosos;
}
