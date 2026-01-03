#!/bin/bash

#######################################################################
# Script de Instalação - Easyti Cloud Database Provisioner
# 
# Este script instala e configura todo o ambiente necessário:
# - Docker e Docker Compose
# - PHP 8.3 com extensões necessárias
# - PostgreSQL 16
# - Redis
# - Composer
# - A aplicação Laravel com Horizon
#
# Uso: sudo ./install.sh
#######################################################################

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configurações
APP_DIR="/opt/easyti-db-provisioner"
APP_USER="easyti"
DB_NAME="database_provisioner"
DB_USER="easyti_provisioner"
DB_PASS=$(openssl rand -base64 32 | tr -dc 'a-zA-Z0-9' | head -c 32)
APP_KEY=""
DOMAIN="easytidatabase.cloud"

# Funções de log
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[OK]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[AVISO]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERRO]${NC} $1"
}

# Verificar se é root
check_root() {
    if [ "$EUID" -ne 0 ]; then
        log_error "Este script precisa ser executado como root (sudo)"
        exit 1
    fi
}

# Banner
print_banner() {
    echo ""
    echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║${NC}     ${GREEN}Easyti Cloud Database Provisioner - Instalador${NC}          ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}                    Versão 1.0.0                              ${BLUE}║${NC}"
    echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

# Atualizar sistema
update_system() {
    log_info "Atualizando sistema..."
    apt-get update -y
    apt-get upgrade -y
    log_success "Sistema atualizado"
}

# Instalar dependências básicas
install_dependencies() {
    log_info "Instalando dependências básicas..."
    apt-get install -y \
        curl \
        wget \
        git \
        unzip \
        software-properties-common \
        apt-transport-https \
        ca-certificates \
        gnupg \
        lsb-release \
        supervisor \
        nginx \
        certbot \
        python3-certbot-nginx
    log_success "Dependências básicas instaladas"
}

# Instalar Docker
install_docker() {
    log_info "Instalando Docker..."
    
    # Remover versões antigas
    apt-get remove -y docker docker-engine docker.io containerd runc 2>/dev/null || true
    
    # Adicionar chave GPG oficial do Docker
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg
    
    # Adicionar repositório
    echo \
        "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
        $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
        tee /etc/apt/sources.list.d/docker.list > /dev/null
    
    # Instalar Docker
    apt-get update -y
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    
    # Iniciar e habilitar Docker
    systemctl start docker
    systemctl enable docker
    
    # Criar network para bancos de dados
    docker network create easytidb_net 2>/dev/null || true
    
    log_success "Docker instalado e configurado"
}

# Instalar PHP 8.3
install_php() {
    log_info "Instalando PHP 8.3..."
    
    # Adicionar repositório PPA
    add-apt-repository ppa:ondrej/php -y
    apt-get update -y
    
    # Instalar PHP e extensões
    apt-get install -y \
        php8.3 \
        php8.3-fpm \
        php8.3-cli \
        php8.3-common \
        php8.3-pgsql \
        php8.3-mysql \
        php8.3-redis \
        php8.3-curl \
        php8.3-mbstring \
        php8.3-xml \
        php8.3-zip \
        php8.3-bcmath \
        php8.3-intl \
        php8.3-gd \
        php8.3-opcache
    
    # Configurar PHP-FPM
    sed -i 's/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/' /etc/php/8.3/fpm/php.ini
    
    systemctl restart php8.3-fpm
    systemctl enable php8.3-fpm
    
    log_success "PHP 8.3 instalado"
}

# Instalar Composer
install_composer() {
    log_info "Instalando Composer..."
    
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    
    log_success "Composer instalado"
}

