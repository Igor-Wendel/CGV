<?php
// Corte de cobertura por ferias (container "sync", de hora em hora).
//
// Regra: ferias aprovada no SAF tem prioridade. Colaborador designado no CGV nos dias de uma ferias
// que ele cobre, ou da propria ferias, sai dessas linhas de solicitacoes_cobertura, que voltam a
// ficar abertas (fechar-solicitacoes-vencidas.php segue valendo). So do dia seguinte em diante e so
// a partir do dia em que a ferias passa a contar: dia passado e o dia de hoje ficam como estao, e o
// PFM nao paga a ferias nesses dias (diasCoberturaFeriasSaf).
//
// Grava dados, entao so age com MATRICULA vinda do SAF (nome repetido cortaria a pessoa errada) e
// com arquivo de ate 2h. Ferias cancelada ou alterada depois nao devolve o volante, porque o posto
// ja pode ter outra pessoa: cortes_ferias guarda o corte e a proxima rodada avisa. Arquivo sem nenhuma
// ferias nao conta como cancelamento (restauracao ou banco vazio no SAF avisaria todos os cortes de uma vez).
//
// Posto ja coberto (cortes_ferias.posto = 1): linha do PROPRIO titular (falta/atestado nas ferias, posto coberto no CGV enquanto
// era vaga em aberto) com outra pessoa, nos dias em que o volante do SAF cobre: o PFM paga o dia ao SAF (semDiasDeFeriasSaf),
// entao a outra pessoa sai e a linha fica com o volante das ferias (fechada: nao vira "Sem cobertura" no fechamento automatico,
// e o PFM paga o dia uma vez so). Esse corte vale enquanto a ferias tiver volante do SAF (nao a mesma pessoa) e so ate o ultimo
// dia dele no cadastro do CGV (desligamento; mesma regra do PFM, diasCoberturaFeriasSaf): depois disso o PFM nao pagaria a ele.
//
// Uso: php cortar-coberturas-ferias.php            (corta e avisa)
//      php cortar-coberturas-ferias.php --simular  (so lista o que seria feito, nao grava nada)

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado - script de linha de comando.');
}

require_once __DIR__ . '/../api/db.php';

function cortarFeriasLog(string $msg): void {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n");
}

