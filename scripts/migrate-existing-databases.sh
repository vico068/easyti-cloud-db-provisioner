#!/bin/bash
#
# Script para migrar bancos de dados existentes para o SNI Proxy
# Lê os bancos do banco de dados e cria as rotas no Nginx
#

set -e

echo "=============================================="
echo "  Migração de Bancos Existentes para SNI Proxy"
echo "=============================================="

# Cores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

info() { echo -e "${GREEN}[INFO]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }

# Diretório do provisioner
PROVISIONER_DIR="/opt/easyti-db-provisioner"

# Verificar se o script update-db-routes existe
if [ ! -f /usr/local/bin/update-db-routes ]; then
    echo "Erro: Script update-db-routes não encontrado. Execute install-sni-proxy.sh primeiro."
    exit 1
fi

cd "$PROVISIONER_DIR"

info "Buscando bancos de dados existentes..."

# Usa o artisan tinker para buscar os bancos
php artisan tinker --execute="
use App\Models\DatabaseInstance;

\$instances = DatabaseInstance::whereIn('status', ['running', 'active', 'stopped'])
    ->whereNotNull('host')
    ->whereNotNull('port')
    ->get();

echo \"Encontrados \" . \$instances->count() . \" bancos de dados\n\";

foreach (\$instances as \$db) {
    \$line = \$db->engine . '|' . \$db->host . '|' . \$db->port;
    echo \$line . \"\n\";
}
" 2>/dev/null | tail -n +2 > /tmp/db_list.txt

# Processa cada banco
count=0
while IFS='|' read -r engine host port; do
    if [ -n "$engine" ] && [ -n "$host" ] && [ -n "$port" ]; then
        info "Configurando rota: $host -> 127.0.0.1:$port ($engine)"
        /usr/local/bin/update-db-routes "$engine" "$host" "$port"
        ((count++))
    fi
done < /tmp/db_list.txt

rm -f /tmp/db_list.txt

echo ""
echo "=============================================="
echo -e "${GREEN}  Migração concluída!${NC}"
echo "=============================================="
echo ""
echo "Total de rotas configuradas: $count"
echo ""
echo "Para verificar as rotas configuradas:"
echo "  cat /etc/nginx/stream-maps.d/postgres-routes.conf"
echo "  cat /etc/nginx/stream-maps.d/mysql-routes.conf"
echo "  cat /etc/nginx/stream-maps.d/redis-routes.conf"
echo ""
echo "Para testar uma conexão:"
echo "  psql 'host=<hostname> port=5432 user=postgres sslmode=require'"
echo ""

