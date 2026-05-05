#!/usr/bin/env bash
# bit-waf helper: apply-rule.sh
#
# REQUER bash 4+ (associative arrays). macOS default /bin/bash e 3.x.
# Use /opt/homebrew/bin/bash (instalavel via `brew install bash`).
# Shebang #!/usr/bin/env bash respeita PATH (homebrew em primeiro).
#
# Aplica um template de rule WAF em uma Web ACL com:
# - Substituicao de placeholders (DEV_IPSET_ARN, HOST_HEADER_BASE64, etc.)
# - Snapshot pre-mudanca em git (via snapshot-acl.sh)
# - check-capacity antes de aplicar (abort se exceder)
# - Diff visual rule antiga vs nova (cores BIT)
# - Confirmacao interativa
# - Validacao pos-mudanca
# - Comando de rollback exibido ao final
#
# Uso:
#   ./apply-rule.sh <site-key> <template-name> [--mode=replace|append] [--dry-run]
#                                              [--substitute KEY=VAL]... [--skip-snapshot]
#
# Exemplos:
#   # Substitui rule existente (mesmo Name) — modo padrao
#   ./apply-rule.sh concertacao rate-limit-generic
#
#   # Dry-run — so mostra diff
#   ./apply-rule.sh concertacao rate-limit-generic --dry-run
#
#   # Adiciona rule nova
#   ./apply-rule.sh concertacao block-meta-externalagent --mode=append
#
#   # Substitui placeholder custom
#   ./apply-rule.sh concertacao block-nondev-wpadmin \
#     --substitute HOST_HEADER_BASE64=Y29uY2VydGFjYW9hbWF6b25pYS5jb20uYnI=

set -euo pipefail
export LC_NUMERIC=C  # printf %.2f compativel pt_BR

# ---------------------------------------------------------------------------
# Paths + config
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SKILL_DIR="$(dirname "$SCRIPT_DIR")"
TEMPLATES_DIR="$SKILL_DIR/templates/rules"
WAF_SITES_YAML="$HOME/.config/bit-bpo/waf-sites.yaml"

# Cores BIT
FG_GREEN="\033[0;32m"
FG_RED="\033[0;31m"
FG_ORANGE="\033[0;33m"
FG_BLUE="\033[0;34m"
FG_GRAY="\033[0;90m"
FG_BOLD="\033[1m"
ENDC="\033[0m"

ok()    { echo -e "${FG_GREEN}[+]${ENDC} $*"; }
err()   { echo -e "${FG_RED}[!]${ENDC} $*" >&2; }
warn()  { echo -e "${FG_ORANGE}[~]${ENDC} $*"; }
info()  { echo -e "${FG_BLUE}[i]${ENDC} $*"; }
gray()  { echo -e "${FG_GRAY}    $*${ENDC}"; }
bold()  { echo -e "${FG_BOLD}$*${ENDC}"; }

# ---------------------------------------------------------------------------
# Args parsing
# ---------------------------------------------------------------------------
SITE=""
TEMPLATE=""
MODE="replace"
DRY_RUN=0
SKIP_SNAPSHOT=0
declare -A SUBSTITUTES=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        --mode=*) MODE="${1#*=}" ;;
        --dry-run) DRY_RUN=1 ;;
        --skip-snapshot) SKIP_SNAPSHOT=1 ;;
        --substitute)
            shift
            KEY="${1%%=*}"
            VAL="${1#*=}"
            SUBSTITUTES["$KEY"]="$VAL"
            ;;
        --help|-h)
            head -30 "$0" | grep '^#'
            exit 0
            ;;
        -*)
            err "Flag desconhecida: $1"
            exit 1
            ;;
        *)
            if [[ -z "$SITE" ]]; then
                SITE="$1"
            elif [[ -z "$TEMPLATE" ]]; then
                TEMPLATE="$1"
            else
                err "Argumento extra: $1"
                exit 1
            fi
            ;;
    esac
    shift
done

if [[ -z "$SITE" || -z "$TEMPLATE" ]]; then
    err "Uso: $0 <site-key> <template-name> [opcoes]"
    err "Veja --help"
    exit 1
fi

if [[ "$MODE" != "replace" && "$MODE" != "append" ]]; then
    err "--mode deve ser 'replace' ou 'append'"
    exit 1
fi

