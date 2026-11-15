# Estagio so para MINIFICAR o JS compartilhado: os comentarios descrevem regra interna e
# integracao com o Protheus, que nao deve ir ao navegador. O container recebe a versao minificada
# com o MESMO nome (o ?v= do HTML continua valendo).
FROM node:20-alpine AS jsbuild
WORKDIR /build
COPY backend/filtro.js backend/hostinger-storage.js fopag/pfm.js ./src/
RUN npm install -g esbuild@0.24.2 \
    && mkdir -p ./dist \
    && for f in ./src/*.js; do esbuild "$f" --minify --outfile="./dist/$(basename "$f")"; done

FROM php:8.2-apache

# pdo_mysql: backend/api/db.php conecta no MariaDB.
# curl: chamadas HTTP a servicos externos (directory USC, etc.).
# mbstring: db.php (mb_substr) e login.php (mb_strlen).
# pdo_dblib: SQL Server do Protheus via FreeTDS (a conta de servico so autentica por NTLM,
# "DOMINIO\usuario").
# ldap: autenticarViaLDAP (db.php) - bind no AD/UCS com a senha do usuario.
# headers: cabecalhos de seguranca do .htaccess.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev freetds-dev libldap2-dev \
    && docker-php-ext-install pdo_mysql curl mbstring pdo_dblib ldap \
    && a2enmod headers \
    && rm -rf /var/lib/apt/lists/*

# .htaccess so tem efeito com AllowOverride All.
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# CA raiz do UCS (Univention): o UCS recusa bind sem TLS, entao o login e via ldaps://.
COPY docker/ucs-root-ca.crt /usr/local/share/ca-certificates/ucs-root-ca.crt
RUN update-ca-certificates

# A libldap nao le o store do sistema: sem TLS_CACERT o ldaps falha mesmo com a CA instalada.
RUN mkdir -p /etc/ldap \
    && printf 'TLS_CACERT /usr/local/share/ca-certificates/ucs-root-ca.crt\n' > /etc/ldap/ldap.conf

# php.ini de producao (display_errors desligado - erro nao vaza caminho de
# arquivo numa resposta JSON) e sem o cabecalho X-Powered-By.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "expose_php = Off" >> "$PHP_INI_DIR/php.ini"

# Cabecalho Server e pagina de erro do Apache sem versao/SO. Prefixo "zz-": o
# conf-enabled/security.conf da imagem tambem define ServerTokens e vale o ultimo carregado
# (ordem alfabetica).
RUN printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/zz-cgv-security.conf \
    && a2enconf zz-cgv-security

WORKDIR /var/www/html
COPY . .
COPY --from=jsbuild /build/dist/filtro.js /build/dist/hostinger-storage.js ./backend/
COPY --from=jsbuild /build/dist/pfm.js ./fopag/

# Mesmo teste do healthcheck do stack.yml/docker-compose.yml.
HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=15s \
    CMD php -r "exit(@file_get_contents('http://localhost/index.html') === false ? 1 : 0);"
