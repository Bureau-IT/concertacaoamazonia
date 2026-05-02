#!/usr/bin/env bash
#
# sync-elementor-templates.sh - Sincroniza templates Header/Footer do Elementor Theme Builder
#
# Autor: Daniel Cambría + Claude Code
# Versão: 1.1.0
# Data: 2026-03-10
#
# Descrição:
#   Copia _elementor_data e _elementor_page_settings de templates Header/Footer
#   entre sites/subsites conforme sync-elementor-templates.conf.
#   Suporta múltiplos grupos, backup automático e rollback interativo.
#
#   Metadados sincronizados:
#     _elementor_data          — Conteúdo principal do template
#     _elementor_page_settings — Configurações do template (largura, etc.)
#     _elementor_template_type — Sempre definido (header/footer)
#
#   Metadados NÃO sincronizados (cada site tem os seus):
#     _elementor_conditions    — Condições de exibição
#     _elementor_css           — Gerado automaticamente pelo flush
#
# Uso:
#   ./sync-elementor-templates.sh                    # menu interativo
#   ./sync-elementor-templates.sh --group=www2       # grupo específico
#   ./sync-elementor-templates.sh --all              # todos os grupos
#   ./sync-elementor-templates.sh --dry-run          # simula sem alterar
#   ./sync-elementor-templates.sh --yes              # sem confirmação interativa
#   ./sync-elementor-templates.sh --rollback         # rollback interativo
#   ./sync-elementor-templates.sh --rollback --group=www2
#   ./sync-elementor-templates.sh list               # lista templates em todos os targets
#   ./sync-elementor-templates.sh --help

set -euo pipefail

# ==============================================================================
# CONSTANTES
# ==============================================================================

SCRIPT_DIR_SELF="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR_SELF
readonly CONF_FILE="$SCRIPT_DIR_SELF/sync-elementor-templates.conf"
readonly COLORS_SH="$SCRIPT_DIR_SELF/../../../../contexto/colors.sh"
readonly BRAND_HELPER="$SCRIPT_DIR_SELF/../../../../helpers/brand-helper.sh"
readonly BACKUP_DIR="$SCRIPT_DIR_SELF/backups/elementor-templates"

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

log_info()    { printf '%b  ℹ %s%b\n' "$FG_BLUE"   "$*" "$ENDC"; }
log_success() { printf '%b  ✓ %s%b\n' "$FG_GREEN"  "$*" "$ENDC"; }
log_warn()    { printf '%b  ⚠ %s%b\n' "$FG_ORANGE" "$*" "$ENDC"; }
log_error()   { printf '%b  ✗ %s%b\n' "$FG_RED"    "$*" "$ENDC" >&2; }

# ==============================================================================
# CONF HELPERS
# ==============================================================================

# Lista nomes de grupos definidos no conf
get_groups() {
    grep -E '^SOURCE_' "$CONF_FILE" 2>/dev/null \
        | sed 's/^SOURCE_//;s/|.*//' \
        | sort -u
}

# Retorna campo do SOURCE de um grupo (field: 1=container, 2=url)
get_source() {
    local group="$1"
    local field="$2"
    grep -E "^SOURCE_${group}\|" "$CONF_FILE" 2>/dev/null \
        | head -1 \
        | cut -d'|' -f$(( field + 1 ))
}

# Retorna id do template de um tipo num grupo (tipo: header|footer)
get_tmpl_id() {
    local group="$1"
    local type="$2"
    grep -E "^TMPL_${group}\|${type}\|" "$CONF_FILE" 2>/dev/null \
        | head -1 \
        | cut -d'|' -f3
}

# Retorna linhas TARGET de um grupo: container|url|header=ID|footer=ID
get_targets() {
    local group="$1"
    grep -E "^TARGET_${group}\|" "$CONF_FILE" 2>/dev/null \
        | sed 's/^TARGET_[^|]*|//'
}

# Extrai o ID de um campo header=X ou footer=X (retorna vazio se não encontrado)
parse_tmpl_field() {
    local fields="$1"  # ex: "header=89307|footer=89361"
    local type="$2"    # header, footer, header-en, footer-en
    echo "$fields" | tr '|' '\n' | grep "^${type}=" | cut -d'=' -f2 || true
}

# ==============================================================================
# VERIFICAÇÃO DE SVGs
# ==============================================================================

