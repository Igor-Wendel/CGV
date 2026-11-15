<?php
// Migracao do banco (papeis de usuario, auditoria, lotes e demais tabelas/colunas novas).
// Roda quantas vezes quiser (cada passo confere se ja foi aplicado) e precisa rodar ANTES de publicar o
// codigo novo, que ja grava nas colunas novas (o CI roda antes e depois do deploy). Todas as mudancas sao
// aditivas, entao o codigo antigo segue funcionando.
//
// Uso:  php ferramentas/migrar-banco.php
//       php ferramentas/migrar-banco.php --conferir   (nao altera nada)

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../backend/api/db.php';

$conferirApenas = in_array('--conferir', $argv, true);

function log_linha($msg) { echo $msg . PHP_EOL; }

// Com retry; em falha final sai com codigo 1 (ver conectarBanco em db.php).
$pdo = conectarBanco();

// ------------------------------------------------------------
// Ajudantes de inspecao: consultam o INFORMATION_SCHEMA em vez de tentar o ALTER e engolir o
// erro, para a saida dizer o que ja existia e o que foi criado.
// ------------------------------------------------------------
function temTabela(PDO $pdo, string $tabela): bool {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$tabela]);
    return (bool) $stmt->fetchColumn();
}

function temColuna(PDO $pdo, string $tabela, string $coluna): bool {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$tabela, $coluna]);
    return (bool) $stmt->fetchColumn();
}

function temIndice(PDO $pdo, string $tabela, string $indice): bool {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$tabela, $indice]);
    return (bool) $stmt->fetchColumn();
}

$passos = 0;
$aplicados = 0;

function passo(string $descricao, bool $jaFeito, callable $aplicar) {
    global $passos, $aplicados, $conferirApenas;
    $passos++;
    if ($jaFeito) {
        log_linha("  [ja existe] $descricao");
        return;
    }
    if ($conferirApenas) {
        log_linha("  [PENDENTE ] $descricao");
        return;
    }
    $aplicar();
    $aplicados++;
    log_linha("  [aplicado ] $descricao");
}

log_linha($conferirApenas ? 'Conferindo migracoes (nada sera alterado)...' : 'Aplicando migracoes...');
log_linha('');

// ------------------------------------------------------------
// 1. PERFIS GESTOR/RH
// ------------------------------------------------------------
// Substitui o modelo antigo de 3 papeis (admin/operador/leitura) por 2: 'rh' (acesso total) e
// 'gestor' (Colaboradores dos centros de custo liberados, ver 1c, e Coberturas). 'admin' vira
// 'rh'; 'operador'/'leitura' viram 'gestor' sem centro liberado.
log_linha('1) Perfis Gestor/RH');

function tipoColunaPapel(PDO $pdo): ?string {
    $stmt = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'papel'"
    );
    $stmt->execute();
    $tipo = $stmt->fetchColumn();
    return $tipo === false ? null : $tipo;
}

// Verdadeiro em qualquer estado final valido do modelo atual (com ou sem 'preposto'/
// 'administrador'). Evita reduzir o ENUM de volta e truncar esses papeis para vazio.
function colunaPapelProntaParaRhGestor(PDO $pdo): bool {
    $tipo = tipoColunaPapel($pdo);
    // VARCHAR tambem conta: o tipo exato nunca importou, so os valores aceitos.
    return $tipo === "enum('rh','gestor')"
        || $tipo === "enum('rh','gestor','preposto')"
        || $tipo === "enum('rh','gestor','preposto','administrador')"
        || ($tipo !== null && str_starts_with($tipo, 'varchar'));
}

$tipoPapelAntes = tipoColunaPapel($pdo);

// O banco real pode ter usuarios.papel em VARCHAR(20); colunaPapelProntaParaRhGestor() cobre
// isso, senao o passo 1 reaplicaria o remapeamento legado e estreitaria a coluna para
// ENUM('rh','gestor'), quebrando com linhas 'preposto'/'administrador'.
passo(
    "coluna usuarios.papel no modelo Gestor/RH",
    colunaPapelProntaParaRhGestor($pdo),
    function () use ($pdo, $tipoPapelAntes) {
        if ($tipoPapelAntes === null) {
            // Instalacao nova, nunca rodou a migracao antiga - cria direto
            // no modelo atual.
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN papel ENUM('rh','gestor') NOT NULL DEFAULT 'gestor'");
        } else {
            // Modelo antigo: solta para VARCHAR, remapeia os dados e so entao aperta para o ENUM
            // final (ALTER direto trocaria valores antigos por string vazia).
            $pdo->exec("ALTER TABLE usuarios MODIFY papel VARCHAR(20) NOT NULL DEFAULT 'gestor'");
            $pdo->exec("UPDATE usuarios SET papel = 'rh' WHERE papel = 'admin'");
            $pdo->exec("UPDATE usuarios SET papel = 'gestor' WHERE papel IN ('operador', 'leitura')");
            $pdo->exec("ALTER TABLE usuarios MODIFY papel ENUM('rh','gestor') NOT NULL DEFAULT 'gestor'");
        }
    }
);

// Sempre roda (nao e destrutivo e garante que existe pelo menos 1 rh mesmo
// se o passo acima rodou em outra execucao).
if (!$conferirApenas && colunaPapelProntaParaRhGestor($pdo)) {
    // Administrador conta como quem gerencia o sistema: num banco novo o
    // unico usuario e o 'Admin' (papel administrador, criado pelo schema.sql)
    // e ele nao pode ser rebaixado pra rh aqui.
    $qtdRh = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE papel IN ('rh', 'administrador')")->fetchColumn();
    if ($qtdRh === 0) {
        $n = $pdo->exec("UPDATE usuarios SET papel = 'rh' WHERE LOWER(usuario) = 'admin'");
        if ($n > 0) {
            log_linha("  [aplicado ] usuario 'admin' marcado com papel rh (nenhum rh existia)");
        } else {
            log_linha('  ATENCAO: nenhum usuario com papel rh! Rode no phpMyAdmin:');
            log_linha("           UPDATE usuarios SET papel='rh' WHERE usuario='SEU_USUARIO';");
        }
    }
}

// ------------------------------------------------------------
// Papel Preposto
// ------------------------------------------------------------
// Terceiro papel: Colaboradores escopado por centro de custo (usuario_centros_custo, ver 1c),
// sem Coberturas. So amplia o ENUM.
log_linha('');
log_linha('1a) Papel Preposto');

$tipoAntesPreposto = tipoColunaPapel($pdo);
$passos++;
if ($tipoAntesPreposto === "enum('rh','gestor','preposto')"
    || ($tipoAntesPreposto !== null && str_starts_with($tipoAntesPreposto, 'varchar'))) {
    log_linha("  [ja existe] coluna usuarios.papel inclui 'preposto'");
} elseif ($tipoAntesPreposto !== "enum('rh','gestor')") {
    log_linha("  [aguardando] coluna usuarios.papel inclui 'preposto' - depende do passo 1) rodar primeiro");
} elseif ($conferirApenas) {
    log_linha("  [PENDENTE ] coluna usuarios.papel inclui 'preposto'");
} else {
    $pdo->exec("ALTER TABLE usuarios MODIFY papel ENUM('rh','gestor','preposto') NOT NULL DEFAULT 'gestor'");
    $aplicados++;
    log_linha("  [aplicado ] coluna usuarios.papel inclui 'preposto'");
}

// ------------------------------------------------------------
// Papel Administrador
// ------------------------------------------------------------
// Quarto papel: acesso total de 'rh' mais a pagina de Logs. So amplia o ENUM. Nome
// 'administrador' (nao 'admin') de proposito: 'admin' e valor legado que vira 'rh' no passo 1.
log_linha('');
log_linha('1a2) Papel Administrador');