// Mesmos destinatarios da ausencia de cobertura + o Preposto do titular (so quem ainda tem o papel: lista que sobrou de
// quem deixou de ser Preposto nao avisa).
function destinatariosCorte(PDO $pdo, string $centro, string $matriculaVol): array {
    $stmt = $pdo->prepare("SELECT c.usuario_id FROM usuario_centros_custo c JOIN usuarios u ON u.id = c.usuario_id
                            WHERE c.papel = 'preposto' AND c.centro_custo IN (?, 'TODOS')
                              AND (u.papel = 'preposto' OR EXISTS (SELECT 1 FROM usuario_papeis_extra e WHERE e.usuario_id = u.id AND e.papel = 'preposto'))");
    $stmt->execute([$centro]);
    return array_unique(array_merge(destinatariosCobertura($pdo, $centro, $matriculaVol), array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

$simular = in_array('--simular', $argv, true);
$pdo = conectarBanco();

if ((string) SAF_COBERTURA_JSON === '') {
    cortarFeriasLog('Integracao com o SAF nao configurada neste ambiente - nada a fazer.');
    exit(0);
}

try {
    $ferias = feriasSafComVolante($pdo, $avisoSaf);
    if ($avisoSaf === null && time() - (int) filemtime((string) SAF_COBERTURA_JSON) > 2 * 3600) {
        $avisoSaf = 'o arquivo de cobertura do SAF tem mais de 2h';
    }
    if ($avisoSaf !== null) {
        cortarFeriasLog("Corte nao executado: $avisoSaf");
        if (!$simular) registrarErroSyncStatus($pdo, 'cortar_ferias', $avisoSaf);
        exit(0);
    }

    // Quem sai de cada ferias: quem cobre (so com a matricula do SAF), o proprio titular e, no posto dele, quem nao e o volante
    // do SAF ($posto = matricula desse volante; so com a coluna cortes_ferias.posto, ver migrar-banco.php).
    $comPosto = colunaExiste($pdo, 'cortes_ferias', 'posto');
    $alvos = [];
    $vigentes = [];
    foreach ($ferias as $f) {
        $titular = trim((string) ($f['matricula'] ?? ''));
        // Posto de origem do emprestado (linhasPostoOrigemSaf): ele nao esta de ferias (sai pela linha das ferias que cobre). Trecho de
        // quem cobria antes de uma troca (feriasSafComVolante): o titular ja sai pela linha das ferias; o trecho so corta e passa o
        // posto para quem cobria, nos dias dele. Quem saiu na troca fica livre do dia dela: o corte antigo dele deixa de valer e a
        // proxima rodada avisa ("FÉRIAS ALTERADA"), como em qualquer troca de quem cobre.
        if (empty($f['posto_origem']) && empty($f['trecho_anterior'])) $alvos[] = [$f, $titular, $f['ferias_inicio'], 'porque está de férias', ''];
        $vigentes["$titular|{$f['ferias_inicio']}|{$f['ferias_fim']}|$titular"] = true;
        $matriculaVol = trim((string) ($f['volante_matricula'] ?? ''));
        // Vigente mesmo se a matricula mudou no CGV (readmitido): a ferias nao foi alterada.
        if ($matriculaVol !== '') $vigentes["$titular|{$f['ferias_inicio']}|{$f['ferias_fim']}|$matriculaVol"] = true;
        if ($matriculaVol !== '' && $f['matricula_vol'] === $matriculaVol) {
            $alvos[] = [$f, $matriculaVol, $f['cobertura_inicio'], empty($f['posto_origem']) ? "para cobrir as férias de {$f['nome']}"
                : "para cobrir o posto de {$f['nome']} (emprestado para as férias de {$f['ferias_titular']})", ''];
            $vigentes["$titular|{$f['ferias_inicio']}|{$f['ferias_fim']}|posto"] = true;
            if ($comPosto) $alvos[] = [$f, $titular, $f['cobertura_inicio'], empty($f['posto_origem'])
                ? "porque o posto já está coberto pelas férias do SAF ({$f['volante']})" : "porque o posto já está coberto pelo substituto do empréstimo ({$f['volante']})", $matriculaVol];
        }
    }

    // Corte de ferias que sumiu do arquivo: o volante ficou livre e a cobertura nao volta sozinha.
    $hoje = date('Y-m-d');
    $stmtResolver = $pdo->prepare('UPDATE cortes_ferias SET resolvido_em = NOW() WHERE id = ?');
    $cortesAbertos = count($ferias) > 0 ? $pdo->query('SELECT * FROM cortes_ferias WHERE resolvido_em IS NULL')->fetchAll() : [];
    foreach ($cortesAbertos as $c) {
        $chave = empty($c['posto']) ? $c['matricula_vol'] : 'posto';
        $vigente = isset($vigentes["{$c['titular_ferias']}|{$c['ferias_inicio']}|{$c['ferias_fim']}|$chave"]);
        if ($vigente && $c['ferias_fim'] >= $hoje) continue;
        if ($simular) {
            if (!$vigente && $c['ferias_fim'] >= $hoje) echo "AVISARIA: ferias de {$c['nome_ferias']} sem o corte de {$c['nome_vol']} ({$c['cobertura_de']} {$c['periodo']})\n";
            continue;
        }
        if (!$vigente && $c['ferias_fim'] >= $hoje) {
            $mensagem = empty($c['posto'])
                ? "FÉRIAS ALTERADA: as férias de {$c['nome_ferias']} foram canceladas ou alteradas (no SAF ou pela Previsão do CGV), e {$c['nome_vol']} pode ter ficado livre."
                    . " A cobertura de {$c['cobertura_de']} cortada ({$c['periodo']}) não volta sozinha: confira e designe de novo, se precisar."
                : "FÉRIAS ALTERADA: as férias de {$c['nome_ferias']} foram canceladas ou alteradas (no SAF ou pela Previsão do CGV). As linhas dele"
                    . " ({$c['periodo']}) que eram de {$c['nome_vol']} ficaram com o volante das férias: confira quem cobre esses dias e corrija, se precisar.";
            criarNotificacoes($pdo, destinatariosCorte($pdo, (string) $c['centro_custo'], (string) $c['matricula_vol']), 'corte_ferias', $mensagem, null, null);
            registrarAuditoria($pdo, null, 'corte_ferias_alterada', ['tabela' => 'cortes_ferias', 'quantidade' => 1, 'detalhe' => $mensagem]);
        }
        $stmtResolver->execute([$c['id']]);
    }

    // Ultimo dia no CGV de quem cobre o posto ('' = ativa; a mesma matricula pode ter mais de uma linha), como no PFM.
    $ultimoDiaVol = [];
    $matsPosto = array_values(array_unique(array_filter(array_map(fn($a) => $a[4] !== '' ? (string) $a[0]['matricula_vol'] : '', $alvos))));
    if ($matsPosto) {
        $stmtColab = $pdo->prepare('SELECT matricula, desligamento FROM colaboradores WHERE matricula IN (' . implode(',', array_fill(0, count($matsPosto), '?')) . ')');
        $stmtColab->execute($matsPosto);
        foreach ($stmtColab->fetchAll() as $c) {
            $saida = DateTime::createFromFormat('!d/m/Y', (string) $c['desligamento']);
            $saida = $saida ? $saida->format('Y-m-d') : '';
            $atual = $ultimoDiaVol[$c['matricula']] ?? null;
            $ultimoDiaVol[$c['matricula']] = ($atual === '' || $saida === '') ? '' : max((string) $atual, $saida);
        }
    }

    $amanha = date('Y-m-d', strtotime('tomorrow'));
    $cortadas = 0;
    foreach ($alvos as [$f, $matricula, $inicio, $motivo, $posto]) {
        if ($matricula === '' || $f['ferias_fim'] < $amanha) continue;
        $dias = [];
        $dia = new DateTime(max($amanha, $inicio));
        $fim = new DateTime($posto === '' ? $f['ferias_fim'] : min(array_filter([$f['ferias_fim'], $ultimoDiaVol[$f['matricula_vol']] ?? ''])));
        for (; $dia <= $fim; $dia->modify('+1 day')) $dias[] = $dia->format('d/m/Y');
        if (count($dias) === 0) continue;

        $marcadores = implode(',', array_fill(0, count($dias), '?'));
        $pdo->beginTransaction();
        // matricula <> titular: a linha do proprio titular da ferias nao e outra cobertura (ela e o alvo $posto).
        $stmt = $pdo->prepare($posto === ''
            ? "SELECT id, centro_custo, matricula, nome, data, matricula_vol, nome_vol FROM solicitacoes_cobertura
                WHERE matricula_vol = ? AND data IN ($marcadores) AND matricula <> ?
                ORDER BY STR_TO_DATE(data, '%d/%m/%Y') FOR UPDATE"
            : "SELECT id, centro_custo, matricula, nome, data, matricula_vol, nome_vol FROM solicitacoes_cobertura
                WHERE matricula = ? AND data IN ($marcadores) AND matricula_vol NOT IN ('', 'SEM_COBERTURA', ?)
                  AND COALESCE(ausencia_matricula_vol, '') <> ?
                ORDER BY STR_TO_DATE(data, '%d/%m/%Y') FOR UPDATE");
        // (posto: linha com a falta do volante das ferias registrada fica com quem foi designado - o SAF nao paga esse dia)
        $stmt->execute($posto === '' ? array_merge([$matricula], $dias, [(string) $f['matricula']]) : array_merge([$matricula], $dias, [$posto, $posto]));
        $linhas = $stmt->fetchAll();
        if (count($linhas) === 0) {
            $pdo->rollBack();
            continue;
        }

        // Um aviso por titular, centro e quem saiu, com o periodo que ficou sem volante.
        $grupos = [];
        foreach ($linhas as $l) $grupos[$l['matricula'] . '|' . $l['centro_custo'] . '|' . $l['matricula_vol']][] = $l;
        $avisos = [];
        foreach ($grupos as $doTitular) {
            $l = $doTitular[0];
            $datas = array_column($doTitular, 'data');
            $periodo = count($datas) === 1 ? "em {$datas[0]}" : "de {$datas[0]} a " . end($datas);
            $avisos[] = [
                'linha' => $l,
                'periodo' => $periodo,
                'dias' => count($datas),
                'destinatarios' => destinatariosCorte($pdo, (string) $l['centro_custo'], (string) $l['matricula_vol']),
                'mensagem' => "COBERTURA CORTADA: {$l['nome_vol']} saiu da cobertura de {$l['nome']} ("
                    . rotuloCentroCusto($pdo, (string) $l['centro_custo']) . ") $periodo $motivo." . ($posto === '' ? ' Esses dias ficaram sem cobertura.' : ' A linha ficou com ele.'),
            ];
        }

        if ($simular) {
            $pdo->rollBack();
            foreach ($avisos as $a) echo "CORTARIA: {$a['mensagem']}\n";
            $cortadas += count($linhas);
            continue;
        }

        $ids = array_column($linhas, 'id');
        $marcadoresIds = implode(',', array_fill(0, count($ids), '?'));
        if ($posto === '') {
            $pdo->prepare("UPDATE solicitacoes_cobertura SET matricula_vol = '', nome_vol = '', vol_salario = NULL, fechado_em = NULL WHERE id IN ($marcadoresIds)")
                ->execute($ids);
        } else {
            // Posto ja coberto: a linha fica com o volante das ferias (matricula_vol do CGV), fechada.
            $stmtNome = $pdo->prepare('SELECT nome FROM colaboradores WHERE matricula = ? LIMIT 1');
            $stmtNome->execute([$f['matricula_vol']]);
            $pdo->prepare("UPDATE solicitacoes_cobertura SET matricula_vol = ?, nome_vol = ?, vol_salario = ?, fechado_em = COALESCE(fechado_em, NOW()) WHERE id IN ($marcadoresIds)")
                ->execute(array_merge([$f['matricula_vol'], (string) ($stmtNome->fetchColumn() ?: $f['volante']), snapshotVolSalario($pdo, (string) $f['matricula_vol'])], $ids));
        }
        // Posto de origem do emprestado (linhasPostoOrigemSaf): o nome guardado e o das ferias do titular - o emprestado nao esta de
        // ferias, e e esse nome que vai no aviso de "ferias alterada" se o emprestimo mudar depois.
        $nomeFerias = empty($f['posto_origem']) ? (string) $f['nome'] : mb_substr("{$f['ferias_titular']} (posto de origem de {$f['nome']})", 0, 150);
        $stmtCorte = $pdo->prepare($comPosto
            ? 'INSERT INTO cortes_ferias (titular_ferias, nome_ferias, ferias_inicio, ferias_fim, matricula_vol, nome_vol, centro_custo, cobertura_de, periodo, criado_em, posto)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ' . ($posto === '' ? 0 : 1) . ')'
            : 'INSERT INTO cortes_ferias (titular_ferias, nome_ferias, ferias_inicio, ferias_fim, matricula_vol, nome_vol, centro_custo, cobertura_de, periodo, criado_em)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        foreach ($avisos as $a) {
            $stmtCorte->execute([$f['matricula'], $nomeFerias, $f['ferias_inicio'], $f['ferias_fim'], $a['linha']['matricula_vol'],
                $a['linha']['nome_vol'], $a['linha']['centro_custo'], $a['linha']['nome'], $a['periodo']]);
        }
        $pdo->commit();
        $cortadas += count($ids);

        foreach ($avisos as $a) {
            criarNotificacoes($pdo, $a['destinatarios'], 'corte_ferias', $a['mensagem'], null, null);
            registrarAuditoria($pdo, null, 'corte_cobertura_ferias', [
                'tabela' => 'solicitacoes_cobertura',
                'quantidade' => $a['dias'],
                'detalhe' => $a['mensagem'],
            ]);
        }
        cortarFeriasLog(count($ids) . ' dia(s) cortado(s) ' . ($posto === '' ? "de $matricula" : 'do posto do titular') . " (ferias de $nomeFerias).");
    }

    cortarFeriasLog(($simular ? 'Simulacao concluida' : 'Corte concluido') . ": $cortadas dia(s).");
    if (!$simular) registrarSucessoSyncStatus($pdo, 'cortar_ferias');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cortarFeriasLog('ERRO: ' . $e->getMessage());
    if (!$simular) registrarErroSyncStatus($pdo, 'cortar_ferias', $e->getMessage());
    exit(1);
}