# Instalar PostgreSQL
install_postgresql() {
    log_info "Instalando PostgreSQL 16..."
    
    # Adicionar repositório PostgreSQL
    sh -c 'echo "deb http://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list'
    wget --quiet -O - https://www.postgresql.org/media/keys/ACCC4CF8.asc | apt-key add -
    
    apt-get update -y
    apt-get install -y postgresql-16 postgresql-client-16
    
    # Iniciar e habilitar
    systemctl start postgresql
    systemctl enable postgresql
    
    # Criar usuário e banco de dados
    log_info "Configurando banco de dados..."
    sudo -u postgres psql -c "CREATE USER ${DB_USER} WITH PASSWORD '${DB_PASS}';" 2>/dev/null || true
    sudo -u postgres psql -c "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};" 2>/dev/null || true
    sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME} TO ${DB_USER};" 2>/dev/null || true
    
    log_success "PostgreSQL instalado e configurado"
}

# Instalar Redis
install_redis() {
    log_info "Instalando Redis..."
    
    apt-get install -y redis-server
    
    # Configurar Redis
    sed -i 's/supervised no/supervised systemd/' /etc/redis/redis.conf
    
    systemctl restart redis-server
    systemctl enable redis-server
    
    log_success "Redis instalado e configurado"
}

# Criar usuário do sistema
create_user() {
    log_info "Criando usuário do sistema..."
    
    if ! id -u ${APP_USER} > /dev/null 2>&1; then
        useradd -m -s /bin/bash ${APP_USER}
        usermod -aG docker ${APP_USER}
        usermod -aG www-data ${APP_USER}
    fi
    
    log_success "Usuário ${APP_USER} criado"
}

# Instalar aplicação
install_application() {
    log_info "Instalando aplicação..."
    
    # Criar diretório
    mkdir -p ${APP_DIR}
    
    # Clonar repositório
    if [ -d "${APP_DIR}/.git" ]; then
        log_info "Atualizando repositório existente..."
        cd ${APP_DIR}
        git pull origin main
    else
        log_info "Clonando repositório..."
        git clone git@github.com:vico068/easyti-cloud-db-provisioner.git ${APP_DIR}
    fi
    
    cd ${APP_DIR}
    
    # Instalar dependências
    log_info "Instalando dependências PHP..."
    composer install --no-dev --optimize-autoloader --no-interaction
    
    # Gerar chave da aplicação
    APP_KEY=$(php artisan key:generate --show)
    
    # Criar arquivo .env
    log_info "Configurando ambiente..."
    cat > ${APP_DIR}/.env << EOF
APP_NAME="Easyti Database Provisioner"
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_TIMEZONE=America/Sao_Paulo
APP_URL=https://provisioner.${DOMAIN}

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_CLIENT=predis

BROADCAST_CONNECTION=log
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

HORIZON_PREFIX=easyti-db-provisioner-horizon:

DB_DOMAIN=${DOMAIN}
DOCKER_HOST_IP=$(hostname -I | awk '{print $1}')
EOF
    
    # Executar migrations
    log_info "Executando migrations..."
    php artisan migrate --force
    
    # Cache de configurações
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    
    # Ajustar permissões
    chown -R ${APP_USER}:www-data ${APP_DIR}
    chmod -R 755 ${APP_DIR}
    chmod -R 775 ${APP_DIR}/storage
    chmod -R 775 ${APP_DIR}/bootstrap/cache
    
    log_success "Aplicação instalada"
}

