<?php
// ============================================================
// VAGA DE AFASTAMENTO (coberturas/solicitacao.html, motivo "afastamento_indeterminado"):
// vaga em aberto (sem Fim) + deteccao automatica de substituto quando um colaborador novo
// aparece no centro de custo. O titular continua ATIVO (afastado por tempo indeterminado).
// Motivo distinto de 'afastamento' (Atestado > 15 dias, sempre fechado) pra nunca se misturar
// numa consulta. Tabelas proprias: solicitacoes_afastamento, solicitacao_afastamento_baseline,
// solicitacao_afastamento_descartado.
// Uso: afastamento-confirmacao.php?acao=abrir|pendente|confirmar|definir_fim_manual
// ============================================================
require_once __DIR__ . '/db.php';

$pdo = conectarBanco();
$usuarioId = verificarSessao($pdo);
header('Content-Type: application/json');

// So Preposto usa esta tela; RH/Gestor so designam cobertura (cobertura-solicitacao.php) depois que o Fim existe. O RH/Administrador
// tambem cancela a vaga aberta por engano (acao=cancelar, com motivo - ver acaoCancelar).
$papel = exigirPapel($pdo, $usuarioId, ($_GET['acao'] ?? '') === 'cancelar' ? ['preposto', 'rh', 'administrador'] : ['preposto']);

function erro($msg, $status = 400) {
    http_response_code($status);
    echo json_encode(['error' => $msg]);
    exit;
}

$acao = $_GET['acao'] ?? '';

if ($acao === 'abrir') {
    acaoAbrir($pdo, $usuarioId);
} elseif ($acao === 'pendente') {
    acaoPendente($pdo, $usuarioId);
} elseif ($acao === 'confirmar') {
    acaoConfirmar($pdo, $usuarioId);
} elseif ($acao === 'definir_fim_manual') {
    acaoDefinirFimManual($pdo, $usuarioId);
} elseif ($acao === 'cancelar') {
    acaoCancelar($pdo, $usuarioId, $papel);
} else {
    erro('Acao invalida.');
}

