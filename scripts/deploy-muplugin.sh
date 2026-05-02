#!/usr/bin/env bash
# ==============================================================================
# deploy-muplugin.sh — Deploy seguro de mu-plugin em produção
# Autor: Daniel Cambría / Bureau de Tecnologia
# Versão: 1.0.0
# Data: 2026-04-02
#
# Descrição:
#   Substitui o processo manual (scp + sudo mv + systemctl reload) por um
#   fluxo que invalida cirurgicamente o arquivo no OPcache do pool FPM via
#   HTTP, sem descartar o cache inteiro — eliminando o downtime causado por
#   OPcache frio sob carga de crawlers CloudFront.
#
# Mecanismo:
#   1. Copia o arquivo para /tmp no servidor via scp
#   2. Move para mu-plugins/ com sudo (um arquivo, permissões corretas)
#   3. Faz POST ao endpoint bit-opcache-invalidate.php com o path do arquivo
#      e um token secreto → o PHP-FPM (não o CLI) invalida o arquivo no OPcache
#      da própria instância do processo — sem reload, sem cache frio global
#   4. Executa warmup das URLs principais SOMENTE se o endpoint não estiver
#      disponível e o reload for inevitável (fallback)
#   5. Verifica a resposta HTTP do site ao final
#
# Uso:
#   ./deploy-muplugin.sh <arquivo.php> [--dry-run]
#
# Exemplos:
#   ./deploy-muplugin.sh bit-tec-cache.php
#   ./deploy-muplugin.sh bit-tec-cache.php --dry-run
#
# Pré-requisitos:
#   - SSH host alias: concertacaoamazonia.com.br-prod-sa
#   - Variável OPCACHE_TOKEN exportada ou em .env local
#   - Arquivo bit-opcache-invalidate.php já deployado em WP_ROOT
#     (ver scripts/bit-opcache-invalidate.php)
# ==============================================================================

set -euo pipefail

# ------------------------------------------------------------------------------
# Configuração
# ------------------------------------------------------------------------------

readonly SSH_HOST="concertacaoamazonia.com.br-prod-sa"
readonly WP_ROOT="/var/www/concertacaoamazonia.com.br"
readonly MU_PLUGINS_DIR="${WP_ROOT}/wp-content/mu-plugins"
readonly SITE_URL="https://concertacaoamazonia.com.br"
readonly INVALIDATE_ENDPOINT="${SITE_URL}/bit-opcache-invalidate.php"
readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Token de autenticação — ler do ambiente ou de .env local
# Exportar antes de rodar: export OPCACHE_TOKEN="seu-token-aqui"
# Ou adicionar ao .env do site: OPCACHE_INVALIDATE_TOKEN=...
if [[ -z "${OPCACHE_TOKEN:-}" ]]; then
    # Tentar carregar do .env do site
    local_env="${SCRIPT_DIR}/../.env"
    if [[ -f "$local_env" ]] && grep -q "OPCACHE_INVALIDATE_TOKEN" "$local_env"; then
        OPCACHE_TOKEN="$(grep '^OPCACHE_INVALIDATE_TOKEN=' "$local_env" | cut -d'=' -f2- | tr -d '"' | tr -d "'")"
    fi
fi

# Páginas principais para warmup (apenas usado no fallback com reload)
readonly WARMUP_URLS=(
    "${SITE_URL}/"
    "${SITE_URL}/cultura/"
    "${SITE_URL}/agenda/"
    "${SITE_URL}/editais/"
    "${SITE_URL}/quem-somos/"
    "${SITE_URL}/cultura/agenda/"
)

# Cores
readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly GRAY='\033[0;37m'
readonly BOLD='\033[1m'
readonly RESET='\033[0m'

# ------------------------------------------------------------------------------
# Funções auxiliares
# ------------------------------------------------------------------------------

log_info()    { echo -e "${BLUE}[INFO]${RESET} $*"; }
log_ok()      { echo -e "${GREEN}[OK]${RESET}   $*"; }
log_warn()    { echo -e "${YELLOW}[WARN]${RESET} $*"; }
log_error()   { echo -e "${RED}[ERRO]${RESET} $*" >&2; }
log_step()    { echo -e "\n${BOLD}${GRAY}──────────────────────────────────────────────${RESET}"; echo -e "${BOLD}  $*${RESET}"; }
dry_run_msg() { echo -e "${YELLOW}[DRY-RUN]${RESET} Executaria: $*"; }