$tipoAntesAdmin = tipoColunaPapel($pdo);
$passos++;
if ($tipoAntesAdmin === "enum('rh','gestor','preposto','administrador')"
    || ($tipoAntesAdmin !== null && str_starts_with($tipoAntesAdmin, 'varchar'))) {
    log_linha("  [ja existe] coluna usuarios.papel inclui 'administrador'");
} elseif ($tipoAntesAdmin !== "enum('rh','gestor','preposto')") {
    log_linha("  [aguardando] coluna usuarios.papel inclui 'administrador' - depende do passo 1a) rodar primeiro");
} elseif ($conferirApenas) {
    log_linha("  [PENDENTE ] coluna usuarios.papel inclui 'administrador'");
} else {
    $pdo->exec("ALTER TABLE usuarios MODIFY papel ENUM('rh','gestor','preposto','administrador') NOT NULL DEFAULT 'gestor'");
    $aplicados++;
    log_linha("  [aplicado ] coluna usuarios.papel inclui 'administrador'");
}

log_linha('');
log_linha('1c) Centros de custo por Gestor e Preposto');

passo(
    'tabela usuario_centros_custo',
    temTabela($pdo, 'usuario_centros_custo'),
    fn() => $pdo->exec(
        "CREATE TABLE usuario_centros_custo (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            centro_custo VARCHAR(50) NOT NULL,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            UNIQUE KEY uq_usuario_centro (usuario_id, centro_custo)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

log_linha('');
log_linha('1c-2) Centros de custo que cobrem um Preposto');

passo(
    'tabela preposto_centro_cobertura',
    temTabela($pdo, 'preposto_centro_cobertura'),
    fn() => $pdo->exec(
        "CREATE TABLE preposto_centro_cobertura (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            centro_custo_cobre VARCHAR(50) NOT NULL,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            UNIQUE KEY uq_usuario_centro_cobre (usuario_id, centro_custo_cobre)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

log_linha('');
log_linha('1d) Solicitacoes do Preposto');

passo(
    'tabela preposto_solicitacoes',
    temTabela($pdo, 'preposto_solicitacoes'),
    fn() => $pdo->exec(
        "CREATE TABLE preposto_solicitacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            matricula VARCHAR(20) NOT NULL,
            nome VARCHAR(150),
            motivo ENUM('falta', 'atestado', 'servico_extra', 'afastamento', 'desligamento') NOT NULL,
            data_inicio VARCHAR(10) NOT NULL,
            data_fim VARCHAR(10) NOT NULL,
            usuario_id INT NULL,
            usuario VARCHAR(100) NULL,
            criado_em DATETIME NOT NULL,
            INDEX idx_preposto_solicitacoes_matricula (matricula),
            INDEX idx_preposto_solicitacoes_usuario (usuario_id)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

// Motivo Desligamento: so amplia o ENUM. Bancos que ja tinham a tabela nao passam pelo CREATE
// TABLE acima, entao precisam deste passo.
log_linha('');
log_linha('1e) Motivo Desligamento nas Solicitacoes do Preposto');

$tipoColunaMotivo = null;
if (temTabela($pdo, 'preposto_solicitacoes')) {
    $stmt = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'preposto_solicitacoes' AND COLUMN_NAME = 'motivo'"
    );
    $stmt->execute();
    $tipoColunaMotivo = $stmt->fetchColumn() ?: null;
}
$motivoTemDesligamento = $tipoColunaMotivo !== null && str_contains($tipoColunaMotivo, "'desligamento'");

$passos++;
if ($motivoTemDesligamento) {
    log_linha("  [ja existe] coluna preposto_solicitacoes.motivo inclui 'desligamento'");
} elseif ($tipoColunaMotivo === null) {
    log_linha("  [aguardando] coluna preposto_solicitacoes.motivo - depende do passo 1d) ter sido aplicado antes");
} elseif ($conferirApenas) {
    log_linha("  [PENDENTE ] coluna preposto_solicitacoes.motivo inclui 'desligamento'");
} else {
    $pdo->exec(
        "ALTER TABLE preposto_solicitacoes
          MODIFY motivo ENUM('falta', 'atestado', 'servico_extra', 'afastamento', 'desligamento') NOT NULL"
    );
    $aplicados++;
    log_linha("  [aplicado ] coluna preposto_solicitacoes.motivo inclui 'desligamento'");
}

// ------------------------------------------------------------
// 1f) Resposta do Gestor as solicitacoes do Preposto (designar
// volante / sem cobertura) - ver backend/api/cobertura-gestor.php
// ------------------------------------------------------------
log_linha('');
log_linha('1f) Respostas do Gestor as Solicitacoes do Preposto');

passo(
    'tabela preposto_solicitacoes_respostas',
    temTabela($pdo, 'preposto_solicitacoes_respostas'),
    fn() => $pdo->exec(
        "CREATE TABLE preposto_solicitacoes_respostas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            solicitacao_id INT NOT NULL,
            tipo ENUM('designado', 'sem_cobertura') NOT NULL,
            data_inicio VARCHAR(10) NOT NULL,
            data_fim VARCHAR(10) NOT NULL,
            matricula_vol VARCHAR(20) NULL,
            nome_vol VARCHAR(150) NULL,
            cobertura_ids TEXT NULL,
            usuario_id INT NULL,
            usuario VARCHAR(100) NULL,
            criado_em DATETIME NOT NULL,
            INDEX idx_resposta_solicitacao (solicitacao_id),
            INDEX idx_resposta_usuario (usuario_id)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

// Cobertura por segmentos (varias respostas por solicitacao, cada uma com seu periodo): bancos
// existentes ganham as colunas novas e perdem a UNIQUE antiga (1 resposta por solicitacao).
log_linha('');
log_linha('1g) Cobertura por segmentos (periodo por resposta)');

$temColunaDataInicioResposta = temColuna($pdo, 'preposto_solicitacoes_respostas', 'data_inicio');
$passos++;
if ($temColunaDataInicioResposta) {
    log_linha("  [ja existe] preposto_solicitacoes_respostas.data_inicio/data_fim");
} elseif (!temTabela($pdo, 'preposto_solicitacoes_respostas')) {
    log_linha("  [aguardando] preposto_solicitacoes_respostas.data_inicio/data_fim - depende do passo 1f) rodar primeiro");
} elseif ($conferirApenas) {
    log_linha("  [PENDENTE ] preposto_solicitacoes_respostas.data_inicio/data_fim");
} else {
    // O UNIQUE(solicitacao_id) so existe em bancos antigos; dropar so se existir.
    $stmtIdx = $pdo->prepare(
        "SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'preposto_solicitacoes_respostas'
            AND INDEX_NAME = 'uq_solicitacao_resposta'"
    );
    $stmtIdx->execute();
    if ($stmtIdx->fetchColumn()) {
        $pdo->exec('ALTER TABLE preposto_solicitacoes_respostas DROP INDEX uq_solicitacao_resposta');
    }

    $pdo->exec(
        "ALTER TABLE preposto_solicitacoes_respostas
            ADD COLUMN data_inicio VARCHAR(10) NULL AFTER tipo,
            ADD COLUMN data_fim VARCHAR(10) NULL AFTER data_inicio,
            ADD INDEX idx_resposta_solicitacao (solicitacao_id)"
    );

    // Backfill: respostas antigas ganham o periodo da propria solicitacao.
    $pdo->exec(
        "UPDATE preposto_solicitacoes_respostas r
           JOIN preposto_solicitacoes ps ON ps.id = r.solicitacao_id
            SET r.data_inicio = ps.data_inicio, r.data_fim = ps.data_fim
          WHERE r.data_inicio IS NULL"
    );

    $pdo->exec(
        "ALTER TABLE preposto_solicitacoes_respostas
            MODIFY data_inicio VARCHAR(10) NOT NULL,
            MODIFY data_fim VARCHAR(10) NOT NULL"
    );

    $aplicados++;
    log_linha("  [aplicado ] preposto_solicitacoes_respostas.data_inicio/data_fim (com backfill)");
}

// ------------------------------------------------------------
// 2. AUDITORIA
// ------------------------------------------------------------
log_linha('');
log_linha('2) Log de auditoria');

passo(
    'tabela auditoria',
    temTabela($pdo, 'auditoria'),
    fn() => $pdo->exec(
        "CREATE TABLE auditoria (
            id INT AUTO_INCREMENT PRIMARY KEY,
            em DATETIME NOT NULL,
            usuario_id INT NULL,
            usuario VARCHAR(100) NULL,
            acao VARCHAR(60) NOT NULL,
            tabela VARCHAR(60) NULL,
            quantidade INT NULL,
            lote_id VARCHAR(40) NULL,
            detalhe VARCHAR(500) NULL,
            ip VARCHAR(45) NULL,
            INDEX idx_auditoria_em (em),
            INDEX idx_auditoria_acao (acao),
            INDEX idx_auditoria_lote (lote_id)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

// ------------------------------------------------------------
// 3. LOTE DE IMPORTACAO (para poder desfazer)
// ------------------------------------------------------------
// Anulavel: linhas antigas ficam sem lote (nao da para desfazer o que foi importado antes).
log_linha('');
log_linha('3) Lote de importacao (desfazer)');

foreach (['solicitacoes_cobertura', 'colaboradores'] as $tabela) {
    passo(
        "coluna $tabela.lote_id",
        temColuna($pdo, $tabela, 'lote_id'),
        fn() => $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN lote_id VARCHAR(40) NULL")
    );
    passo(
        "indice idx_{$tabela}_lote",
        temIndice($pdo, $tabela, "idx_{$tabela}_lote"),
        fn() => $pdo->exec("ALTER TABLE `$tabela` ADD INDEX idx_{$tabela}_lote (lote_id)")
    );
}

// ------------------------------------------------------------
// 4. DESCRICAO DOS CENTROS DE CUSTO (PROTHEUS) - ver sync-protheus-centros-custo.php
// ------------------------------------------------------------
log_linha('');
log_linha('4) Descricao dos centros de custo (Protheus)');

passo(
    'tabela centros_custo',
    temTabela($pdo, 'centros_custo'),
    fn() => $pdo->exec(
        "CREATE TABLE centros_custo (
            codigo VARCHAR(20) PRIMARY KEY,
            descricao VARCHAR(150) NOT NULL,
            atualizado_em DATETIME NOT NULL
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

// ------------------------------------------------------------
// 5. PERFIL DUPLO (papel_extra) - alternar entre Gestor e Preposto (alternarPerfilAtivo em db.php)
// ------------------------------------------------------------
log_linha('');
log_linha('5) Perfil duplo (papel_extra)');

passo(
    'coluna usuarios.papel_extra',
    temColuna($pdo, 'usuarios', 'papel_extra'),
    fn() => $pdo->exec("ALTER TABLE usuarios ADD COLUMN papel_extra ENUM('gestor', 'preposto') NULL AFTER papel")
);

passo(
    'coluna sessoes.papel_ativo',
    temColuna($pdo, 'sessoes', 'papel_ativo'),
    fn() => $pdo->exec("ALTER TABLE sessoes ADD COLUMN papel_ativo VARCHAR(20) NULL")
);

// ------------------------------------------------------------
// 6. PERFIL TRIPLO (usuario_papeis_extra) - substitui o papel_extra unico por uma tabela (ate 2
// extras). usuarios.papel_extra nao e removida (aditivo); so deixa de ser usada.
// ------------------------------------------------------------
log_linha('');
log_linha('6) Perfil triplo (usuario_papeis_extra)');

$papeisExtraNova = !temTabela($pdo, 'usuario_papeis_extra'); // o backfill abaixo so roda quando a tabela nasce
passo(
    'tabela usuario_papeis_extra',
    temTabela($pdo, 'usuario_papeis_extra'),
    fn() => $pdo->exec(
        "CREATE TABLE usuario_papeis_extra (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            papel ENUM('gestor', 'preposto') NOT NULL,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            UNIQUE KEY uq_usuario_papel_extra (usuario_id, papel)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

// Backfill de usuarios.papel_extra para usuario_papeis_extra: SO quando a tabela nasce nesta execucao. Depois disso quem manda
// e a tabela (o RH tira e poe perfis nela): copiar a coluna antiga a cada deploy devolvia um perfil que o RH ja tinha tirado.
// Extra igual ao principal (normalizado) nao e copiado (o salvar recusaria a linha). Depois a coluna antiga e zerada: ninguem
// a le, nem o codigo de producao anterior.
if ($papeisExtraNova && temColuna($pdo, 'usuarios', 'papel_extra')) {
    $passos++;
    if ($conferirApenas) {
        log_linha('  [PENDENTE ] backfill de usuarios.papel_extra pra usuario_papeis_extra');
    } else {
        $n = $pdo->exec(
            "INSERT IGNORE INTO usuario_papeis_extra (usuario_id, papel)
             SELECT id, papel_extra FROM usuarios
              WHERE papel_extra IS NOT NULL
                AND papel_extra <> CASE WHEN papel IN ('operador', 'leitura') THEN 'gestor' WHEN papel = 'admin' THEN 'rh' ELSE papel END"
        );
        $aplicados++;
        log_linha("  [aplicado ] backfill de $n papel_extra existente(s) pra usuario_papeis_extra");
    }
}
passo(
    'coluna antiga usuarios.papel_extra zerada (o perfil extra fica so em usuario_papeis_extra)',
    !temColuna($pdo, 'usuarios', 'papel_extra')
        || (int) $pdo->query('SELECT COUNT(*) FROM usuarios WHERE papel_extra IS NOT NULL')->fetchColumn() === 0,
    fn() => $pdo->exec('UPDATE usuarios SET papel_extra = NULL WHERE papel_extra IS NOT NULL')
);

// ------------------------------------------------------------
// 7. CENTRO DE CUSTO COM COLABORADOR ATIVO - a coluna e preenchida por
// sync-protheus-centros-custo.php; a migracao so a cria (default 0). Rode o sync depois para
// preencher sem esperar as 3h.
// ------------------------------------------------------------
log_linha('');
log_linha('7) Centro de custo com colaborador ativo (Protheus)');

passo(
    'coluna centros_custo.tem_colaborador',
    temColuna($pdo, 'centros_custo', 'tem_colaborador'),
    fn() => $pdo->exec("ALTER TABLE centros_custo ADD COLUMN tem_colaborador TINYINT(1) NOT NULL DEFAULT 0 AFTER descricao")
);

// ------------------------------------------------------------
// 8. LIMITE DE TENTATIVAS DE LOGIN (contra forca bruta) - ver minutosBloqueioLogin() e
// registrarTentativaLogin() em db.php.
// ------------------------------------------------------------
log_linha('');
log_linha('8) Limite de tentativas de login');

passo(
    'tabela login_tentativas',
    temTabela($pdo, 'login_tentativas'),
    fn() => $pdo->exec(
        "CREATE TABLE login_tentativas (
            usuario VARCHAR(100) PRIMARY KEY,
            tentativas INT NOT NULL DEFAULT 0,
            ultima_tentativa DATETIME NOT NULL,
            bloqueado_ate DATETIME NULL
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

// ------------------------------------------------------------
// 9. CANCELAMENTO DE COBERTURA PELO PREPOSTO (janela de 24h): so pode cancelar ate 24h da
// criacao. Linhas antigas ficam NULL (nao ha como saber a hora) e nao podem ser canceladas pelo
// Preposto, o que e seguro por omissao.
// ------------------------------------------------------------
log_linha('');
log_linha('9) Cancelamento de cobertura pelo Preposto (janela de 24h)');

passo(
    'coluna solicitacoes_cobertura.criado_em',
    temColuna($pdo, 'solicitacoes_cobertura', 'criado_em'),
    fn() => $pdo->exec("ALTER TABLE solicitacoes_cobertura ADD COLUMN criado_em DATETIME NULL")
);

// ------------------------------------------------------------
// 10. INDICES DE CENTRO DE CUSTO E MATRICULA UNICA: centro_custo (coluna mais filtrada) sem
// indice, e nada impedia matricula duplicada. O UNIQUE so aplica sem duplicata no banco (se
// falhar com "Duplicate entry", limpe a duplicata antes).
// ------------------------------------------------------------
log_linha('');
log_linha('10) Indices de centro de custo e matricula unica');

passo(
    'indice idx_colaboradores_centro_custo',
    temIndice($pdo, 'colaboradores', 'idx_colaboradores_centro_custo'),
    fn() => $pdo->exec("ALTER TABLE colaboradores ADD INDEX idx_colaboradores_centro_custo (centro_custo)")
);

passo(
    'indice idx_solicitacoes_centro_custo',
    temIndice($pdo, 'solicitacoes_cobertura', 'idx_solicitacoes_centro_custo'),
    fn() => $pdo->exec("ALTER TABLE solicitacoes_cobertura ADD INDEX idx_solicitacoes_centro_custo (centro_custo)")
);

$passos++;
if (temIndice($pdo, 'colaboradores', 'uq_colaboradores_matricula')) {
    log_linha('  [ja existe] indice unico uq_colaboradores_matricula (colaboradores.matricula)');
} elseif ($conferirApenas) {
    log_linha('  [PENDENTE ] indice unico uq_colaboradores_matricula (colaboradores.matricula)');
} else {
    try {
        $pdo->exec("ALTER TABLE colaboradores ADD UNIQUE KEY uq_colaboradores_matricula (matricula)");
        $aplicados++;
        log_linha('  [aplicado ] indice unico uq_colaboradores_matricula (colaboradores.matricula)');
    } catch (PDOException $e) {
        log_linha('  ATENCAO: nao foi possivel criar o indice unico - provavelmente ha matricula(s)');
        log_linha('           duplicada(s) ainda no banco. Rode para achar quais:');
        log_linha("           SELECT matricula, COUNT(*) FROM colaboradores WHERE matricula <> '' GROUP BY matricula HAVING COUNT(*) > 1;");
        log_linha('           Resolva manualmente (decida qual linha manter) e rode a migracao de novo.');
    }
}

// ------------------------------------------------------------
// 11. VERIFICACAO DE SAUDE DO SYNC: torna visivel sincronizacao parada. Ver
// verificar-saude-sync.php.
// ------------------------------------------------------------
log_linha('');
log_linha('11) Verificacao de saude das sincronizacoes');

passo(
    'tabela sync_status',
    temTabela($pdo, 'sync_status'),
    fn() => $pdo->exec(
        "CREATE TABLE sync_status (
            chave VARCHAR(50) NOT NULL PRIMARY KEY,
            executado_em DATETIME NULL,
            ultimo_erro_em DATETIME NULL,
            mensagem_erro VARCHAR(500) NULL,
            notificado_atraso_em DATETIME NULL
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

// ------------------------------------------------------------
// 12. PERICULOSIDADE/INSALUBRIDADE (cadastro do Protheus) - usado pelo calculo automatico do PFM
// (periculosidade 30%, insalubridade 10/20/40% do minimo) do titular ausente. RA_ADCPERI 1=nao/
// 2=sim; RA_ADCINS 1=nao/2=minima/3=media/4=maxima; guardado como grau 0-3 (0 = nao tem).
// ------------------------------------------------------------
log_linha('');
log_linha('12) Periculosidade/insalubridade do cadastro do funcionario');

passo(
    'coluna colaboradores.periculosidade',
    temColuna($pdo, 'colaboradores', 'periculosidade'),
    fn() => $pdo->exec("ALTER TABLE colaboradores ADD COLUMN periculosidade TINYINT(1) NOT NULL DEFAULT 0 AFTER salario")
);

passo(
    'coluna colaboradores.insalubridade_grau',
    temColuna($pdo, 'colaboradores', 'insalubridade_grau'),
    fn() => $pdo->exec("ALTER TABLE colaboradores ADD COLUMN insalubridade_grau TINYINT(1) NOT NULL DEFAULT 0 AFTER periculosidade")
);

// ------------------------------------------------------------
// 13. VAGA EM ABERTO DE DESLIGAMENTO/AFASTAMENTO: a solicitacao nao pede mais data Fim; a vaga
// fica aberta ate fechar automaticamente (colaborador novo confirmado pelo Preposto) ou manualmente.
// Ver desligamento-confirmacao.php, afastamento-confirmacao.php e schema.sql.
// ------------------------------------------------------------
log_linha('');
log_linha('13) Vaga em aberto de Desligamento/Afastamento');

passo(
    'tabela solicitacoes_desligamento',
    temTabela($pdo, 'solicitacoes_desligamento'),
    fn() => $pdo->exec(
        "CREATE TABLE solicitacoes_desligamento (
            solicitacao_cobertura_id INT PRIMARY KEY,
            centro_custo VARCHAR(100) NOT NULL,
            matricula_titular VARCHAR(20) NOT NULL,
            aberta_em DATETIME NOT NULL,
            fim_definido_em DATETIME NULL,
            substituto_sugerido_matricula VARCHAR(20) NULL,
            substituto_sugerido_nome VARCHAR(150) NULL,
            FOREIGN KEY (solicitacao_cobertura_id) REFERENCES solicitacoes_cobertura(id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

passo(
    'tabela solicitacao_desligamento_baseline',
    temTabela($pdo, 'solicitacao_desligamento_baseline'),
    fn() => $pdo->exec(
        "CREATE TABLE solicitacao_desligamento_baseline (
            solicitacao_cobertura_id INT NOT NULL,
            matricula VARCHAR(20) NOT NULL,
            PRIMARY KEY (solicitacao_cobertura_id, matricula),
            FOREIGN KEY (solicitacao_cobertura_id) REFERENCES solicitacoes_cobertura(id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

passo(
    'tabela solicitacao_desligamento_descartado',
    temTabela($pdo, 'solicitacao_desligamento_descartado'),
    fn() => $pdo->exec(
        "CREATE TABLE solicitacao_desligamento_descartado (
            solicitacao_cobertura_id INT NOT NULL,
            matricula VARCHAR(20) NOT NULL,
            descartado_em DATETIME NOT NULL,
            PRIMARY KEY (solicitacao_cobertura_id, matricula),
            FOREIGN KEY (solicitacao_cobertura_id) REFERENCES solicitacoes_cobertura(id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

passo(
    'tabela solicitacoes_afastamento',
    temTabela($pdo, 'solicitacoes_afastamento'),
    fn() => $pdo->exec(
        "CREATE TABLE solicitacoes_afastamento (
            solicitacao_cobertura_id INT PRIMARY KEY,
            centro_custo VARCHAR(100) NOT NULL,
            matricula_titular VARCHAR(20) NOT NULL,
            aberta_em DATETIME NOT NULL,
            fim_definido_em DATETIME NULL,
            substituto_sugerido_matricula VARCHAR(20) NULL,
            substituto_sugerido_nome VARCHAR(150) NULL,
            FOREIGN KEY (solicitacao_cobertura_id) REFERENCES solicitacoes_cobertura(id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

passo(
    'tabela solicitacao_afastamento_baseline',
    temTabela($pdo, 'solicitacao_afastamento_baseline'),
    fn() => $pdo->exec(
        "CREATE TABLE solicitacao_afastamento_baseline (
            solicitacao_cobertura_id INT NOT NULL,
            matricula VARCHAR(20) NOT NULL,
            PRIMARY KEY (solicitacao_cobertura_id, matricula),
            FOREIGN KEY (solicitacao_cobertura_id) REFERENCES solicitacoes_cobertura(id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

passo(
    'tabela solicitacao_afastamento_descartado',
    temTabela($pdo, 'solicitacao_afastamento_descartado'),
    fn() => $pdo->exec(
        "CREATE TABLE solicitacao_afastamento_descartado (
            solicitacao_cobertura_id INT NOT NULL,
            matricula VARCHAR(20) NOT NULL,
            descartado_em DATETIME NOT NULL,
            PRIMARY KEY (solicitacao_cobertura_id, matricula),
            FOREIGN KEY (solicitacao_cobertura_id) REFERENCES solicitacoes_cobertura(id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

// ------------------------------------------------------------
// 14. CPF DO COLABORADOR (RA_CIC): so leitura, sincronizado do Protheus; fora do allowlist de
// colunas graveis de dados.php.
// ------------------------------------------------------------
log_linha('');
log_linha('14) CPF do colaborador');

passo(
    'coluna colaboradores.cpf',
    temColuna($pdo, 'colaboradores', 'cpf'),
    fn() => $pdo->exec("ALTER TABLE colaboradores ADD COLUMN cpf VARCHAR(14) AFTER matricula")
);

// ------------------------------------------------------------
log_linha('');
log_linha('15) Escopo de Gestor separado do Preposto (Centro de Custo com papel + Cobertura do Gestor)');

// 15a) usuario_centros_custo ganha "papel" (separa as listas de quem e Gestor e Preposto, ver
// schema.sql). DEFAULT 'preposto' so evita falha do ALTER; o 15b corrige os Gestores.
passo(
    'coluna usuario_centros_custo.papel',
    temColuna($pdo, 'usuario_centros_custo', 'papel')
        && temIndice($pdo, 'usuario_centros_custo', 'uq_usuario_papel_centro')
        && !temIndice($pdo, 'usuario_centros_custo', 'uq_usuario_centro'),
    function () use ($pdo) {
        // Cada ALTER e guardado individualmente: rodar de novo apos falha no meio nao pode
        // recriar o que ja existe.
        if (!temColuna($pdo, 'usuario_centros_custo', 'papel')) {
            $pdo->exec("ALTER TABLE usuario_centros_custo
                           ADD COLUMN papel ENUM('gestor', 'preposto') NOT NULL DEFAULT 'preposto' AFTER usuario_id");
        }
        // A UNIQUE precisa incluir "papel" (usuario duplo pode ter o mesmo codigo nas 2 listas).
        // ORDEM IMPORTA: criar o indice novo ANTES de dropar o antigo, que sustenta a FK de
        // usuario_id (MySQL recusa o DROP, erro 1553).
        if (!temIndice($pdo, 'usuario_centros_custo', 'uq_usuario_papel_centro')) {
            $pdo->exec('ALTER TABLE usuario_centros_custo
                           ADD UNIQUE KEY uq_usuario_papel_centro (usuario_id, papel, centro_custo)');
        }
        if (temIndice($pdo, 'usuario_centros_custo', 'uq_usuario_centro')) {
            $pdo->exec('ALTER TABLE usuario_centros_custo DROP INDEX uq_usuario_centro');
        }
    }
);

// 15b) Backfill: linhas criadas antes da coluna ficaram 'preposto'; corrige para 'gestor' quem
// tem Gestor e NAO tem Preposto (principal gestor/operador/leitura ou Gestor como papel extra, ex. RH+Gestor;
// tambem a sobra de quem passou de Preposto a Gestor na tela antiga). Senao o Gestor perde o proprio Centro de
// Custo (a tela le a lista pelo papel) e segue no aviso de corte como Preposto. Quem tem Preposto nao e tocado:
// a lista 'preposto' dele e a do Preposto. IGNORE: centro que ja esta na lista do Gestor fica de fora do UPDATE
// e a sobra (igual a uma linha 'gestor' que ja existe) e apagada - nada se perde e a UNIQUE nunca quebra.
// TODOS nao vira Gestor (seria Gestor de todos os contratos sem ninguem decidir): fica como sobra do papel retirado e sai no
// proximo salvar do usuario (login.php apaga a lista do papel que a pessoa nao tem).
$condicaoListaGestorComoPreposto = "ucc.papel = 'preposto' AND ucc.centro_custo <> 'TODOS' AND u.papel NOT IN ('preposto', 'administrador')
    AND NOT EXISTS (SELECT 1 FROM usuario_papeis_extra e WHERE e.usuario_id = u.id AND e.papel = 'preposto')
    AND (u.papel IN ('gestor', 'operador', 'leitura')
         OR EXISTS (SELECT 1 FROM usuario_papeis_extra e WHERE e.usuario_id = u.id AND e.papel = 'gestor'))";
passo(
    'usuario_centros_custo.papel correto pros Gestores existentes',
    (function () use ($pdo, $condicaoListaGestorComoPreposto) {
        // --conferir roda antes do 15a e do 6 aplicarem; sem este guard a query quebra com "Unknown column/table".
        if (!temColuna($pdo, 'usuario_centros_custo', 'papel') || !temTabela($pdo, 'usuario_papeis_extra')) return false;
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM usuario_centros_custo ucc
               JOIN usuarios u ON u.id = ucc.usuario_id
              WHERE $condicaoListaGestorComoPreposto"
        );
        return ((int) $stmt->fetchColumn()) === 0;
    })(),
    function () use ($pdo, $condicaoListaGestorComoPreposto) {
        $pdo->exec(
            "UPDATE IGNORE usuario_centros_custo ucc
               JOIN usuarios u ON u.id = ucc.usuario_id
                SET ucc.papel = 'gestor'
              WHERE $condicaoListaGestorComoPreposto"
        );
        $pdo->exec(
            "DELETE ucc FROM usuario_centros_custo ucc
               JOIN usuario_centros_custo g ON g.usuario_id = ucc.usuario_id AND g.papel = 'gestor' AND g.centro_custo = ucc.centro_custo
               JOIN usuarios u ON u.id = ucc.usuario_id
              WHERE $condicaoListaGestorComoPreposto"
        );
    }
);

// 15c) Tabela do pool de volante/intermitente que um Gestor administra (ver schema.sql);
// mesmo formato de preposto_centro_cobertura.
passo(
    'tabela gestor_centro_cobertura',
    temTabela($pdo, 'gestor_centro_cobertura'),
    fn() => $pdo->exec(
        "CREATE TABLE gestor_centro_cobertura (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            centro_custo_cobre VARCHAR(50) NOT NULL,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            UNIQUE KEY uq_usuario_centro_cobre (usuario_id, centro_custo_cobre)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

// 15d) Dado existente: Centro de Custo nao guarda mais codigo volante/intermitente; os que um
// Gestor ja tinha migram para gestor_centro_cobertura (e saem de usuario_centros_custo). Fora do
// padrao passo()/jaFeito (transforma dados); so roda quando 15a/15c ja foram aplicados.
$passos++;
if (!temColuna($pdo, 'usuario_centros_custo', 'papel') || !temTabela($pdo, 'gestor_centro_cobertura')) {
    log_linha('  [PENDENTE ] migrar codigo volante/intermitente do Centro de Custo de Gestor pra Cobertura (depende dos passos acima)');
} else {
    require_once __DIR__ . '/../backend/scripts/protheus-colaboradores-helpers.php';
    $stmt = $pdo->query(
        "SELECT ucc.id, ucc.usuario_id, ucc.centro_custo, cc.descricao
           FROM usuario_centros_custo ucc
           LEFT JOIN centros_custo cc ON cc.codigo = ucc.centro_custo
          WHERE ucc.papel = 'gestor'"
    );
    $linhasVolante = array_filter(
        $stmt->fetchAll(),
        fn($l) => centroCustoEhVolante((string) $l['centro_custo'], (string) ($l['descricao'] ?? ''))
    );
    if (!$linhasVolante) {
        log_linha('  [ja existe] nenhum codigo volante/intermitente sobrando no Centro de Custo de Gestor');
    } elseif ($conferirApenas) {
        log_linha('  [PENDENTE ] ' . count($linhasVolante) . ' codigo(s) de Centro de Custo de Gestor pra mover pra Cobertura');
    } else {
        $stmtInsere = $pdo->prepare(
            'INSERT IGNORE INTO gestor_centro_cobertura (usuario_id, centro_custo_cobre) VALUES (?, ?)'
        );
        $stmtRemove = $pdo->prepare('DELETE FROM usuario_centros_custo WHERE id = ?');
        foreach ($linhasVolante as $linha) {
            $stmtInsere->execute([$linha['usuario_id'], $linha['centro_custo']]);
            $stmtRemove->execute([$linha['id']]);
        }
        $aplicados++;
        log_linha("  [aplicado ] " . count($linhasVolante) . " codigo(s) de Centro de Custo de Gestor movido(s) pra Cobertura");
    }
}

// ------------------------------------------------------------
// 16) "Retrato" de salario/periculosidade/insalubridade congelado na linha de
// solicitacoes_cobertura (ver schema.sql). 16a cria as 4 colunas; 16b preenche as linhas antigas
// com o valor ATUAL de colaboradores (aproximacao, melhor que NULL para o PFM).
// ------------------------------------------------------------
log_linha('');
log_linha('16) Retrato de salario/periculosidade/insalubridade por solicitacao');

passo(
    'colunas de retrato em solicitacoes_cobertura',
    temColuna($pdo, 'solicitacoes_cobertura', 'titular_salario'),
    fn() => $pdo->exec(
        "ALTER TABLE solicitacoes_cobertura
            ADD COLUMN titular_salario DECIMAL(12,2) NULL,
            ADD COLUMN titular_periculosidade TINYINT(1) NULL,
            ADD COLUMN titular_insalubridade_grau TINYINT(1) NULL,
            ADD COLUMN vol_salario DECIMAL(12,2) NULL"
    )
);

$passos++;
if (!temColuna($pdo, 'solicitacoes_cobertura', 'titular_salario')) {
    log_linha('  [PENDENTE ] backfill do retrato (depende do passo acima)');
} else {
    $stmtFaltantes = $pdo->query(
        "SELECT COUNT(*) FROM solicitacoes_cobertura WHERE titular_salario IS NULL"
    );
    $faltantes = (int) $stmtFaltantes->fetchColumn();
    if ($faltantes === 0) {
        log_linha('  [ja existe] nenhuma linha de solicitacoes_cobertura sem o retrato do titular');
    } elseif ($conferirApenas) {
        log_linha("  [PENDENTE ] $faltantes linha(s) sem retrato do titular (backfill com o salario ATUAL)");
    } else {
        $pdo->exec(
            "UPDATE solicitacoes_cobertura sc
               JOIN colaboradores co ON co.matricula = sc.matricula
                SET sc.titular_salario = co.salario,
                    sc.titular_periculosidade = co.periculosidade,
                    sc.titular_insalubridade_grau = co.insalubridade_grau
              WHERE sc.titular_salario IS NULL"
        );
        $pdo->exec(
            "UPDATE solicitacoes_cobertura sc
               JOIN colaboradores co ON co.matricula = sc.matricula_vol
                SET sc.vol_salario = co.salario
              WHERE sc.vol_salario IS NULL
                AND sc.matricula_vol IS NOT NULL AND sc.matricula_vol NOT IN ('', 'SEM_COBERTURA')"
        );
        $aplicados++;
        log_linha("  [aplicado ] backfill de $faltantes linha(s) (retrato aproximado pelo salario atual)");
    }
}

// ------------------------------------------------------------
// 17) sync_status.mensagem_erro vira TEXT: o aviso do retrato lista todos os casos e nao cabe
// em 500 caracteres.
// ------------------------------------------------------------
log_linha('');
log_linha('17) sync_status.mensagem_erro como TEXT');

passo(
    'sync_status.mensagem_erro TEXT',
    $pdo->query(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sync_status' AND COLUMN_NAME = 'mensagem_erro'"
    )->fetchColumn() === 'text',
    fn() => $pdo->exec('ALTER TABLE sync_status MODIFY mensagem_erro TEXT NULL')
);

// ------------------------------------------------------------
// 18) Administrador e perfil unico: tira os perfis extras de quem ja era Administrador.
// ------------------------------------------------------------
log_linha('');
log_linha('18) Administrador sem perfil extra');

$sqlAdminComExtra = "FROM usuario_papeis_extra WHERE usuario_id IN (SELECT id FROM usuarios WHERE papel = 'administrador')";
passo(
    'perfis extras de usuarios Administrador removidos',
    !temTabela($pdo, 'usuario_papeis_extra') || (int) $pdo->query("SELECT COUNT(*) $sqlAdminComExtra")->fetchColumn() === 0,
    function () use ($pdo, $sqlAdminComExtra) {
        $pdo->exec("DELETE $sqlAdminComExtra");
        // Sessao aberta que estava "alternada" pra um perfil extra volta pro principal.
        if (temColuna($pdo, 'sessoes', 'papel_ativo')) {
            $pdo->exec("UPDATE sessoes SET papel_ativo = NULL WHERE usuario_id IN (SELECT id FROM usuarios WHERE papel = 'administrador')");
        }
    }
);

// ------------------------------------------------------------
// 19) Tabela "configuracoes" + valor padrao do Vale Alimentacao (antes constante em
// beneficios/bene-intermitente.html, ver valor-va.php), iniciando em 35.07.
// ------------------------------------------------------------
log_linha('');
log_linha('19) Configuracoes (valor padrao do Vale Alimentacao)');

passo(
    'tabela configuracoes',
    temTabela($pdo, 'configuracoes'),
    fn() => $pdo->exec(
        "CREATE TABLE configuracoes (
            chave VARCHAR(50) NOT NULL PRIMARY KEY,
            valor VARCHAR(255) NOT NULL,
            atualizado_em DATETIME NULL,
            atualizado_por INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);
passo(
    'valor inicial do Vale Alimentacao (35.07)',
    temTabela($pdo, 'configuracoes')
        && (bool) $pdo->query("SELECT 1 FROM configuracoes WHERE chave = 'valor_va_padrao'")->fetchColumn(),
    fn() => $pdo->exec("INSERT IGNORE INTO configuracoes (chave, valor, atualizado_em) VALUES ('valor_va_padrao', '35.07', NOW())")
);

// ------------------------------------------------------------
// 20) Ausencia de cobertura: guarda quem faltou na linha (ausencia-cobertura.php) + caixa de
// notificacao por usuario (notificacoes.php).
// ------------------------------------------------------------
log_linha('');
log_linha('20) Ausencia de cobertura + caixa de notificacao');

foreach ([
    'ausencia_matricula_vol' => 'VARCHAR(20) NULL',
    'ausencia_nome_vol' => 'VARCHAR(150) NULL',
    'ausencia_em' => 'DATETIME NULL',
] as $coluna => $tipo) {
    passo(
        "solicitacoes_cobertura.$coluna",
        temColuna($pdo, 'solicitacoes_cobertura', $coluna),
        fn() => $pdo->exec("ALTER TABLE solicitacoes_cobertura ADD COLUMN $coluna $tipo")
    );
}
passo(
    'tabela notificacoes',
    temTabela($pdo, 'notificacoes'),
    fn() => $pdo->exec(
        "CREATE TABLE notificacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            tipo VARCHAR(30) NOT NULL,
            mensagem VARCHAR(500) NOT NULL,
            referencia_id INT NULL,
            criado_em DATETIME NOT NULL,
            lida_em DATETIME NULL,
            INDEX idx_notificacoes_usuario (usuario_id, lida_em),
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (referencia_id) REFERENCES solicitacoes_cobertura(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

// ------------------------------------------------------------
// 21) Base do volante (base-colaborador.php): 1 linha por dia, com trava de "1 base por volante
// por dia" no banco; a base nova substitui a anterior nos dias que se cruzam. A mesma trava
// entra na capacitacao (so a tela evitava duplicado); antes, apaga duplicados, ficando com o
// mais antigo.
// ------------------------------------------------------------
log_linha('');
log_linha('21) Base do volante + trava de duplicado na capacitacao');

passo(
    'tabela pedidos_cobertura_saf',
    temTabela($pdo, 'pedidos_cobertura_saf'),
    fn() => $pdo->exec(
        "CREATE TABLE pedidos_cobertura_saf (
            id INT AUTO_INCREMENT PRIMARY KEY,
            solicitacao_saf_id INT NOT NULL,
            arquivo VARCHAR(60) NOT NULL,
            resumo VARCHAR(300) NOT NULL,
            usuario_id INT NOT NULL,
            criado_em DATETIME NOT NULL,
            resolvido_em DATETIME NULL,
            aplicado TINYINT(1) NULL,
            mensagem VARCHAR(300) NULL,
            INDEX idx_pedidos_saf_aberto (resolvido_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

$cortesFeriasNova = !temTabela($pdo, 'cortes_ferias');
passo(
    'tabela cortes_ferias',
    !$cortesFeriasNova,
    fn() => $pdo->exec(
        "CREATE TABLE cortes_ferias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titular_ferias VARCHAR(20) NOT NULL,
            nome_ferias VARCHAR(150) NULL,
            ferias_inicio VARCHAR(10) NOT NULL,
            ferias_fim VARCHAR(10) NOT NULL,
            matricula_vol VARCHAR(20) NOT NULL,
            nome_vol VARCHAR(150) NULL,
            centro_custo VARCHAR(100) NOT NULL,
            cobertura_de VARCHAR(150) NULL,
            periodo VARCHAR(40) NOT NULL,
            criado_em DATETIME NOT NULL,
            resolvido_em DATETIME NULL,
            INDEX idx_cortes_ferias_aberto (resolvido_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);
// posto = 1: corte da linha do proprio titular coberta por outra pessoa (o posto ja esta coberto pelas ferias do SAF);
// vale enquanto a ferias tiver volante do SAF (cortar-coberturas-ferias.php). Aditiva: o codigo anterior nao le.
passo(
    'coluna cortes_ferias.posto',
    !$cortesFeriasNova && temColuna($pdo, 'cortes_ferias', 'posto'),
    fn() => $pdo->exec('ALTER TABLE cortes_ferias ADD COLUMN IF NOT EXISTS posto TINYINT(1) NOT NULL DEFAULT 0')
);

passo(
    'tabela colaborador_bases',
    temTabela($pdo, 'colaborador_bases'),
    fn() => $pdo->exec(
        "CREATE TABLE colaborador_bases (
            id INT AUTO_INCREMENT PRIMARY KEY,
            matricula VARCHAR(20) NOT NULL,
            nome VARCHAR(150) NULL,
            data VARCHAR(10) NOT NULL,
            centro_custo VARCHAR(100) NOT NULL,
            ausencia_em DATETIME NULL,
            criado_em DATETIME NOT NULL,
            criado_por INT NULL,
            UNIQUE KEY uq_base_matricula_data (matricula, data),
            INDEX idx_base_centro_data (centro_custo, data)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);
passo(
    'colaborador_capacitacoes: 1 por matricula+dia',
    (bool) $pdo->query(
        "SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colaborador_capacitacoes' AND INDEX_NAME = 'uq_capacitacao_matricula_data'"
    )->fetchColumn(),
    function () use ($pdo) {
        $removidos = $pdo->exec(
            "DELETE c1 FROM colaborador_capacitacoes c1
               JOIN colaborador_capacitacoes c2 ON c2.matricula = c1.matricula AND c2.data = c1.data AND c2.id < c1.id"
        );
        if ($removidos) log_linha("    $removidos capacitacao(oes) duplicada(s) removida(s)");
        $pdo->exec('ALTER TABLE colaborador_capacitacoes ADD UNIQUE KEY uq_capacitacao_matricula_data (matricula, data)');
    }
);
// Nota da aba Volantes Ociosos (previsao-ferias.php?acao=nota). Aditiva: o codigo anterior nao le.
passo(
    'tabela volante_notas',
    temTabela($pdo, 'volante_notas'),
    fn() => $pdo->exec(
        "CREATE TABLE volante_notas (
            matricula VARCHAR(20) NOT NULL PRIMARY KEY,
            texto VARCHAR(500) NOT NULL,
            atualizado_em DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);
// Historico de observacao (2026-11-09): nota por mes, com quem gravou. Aditiva: tabela nova, a volante_notas fica como esta
// (o codigo anterior continua lendo e gravando nela). As notas que ja existem vem para o mes em que foram gravadas.
passo(
    'tabela volante_notas_mes (copia as notas de volante_notas)',
    temTabela($pdo, 'volante_notas_mes'),
    function () use ($pdo) {
        $pdo->exec(
            "CREATE TABLE volante_notas_mes (
                matricula VARCHAR(20) NOT NULL,
                mes CHAR(7) NOT NULL,
                texto VARCHAR(500) NOT NULL,
                atualizado_em DATETIME NOT NULL,
                usuario_id INT NULL,
                PRIMARY KEY (matricula, mes)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $copiadas = $pdo->exec("INSERT IGNORE INTO volante_notas_mes (matricula, mes, texto, atualizado_em)
                                SELECT matricula, DATE_FORMAT(atualizado_em, '%Y-%m'), texto, atualizado_em FROM volante_notas");
        log_linha("    $copiadas nota(s) copiada(s) de volante_notas");
    }
);
// Nota gravada pela tela antiga entre a migracao de antes do deploy e a troca do container (revisao do MR, 2026-11-11): a migracao
// de depois do deploy (.gitlab-ci.yml, minutos depois) traz para o mes dela, se for mais nova que a do mesmo mes. So nota do ultimo
// dia: depois do deploy volante_notas nao muda. Limite conhecido (revisao de 2026-11-13): nota desse ultimo dia apagada na tela nova
// volta se a migracao rodar de novo dentro dele (outro deploy no mesmo dia).
$sqlNotasAtrasadas = "FROM volante_notas n LEFT JOIN volante_notas_mes m ON m.matricula = n.matricula AND m.mes = DATE_FORMAT(n.atualizado_em, '%Y-%m')
      WHERE n.atualizado_em >= NOW() - INTERVAL 1 DAY AND (m.matricula IS NULL OR n.atualizado_em > m.atualizado_em)";
passo(
    'notas de volante_notas gravadas depois da copia',
    !(temTabela($pdo, 'volante_notas') && temTabela($pdo, 'volante_notas_mes') && (int) $pdo->query("SELECT COUNT(*) $sqlNotasAtrasadas")->fetchColumn() > 0),
    function () use ($pdo, $sqlNotasAtrasadas) {
        $copiadas = $pdo->exec("INSERT INTO volante_notas_mes (matricula, mes, texto, atualizado_em)
                                SELECT n.matricula, DATE_FORMAT(n.atualizado_em, '%Y-%m'), n.texto, n.atualizado_em $sqlNotasAtrasadas
                                ON DUPLICATE KEY UPDATE volante_notas_mes.usuario_id = NULL, volante_notas_mes.texto = VALUES(texto),
                                                        volante_notas_mes.atualizado_em = VALUES(atualizado_em)");
        log_linha("    $copiadas linha(s) afetada(s) em volante_notas_mes");
    }
);
// Foto diaria da aba Volantes Ociosos (registrar-ociosidade.php). Aditiva: o codigo anterior nao le.
passo(
    'tabela volante_ociosidade',
    temTabela($pdo, 'volante_ociosidade'),
    fn() => $pdo->exec(
        "CREATE TABLE volante_ociosidade (
            matricula VARCHAR(20) NOT NULL,
            dia DATE NOT NULL,
            PRIMARY KEY (matricula, dia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

// ------------------------------------------------------------
// 22. "Adicionar colaborador": centro volante de destino (transferencia para volante / aviso) - o Gestor que tem esse centro na
// Cobertura ve a linha - e a resposta ao aviso vencido de quem ainda esta ativo no Protheus (PENDENTE/AGUARDANDO, sync passo 4).
// Aditivas: o codigo anterior nao le.
// ------------------------------------------------------------
log_linha('');
log_linha('22) Centro de destino e aviso vencido do "Adicionar colaborador"');
passo(
    'coluna colaboradores.centro_destino',
    temColuna($pdo, 'colaboradores', 'centro_destino'),
    fn() => $pdo->exec('ALTER TABLE colaboradores ADD COLUMN centro_destino VARCHAR(50) NULL AFTER motivo_inclusao')
);
passo(
    'coluna colaboradores.aviso_situacao',
    temColuna($pdo, 'colaboradores', 'aviso_situacao'),
    fn() => $pdo->exec('ALTER TABLE colaboradores ADD COLUMN aviso_situacao VARCHAR(20) NULL AFTER fim_aviso')
);

// ------------------------------------------------------------
// 23. Observacao do Preposto na Nova Solicitacao (coberturas/solicitacao.html). Aditiva: o codigo anterior nao le.
// ------------------------------------------------------------
log_linha('');
log_linha('23) Observacao do Preposto na solicitacao de cobertura');
passo(
    'coluna solicitacoes_cobertura.observacao',
    temColuna($pdo, 'solicitacoes_cobertura', 'observacao'),
    fn() => $pdo->exec('ALTER TABLE solicitacoes_cobertura ADD COLUMN observacao VARCHAR(300) NULL')
);

// ------------------------------------------------------------
// 24. Quem abriu a solicitacao ("Aberta por" no painel, coberturas/solicitacao.html). Aditiva: o codigo anterior nao le.
// ------------------------------------------------------------
log_linha('');
log_linha('24) Quem abriu a solicitacao de cobertura');
foreach (['criado_por_login' => 'VARCHAR(100) NULL', 'criado_por_papel' => 'VARCHAR(20) NULL'] as $coluna => $tipo) {
    passo(
        "coluna solicitacoes_cobertura.$coluna",
        temColuna($pdo, 'solicitacoes_cobertura', $coluna),
        fn() => $pdo->exec("ALTER TABLE solicitacoes_cobertura ADD COLUMN $coluna $tipo")
    );
}

// ------------------------------------------------------------
// 25. Escala da Nova Solicitacao (coberturas/solicitacao.html). Aditiva: o codigo anterior nao le.
// ------------------------------------------------------------
log_linha('');
log_linha('25) Escala na solicitacao de cobertura');
passo(
    'coluna solicitacoes_cobertura.escala',
    temColuna($pdo, 'solicitacoes_cobertura', 'escala'),
    fn() => $pdo->exec('ALTER TABLE solicitacoes_cobertura ADD COLUMN escala VARCHAR(30) NULL')
);

// ------------------------------------------------------------
// 26. Quem editou a Nota do Preposto por ultimo ("editada por", coberturas/solicitacao.html). Aditiva: o codigo anterior nao le.
// ------------------------------------------------------------
log_linha('');
log_linha('26) Quem editou a nota da solicitacao de cobertura');
foreach (['observacao_editada_por' => 'VARCHAR(100) NULL', 'observacao_editada_em' => 'DATETIME NULL'] as $coluna => $tipo) {
    passo(
        "coluna solicitacoes_cobertura.$coluna",
        temColuna($pdo, 'solicitacoes_cobertura', $coluna),
        fn() => $pdo->exec("ALTER TABLE solicitacoes_cobertura ADD COLUMN $coluna $tipo")
    );
}

// ------------------------------------------------------------
// 27. Troca de quem cobre na Previsao: Gestores do grupo de quem sai/entra a avisar (previsao-ferias.php). Aditiva.
// ------------------------------------------------------------
log_linha('');
log_linha('27) Gestores do grupo a avisar no pedido ao SAF');
passo(
    'coluna pedidos_cobertura_saf.avisar_ids',
    temColuna($pdo, 'pedidos_cobertura_saf', 'avisar_ids'),
    fn() => $pdo->exec('ALTER TABLE pedidos_cobertura_saf ADD COLUMN avisar_ids VARCHAR(500) NULL')
);

// ------------------------------------------------------------
// 28. Nota do Preposto por ferias do SAF na Previsao (previsao-ferias.php?acao=nota_ferias, 2026-11-13). Aditiva: tabela nova.
// ------------------------------------------------------------
log_linha('');
log_linha('28) Nota do Preposto nas ferias da Previsao');
passo(
    'tabela ferias_saf_notas',
    temTabela($pdo, 'ferias_saf_notas'),
    fn() => $pdo->exec(
        "CREATE TABLE ferias_saf_notas (
            solicitacao_saf_id INT NOT NULL PRIMARY KEY,
            texto VARCHAR(500) NOT NULL,
            atualizado_em DATETIME NOT NULL,
            usuario_id INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    )
);

// ------------------------------------------------------------
// CONFERIR (so leitura, nao e passo): quanto o passo 4e do sync de colaboradores (sync-protheus-colaboradores.php) vai reabrir na
// proxima madrugada - dias de volante ja desligado que continua designado depois do desligamento, em periodo do PFM ainda aberto
// (inicioPeriodoPfmAberto: o db.php antigo, com que o CI roda este arquivo antes do deploy, ja tem a mesma funcao). Os incluidos
// a mao que o Protheus ja desligou ganham a data no passo 3 do MESMO sync e tambem sao reabertos nele - ficam fora desta conta.
// ------------------------------------------------------------
try {
    $hojeConferir = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();
    $inicioPfmConferir = inicioPeriodoPfmAberto($hojeConferir);
    $stmtConferir = $pdo->prepare(
        "SELECT COUNT(*) AS dias, COUNT(DISTINCT sc.matricula_vol) AS volantes
           FROM solicitacoes_cobertura sc
           JOIN (SELECT matricula, MAX(STR_TO_DATE(desligamento, '%d/%m/%Y')) AS deslig FROM colaboradores
                  GROUP BY matricula HAVING SUM(desligamento IS NULL OR desligamento = '') = 0) d ON d.matricula = sc.matricula_vol
          WHERE d.deslig <= ? AND STR_TO_DATE(sc.data, '%d/%m/%Y') > d.deslig AND STR_TO_DATE(sc.data, '%d/%m/%Y') >= ?"
    );
    $stmtConferir->execute([$hojeConferir, $inicioPfmConferir]);
    $contagem = $stmtConferir->fetch(PDO::FETCH_ASSOC);
    log_linha('');
    log_linha("CONFERIR: o passo 4e do sync vai reabrir {$contagem['dias']} dia(s) de cobertura de {$contagem['volantes']} volante(s) "
        . 'desligado(s) na proxima madrugada (voltam para Abertas, com aviso no sino), fora os incluidos a mao que o Protheus ja desligou.');
} catch (Throwable $e) {
    log_linha('CONFERIR: a contagem do passo 4e nao rodou - ' . $e->getMessage());
}

// ------------------------------------------------------------
log_linha('');
if ($conferirApenas) {
    log_linha("Conferencia concluida: $passos passo(s) verificado(s).");
    log_linha('Rode sem --conferir para aplicar o que estiver PENDENTE.');
} else {
    log_linha("Migracao concluida: $aplicados de $passos passo(s) aplicados agora.");
    log_linha('O restante ja estava no lugar. Pode rodar de novo sem problema.');
}
