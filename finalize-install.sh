#!/bin/bash

#######################################################################
# Script de Finalização - Easyti Cloud Database Provisioner
# 
# Execute após as migrations estarem concluídas.
# Este script configura:
# - Permissões de arquivos
# - Cache do Laravel
# - Nginx como reverse proxy
# - SSL com Certbot (Let's Encrypt)
# - Supervisor para Horizon
#
# Uso: sudo ./finalize-install.sh
#######################################################################

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Configurações - ALTERE CONFORME NECESSÁRIO
APP_DIR="/opt/easyti-db-provisioner"
DOMAIN="provisioner.easytidatabase.cloud"
EMAIL="admin@easytidatabase.cloud"
APP_USER="www-data"

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
    echo -e "${BLUE}║${NC}  ${GREEN}Easyti Database Provisioner - Finalização da Instalação${NC}  ${BLUE}║${NC}"
    echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

# Instalar dependências necessárias
install_dependencies() {
    log_info "Verificando dependências..."
    
    apt-get update -y
    apt-get install -y nginx supervisor certbot python3-certbot-nginx
    
    log_success "Dependências instaladas"
}

# Cache do Laravel
configure_laravel_cache() {
    log_info "Configurando cache do Laravel..."
    
    cd ${APP_DIR}
    
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    
    log_success "Cache configurado"
}

# Ajustar permissões
fix_permissions() {
    log_info "Ajustando permissões..."
    
    chown -R ${APP_USER}:${APP_USER} ${APP_DIR}
    chmod -R 755 ${APP_DIR}
    chmod -R 775 ${APP_DIR}/storage
    chmod -R 775 ${APP_DIR}/bootstrap/cache
    
    # Garantir que o diretório de logs existe
    mkdir -p ${APP_DIR}/storage/logs
    touch ${APP_DIR}/storage/logs/horizon.log
    chown ${APP_USER}:${APP_USER} ${APP_DIR}/storage/logs/horizon.log
    
    log_success "Permissões ajustadas"
}

# Configurar Nginx
configure_nginx() {
    log_info "Configurando Nginx..."
    
    # Remover configuração default se existir
    rm -f /etc/nginx/sites-enabled/default
    
    # Criar configuração do site
    cat > /etc/nginx/sites-available/easyti-db-provisioner << EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root ${APP_DIR}/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header X-XSS-Protection "1; mode=block";

    index index.php;
    charset utf-8;

    # Logs
    access_log /var/log/nginx/easyti-db-provisioner.access.log;
    error_log /var/log/nginx/easyti-db-provisioner.error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php\$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Otimizações
    client_max_body_size 100M;
    
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_proxied expired no-cache no-store private auth;
    gzip_types text/plain text/css text/xml text/javascript application/x-javascript application/xml application/javascript application/json;
}
EOF
    
    # Habilitar site
    ln -sf /etc/nginx/sites-available/easyti-db-provisioner /etc/nginx/sites-enabled/
    
    # Testar e reiniciar
    nginx -t
    systemctl restart nginx
    systemctl enable nginx
    
    log_success "Nginx configurado"
}

# Configurar SSL com Certbot
configure_ssl() {
    log_info "Configurando SSL com Certbot..."
    
    # Verificar se o domínio resolve para este servidor
    log_warning "Certifique-se que o domínio ${DOMAIN} aponta para este servidor!"
    echo ""
    read -p "O DNS já está configurado e propagado? (s/N): " -n 1 -r
    echo ""
    
    if [[ $REPLY =~ ^[Ss]$ ]]; then
        log_info "Obtendo certificado SSL..."
        
        certbot --nginx \
            -d ${DOMAIN} \
            --non-interactive \
            --agree-tos \
            --email ${EMAIL} \
            --redirect
        
        # Configurar renovação automática
        systemctl enable certbot.timer
        systemctl start certbot.timer
        
        log_success "SSL configurado com sucesso!"
    else
        log_warning "SSL não configurado. Execute manualmente depois:"
        echo -e "${YELLOW}  sudo certbot --nginx -d ${DOMAIN}${NC}"
    fi
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
startsecs=0
EOF
    
    supervisorctl reread
    supervisorctl update
    supervisorctl start easyti-horizon 2>/dev/null || supervisorctl restart easyti-horizon
    
    log_success "Supervisor configurado"
}

# Criar script de atualização
create_update_script() {
    log_info "Criando script de atualização..."
    
    cat > /usr/local/bin/easyti-db-update << 'SCRIPT'
#!/bin/bash
set -e

APP_DIR="/opt/easyti-db-provisioner"

echo "🔄 Atualizando Easyti Database Provisioner..."

cd ${APP_DIR}

# Modo de manutenção
php artisan down --refresh=15

# Pull das atualizações
git pull origin main

# Atualizar dependências
composer install --no-dev --optimize-autoloader --no-interaction

# Migrations
php artisan migrate --force

# Limpar e recriar caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Reiniciar Horizon
supervisorctl restart easyti-horizon

# Sair do modo de manutenção
php artisan up

echo "✅ Atualização concluída!"
SCRIPT
    
    chmod +x /usr/local/bin/easyti-db-update
    
    log_success "Script de atualização criado: /usr/local/bin/easyti-db-update"
}

