# Spec: Self-host Plus Jakarta Sans + cleanup elementor/google-fonts

**Data:** 2026-05-02
**Status:** Proposed
**Decisores:** Daniel Cambría
**Contexto:** Trilha estrutural pré-Opção D (ADR `2026-05-02-adr-s3-uploads-hybrid.md`)

## Problema

Site Concertação carrega Plus Jakarta Sans (PJS) externamente de `fonts.googleapis.com`, enquanto o requisito é **fontes hospedadas localmente** (privacidade, performance, independência de CDN externo). Adicionalmente, há 412 woff2 órfãs em `/uploads/elementor/google-fonts/fonts/` (7.9MB) geradas pelo experiment `elementor_local_google_fonts` que NUNCA são referenciadas no HTML.

## Estado atual auditado

- **PJS** (4 weights: 400/500/600/700) é enqueado pelo mu-plugin `bureau-a11y.php` apontando para `fonts.googleapis.com` (handle `ba-font`)
- **Franie / JustSans / Roboto** já são locais em `themes/hello-elementor-child/fonts/woff2/` (referenciados em `functions.php:169-242` via `@font-face` inline)
- **Roboto** usa pattern variable subsetado (`Roboto-latin-w400-700.woff2`, ~37KB)
- **412 fontes órfãs** em uploads (Barlow, Open Sans, Montserrat, Cabin, Roboto Condensed) — zero referências no HTML
- **WP Rocket** tem `preload_fonts_list` apontando para URLs S3 stale (config legacy)
- **2 duplicatas Roboto-VariableFont** no tema (~840KB de lixo: pares MD5-idênticos)
- **dns-prefetch / preconnect** para `fonts.googleapis.com` aparecem no HTML (vão sair junto)

## Decisão

