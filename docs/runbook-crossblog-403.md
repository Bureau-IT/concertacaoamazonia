# Runbook — 403/404 em `<img>`/`<img srcset>` no /cultura/

**Quando usar:** clientes ou Playwright reportam imagens quebradas em massa em páginas sob `/cultura/`, especialmente em traduzidas (`/cultura/en/*`) ou após deploy blue-green.

**Tempo estimado:** 5–10 min.

---

## 1. Reproduzir + capturar evidência

Abra a página afetada em janela anônima do Chrome. DevTools (F12) → Console → cole:

```js
({
  total: document.querySelectorAll('img').length,
  broken: [...document.querySelectorAll('img')].filter(i => i.complete && i.naturalWidth === 0 && i.src).length,
  sites_n_refs: (document.documentElement.outerHTML.match(/\/sites\/\d+\/uploads\//g) || []).length,
  sample_4xx: [...document.querySelectorAll('img')].filter(i => i.complete && i.naturalWidth === 0).slice(0, 3).map(i => i.currentSrc || i.src),
})
```

Interpretação:

- `broken > 2` **e** `sites_n_refs > 0` → cross-blog, seguir adiante
- `broken > 2` mas `sites_n_refs === 0` → outra categoria (CSP, S3, CF) — sair deste runbook
- tudo zero → reproduza com hard refresh (Cmd+Shift+R) ou janela anônima

---

## 2. Verificar mu-plugin no servidor

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo grep '\* Version' /var/www/concertacaoamazonia.com.br/wp-content/mu-plugins/bit-crossblog-attachment-fix.php"
```

| Resultado | Ação |
|-----------|------|
| (vazio) | mu-plugin **ausente** — deploy imediato a partir de `docker-dev/common/mu-plugins/` |
| `Version: 1.5.x` | upgrade para 1.6.0+ (Hook 14 cobre caso NML default) |
| `Version: 1.6.0+` | seguir adiante |

---

## 3. Validar attachment ID no DB

Pegue um ID de exemplo do snippet do passo 1 (`class="wp-image-XXXX"` no `<img>` quebrado — use DevTools → Elements para inspecionar).

```bash
# Existe em wp_posts (blog 1)?
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp post get XXXX --field=post_status \
   --url=https://concertacaoamazonia.com.br/"

# E em wp_2_posts (blog 2)?
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp post get XXXX --field=post_status \
   --url=https://concertacaoamazonia.com.br/cultura/"
```

| blog 1     | blog 2     | Diagnóstico                                           |
|------------|------------|-------------------------------------------------------|
| `inherit`  | (vazio)    | **NML cross-blog default** — Hooks 1-8 + 14 cobrem    |
| (vazio)    | `inherit`  | **Órfão WPML** — Hooks 9-13 cobrem                    |
| `inherit`  | `inherit`  | Duplicado WPML legítimo — verificar `_wpml_media_*`   |
| (vazio)    | (vazio)    | Attachment deletado — investigar histórico            |

---

## 4. Rodar gate específico do `/smoke`

```bash
# Em dev (local)
std smoke

# Ou via slash command no Claude Code
/smoke
```

Os gates relevantes:

- **Gate 26** (`wpml_orphan_leak`) — 4 páginas EN canários
- **Gate 37** (`crossblog_srcset_4xx`) — varre páginas blog 2 procurando `<img srcset>` com 4xx

Se passa mas você vê o sintoma → caso novo (URL não está nos canários). Pular para passo 5.

---

## 5. Fix imediato — limpar caches

```bash
# CF invalidate cirúrgico (NUNCA usar /*)
aws cloudfront create-invalidation \
  --distribution-id E2F1QD7E7YOYEB \
  --paths "/cultura/PATH/" "/cultura/PATH" \
  --profile "Concertação"

# WP Rocket cache do post
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp rocket_clean_post --post_id=PAGE_ID \
   --url=https://concertacaoamazonia.com.br/cultura/"

# (Opcional) regenerar Elementor CSS
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp eval '(new \\Elementor\\Core\\Files\\CSS\\Post(PAGE_ID))->update();' \
   --url=https://concertacaoamazonia.com.br/cultura/"
```

---

## 6. Se o fix não cobre — investigar e reportar

Cenário: mu-plugin OK (1.6.0+), gates 26/37 passam, mas página específica ainda quebra.

**Coletar antes de reportar:**

```bash
# A. HTML server-side
curl -sS -A 'Mozilla/5.0' "https://concertacaoamazonia.com.br/cultura/PATH/" \
  | grep -oE '/wp-content/uploads/[^"'"'"']+' | sort -u | head -20

# B. Meta do attachment problemático
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp post meta get XXXX _wp_attachment_metadata \
   --url=https://concertacaoamazonia.com.br/" | head -50

# C. WPML trid (se aplicável)
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp db query \
   'SELECT element_id, trid, language_code FROM wp_icl_translations WHERE element_id = XXXX;'"
```

**Reportar para backend** (ClickUp BIT) com:

1. URL afetada + timestamp + ambiente (prod/green)
2. Output do snippet DevTools (passo 1)
3. Outputs A, B, C acima
4. Contexto do widget que renderiza a imagem (Elementor image? gallery? jet-listing?)
5. Versão atual do mu-plugin (passo 2)

Se o caso for novo, vira **Hook 15** com bump para v1.7.0.

---

## O que NÃO fazer durante incidente

- **Não invalidar CloudFront com `/*`** — avalanche de cache misses derruba o servidor (memória `feedback_cloudfront_invalidation.md`).
- **Não reativar WPML duplicate-on-translate** "para testar" — gera mais órfãos em `wp_2_posts`.
- **Não deletar attachments no blog 2** sem confirmar que não há cópia no blog 1.
- **Não rodar `wp media regenerate` sem `--url=`** — gera novos arquivos no blog errado.
- **Não fazer `aws s3 sync` reverso** (`/assets/uploads/` → `/green/uploads/`) — o sync é one-way em ambientes pós-cutover.
