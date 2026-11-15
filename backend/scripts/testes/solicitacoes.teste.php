<?php
// Testes standalone (sem framework) das regras de solicitacao de db.php: prazo do PFM, cancelar do RH e avisos no sino. Usa o banco
// do teste do CI (schema.sql + migrar-banco.php, ver .gitlab-ci.yml) e so mexe em linhas TSTQ*/tstq_* (apagadas antes e depois).
// Rodar: php backend/scripts/testes/solicitacoes.teste.php (com DB_HOST/DB_NAME/DB_USER/DB_PASS de um banco de TESTE).
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado - script de linha de comando.');
}

// Ferias do SAF de teste (avisarTitularCobrindoNoDia): o arquivo e escrito abaixo, antes dos avisos, e apagado no fim.
$arquivoSaf = sys_get_temp_dir() . '/tstq_cobertura_saf.json';
putenv("SAF_COBERTURA_JSON=$arquivoSaf");
require_once __DIR__ . '/../../api/db.php';

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

// ---------------------------------------------------------------- regras puras
// Periodo do PFM 20 -> 19 aceita ajuste ate o dia 3 do mes seguinte.
verificar(inicioPeriodoPfmAberto('2026-11-13') === '2026-10-20', 'dia 13: periodo aberto desde o dia 20 do mes anterior');
verificar(inicioPeriodoPfmAberto('2026-12-03') === '2026-10-20', 'dia 3: o periodo de 20/10 a 19/11 ainda aceita ajuste');
verificar(inicioPeriodoPfmAberto('2026-12-04') === '2026-11-20', 'dia 4: so de 20/11 em diante');
verificar(inicioPeriodoPfmAberto('2027-01-02') === '2026-11-20', 'virada do ano (dia 2)');

// Prazo de correcao: o que vencer primeiro, 15 dias do fim/fechamento ou o dia 3 da folha.
verificar(dentroDoPrazoDeCorrecao('2026-11-10', null, '2026-11-20', '2026-11-10'), 'dentro dos 15 dias e da folha aberta');
verificar(!dentroDoPrazoDeCorrecao('2026-11-10', null, '2026-11-26', '2026-11-10'), '16 dias depois do fim: fora do prazo');
verificar(dentroDoPrazoDeCorrecao('2026-10-25', '2026-11-02 10:00:00', '2026-11-16', '2026-10-25'), 'fechada depois do fim: conta do fechamento');
verificar(!dentroDoPrazoDeCorrecao('2026-11-10', '2026-11-12 08:00:00', '2026-11-13', '2026-10-15'), 'dia de folha ja fechada: fora, mesmo nos 15 dias');
verificar(!dentroDoPrazoDeCorrecao('2026-11-25', null, '2026-12-04', '2026-11-10'), 'dia 4: o periodo anterior fechou');

verificar(textoDiasSolicitacao(['14/11/2026', '13/11/2026']) === 'de 13/11/2026 a 14/11/2026', 'dias em qualquer ordem');
verificar(textoDiasSolicitacao(['13/11/2026']) === 'em 13/11/2026', 'um dia so');
verificar(count(solicitacoesDasLinhas([
    ['lote_id' => 'A', 'matricula' => '1', 'motivo' => 'falta'], ['lote_id' => 'A', 'matricula' => '1', 'motivo' => 'falta'],
    ['lote_id' => 'B', 'matricula' => '1', 'motivo' => 'falta'],
    ['lote_id' => null, 'matricula' => '2', 'motivo' => 'atestado'], ['lote_id' => '', 'matricula' => '2', 'motivo' => 'atestado'],
])) === 3, 'solicitacoes pelo id (lote) e, sem id, por titular+motivo');
verificar(rotuloMotivoSolicitacao('afastamento_indeterminado') === 'AFASTAMENTO', 'rotulo da vaga de afastamento');
verificar(NOME_FOLGA_TITULAR === 'FOLGA DO TITULAR', 'nome gravado na folga do titular');

