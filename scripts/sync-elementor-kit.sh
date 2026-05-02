#!/usr/bin/env bash
#
# sync-elementor-kit.sh - Sincroniza cores e fontes globais do Elementor Kit
#
# Autor: Daniel Cambría + Claude Code
# Versão: 2.1.0
# Data: 2026-03-10
#
# Descrição:
#   Copia system_colors, custom_colors, system_typography, custom_typography
#   e default_generic_fonts entre Kits Elementor conforme sync-elementor-kit.conf.
#   Suporta múltiplos grupos (ex: www2 → cultura) e backup/rollback automático.
#
# Uso:
#   ./sync-elementor-kit.sh                   # menu interativo para selecionar grupo
#   ./sync-elementor-kit.sh --group=www2      # sincroniza grupo específico
#   ./sync-elementor-kit.sh --all             # sincroniza todos os grupos
#   ./sync-elementor-kit.sh --dry-run         # mostra diff sem alterar
#   ./sync-elementor-kit.sh --yes             # sincroniza sem pedir confirmação
#   ./sync-elementor-kit.sh --rollback        # restaura backup de um kit
#   ./sync-elementor-kit.sh --rollback --group=www2  # rollback de grupo específico
#   ./sync-elementor-kit.sh --help

set -euo pipefail

# ==============================================================================
# CONSTANTES
# ==============================================================================

SCRIPT_DIR_SELF="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR_SELF
readonly CONF_FILE="$SCRIPT_DIR_SELF/sync-elementor-kit.conf"
readonly COLORS_SH="$SCRIPT_DIR_SELF/../../../../contexto/colors.sh"
readonly BRAND_HELPER="$SCRIPT_DIR_SELF/../../../../helpers/brand-helper.sh"

# Chaves do kit a sincronizar
readonly SYNC_KEYS="system_colors custom_colors system_typography custom_typography default_generic_fonts"

# Diretório de backups
readonly BACKUP_DIR_KITS="$SCRIPT_DIR_SELF/backups/elementor-kits"

# ==============================================================================
# DEPENDÊNCIAS
# ==============================================================================

# shellcheck source=/dev/null
source "$COLORS_SH"
# shellcheck source=/dev/null
source "$BRAND_HELPER"

# ==============================================================================
# LOG
# ==============================================================================

log_info()    { printf '%b  ℹ %s%b\n' "$FG_BLUE" "$*" "$ENDC"; }
log_success() { printf '%b  ✓ %s%b\n' "$FG_GREEN" "$*" "$ENDC"; }
log_warn()    { printf '%b  ⚠ %s%b\n' "$FG_ORANGE" "$*" "$ENDC"; }
log_error()   { printf '%b  ✗ %s%b\n' "$FG_RED" "$*" "$ENDC" >&2; }

# ==============================================================================
# CONF HELPERS
# ==============================================================================

# Lista os nomes de grupos definidos no conf
get_groups() {
    grep -E '^SOURCE_' "$CONF_FILE" 2>/dev/null \
        | sed 's/^SOURCE_//;s/|.*//' \
        | sort -u
}

# Retorna campo de SOURCE de um grupo (field: 1=container, 2=url, 3=kit_id)
get_source() {
    local group="$1"
    local field="$2"
    grep -E "^SOURCE_${group}\|" "$CONF_FILE" 2>/dev/null \
        | head -1 \
        | cut -d'|' -f$(( field + 1 ))
}

# Retorna linhas "container|url|kit_id" dos TARGETs de um grupo
get_targets() {
    local group="$1"
    grep -E "^TARGET_${group}\|" "$CONF_FILE" 2>/dev/null \
        | sed 's/^TARGET_[^|]*|//'
}

# ==============================================================================
# EXPORTAR CHAVES DO KIT
# ==============================================================================

