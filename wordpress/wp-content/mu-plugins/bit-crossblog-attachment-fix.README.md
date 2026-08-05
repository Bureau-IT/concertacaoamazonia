# BIT Cross-Blog Attachment Fix

Mu-plugin para WordPress Multisite do projeto Concertação Amazônica que corrige
casos onde o blog 2 (`/cultura/`) referencia attachments armazenados no blog 1
(raiz) via [Network Media Library](https://github.com/humanmade/network-media-library).

## O que o plugin resolve

Em sites multisite com Network Media Library, a biblioteca de mídia é centralizada
no blog 1. Quando o blog 2 referencia esses attachments, alguns hooks do WordPress
core e plugins terceiros (Elementor, WPML) não fazem o `switch_to_blog()` necessário,
gerando URLs erradas. Sintomas:

- `<img srcset>` com `/wp-content/uploads/sites/2/...` que retorna 403 do S3
- Featured image quebrada em listings JetEngine
- Slides do Elementor com background-image vazio em páginas EN/ES (`/cultura/en/*`)
- AJAX inline edit do Admin Columns Pro perdendo featured image cross-blog
- `jet_download` button retornando arquivo errado quando ID é cross-blog
- Lightbox/gallery/audio/video do Elementor mostrando 404 em assets cross-blog

## Como diagnosticar se um bug é dessa categoria

Abra DevTools (F12) na página afetada e cole no console:

```js
({
  total: document.querySelectorAll('img').length,
  broken: [...document.querySelectorAll('img')].filter(i => i.complete && i.naturalWidth === 0 && i.src).length,
  sites_n_in_dom: (document.documentElement.outerHTML.match(/\/sites\/\d+\/uploads\//g) || []).length,
  sample_broken: [...document.querySelectorAll('img')].filter(i => i.complete && i.naturalWidth === 0).slice(0, 3).map(i => (i.currentSrc || i.src).split('/').slice(-3).join('/')),
})
```

Se `broken > 2` ou `sites_n_in_dom > 0`, é cross-blog.

## Hooks principais (visão geral, sem entrar no código)

| # | Filtro | O que faz |
|---|--------|-----------|
| 1-2 | `wp_get_attachment_url`, `get_attached_file` | Resolve URL/path no contexto do blog 1 quando blog atual é 2 |
| 3-8 | `init`, `elementor/widget/before_render_content`, REST, `_thumbnail_id` | Pré-popula cache para diferentes consumidores (download handler, gallery, REST writes, Admin Columns Pro) |
| 9-12 | `wp_get_attachment_image_src`, `wp_get_attachment_url`, `get_attached_file`, `wp_get_attachment_metadata` | Fallback para órfãos WPML duplicate-on-translate (attachments que vivem só em `wp_2_posts`) |
| 13 | `wp_calculate_image_srcset` | Reescreve srcset de órfãos WPML para URLs do blog source |
| 14 | `wp_calculate_image_srcset` | Reescreve srcset de attachments NML cross-blog comuns (caso default, sem WPML) |

## Se isso quebrar (checklist de incidente)

1. **Verificar mu-plugin ativo e versão**
   ```bash
   ssh prod-sa "sudo grep '* Version' /var/www/.../mu-plugins/bit-crossblog-attachment-fix.php"
   ```
   Versão mínima esperada em prod: **1.6.0** (Hooks 1-14).

2. **Validar que constantes batem com o site**
   No início do mu-plugin:
   - `BIT_CROSSBLOG_TARGET_BLOG = 2` (subsite que referencia)
   - `BIT_CROSSBLOG_SOURCE_BLOG = 1` (blog que armazena)
   - `BIT_CROSSBLOG_SOURCE_LANG = 'pt-br'` (idioma source no WPML)

3. **Rodar `/smoke` (gate 26 — wpml_orphan_leak)**
   Detecta automaticamente regressões em 4 páginas EN canários.

4. **Conferir se NML está ativo e configurado**
   ```bash
   wp plugin list --status=active --name=network-media-library
   ```
   Se inativo, mu-plugin vira inerte (degrada graciosamente — não fica pior, mas não fixa cross-blog).

5. **Se nada acima resolveu**: ler o [runbook](../../docs/runbook-crossblog-403.md)
   e abrir ticket no clickup BIT com output do passo 1 do diagnóstico.

## Versionamento

Sempre que modificar o plugin, bumpar o header `Version:` e copiar a nova versão para
`docker-dev/common/mu-plugins/bit-crossblog-attachment-fix.php` no repositório do
server-tools. Commits seguem o padrão `feat(bit-crossblog): ...` ou `fix(bit-crossblog): ...`.

## Limitações conhecidas

- **Single-pair multisite**: foi desenhado para o cenário concertação (blog 1 source +
  blog 2 target). Não cobre topologias com 3+ blogs ou múltiplos targets.
- **WPML dependente**: hooks 9-13 assumem `wp_<N>_icl_translations` para resolver
  órfãos. Se o site não usa WPML, esses hooks viram inertes — Hooks 1-8 + 14 cobrem
  o caso default sem WPML.
- **Não cobre block editor (Gutenberg) puro**: o foco é Elementor + JetEngine. Se a
  Concertação migrar para Gutenberg, hooks adicionais podem ser necessários
  (`render_block_core_image`, etc).

## Referências

- Plugin Network Media Library: https://github.com/humanmade/network-media-library
- Issue #14 (srcset support, fechada): https://github.com/humanmade/network-media-library/issues/14
- Issue #83 (`wp_uploads_dir` no subsite, aberta): https://github.com/humanmade/network-media-library/issues/83
- Memória dos commits/decisões: `~/.claude/projects/-Users-dcambria-scripts-server-tools-v2-docker-dev-sites-concertacao/memory/feedback_nml_crossblog_*.md`
