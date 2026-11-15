-- ============================================================
-- Schema do banco MySQL/MariaDB. Aplicado automaticamente na primeira subida do banco
-- (ver README.md). Datas ficam como VARCHAR(10) no formato DD/MM/AAAA (nao DATE), formato que
-- todo o codigo das paginas ja usa.
-- ============================================================

-- 'rh' = acesso total, inclusive gerenciar usuarios. 'gestor' = Colaboradores e Coberturas
-- (so dos centros de custo liberados em usuario_centros_custo). 'preposto' = so Colaboradores,
-- no mesmo esquema (sem Coberturas). "papel" e sempre o PRINCIPAL; a pessoa pode acumular os
-- outros (usuario_papeis_extra) e alternar o perfil ATIVO na sessao (sessoes.papel_ativo,
-- alternarPerfilAtivo() em db.php), o que muda so a "lente" de acesso, nao o cadastro.
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(100) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    -- 'administrador' = acesso total de 'rh' mais a pagina de Logs (logs.html). Nome diferente de
    -- 'admin' de proposito: esse literal e LEGADO (normalizarPapel() o converte em 'rh').
    papel ENUM('rh', 'gestor', 'preposto', 'administrador') NOT NULL DEFAULT 'gestor'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Conta inicial: o Administrador unico. Senha so em hash bcrypt - troque pela tela de Usuarios
-- no primeiro acesso. So roda na criacao do banco; nao mexe num Admin que ja exista.
INSERT INTO usuarios (usuario, senha_hash, papel) VALUES
('Admin', '$2y$10$EyKhxRTGzpni/8B8PUg/neSQoJKXkPlKVgUnEa5o5cg2BfriFnyKK', 'administrador')
ON DUPLICATE KEY UPDATE usuario = usuario;

