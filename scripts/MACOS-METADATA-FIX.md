# Correção de Metadados do macOS em Backups WordPress

## Problema

Ao transferir arquivos do macOS para ambientes Linux (WSL2, Docker em Windows), metadados específicos do macOS podem ser incluídos nos arquivos tar.gz, causando problemas como:

- Arquivos `._*` (resource forks)
- Arquivos `.DS_Store`
- Extended attributes (xattr)
- Metadados `com.apple.provenance`

Esses metadados podem aparecer como lixo binário quando listados ou exibidos no site WordPress.

### Exemplo do erro:
```
Mac OS X  2q�ATTR���com.apple.provenanceX��'��oMac OS X  2��ATTR��+�com.apple.provenance� com.docker.grpcfuse.ownershipX��'��o{"UID":33,"GID":33,"mode":10000}
```

## Solução

### 1. Correção no script de export (export-wpcontent.sh)

O script foi atualizado para:

**a) Adicionar flags específicas do macOS ao tar:**
```bash
if [[ "$(uname)" == "Darwin" ]]; then
    tar_opts+=( --no-xattrs --no-mac-metadata )
fi
```

**b) Excluir arquivos de metadados:**
```bash
local macos_excludes=(
    ".DS_Store"
    "._*"
    ".AppleDouble"
    ".LSOverride"
    "__MACOSX"
)
```

### 2. Limpeza de arquivos existentes

Use o script `cleanup-macos-metadata.sh` para limpar metadados de diretórios já contaminados.

#### No macOS (antes de fazer export):
```bash
cd /Users/dcambria/scripts/concertacao/server-tools/docker-dev/scripts
./cleanup-macos-metadata.sh ../wordpress/wp-content
```

#### No Linux/WSL2 (depois de importar):
```bash
cd ~/server-tools/docker-dev/scripts
./cleanup-macos-metadata.sh ../wordpress/wp-content
```

### 3. Verificação

Após a limpeza, verifique se não há mais metadados:

```bash
# Verificar arquivos .DS_Store
find wp-content -name ".DS_Store"

# Verificar resource forks
find wp-content -name "._*"

# No macOS, verificar extended attributes
find wp-content -type f -exec xattr -l {} \; | grep -v "^$"
```

## Prevenção

Para evitar que o problema ocorra novamente:

1. **Sempre use o script atualizado** `export-wpcontent.sh` para criar backups
2. **Execute o cleanup antes de fazer export no macOS:**
   ```bash
   ./cleanup-macos-metadata.sh ../wordpress/wp-content
   ./export-wpcontent.sh --save-local
   ```
3. **Configure o .gitignore** para nunca versionar esses arquivos:
   ```
   .DS_Store
   ._*
   .AppleDouble
   .LSOverride
   __MACOSX/
   ```

## Comandos úteis

### Remover metadados manualmente (macOS)
```bash
# Remover extended attributes recursivamente
xattr -cr /caminho/para/diretorio

# Remover .DS_Store
find . -name ".DS_Store" -delete

# Remover resource forks
find . -name "._*" -delete
```

### Verificar se um tar.gz contém metadados
```bash
tar -tzf arquivo.tar.gz | grep -E '\._|\.DS_Store|__MACOSX'
```

## Referências

- [Apple Technical Note TN2099](https://developer.apple.com/library/archive/technotes/tn2099/)
- [GNU tar documentation](https://www.gnu.org/software/tar/manual/html_node/tar.html)
- [Extended attributes on macOS](https://en.wikipedia.org/wiki/Extended_file_attributes#macOS)
