<?php
// Conferencia do retrato (salario/periculosidade/insalubridade gravados em
// solicitacoes_cobertura, ver schema.sql) depois que o sync de colaboradores volta de uma
// falha; chamada no fim de sync-protheus-colaboradores.php.
//
// Se o sync das 3h falha, as solicitacoes criadas ate ele voltar gravam o cadastro da
// vespera. Ao voltar, compara o gravado com o cadastro atualizado:
//   - igual: nao faz nada;
//   - salario diferente: busca no historico salarial do Protheus (SR3010/SR3020, verba 000)
//     o valor vigente no dia da solicitacao;
//   - adicional diferente, ou salario sem historico confiavel: regrava com o valor atual e
//     avisa RH/Administrador (sync_status 'retrato_solicitacoes', lida por saude-sync.php).
//
// So entram solicitacoes dos dias sem sincronizacao; nas mais antigas a diferenca e normal
// (e o reajuste que o retrato existe para preservar).

require_once __DIR__ . '/../api/db.php';

// Mesma folga de verificar-saude-sync.php/saude-sync.php: so e falha se o sync anterior com
// sucesso foi ha mais de 26h.
const RETRATO_HORAS_SEM_SYNC = 26;

// Historico ja ordenado por data -> valor da ultima alteracao com data <=
// $diaYmd ("AAAAMMDD"), ou null se nenhuma alteracao e anterior ao dia.
function retratoSalarioVigenteNoDia(array $historico, string $diaYmd): ?float {
    $valor = null;
    foreach ($historico as [$data, $v]) {
        if ($data > $diaYmd) break;
        $valor = $v;
    }
    return $valor;
}

// [matricula => [[AAAAMMDD, valor], ...]] em ordem cronologica. Data invalida
// (o Protheus tem lancamentos com ano digitado errado, ex: "92020801") e
// descartada - senao ela viraria a "ultima alteracao" pra sempre.
function retratoHistoricoSalarial(PDO $protheusPdo, array $matriculas): array {
    $brutas = [];
    foreach (array_chunk(array_values(array_unique($matriculas)), 500) as $lote) {
        $marcadores = implode(',', array_fill(0, count($lote), '?'));
        $stmt = $protheusPdo->prepare(
            "SELECT RTRIM(R3_MAT) AS MAT, RTRIM(R3_DATA) AS DATA, R3_VALOR AS VALOR, RTRIM(R3_SEQ) AS SEQ, R_E_C_N_O_ AS RECNO
               FROM SR3010 WHERE R3_MAT IN ($marcadores) AND D_E_L_E_T_ <> '*' AND R3_PD = '000'
             UNION ALL
             SELECT RTRIM(R3_MAT), RTRIM(R3_DATA), R3_VALOR, RTRIM(R3_SEQ), R_E_C_N_O_
               FROM SR3020 WHERE R3_MAT IN ($marcadores) AND D_E_L_E_T_ <> '*' AND R3_PD = '000'"
        );
        $stmt->execute(array_merge($lote, $lote));
        foreach ($stmt->fetchAll() as $l) {
            $data = (string) $l['DATA'];
            if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $data, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) continue;
            $brutas[(string) $l['MAT']][] = [$data, (float) $l['VALOR'], (string) $l['SEQ'], (int) $l['RECNO']];
        }
    }
    $historico = [];
    foreach ($brutas as $mat => $linhas) {
        usort($linhas, fn($a, $b) => [$a[0], $a[2], $a[3]] <=> [$b[0], $b[2], $b[3]]);
        $historico[$mat] = array_map(fn($l) => [$l[0], $l[1]], $linhas);
    }
    return $historico;
}

function retratoValoresDiferem($a, $b): bool {
    if ($a === null || $b === null) return $a !== $b;
    return abs((float) $a - (float) $b) >= 0.005;
}

function retratoMoeda($v): string {
    return $v === null ? 'vazio' : number_format((float) $v, 2, ',', '.');
}