# Verifica se os arquivos SVG referenciados no template header existem nos targets
# Uso: verify_logo_svgs group src_header_json
verify_logo_svgs() {
    local group="$1"
    local src_header_json="$2"

    [[ -z "$src_header_json" ]] && return 0

    # Extrair URLs de SVG do JSON do header (grep pode retornar exit 1 se não encontrar)
    local svg_urls
    svg_urls=$(echo "$src_header_json" | grep -oE 'https?://[^"]+\.svg' 2>/dev/null | sort -u || true)
    [[ -z "$svg_urls" ]] && return 0

    local targets
    targets=$(get_targets "$group")

    while IFS='|' read -r tgt_container tgt_url tgt_fields <&3; do
        [[ -z "$tgt_container" ]] && continue

        while IFS= read -r svg_url; do
            [[ -z "$svg_url" ]] && continue
            # Converter URL → caminho no container
            # Ex: https://cambrasmax.local:8484/wp-content/themes/X/svg/logo-br.svg
            #   → /var/www/html/wp-content/themes/X/svg/logo-br.svg
            local svg_path
            svg_path=$(echo "$svg_url" | sed 's|https\?://[^/]*/||;s|^|/var/www/html/|')

            if ! docker exec "$tgt_container" test -f "$svg_path" 2>/dev/null; then
                log_warn "SVG ausente em ${tgt_url}: ${svg_path}"
            fi
        done <<< "$svg_urls"

    done 3<<< "$targets"
}

# ==============================================================================
# WPML
# ==============================================================================

# Registra relação WPML entre template PT e EN
# Uso: register_wpml_translation container wp_url pt_post_id en_post_id
register_wpml_translation() {
    local container="$1"
    local wp_url="$2"
    local pt_post_id="$3"
    local en_post_id="$4"

    local php_snippet
    php_snippet="\$pt_id = (int) ${pt_post_id};
\$en_id = (int) ${en_post_id};
\$et = 'post_elementor_library';
global \$wpdb;
\$trid = \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT trid FROM {\$wpdb->prefix}icl_translations WHERE element_type=%s AND element_id=%d LIMIT 1\",
    \$et, \$pt_id
));
if (!\$trid) {
    \$max = (int) \$wpdb->get_var(\"SELECT MAX(trid) FROM {\$wpdb->prefix}icl_translations\");
    \$trid = \$max + 1;
    \$wpdb->insert(\"{\$wpdb->prefix}icl_translations\",
        ['element_type'=>\$et,'element_id'=>\$pt_id,'trid'=>\$trid,'language_code'=>'pt-br','source_language_code'=>NULL],
        ['%s','%d','%d','%s','%s']
    );
}
\$exists = (int) \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT COUNT(*) FROM {\$wpdb->prefix}icl_translations WHERE element_type=%s AND element_id=%d\",
    \$et, \$en_id
));
if (!\$exists) {
    \$wpdb->insert(\"{\$wpdb->prefix}icl_translations\",
        ['element_type'=>\$et,'element_id'=>\$en_id,'trid'=>\$trid,'language_code'=>'en','source_language_code'=>'pt-br'],
        ['%s','%d','%d','%s','%s']
    );
    echo \"WPML: EN template \$en_id vinculado ao trid \$trid (PT: \$pt_id)\\n\";
} else {
    echo \"WPML: EN template \$en_id ja registrado\\n\";
}"

    docker exec -u www-data "$container" \
        wp eval "$php_snippet" \
        --url="$wp_url" 2>/dev/null || true
}

# ==============================================================================
# EXPORTAR TEMPLATE
# ==============================================================================

# Exporta _elementor_data e _elementor_page_settings de um post
# Retorna JSON com duas chaves: data, page_settings
export_template() {
    local container="$1"
    local wp_url="$2"
    local post_id="$3"

    local tmp_php="/tmp/elementor-tmpl-export-$$.php"

    docker exec -i "$container" bash -c "cat > '$tmp_php'" << PHPEOF
<?php
\$id = (int) ${post_id};
\$d  = get_post_meta(\$id, '_elementor_data', true);
\$ps = get_post_meta(\$id, '_elementor_page_settings', true);
if (!is_array(\$ps)) { \$ps = []; }
echo json_encode(['data' => \$d, 'page_settings' => \$ps]);
PHPEOF

    docker exec -u www-data "$container" \
        wp eval-file "$tmp_php" \
        --url="$wp_url" 2>/dev/null
    docker exec "$container" rm -f "$tmp_php" 2>/dev/null || true
}

# ==============================================================================
# BACKUP
# ==============================================================================