// Cancelar do RH: sem volante, sem ausencia e so periodo do PFM aberto.
$linha = fn(array $extra) => $extra + ['data' => '13/11/2026', 'motivo' => 'falta', 'matricula_vol' => '', 'nome_vol' => '',
                                       'ausencia_matricula_vol' => null, 'ausencia_nome_vol' => null];
verificar(impedimentoCancelamentoRh([$linha([]), $linha(['matricula_vol' => 'SEM_COBERTURA'])], '2026-11-13') === null,
    'RH cancela dia aberto ou SEM COBERTURA de folha aberta');
$imp = impedimentoCancelamentoRh([$linha(['matricula_vol' => '123', 'nome_vol' => 'FULANO'])], '2026-11-13');
verificar($imp !== null && $imp[1] === 409 && str_contains($imp[0], 'cobertura de FULANO'), 'dia com volante: corrigir para SEM COBERTURA antes');
$imp = impedimentoCancelamentoRh([$linha(['matricula_vol' => 'SEM_COBERTURA', 'ausencia_matricula_vol' => '9', 'ausencia_nome_vol' => 'BELTRANO'])], '2026-11-13');
verificar($imp !== null && str_contains($imp[0], 'ausência de BELTRANO'), 'dia com ausencia registrada nao sai (o PFM pagaria quem faltou)');
verificar(impedimentoCancelamentoRh([$linha(['motivo' => 'ferias', 'matricula_vol' => 'SEM_COBERTURA'])], '2026-11-13') !== null, 'linha de ferias (ausencia do SAF) nao sai');
$imp = impedimentoCancelamentoRh([$linha(['data' => '19/10/2026'])], '2026-11-13');
verificar($imp !== null && str_contains($imp[0], 'já fechado'), 'dia de folha ja fechada nao sai');
$imp = impedimentoAusenciaNaLinha([$linha([]), $linha(['matricula_vol' => '7', 'ausencia_matricula_vol' => '9', 'ausencia_nome_vol' => 'BELTRANO'])], 'Fale com o RH.');
verificar($imp !== null && str_contains($imp[0], 'ausência de BELTRANO') && str_contains($imp[0], 'Fale com o RH.'),
    'cancelar do Preposto (24h): linha com ausencia registrada nao sai');
verificar(impedimentoAusenciaNaLinha([$linha(['matricula_vol' => '7', 'nome_vol' => 'X'])], 'x') === null,
    'cancelar do Preposto (24h): dia so com volante continua saindo (o aviso ao Gestor/RH segue)');

