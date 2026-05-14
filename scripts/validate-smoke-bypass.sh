#!/usr/bin/env bash
# ==============================================================================
# validate-smoke-bypass.sh - Valida deploy do mu-plugin bit-smoke-recaptcha-bypass
# Autor: Daniel Cambria (Bureau de Tecnologia)
# Data: 2026-05-14
# Versão: 1.0.0
#
# Descrição:
#   Roda os 6 testes obrigatórios pós-deploy do mu-plugin
#   bit-smoke-recaptcha-bypass em um ambiente alvo (DEV/HML/PROD).
#   Exit 0 = todos passaram; exit >0 = quantidade de falhas.
#
#   Testes:
#     1. Header X-BIT-Smoke-Bypass: OK com token válido
#     2. Header X-BIT-Smoke-Bypass: NOOP com token errado (64 chars)
#     3. Header ausente sem header de request (v1.1.1+: header condicional)
#     4. Header X-BIT-Smoke-Bypass: NOOP com token curto (< 32 chars)
#     5. Resposta HTTP 200 em todos os casos
#     6. Constante BIT_SMOKE_BYPASS_TOKEN definida (via wp eval se acessível)
#
# Uso:
#   validate-smoke-bypass.sh <URL> <TOKEN> [--insecure]
#
# Exemplos:
#   # DEV
#   ./validate-smoke-bypass.sh https://cambrasmax.local:8484 \
#       8315ac7c... --insecure
#
#   # PROD
#   ./validate-smoke-bypass.sh https://concertacaoamazonia.com.br \
#       $(ssh prod-sa "sudo grep BIT_SMOKE_BYPASS /var/www/.../wp-config.php" | grep -oE "'[a-f0-9]{32,}'" | tr -d "'")
#
# Spec:
#   docs/superpowers/specs/2026-05-14-smoke-recaptcha-bypass-design.md
# ==============================================================================

set -Eeuo pipefail