# Criar script de monitoramento
create_status_script() {
    log_info "Criando script de status..."
    
    cat > /usr/local/bin/easyti-db-status << 'SCRIPT'
#!/bin/bash

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║        Easyti Database Provisioner - Status                  ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

echo "📦 Serviços:"
echo "────────────────────────────────────────────────────────────────"

# Nginx
if systemctl is-active --quiet nginx; then
    echo "  ✅ Nginx: Rodando"
else
    echo "  ❌ Nginx: Parado"
fi

# PHP-FPM
if systemctl is-active --quiet php8.3-fpm; then
    echo "  ✅ PHP-FPM: Rodando"
else
    echo "  ❌ PHP-FPM: Parado"
fi

# PostgreSQL
if systemctl is-active --quiet postgresql; then
    echo "  ✅ PostgreSQL: Rodando"
else
    echo "  ❌ PostgreSQL: Parado"
fi

# Redis
if systemctl is-active --quiet redis-server; then
    echo "  ✅ Redis: Rodando"
else
    echo "  ❌ Redis: Parado"
fi

# Horizon
horizon_status=$(supervisorctl status easyti-horizon 2>/dev/null | awk '{print $2}')
if [ "$horizon_status" == "RUNNING" ]; then
    echo "  ✅ Horizon: Rodando"
else
    echo "  ❌ Horizon: $horizon_status"
fi

echo ""
echo "🐳 Docker Containers (Bancos de Dados):"
echo "────────────────────────────────────────────────────────────────"
docker ps --filter "name=db_" --format "  {{.Names}}: {{.Status}}" 2>/dev/null || echo "  Nenhum container rodando"

echo ""
echo "📊 Uso de Recursos:"
echo "────────────────────────────────────────────────────────────────"
echo "  Memória: $(free -h | awk '/^Mem:/ {print $3 "/" $2}')"
echo "  Disco:   $(df -h / | awk 'NR==2 {print $3 "/" $2 " (" $5 " usado)"}')"

echo ""
echo "🔗 URLs:"
echo "────────────────────────────────────────────────────────────────"
echo "  API:     https://provisioner.easytidatabase.cloud/api/health"
echo "  Horizon: https://provisioner.easytidatabase.cloud/horizon"
echo ""
SCRIPT
    
    chmod +x /usr/local/bin/easyti-db-status
    
    log_success "Script de status criado: /usr/local/bin/easyti-db-status"
}

# Configurar firewall
configure_firewall() {
    log_info "Configurando firewall..."
    
    # Verificar se ufw está instalado
    if command -v ufw &> /dev/null; then
        ufw allow 22/tcp    # SSH
        ufw allow 80/tcp    # HTTP
        ufw allow 443/tcp   # HTTPS
        
        # Portas dos bancos (range para containers)
        ufw allow 13306:13400/tcp  # MySQL containers
        ufw allow 15432:15500/tcp  # PostgreSQL containers
        ufw allow 16379:16400/tcp  # Redis containers
        
        ufw --force enable
        
        log_success "Firewall configurado"
    else
        log_warning "UFW não instalado. Configure o firewall manualmente."
    fi
}

# Testar instalação
test_installation() {
    log_info "Testando instalação..."
    
    echo ""
    
    # Testar API
    response=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/api/health 2>/dev/null || echo "000")
    
    if [ "$response" == "200" ]; then
        log_success "API respondendo corretamente (HTTP 200)"
        echo ""
        echo -e "${GREEN}Resposta da API:${NC}"
        curl -s http://localhost/api/health | python3 -m json.tool 2>/dev/null || curl -s http://localhost/api/health
    else
        log_warning "API retornou HTTP $response"
    fi
    
    echo ""
}

# Imprimir resumo final
print_summary() {
    local IP=$(hostname -I | awk '{print $1}')
    
    echo ""
    echo -e "${GREEN}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║${NC}              INSTALAÇÃO CONCLUÍDA COM SUCESSO!               ${GREEN}║${NC}"
    echo -e "${GREEN}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${BLUE}URLs:${NC}"
    echo "────────────────────────────────────────────────────────────────"
    echo -e "  API:      ${GREEN}https://${DOMAIN}/api${NC}"
    echo -e "  Health:   ${GREEN}https://${DOMAIN}/api/health${NC}"
    echo -e "  Horizon:  ${GREEN}https://${DOMAIN}/horizon${NC}"
    echo ""
    echo -e "${BLUE}IP do Servidor:${NC} ${GREEN}${IP}${NC}"
    echo ""
    echo -e "${BLUE}Comandos Úteis:${NC}"
    echo "────────────────────────────────────────────────────────────────"
    echo -e "  Ver status:             ${YELLOW}easyti-db-status${NC}"
    echo -e "  Atualizar aplicação:    ${YELLOW}easyti-db-update${NC}"
    echo -e "  Ver logs Horizon:       ${YELLOW}tail -f ${APP_DIR}/storage/logs/horizon.log${NC}"
    echo -e "  Ver logs Laravel:       ${YELLOW}tail -f ${APP_DIR}/storage/logs/laravel.log${NC}"
    echo -e "  Reiniciar Horizon:      ${YELLOW}supervisorctl restart easyti-horizon${NC}"
    echo ""
    echo -e "${BLUE}Docker:${NC}"
    echo "────────────────────────────────────────────────────────────────"
    echo -e "  Ver containers:         ${YELLOW}docker ps${NC}"
    echo -e "  Ver logs container:     ${YELLOW}docker logs db_<id>${NC}"
    echo ""
    echo -e "${YELLOW}IMPORTANTE:${NC}"
    echo "────────────────────────────────────────────────────────────────"
    echo "  1. Configure o DNS do domínio ${DOMAIN} para IP: ${IP}"
    echo "  2. Adicione no painel principal:"
    echo "     DATABASE_PROVISIONER_URL=https://${DOMAIN}"
    echo ""
}

# Função principal
main() {
    print_banner
    check_root
    
    log_info "Iniciando finalização da instalação..."
    echo ""
    
    install_dependencies
    fix_permissions
    configure_laravel_cache
    configure_nginx
    configure_supervisor
    create_update_script
    create_status_script
    configure_firewall
    configure_ssl
    test_installation
    
    print_summary
}

# Executar
main "$@"