usage() {
    cat <<EOF
Uso: $(basename "$0") <arquivo.php> [--dry-run]

  arquivo.php   Nome do arquivo mu-plugin (deve existir no diretório corrente
                ou em wordpress/wp-content/mu-plugins/)
  --dry-run     Mostra o que seria feito sem executar nada

Exemplos:
  $(basename "$0") bit-tec-cache.php
  $(basename "$0") bit-tec-cache.php --dry-run

Variáveis de ambiente:
  OPCACHE_TOKEN   Token secreto para o endpoint de invalidação
                  (obrigatório, ou configurar OPCACHE_INVALIDATE_TOKEN no .env)
EOF
    exit 0
}

# ------------------------------------------------------------------------------
# Parse de argumentos
# ------------------------------------------------------------------------------

DRY_RUN=false
PLUGIN_FILE=""

for arg in "$@"; do
    case "$arg" in
        --help|-h) usage ;;
        --dry-run) DRY_RUN=true ;;
        *.php)     PLUGIN_FILE="$arg" ;;
        *)
            log_error "Argumento desconhecido: $arg"
            usage
            ;;
    esac
done

if [[ -z "$PLUGIN_FILE" ]]; then
    log_error "Arquivo PHP não especificado."
    usage
fi

# ------------------------------------------------------------------------------
# Localizar arquivo fonte
# ------------------------------------------------------------------------------

log_step "1. Localizando arquivo fonte"

SOURCE_FILE=""
SEARCH_PATHS=(
    "${SCRIPT_DIR}/${PLUGIN_FILE}"
    "${SCRIPT_DIR}/../wordpress/wp-content/mu-plugins/${PLUGIN_FILE}"
    "$(pwd)/${PLUGIN_FILE}"
    "/Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/${PLUGIN_FILE}"
)

for path in "${SEARCH_PATHS[@]}"; do
    if [[ -f "$path" ]]; then
        SOURCE_FILE="$path"
        break
    fi
done

if [[ -z "$SOURCE_FILE" ]]; then
    log_error "Arquivo '${PLUGIN_FILE}' não encontrado em nenhum dos caminhos:"
    for path in "${SEARCH_PATHS[@]}"; do
        log_error "  - $path"
    done
    exit 1
fi

log_ok "Fonte: ${SOURCE_FILE}"

# ------------------------------------------------------------------------------
# Verificar token
# ------------------------------------------------------------------------------

log_step "2. Verificando autenticação"

if [[ -z "${OPCACHE_TOKEN:-}" ]]; then
    log_error "OPCACHE_TOKEN não definido."
    log_error "Exporte a variável antes de rodar:"
    log_error "  export OPCACHE_TOKEN=\"seu-token-aqui\""
    log_error "Ou adicione OPCACHE_INVALIDATE_TOKEN ao .env do site."
    exit 1
fi

log_ok "Token de invalidação presente."

# ------------------------------------------------------------------------------
# Verificar conectividade SSH
# ------------------------------------------------------------------------------

log_step "3. Verificando conectividade SSH"

if ! ssh -o ConnectTimeout=5 -o BatchMode=yes "${SSH_HOST}" "echo ok" &>/dev/null; then
    log_error "Não foi possível conectar via SSH a ${SSH_HOST}."
    log_error "Verifique VPN e permissões de chave."
    exit 1
fi

log_ok "SSH OK → ${SSH_HOST}"

# ------------------------------------------------------------------------------
# Verificar se endpoint de invalidação existe em produção
# ------------------------------------------------------------------------------

log_step "4. Verificando endpoint de invalidação OPcache"

ENDPOINT_STATUS=$(ssh "${SSH_HOST}" \
    "test -f '${WP_ROOT}/bit-opcache-invalidate.php' && echo 'present' || echo 'absent'" 2>/dev/null)

if [[ "$ENDPOINT_STATUS" == "absent" ]]; then
    log_warn "Endpoint bit-opcache-invalidate.php NÃO encontrado em ${WP_ROOT}."
    log_warn "Será necessário fazer o deploy do endpoint primeiro."
    log_warn "Execute: scp scripts/bit-opcache-invalidate.php ${SSH_HOST}:${WP_ROOT}/"
    log_warn "Continuando com estratégia de FALLBACK (reload + warmup)..."
    USE_ENDPOINT=false
