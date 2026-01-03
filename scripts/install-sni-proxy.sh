#!/bin/bash
#
# Script de instalação do SNI Proxy para Database Provisioner
# Permite que clientes conectem nas portas padrão (5432, 3306, 6379)
# e sejam roteados para o container correto via SNI
#

set -e

echo "=============================================="
echo "  Instalação do SNI Proxy - Easyti Database"
echo "=============================================="

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

info() { echo -e "${GREEN}[INFO]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# Verificar se é root
if [ "$EUID" -ne 0 ]; then
    error "Este script precisa ser executado como root"
fi

# Variáveis
DOMAIN="easytidatabase.cloud"
EMAIL="admin@easyti.com.br"
NGINX_STREAM_DIR="/etc/nginx/stream.d"
NGINX_MAPS_DIR="/etc/nginx/stream-maps.d"
CERT_DIR="/etc/letsencrypt/live/${DOMAIN}"

info "Instalando dependências..."
apt update
apt install -y nginx certbot python3-certbot-dns-cloudflare

info "Verificando se Nginx tem módulo stream..."
if ! nginx -V 2>&1 | grep -q "with-stream"; then
    warn "Nginx não tem módulo stream. Instalando nginx-full..."
    apt install -y nginx-full
fi

info "Criando diretórios..."
mkdir -p "$NGINX_STREAM_DIR"
mkdir -p "$NGINX_MAPS_DIR"
mkdir -p /var/log/nginx/stream

info "Criando configuração principal do stream..."
cat > /etc/nginx/nginx.conf << 'NGINX_CONF'
user www-data;
worker_processes auto;
pid /run/nginx.pid;
include /etc/nginx/modules-enabled/*.conf;

events {
    worker_connections 4096;
    multi_accept on;
}

# Configuração HTTP (para API e certificados)
http {
    sendfile on;
    tcp_nopush on;
    types_hash_max_size 2048;
    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers on;

    access_log /var/log/nginx/access.log;
    error_log /var/log/nginx/error.log;

    gzip on;

    include /etc/nginx/conf.d/*.conf;
    include /etc/nginx/sites-enabled/*;
}

# Configuração Stream (para proxy TCP com SNI)
stream {
    log_format stream_log '$remote_addr [$time_local] '
                         '$protocol $status $bytes_sent $bytes_received '
                         '$session_time "$upstream_addr" '
                         '"$ssl_preread_server_name"';

    access_log /var/log/nginx/stream/access.log stream_log;
    error_log /var/log/nginx/stream/error.log;

    # Mapas de roteamento por SNI
    include /etc/nginx/stream-maps.d/*.conf;
    
    # Configurações dos listeners
    include /etc/nginx/stream.d/*.conf;
}
NGINX_CONF

info "Criando mapa padrão de roteamento..."
cat > "$NGINX_MAPS_DIR/default.conf" << 'EOF'
# Mapa SNI -> Backend para PostgreSQL
map $ssl_preread_server_name $postgres_backend {
    default 127.0.0.1:59999;  # Porta inexistente para rejeitar conexões desconhecidas
    # Entradas serão adicionadas automaticamente pelo provisioner
    # Exemplo: db123456.easytidatabase.cloud 127.0.0.1:15432;
}

# Mapa SNI -> Backend para MySQL
map $ssl_preread_server_name $mysql_backend {
    default 127.0.0.1:59999;
}

# Mapa SNI -> Backend para Redis
map $ssl_preread_server_name $redis_backend {
    default 127.0.0.1:59999;
}
EOF

info "Criando listeners de stream..."

# PostgreSQL Listener (porta 5432)
cat > "$NGINX_STREAM_DIR/postgres.conf" << 'EOF'
# PostgreSQL SNI Proxy
server {
    listen 5432;
    listen [::]:5432;
    
    proxy_pass $postgres_backend;
    proxy_connect_timeout 10s;
    proxy_timeout 300s;
    
    ssl_preread on;
}
EOF

# MySQL Listener (porta 3306)
cat > "$NGINX_STREAM_DIR/mysql.conf" << 'EOF'
# MySQL SNI Proxy
server {
    listen 3306;
    listen [::]:3306;
    
    proxy_pass $mysql_backend;
    proxy_connect_timeout 10s;
    proxy_timeout 300s;
    
    ssl_preread on;
}
EOF

# Redis Listener (porta 6379)
cat > "$NGINX_STREAM_DIR/redis.conf" << 'EOF'
# Redis SNI Proxy
server {
    listen 6379;
    listen [::]:6379;
    
    proxy_pass $redis_backend;
    proxy_connect_timeout 10s;
    proxy_timeout 300s;
    
    ssl_preread on;
}
EOF

info "Criando script de atualização de rotas..."
cat > /usr/local/bin/update-db-routes << 'SCRIPT'
#!/bin/bash
#
# Atualiza as rotas do SNI proxy
# Uso: update-db-routes <engine> <hostname> <port> [remove]
#

ENGINE="$1"
HOSTNAME="$2"
PORT="$3"
ACTION="${4:-add}"

MAPS_DIR="/etc/nginx/stream-maps.d"

case "$ENGINE" in
    postgres)
        MAP_FILE="$MAPS_DIR/postgres-routes.conf"
        ;;
    mysql)
        MAP_FILE="$MAPS_DIR/mysql-routes.conf"
        ;;
    redis)
        MAP_FILE="$MAPS_DIR/redis-routes.conf"
        ;;
    *)
        echo "Engine desconhecida: $ENGINE"
        exit 1
        ;;
esac

# Cria arquivo se não existir
touch "$MAP_FILE"

if [ "$ACTION" = "remove" ]; then
    # Remove entrada
    sed -i "/$HOSTNAME/d" "$MAP_FILE"
else
    # Remove entrada existente (se houver) e adiciona nova
    sed -i "/$HOSTNAME/d" "$MAP_FILE"
    echo "    $HOSTNAME 127.0.0.1:$PORT;" >> "$MAP_FILE"
fi

# Testa configuração do Nginx
nginx -t 2>/dev/null && nginx -s reload 2>/dev/null

echo "Rota ${ACTION}: $HOSTNAME -> 127.0.0.1:$PORT ($ENGINE)"
SCRIPT
chmod +x /usr/local/bin/update-db-routes

info "Criando arquivos de rotas por engine..."
cat > "$NGINX_MAPS_DIR/postgres-routes.conf" << 'EOF'
# Rotas PostgreSQL - Geradas automaticamente
# Formato: hostname 127.0.0.1:porta;
EOF

cat > "$NGINX_MAPS_DIR/mysql-routes.conf" << 'EOF'
# Rotas MySQL - Geradas automaticamente
# Formato: hostname 127.0.0.1:porta;
EOF

cat > "$NGINX_MAPS_DIR/redis-routes.conf" << 'EOF'
# Rotas Redis - Geradas automaticamente
# Formato: hostname 127.0.0.1:porta;
EOF

info "Atualizando mapa principal para incluir rotas..."
cat > "$NGINX_MAPS_DIR/default.conf" << 'EOF'
# Mapa SNI -> Backend para PostgreSQL
map $ssl_preread_server_name $postgres_backend {
    default 127.0.0.1:59999;
    include /etc/nginx/stream-maps.d/postgres-routes.conf;
}

# Mapa SNI -> Backend para MySQL
map $ssl_preread_server_name $mysql_backend {
    default 127.0.0.1:59999;
    include /etc/nginx/stream-maps.d/mysql-routes.conf;
}

# Mapa SNI -> Backend para Redis
map $ssl_preread_server_name $redis_backend {
    default 127.0.0.1:59999;
    include /etc/nginx/stream-maps.d/redis-routes.conf;
}
EOF

info "Abrindo portas no firewall..."
ufw allow 5432/tcp comment 'PostgreSQL SNI Proxy' 2>/dev/null || true
ufw allow 3306/tcp comment 'MySQL SNI Proxy' 2>/dev/null || true
ufw allow 6379/tcp comment 'Redis SNI Proxy' 2>/dev/null || true

info "Testando configuração do Nginx..."
nginx -t || error "Configuração do Nginx inválida!"

info "Reiniciando Nginx..."
systemctl restart nginx

echo ""
echo "=============================================="
echo -e "${GREEN}  SNI Proxy instalado com sucesso!${NC}"
echo "=============================================="
echo ""
echo "Próximos passos:"
echo "1. Configure o DNS wildcard *.${DOMAIN} para apontar para este servidor"
echo "2. Gere o certificado wildcard SSL (ver instruções abaixo)"
echo "3. Execute o script de migração para bancos existentes"
echo ""
echo "Para gerar certificado SSL wildcard (requer DNS Cloudflare):"
echo "  certbot certonly --dns-cloudflare --dns-cloudflare-credentials /root/.cloudflare.ini -d '*.${DOMAIN}' -d '${DOMAIN}'"
echo ""
echo "Ou com validação manual DNS:"
echo "  certbot certonly --manual --preferred-challenges dns -d '*.${DOMAIN}' -d '${DOMAIN}'"
echo ""
echo "Para testar:"
echo "  update-db-routes postgres db123456.easytidatabase.cloud 15432"
echo "  psql 'host=db123456.easytidatabase.cloud port=5432 sslmode=require'"
echo ""