// $sucessoAnterior: sync_status.executado_em da chave 'colaboradores' lido
// ANTES desta rodada registrar o proprio sucesso (null = nunca rodou).
function reconciliarRetratoAposFalha(PDO $pdo, PDO $protheusPdo, ?string $sucessoAnterior, callable $log): void {
    if ($sucessoAnterior === null) return;
    if ((time() - strtotime($sucessoAnterior)) / 3600 <= RETRATO_HORAS_SEM_SYNC) return;
    if (!colunaExiste($pdo, 'solicitacoes_cobertura', 'titular_salario')) return;

    $log("Sync anterior com sucesso foi em $sucessoAnterior (mais de " . RETRATO_HORAS_SEM_SYNC . 'h) - conferindo o retrato das solicitacoes criadas nesse intervalo...');

    // Titular: linha criada durante a falha (criado_em NULL = linha de vaga
    // de desligamento/afastamento, que nasce sempre no proprio dia). Quem
    // cobriu: cobertura designada durante a falha (fechado_em).
    $stmt = $pdo->prepare(
        "SELECT sc.id, sc.matricula, sc.data, sc.matricula_vol,
                sc.titular_salario, sc.titular_periculosidade, sc.titular_insalubridade_grau, sc.vol_salario,
                (sc.criado_em IS NULL OR sc.criado_em > ?) AS checar_titular,
                (sc.fechado_em > ? AND sc.matricula_vol NOT IN ('', 'SEM_COBERTURA')) AS checar_vol,
                t.id AS t_id, t.salario AS t_salario, t.periculosidade AS t_peric, t.insalubridade_grau AS t_insal,
                v.id AS v_id, v.salario AS v_salario
           FROM solicitacoes_cobertura sc
           LEFT JOIN colaboradores t ON t.matricula = sc.matricula
           LEFT JOIN colaboradores v ON v.matricula = sc.matricula_vol
          WHERE STR_TO_DATE(sc.data, '%d/%m/%Y') > DATE(?)
            AND (sc.criado_em IS NULL OR sc.criado_em > ?
                 OR (sc.fechado_em > ? AND sc.matricula_vol NOT IN ('', 'SEM_COBERTURA')))"
    );
    $stmt->execute(array_fill(0, 5, $sucessoAnterior));

    $pendenciasSalario = []; // decididas pelo historico do Protheus, mais abaixo
    $alteracoes = [];        // [id, coluna, valorNovo]
    $avisos = [];            // texto curto por caso sem data confirmada

    foreach ($stmt->fetchAll() as $l) {
        $dia = DateTime::createFromFormat('d/m/Y', (string) $l['data']);
        if (!$dia) continue;

        if ((int) $l['checar_titular'] === 1 && $l['t_id'] !== null) {
            if ($l['t_salario'] !== null && retratoValoresDiferem($l['titular_salario'], $l['t_salario'])) {
                $pendenciasSalario[] = ['id' => $l['id'], 'coluna' => 'titular_salario', 'mat' => (string) $l['matricula'],
                                        'dia' => $dia, 'gravado' => $l['titular_salario'], 'atual' => (float) $l['t_salario']];
            }
            foreach (['titular_periculosidade' => ['t_peric', 'periculosidade'], 'titular_insalubridade_grau' => ['t_insal', 'insalubridade']] as $coluna => [$campoAtual, $nome]) {
                $gravado = $l[$coluna];
                $atual = (int) $l[$campoAtual];
                if ($gravado !== null && (int) $gravado === $atual) continue;
                $alteracoes[] = [$l['id'], $coluna, $atual];
                // Retrato vazio (titular ainda nao existia no cadastro local
                // quando a linha nasceu) so e preenchido - nao e "mudanca".
                if ($gravado !== null) {
                    $avisos[] = "{$l['matricula']} dia {$l['data']} $nome " . (int) $gravado . "->$atual";
                }
            }
        }

        if ((int) $l['checar_vol'] === 1 && $l['v_id'] !== null && $l['v_salario'] !== null
            && retratoValoresDiferem($l['vol_salario'], $l['v_salario'])) {
            $pendenciasSalario[] = ['id' => $l['id'], 'coluna' => 'vol_salario', 'mat' => (string) $l['matricula_vol'],
                                    'dia' => $dia, 'gravado' => $l['vol_salario'], 'atual' => (float) $l['v_salario']];
        }
    }

    $mantidos = 0;
    if ($pendenciasSalario) {
        $historico = retratoHistoricoSalarial($protheusPdo, array_column($pendenciasSalario, 'mat'));
        $hojeYmd = date('Ymd');
        foreach ($pendenciasSalario as $p) {
            $hist = $historico[$p['mat']] ?? [];
            // So confia no historico se o ultimo valor dele (ate hoje) bate
            // com o cadastro - senao o proprio Protheus esta inconsistente
            // pra essa matricula (ou ela nao tem historico nenhum).
            $vigenteHoje = retratoSalarioVigenteNoDia($hist, $hojeYmd);
            $vigenteNoDia = ($vigenteHoje !== null && abs($vigenteHoje - $p['atual']) < 0.01)
                ? retratoSalarioVigenteNoDia($hist, $p['dia']->format('Ymd'))
                : null;

            $novo = $vigenteNoDia ?? $p['atual'];
            if (!retratoValoresDiferem($p['gravado'], $novo)) {
                $mantidos++; // reajuste so passou a valer depois desse dia
                continue;
            }
            $alteracoes[] = [$p['id'], $p['coluna'], $novo];
            if ($vigenteNoDia === null && $p['gravado'] !== null) {
                $quem = $p['coluna'] === 'vol_salario' ? 'salario de quem cobriu' : 'salario';
                $avisos[] = "{$p['mat']} dia {$p['dia']->format('d/m/Y')} $quem " . retratoMoeda($p['gravado']) . '->' . retratoMoeda($novo);
            }
        }
    }

    if ($alteracoes) {
        $pdo->beginTransaction();
        try {
            foreach ($alteracoes as [$id, $coluna, $valor]) {
                // $coluna vem sempre das 4 constantes acima, nunca de entrada externa.
                $pdo->prepare("UPDATE solicitacoes_cobertura SET `$coluna` = ? WHERE id = ?")->execute([$valor, $id]);
                $log("Retrato corrigido: solicitacao $id, $coluna = " . ($valor ?? 'NULL'));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    $log(count($alteracoes) . " valor(es) de retrato corrigido(s), $mantidos mantido(s) (reajuste posterior ao dia), "
        . count($avisos) . ' sem data confirmada (aviso pro RH/Administrador).');

    if ($avisos) {
                // Lista todos os casos, um por linha (o RH nao acessa o log do Docker); o alerta do login
                // mostra o comeco e oferece o arquivo completo (backend/filtro.js).
                // So ASCII: registrarErroSyncStatus corta por bytes, e cortar um acento quebraria o json_encode.
        $msg = count($avisos) . " valor(es) de solicitacoes dos dias sem sincronizacao mudaram no Protheus sem data confirmada e foram regravados com o valor atual. Confira:\n"
            . implode("\n", $avisos);
        registrarErroSyncStatus($pdo, 'retrato_solicitacoes', $msg, 60000);
    }
}
