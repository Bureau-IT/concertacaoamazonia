# Template para Novos Sites WordPress

Este é um template para criar novos ambientes de desenvolvimento WordPress.

## Como Usar

### 1. Copiar o Template

```bash
cd /Users/dcambria/scripts/concertacao/server-tools/sites
cp -r _template/ meu-novo-site/
cd meu-novo-site/
```

### 2. Configurar o .env

```bash
cp .env.example .env
# Edite o .env e ajuste:
# - SITE_NAME (nome único)
# - NGINX_PORT (porta única, ex: 8082, 8083, etc.)
# - MYSQL_PORT (porta única, ex: 33062, 33063, etc.)
# - REDIS_PORT (porta única, ex: 6382, 6383, etc.)
# - MYSQL_DATABASE, MYSQL_USER, etc.
```

### 3. Copiar WordPress Content (opcional)

Se você tem um WordPress existente:

```bash
# Copiar wp-content
cp -r /caminho/para/wp-content ./wordpress/wp-content

# OU importar de backup/S3
std import-from-s3
```

### 4. Iniciar o Ambiente

```bash
# Setup inicial
std setup

# Iniciar containers
std up

# Verificar status
std status
```

### 5. Acessar

- WordPress: `http://localhost:PORTA_NGINX` (conforme configurado no .env)
- MySQL: `localhost:PORTA_MYSQL`
- Redis: `localhost:PORTA_REDIS`

## Comandos Disponíveis

```bash
std up              # Iniciar
std stop            # Parar
std restart         # Reiniciar
std status          # Status
std logs [service]  # Logs
std shell           # Shell no container
std wp "comando"    # WP-CLI
std backup          # Backup local
```

## Estrutura de Portas Sugerida

- **Concertação**: 8080 (nginx), 3306 (mysql), 6379 (redis)
- **Site 2**: 8081 (nginx), 33061 (mysql), 6380 (redis)
- **Site 3**: 8082 (nginx), 33062 (mysql), 6381 (redis)

## Notas

- Certifique-se de usar portas únicas para cada site
- O nome do site (SITE_NAME) deve ser único
- Cada site tem seu próprio banco de dados isolado
