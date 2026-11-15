#!/bin/sh
# Backup diario do banco do CGV (mariadb-dump), no servico "backup" (docker-compose.yml local e
# stack.yml do servidor; imagem mariadb:11, que ja traz o mariadb-dump).
#
# Grava em /backups (volume cgv_db_backups no servidor, ./backups no local), um arquivo por
# rodada, e apaga os com mais de BACKUP_RETENCAO_DIAS (padrao 14).
#
# LIMITACAO: protege contra erro humano/bug, NAO contra perda do disco do servidor (o backup
# fica no mesmo disco). Copia para fora do servidor e decisao de infraestrutura.
set -eu

RETENCAO_DIAS="${BACKUP_RETENCAO_DIAS:-14}"
DESTINO=/backups

# Resultado de cada rodada em sync_status (chave 'backup'): o aviso do login do RH/Administrador
# (backend/api/saude-sync.php) mostra a ultima falha, com o motivo, ou mais de 72h sem sucesso.
# Gravar o status nunca derruba o backup (|| true): banco fora do ar ou tabela ainda sem migracao
# so deixam de registrar - ai valem o aviso de 72h sem sucesso e o healthcheck do servico.
registrar_status() {
  MYSQL_PWD="${DB_PASS:-}" mariadb --host="${DB_HOST:-}" --port="${DB_PORT:-3306}" --user="${DB_USER:-}" \
    --default-character-set=utf8mb4 --connect-timeout=10 -e "$1" "${DB_NAME:-}" >/dev/null 2>&1 || true
}
# $1 = motivo em texto fixo, SEM aspas simples (vai direto no SQL). Grava a falha e sai com erro.
falhou() {
  echo "[backup] $(date '+%Y-%m-%d %H:%M:%S') FALHOU - $1" >&2
  registrar_status "INSERT INTO sync_status (chave, ultimo_erro_em, mensagem_erro) VALUES ('backup', NOW(), '$1')
    ON DUPLICATE KEY UPDATE ultimo_erro_em = NOW(), mensagem_erro = VALUES(mensagem_erro)"
  exit 1
}

# No Swarm a senha chega como arquivo (/run/secrets/...); tr tira a quebra de linha.
if [ -n "${DB_PASS_FILE:-}" ]; then
    if [ ! -r "$DB_PASS_FILE" ]; then
        echo "[backup] DB_PASS_FILE='$DB_PASS_FILE' nao e legivel." >&2
        falhou "O arquivo com a senha do banco (DB_PASS_FILE) não pôde ser lido."
    fi
    DB_PASS=$(tr -d '\r\n' < "$DB_PASS_FILE")
fi

ARQUIVO="$DESTINO/cgv_backup_$(date +%Y%m%d_%H%M%S).sql.gz"
mkdir -p "$DESTINO" || falhou "Não foi possível criar a pasta de backup ($DESTINO)."

# Espera o banco aceitar conexao: no Swarm o DNS da rede overlay pode ainda nao ter o nome "db".
TENTATIVA=1
while [ "$TENTATIVA" -le 30 ]; do
  if MYSQL_PWD="$DB_PASS" mariadb --host="$DB_HOST" --port="$DB_PORT" \
      --user="$DB_USER" -e "SELECT 1" "$DB_NAME" >/dev/null 2>&1; then
    break
  fi
  echo "[backup] aguardando o banco ($TENTATIVA/30)..." >&2
  TENTATIVA=$((TENTATIVA + 1))
  sleep 5
done

echo "[backup] $(date '+%Y-%m-%d %H:%M:%S') iniciando dump de '$DB_NAME'..." >&2

# Dump vai para arquivo temporario ANTES de comprimir: num pipeline o status e o do gzip,
# que tem sucesso ate com entrada vazia. MYSQL_PWD em vez de --password= para a senha nao
# aparecer no "ps".
TEMP="$ARQUIVO.tmp"
if ! MYSQL_PWD="$DB_PASS" mariadb-dump \
    --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
    --single-transaction --routines --triggers "$DB_NAME" > "$TEMP"; then
  rm -f "$TEMP"
  falhou "O mariadb-dump terminou com erro (banco fora do ar, senha errada ou sem espaço em disco). Detalhe no log do container backup."
fi

# Dump pode sair com codigo 0 e estar truncado (disco cheio, conexao
# cortada): o marcador final so existe em dump completo.
if ! tail -c 200 "$TEMP" | grep -q "Dump completed"; then
  rm -f "$TEMP"
  falhou "O dump saiu incompleto, sem o marcador final (sem espaço em disco ou conexão cortada no meio)."
fi

if ! gzip -c "$TEMP" > "$ARQUIVO"; then
  rm -f "$TEMP" "$ARQUIVO"
  falhou "Erro ao comprimir o arquivo de backup."
fi
rm -f "$TEMP"

TAMANHO=$(du -h "$ARQUIVO" | cut -f1)
echo "[backup] $(date '+%Y-%m-%d %H:%M:%S') OK - $ARQUIVO ($TAMANHO)." >&2
registrar_status "INSERT INTO sync_status (chave, executado_em) VALUES ('backup', NOW())
  ON DUPLICATE KEY UPDATE executado_em = NOW()"

REMOVIDOS=$(find "$DESTINO" -name 'cgv_backup_*.sql.gz' -mtime "+$RETENCAO_DIAS" -print -delete | wc -l)
if [ "$REMOVIDOS" -gt 0 ]; then
  echo "[backup] $(date '+%Y-%m-%d %H:%M:%S') $REMOVIDOS backup(s) com mais de ${RETENCAO_DIAS} dias removido(s)." >&2
fi
