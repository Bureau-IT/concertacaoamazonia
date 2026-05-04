#!/bin/bash
# bit-waf helper: snapshot-acl.sh
#
# Captura snapshot da Web ACL atual + CloudFront distribution config + ALB
# em sites/<site>/aws/ e commita em git. Usar SEMPRE antes de update prod.
#
# Lição feedback_aws_changes_audit_trail.md: CloudTrail tem 90d sem diff fácil;
# snapshot em git permite rollback exato e diff visual via `git log -p`.
#
# Uso:
#   ./snapshot-acl.sh <site-key> [--no-commit]

set -euo pipefail

WAF_SITES_YAML="$HOME/.config/bit-bpo/waf-sites.yaml"
SITES_REPO_BASE="$HOME/scripts/server-tools/v2/docker-dev/sites"

FG_GREEN="\033[0;32m"
FG_RED="\033[0;31m"
FG_ORANGE="\033[0;33m"
FG_BLUE="\033[0;34m"
FG_GRAY="\033[0;90m"
ENDC="\033[0m"

ok()   { echo -e "${FG_GREEN}[+]${ENDC} $*"; }
err()  { echo -e "${FG_RED}[!]${ENDC} $*" >&2; }
warn() { echo -e "${FG_ORANGE}[~]${ENDC} $*"; }
info() { echo -e "${FG_BLUE}[i]${ENDC} $*"; }
gray() { echo -e "${FG_GRAY}    $*${ENDC}"; }

# ---------------------------------------------------------------------------
# Args
# ---------------------------------------------------------------------------
NO_COMMIT=0
SITE=""

for arg in "$@"; do
    case "$arg" in
        --no-commit) NO_COMMIT=1 ;;
        -*)
            err "Flag desconhecida: $arg"
            exit 1
            ;;
        *)
            if [[ -z "$SITE" ]]; then
                SITE="$arg"
            else
                err "Argumento extra: $arg"
                exit 1
            fi
            ;;
    esac
done

if [[ -z "$SITE" ]]; then
    err "Uso: $0 <site-key> [--no-commit]"
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

AWS_PROFILE="$(read_yaml aws_profile)"
DIST_ID="$(read_yaml distribution_id)"
WEB_ACL_NAME="$(read_yaml web_acl_name)"
WEB_ACL_ID="$(read_yaml web_acl_id)"
ALB_NAME="$(read_yaml alb_name)"
ALB_REGION="$(read_yaml alb_region)"

if [[ -z "$AWS_PROFILE" || -z "$DIST_ID" || -z "$WEB_ACL_ID" ]]; then
    err "Config incompleta para site '$SITE'"
    exit 1
fi

SITE_AWS_DIR="$SITES_REPO_BASE/$SITE/aws"
mkdir -p "$SITE_AWS_DIR"

info "Capturando snapshot AWS para '$SITE'"
gray "Profile: $AWS_PROFILE"
gray "Output: $SITE_AWS_DIR/"
echo ""

# ---------------------------------------------------------------------------
# CloudFront distribution config
# ---------------------------------------------------------------------------
info "CloudFront distribution config..."
aws cloudfront get-distribution-config \
    --profile "$AWS_PROFILE" \
    --id "$DIST_ID" \
    > "$SITE_AWS_DIR/cf-distribution.json" 2>/dev/null
ok "cf-distribution.json ($(wc -l < "$SITE_AWS_DIR/cf-distribution.json") linhas)"

# ---------------------------------------------------------------------------
# WAF Web ACL
# ---------------------------------------------------------------------------
info "WAF Web ACL..."
aws wafv2 get-web-acl \
    --profile "$AWS_PROFILE" \
    --scope CLOUDFRONT \
    --region us-east-1 \
    --name "$WEB_ACL_NAME" \
    --id "$WEB_ACL_ID" \
    > "$SITE_AWS_DIR/waf-acl.json" 2>/dev/null
ok "waf-acl.json ($(wc -l < "$SITE_AWS_DIR/waf-acl.json") linhas)"

# ---------------------------------------------------------------------------
# ALB (opcional)
# ---------------------------------------------------------------------------
if [[ -n "$ALB_NAME" && -n "$ALB_REGION" ]]; then
    info "ALB $ALB_NAME ($ALB_REGION)..."
    aws elbv2 describe-load-balancers \
        --profile "$AWS_PROFILE" \
        --region "$ALB_REGION" \
        --names "$ALB_NAME" \
        > "$SITE_AWS_DIR/alb.json" 2>/dev/null
    ok "alb.json"
fi

# ---------------------------------------------------------------------------
# Git commit (opcional)
# ---------------------------------------------------------------------------
echo ""
if [[ $NO_COMMIT -eq 1 ]]; then
    warn "--no-commit passado, pulando git"
    info "Para versionar:"
    gray "cd $SITES_REPO_BASE/$SITE && git add aws/ && git commit -m 'chore(aws): snapshot manual'"
    exit 0
fi

cd "$SITES_REPO_BASE/$SITE"

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    warn "Nao e um repo git: $SITES_REPO_BASE/$SITE"
    warn "Snapshot salvo mas nao versionado"
    exit 0
fi

if git diff --quiet aws/ 2>/dev/null && [[ -z "$(git status --porcelain aws/)" ]]; then
    info "Nenhuma mudanca detectada — snapshot identico ao anterior"
    exit 0
fi

info "Diff detectado:"
git diff --stat aws/ | sed 's/^/    /'
echo ""

git add aws/
git commit -m "chore(aws): snapshot ACL/CF/ALB $(date -u +%Y-%m-%dT%H:%M:%SZ)" >/dev/null
COMMIT=$(git log -1 --format=%h aws/)
ok "Commit $COMMIT"