// Cria a linha do dia INICIO em solicitacoes_cobertura + a vaga em solicitacoes_afastamento
// + o baseline (quem ja estava no centro), numa transacao so.
function acaoAbrir($pdo, $usuarioId) {
    $corpo = json_decode(file_get_contents('php://input'), true);
    if (!is_array($corpo)) erro('Corpo invalido, esperado um objeto JSON');

    $filial = trim((string) ($corpo['filial'] ?? ''));
    $centroCusto = trim((string) ($corpo['centro_custo'] ?? ''));
    $matricula = trim((string) ($corpo['matricula'] ?? ''));
    $nome = trim((string) ($corpo['nome'] ?? ''));
    $funcao = trim((string) ($corpo['funcao'] ?? ''));
    $dataInicio = trim((string) ($corpo['data_inicio'] ?? ''));
    // Observacao do Preposto (opcional): mesma regra da Nova Solicitacao comum (dados.php); o cron copia da ancora.
    $observacaoBruta = $corpo['observacao'] ?? null;
    if ($observacaoBruta !== null && !is_string($observacaoBruta)) erro('Observacao invalida.');
    $observacao = trim((string) $observacaoBruta);

    if ($matricula === '' || $centroCusto === '' || $dataInicio === '') {
        erro('Preencha colaborador, centro de custo e data de inicio.');
    }
    if (mb_strlen($observacao) > 300) erro('A observacao passa de 300 caracteres.');
    if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dataInicio)) {
        erro('Data de inicio invalida.');
    }
    if (dataBrAntesDeHoje($dataInicio)) erro('Não é possível abrir uma solicitação com data anterior a hoje.'); // so de hoje em diante (db.php)
    // Escala obrigatoria e o inicio na paridade da 12x36 par/impar (mesma regra da Nova Solicitacao comum, db.php); o cron copia da ancora.
    $escala = $corpo['escala'] ?? null;
    if (!escalaSolicitacaoValida($escala)) erro('Selecione a escala (se o campo Escala não aparece, atualize a página com F5).');
    if ($msgParidade = erroParidadeEscala($escala, $dataInicio)) erro($msgParidade);

    // Mesma checagem de escopo do dados.php pro Preposto.
    $centrosPermitidos = centrosCustoLiberados($pdo, $usuarioId, 'preposto');
    if ($centrosPermitidos !== null && !in_array($centroCusto, $centrosPermitidos, true)) {
        erro('Centro de custo fora do seu escopo.', 403);
    }
    // O titular continua ativo: e do centro enviado no cadastro (titularDoCentro, db.php). Desligamento nao confere - o titular pode ja
    // ter saido do cadastro, e a vaga vale tambem para quem foi transferido.
    if (!titularDoCentro($pdo, $matricula, $centroCusto)) erro('Esse colaborador não é do centro de custo informado (atualize a página com F5).', 403);

    // Retrato congelado do titular (ver schema.sql), usado pelo PFM no calculo do mes certo: do cadastro, onde titularDoCentro (acima)
    // garante que ele esta - a busca no Protheus de snapshotTitularVaga (db.php) so vale para o Desligamento. Antes da trava e da transacao.
    $retratoTitular = snapshotTitularVaga($pdo, $matricula);

    // Impede 2 vagas abertas ao mesmo tempo pro mesmo titular (2 de afastamento, ou afastamento +
    // desligamento). Mesmo GET_LOCK de desligamento-confirmacao.php (ver la).
    $chaveLockVaga = 'vaga_titular_' . $matricula;
    $stmtLock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $stmtLock->execute([$chaveLockVaga]);
    if ($stmtLock->fetchColumn() != 1) {
        erro('Nao foi possivel confirmar o estado atual deste colaborador agora (outra operacao em andamento) - tente novamente.', 409);
    }

    $stmtExistente = $pdo->prepare(
        'SELECT 1 FROM solicitacoes_afastamento WHERE matricula_titular = ? AND fim_definido_em IS NULL LIMIT 1'
    );
    $stmtExistente->execute([$matricula]);
    if ($stmtExistente->fetchColumn() !== false) {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$chaveLockVaga]);
        erro('Ja existe uma vaga de afastamento em aberto para este colaborador.', 409);
    }
    $stmtExistenteDesligamento = $pdo->prepare(
        'SELECT 1 FROM solicitacoes_desligamento WHERE matricula_titular = ? AND fim_definido_em IS NULL LIMIT 1'
    );
    $stmtExistenteDesligamento->execute([$matricula]);
    if ($stmtExistenteDesligamento->fetchColumn() !== false) {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$chaveLockVaga]);
        erro('Ja existe uma vaga de desligamento em aberto para este colaborador - nao e possivel abrir afastamento ao mesmo tempo.', 409);
    }

    // Maximo 1 linha por dia por titular (existeSolicitacaoNoDia em db.php): cobre outros motivos no dia aberto agora.
    if (existeSolicitacaoNoDia($pdo, $matricula, $dataInicio)) {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$chaveLockVaga]);
        erro('Este colaborador ja tem uma solicitacao registrada nesse dia.', 409);
    }
    // Dia de ferias do titular no SAF (feriasSafDoTitularNoDia, db.php). O desligamento nao confere: quem saiu tem as ferias
    // canceladas pelo SAF no sync.
    if ($periodo = feriasSafDoTitularNoDia($matricula, $dataInicio)) {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$chaveLockVaga]);
        erro("Este colaborador está de férias de $periodo (SAF): não é possível abrir solicitação nos dias de férias.", 409);
    }

    $pdo->beginTransaction();
    try {
        $stmtInsert = $pdo->prepare(
            "INSERT INTO solicitacoes_cobertura
                (filial, centro_custo, matricula, nome, funcao, data, motivo, matricula_vol, nome_vol,
                 titular_salario, titular_periculosidade, titular_insalubridade_grau, observacao, criado_por_login, criado_por_papel, escala, lote_id)
             VALUES (?, ?, ?, ?, ?, ?, 'afastamento_indeterminado', '', '', ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        // Retrato do titular (acima) e de quem abriu (db.php). lote_id: id da solicitacao (novoLoteSolicitacao), que a extensao
        // diaria copia da ancora.
        $quemAbriu = quemAbriuSolicitacao($pdo, $usuarioId, 'preposto');
        $stmtInsert->execute([
            $filial, $centroCusto, $matricula, $nome, $funcao, $dataInicio,
            $retratoTitular['titular_salario'], $retratoTitular['titular_periculosidade'], $retratoTitular['titular_insalubridade_grau'],
            $observacao === '' ? null : $observacao, $quemAbriu['criado_por_login'], $quemAbriu['criado_por_papel'], $escala,
            novoLoteSolicitacao(),
        ]);
        $solicitacaoId = (int) $pdo->lastInsertId();

        $stmtVaga = $pdo->prepare(
            "INSERT INTO solicitacoes_afastamento
                (solicitacao_cobertura_id, centro_custo, matricula_titular, aberta_em)
             VALUES (?, ?, ?, NOW())"
        );
        $stmtVaga->execute([$solicitacaoId, $centroCusto, $matricula]);

        // Retrato: todo colaborador do centro de custo AGORA, exceto o titular; quem aparecer depois
        // e candidato a substituto (ver acaoPendente).
        $stmtAtivos = $pdo->prepare('SELECT matricula FROM colaboradores WHERE centro_custo = ? AND matricula <> ?');
        $stmtAtivos->execute([$centroCusto, $matricula]);
        $matriculasAtivas = $stmtAtivos->fetchAll(PDO::FETCH_COLUMN);

        if ($matriculasAtivas) {
            $stmtBaseline = $pdo->prepare(
                'INSERT INTO solicitacao_afastamento_baseline (solicitacao_cobertura_id, matricula) VALUES (?, ?)'
            );
            foreach ($matriculasAtivas as $matriculaAtiva) {
                $stmtBaseline->execute([$solicitacaoId, $matriculaAtiva]);
            }
        }

        $pdo->commit();
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$chaveLockVaga]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$chaveLockVaga]);
        error_log('Falha ao abrir a solicitacao de afastamento: ' . $e->getMessage());
        erro('Falha ao abrir a solicitacao de afastamento. Tente novamente em instantes.', 500);
    }

    registrarAuditoria($pdo, $usuarioId, 'abrir_solicitacao_afastamento', [
        'tabela' => 'solicitacoes_afastamento',
        'detalhe' => "titular $matricula, centro $centroCusto, id $solicitacaoId",
    ]);
    // Comeca hoje/amanha: aviso no sino de quem designa (db.php), como a Nova Solicitacao comum.
    avisarSolicitacaoUrgente($pdo, $usuarioId, [['nome' => $nome, 'centro_custo' => $centroCusto, 'motivo' => 'afastamento_indeterminado', 'data' => $dataInicio]]);
    // Quem saiu ainda designado como volante dali em diante: aviso para trocar quem cobre (db.php).
    avisarTitularCobrindoNoDia($pdo, $usuarioId, [['matricula' => $matricula, 'nome' => $nome, 'motivo' => 'afastamento_indeterminado', 'data' => $dataInicio]]);

    echo json_encode(['success' => true, 'id' => $solicitacaoId]);
    exit;
}

