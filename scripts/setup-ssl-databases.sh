#!/bin/bash
#
# Script para configurar SSL nos containers de banco de dados
# Permite que o SNI Proxy funcione corretamente
#

set -e

echo "=============================================="
echo "  Configuração SSL para Containers de Banco"
echo "=============================================="

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

info() { echo -e "${GREEN}[INFO]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# Verificar root
if [ "$EUID" -ne 0 ]; then
    error "Este script precisa ser executado como root"
fi

DOMAIN="easytidatabase.cloud"
CERT_DIR="/opt/easyti-db-certs"
PROVISIONER_DIR="/opt/easyti-db-provisioner"

info "Criando diretório de certificados..."
mkdir -p "$CERT_DIR"

# Verificar se já tem certificado wildcard do Let's Encrypt
if [ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
    info "Usando certificado existente do Let's Encrypt..."
    cp "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" "$CERT_DIR/server.crt"
    cp "/etc/letsencrypt/live/$DOMAIN/privkey.pem" "$CERT_DIR/server.key"
else
    info "Gerando certificado wildcard auto-assinado..."
    
    # Criar configuração do OpenSSL
    cat > /tmp/ssl.conf << EOF
[req]
default_bits = 2048
prompt = no
default_md = sha256
distinguished_name = dn
req_extensions = req_ext
x509_extensions = v3_ca

[dn]
C = BR
ST = Estado
L = Cidade
O = Easyti Cloud
OU = Database Services
CN = *.$DOMAIN

[req_ext]
subjectAltName = @alt_names

[v3_ca]
subjectAltName = @alt_names
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth

[alt_names]
DNS.1 = *.$DOMAIN
DNS.2 = $DOMAIN
EOF

    # Gerar certificado
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout "$CERT_DIR/server.key" \
        -out "$CERT_DIR/server.crt" \
        -config /tmp/ssl.conf

    rm /tmp/ssl.conf
fi

# Ajustar permissões
chmod 644 "$CERT_DIR/server.crt"
chmod 600 "$CERT_DIR/server.key"

info "Criando configuração SSL para PostgreSQL..."
cat > "$CERT_DIR/postgresql-ssl.conf" << 'EOF'
ssl = on
ssl_cert_file = '/var/lib/postgresql/ssl/server.crt'
ssl_key_file = '/var/lib/postgresql/ssl/server.key'
EOF

info "Criando script de inicialização PostgreSQL com SSL..."
cat > "$CERT_DIR/postgres-ssl-entrypoint.sh" << 'EOF'
#!/bin/bash
set -e

# Copiar certificados e ajustar permissões
cp /ssl-certs/server.crt /var/lib/postgresql/ssl/
cp /ssl-certs/server.key /var/lib/postgresql/ssl/
chown postgres:postgres /var/lib/postgresql/ssl/*
chmod 600 /var/lib/postgresql/ssl/server.key
chmod 644 /var/lib/postgresql/ssl/server.crt

# Executar entrypoint original
exec docker-entrypoint.sh "$@"
EOF
chmod +x "$CERT_DIR/postgres-ssl-entrypoint.sh"

info "Criando configuração SSL para MySQL..."
cat > "$CERT_DIR/mysql-ssl.cnf" << 'EOF'
[mysqld]
ssl-ca=/etc/mysql/ssl/server.crt
ssl-cert=/etc/mysql/ssl/server.crt
ssl-key=/etc/mysql/ssl/server.key
require_secure_transport=ON
EOF

info "Criando configuração SSL para Redis..."
cat > "$CERT_DIR/redis-ssl.conf" << 'EOF'
tls-port 6379
port 0
tls-cert-file /etc/redis/ssl/server.crt
tls-key-file /etc/redis/ssl/server.key
tls-auth-clients no
EOF

info "Criando Dockerfile customizado para PostgreSQL com SSL..."
mkdir -p "$CERT_DIR/docker"

cat > "$CERT_DIR/docker/Dockerfile.postgres-ssl" << 'EOF'
FROM postgres:16-alpine

# Criar diretório SSL
RUN mkdir -p /var/lib/postgresql/ssl && \
    chown postgres:postgres /var/lib/postgresql/ssl

# Copiar script de entrada
COPY postgres-ssl-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/postgres-ssl-entrypoint.sh

ENTRYPOINT ["postgres-ssl-entrypoint.sh"]
CMD ["postgres", "-c", "ssl=on", "-c", "ssl_cert_file=/var/lib/postgresql/ssl/server.crt", "-c", "ssl_key_file=/var/lib/postgresql/ssl/server.key"]
EOF

cp "$CERT_DIR/postgres-ssl-entrypoint.sh" "$CERT_DIR/docker/"

info "Construindo imagem PostgreSQL com SSL..."
cd "$CERT_DIR/docker"
docker build -t postgres-ssl:16-alpine -f Dockerfile.postgres-ssl .

info "Criando rede Docker se não existir..."
docker network create easytidb_net 2>/dev/null || true

echo ""
echo "=============================================="
echo -e "${GREEN}  SSL configurado com sucesso!${NC}"
echo "=============================================="
echo ""
echo "Certificados em: $CERT_DIR"
echo "Imagem Docker: postgres-ssl:16-alpine"
echo ""
echo "Próximo passo: Atualizar DockerService para usar SSL"
echo ""
echo "Para testar conexão SSL:"
echo "  psql 'host=db123.easytidatabase.cloud port=5432 sslmode=require'"
echo ""

# Informar se é auto-assinado
if [ ! -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
    warn "ATENÇÃO: Usando certificado auto-assinado!"
    warn "Para produção, gere um certificado Let's Encrypt:"
    echo ""
    echo "  certbot certonly --manual --preferred-challenges dns -d '*.$DOMAIN' -d '$DOMAIN'"
    echo ""
    echo "Depois execute este script novamente para usar o certificado válido."
fi