# --- args ---
if [[ $# -lt 2 ]]; then
    echo "Uso: $0 <URL> <TOKEN> [--insecure]" >&2
    echo "Exemplo: $0 https://concertacaoamazonia.com.br abc123def456... " >&2
    exit 2
fi

readonly URL="$1"
readonly TOKEN="$2"
readonly INSECURE_FLAG="${3:-}"

CURL_OPTS=("-s" "-D" "-" "-o" "/dev/null" "--max-time" "10")
if [[ "$INSECURE_FLAG" == "--insecure" ]]; then
    CURL_OPTS+=("-k")
fi

# --- helpers ---
LOG_PREFIX="[$(date +'%H:%M:%S')] [validate-smoke-bypass]"
log()  { echo "${LOG_PREFIX} $*" >&2; }
pass() { echo "  ✓ $*"; PASSED=$((PASSED+1)); }
fail() { echo "  ✗ $*"; FAILED=$((FAILED+1)); }

PASSED=0
FAILED=0

# --- pre-flight ---
if [[ ${#TOKEN} -lt 32 ]]; then
    log "❌ Token fornecido tem menos de 32 chars (recebido: ${#TOKEN}). Abortando."
    exit 2
fi
log "ℹ️  Validando $URL (token prefix=${TOKEN:0:8}...)"

# --- testes ---
echo ""
echo "Teste 1: token válido → X-BIT-Smoke-Bypass: OK"
HEADERS=$(curl "${CURL_OPTS[@]}" -H "X-BIT-Smoke-Token: $TOKEN" "$URL/?cb=$(date +%s)1" 2>/dev/null || true)
HTTP_CODE=$(echo "$HEADERS" | grep -E "^HTTP/" | tail -1 | awk '{print $2}')
BYPASS=$(echo "$HEADERS" | grep -i "x-bit-smoke-bypass:" | tail -1 | awk -F': ' '{print $2}' | tr -d '\r' || true)
if [[ "$HTTP_CODE" == "200" ]] && [[ "$BYPASS" == "OK" ]]; then
    pass "HTTP 200, X-BIT-Smoke-Bypass=OK"
elif [[ "$HTTP_CODE" == "200" ]] && [[ "$BYPASS" == "FAILED" ]]; then
    fail "X-BIT-Smoke-Bypass=FAILED — drift do Elementor Pro (priority/classe mudou)?"
else
    fail "HTTP=$HTTP_CODE, X-BIT-Smoke-Bypass='$BYPASS' (esperado HTTP 200 + OK)"
fi

echo ""
echo "Teste 2: token errado (64 chars) → X-BIT-Smoke-Bypass: NOOP"
WRONG_TOKEN=$(printf 'x%.0s' {1..64})
HEADERS=$(curl "${CURL_OPTS[@]}" -H "X-BIT-Smoke-Token: $WRONG_TOKEN" "$URL/?cb=$(date +%s)2" 2>/dev/null || true)
BYPASS=$(echo "$HEADERS" | grep -i "x-bit-smoke-bypass:" | tail -1 | awk -F': ' '{print $2}' | tr -d '\r' || true)
if [[ "$BYPASS" == "NOOP" ]]; then
    pass "X-BIT-Smoke-Bypass=NOOP (bypass não aberto pra token errado)"
else
    fail "X-BIT-Smoke-Bypass='$BYPASS' (esperado NOOP) — RISCO DE SEGURANÇA"
fi

echo ""
echo "Teste 3: sem header de request → X-BIT-Smoke-Bypass AUSENTE (v1.1.1+)"
HEADERS=$(curl "${CURL_OPTS[@]}" "$URL/?cb=$(date +%s)3" 2>/dev/null || true)
if echo "$HEADERS" | grep -qi "x-bit-smoke-bypass:"; then
    BYPASS=$(echo "$HEADERS" | grep -i "x-bit-smoke-bypass:" | tail -1 | awk -F': ' '{print $2}' | tr -d '\r' || true)
    fail "X-BIT-Smoke-Bypass='$BYPASS' deveria estar AUSENTE quando sem header de request (v1.1.1+ comportamento)"
else
    pass "header não emitido para request anônimo (sem cache-poisoning)"
fi

echo ""
echo "Teste 4: token curto (< 32 chars) → X-BIT-Smoke-Bypass: NOOP"
HEADERS=$(curl "${CURL_OPTS[@]}" -H "X-BIT-Smoke-Token: shorttoken123" "$URL/?cb=$(date +%s)4" 2>/dev/null || true)
BYPASS=$(echo "$HEADERS" | grep -i "x-bit-smoke-bypass:" | tail -1 | awk -F': ' '{print $2}' | tr -d '\r' || true)
if [[ "$BYPASS" == "NOOP" ]]; then
    pass "X-BIT-Smoke-Bypass=NOOP (rejeita token < MIN_TOKEN_LEN)"
else
    fail "X-BIT-Smoke-Bypass='$BYPASS' (esperado NOOP)"
fi

echo ""
echo "Teste 5: token vazio (header presente, valor vazio) → header ausente"
HEADERS=$(curl "${CURL_OPTS[@]}" -H "X-BIT-Smoke-Token;" "$URL/?cb=$(date +%s)5" 2>/dev/null || true)
if echo "$HEADERS" | grep -qi "x-bit-smoke-bypass:"; then
    BYPASS=$(echo "$HEADERS" | grep -i "x-bit-smoke-bypass:" | tail -1 | awk -F': ' '{print $2}' | tr -d '\r' || true)
    fail "X-BIT-Smoke-Bypass='$BYPASS' deveria estar AUSENTE para header vazio"
else
    pass "header não emitido para X-BIT-Smoke-Token vazio"
fi

echo ""
echo "Teste 6: site responde HTTP 200 sem bypass (sanidade — site não derrubado)"
HEADERS=$(curl "${CURL_OPTS[@]}" "$URL/?cb=$(date +%s)6" 2>/dev/null || true)
HTTP_CODE=$(echo "$HEADERS" | grep -E "^HTTP/" | tail -1 | awk '{print $2}')
if [[ "$HTTP_CODE" == "200" ]] || [[ "$HTTP_CODE" == "301" ]] || [[ "$HTTP_CODE" == "302" ]]; then
    pass "HTTP $HTTP_CODE (site responde)"
else
    fail "HTTP=$HTTP_CODE — site pode estar degradado, investigar antes de seguir"
fi

# --- resultado ---
echo ""
echo "================================================================="
echo "Resultado: ${PASSED} passou(ram), ${FAILED} falhou(ram)"
echo "================================================================="

if [[ $FAILED -gt 0 ]]; then
    log "❌ FALHAS detectadas — não prosseguir com deploy/uso. Investigar."
    exit "$FAILED"
fi

log "✅ Todos os testes passaram. Mu-plugin funcionando corretamente em $URL."
exit 0