# Configurar Nginx
configure_nginx() {
    log_info "Configurando Nginx..."
    
    cat > /etc/nginx/sites-available/easyti-db-provisioner << EOF
server {
    listen 80;
    listen [::]:80;
    server_name provisioner.${DOMAIN};
    root ${APP_DIR}/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF
    
    # Habilitar site
    ln -sf /etc/nginx/sites-available/easyti-db-provisioner /etc/nginx/sites-enabled/
    
    # Testar configuração
    nginx -t
    
    systemctl restart nginx
    
    log_success "Nginx configurado"
}

# Configurar Supervisor para Horizon
configure_supervisor() {
    log_info "Configurando Supervisor para Horizon..."
    
    cat > /etc/supervisor/conf.d/easyti-horizon.conf << EOF
[program:easyti-horizon]
process_name=%(program_name)s
command=php ${APP_DIR}/artisan horizon
autostart=true
autorestart=true
user=${APP_USER}
redirect_stderr=true
stdout_logfile=${APP_DIR}/storage/logs/horizon.log
stopwaitsecs=3600
EOF
    
    supervisorctl reread
    supervisorctl update
    supervisorctl start easyti-horizon
    
    log_success "Supervisor configurado"
}

# Configurar SSL (opcional)
configure_ssl() {
    log_info "Configurando SSL com Certbot..."
    
    read -p "Deseja configurar SSL agora? (s/N): " -n 1 -r
    echo
    
    if [[ $REPLY =~ ^[Ss]$ ]]; then
        certbot --nginx -d provisioner.${DOMAIN} --non-interactive --agree-tos --email admin@${DOMAIN}
        log_success "SSL configurado"
    else
        log_warning "SSL não configurado. Execute 'certbot --nginx' posteriormente."
    fi
}

# Criar script de atualização
create_update_script() {
    log_info "Criando script de atualização..."
    
    cat > /usr/local/bin/easyti-db-update << 'EOF'
#!/bin/bash
set -e

APP_DIR="/opt/easyti-db-provisioner"

echo "Atualizando Easyti Database Provisioner..."

cd ${APP_DIR}

# Modo de manutenção
php artisan down

# Pull das atualizações
git pull origin main

# Atualizar dependências
composer install --no-dev --optimize-autoloader --no-interaction

# Migrations
php artisan migrate --force

# Limpar caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Reiniciar Horizon
supervisorctl restart easyti-horizon

# Sair do modo de manutenção
php artisan up

echo "Atualização concluída!"
EOF
    
    chmod +x /usr/local/bin/easyti-db-update
    
    log_success "Script de atualização criado em /usr/local/bin/easyti-db-update"
}

# Imprimir informações finais
print_summary() {
    echo ""
    echo -e "${GREEN}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║${NC}              INSTALAÇÃO CONCLUÍDA COM SUCESSO!               ${GREEN}║${NC}"
    echo -e "${GREEN}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${BLUE}Informações do Sistema:${NC}"
    echo "────────────────────────────────────────────────────────────────"
    echo -e "  Diretório da aplicação: ${GREEN}${APP_DIR}${NC}"
    echo -e "  Usuário do sistema:     ${GREEN}${APP_USER}${NC}"
    echo ""
    echo -e "${BLUE}Banco de Dados:${NC}"
    echo "────────────────────────────────────────────────────────────────"
    echo -e "  Host:     ${GREEN}127.0.0.1${NC}"
    echo -e "  Porta:    ${GREEN}5432${NC}"
    echo -e "  Database: ${GREEN}${DB_NAME}${NC}"
    echo -e "  Usuário:  ${GREEN}${DB_USER}${NC}"
    echo -e "  Senha:    ${GREEN}${DB_PASS}${NC}"
    echo ""
    echo -e "${BLUE}URLs:${NC}"
    echo "────────────────────────────────────────────────────────────────"
    echo -e "  API:      ${GREEN}http://provisioner.${DOMAIN}/api${NC}"
    echo -e "  Horizon:  ${GREEN}http://provisioner.${DOMAIN}/horizon${NC}"
    echo ""
    echo -e "${BLUE}Comandos Úteis:${NC}"
    echo "────────────────────────────────────────────────────────────────"
    echo -e "  Atualizar aplicação:    ${YELLOW}easyti-db-update${NC}"
    echo -e "  Ver logs Horizon:       ${YELLOW}tail -f ${APP_DIR}/storage/logs/horizon.log${NC}"
    echo -e "  Status Horizon:         ${YELLOW}supervisorctl status easyti-horizon${NC}"
    echo -e "  Reiniciar Horizon:      ${YELLOW}supervisorctl restart easyti-horizon${NC}"
    echo ""
    echo -e "${YELLOW}IMPORTANTE: Guarde as credenciais do banco de dados!${NC}"
    echo ""
}

# Função principal
main() {
    print_banner
    check_root
    
    log_info "Iniciando instalação..."
    echo ""
    
    update_system
    install_dependencies
    install_docker
    install_php
    install_composer
    install_postgresql
    install_redis
    create_user
    install_application
    configure_nginx
    configure_supervisor
    create_update_script
    configure_ssl
    
    print_summary
}

# Executar
main "$@"