// Devolve a vaga aberta mais antiga, no escopo do Preposto, com pelo menos 1 candidato agora
// (calculado na hora, sem fila). Sem candidato: {pendente: null}.
function acaoPendente($pdo, $usuarioId) {
    $centrosPermitidos = centrosCustoLiberados($pdo, $usuarioId, 'preposto');

    $sqlVagas = "SELECT sa.solicitacao_cobertura_id, sa.centro_custo, sa.matricula_titular, sc.nome AS nome_titular
                   FROM solicitacoes_afastamento sa
                   JOIN solicitacoes_cobertura sc ON sc.id = sa.solicitacao_cobertura_id
                  WHERE sa.fim_definido_em IS NULL";
    $params = [];
    if ($centrosPermitidos !== null) {
        if (count($centrosPermitidos) === 0) {
            echo json_encode(['pendente' => null]);
            exit;
        }
        $marcadores = implode(',', array_fill(0, count($centrosPermitidos), '?'));
        $sqlVagas .= " AND sa.centro_custo IN ($marcadores)";
        $params = $centrosPermitidos;
    }
    $sqlVagas .= ' ORDER BY sa.aberta_em ASC';

    $stmtVagas = $pdo->prepare($sqlVagas);
    $stmtVagas->execute($params);
    $vagas = $stmtVagas->fetchAll();

    foreach ($vagas as $vaga) {
        $candidatos = candidatosDaVaga($pdo, $vaga['solicitacao_cobertura_id'], $vaga['centro_custo'], $vaga['matricula_titular']);
        if (count($candidatos) > 0) {
            echo json_encode(['pendente' => [
                'solicitacao_cobertura_id' => (int) $vaga['solicitacao_cobertura_id'],
                'nome_titular' => $vaga['nome_titular'],
                'centro_custo' => $vaga['centro_custo'],
                'candidatos' => $candidatos,
            ]]);
            exit;
        }
    }

    echo json_encode(['pendente' => null]);
    exit;
}