_run_kit_export() {
    local container="$1"
    local wp_url="$2"
    local kit_id="$3"

    local tmp_php="/tmp/kit-export-$$.php"

    docker exec -i "$container" bash -c "cat > '$tmp_php'" << PHPEOF
<?php
\$kit_id   = (int) ${kit_id};
\$keys     = explode(' ', '${SYNC_KEYS}');
\$settings = get_post_meta(\$kit_id, '_elementor_page_settings', true);
if (!is_array(\$settings)) { \$settings = []; }
\$out = [];
foreach (\$keys as \$key) {
    if (array_key_exists(\$key, \$settings)) {
        \$out[\$key] = \$settings[\$key];
    }
}
echo json_encode(\$out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
PHPEOF

    docker exec -u www-data "$container" \
        wp eval-file "$tmp_php" \
        --url="$wp_url" 2>/dev/null
    docker exec "$container" rm -f "$tmp_php" 2>/dev/null || true
}

# ==============================================================================
# DIFF
# ==============================================================================

show_diff() {
    local source_json="$1"
    local target_json="$2"

    SOURCE_JSON="$source_json" TARGET_JSON="$target_json" python3 - <<'PYEOF'
import json, sys, os

src = json.loads(os.environ.get('SOURCE_JSON', '{}'))
tgt = json.loads(os.environ.get('TARGET_JSON', '{}'))

BLUE   = '\033[38;2;102;217;239m'
GREEN  = '\033[38;2;166;226;46m'
ORANGE = '\033[38;2;255;152;0m'
GRAY   = '\033[38;2;126;142;145m'
WHITE  = '\033[38;2;255;255;255m'
RESET  = '\033[0m'

def summarize_colors(arr):
    if not isinstance(arr, list): return '(vazio)'
    return ', '.join([c.get('title', c.get('_id', '?')) for c in arr[:5]]) + (f'... +{len(arr)-5}' if len(arr) > 5 else '')

def summarize_typo(arr):
    if not isinstance(arr, list): return '(vazio)'
    return ', '.join([t.get('title', t.get('_id', '?')) for t in arr[:4]]) + (f'... +{len(arr)-4}' if len(arr) > 4 else '')

keys_meta = {
    'system_colors':        ('Cores do sistema',      summarize_colors),
    'custom_colors':        ('Paleta customizada',    summarize_colors),
    'system_typography':    ('Tipografia do sistema', summarize_typo),
    'custom_typography':    ('Tipografia customizada',summarize_typo),
    'default_generic_fonts':('Fontes genéricas',      lambda x: str(x)[:60] if x else '(vazio)'),
}

sep = GRAY + '  ' + '─' * 72 + RESET
print(f'\n  {BLUE}{"Chave":<26} {"Fonte (main)":<22} {"Target":<22}{RESET}')
print(sep)

for key, (label, fmt) in keys_meta.items():
    src_val  = fmt(src.get(key))
    tgt_val  = fmt(tgt.get(key))
    changed  = src_val != tgt_val
    indicator = f'{ORANGE}≠{RESET}' if changed else f'{GREEN}={RESET}'
    print(f'  {indicator} {WHITE}{label:<25}{RESET} {GRAY}{src_val[:21]:<22}{RESET} {GRAY}{tgt_val[:21]:<22}{RESET}')

print(sep)
changed_count = sum(1 for key, (label, fmt) in keys_meta.items() if fmt(src.get(key)) != fmt(tgt.get(key)))
print(f'  {BLUE}Chaves com diferença: {ORANGE}{changed_count}{RESET}')
PYEOF
}

# ==============================================================================
# APLICAR SYNC
# ==============================================================================

apply_sync() {
    local container="$1"
    local wp_url="$2"
    local kit_id="$3"
    local source_json="$4"

    local tmp_file="/tmp/elementor-kit-sync-$$.json"

    echo "$source_json" | docker exec -i "$container" bash -c "cat > '$tmp_file'"

    local tmp_php="/tmp/kit-apply-$$.php"

    docker exec -i "$container" bash -c "cat > '$tmp_php'" << PHPEOF
<?php
\$kit_id   = (int) ${kit_id};
\$keys     = explode(' ', '${SYNC_KEYS}');
\$src      = json_decode(file_get_contents('${tmp_file}'), true);
if (!is_array(\$src)) { echo "ERRO: JSON invalido em ${tmp_file}\n"; exit(1); }
\$settings = get_post_meta(\$kit_id, '_elementor_page_settings', true);
if (!is_array(\$settings)) { \$settings = []; }
foreach (\$keys as \$key) {
    if (array_key_exists(\$key, \$src)) {
        \$settings[\$key] = \$src[\$key];
    }
}
update_post_meta(\$kit_id, '_elementor_page_settings', \$settings);
unlink('${tmp_file}');
echo "OK: Kit \$kit_id atualizado com " . count(\$keys) . " chaves.\n";
PHPEOF

    local result
    result=$(docker exec -u www-data "$container" \
        wp eval-file "$tmp_php" \
        --url="$wp_url" 2>/dev/null)
    docker exec "$container" rm -f "$tmp_php" 2>/dev/null || true

    echo "$result"
    [[ "$result" == OK:* ]]
}

# ==============================================================================
# FLUSH CSS
# ==============================================================================

flush_elementor_css() {
    local container="$1"
    local wp_url="$2"

    log_info "Regenerando CSS do Elementor em ${wp_url}..."

    if docker exec -u www-data "$container" \
        wp elementor flush-css \
        --url="$wp_url" 2>/dev/null; then
        log_success "Elementor CSS regenerado"
    else
        log_warn "wp elementor flush-css falhou (tentando via PHP)"
    fi

    docker exec -u www-data "$container" \
        wp eval '\Elementor\Plugin::$instance->files_manager->clear_cache();
echo "CSS cache limpo via PHP\n";
' --url="$wp_url" 2>/dev/null || true

    docker exec -u www-data "$container" \
        wp eval 'if (function_exists("rocket_clean_domain")) {
    rocket_clean_domain();
    rocket_clean_minify();
    echo "WP Rocket limpo\n";
}' --url="$wp_url" 2>/dev/null || true

    docker exec -u www-data "$container" \
        wp cache flush --url="$wp_url" 2>/dev/null \
        && log_success "Cache Redis/Object flushed" || true
}

