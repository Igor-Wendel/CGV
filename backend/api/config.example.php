<?php
// ============================================================
// CONFIG LOCAL (MODELO) - so para rodar FORA de producao
// ============================================================
// Em producao (Docker Swarm, stack.yml) este arquivo NAO e usado: com
// DB_HOST no ambiente, backend/api/db.php le TUDO das variaveis de ambiente
// e dos Docker secrets (<NOME>_FILE, ex: DB_PASS_FILE=/run/secrets/...),
// com os mesmos nomes das constantes abaixo. Sem DB_HOST no ambiente, db.php
// carrega config.local.php (se existir) ou config.php - copie este arquivo
// para um deles e preencha. Constante ausente aqui cai no padrao definido em
// db.php (vazio = integracao nao configurada), entao nada quebra.
//
// CORS: tratado em db.php (fechado por padrao). Para testar paginas de outra origem (ex: Live
// Server em http://127.0.0.1:5500), defina CGV_ALLOWED_ORIGIN com essa origem exata.
// define('CGV_ALLOWED_ORIGIN', 'http://127.0.0.1:5500');

// Banco MySQL/MariaDB do CGV.
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'TROQUE-PELO-NOME-DO-BANCO');
define('DB_USER', 'TROQUE-PELO-USUARIO-DO-BANCO');
define('DB_PASS', 'TROQUE-PELA-SENHA-DO-BANCO');

// SQL Server do Protheus, acesso direto via FreeTDS/pdo_dblib - usado pelos scripts CLI
// backend/scripts/sync-protheus-colaboradores.php e sync-protheus-centros-custo.php. Conta de
// servico de dominio (NTLM) - "DOMINIO\usuario", nao SQL Auth puro (ver conectarProtheusDb() em db.php).
define('PROTHEUS_DB_HOST', getenv('PROTHEUS_DB_HOST') ?: '');
define('PROTHEUS_DB_PORT', getenv('PROTHEUS_DB_PORT') ?: '1433');
define('PROTHEUS_DB_NAME', getenv('PROTHEUS_DB_NAME') ?: '');
define('PROTHEUS_DB_DOMAIN', getenv('PROTHEUS_DB_DOMAIN') ?: '');
define('PROTHEUS_DB_USER', getenv('PROTHEUS_DB_USER') ?: '');
define('PROTHEUS_DB_PASSWORD', getenv('PROTHEUS_DB_PASSWORD') ?: '');

// Cobertura de FERIAS do SAF usada pelo PFM (pfm-calculo.php): caminho do JSON que o SAF exporta
// a cada 3 min. Vazio, ausente, invalido ou com mais de 24h = o PFM calcula sem as ferias e
// mostra um aviso na tela.
define('SAF_COBERTURA_JSON', getenv('SAF_COBERTURA_JSON') ?: '');

// Lista de usuarios corporativos da pagina Usuarios ("+ Novo usuario do
// diretorio") - a senha passa a ser gerenciada pelo AD da empresa, nunca
// guardada aqui. Duas fontes, nessa prioridade:
// 1) API do directory USC (USC_API_URL): GET devolvendo {"dados":
//    [{"usuario": "...", "nome": "..."}, ...]} (array direto tambem aceito).
//    USC_API_TOKEN (opcional) vai como "Authorization: Bearer <token>".
// 2) Sem USC_API_URL: o proprio AD por LDAP, com conta de servico (LDAP_BIND_DN/LDAP_BIND_PASSWORD).
// Nenhuma das duas = pagina avisa "nao configurada"; criar usuario local com
// senha propria continua funcionando normalmente.
define('USC_API_URL', getenv('USC_API_URL') ?: ''); // ex: 'https://usc.suaempresa.com.br/api/usuarios'
define('USC_API_TOKEN', getenv('USC_API_TOKEN') ?: '');

// Autenticacao (LDAP bind) das contas do diretorio (senha_hash=''):
// login.php faz ldap_bind(usuario@LDAP_DOMAIN, senha) contra o AD -
// nenhuma senha e guardada no CGV. LDAP_HOST aceita host simples
// (LDAP_PORT, sem criptografia) ou URI "ldaps://..." (TLS, ignora
// LDAP_PORT). Vazio = login de conta do diretorio fica bloqueado; contas
// locais (senha propria) continuam funcionando normalmente.
define('LDAP_HOST', getenv('LDAP_HOST') ?: ''); // ex: 'ldaps://dc01.suaempresa.local'
define('LDAP_PORT', getenv('LDAP_PORT') ?: '389');
define('LDAP_DOMAIN', getenv('LDAP_DOMAIN') ?: ''); // ex: 'suaempresa.local' - forma "usuario@dominio" (UPN)
// Conta de servico que LE o diretorio (so para listar usuarios): UPN
// ("svc-cgv") ou DN completo. LDAP_BASE_DN vazio = derivado de LDAP_DOMAIN;
// LDAP_FILTRO vazio = usuarios ativos do AD (padrao em db.php).
define('LDAP_BIND_DN', getenv('LDAP_BIND_DN') ?: '');
define('LDAP_BIND_PASSWORD', getenv('LDAP_BIND_PASSWORD') ?: '');
define('LDAP_BASE_DN', getenv('LDAP_BASE_DN') ?: '');

// SMTP do botao de e-mail de beneficios/bene-intermitente.html - ver
// backend/api/enviar-email-intermitente.php. Porta 465 = SSL implicito
// (SMTPS); 587 = STARTTLS.
// SMTP_USER/SMTP_PASSWORD sao a conta que ENVIA o e-mail - vazio = botao
// mostra erro ao clicar, nada mais quebra.
define('SMTP_HOST', getenv('SMTP_HOST') ?: '');
define('SMTP_PORT', getenv('SMTP_PORT') ?: '465');
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: '');
define('SMTP_FROM_NOME', getenv('SMTP_FROM_NOME') ?: 'CGV - Controle Geral de Volantes');