// ---------------------------------------------------------------- avisos no sino (banco de teste)
$pdo = conectarBanco();
$limpar = function () use ($pdo) {
    $pdo->exec("DELETE FROM notificacoes WHERE usuario_id IN (SELECT id FROM usuarios WHERE usuario LIKE 'tstq\\_%')");
    $pdo->exec("DELETE FROM usuario_centros_custo WHERE usuario_id IN (SELECT id FROM usuarios WHERE usuario LIKE 'tstq\\_%')");
    $pdo->exec("DELETE FROM usuarios WHERE usuario LIKE 'tstq\\_%'");
    $pdo->exec("DELETE FROM solicitacoes_cobertura WHERE matricula LIKE 'TSTQ%'");
};
$limpar();
$novoUsuario = function (string $usuario, string $papel, ?string $centro) use ($pdo): int {
    $pdo->prepare('INSERT INTO usuarios (usuario, senha_hash, papel) VALUES (?, ?, ?)')->execute([$usuario, 'x', $papel]);
    $id = (int) $pdo->lastInsertId();
    if ($centro) $pdo->prepare('INSERT INTO usuario_centros_custo (usuario_id, papel, centro_custo) VALUES (?, ?, ?)')->execute([$id, $papel, $centro]);
    return $id;
};
$rh = $novoUsuario('tstq_rh', 'rh', null);
$prep = $novoUsuario('tstq_prep', 'preposto', 'TSTQC1');
$gest = $novoUsuario('tstq_gest', 'gestor', 'TSTQC2');
$avisos = function (int $usuario, string $tipo) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT mensagem FROM notificacoes WHERE usuario_id = ? AND tipo = ? ORDER BY id');
    $stmt->execute([$usuario, $tipo]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
};
$hoje = date('d/m/Y');
// TSTQ0005 (cadastro) cobre pelo SAF as ferias de TSTQ0006 (centro TSTQC2) de ontem a daqui a 5 dias.
$pdo->exec("DELETE FROM colaboradores WHERE matricula LIKE 'TSTQ000%'");
$pdo->exec("INSERT INTO colaboradores (origem, filial, centro_custo, matricula, nome, funcao, salario) VALUES
            ('IMPORTADO','01','TSTQC2','TSTQ0005','VOLANTE SAF TSTQ','VIGILANTE',1500)");
file_put_contents($arquivoSaf, json_encode(['gerado_em' => date('c'), 'dados' => [[
    'id' => 1, 'matricula' => 'TSTQ0006', 'nome' => 'FERIAS TSTQ', 'filial' => '01', 'centro_custo' => 'TSTQC2', 'funcao' => 'VIGILANTE',
    'ferias_inicio' => date('Y-m-d', strtotime('-1 day')), 'ferias_fim' => date('Y-m-d', strtotime('+5 days')), 'precisa_cobertura' => 'Sim',
    'volante' => 'VOLANTE SAF TSTQ', 'volante_matricula' => 'TSTQ0005', 'volante_motivo' => '', 'cobertura_desde' => '']]]));
verificar(titularDoCentro($pdo, 'TSTQ0005', 'TSTQC2') && !titularDoCentro($pdo, 'TSTQ0005', 'TSTQC1') && !titularDoCentro($pdo, 'TSTQ9999', 'TSTQC2'),
    'titularDoCentro: so a matricula do cadastro naquele centro');
// Retrato do salario de quem cobre: sem salario no cadastro fica null (nao 0,00, que faria o PFM pagar o salario inteiro do titular).
$pdo->exec("DELETE FROM colaboradores WHERE matricula LIKE 'TSTQS%'");
$pdo->exec("INSERT INTO colaboradores (origem, filial, centro_custo, matricula, nome, funcao, salario) VALUES
            ('IMPORTADO','01','TSTQC2','TSTQS1','SEM SALARIO','VIGILANTE',NULL), ('IMPORTADO','01','TSTQC2','TSTQS2','SALARIO ZERO','VIGILANTE',0),
            ('IMPORTADO','01','TSTQC2','TSTQS3','COM SALARIO','VIGILANTE',1500)");
verificar(snapshotVolSalario($pdo, 'TSTQS1') === null && snapshotVolSalario($pdo, 'TSTQS2') === null && snapshotVolSalario($pdo, 'TSTQS3') === 1500.0,
    'retrato do salario de quem cobre: vazio ou 0 = null; com salario = o valor');
$pdo->exec("DELETE FROM colaboradores WHERE matricula LIKE 'TSTQS%'");
// TSTQ0001 cobre o titular TSTQ0002 (centro TSTQC2) hoje.
$pdo->prepare("INSERT INTO solicitacoes_cobertura (filial, centro_custo, matricula, nome, funcao, data, motivo, matricula_vol, nome_vol, fechado_em)
               VALUES ('01', 'TSTQC2', 'TSTQ0002', 'TITULAR TSTQ', 'VIGILANTE', ?, 'falta', 'TSTQ0001', 'VOLANTE TSTQ', NOW())")->execute([$hoje]);

verificar(in_array($prep, prepostosDoCentro($pdo, 'TSTQC1'), true) && !in_array($prep, prepostosDoCentro($pdo, 'TSTQC2'), true),
    'prepostosDoCentro: so o Preposto do proprio centro');

avisarTitularCobrindoNoDia($pdo, $prep, [['matricula' => 'TSTQ0001', 'nome' => 'VOLANTE TSTQ', 'motivo' => 'falta', 'data' => $hoje]]);
$doRh = $avisos($rh, 'ausencia');
verificar(count($doRh) === 1 && str_contains($doRh[0], 'VOLANTE AUSENTE') && str_contains($doRh[0], 'TITULAR TSTQ'),
    'falta de quem esta designado como volante: aviso ao RH com o posto que ele cobre');
verificar(count($avisos($gest, 'ausencia')) === 1, 'falta de quem esta designado como volante: aviso ao Gestor do posto coberto');
avisarTitularCobrindoNoDia($pdo, $prep, [['matricula' => 'TSTQ0001', 'nome' => 'VOLANTE TSTQ', 'motivo' => 'servico_extra', 'data' => $hoje]]);
verificar(count($avisos($rh, 'ausencia')) === 1, 'servico extra nao avisa (ele esta trabalhando)');
avisarTitularCobrindoNoDia($pdo, $prep, [['matricula' => 'TSTQ0001', 'nome' => 'VOLANTE TSTQ', 'motivo' => 'desligamento',
                                           'data' => date('d/m/Y', strtotime('-5 days'))]]);
verificar(count($avisos($rh, 'ausencia')) === 1, 'desligamento nao avisa: a vaga e do posto (quem cumpre aviso como volante segue trabalhando)');
avisarTitularCobrindoNoDia($pdo, $prep, [['matricula' => 'TSTQ0001', 'nome' => 'VOLANTE TSTQ', 'motivo' => 'afastamento_indeterminado',
                                           'data' => date('d/m/Y', strtotime('-5 days'))]]);
verificar(count($avisos($rh, 'ausencia')) === 2, 'vaga de afastamento aberta antes: avisa dos dias designados dali em diante');
avisarTitularCobrindoNoDia($pdo, $prep, [['matricula' => 'TSTQ0001', 'nome' => 'VOLANTE TSTQ', 'motivo' => 'falta',
                                           'data' => date('d/m/Y', strtotime('+1 day'))]]);
verificar(count($avisos($rh, 'ausencia')) === 2, 'dia em que ele nao esta designado: sem aviso');
verificar(str_contains($avisos($rh, 'ausencia')[0], 'o PFM não paga a ele os dias em que está ausente'),
    'o aviso diz que o PFM nao paga o dia a quem esta ausente');

avisarTitularCobrindoNoDia($pdo, $prep, [['matricula' => 'TSTQ0005', 'nome' => 'VOLANTE SAF TSTQ', 'motivo' => 'atestado', 'data' => $hoje],
                                         ['matricula' => 'TSTQ0005', 'nome' => 'VOLANTE SAF TSTQ', 'motivo' => 'atestado', 'data' => date('d/m/Y', strtotime('+9 days'))]]);
$doRh = $avisos($rh, 'ausencia');
verificar(count($doRh) === 3 && str_contains($doRh[2], 'cobre as férias de FERIAS TSTQ pelo SAF') && str_contains($doRh[2], "em $hoje")
    && str_contains($doRh[2], 'Previsão'), 'atestado de quem cobre ferias do SAF: aviso com os dias dentro da cobertura (so hoje)');
verificar(count($avisos($gest, 'ausencia')) === 3, 'ferias do SAF: aviso tambem ao Gestor do centro do titular das ferias');
avisarTitularCobrindoNoDia($pdo, $prep, [['matricula' => 'TSTQ0005', 'nome' => 'VOLANTE SAF TSTQ', 'motivo' => 'afastamento_indeterminado',
                                           'data' => date('d/m/Y', strtotime('+2 days'))]]);
$doRh = $avisos($rh, 'ausencia');
verificar(count($doRh) === 4 && str_contains($doRh[3], 'de ' . date('d/m/Y', strtotime('+2 days')) . ' a ' . date('d/m/Y', strtotime('+5 days'))),
    'vaga de afastamento de quem cobre ferias do SAF: do 1o dia da vaga ao fim das ferias');
avisarTitularCobrindoNoDia($pdo, $prep, [['matricula' => 'TSTQ0005', 'nome' => 'VOLANTE SAF TSTQ', 'motivo' => 'falta',
                                           'data' => date('d/m/Y', strtotime('+7 days'))]]);
verificar(count($avisos($rh, 'ausencia')) === 4, 'falta fora das ferias que ele cobre: sem aviso');

avisarPrepostoCancelamentoRh($pdo, $rh, [['nome' => 'TITULAR TSTQ', 'centro_custo' => 'TSTQC1', 'motivo' => 'falta', 'data' => $hoje]], 'aberta por engano');
$doPrep = $avisos($prep, 'cancelamento');
verificar(count($doPrep) === 1 && str_contains($doPrep[0], 'CANCELADA PELO RH') && str_contains($doPrep[0], 'Motivo: aberta por engano'),
    'cancelamento do RH: aviso ao Preposto do centro, com o motivo');
verificar(count($avisos($gest, 'cancelamento')) === 0, 'cancelamento do RH: o Gestor de outro centro nao recebe');

// Aviso sem volante (gestoresDoGrupoDoVolante com matricula vazia): nem uma linha de colaborador com matricula vazia leva o aviso aos
// Gestores do grupo dela (revisao de 2026-11-13).
$pdo->prepare('INSERT INTO gestor_centro_cobertura (usuario_id, centro_custo_cobre) VALUES (?, ?)')->execute([$gest, 'TSTQC2']);
$pdo->exec("INSERT INTO colaboradores (origem, filial, centro_custo, matricula, nome, funcao, salario)
            VALUES ('MANUAL','01','TSTQC2','','TSTQ SEM MATRICULA','VIGILANTE',1500)");
verificar(gestoresDoGrupoDoVolante($pdo, '') === [] && in_array($gest, gestoresDoGrupoDoVolante($pdo, 'TSTQ0005'), true),
    'gestoresDoGrupoDoVolante: matricula vazia nao acha ninguem; a do volante acha o Gestor do grupo');
$pdo->exec("DELETE FROM colaboradores WHERE matricula = '' AND nome = 'TSTQ SEM MATRICULA'");

// Definir Fim da vaga (apagarDiasDaVagaDepoisDoFim): so os dias depois do Fim que ninguem cobriu nem guardam ausencia, depois da
// ancora e do motivo da vaga.
$diaRel = fn(int $n) => date('d/m/Y', strtotime("$n days"));
$linhaVaga = $pdo->prepare("INSERT INTO solicitacoes_cobertura (filial, centro_custo, matricula, nome, funcao, data, motivo, matricula_vol, ausencia_matricula_vol)
                            VALUES ('01', 'TSTQC2', 'TSTQ0007', 'TITULAR VAGA TSTQ', 'VIGILANTE', ?, ?, ?, ?)");
$linhaVaga->execute([$diaRel(1), 'afastamento_indeterminado', '', null]); // linha de antes da ancora (outra vaga): fica
$linhaVaga->execute([$diaRel(-5), 'afastamento_indeterminado', '', null]); // ancora
$ancora = (int) $pdo->lastInsertId();
foreach ([[-4, ''], [-3, 'TSTQ0001'], [-2, ''], [0, '']] as [$n, $vol]) $linhaVaga->execute([$diaRel($n), 'afastamento_indeterminado', $vol, null]);
$linhaVaga->execute([$diaRel(-1), 'afastamento_indeterminado', 'SEM_COBERTURA', 'TSTQ0009']);
$linhaVaga->execute([$diaRel(2), 'falta', '', null]);
$apagados = apagarDiasDaVagaDepoisDoFim($pdo, 'TSTQ0007', 'afastamento_indeterminado', $ancora, date('Y-m-d', strtotime('-4 days')));
$sobraram = $pdo->query("SELECT data FROM solicitacoes_cobertura WHERE matricula = 'TSTQ0007' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
verificar($apagados === 2 && $sobraram === [$diaRel(1), $diaRel(-5), $diaRel(-4), $diaRel(-3), $diaRel(-1), $diaRel(2)],
    'Definir Fim: apaga so os dias abertos depois do Fim (ficam o do Fim, com volante, com ausencia, outro motivo e de antes da ancora) - obtido '
    . json_encode([$apagados, $sobraram]));

$limpar();
$pdo->exec("DELETE FROM colaboradores WHERE matricula LIKE 'TSTQ000%'");
@unlink($arquivoSaf);

if ($falhas > 0) {
    echo "\n$falhas teste(s) falharam.\n";
    exit(1);
}
echo "\nTodos os testes passaram.\n";
exit(0);