_url_to_slug() {
    echo "$1" | sed 's|https://||;s|http://||;s|[/:]|-|g;s|\.|-|g;s|-\+$||'
}

# Cria backup do template antes do sync
# Uso: backup_template container wp_url post_id type
# Retorna: caminho do arquivo (stdout)
backup_template() {
    local container="$1"
    local wp_url="$2"
    local post_id="$3"
    local type="$4"

    mkdir -p "$BACKUP_DIR"

    local ts slug backup_file
    ts=$(date '+%Y%m%d-%H%M%S')
    slug=$(_url_to_slug "$wp_url")
    backup_file="${BACKUP_DIR}/${slug}--${type}${post_id}--${ts}.json"

    local json
    if ! json=$(export_template "$container" "$wp_url" "$post_id" 2>/dev/null); then
        log_warn "Não foi possível criar backup do template ${post_id} (${wp_url})" >&2
        return 1
    fi

    echo "$json" > "$backup_file"
    log_info "Backup: backups/elementor-templates/$(basename "$backup_file")" >&2
    echo "$backup_file"
}

# ==============================================================================
# APLICAR SYNC
# ==============================================================================

# Aplica _elementor_data e _elementor_page_settings em um target via eval-file
apply_template_sync() {
    local container="$1"
    local wp_url="$2"
    local post_id="$3"
    local type="$4"
    local source_json="$5"  # JSON com chaves: data, page_settings

    local tmp_data="/tmp/elementor_sync_data_$$.json"
    local tmp_ps="/tmp/elementor_sync_ps_$$.json"
    local tmp_php="/tmp/elementor_sync_apply_$$.php"

    # Extrair as duas chaves do JSON de exportação
    local raw_data raw_ps
    raw_data=$(echo "$source_json" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['data'])" 2>/dev/null)
    raw_ps=$(echo "$source_json" | python3 -c "import sys,json; d=json.load(sys.stdin); print(json.dumps(d.get('page_settings', {})))" 2>/dev/null)

    # Escrever dados no container (preserva JSON escaping)
    echo "$raw_data" | docker exec -i "$container" bash -c "cat > '$tmp_data'"
    echo "$raw_ps"   | docker exec -i "$container" bash -c "cat > '$tmp_ps'"

    # Escrever arquivo PHP de aplicação
    docker exec -i "$container" bash -c "cat > '$tmp_php'" << PHPEOF
<?php
\$id   = (int) ${post_id};
\$type = '${type}';
\$d    = file_get_contents('${tmp_data}');
\$ps   = json_decode(file_get_contents('${tmp_ps}'), true);
if (!\$d) { echo "ERRO: arquivo de dados vazio\n"; exit(1); }
if (!is_array(\$ps)) { \$ps = []; }
update_post_meta(\$id, '_elementor_data', wp_slash(\$d));
update_post_meta(\$id, '_elementor_page_settings', \$ps);
update_post_meta(\$id, '_elementor_template_type', \$type);
@unlink('${tmp_data}');
@unlink('${tmp_ps}');
echo "OK: Template \$id atualizado (\$type)\n";
PHPEOF

    local result
    result=$(docker exec -u www-data "$container" \
        wp eval-file "$tmp_php" \
        --url="$wp_url" 2>/dev/null)
    docker exec "$container" rm -f "$tmp_php" 2>/dev/null || true

    echo "$result"
    [[ "$result" == OK:* ]]
}

# Cria novo post elementor_library e aplica dados do source
create_and_sync_template() {
    local container="$1"
    local wp_url="$2"
    local type="$3"
    local title="$4"
    local source_json="$5"

    # Criar post via WP-CLI
    local new_id
    new_id=$(docker exec -u www-data "$container" \
        wp post create \
        --post_type=elementor_library \
        --post_title="$title" \
        --post_status=publish \
        --url="$wp_url" \
        --porcelain \
        2>/dev/null) || {
            log_error "Falha ao criar post elementor_library"
            return 1
        }

    log_info "Novo post criado: ID=${new_id}"

    # Aplicar dados
    apply_template_sync "$container" "$wp_url" "$new_id" "$type" "$source_json"
    echo "$new_id"
}

# ==============================================================================
# FLUSH CSS
# ==============================================================================