// Candidatos: ativos hoje no centro da vaga, menos o titular (continua em colaboradores), o baseline,
// os descartados pra essa vaga e quem ja foi sugerido em qualquer vaga.
function candidatosDaVaga($pdo, $solicitacaoId, $centroCusto, $matriculaTitular) {
    $stmt = $pdo->prepare(
        "SELECT c.matricula, c.nome, c.funcao
           FROM colaboradores c
          WHERE c.centro_custo = ?
            AND c.matricula <> ?
            AND c.matricula NOT IN (
                SELECT matricula FROM solicitacao_afastamento_baseline WHERE solicitacao_cobertura_id = ?
            )
            AND c.matricula NOT IN (
                SELECT matricula FROM solicitacao_afastamento_descartado WHERE solicitacao_cobertura_id = ?
            )
            AND c.matricula NOT IN (
                SELECT substituto_sugerido_matricula FROM solicitacoes_afastamento
                 WHERE substituto_sugerido_matricula IS NOT NULL
            )
          ORDER BY c.nome"
    );
    $stmt->execute([$centroCusto, $matriculaTitular, $solicitacaoId, $solicitacaoId]);
    return $stmt->fetchAll();
}

// matricula preenchida = grava fim_definido_em=NOW() e o substituto sugerido (so informativo,
// nunca mexe em matricula_vol). Vazia = "Nenhuma dessas pessoas": descarta os candidatos
// mostrados, so pra essa vaga.
function acaoConfirmar($pdo, $usuarioId) {
    $corpo = json_decode(file_get_contents('php://input'), true);
    if (!is_array($corpo)) erro('Corpo invalido, esperado um objeto JSON');

    $solicitacaoId = (int) ($corpo['solicitacao_cobertura_id'] ?? 0);
    if ($solicitacaoId <= 0) erro('solicitacao_cobertura_id obrigatorio.');

    // FOR UPDATE: 2 chamadas simultaneas nao gravam por cima uma da outra (a segunda cai no 409).
    $pdo->beginTransaction();

    $stmtVaga = $pdo->prepare(
        'SELECT centro_custo, fim_definido_em, matricula_titular FROM solicitacoes_afastamento WHERE solicitacao_cobertura_id = ? FOR UPDATE'
    );
    $stmtVaga->execute([$solicitacaoId]);
    $vaga = $stmtVaga->fetch();
    if (!$vaga) {
        $pdo->rollBack();
        erro('Vaga de afastamento nao encontrada.', 404);
    }
    if ($vaga['fim_definido_em'] !== null) {
        $pdo->rollBack();
        erro('Esta vaga ja teve o Fim definido.', 409);
    }

    // Confere escopo de centro de custo de novo (defesa em profundidade).
    $centrosPermitidos = centrosCustoLiberados($pdo, $usuarioId, 'preposto');
    if ($centrosPermitidos !== null && !in_array($vaga['centro_custo'], $centrosPermitidos, true)) {
        $pdo->rollBack();
        erro('Esta vaga esta fora do seu escopo.', 403);
    }

    $matricula = trim((string) ($corpo['matricula'] ?? ''));

    if ($matricula === '') {
        // "Nenhuma dessas pessoas": descarta os candidatos atuais (mesmo calculo de acaoPendente).
        $candidatos = candidatosDaVaga($pdo, $solicitacaoId, $vaga['centro_custo'], $vaga['matricula_titular']);
        if ($candidatos) {
            $stmtDescarta = $pdo->prepare(
                'INSERT INTO solicitacao_afastamento_descartado (solicitacao_cobertura_id, matricula, descartado_em)
                 VALUES (?, ?, NOW())'
            );
            foreach ($candidatos as $candidato) {
                $stmtDescarta->execute([$solicitacaoId, $candidato['matricula']]);
            }
        }
        $pdo->commit();
        registrarAuditoria($pdo, $usuarioId, 'descartar_candidato_afastamento', [
            'tabela' => 'solicitacoes_afastamento',
            'detalhe' => "vaga $solicitacaoId, " . count($candidatos) . " candidato(s) descartado(s)",
        ]);
        echo json_encode(['success' => true]);
        exit;
    }

    $stmtColab = $pdo->prepare('SELECT nome FROM colaboradores WHERE matricula = ? AND centro_custo = ? LIMIT 1');
    $stmtColab->execute([$matricula, $vaga['centro_custo']]);
    $nome = $stmtColab->fetchColumn();
    if ($nome === false) {
        $pdo->rollBack();
        erro('Colaborador nao encontrado nesse centro de custo.', 404);
    }

    $stmtUpdate = $pdo->prepare(
        'UPDATE solicitacoes_afastamento
            SET fim_definido_em = NOW(), substituto_sugerido_matricula = ?, substituto_sugerido_nome = ?
          WHERE solicitacao_cobertura_id = ?'
    );
    $stmtUpdate->execute([$matricula, $nome, $solicitacaoId]);
    $pdo->commit();

    registrarAuditoria($pdo, $usuarioId, 'confirmar_substituto_afastamento', [
        'tabela' => 'solicitacoes_afastamento',
        'detalhe' => "vaga $solicitacaoId, substituto sugerido $matricula",
    ]);

    echo json_encode(['success' => true]);
    exit;
}