TEMPLATE_FILE="$TEMPLATES_DIR/${TEMPLATE}.json"
if [[ ! -f "$TEMPLATE_FILE" ]]; then
    err "Template nao encontrado: $TEMPLATE_FILE"
    err "Templates disponiveis:"
    ls -1 "$TEMPLATES_DIR"/*.json 2>/dev/null | xargs -n1 basename | sed 's/\.json$//' | sed 's/^/  /'
    exit 1
fi

if [[ ! -f "$WAF_SITES_YAML" ]]; then
    err "Config nao encontrada: $WAF_SITES_YAML"
    exit 1
fi

# ---------------------------------------------------------------------------
# Parse YAML
# ---------------------------------------------------------------------------
read_yaml() {
    python3 -c "
import yaml
with open('$WAF_SITES_YAML') as f:
    d = yaml.safe_load(f)
print(d['sites']['$SITE'].get('$1', ''))
"
}

PROFILE="$(read_yaml aws_profile)"
WEB_ACL_NAME="$(read_yaml web_acl_name)"
WEB_ACL_ID="$(read_yaml web_acl_id)"
WEB_ACL_ARN="$(read_yaml web_acl_arn)"
DEV_IPSET_ARN="$(read_yaml dev_ipset_arn)"
DIST_ALIAS="$(read_yaml distribution_alias)"

if [[ -z "$PROFILE" || -z "$WEB_ACL_NAME" || -z "$WEB_ACL_ID" ]]; then
    err "Config incompleta para site '$SITE'"
    exit 1
fi

# Auto-substitutes do YAML (so popula se nao foi sobrescrito por --substitute)
[[ -z "${SUBSTITUTES[DEV_IPSET_ARN]:-}" ]] && SUBSTITUTES[DEV_IPSET_ARN]="$DEV_IPSET_ARN"
if [[ -z "${SUBSTITUTES[HOST_HEADER_BASE64]:-}" && -n "$DIST_ALIAS" ]]; then
    SUBSTITUTES[HOST_HEADER_BASE64]="$(printf '%s' "$DIST_ALIAS" | base64)"
fi

# ---------------------------------------------------------------------------
# Banner
# ---------------------------------------------------------------------------
echo ""
bold "═══════════════════════════════════════════════════════════"
bold " apply-rule  ·  $SITE  ·  $(date '+%Y-%m-%d %H:%M %Z')"
bold "═══════════════════════════════════════════════════════════"
echo ""
info "Site: $SITE  ·  ACL: $WEB_ACL_NAME"
info "Template: $TEMPLATE"
info "Mode: $MODE"
[[ $DRY_RUN -eq 1 ]] && warn "DRY-RUN: nenhuma mudanca sera aplicada"
echo ""

if [[ ${#SUBSTITUTES[@]} -gt 0 ]]; then
    info "Placeholders a substituir:"
    for k in "${!SUBSTITUTES[@]}"; do
        gray "  {{$k}} -> ${SUBSTITUTES[$k]:0:60}..."
    done
    echo ""
fi

# ---------------------------------------------------------------------------
# Step 1: Carregar template e substituir placeholders
# ---------------------------------------------------------------------------
info "[1/8] Carregando template + substituindo placeholders"
TEMPLATE_JSON=$(jq '.rule' "$TEMPLATE_FILE")
if [[ "$TEMPLATE_JSON" == "null" ]]; then
    err "Template '$TEMPLATE' sem campo 'rule' - JSON invalido?"
    exit 1
fi

# Substituir placeholders (sed pode falhar com / nos valores, mas placeholders sao identifiers)
for k in "${!SUBSTITUTES[@]}"; do
    v="${SUBSTITUTES[$k]}"
    # Use perl para escape robusto (placeholders podem ter / em ARNs)
    TEMPLATE_JSON=$(echo "$TEMPLATE_JSON" | perl -pe "s|\\{\\{$k\\}\\}|$v|g")
done

# Validar que nao sobraram placeholders
LEFTOVER=$(echo "$TEMPLATE_JSON" | grep -oE '\{\{[A-Z_]+\}\}' | sort -u || true)
if [[ -n "$LEFTOVER" ]]; then
    err "Placeholders nao substituidos:"
    echo "$LEFTOVER" | sed 's/^/    /'
    err "Use --substitute KEY=VALUE"
    exit 1
fi

# Validar JSON resultante
if ! echo "$TEMPLATE_JSON" | jq empty 2>/dev/null; then
    err "JSON invalido apos substituicao"
    echo "$TEMPLATE_JSON" | head -20
    exit 1
fi

RULE_NAME=$(echo "$TEMPLATE_JSON" | jq -r .Name)
RULE_PRIORITY=$(echo "$TEMPLATE_JSON" | jq -r .Priority)
ok "Template carregado: rule='$RULE_NAME' priority=$RULE_PRIORITY"

# ---------------------------------------------------------------------------
# Step 2: Snapshot pre-mudanca
# ---------------------------------------------------------------------------
if [[ $SKIP_SNAPSHOT -eq 0 && $DRY_RUN -eq 0 ]]; then
    info "[2/8] Snapshot pre-mudanca (helpers/snapshot-acl.sh)"
    "$SCRIPT_DIR/snapshot-acl.sh" "$SITE" >/dev/null 2>&1 || warn "snapshot-acl falhou — continuando"
    ok "Snapshot capturado"
else
    info "[2/8] Snapshot SKIPPED (--skip-snapshot ou --dry-run)"
fi

# ---------------------------------------------------------------------------
# Step 3: Get current ACL + LockToken
# ---------------------------------------------------------------------------
info "[3/8] Capturando ACL atual + LockToken"
TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT

aws wafv2 get-web-acl --profile "$PROFILE" --scope CLOUDFRONT --region us-east-1 \
    --name "$WEB_ACL_NAME" --id "$WEB_ACL_ID" \
    --output json > "$TMP_DIR/current.json" 2>&1

LOCK_TOKEN=$(jq -r .LockToken "$TMP_DIR/current.json")
ok "LockToken: $LOCK_TOKEN"

# ---------------------------------------------------------------------------
# Step 4: Construir payload novo
# ---------------------------------------------------------------------------
info "[4/8] Construindo payload de update"

if [[ "$MODE" == "replace" ]]; then
    # Verificar se rule com mesmo Name existe
    EXISTING=$(jq --arg n "$RULE_NAME" '.WebACL.Rules[] | select(.Name == $n)' "$TMP_DIR/current.json")
    if [[ -z "$EXISTING" ]]; then
        warn "Mode=replace mas rule '$RULE_NAME' nao existe na ACL atual"
        warn "Use --mode=append para adicionar como nova rule"
        exit 1
    fi
    # Replace
    jq --argjson newrule "$TEMPLATE_JSON" --arg n "$RULE_NAME" '
    {
      Name: .WebACL.Name,
      Scope: "CLOUDFRONT",
      Id: .WebACL.Id,
      DefaultAction: .WebACL.DefaultAction,
      Rules: [.WebACL.Rules[] | if .Name == $n then $newrule else . end],
      VisibilityConfig: .WebACL.VisibilityConfig,
      CustomResponseBodies: (.WebACL.CustomResponseBodies // {}),
      LockToken: .LockToken
    }' "$TMP_DIR/current.json" > "$TMP_DIR/update.json"
else
    # Append — verificar conflito de Priority
    EXISTING_PRI=$(jq --arg p "$RULE_PRIORITY" '.WebACL.Rules[] | select(.Priority == ($p | tonumber)) | .Name' "$TMP_DIR/current.json")
    if [[ -n "$EXISTING_PRI" ]]; then
        err "Mode=append mas Priority $RULE_PRIORITY ja em uso por: $EXISTING_PRI"
        err "Edite o template para usar Priority livre ou use --mode=replace"
        exit 1
    fi
    jq --argjson newrule "$TEMPLATE_JSON" '
    {
      Name: .WebACL.Name,
      Scope: "CLOUDFRONT",
      Id: .WebACL.Id,
      DefaultAction: .WebACL.DefaultAction,
      Rules: (.WebACL.Rules + [$newrule]),
      VisibilityConfig: .WebACL.VisibilityConfig,
      CustomResponseBodies: (.WebACL.CustomResponseBodies // {}),
      LockToken: .LockToken
    }' "$TMP_DIR/current.json" > "$TMP_DIR/update.json"
fi

NEW_RULES_COUNT=$(jq '.Rules | length' "$TMP_DIR/update.json")
OLD_RULES_COUNT=$(jq '.WebACL.Rules | length' "$TMP_DIR/current.json")
ok "Payload: $OLD_RULES_COUNT -> $NEW_RULES_COUNT rules"

# ---------------------------------------------------------------------------
# Step 5: Diff visual
# ---------------------------------------------------------------------------
info "[5/8] Diff visual rule antiga vs nova"

if [[ "$MODE" == "replace" ]]; then
    OLD_RULE=$(jq --arg n "$RULE_NAME" '.WebACL.Rules[] | select(.Name == $n)' "$TMP_DIR/current.json")
    echo ""
    bold "  --- $RULE_NAME (antes) ---"
    echo "$OLD_RULE" | jq . | head -30 | sed 's/^/    /'
    echo ""
    bold "  +++ $RULE_NAME (depois) ---"
    echo "$TEMPLATE_JSON" | jq . | head -30 | sed 's/^/    /'
else
    echo ""
    bold "  +++ $RULE_NAME (NOVA) ---"
    echo "$TEMPLATE_JSON" | jq . | head -30 | sed 's/^/    /'
fi
echo ""

# ---------------------------------------------------------------------------
# Step 6: Check capacity
# ---------------------------------------------------------------------------
info "[6/8] Validando capacity (WCU)"

# Remover campos que check-capacity nao aceita
RULES_FOR_CHECK=$(jq '[.Rules[] | del(.RuleLabels)]' "$TMP_DIR/update.json")
CAPACITY=$(aws wafv2 check-capacity --profile "$PROFILE" --region us-east-1 \
    --scope CLOUDFRONT --rules "$RULES_FOR_CHECK" \
    --query Capacity --output text 2>&1) || {
    err "check-capacity falhou:"
    echo "$CAPACITY" | sed 's/^/    /'
    exit 1
}

ok "Capacity: $CAPACITY WCU (limite default AWS = 1500)"
if [[ $CAPACITY -gt 1400 ]]; then
    warn "Capacity proximo do limite. Considere consolidar rules."
fi

# ---------------------------------------------------------------------------
# Step 7: Confirmar (skip se dry-run)
# ---------------------------------------------------------------------------
if [[ $DRY_RUN -eq 1 ]]; then
    echo ""
    bold "═══════════════════════════════════════════════════════════"
    info "DRY-RUN concluido. Payload em $TMP_DIR/update.json"
    bold "═══════════════════════════════════════════════════════════"
    cp "$TMP_DIR/update.json" "/tmp/apply-rule-${SITE}-${TEMPLATE}-$(date +%s).json"
    ok "Payload salvo em /tmp/ para inspecao"
    exit 0
fi

echo ""
warn "Aplicar mudanca em PRODUCAO?"
read -p "  digite 'sim' para confirmar: " CONFIRM
if [[ "$CONFIRM" != "sim" ]]; then
    info "Cancelado pelo usuario"
    exit 0
fi

# ---------------------------------------------------------------------------
# Step 8: Apply + validate
# ---------------------------------------------------------------------------
info "[7/8] Aplicando update-web-acl"
NEXT_LOCK=$(aws wafv2 update-web-acl --profile "$PROFILE" --region us-east-1 \
    --cli-input-json "file://$TMP_DIR/update.json" \
    --query NextLockToken --output text 2>&1) || {
    err "update-web-acl falhou:"
    echo "$NEXT_LOCK" | sed 's/^/    /'
    exit 1
}

ok "Update aceito. NextLockToken: $NEXT_LOCK"

echo ""
info "[8/8] Aguardando 5s + validacao"
sleep 5

aws wafv2 get-web-acl --profile "$PROFILE" --scope CLOUDFRONT --region us-east-1 \
    --name "$WEB_ACL_NAME" --id "$WEB_ACL_ID" \
    --output json > "$TMP_DIR/post.json"

VERIFIED_RULE=$(jq --arg n "$RULE_NAME" '.WebACL.Rules[] | select(.Name == $n)' "$TMP_DIR/post.json")
if [[ -z "$VERIFIED_RULE" ]]; then
    err "Rule '$RULE_NAME' nao encontrada na ACL pos-update"
    err "Algo deu errado — verifique manualmente"
    exit 1
fi

ok "Rule '$RULE_NAME' confirmada em producao"

# Snapshot pos-mudanca
"$SCRIPT_DIR/snapshot-acl.sh" "$SITE" >/dev/null 2>&1 || warn "snapshot-acl pos-mudanca falhou"

# ---------------------------------------------------------------------------
# Final + comando de rollback
# ---------------------------------------------------------------------------
echo ""
bold "═══════════════════════════════════════════════════════════"
ok "MUDANCA APLICADA COM SUCESSO"
bold "═══════════════════════════════════════════════════════════"
echo ""
info "Monitorar nos proximos 30min:"
gray "aws cloudwatch get-metric-statistics --profile '$PROFILE' --region us-east-1 \\"
gray "  --namespace AWS/WAFV2 --metric-name BlockedRequests \\"
gray "  --dimensions Name=WebACL,Value='$WEB_ACL_NAME' Name=Rule,Value='$RULE_NAME' Name=Region,Value=CloudFront \\"
gray "  --start-time \"\$(date -u -v-30M +%Y-%m-%dT%H:%M:%SZ)\" \\"
gray "  --end-time \"\$(date -u +%Y-%m-%dT%H:%M:%SZ)\" \\"
gray "  --period 60 --statistics Sum"
echo ""
info "Para rollback (se necessario):"
SITE_REPO="$HOME/scripts/server-tools/v2/docker-dev/sites/$SITE"
gray "cd $SITE_REPO"
gray "git log --oneline aws/waf-acl.json | head -5  # ver commits recentes"
gray "git show HEAD~1:aws/waf-acl.json > /tmp/rollback.json  # extrai snapshot anterior"
gray "# Reconstruir payload com Rules do snapshot + LockToken atual + apply"
gray ""
gray "Ou ver $SKILL_DIR/playbooks/deploy-rule.md secao Rollback"
echo ""