else
    log_ok "Endpoint presente em ${WP_ROOT}/bit-opcache-invalidate.php"
    USE_ENDPOINT=true
fi

# ------------------------------------------------------------------------------
# Executar deploy
# ------------------------------------------------------------------------------

log_step "5. Transferindo arquivo para produção"

REMOTE_TMP="/tmp/${PLUGIN_FILE}"
REMOTE_DEST="${MU_PLUGINS_DIR}/${PLUGIN_FILE}"

if [[ "$DRY_RUN" == "true" ]]; then
    dry_run_msg "scp \"${SOURCE_FILE}\" \"${SSH_HOST}:${REMOTE_TMP}\""
    dry_run_msg "ssh ${SSH_HOST} sudo mkdir -p \"${MU_PLUGINS_DIR}\""
    dry_run_msg "ssh ${SSH_HOST} sudo mv \"${REMOTE_TMP}\" \"${REMOTE_DEST}\""
    dry_run_msg "ssh ${SSH_HOST} sudo chown www-data:www-data \"${REMOTE_DEST}\""
    dry_run_msg "ssh ${SSH_HOST} sudo chmod 644 \"${REMOTE_DEST}\""
else
    scp -q "${SOURCE_FILE}" "${SSH_HOST}:${REMOTE_TMP}"
    ssh "${SSH_HOST}" "sudo mkdir -p '${MU_PLUGINS_DIR}' && sudo chown www-data:www-data '${MU_PLUGINS_DIR}'"
    ssh "${SSH_HOST}" "sudo mv '${REMOTE_TMP}' '${REMOTE_DEST}'"
    ssh "${SSH_HOST}" "sudo chown www-data:www-data '${REMOTE_DEST}'"
    ssh "${SSH_HOST}" "sudo chmod 644 '${REMOTE_DEST}'"
    log_ok "Arquivo copiado para ${REMOTE_DEST}"
fi

# ------------------------------------------------------------------------------
# Invalidar OPcache — estratégia principal: endpoint HTTP via FPM
# ------------------------------------------------------------------------------

log_step "6. Invalidando OPcache"

if [[ "$USE_ENDPOINT" == "true" ]]; then
    log_info "Estratégia: invalidação cirúrgica via endpoint HTTP (sem reload)"

    if [[ "$DRY_RUN" == "true" ]]; then
        dry_run_msg "POST http://localhost/bit-opcache-invalidate.php (via SSH) file=${REMOTE_DEST}"
    else
        # Chamar via SSH no localhost do servidor para bypassar CloudFront
        # CloudFront bloqueia POST direto → chamar dentro do EC2 garante acesso direto ao PHP-FPM
        INVALIDATE_RESPONSE=$(ssh "${SSH_HOST}" \
            "curl -s -X POST http://localhost/bit-opcache-invalidate.php \
             -H 'Host: concertacaoamazonia.com.br' \
             -d 'token=${OPCACHE_TOKEN}&file=${REMOTE_DEST}&force=1' \
             --max-time 10 2>/dev/null" || echo "SSH_ERROR")

        if echo "${INVALIDATE_RESPONSE}" | grep -q '"ok":true'; then
            log_ok "OPcache invalidado cirurgicamente para ${PLUGIN_FILE}"
            log_ok "Pool FPM NÃO foi reiniciado — zero downtime"
            log_info "Resposta: ${INVALIDATE_RESPONSE}"
        else
            log_warn "Endpoint retornou: ${INVALIDATE_RESPONSE}. Tentando fallback com reload..."
            USE_ENDPOINT=false
        fi
    fi
fi