// Preposto define o Fim direto (sem deteccao automatica); grava so fim_definido_em, nunca substituto_sugerido_*.
function acaoDefinirFimManual($pdo, $usuarioId) {
    $corpo = json_decode(file_get_contents('php://input'), true);
    if (!is_array($corpo)) erro('Corpo invalido, esperado um objeto JSON');

    $solicitacaoId = (int) ($corpo['solicitacao_cobertura_id'] ?? 0);
    $fim = trim((string) ($corpo['fim'] ?? '')); // "yyyy-mm-dd"
    if ($solicitacaoId <= 0) erro('solicitacao_cobertura_id obrigatorio.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)) erro('Data de fim invalida.');

    // FOR UPDATE, como em acaoConfirmar.
    $pdo->beginTransaction();

    $stmtVaga = $pdo->prepare(
        'SELECT sa.centro_custo, sa.fim_definido_em, sa.matricula_titular, sc.data AS data_inicio
           FROM solicitacoes_afastamento sa
           JOIN solicitacoes_cobertura sc ON sc.id = sa.solicitacao_cobertura_id
          WHERE sa.solicitacao_cobertura_id = ? FOR UPDATE'
    );
    $stmtVaga->execute([$solicitacaoId]);
    $vaga = $stmtVaga->fetch();
    if (!$vaga) {
        $pdo->rollBack();
        erro('Vaga de afastamento nao encontrada.', 404);
    }
    if ($vaga['fim_definido_em'] !== null) {
        $pdo->rollBack();
        erro('Esta vaga ja teve o Fim definido.', 409);
    }

    $centrosPermitidos = centrosCustoLiberados($pdo, $usuarioId, 'preposto');
    if ($centrosPermitidos !== null && !in_array($vaga['centro_custo'], $centrosPermitidos, true)) {
        $pdo->rollBack();
        erro('Esta vaga esta fora do seu escopo.', 403);
    }

    // createFromFormat() herda a hora atual em vez de meia-noite; sem o setTime(0,0,0) o $fimTs > $hojeTs dava sempre verdadeiro.
    $inicioTs = DateTime::createFromFormat('d/m/Y', $vaga['data_inicio']);
    $fimTs = DateTime::createFromFormat('Y-m-d', $fim);
    $hojeTs = new DateTime('today');
    if ($inicioTs) $inicioTs->setTime(0, 0, 0);
    if ($fimTs) $fimTs->setTime(0, 0, 0);
    $hojeTs->setTime(0, 0, 0);
    if (!$inicioTs || !$fimTs || $fimTs < $inicioTs || $fimTs > $hojeTs) {
        $pdo->rollBack();
        erro('A data de fim precisa estar entre o Inicio e hoje.');
    }

    $stmtUpdate = $pdo->prepare('UPDATE solicitacoes_afastamento SET fim_definido_em = NOW() WHERE solicitacao_cobertura_id = ?');
    $stmtUpdate->execute([$solicitacaoId]);
    $apagados = apagarDiasDaVagaDepoisDoFim($pdo, (string) $vaga['matricula_titular'], 'afastamento_indeterminado', $solicitacaoId, $fim); // db.php
    $pdo->commit();

    registrarAuditoria($pdo, $usuarioId, 'definir_fim_manual_afastamento', [
        'tabela' => 'solicitacoes_afastamento',
        'detalhe' => "vaga $solicitacaoId, fim $fim" . ($apagados ? ", $apagados dia(s) depois do fim apagado(s)" : ''),
    ]);

    echo json_encode(['success' => true]);
    exit;
}

// Cancela a vaga inteira (aberta por engano) nas primeiras 24h; mesma logica de desligamento-confirmacao.php.
function acaoCancelar($pdo, $usuarioId, string $papel) {
    $corpo = json_decode(file_get_contents('php://input'), true);
    if (!is_array($corpo)) erro('Corpo invalido, esperado um objeto JSON');

    $solicitacaoId = (int) ($corpo['solicitacao_cobertura_id'] ?? 0);
    if ($solicitacaoId <= 0) erro('solicitacao_cobertura_id obrigatorio.');

    $stmtVaga = $pdo->prepare(
        'SELECT sa.centro_custo, sa.matricula_titular, sa.aberta_em, sa.fim_definido_em, sc.data AS data_inicio
           FROM solicitacoes_afastamento sa
           JOIN solicitacoes_cobertura sc ON sc.id = sa.solicitacao_cobertura_id
          WHERE sa.solicitacao_cobertura_id = ?'
    );
    $stmtVaga->execute([$solicitacaoId]);
    $vaga = $stmtVaga->fetch();
    if (!$vaga) erro('Vaga de afastamento nao encontrada.', 404);
    if ($vaga['fim_definido_em'] !== null) erro('Esta vaga ja teve o Fim definido.', 409);

    // Preposto: ate 24h da abertura, no proprio centro. RH/Administrador (item 4 da analise de 2026-11-12): a vaga aberta por engano e
    // vista depois, com motivo, so sem volante, sem ausencia e em periodo do PFM aberto (impedimentoCancelamentoRh, db.php, abaixo).
    $ehRh = $papel !== 'preposto';
    $motivoCancelamento = '';
    if ($ehRh) {
        $motivoCancelamento = trim(preg_replace('/\s+/', ' ', (string) ($corpo['motivo_cancelamento'] ?? '')));
        if (mb_strlen($motivoCancelamento) < 5) erro('Informe o motivo do cancelamento (pelo menos 5 caracteres).');
        if (mb_strlen($motivoCancelamento) > 200) erro('O motivo do cancelamento passa de 200 caracteres.');
    } else {
        $centrosPermitidos = centrosCustoLiberados($pdo, $usuarioId, 'preposto');
        if ($centrosPermitidos !== null && !in_array($vaga['centro_custo'], $centrosPermitidos, true)) {
            erro('Esta vaga esta fora do seu escopo.', 403);
        }

        $abertaEm = new DateTime($vaga['aberta_em']);
        $agora = new DateTime();
        if ($agora->getTimestamp() - $abertaEm->getTimestamp() > 24 * 3600) {
            erro('So e possivel cancelar ate 24h apos a abertura da solicitacao.', 403);
        }
    }

    // Apaga TODAS as linhas da vaga, inclusive dias ja com volante (que recebe aviso antes do DELETE). id >= ancora: uma vaga
    // antiga do mesmo titular com dias depois do inicio desta (ex.: fechada antes de comecar) foi criada antes e fica de fora.
    // FOR UPDATE ate o DELETE: uma designacao que chegue no meio espera (o que o RH conferiu continua valendo); erro() sai sem commit
    // e a conexao fechada desfaz.
    $pdo->beginTransaction();
    $stmtIds = $pdo->prepare(
        "SELECT id, centro_custo, matricula, nome, data, motivo, matricula_vol, nome_vol, ausencia_matricula_vol, ausencia_nome_vol
           FROM solicitacoes_cobertura
          WHERE matricula = ? AND motivo = 'afastamento_indeterminado' AND id >= ?
            AND STR_TO_DATE(data, '%d/%m/%Y') >= STR_TO_DATE(?, '%d/%m/%Y') FOR UPDATE"
    );
    $stmtIds->execute([$vaga['matricula_titular'], $solicitacaoId, $vaga['data_inicio']]);
    $linhasVaga = $stmtIds->fetchAll();
    $idsVaga = array_column($linhasVaga, 'id');
    // RH: sem volante, sem ausencia e periodo do PFM aberto. Preposto (24h): sem ausencia registrada (revisao de 2026-11-13).
    $impedimento = $ehRh ? impedimentoCancelamentoRh($linhasVaga, date('Y-m-d')) : impedimentoAusenciaNaLinha($linhasVaga, 'Fale com o RH.');
    if ($impedimento) erro($impedimento[0], $impedimento[1]);
    notificarCancelamentoDeCobertura($pdo, $idsVaga, $usuarioId);

    $linhasApagadas = 0;
    if (count($idsVaga) > 0) {
        $marcadoresVaga = implode(',', array_fill(0, count($idsVaga), '?'));
        $stmtDel = $pdo->prepare("DELETE FROM solicitacoes_cobertura WHERE id IN ($marcadoresVaga)");
        $stmtDel->execute($idsVaga);
        $linhasApagadas = $stmtDel->rowCount();
    }
    $pdo->commit();

    registrarAuditoria($pdo, $usuarioId, 'cancelar_solicitacao_afastamento', [
        'tabela' => 'solicitacoes_afastamento',
        'detalhe' => "vaga $solicitacaoId, titular {$vaga['matricula_titular']}, $linhasApagadas linha(s) apagada(s)"
            . ($motivoCancelamento !== '' ? ", cancelada pelo RH - motivo do cancelamento: $motivoCancelamento" : ''),
    ]);
    // Cancelada pelo RH: o Preposto do centro fica sabendo, com o motivo (db.php).
    if ($ehRh) avisarPrepostoCancelamentoRh($pdo, $usuarioId, $linhasVaga, $motivoCancelamento);

    echo json_encode(['success' => true]);
    exit;
}