# ==============================================================================
# BACKUP / ROLLBACK
# ==============================================================================

_url_to_slug() {
    echo "$1" | sed 's|https://||;s|http://||;s|[/:]|-|g;s|\.|-|g;s|-\+$||'
}

# Salva estado atual do kit antes do sync
# Uso: backup_kit container wp_url kit_id
# Retorna: caminho do arquivo de backup (stdout)
backup_kit() {
    local container="$1"
    local wp_url="$2"
    local kit_id="$3"

    mkdir -p "$BACKUP_DIR_KITS"

    local ts slug backup_file
    ts=$(date '+%Y%m%d-%H%M%S')
    slug=$(_url_to_slug "$wp_url")
    backup_file="${BACKUP_DIR_KITS}/${slug}--kit${kit_id}--${ts}.json"

    local json
    if ! json=$(_run_kit_export "$container" "$wp_url" "$kit_id" 2>/dev/null); then
        log_warn "Não foi possível criar backup de Kit ${kit_id} (${wp_url})" >&2
        return 1
    fi

    echo "$json" > "$backup_file"
    log_info "Backup: backups/elementor-kits/$(basename "$backup_file")" >&2
    echo "$backup_file"
}

# Rollback interativo: lista backups disponíveis para um grupo e restaura
cmd_rollback_kit() {
    local group="${1:-}"

    if [[ -z "$group" ]]; then
        local all_groups
        all_groups=$(get_groups)
        if [[ -z "$all_groups" ]]; then
            log_error "Nenhum grupo encontrado em $CONF_FILE"
            return 1
        fi
        local opts=()
        while IFS= read -r g; do
            opts+=("$g")
        done <<< "$all_groups"
        local sel
        sel=$(brand_menu_arrows "Grupo para rollback:" "${opts[@]}")
        case "$sel" in 254|255) return 0 ;; esac
        group=$(echo "$all_groups" | sed -n "$(( sel + 1 ))p")
    fi

    local targets
    targets=$(get_targets "$group")
    if [[ -z "$targets" ]]; then
        log_warn "Nenhum target no grupo: $group"
        return 0
    fi

    while IFS='|' read -r tgt_container tgt_url tgt_kit_id <&3; do
        [[ -z "$tgt_container" ]] && continue

        brand_phase_header "ROLLBACK KIT: $tgt_url"

        local slug
        slug=$(_url_to_slug "$tgt_url")

        local -a backup_files=()
        while IFS= read -r f; do
            backup_files+=("$f")
        done < <(find "$BACKUP_DIR_KITS" -maxdepth 1 -name "${slug}--kit${tgt_kit_id}--*.json" 2>/dev/null | sort -r | head -10)

        if [[ ${#backup_files[@]} -eq 0 ]]; then
            log_warn "Nenhum backup encontrado para Kit ${tgt_kit_id} (${tgt_url})"
            echo
            continue
        fi

        local opts=()
        for f in "${backup_files[@]}"; do
            opts+=("$(basename "$f")")
        done

        local sel
        sel=$(brand_menu_arrows "Backup para restaurar:" "${opts[@]}")
        case "$sel" in 254|255) echo; continue ;; esac

        local backup_json
        backup_json=$(cat "${backup_files[$sel]}")

        brand_phase_header "RESTAURANDO: ${tgt_url}"
        if apply_sync "$tgt_container" "$tgt_url" "$tgt_kit_id" "$backup_json"; then
            log_success "Kit ${tgt_kit_id} restaurado"
            flush_elementor_css "$tgt_container" "$tgt_url"
        else
            log_error "Falha ao restaurar Kit ${tgt_kit_id}"
        fi
        echo

    done 3<<< "$targets"
}

# ==============================================================================
# SYNC DE UM GRUPO
# ==============================================================================

sync_group() {
    local group="$1"
    local dry_run="$2"
    local auto_yes="$3"

    local src_container src_url src_kit_id
    src_container=$(get_source "$group" 1)
    src_url=$(get_source "$group" 2)
    src_kit_id=$(get_source "$group" 3)

    if [[ -z "$src_container" || -z "$src_url" || -z "$src_kit_id" ]]; then
        log_error "Grupo não encontrado no conf: $group"
        return 1
    fi

    brand_phase_header "GRUPO: $group"
    log_info "Fonte : Kit ${src_kit_id} · ${src_url}"
    echo

    # Exportar kit fonte
    log_info "Exportando kit fonte..." >&2
    local source_json
    source_json=$(_run_kit_export "$src_container" "$src_url" "$src_kit_id") \
        || { log_error "Falha ao exportar kit fonte"; return 1; }
    log_success "Kit fonte exportado"
    echo

    # Iterar targets
    local targets
    targets=$(get_targets "$group")

    if [[ -z "$targets" ]]; then
        log_warn "Nenhum target encontrado para grupo: $group"
        return 0
    fi

    while IFS='|' read -r tgt_container tgt_url tgt_kit_id <&3; do
        [[ -z "$tgt_container" ]] && continue

        log_info "Target: Kit ${tgt_kit_id} · ${tgt_url}"

        local target_json
        if ! target_json=$(_run_kit_export "$tgt_container" "$tgt_url" "$tgt_kit_id" 2>/dev/null); then
            log_warn "Falha ao exportar kit target ${tgt_url} — pulando"
            echo
            continue
        fi

        show_diff "$source_json" "$target_json"
        echo

        if [[ "$dry_run" == "true" ]]; then
            log_warn "Dry-run: nenhuma alteração em ${tgt_url}"
            echo
            continue
        fi

        if [[ "$auto_yes" != "true" ]] && [[ -t 0 ]]; then
            printf '%b  Aplicar sync em %s? [s/N]: %b' "$FG_ORANGE" "$tgt_url" "$ENDC"
            read -r answer
            if [[ ! "$answer" =~ ^[sSyY]$ ]]; then
                log_info "Pulando ${tgt_url}"
                echo
                continue
            fi
        fi

        brand_phase_header "APLICANDO: ${tgt_url}"
        backup_kit "$tgt_container" "$tgt_url" "$tgt_kit_id"
        if apply_sync "$tgt_container" "$tgt_url" "$tgt_kit_id" "$source_json"; then
            log_success "Kit atualizado: ${tgt_url}"
        else
            log_error "Falha ao atualizar: ${tgt_url}"
            echo
            continue
        fi
        echo

        brand_phase_header "REGENERANDO CSS: ${tgt_url}"
        flush_elementor_css "$tgt_container" "$tgt_url"
        echo

    done 3<<< "$targets"
}

# ==============================================================================
# HELP
# ==============================================================================

show_help() {
    local help_text
    help_text="
$(brand_help_title "SYNC-ELEMENTOR-KIT - Sincronização de Cores e Fontes Globais")

$(brand_help_section "DESCRIÇÃO:")
  Copia as cores e fontes globais do Elementor Kit de um site principal
  para seus subsites, mantendo a identidade visual sincronizada.
  Grupos e kits configurados em sync-elementor-kit.conf.

$(brand_help_section "CHAVES SINCRONIZADAS:")
  ${FG_GREEN}system_colors${ENDC}         Cores do sistema Elementor
  ${FG_GREEN}custom_colors${ENDC}         Paleta de cores customizada
  ${FG_GREEN}system_typography${ENDC}     Tipografia do sistema
  ${FG_GREEN}custom_typography${ENDC}     Tipografia customizada
  ${FG_GREEN}default_generic_fonts${ENDC} Fontes genéricas padrão

$(brand_help_section "GRUPOS DISPONÍVEIS:")
$(while IFS= read -r g; do
    local src_url
    src_url=$(get_source "$g" 2)
    local tgt_count
    tgt_count=$(get_targets "$g" | grep -c . 2>/dev/null || echo 0)
    printf '  %b%-10s%b  Fonte: %s  (%s targets)\n' \
        "$FG_GREEN" "$g" "$ENDC" "$src_url" "$tgt_count"
done < <(get_groups))

$(brand_help_section "USO:")
$(brand_help_command "./sync-elementor-kit.sh" "Menu interativo (seleciona grupo)")
$(brand_help_command "./sync-elementor-kit.sh --group=www2" "Sincroniza grupo www2")
$(brand_help_command "./sync-elementor-kit.sh --all" "Sincroniza todos os grupos")
$(brand_help_command "./sync-elementor-kit.sh --dry-run" "Mostra diff sem alterar")
$(brand_help_command "./sync-elementor-kit.sh --yes" "Sincroniza sem confirmação")
"
    if [[ -t 1 ]]; then
        echo "$help_text" | less -R
    else
        echo "$help_text"
    fi
}

# ==============================================================================
# MAIN
# ==============================================================================

main() {
    local dry_run=false
    local auto_yes=false
    local group=""
    local do_all=false
    local do_rollback=false

    for arg in "$@"; do
        case "$arg" in
            --dry-run)    dry_run=true ;;
            --yes|-y)     auto_yes=true ;;
            --all)        do_all=true ;;
            --group=*)    group="${arg#*=}" ;;
            --rollback)   do_rollback=true ;;
            --help|-h)    show_help; exit 0 ;;
            *)
                log_error "Argumento desconhecido: $arg"
                echo "Use --help para ajuda"
                exit 1
                ;;
        esac
    done

    # Rollback: não precisa de conf completo, despachar direto
    if [[ "$do_rollback" == "true" ]]; then
        brand_phase_header "ROLLBACK ELEMENTOR KIT"
        echo
        cmd_rollback_kit "$group"
        brand_phase_header "ROLLBACK CONCLUÍDO"
        echo
        exit 0
    fi

    brand_phase_header "SYNC ELEMENTOR KIT — CORES E FONTES"
    [[ "$dry_run" == "true" ]] && log_warn "Modo DRY-RUN ativado"
    echo

    if [[ ! -f "$CONF_FILE" ]]; then
        log_error "Arquivo de configuração não encontrado: $CONF_FILE"
        log_info "Esperado em: $CONF_FILE"
        exit 1
    fi

    local all_groups
    all_groups=$(get_groups)

    if [[ "$do_all" == "true" ]]; then
        while IFS= read -r g; do
            sync_group "$g" "$dry_run" "$auto_yes"
        done <<< "$all_groups"

    elif [[ -n "$group" ]]; then
        sync_group "$group" "$dry_run" "$auto_yes"

    else
        # Menu interativo
        local options=()
        while IFS= read -r g; do
            local src_url
            src_url=$(get_source "$g" 2)
            local tgt_count
            tgt_count=$(get_targets "$g" | grep -c . 2>/dev/null || echo 0)
            options+=("$g  ·  ${src_url}  (${tgt_count} targets)")
        done <<< "$all_groups"
        options+=("Todos os grupos")

        local selected
        selected=$(brand_menu_arrows "Kit a sincronizar:" "${options[@]}")

        local n_groups
        n_groups=$(echo "$all_groups" | wc -l | tr -d ' ')

        case "$selected" in
            254|255) exit 0 ;;
            *)
                if [[ "$selected" -eq "$n_groups" ]]; then
                    # "Todos os grupos"
                    while IFS= read -r g; do
                        sync_group "$g" "$dry_run" "$auto_yes"
                    done <<< "$all_groups"
                else
                    local chosen_group
                    chosen_group=$(echo "$all_groups" | sed -n "$(( selected + 1 ))p")
                    sync_group "$chosen_group" "$dry_run" "$auto_yes"
                fi
                ;;
        esac
    fi

    brand_phase_header "SYNC CONCLUÍDO"
    log_success "Sincronização de cores e fontes finalizada"
    echo
}

main "$@"