# Fallback: reload + warmup (apenas se endpoint falhou ou não está disponível)
if [[ "$USE_ENDPOINT" == "false" ]]; then
    log_warn "Estratégia FALLBACK: systemctl reload + warmup de URLs principais"
    log_warn "Este processo causa ~15-30s de OPcache frio — executar fora do horário de pico."

    if [[ "$DRY_RUN" == "true" ]]; then
        dry_run_msg "ssh ${SSH_HOST} sudo systemctl reload php8.3-fpm"
        log_info "[DRY-RUN] Warmup das seguintes URLs:"
        for url in "${WARMUP_URLS[@]}"; do
            dry_run_msg "  curl -s -o /dev/null ${url}"
        done
    else
        log_info "Executando reload do PHP-FPM..."
        ssh "${SSH_HOST}" "sudo systemctl reload php8.3-fpm"
        log_ok "PHP-FPM recarregado."

        log_info "Aguardando pool estabilizar (3s)..."
        sleep 3

        log_info "Iniciando warmup das ${#WARMUP_URLS[@]} URLs principais..."
        WARMUP_ERRORS=0
        for url in "${WARMUP_URLS[@]}"; do
            STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
                --max-time 15 \
                -H "User-Agent: BIT-Warmup/1.0" \
                "$url" 2>/dev/null || echo "000")
            if [[ "$STATUS" == "200" ]] || [[ "$STATUS" == "301" ]] || [[ "$STATUS" == "302" ]]; then
                log_ok "  ${STATUS} ${url}"
            else
                log_warn "  ${STATUS} ${url} (pode ser redirecionamento ou cache miss)"
                ((WARMUP_ERRORS++)) || true
            fi
        done

        if [[ "$WARMUP_ERRORS" -gt 0 ]]; then
            log_warn "${WARMUP_ERRORS} URL(s) com resposta inesperada durante warmup."
        else
            log_ok "Warmup concluído. OPcache reaquecido nas páginas principais."
        fi
    fi
fi

# ------------------------------------------------------------------------------
# Verificação final
# ------------------------------------------------------------------------------

log_step "7. Verificação final"

if [[ "$DRY_RUN" == "true" ]]; then
    dry_run_msg "Verificar HTTP 200 em ${SITE_URL}/"
    dry_run_msg "Verificar que o arquivo existe em produção"
    echo -e "\n${YELLOW}[DRY-RUN]${RESET} Simulação concluída. Nenhuma alteração foi feita."
    exit 0
fi

# Verificar que o arquivo chegou em produção
REMOTE_CHECK=$(ssh "${SSH_HOST}" \
    "test -f '${REMOTE_DEST}' && stat -c '%U %a %s' '${REMOTE_DEST}' 2>/dev/null || echo 'MISSING'")

if [[ "$REMOTE_CHECK" == "MISSING" ]]; then
    log_error "Arquivo NÃO encontrado em ${REMOTE_DEST} após deploy!"
    exit 1
fi

REMOTE_OWNER=$(echo "$REMOTE_CHECK" | awk '{print $1}')
REMOTE_PERMS=$(echo "$REMOTE_CHECK" | awk '{print $2}')
log_ok "Arquivo presente em produção: owner=${REMOTE_OWNER} perms=${REMOTE_PERMS}"

if [[ "$REMOTE_OWNER" != "www-data" ]]; then
    log_warn "Owner inesperado: ${REMOTE_OWNER} (esperado: www-data)"
fi

# Verificar resposta HTTP do site
FINAL_STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
    --max-time 10 \
    -H "User-Agent: BIT-HealthCheck/1.0" \
    "${SITE_URL}/" 2>/dev/null || echo "000")

if [[ "$FINAL_STATUS" == "200" ]] || [[ "$FINAL_STATUS" == "301" ]]; then
    log_ok "Site respondendo: HTTP ${FINAL_STATUS}"
else
    log_error "Site retornou HTTP ${FINAL_STATUS} — verificar logs imediatamente!"
    log_error "  ssh ${SSH_HOST} sudo tail -50 /var/log/php-fpm-wordpress.log"
    log_error "  ssh ${SSH_HOST} sudo tail -50 /var/log/nginx/error.log"
    exit 1
fi

# ------------------------------------------------------------------------------
# Resumo
# ------------------------------------------------------------------------------

echo ""
echo -e "${GREEN}${BOLD}╔══════════════════════════════════════════════╗${RESET}"
echo -e "${GREEN}${BOLD}║   Deploy concluído com sucesso               ║${RESET}"
echo -e "${GREEN}${BOLD}╚══════════════════════════════════════════════╝${RESET}"
echo -e "  Arquivo:    ${PLUGIN_FILE}"
echo -e "  Destino:    ${REMOTE_DEST}"
if [[ "$USE_ENDPOINT" == "true" ]]; then
    echo -e "  Método:     Invalidação cirúrgica via HTTP (zero downtime)"
else
    echo -e "  Método:     Reload PHP-FPM + warmup (fallback)"
fi
echo -e "  Site:       HTTP ${FINAL_STATUS} ${SITE_URL}/"
echo ""
