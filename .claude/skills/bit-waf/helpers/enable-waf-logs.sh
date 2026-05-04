#!/bin/bash
# bit-waf helper: enable-waf-logs.sh
#
# Habilita logging WAF S3 para um site. Idempotente — re-run em site com
# logs já ativos não quebra (detecta e reporta).
#
# Cria:
# - bucket S3 em us-east-1 com prefix `aws-waf-logs-` (obrigatório AWS)
# - lifecycle: 30d → Glacier, 90d → expire
# - public access block + AES256 encryption
# - PutLoggingConfiguration associando bucket à WebACL
#
# Uso:
#   ./enable-waf-logs.sh <site-key>
#
# Lê config de ~/.config/bit-bpo/waf-sites.yaml

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WAF_SITES_YAML="$HOME/.config/bit-bpo/waf-sites.yaml"

# ---------------------------------------------------------------------------
# Cores (compatibilidade BIT brand)
# ---------------------------------------------------------------------------
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
# Args + validation
# ---------------------------------------------------------------------------
if [[ $# -ne 1 ]]; then
    err "Uso: $0 <site-key>"
    err "Exemplo: $0 concertacao"
    exit 1
fi

SITE="$1"

if [[ ! -f "$WAF_SITES_YAML" ]]; then
    err "Config nao encontrada: $WAF_SITES_YAML"
    err "Crie o arquivo com a entrada do site primeiro."
    exit 1
fi

# Parse YAML (sem dependencias externas — usa python3 ja disponivel)
read_yaml() {
    local key="$1"
    python3 -c "
import yaml, sys
try:
    with open('$WAF_SITES_YAML') as f:
        d = yaml.safe_load(f)
    print(d['sites']['$SITE'].get('$key', ''))
except KeyError:
    sys.exit(1)
except Exception as e:
    print(f'YAML error: {e}', file=sys.stderr)
    sys.exit(1)
"
}

AWS_PROFILE="$(read_yaml aws_profile)"
WEB_ACL_ARN="$(read_yaml web_acl_arn)"
LOG_BUCKET="$(read_yaml log_bucket)"

if [[ -z "$AWS_PROFILE" || -z "$WEB_ACL_ARN" ]]; then
    err "Config incompleta para site '$SITE' em $WAF_SITES_YAML"
    err "Necessario: aws_profile, web_acl_arn"
    exit 1
fi

if [[ -z "$LOG_BUCKET" ]]; then
    # Generate default bucket name
    LOG_BUCKET="aws-waf-logs-${SITE}-prd-use1"
    warn "log_bucket nao definido em yaml — usando default: $LOG_BUCKET"
fi

ACCOUNT_ID=$(aws sts get-caller-identity --profile "$AWS_PROFILE" --query Account --output text 2>/dev/null) || {
    err "Falha ao validar AWS profile '$AWS_PROFILE'"
    exit 1
}

info "Site: $SITE"
gray "Profile: $AWS_PROFILE (account $ACCOUNT_ID)"
gray "WebACL: $WEB_ACL_ARN"
gray "Bucket: $LOG_BUCKET"
echo ""

# ---------------------------------------------------------------------------
# Step 1: Verificar se logging ja esta ativo
# ---------------------------------------------------------------------------
EXISTING_CONFIG=$(aws wafv2 get-logging-configuration \
    --profile "$AWS_PROFILE" --region us-east-1 \
    --resource-arn "$WEB_ACL_ARN" 2>/dev/null || echo "")

if [[ -n "$EXISTING_CONFIG" ]]; then
    EXISTING_DEST=$(echo "$EXISTING_CONFIG" | python3 -c "import json,sys; print(json.load(sys.stdin)['LoggingConfiguration']['LogDestinationConfigs'][0])" 2>/dev/null || echo "")
    if [[ -n "$EXISTING_DEST" ]]; then
        ok "Logging JA esta ativo"
        gray "Destination: $EXISTING_DEST"
        info "Nada a fazer. Para reconfigurar, delete logging primeiro:"
        gray "aws wafv2 delete-logging-configuration --profile $AWS_PROFILE --region us-east-1 --resource-arn '$WEB_ACL_ARN'"
        exit 0
    fi
fi

# ---------------------------------------------------------------------------
# Step 2: Criar bucket (idempotente)
# ---------------------------------------------------------------------------
if aws s3api head-bucket --profile "$AWS_PROFILE" --bucket "$LOG_BUCKET" 2>/dev/null; then
    ok "Bucket ja existe: $LOG_BUCKET"
else
    info "Criando bucket: $LOG_BUCKET"
    aws s3api create-bucket \
        --profile "$AWS_PROFILE" \
        --region us-east-1 \
        --bucket "$LOG_BUCKET" >/dev/null
    ok "Bucket criado"
fi

# ---------------------------------------------------------------------------
# Step 3: Lifecycle (30d Glacier, 90d expire)
# ---------------------------------------------------------------------------
LIFECYCLE_JSON='{
  "Rules": [
    {
      "ID": "expire-waf-logs-90d",
      "Status": "Enabled",
      "Filter": {"Prefix": ""},
      "Transitions": [{"Days": 30, "StorageClass": "GLACIER"}],
      "Expiration": {"Days": 90}
    }
  ]
}'

aws s3api put-bucket-lifecycle-configuration \
    --profile "$AWS_PROFILE" \
    --bucket "$LOG_BUCKET" \
    --lifecycle-configuration "$LIFECYCLE_JSON" >/dev/null
ok "Lifecycle aplicado: 30d Glacier, 90d expire"

# ---------------------------------------------------------------------------
# Step 4: Public access block + encryption
# ---------------------------------------------------------------------------
aws s3api put-public-access-block \
    --profile "$AWS_PROFILE" \
    --bucket "$LOG_BUCKET" \
    --public-access-block-configuration "BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true" >/dev/null
ok "Public access block aplicado"

aws s3api put-bucket-encryption \
    --profile "$AWS_PROFILE" \
    --bucket "$LOG_BUCKET" \
    --server-side-encryption-configuration '{"Rules":[{"ApplyServerSideEncryptionByDefault":{"SSEAlgorithm":"AES256"}}]}' >/dev/null
ok "Encryption AES256 aplicado"

# ---------------------------------------------------------------------------
# Step 5: PutLoggingConfiguration
# ---------------------------------------------------------------------------
LOGGING_CONFIG=$(cat <<EOF
{
  "ResourceArn": "$WEB_ACL_ARN",
  "LogDestinationConfigs": ["arn:aws:s3:::$LOG_BUCKET"]
}
EOF
)

aws wafv2 put-logging-configuration \
    --profile "$AWS_PROFILE" \
    --region us-east-1 \
    --logging-configuration "$LOGGING_CONFIG" >/dev/null
ok "Logging configuration associado a WebACL"

echo ""
ok "WAF logs habilitados para '$SITE'"
warn "Primeiros logs S3 chegam em ~5-10min (buffer da AWS)"
gray "Verifique com: aws s3 ls --profile $AWS_PROFILE s3://$LOG_BUCKET/AWSLogs/$ACCOUNT_ID/WAFLogs/ --recursive"

echo ""
info "Sugerido atualizar $WAF_SITES_YAML com:"
gray "  log_bucket: $LOG_BUCKET"