-- Papeis ALEM do principal (usuarios.papel): ate 2 linhas, para dar os 3 papeis ao mesmo tempo.
-- Nunca guarda 'rh': quando RH esta entre os papeis, ele e sempre o principal (regra em
-- login.php, acao=salvar).
CREATE TABLE IF NOT EXISTS usuario_papeis_extra (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    papel ENUM('gestor', 'preposto') NOT NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    UNIQUE KEY uq_usuario_papel_extra (usuario_id, papel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- ESCOPO DE ACESSO DE GESTOR/PREPOSTO - 2 conceitos, com significado diferente por papel:
--
--   CENTRO DE CUSTO (usuario_centros_custo): nunca volante/intermitente.
--     Preposto: o proprio time dele, usado direto para ver suas coisas.
--     Gestor: de quais centros aparecem as SOLICITACOES (titulares de outros times) para atender.
--   COBERTURA (preposto_centro_cobertura / gestor_centro_cobertura): sempre volante/intermitente.
--     Preposto: quem cobre ele - so informativo, NAO entra no que ele ve (condicaoCentroTitular,
--     dashboard.php).
--     Gestor: o pool de COLABORADORES (volantes) que ele administra.
--
-- Papel duplo (Gestor + Preposto): cada papel usa so o seu Centro de Custo (a coluna "papel"
-- separa as listas); so a Cobertura do lado Gestor e usada.
-- ============================================================

-- Centros de custo que um usuario Gestor ou Preposto pode ver/editar (N para N). So para
-- papel='gestor' ou 'preposto'; 'rh' tem acesso total. "papel" diz a qual dos papeis a linha
-- pertence: quem e Gestor E Preposto tem 2 listas separadas.
CREATE TABLE IF NOT EXISTS usuario_centros_custo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    papel ENUM('gestor', 'preposto') NOT NULL DEFAULT 'preposto',
    centro_custo VARCHAR(50) NOT NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    UNIQUE KEY uq_usuario_papel_centro (usuario_id, papel, centro_custo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Centros de custo que COBREM (fornecem volantes para) um Preposto - independente de
-- usuario_centros_custo ("onde trabalha"); so informativo para ele. Um Gestor ve a solicitacao
-- de um Preposto se um centro do Gestor estiver aqui (condicaoCentroTitular, dashboard.php).
-- So para papel='preposto'.
CREATE TABLE IF NOT EXISTS preposto_centro_cobertura (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    centro_custo_cobre VARCHAR(50) NOT NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    UNIQUE KEY uq_usuario_centro_cobre (usuario_id, centro_custo_cobre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Centros de custo (pool de volante/intermitente) que um Gestor administra: define quais
-- COLABORADORES aparecem para ele. Mesmo formato de preposto_centro_cobertura, em tabela
-- separada de proposito (papeis diferentes). So para papel='gestor'.
CREATE TABLE IF NOT EXISTS gestor_centro_cobertura (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    centro_custo_cobre VARCHAR(50) NOT NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    UNIQUE KEY uq_usuario_centro_cobre (usuario_id, centro_custo_cobre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sessao de login: cada login apaga a sessao anterior do usuario (so uma ativa; o dispositivo
-- antigo e desconectado na proxima acao). Nao ha logout por inatividade, mas expira_em dura
-- ~90s e e renovada por pulso enquanto a aba esta aberta (SESSAO_DURACAO_SEGUNDOS em db.php).
-- Guarda o HASH (SHA-256) do token, nao o token, para um vazamento do banco nao permitir
-- se passar por um usuario logado.
-- Limite de tentativas de login (5 erradas em 15min bloqueiam por 15min): ver
-- minutosBloqueioLogin()/registrarTentativaLogin() em db.php. "usuario" e sempre minusculo.
CREATE TABLE IF NOT EXISTS login_tentativas (
    usuario VARCHAR(100) PRIMARY KEY,
    tentativas INT NOT NULL DEFAULT 0,
    ultima_tentativa DATETIME NOT NULL,
    bloqueado_ate DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessoes (
    token_hash VARCHAR(64) PRIMARY KEY,
    usuario_id INT NOT NULL,
    expira_em DATETIME NOT NULL,
    -- Papel ATIVO nesta sessao (para quem tem papel extra). NULL = papel principal.
    papel_ativo VARCHAR(20) NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela unica de colaboradores do CDV. "origem": IMPORTADO (sync do Protheus) ou MANUAL.
CREATE TABLE IF NOT EXISTS colaboradores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    origem ENUM('IMPORTADO', 'MANUAL') NOT NULL DEFAULT 'IMPORTADO',
    filial VARCHAR(20),
    centro_custo VARCHAR(50),
    matricula VARCHAR(20),
    -- CPF formatado (XXX.XXX.XXX-XX) pelo sync (RA_CIC); so leitura, fora do allowlist de dados.php.
    cpf VARCHAR(14),
    nome VARCHAR(150),
    funcao VARCHAR(100),
    funcao_codigo VARCHAR(30),
    data_admissao VARCHAR(10),
    salario DECIMAL(12,2),
    -- Periculosidade/insalubridade do Protheus (RA_ADCPERI/RA_ADCINS), usadas no calculo do PFM;
    -- codigos em ferramentas/migrar-banco.php passo 12.
    periculosidade TINYINT(1) NOT NULL DEFAULT 0,
    insalubridade_grau TINYINT(1) NOT NULL DEFAULT 0,
    motivo_inclusao VARCHAR(100),
    -- Centro volante escolhido no "Adicionar colaborador" (transferencia para volante / aviso): o Gestor com esse centro na
    -- Cobertura ve a linha (dados.php). O sync limpa quando a pessoa chega a um centro volante.
    centro_destino VARCHAR(50),
    inicio_aviso VARCHAR(10),
    fim_aviso VARCHAR(10),
    -- Aviso vencido de quem continua ativo no Protheus (sync passo 4): PENDENTE = painel pergunta; AGUARDANDO = "Manter e
    -- aguardar". Mudar fim_aviso limpa.
    aviso_situacao VARCHAR(20),
    transferencia VARCHAR(100),
    experiencia1 VARCHAR(10),
    experiencia2 VARCHAR(10),
    desligamento VARCHAR(10),
    ferias_inicio VARCHAR(10),
    ferias_fim VARCHAR(10),
    email VARCHAR(150),
    contato VARCHAR(30),
    observacao TEXT,
    -- UNIQUE de proposito: uma matricula tem UMA linha no sistema, seja qual for a origem. Evita
    -- criar uma linha MANUAL em vez de converter a IMPORTADO, o que duplicava o colaborador.
    UNIQUE KEY uq_colaboradores_matricula (matricula),
    INDEX idx_colaboradores_centro_custo (centro_custo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Solicitacoes/coberturas, 1 linha por dia (filtravel por "data").
CREATE TABLE IF NOT EXISTS solicitacoes_cobertura (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filial VARCHAR(20),
    centro_custo VARCHAR(100),
    matricula VARCHAR(20),
    nome VARCHAR(150),
    funcao VARCHAR(100),
    data VARCHAR(10),
    motivo VARCHAR(150),
    matricula_vol VARCHAR(20),
    nome_vol VARCHAR(150),
    -- Quando a linha fechou (designacao manual ou fechamento automatico por prazo, ver
    -- fechar-solicitacoes-vencidas.php); o frontend usa para destacar a janela de 15 dias de correcao.
    fechado_em DATETIME NULL,
    -- Quando a linha foi criada (NOW()); NULL em linhas antigas. Uso: o Preposto so pode CANCELAR
    -- (nunca editar) uma cobertura dentro de 24h daqui (dados.php).
    criado_em DATETIME NULL,
    -- "Retrato" congelado no momento do evento: salario/periculosidade/insalubridade do TITULAR na
    -- criacao da linha e salario de quem COBRIU quando matricula_vol e definido. Sem ele,
    -- pfm-calculo.php e dashboard.php (glosa) usariam o valor ATUAL de colaboradores e reabrir um
    -- PFM antigo apos reajuste daria o salario errado. NULL em linhas antigas (migrar-banco.php
    -- faz um backfill aproximado).
    titular_salario DECIMAL(12,2) NULL,
    titular_periculosidade TINYINT(1) NULL,
    titular_insalubridade_grau TINYINT(1) NULL,
    vol_salario DECIMAL(12,2) NULL,
    -- Ausencia de cobertura: o Preposto marca que o volante designado nao compareceu; a linha vira
    -- SEM_COBERTURA e guarda aqui quem faltou e quando (ausencia-cobertura.php). Redesignar o MESMO
    -- volante desfaz (limpa os 3 campos); designar outro mantem o registro.
    ausencia_matricula_vol VARCHAR(20) NULL,
    ausencia_nome_vol VARCHAR(150) NULL,
    ausencia_em DATETIME NULL,
    -- Observacao do Preposto na Nova Solicitacao (opcional, ate 300 caracteres), repetida em todos os dias da
    -- solicitacao; os dias que a vaga de desligamento/afastamento ganha sozinha copiam a da linha ancora
    -- (estender-vagas-helpers.php). O Preposto edita so enquanto ainda pode cancelar (dados.php,
    -- acao=salvar_observacao); RH/Gestor so leem.
    observacao VARCHAR(300) NULL,
    -- Quem mudou a nota por ultimo (login) e quando, pelo painel do Preposto (acao=salvar_observacao): "editada por" nos paineis.
    -- NULL = nunca editada depois de aberta.
    observacao_editada_por VARCHAR(100) NULL,
    observacao_editada_em DATETIME NULL,
    -- Quem abriu (retrato do login e do papel ativo no INSERT, quemAbriuSolicitacao em db.php): "Aberta por" no painel. Os dias
    -- que a vaga ganha sozinha copiam da ancora. NULL em linha antiga e nas de ausencia de ferias do SAF.
    criado_por_login VARCHAR(100) NULL,
    criado_por_papel VARCHAR(20) NULL,
    -- Escala da Nova Solicitacao (codigo de ESCALAS_SOLICITACAO, db.php): obrigatoria quando o Preposto abre e nunca muda depois;
    -- 12x36 par/impar so comeca em dia par/impar. Os dias que a vaga ganha sozinha copiam da ancora. NULL = linha antiga ou
    -- criada pelo sistema (a tela mostra "-").
    escala VARCHAR(30) NULL,
    INDEX idx_solicitacoes_titular (matricula, data),
    INDEX idx_solicitacoes_volante (matricula_vol, data),
    INDEX idx_solicitacoes_centro_custo (centro_custo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Controle da "vaga" de uma solicitacao de Desligamento (motivo "desligamento"): nao tem Fim na
-- criacao, so Inicio; fica em aberto, crescendo 1 linha por dia em solicitacoes_cobertura
-- (estender-solicitacoes-desligamento.php) ate o Preposto definir o Fim - automaticamente
-- (desligamento-confirmacao.php detecta colaborador novo no centro e ele confirma) ou na tela.
-- solicitacao_cobertura_id aponta para a linha do dia INICIO, a "ancora" da vaga. Com
-- fim_definido_em preenchido a vaga vira solicitacao normal (Inicio->Fim; fecha sozinha 3 dias
-- depois, ver fechar-solicitacoes-vencidas.php) e esta tabela deixa de ser consultada.
CREATE TABLE IF NOT EXISTS solicitacoes_desligamento (
    solicitacao_cobertura_id INT PRIMARY KEY,
    centro_custo VARCHAR(100) NOT NULL,
    matricula_titular VARCHAR(20) NOT NULL,
    aberta_em DATETIME NOT NULL,
    fim_definido_em DATETIME NULL,
    -- So informativo/auditoria (substituto confirmado pelo Preposto); NUNCA preenche matricula_vol
    -- em solicitacoes_cobertura, decisao exclusiva de RH/Gestor.
    substituto_sugerido_matricula VARCHAR(20) NULL,
    substituto_sugerido_nome VARCHAR(150) NULL,
    FOREIGN KEY (solicitacao_cobertura_id) REFERENCES solicitacoes_cobertura(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Retrato" de quem ja estava no centro de custo quando a vaga abriu (ativos, exceto o titular),
-- para saber quem e "novo" depois (candidato a substituto); ver acao=pendente em
-- desligamento-confirmacao.php.
CREATE TABLE IF NOT EXISTS solicitacao_desligamento_baseline (
    solicitacao_cobertura_id INT NOT NULL,
    matricula VARCHAR(20) NOT NULL,
    PRIMARY KEY (solicitacao_cobertura_id, matricula),
    FOREIGN KEY (solicitacao_cobertura_id) REFERENCES solicitacoes_cobertura(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Candidato que o Preposto disse que NAO e o substituto de uma vaga: nao reaparece nessa vaga,
-- mas pode ser candidato de outra.
CREATE TABLE IF NOT EXISTS solicitacao_desligamento_descartado (
    solicitacao_cobertura_id INT NOT NULL,
    matricula VARCHAR(20) NOT NULL,
    descartado_em DATETIME NOT NULL,
    PRIMARY KEY (solicitacao_cobertura_id, matricula),
    FOREIGN KEY (solicitacao_cobertura_id) REFERENCES solicitacoes_cobertura(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mesmas 3 tabelas, para a vaga de Afastamento (motivo "afastamento_indeterminado": licenca/INSS
-- por tempo indeterminado). Tabelas proprias porque o titular continua ATIVO em colaboradores e
-- porque o motivo precisa diferir de 'afastamento' (Atestado > 15 dias, sempre fechado, em
-- preposto-solicitacoes.php), que nunca deve se misturar com a vaga em aberto.
CREATE TABLE IF NOT EXISTS solicitacoes_afastamento (
    solicitacao_cobertura_id INT PRIMARY KEY,
    centro_custo VARCHAR(100) NOT NULL,
    matricula_titular VARCHAR(20) NOT NULL,
    aberta_em DATETIME NOT NULL,
    fim_definido_em DATETIME NULL,
    substituto_sugerido_matricula VARCHAR(20) NULL,
    substituto_sugerido_nome VARCHAR(150) NULL,
    FOREIGN KEY (solicitacao_cobertura_id) REFERENCES solicitacoes_cobertura(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS solicitacao_afastamento_baseline (
    solicitacao_cobertura_id INT NOT NULL,
    matricula VARCHAR(20) NOT NULL,
    PRIMARY KEY (solicitacao_cobertura_id, matricula),
    FOREIGN KEY (solicitacao_cobertura_id) REFERENCES solicitacoes_cobertura(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS solicitacao_afastamento_descartado (
    solicitacao_cobertura_id INT NOT NULL,
    matricula VARCHAR(20) NOT NULL,
    descartado_em DATETIME NOT NULL,
    PRIMARY KEY (solicitacao_cobertura_id, matricula),
    FOREIGN KEY (solicitacao_cobertura_id) REFERENCES solicitacoes_cobertura(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Substitui coberturas/notificacao_cobertura.xlsx
CREATE TABLE IF NOT EXISTS contratos_notificacao (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contrato VARCHAR(150),
    responsavel VARCHAR(150),
    telefone VARCHAR(30),
    localizacao VARCHAR(200)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Aba "VALORES VT" de intermitente. optante: se o colaborador opta por receber VT (default 1,
-- pois quem ja estava cadastrado ja recebia).
CREATE TABLE IF NOT EXISTS vt_valores (
    matricula VARCHAR(20) PRIMARY KEY,
    nome VARCHAR(150),
    valor_vt DECIMAL(10,2),
    optante TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Datas de capacitacao por colaborador (1-N), por isso tabela propria.
CREATE TABLE IF NOT EXISTS colaborador_capacitacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    matricula VARCHAR(20) NOT NULL,
    data VARCHAR(10) NOT NULL,
    INDEX idx_capacitacoes_matricula (matricula),
    -- 1 capacitacao por pessoa por dia (so a tela evitava duplicado)
    UNIQUE KEY uq_capacitacao_matricula_data (matricula, data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pedidos de alteracao de cobertura mandados ao SAF pela Previsao (previsao-ferias.php). O SAF aplica e devolve o
-- resultado em arquivo; resolvido_em/aplicado/mensagem vem dele e quem pediu recebe aviso no sino.
CREATE TABLE IF NOT EXISTS pedidos_cobertura_saf (
    id INT AUTO_INCREMENT PRIMARY KEY,
    solicitacao_saf_id INT NOT NULL,
    arquivo VARCHAR(60) NOT NULL,
    resumo VARCHAR(300) NOT NULL,
    usuario_id INT NOT NULL,
    criado_em DATETIME NOT NULL,
    resolvido_em DATETIME NULL,
    aplicado TINYINT(1) NULL,
    mensagem VARCHAR(300) NULL,
    -- Ids (virgula) dos Gestores do grupo de quem sai e de quem entra (volante e substituto): avisados quando o SAF aplica.
    avisar_ids VARCHAR(500) NULL,
    INDEX idx_pedidos_saf_aberto (resolvido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Coberturas cortadas por ferias do SAF (cortar-coberturas-ferias.php). Enquanto resolvido_em e NULL, a
-- rotina confere se a ferias continua no arquivo; se sumiu ou mudou, avisa que o volante pode ter ficado livre.
CREATE TABLE IF NOT EXISTS cortes_ferias (
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
    posto TINYINT(1) NOT NULL DEFAULT 0, -- 1 = linha do proprio titular coberta por outra pessoa (cortar-coberturas-ferias.php)
    INDEX idx_cortes_ferias_aberto (resolvido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Base do volante: volante alocado num contrato (centro_custo) por um periodo, 1 linha por dia
-- (base-colaborador.php). 1 base por volante por dia: a nova substitui a anterior nos dias que
-- se cruzam. Nao entra no PFM. Prioridade no mesmo dia: cobertura > capacitacao > base.
-- ausencia_em = o Preposto marcou que o volante nao compareceu a base naquele dia.
CREATE TABLE IF NOT EXISTS colaborador_bases (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Solicitacoes que o Preposto registra por colaborador (falta, atestado, servico extra); regras
-- em preposto-solicitacoes.php. 'afastamento' esta no ENUM por ser derivado automaticamente de
-- Atestado > 15 dias. usuario_id/usuario sao denormalizados (como em "auditoria") para
-- sobreviver a exclusao da conta.
CREATE TABLE IF NOT EXISTS preposto_solicitacoes (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Resposta do Gestor a uma solicitacao do Preposto (designar volante ou marcar sem cobertura),
-- ver cobertura-gestor.php. Uma solicitacao pode ter VARIOS segmentos, cada um cobrindo uma
-- fatia do periodo (data_inicio/data_fim sao do SEGMENTO); por isso nao ha UNIQUE(solicitacao_id)
-- e a nao-sobreposicao e checada na aplicacao (validarSegmento()). Cancelar um segmento = apagar
-- a linha, reabrindo os dias.
CREATE TABLE IF NOT EXISTS preposto_solicitacoes_respostas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    solicitacao_id INT NOT NULL,
    tipo ENUM('designado', 'sem_cobertura') NOT NULL,
    data_inicio VARCHAR(10) NOT NULL,
    data_fim VARCHAR(10) NOT NULL,
    matricula_vol VARCHAR(20) NULL,
    nome_vol VARCHAR(150) NULL,
    -- Ids (separados por virgula) das linhas criadas em solicitacoes_cobertura por uma resposta
    -- "designado", para cancelar/editar apagando exatamente essas.
    cobertura_ids TEXT NULL,
    usuario_id INT NULL,
    usuario VARCHAR(100) NULL,
    criado_em DATETIME NOT NULL,
    INDEX idx_resposta_solicitacao (solicitacao_id),
    INDEX idx_resposta_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cache da descricao de cada centro de custo vinda do Protheus (sync-protheus-centros-custo.php),
-- para exibir "nome - codigo". codigo e a chave (upsert); dicionario de leitura, sem escopo por
-- usuario. tem_colaborador: 1 se o centro tem funcionario ATIVO no Protheus na ultima sync;
-- tira do seletor de Gerenciar Usuarios categorias contabeis sem ninguem alocado.
CREATE TABLE IF NOT EXISTS centros_custo (
    codigo VARCHAR(20) PRIMARY KEY,
    descricao VARCHAR(150) NOT NULL,
    tem_colaborador TINYINT(1) NOT NULL DEFAULT 0,
    atualizado_em DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- sync_status: quando cada sincronizacao terminou com sucesso pela ultima vez. Lido por
-- verificar-saude-sync.php (de hora em hora no container "sync") para avisar se alguma ficar
-- muito tempo sem sucesso.
-- configuracoes: valores que o RH altera pela tela. Hoje so 'valor_va_padrao' (Vale Alimentacao
-- em Beneficios > Intermitentes, valor-va.php).
CREATE TABLE IF NOT EXISTS configuracoes (
    chave VARCHAR(50) NOT NULL PRIMARY KEY,
    valor VARCHAR(255) NOT NULL,
    atualizado_em DATETIME NULL,
    atualizado_por INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_status (
    chave VARCHAR(50) NOT NULL PRIMARY KEY,
    executado_em DATETIME NULL,
    ultimo_erro_em DATETIME NULL,
    mensagem_erro TEXT NULL, -- TEXT: o aviso 'retrato_solicitacoes' lista todos os casos
    notificado_atraso_em DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Caixa de notificacao por usuario: o aviso fica ate a pessoa abrir qualquer pagina (o sino busca
-- os nao lidos em notificacoes.php; o X marca como lido). Tipos: 'ausencia' (referencia_id =
-- linha de solicitacoes_cobertura; apagar a linha apaga os avisos) e 'cancelamento' (sem
-- referencia).
CREATE TABLE IF NOT EXISTS notificacoes (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nota antiga da aba Volantes Ociosos (1 por volante, sem mes): substituida por volante_notas_mes. Fica so para o codigo
-- anterior (a migracao copiou as notas dela); nada novo grava aqui.
CREATE TABLE IF NOT EXISTS volante_notas (
    matricula VARCHAR(20) NOT NULL PRIMARY KEY,
    texto VARCHAR(500) NOT NULL,
    atualizado_em DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nota da aba Volantes Ociosos (coberturas/previsao.html, previsao-ferias.php?acao=nota): 1 por volante POR MES (o mes aberto na
-- Previsao, AAAA-MM), a nova substitui a do mesmo mes. Aparece no Historico de observacao. Quem gravou tambem fica na auditoria.
CREATE TABLE IF NOT EXISTS volante_notas_mes (
    matricula VARCHAR(20) NOT NULL,
    mes CHAR(7) NOT NULL,
    texto VARCHAR(500) NOT NULL,
    atualizado_em DATETIME NOT NULL,
    usuario_id INT NULL,
    PRIMARY KEY (matricula, mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nota do Preposto na aba Ferias - Precisa de Cobertura (coberturas/previsao.html, previsao-ferias.php?acao=nota_ferias): 1 por ferias
-- do SAF (id da solicitacao no cobertura.json), a nova substitui a anterior. RH/Gestor veem na tela e ela vai na mensagem do
-- WhatsApp para o colaborador ("Nota:" embaixo do Contrato). Quem gravou tambem fica na auditoria.
CREATE TABLE IF NOT EXISTS ferias_saf_notas (
    solicitacao_saf_id INT NOT NULL PRIMARY KEY,
    texto VARCHAR(500) NOT NULL,
    atualizado_em DATETIME NOT NULL,
    usuario_id INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Foto diaria da aba Volantes Ociosos (backend/scripts/registrar-ociosidade.php): 1 linha por volante por dia em que ficou ocioso,
-- so dias que ja passaram. Base do Historico de observacao (previsao-ferias.php?acao=historico).
CREATE TABLE IF NOT EXISTS volante_ociosidade (
    matricula VARCHAR(20) NOT NULL,
    dia DATE NOT NULL,
    PRIMARY KEY (matricula, dia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