flush_elementor_css() {
    local container="$1"
    local wp_url="$2"

    log_info "Regenerando CSS do Elementor em ${wp_url}..."

    docker exec -u www-data "$container" \
        wp eval --url="$wp_url" \
        '\Elementor\Plugin::$instance->files_manager->clear_cache();
echo "CSS cache limpo via PHP\n";' \
        2>/dev/null || true

    docker exec -u www-data "$container" \
        wp eval --url="$wp_url" \
        'if (function_exists("rocket_clean_domain")) {
    rocket_clean_domain();
    rocket_clean_minify();
    echo "WP Rocket limpo\n";
}' \
        2>/dev/null || true

    docker exec -u www-data "$container" \
        wp cache flush --url="$wp_url" 2>/dev/null \
        && log_success "Cache Redis/Object flushed" || true
}

# ==============================================================================
# LIST
# ==============================================================================

cmd_list() {
    brand_phase_header "TEMPLATES — SITUAÇÃO ATUAL"
    echo

    local all_groups
    all_groups=$(get_groups)

    while IFS= read -r group; do
        local src_container src_url
        src_container=$(get_source "$group" 1)
        src_url=$(get_source "$group" 2)

        printf '%b  ── Grupo: %s ──%b\n' "$FG_BLUE" "$group" "$ENDC"
        printf '     Fonte: %s\n' "$src_url"
        echo

        # Source templates
        for type in header footer header-en footer-en; do
            local src_id
            src_id=$(get_tmpl_id "$group" "$type")
            [[ -z "$src_id" || "$src_id" == "0" ]] && continue

            local title
            title=$(docker exec -u www-data "$src_container" \
                wp post get "$src_id" --field=post_title \
                --url="$src_url" 2>/dev/null || echo "(erro)")

            printf '     %bFonte%b %s  ID=%-6s  %s\n' "$FG_GREEN" "$ENDC" "$type" "$src_id" "$title"
        done
        echo

        # Target templates
        local targets
        targets=$(get_targets "$group")

        while IFS='|' read -r tgt_container tgt_url tgt_fields <&3; do
            [[ -z "$tgt_container" ]] && continue
            printf '     %bTarget%b %s\n' "$FG_ORANGE" "$ENDC" "$tgt_url"

            for type in header footer header-en footer-en; do
                local tgt_id
                tgt_id=$(parse_tmpl_field "$tgt_fields" "$type")
                [[ -z "$tgt_id" ]] && continue  # tipo não configurado para este target
                [[ "$tgt_id" == "0" ]] && { printf '       %s: (criar)\n' "$type"; continue; }

                local title
                title=$(docker exec -u www-data "$tgt_container" \
                    wp post get "$tgt_id" --field=post_title \
                    --url="$tgt_url" 2>/dev/null || echo "(erro)")

                local tmpl_type
                tmpl_type=$(docker exec -u www-data "$tgt_container" \
                    wp eval --url="$tgt_url" \
                    "echo get_post_meta(${tgt_id}, '_elementor_template_type', true);" \
                    2>/dev/null || echo "?")

                printf '       %s  ID=%-6s  %-8s  %s\n' "$type" "$tgt_id" "[$tmpl_type]" "$title"
            done
            echo

        done 3<<< "$targets"

    done <<< "$all_groups"
}

# ==============================================================================
# ROLLBACK
# ==============================================================================