1. **Self-host PJS como variable font subsetado Latin** (~27KB)
2. **Limpar elementor/google-fonts/** (delete + desativar experiment)
3. **Limpar duplicatas Roboto-VariableFont** no tema
4. **Limpar `preload_fonts_list` stale** do WP Rocket
5. **Remover preconnect/dns-prefetch** `fonts.googleapis.com` do mu-plugin a11y

## Arquitetura

### Componentes a modificar

| Componente | Mudança |
|------------|---------|
| `themes/hello-elementor-child/fonts/woff2/PlusJakartaSans-latin-w400-700.woff2` | **NOVO** — variable font subsetado Latin (~27KB) |
| `themes/hello-elementor-child/functions.php` | Adicionar `@font-face` PJS inline (pattern existente do Franie/JustSans/Roboto) |
| `mu-plugins/bureau-a11y.php` | Remover preconnect Google + remover enqueue `ba-font` external (PJS agora vem do tema) |
| `/uploads/elementor/google-fonts/fonts/` | **DELETAR** + desativar experiment `e_local_google_fonts` |
| `/uploads/elementor/css/` | **DELETAR** (vazio, redirecionado por mu-plugin) |
| Roboto-VariableFont duplicates | **DELETAR** par redundante |
| `wp_rocket_settings.preload_fonts_list` | Limpar URLs S3 stale → `[]` |

### Arquivo de fonte

- **URL fonte:** `https://fonts.gstatic.com/s/plusjakartasans/v12/LDIoaomQNQcsA88c7O9yZ4KMCoOg4Ko20yygg_vb.woff2`
- **Subset:** Latin (`U+0000-00FF` + extensões básicas) — cobre 100% PT-BR
- **Range:** `font-weight: 400 700` (variable axis)
- **Tamanho:** 27.272 bytes (~27KB)
- **Filename destino:** `PlusJakartaSans-latin-w400-700.woff2` (match com pattern Roboto-latin-w400-700.woff2)

### `@font-face` proposto (em `functions.php`)

```css
@font-face {
  font-family: 'Plus Jakarta Sans';
  font-style: normal;
  font-weight: 400 700;
  font-display: swap;
  src: url('../fonts/woff2/PlusJakartaSans-latin-w400-700.woff2') format('woff2-variations'),
       url('../fonts/woff2/PlusJakartaSans-latin-w400-700.woff2') format('woff2');
  unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
}
```

### Preload

Adicionar no head (junto com Franie/JustSans):
```html
<link rel="preload" href=".../themes/hello-elementor-child/fonts/woff2/PlusJakartaSans-latin-w400-700.woff2" as="font" type="font/woff2" crossorigin="anonymous">
```
Justificativa: PJS é usado no mu-plugin a11y (botão flutuante, sempre presente). Preload acelera FCP do widget.

### Comportamento `bureau-a11y.php` pós-mudança

- Remover `wp_head` action que adiciona preconnect Google (linhas 25-28)
- Remover `wp_enqueue_style('ba-font', ...)` (linha 38-44)
- Atualizar dependência: `bureau-a11y` CSS depende de `[]` (PJS já carregada pelo tema, não precisa wait)

## Plano de execução

### Fase 1: Dev (validar)
1. Baixar `PlusJakartaSans-latin-w400-700.woff2` para `themes/hello-elementor-child/fonts/woff2/`
2. Editar `functions.php` adicionando `@font-face` e preload
3. Editar `bureau-a11y.php` removendo preconnect + enqueue Google
4. `std cache-flush`
5. Smoke: validar HTML renderizado em dev (`https://concertacao.bureau-it.com/`)
   - PJS carregado de path local
   - Zero requests para `fonts.googleapis.com`
   - Visual a11y panel renderiza com PJS correto
   - 4 weights (400/500/600/700) renderizam diferentes pesos

### Fase 2: Cleanup uploads/elementor/* em PROD
1. `wp option update elementor_local_google_fonts 0`
2. `wp option update elementor_experiment-e_local_google_fonts inactive`
3. `wp option patch update wp_rocket_settings preload_fonts_list '[]' --format=json`
4. Backup tar: `tar czf /var/www/tmp_backups/elementor-google-fonts-<ts>.tar.gz uploads/elementor/google-fonts/`
5. `rm -rf uploads/elementor/google-fonts/fonts/*`
6. `rmdir uploads/elementor/css/` (vazio)

### Fase 3: Cleanup duplicatas Roboto em PROD
1. Confirmar md5 idênticos (já feito: pares confirmados)
2. `rm Roboto-VariableFont.woff2 Roboto-Italic-VariableFont.woff2` (manter os com `_wdth,wght`)
3. Validar HTML não referencia versões removidas

### Fase 4: Deploy dev→prod (cycle weekly normal)
- Deploy dev→green→prod via fluxo blue-green padrão
- PJS local entra junto com `bureau-a11y.php` modificado e `functions.php` atualizado

### Fase 5: Validação pós-deploy
- Smoke `/smoke` cobre páginas críticas
- Manual: confirmar `<link>` para fonts.googleapis.com NÃO aparece em `/`, `/sobre-nos/`
- Lighthouse FCP/LCP delta vs baseline

## Critérios de sucesso

1. ✅ PJS servido de `wp-content/themes/hello-elementor-child/fonts/woff2/`
2. ✅ Zero requests externos para `fonts.googleapis.com` no HTML rendered
3. ✅ Bureau A11y panel renderiza com PJS visualmente idêntico
4. ✅ `/uploads/elementor/google-fonts/fonts/` vazio
5. ✅ `/uploads/elementor/css/` removido (vazio)
6. ✅ Lighthouse: FCP igual ou melhor que baseline
7. ✅ HTML rendered <50KB menor (sem 1 request CSS Google externo)

## Riscos & rollback

| Risco | Probabilidade | Mitigação |
|-------|---------------|-----------|
| PJS variable não suportado em browser legacy | Baixa (98%+ users em 2026) | `font-display: swap` faz fallback gracioso |
| Latin subset insuficiente | Muito baixa (PT-BR é 100% coberto por U+0000-00FF) | Visual smoke catches; rollback trivial |
| WP Rocket cache de CSS antigo | Média | `std cache-flush` em dev, `rocket_clean_post` cirúrgico em prod |
| Path relativo `../fonts/woff2/` quebra em CSS minificado | Média | WP Rocket reescreve URLs corretamente; testar em dev primeiro |
| Filename collision com fontes existentes | Zero | `PlusJakartaSans-latin-w400-700.woff2` é único |

**Rollback Fase 1:** revert commits no tema/mu-plugin → cache-flush → PJS volta de Google CDN
**Rollback Fase 2:** restaurar tar.gz → reativar experiment Elementor
**Rollback Fase 3:** restaurar duplicatas via deploy

## Não-objetivos

- **Não cobrir** outras famílias órfãs (Barlow, etc) — todas serão deletadas, não migradas
- **Não otimizar** Franie/JustSans (fora de escopo; weights atuais funcionam)
- **Não tocar** em Roboto-latin-w400-700.woff2 (já está como deveria)
- **Não migrar** uploads/elementor/thumbs/ (Fase 3 separada do plano de hoje, requer staging)

## Referências

- ADR `2026-05-02-adr-s3-uploads-hybrid.md` (decisão de manter config híbrida; este spec é cleanup auxiliar)
- Audit Auditor #2 (2026-05-02): variable subsetado Latin = melhor escolha
- Audit Auditor #3 (2026-05-02): pattern Roboto-latin-w400-700 é precedente válido
- Memo `feedback_post_deploy_stability.md` (cleanup pré-Opção D)
