# Database Provisioner - Easyti Cloud

Sistema de provisionamento de bancos de dados em containers Docker.

## Requisitos

- PHP 8.2+
- PostgreSQL 15+
- Redis 6+
- Docker
- Composer

## Instalação

```bash
# Clone o repositório
cd /home/vinicius/database-provisioner

# Instale dependências
composer install

# Copie o arquivo de ambiente
cp .env.example .env

# Gere a chave da aplicação
php artisan key:generate

# Configure o banco de dados no .env
# Execute as migrations
php artisan migrate

# Inicie o Horizon para processar filas
php artisan horizon
```

## Variáveis de Ambiente

| Variável | Descrição | Exemplo |
|----------|-----------|---------|
| `DB_CONNECTION` | Driver do banco | `pgsql` |
| `DB_HOST` | Host do banco | `127.0.0.1` |
| `DB_DATABASE` | Nome do banco | `database_provisioner` |
| `REDIS_HOST` | Host do Redis | `127.0.0.1` |
| `REDIS_PORT` | Porta do Redis | `6379` |
| `QUEUE_CONNECTION` | Driver de fila | `redis` |
| `API_KEY` | Chave de API | `sua-chave-segura` |
| `DB_DOMAIN` | Domínio dos DBs | `easytidatabase.cloud` |
| `DOCKER_HOST_IP` | IP do host Docker | `147.78.120.1` |

## Endpoints da API

### Criar Banco de Dados

```bash
POST /api/databases
Content-Type: application/json

{
    "engine": "postgres",  # postgres, mysql, redis
    "user_id": 1,
    "slot_id": 123,        # opcional
    "config": {
        "vcpu": 1,
        "ram_mb": 512,
        "disk_gb": 10
    },
    "callback_url": "https://panel.easyti.cloud/api/database-callback"  # opcional
}
```

**Resposta:**
```json
{
    "message": "Solicitação de banco de dados recebida",
    "request_id": "uuid-da-requisicao",
    "status": "pending"
}
```

### Consultar Status da Requisição

```bash
GET /api/requests/{request_id}
```

**Resposta (sucesso):**
```json
{
    "request_id": "uuid",
    "status": "completed",
    "engine": "postgres",
    "instance": {
        "id": 1,
        "uuid": "uuid-da-instancia",
        "host": "db123456.easytidatabase.cloud",
        "port": 15432,
        "credentials": {
            "host": "db123456.easytidatabase.cloud",
            "port": 15432,
            "username": "user_abc123",
            "password": "senha-gerada",
            "database": "db_xyz789"
        }
    }
}
```

### Listar Bancos de Dados

```bash
GET /api/databases?user_id=1&engine=postgres&status=running
```

### Ver Detalhes de um Banco

```bash
GET /api/databases/{id}
```

### Parar Banco de Dados

```bash
POST /api/databases/{id}/stop
```

### Iniciar Banco de Dados

```bash
POST /api/databases/{id}/start
```

### Remover Banco de Dados

```bash
DELETE /api/databases/{id}
```

## Engines Suportadas

| Engine | Porta Base | Tipo |
|--------|------------|------|
| PostgreSQL | 15432+ | Relacional |
| MySQL | 13306+ | Relacional |
| Redis | 16379+ | Não-Relacional |

## Arquitetura

```
Sistema Principal (Panel)
        |
        v
   [API Request]
        |
        v
Database Provisioner (Laravel)
        |
        v
   [Redis Queue]
        |
        v
   [Horizon Worker]
        |
        v
   [Docker API]
        |
        v
   [Container DB]
```

## Fluxo de Provisionamento

1. Sistema principal envia requisição POST /api/databases
2. Provisioner cria `ProvisionRequest` com status `pending`
3. Job `ProvisionDatabaseJob` é enfileirado
4. Horizon processa o job:
   - Gera hostname único (db{random}.easytidatabase.cloud)
   - Gera porta única
   - Gera credenciais seguras
   - Cria container Docker com limites de recursos
   - Cria volume persistente
5. Atualiza status para `running`
6. Envia callback (se configurado) com credenciais

## Comandos Úteis

```bash
# Iniciar Horizon (processar filas)
php artisan horizon

# Ver status do Horizon
php artisan horizon:status

# Pausar processamento
php artisan horizon:pause

# Continuar processamento
php artisan horizon:continue

# Limpar jobs falhos
php artisan horizon:clear
```

## Docker Network

O provisioner cria automaticamente a network `easytidb_net` para isolar os containers de banco de dados.

## Persistência

Cada container tem um volume Docker dedicado:
- `db_{id}_data` - Dados do banco

## Segurança

- Senhas são geradas com 32 caracteres aleatórios
- Senhas são criptografadas no banco de dados
- Cada banco tem usuário dedicado
- Containers são isolados em network privada