cmd_rollback_templates() {
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

    while IFS='|' read -r tgt_container tgt_url tgt_fields <&3; do
        [[ -z "$tgt_container" ]] && continue

        for type in header footer header-en footer-en; do
            local tgt_id
            tgt_id=$(parse_tmpl_field "$tgt_fields" "$type")
            [[ -z "$tgt_id" || "$tgt_id" == "0" ]] && continue

            brand_phase_header "ROLLBACK ${type^^}: $tgt_url (ID=$tgt_id)"

            local slug
            slug=$(_url_to_slug "$tgt_url")

            local -a backup_files=()
            while IFS= read -r f; do
                backup_files+=("$f")
            done < <(find "$BACKUP_DIR" -maxdepth 1 -name "${slug}--${type}${tgt_id}--*.json" 2>/dev/null | sort -r | head -10)

            if [[ ${#backup_files[@]} -eq 0 ]]; then
                log_warn "Nenhum backup encontrado para ${type} ID=${tgt_id} (${tgt_url})"
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

            local elementor_type_rb="${type%%-en}"
            brand_phase_header "RESTAURANDO: ${type^^} ${tgt_url}"
            if apply_template_sync "$tgt_container" "$tgt_url" "$tgt_id" "$elementor_type_rb" "$backup_json"; then
                log_success "Template ${type} ID=${tgt_id} restaurado"
                flush_elementor_css "$tgt_container" "$tgt_url"
            else
                log_error "Falha ao restaurar ${type} ID=${tgt_id}"
            fi
            echo
        done

    done 3<<< "$targets"
}

# ==============================================================================
# SYNC DE UM GRUPO
# ==============================================================================

sync_group() {
    local group="$1"
    local dry_run="$2"
    local auto_yes="$3"

    local src_container src_url
    src_container=$(get_source "$group" 1)
    src_url=$(get_source "$group" 2)

    if [[ -z "$src_container" || -z "$src_url" ]]; then
        log_error "Grupo não encontrado no conf: $group"
        return 1
    fi

    brand_phase_header "GRUPO: $group"
    log_info "Fonte: ${src_url}"
    echo

    local targets
    targets=$(get_targets "$group")
    if [[ -z "$targets" ]]; then
        log_warn "Nenhum target no grupo: $group"
        return 0
    fi

    # Exportar templates do source (PT e EN)
    declare -A src_jsons=()

    for type in header footer header-en footer-en; do
        local src_id
        src_id=$(get_tmpl_id "$group" "$type")
        if [[ -z "$src_id" || "$src_id" == "0" ]]; then
            [[ "$type" == "header" || "$type" == "footer" ]] && \
                log_warn "Template ${type} não configurado no grupo ${group}"
            continue
        fi

        log_info "Exportando ${type} (ID=${src_id}) do source..."
        local json
        if ! json=$(export_template "$src_container" "$src_url" "$src_id" 2>/dev/null) || [[ -z "$json" ]]; then
            log_error "Falha ao exportar template ${type} ID=${src_id}"
            continue
        fi

        src_jsons["$type"]="$json"
        log_success "Template ${type} exportado (ID=${src_id})"
    done

    echo

    # Verificar SVGs referenciados no header fonte
    if [[ -n "${src_jsons[header]:-}" ]]; then
        verify_logo_svgs "$group" "${src_jsons[header]}"
    fi

    # Iterar targets
    while IFS='|' read -r tgt_container tgt_url tgt_fields <&3; do
        [[ -z "$tgt_container" ]] && continue

        log_info "Target: ${tgt_url}"

        if [[ "$dry_run" == "true" ]]; then
            for type in header footer header-en footer-en; do
                local tgt_id
                tgt_id=$(parse_tmpl_field "$tgt_fields" "$type")
                [[ -z "$tgt_id" ]] && continue  # tipo não configurado para este target
                [[ -z "${src_jsons[$type]:-}" ]] && continue  # sem fonte → skip
                if [[ "$tgt_id" == "0" ]]; then
                    log_warn "  Dry-run: ${type} seria CRIADO em ${tgt_url}"
                else
                    log_warn "  Dry-run: ${type} ID=${tgt_id} seria atualizado em ${tgt_url}"
                fi
            done
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

        for type in header footer header-en footer-en; do
            local tgt_id
            tgt_id=$(parse_tmpl_field "$tgt_fields" "$type")
            [[ -z "$tgt_id" ]] && continue  # tipo não configurado para este target (PT-only)
            [[ -z "${src_jsons[$type]:-}" ]] && { log_warn "Sem dados de source para ${type}, pulando"; continue; }

            # Determinar tipo Elementor real (sem sufixo -en)
            local elementor_type="${type%%-en}"

            if [[ -z "$tgt_id" || "$tgt_id" == "0" ]]; then
                # Criar novo post
                log_info "Criando novo template ${type} em ${tgt_url}..."
                local src_id_for_type new_id
                src_id_for_type=$(get_tmpl_id "$group" "$type")
                local src_title
                src_title=$(docker exec -u www-data "$src_container" \
                    wp post get "$src_id_for_type" --field=post_title \
                    --url="$src_url" 2>/dev/null || echo "Template ${type}")

                # tail -1 garante que só o ID numérico seja capturado (último echo da função)
                new_id=$(create_and_sync_template "$tgt_container" "$tgt_url" "$elementor_type" "$src_title" "${src_jsons[$type]}" 2>/dev/null | tail -1)
                if [[ -n "$new_id" ]]; then
                    log_success "${type} criado: ID=${new_id}"
                    log_warn "ATENÇÃO: Atualize sync-elementor-templates.conf com ${type}=${new_id} para ${tgt_url}"

                    # Registrar relação WPML se for tipo EN e tiver o PT correspondente
                    if [[ "$type" == *-en ]]; then
                        local pt_type="${type%%-en}"
                        local pt_id
                        pt_id=$(parse_tmpl_field "$tgt_fields" "$pt_type")
                        if [[ -n "$pt_id" && "$pt_id" != "0" ]]; then
                            log_info "Registrando relação WPML: PT=${pt_id} → EN=${new_id}"
                            register_wpml_translation "$tgt_container" "$tgt_url" "$pt_id" "$new_id"
                        fi
                    fi
                else
                    log_error "Falha ao criar template ${type} em ${tgt_url}"
                fi
            else
                # Atualizar post existente
                log_info "Atualizando ${type} ID=${tgt_id} em ${tgt_url}..."
                backup_template "$tgt_container" "$tgt_url" "$tgt_id" "$elementor_type"

                if apply_template_sync "$tgt_container" "$tgt_url" "$tgt_id" "$elementor_type" "${src_jsons[$type]}"; then
                    log_success "${type} atualizado: ID=${tgt_id}"
                else
                    log_error "Falha ao atualizar ${type} ID=${tgt_id}"
                fi
            fi
        done

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
$(brand_help_title "SYNC-ELEMENTOR-TEMPLATES - Sincronização de Header e Footer")

$(brand_help_section "DESCRIÇÃO:")
  Copia templates Header/Footer do Elementor Theme Builder entre sites e subsites,
  mantendo a identidade visual sincronizada. Configurado em sync-elementor-templates.conf.

$(brand_help_section "METADADOS SINCRONIZADOS:")
  ${FG_GREEN}_elementor_data${ENDC}           Conteúdo principal do template
  ${FG_GREEN}_elementor_page_settings${ENDC}  Configurações (largura, padding, etc.)
  ${FG_GREEN}_elementor_template_type${ENDC}  Tipo do template (header/footer)

$(brand_help_section "METADADOS NÃO SINCRONIZADOS:")
  ${FG_ORANGE}_elementor_conditions${ENDC}    Condições de exibição (cada site tem as suas)
  ${FG_ORANGE}_elementor_css${ENDC}           Gerado automaticamente pelo flush

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
$(brand_help_command "./sync-elementor-templates.sh" "Menu interativo")
$(brand_help_command "./sync-elementor-templates.sh --group=www2" "Sincroniza grupo www2")
$(brand_help_command "./sync-elementor-templates.sh --all" "Todos os grupos")
$(brand_help_command "./sync-elementor-templates.sh --dry-run" "Simula sem alterar")
$(brand_help_command "./sync-elementor-templates.sh --yes" "Sem confirmação interativa")
$(brand_help_command "./sync-elementor-templates.sh --rollback" "Rollback interativo")
$(brand_help_command "./sync-elementor-templates.sh list" "Listar templates e IDs")
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
    local do_list=false

    for arg in "$@"; do
        case "$arg" in
            --dry-run)   dry_run=true ;;
            --yes|-y)    auto_yes=true ;;
            --all)       do_all=true ;;
            --group=*)   group="${arg#*=}" ;;
            --rollback)  do_rollback=true ;;
            list)        do_list=true ;;
            --help|-h)   show_help; exit 0 ;;
            *)
                log_error "Argumento desconhecido: $arg"
                echo "Use --help para ajuda"
                exit 1
                ;;
        esac
    done

    if [[ ! -f "$CONF_FILE" ]]; then
        log_error "Arquivo de configuração não encontrado: $CONF_FILE"
        exit 1
    fi

    # Listar templates
    if [[ "$do_list" == "true" ]]; then
        cmd_list
        exit 0
    fi

    # Rollback
    if [[ "$do_rollback" == "true" ]]; then
        brand_phase_header "ROLLBACK ELEMENTOR TEMPLATES"
        echo
        cmd_rollback_templates "$group"
        brand_phase_header "ROLLBACK CONCLUÍDO"
        echo
        exit 0
    fi

    brand_phase_header "SYNC ELEMENTOR TEMPLATES — HEADER E FOOTER"
    [[ "$dry_run" == "true" ]] && log_warn "Modo DRY-RUN ativado"
    echo

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
        selected=$(brand_menu_arrows "Templates a sincronizar:" "${options[@]}")

        local n_groups
        n_groups=$(echo "$all_groups" | wc -l | tr -d ' ')

        case "$selected" in
            254|255) exit 0 ;;
            *)
                if [[ "$selected" -eq "$n_groups" ]]; then
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
    log_success "Sincronização de templates finalizada"
    echo
}

main "$@"
