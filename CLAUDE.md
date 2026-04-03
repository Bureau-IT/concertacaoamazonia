# CLAUDE.md — Concertação Amazônica (Docker Dev)

Site WordPress Multisite do projeto Uma Concertação Pela Amazônia.
Para padrões gerais de operação de sites, ver [sites/CLAUDE.md](../CLAUDE.md).


## Ambientes


### Desenvolvimento Local

| Item | Valor |
|------|-------|
| URL HTTPS | `https://cambrasmax.local:8484` |
| URL HTTP | `http://cambrasmax.local:8084` (redireciona para HTTPS) |
| MySQL externo | `localhost:3310` |
| Redis | `localhost:6383` |
| Stack name | `concertacao` |

#### Cloudflare Tunnel

Esse tunnel garante acesso externo ao ambiente de desenvolvimento.
O tunnel está **habilitado** (`CF_TUNNEL_ENABLED=true`) e expõe o site externamente em:

```
https://concertacao.bureau-it.com
```

### Site em Produção

https://concertacaoamazonia.com.br


## Multisite (Subdirectory Mode)

WordPress Multisite com path-based routing:

| blog_id | URL | Tabelas |
|---------|-----|---------|
| 1 | `concertacaoamazonia.com.br/` | `wp_*` |
| 2 | `concertacaoamazonia.com.br/cultura/` | `wp_2_*` |

- Uploads gerenciados pelo plugin **Network Media Library** (`network-media-library/`), que unifica as bibliotecas de mídia de todos os blogs — não acessar uploads por blog diretamente
- Nginx tem rewrites específicos para multisite (wp-admin, wp-includes, wp-content, ms-files)
- **Sempre usar `--url=` no WP-CLI** para operações em subsites:
  ```bash
  std wp --url="https://cambrasmax.local:8484/cultura/" option get siteurl
  ```

## SSH Produção

```bash
# HML (IP: 52.67.96.50, t3.xlarge, running) — único ambiente v2 ativo
ssh concertacaoamazonia.com.br-prod-sa
```

**Características do Produção:**
- nginx na porta 80, SSL termina no Load Balancer (não no servidor)
- Banco: Aurora RDS externo (sem MySQL local)
- DB name: `wp_concertacao_20250316`, user: `concertacao-v2`
- Aurora endpoint: `amazonia-aurora-db-cluster.cluster-cbh7rhtadzwg.sa-east-1.rds.amazonaws.com`
- Health check na porta 8080 para o Load Balancer
- Redis compartilhado — não permite `FLUSHDB`

WP_ROOT: `/var/www/concertacaoamazonia.com.br`

## mu-plugins específicos deste site

Além dos mu-plugins padrão, este site tem:

| Arquivo | Função |
|---------|--------|
| `bit-dropdown-btn.php` | Botão dropdown customizado |
| `bit-elementor-espiral-widget.php` | Widget Elementor para a espiral SVG |
| `bit-wpml-circle.php` | Integração WPML com circle menu |
| `bit-crossblog-attachment-fix.php` | Fix cross-blog para attachments do blog 1 em contexto de blog 2 (URL, path, download, gallery) |
| `gallery-attachment-title.php` | Fallback para título de attachment do blog 1 em contexto de blog 2 (lightbox captions) |
| `tunnel-url-rewrite.php` | Rewrite de URLs no modo tunnel |
| `bit-tec-cache.php` | Cache 24h de `tribe_get_option('previous_ecp_versions')` — elimina DB query + `usort()` custoso a cada request em `tribe_events_is_new_install()` (spike CPU 02/04/2026) |

## Banco de Dados

- MySQL 8.0 local (container) — imagem `mysql:8.0`
- **Não** usa Aurora RDS (somente HML/Prod usam)
- JetEngine CCT de participantes: tabela `wp_jet_cct_participantes_cct` (1.287 registros)

**Produção (Aurora MySQL):**
- Engine: Aurora MySQL `8.0.42` (verificado 2026-03-31)
- Endpoint: `amazonia-aurora-db-cluster.cluster-cbh7rhtadzwg.sa-east-1.rds.amazonaws.com`
- DB name: `wp_concertacao_20250316` · user: `concertacao-v2`
- ⚠️ Comportamentos podem diferir do MySQL 8.0 local em charset/collation/sql_mode — testar migrações em HML antes de prod

## Plugins Críticos

- Elementor + Elementor Pro
- JetEngine + extensões (CCT participantes, listagens, filtros)
- The Events Calendar
- WPML (multilíngue)
- WP Rocket + Redis (cache)
- S3 Uploads (midia — **desligado em produção**, filesystem EC2)
- EWWW Image Optimizer
- Network Media Library (multisite)

## WebP Automático

O nginx serve `.webp` automaticamente quando o browser suporta (`Accept: image/webp`).
Arquivos gerados com nome `imagem.jpg.webp` (não `imagem.webp`).

## Deploy de Tema em Produção

### Procedimento obrigatório após rsync de arquivos PHP

```bash
# 1. Rsync do tema (executar a partir do diretório do site)
rsync -avz --delete \
  wordpress/wp-content/themes/hello-elementor-child/ \
  concertacaoamazonia.com.br-prod-sa:/var/www/concertacaoamazonia.com.br/wp-content/themes/hello-elementor-child/

# 2. OBRIGATÓRIO — Recarregar PHP-FPM (WP-CLI não limpa o pool FPM)
ssh concertacaoamazonia.com.br-prod-sa "sudo systemctl reload php8.3-fpm"

# 3. Invalidar cache WP Rocket da página alterada
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br rocket_clean_post --post_id=<ID>"
```

**Por que php-fpm reload é necessário:** `wp eval 'opcache_reset()'` roda no SAPI CLI — OPcache isolado do pool FPM (SAPI fpm-fcgi). Sem o reload, PHP-FPM continua servindo bytecode da versão anterior. Sintoma: mudanças PHP refletem via WP-CLI mas não no browser.

**Cache em dev:** `opcache.validate_timestamps=1` + `revalidate_freq=2s` — OPcache invalida automaticamente quando mtime do arquivo muda. Reload manual não é necessário em dev.

### Paridade Redis dev/prod

`object-cache.php` existe em dev. Status do plugin `redis-cache` pode divergir após reimport de banco de produção (onde está ATIVO):

```bash
std wp plugin list --name=redis-cache --fields=name,status
std wp eval "echo wp_using_ext_object_cache() ? 'Redis ATIVO' : 'Redis INATIVO';"
```

Se dev tiver Redis inativo mas prod ativo, customizações dependentes de filtros de cache do TEC (ex: Month View, Week View) podem não ser exercitadas em dev. Ativar para validar: `std wp plugin activate redis-cache && std wp redis enable`.

## WPML — Agente Poliglota

Para qualquer operação de tradução:
- Invocar `/wpml-translate` — o agente conhece a configuração multisite deste site
- **Sempre especificar URL do subsite** no WP-CLI (sem `--url=` opera no blog 1 silenciosamente)
  - Blog 1 (raiz): `cambrasmax.local:8484`
  - Blog 2 (/cultura/): `cambrasmax.local:8484/cultura/`
- Redis FLUSHDB proibido em prod — agente usa `wp cache flush` via WP-CLI
- mu-plugin `bit-wpml-circle.php` integra WPML com o circle menu — não remover
