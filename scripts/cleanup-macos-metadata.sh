#!/usr/bin/env bash
# ==============================================================================
# cleanup-macos-metadata.sh - Remove metadados do macOS de arquivos
# Autor: Daniel Cambría + Warp
# Data: 2025-11-02
# Versão: 1.0.0
#
# Descrição:
#   Remove arquivos de metadados do macOS (.DS_Store, ._*, etc) e
#   limpa extended attributes que causam problemas no WordPress
#
# Uso:
#   ./cleanup-macos-metadata.sh [diretório]
#
# Exemplo:
#   ./cleanup-macos-metadata.sh ../wordpress/wp-content
# ==============================================================================

set -Eeuo pipefail

TARGET_DIR="${1:-../wordpress/wp-content}"
LOG_PREFIX="[$(date +'%Y-%m-%d %H:%M:%S')] [CLEANUP]"

log() { echo "${LOG_PREFIX} $*" >&2; }
_die() { log "❌ $*"; exit 1; }

# Verificar se o diretório existe
[[ -d "$TARGET_DIR" ]] || _die "Diretório não encontrado: $TARGET_DIR"

log "ℹ️  Limpando metadados do macOS em: $TARGET_DIR"

# Contar arquivos antes
total_files_before=$(find "$TARGET_DIR" -type f | wc -l)
log "ℹ️  Total de arquivos antes: $total_files_before"

# Remover arquivos .DS_Store
ds_store_count=$(find "$TARGET_DIR" -name ".DS_Store" -type f | wc -l)
if [[ $ds_store_count -gt 0 ]]; then
    log "ℹ️  Removendo $ds_store_count arquivos .DS_Store"
    find "$TARGET_DIR" -name ".DS_Store" -type f -delete
fi

# Remover arquivos ._* (resource forks)
resource_fork_count=$(find "$TARGET_DIR" -name "._*" -type f | wc -l)
if [[ $resource_fork_count -gt 0 ]]; then
    log "ℹ️  Removendo $resource_fork_count arquivos ._* (resource forks)"
    find "$TARGET_DIR" -name "._*" -type f -delete
fi

# Remover diretórios __MACOSX
macosx_dir_count=$(find "$TARGET_DIR" -name "__MACOSX" -type d | wc -l)
if [[ $macosx_dir_count -gt 0 ]]; then
    log "ℹ️  Removendo $macosx_dir_count diretórios __MACOSX"
    find "$TARGET_DIR" -name "__MACOSX" -type d -exec rm -rf {} + 2>/dev/null || true
fi

# Remover arquivos .AppleDouble
appledouble_count=$(find "$TARGET_DIR" -name ".AppleDouble" | wc -l)
if [[ $appledouble_count -gt 0 ]]; then
    log "ℹ️  Removendo $appledouble_count arquivos .AppleDouble"
    find "$TARGET_DIR" -name ".AppleDouble" -exec rm -rf {} + 2>/dev/null || true
fi

# Remover arquivos .LSOverride
lsoverride_count=$(find "$TARGET_DIR" -name ".LSOverride" -type f | wc -l)
if [[ $lsoverride_count -gt 0 ]]; then
    log "ℹ️  Removendo $lsoverride_count arquivos .LSOverride"
    find "$TARGET_DIR" -name ".LSOverride" -type f -delete
fi

# Se estiver no macOS, remover extended attributes
if [[ "$(uname)" == "Darwin" ]]; then
    log "ℹ️  Removendo extended attributes (xattr) dos arquivos"
    xattr_count=0
    while IFS= read -r file; do
        if xattr "$file" >/dev/null 2>&1; then
            xattr -c "$file" 2>/dev/null && ((xattr_count++)) || true
        fi
    done < <(find "$TARGET_DIR" -type f)
    log "ℹ️  Extended attributes removidos de $xattr_count arquivos"
fi

# Contar arquivos depois
total_files_after=$(find "$TARGET_DIR" -type f | wc -l)
files_removed=$((total_files_before - total_files_after))

log "ℹ️  Total de arquivos depois: $total_files_after"
log "✅ Limpeza concluída! $files_removed arquivos removidos"
