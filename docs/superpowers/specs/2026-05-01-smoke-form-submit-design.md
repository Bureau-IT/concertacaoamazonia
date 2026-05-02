# /smoke — submit real de formulários no green

**Data:** 2026-05-01
**Comando afetado:** `.claude/commands/smoke.md`
**Escopo:** estender o smoke para submeter Newsletter (footer/home) e Contato com marcador rastreável, validar resposta visual e tratar fail como gate de bloqueio.

---

## Motivação

O smoke atual valida apenas **renderização** dos formulários (presença, campos, botão visível). Submits podem quebrar silenciosamente após deploy:
- nonce/AJAX endpoint mudou de URL
- reCAPTCHA com chave errada
- action handler do JetForm/Elementor com erro fatal
- email/webhook não disparando (mas form parece OK)

Renderização verde + submit verde é o que valida "pipeline de envio funciona".

## Decisões de design

| # | Decisão | Justificativa |
|---|---------|---------------|
| 1 | Submit **apenas no green** (com `X-Test-Green:true`) | Prod recebe submits reais o tempo todo — validar prod = poluir CRM. Green é o que vai virar prod, e é onde o pipeline pode estar quebrado. |
| 2 | Marcador rastreável fixo | `email = smoke+<UNIX_TS>@bureau-it.com`, `nome = "SMOKE TEST <UNIX_TS>"`, `mensagem = "Automated smoke test from /smoke command — safe to delete."`. Filtragem trivial em qualquer destino. |
| 3 | Validação = **resposta visual** | Aguardar `.elementor-message-success` (Elementor) ou `.jet-form-builder-message--success` (JetForm). Não consultar banco — pipeline pós-submit é responsabilidade de outros testes. |
| 4 | Gate de FAIL bloqueante com retry 1x | Submit pode falhar por race (reCAPTCHA, hydration do JS). 1 retry com backoff de 2s. Falha nas 2 tentativas = gate disparado. |
| 5 | Páginas 1-5 **inalteradas** | Não tocar na lógica de renderização que já funciona. Apenas Newsletter (página 6) e Contato (página 7) ganham fase de submit. |

## Mudanças no smoke.md

### Snippet único de submit (substitui o snippet atual de "validação de formulário" para páginas 6 e 7 quando rodando em GREEN)

```js
async (page) => {
  const ctx = page.context();
  await ctx.setExtraHTTPHeaders({ 'X-Test-Green': 'true' });
  await ctx.clearCookies();

  await page.goto('URL_AQUI?cb=' + Date.now(), { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(1500);

  const ts = Date.now();
  const marker = {
    email: `smoke+${ts}@bureau-it.com`,
    nome: `SMOKE TEST ${ts}`,
    msg: 'Automated smoke test from /smoke command — safe to delete.',
  };

  const submit = async () => {
    return await page.evaluate(async (m) => {
      const form = document.querySelector('form.elementor-form, form.jet-form, form[name]');
      if (!form) return { ok: false, reason: 'form_not_found' };

      // Preenche campos por heurística (placeholder, name, type)
      const fill = (selector, value) => {
        const el = form.querySelector(selector);
        if (el) {
          el.value = value;
          el.dispatchEvent(new Event('input', { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
        }
      };
      fill('input[type=email], input[name*=email i], input[placeholder*=email i]', m.email);
      fill('input[name*=nome i], input[placeholder*=nome i], input[name*=name i]', m.nome);
      fill('textarea, input[name*=mensagem i], input[name*=message i]', m.msg);

      // Selects (ex: Região na Newsletter): primeira opção válida
      const sel = form.querySelector('select');
      if (sel && sel.options.length > 1) sel.selectedIndex = 1;

      const btn = form.querySelector('button[type=submit], input[type=submit], .elementor-button[type=submit]');
      if (!btn) return { ok: false, reason: 'submit_btn_not_found' };
      btn.click();

      // Espera mensagem de sucesso ou erro (15s)
      const deadline = Date.now() + 15000;
      while (Date.now() < deadline) {
        const success = document.querySelector(
          '.elementor-message-success, .jet-form-builder-message--success, .elementor-message.elementor-message-success'
        );
        const error = document.querySelector(
          '.elementor-message-danger, .jet-form-builder-message--error, .elementor-message.elementor-message-danger'
        );
        if (success) return { ok: true, message: success.innerText.trim().slice(0, 200) };
        if (error)   return { ok: false, reason: 'error_message', message: error.innerText.trim().slice(0, 200) };
        await new Promise(r => setTimeout(r, 250));
      }
      return { ok: false, reason: 'timeout_15s' };
    }, m);
  };

  // 1ª tentativa
  let result = await submit(marker);

  // Retry 1x com 2s backoff se falhou
  if (!result.ok) {
    await page.waitForTimeout(2000);
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    result = await submit(marker);
    result.retry = true;
  }

  return {
    submit_ok: result.ok,
    submit_reason: result.reason || null,
    submit_message: result.message || null,
    submit_retry_used: !!result.retry,
    marker,
  };
}
```

### Alterações na seção "Workflow"

Para páginas 6 e 7:
1. `browser_close`
2. `browser_run_code` em **prod** (sem header) — usa snippet **antigo** (validação de presença, sem submit)
3. `browser_close`
4. `browser_run_code` em **green** (com header) — usa snippet **novo** (validação + submit com retry)

### Nova matriz de apresentação (páginas 6-7)

```
| Página     | PROD form | PROD fields | PROD submit | GREEN form | GREEN fields | GREEN submit | GREEN submit_ok | retry | marker email                  |
|------------|----------:|------------:|-------------|-----------:|-------------:|--------------|------------------|-------|-------------------------------|
| Newsletter |     1     |     2       | "ENVIAR"    |     1      |     2        | "ENVIAR"     | ✅              | no    | smoke+1730487...@bureau-it.com|
| Contato    |     1     |     N       | "ENVIAR"    |     1      |     N        | "ENVIAR"     | ✅              | yes   | smoke+1730487...@bureau-it.com|
```

### Novos gates de FAIL (adicionados aos 8 existentes)

9. **Newsletter submit**: `submit_ok === false` no green após retry
10. **Contato submit**: `submit_ok === false` no green após retry

Os 8 gates atuais (1-8) permanecem intactos.

## Não-objetivos

- Não validar entrega de email (cabe a teste de integração SES separado)
- Não validar persistência em CCT/banco (não há contrato de onde o lead deve cair)
- Não submeter forms em prod (decisão #1)
- Não testar reCAPTCHA visível com challenge (assume-se invisible/v3)

## Limpeza

Marcadores `smoke+<ts>@bureau-it.com` aparecerão em qualquer destino (Newsletter list, customercare email). Limpeza recorrente fica fora do escopo deste design — pode virar `/schedule` mensal se acumular.

## Risco residual

- **Se o site usar reCAPTCHA v2 visível** (não v3 invisible) o submit vai travar no challenge. Mitigação: gate de timeout 15s captura, marca como FAIL, operador investiga manualmente.
- **Se as classes CSS de mensagem mudarem** em update do Elementor, o smoke marca FAIL falso. Mitigação: query inclui múltiplas variantes (`.elementor-message-success`, `.elementor-message.elementor-message-success`, `.jet-form-builder-message--success`).

---

## Adendum 2026-05-01 — Fase 8: Warm-up de cache do menu

Estende o smoke com uma fase nova que mede tempo de carregamento de cada item do menu principal a partir do cache, em **prod e green**.

### Decisões

| # | Decisão | Justificativa |
|---|---------|---------------|
| 1 | **Descoberta dinâmica** dos itens do menu (scraping da home) | Menu muda com WPML, releases, requests do cliente — hardcode envelhece. Scraping reflete o estado real. |
| 2 | **Header de cache + TTFB** combinados, gate só em TTFB | `cf-cache-status` é informativo (diagnóstico); o que importa pro usuário é tempo de resposta. |
| 3 | Threshold absoluto **1500ms** na 2ª visita | Margem confortável acima do TTFB típico de cache HIT no CloudFront (≈300-500ms). Pega regressões reais, evita falso positivo por jitter. |
| 4 | **Comparativo prod×green** com gate adicional (green > 2x prod) | Um item green a 1200ms passa no threshold absoluto, mas se prod é 300ms o cache regredeiu — mesmo deploy, mesma página. |
| 5 | 2 visitas (warm-up + medição), descartar a 1ª | A 1ª pode bater no servidor (cache miss); a 2ª valida que o cache pegou. |
| 6 | Teto de **20 itens** no scraping | Megamenu pode ter dezenas de links. Smoke não é teste de carga — primeiros 20 cobrem nav principal. |

### Snippet "Warm-up do menu" (em smoke.md)

Roda 1x para PROD (sem header) e 1x para GREEN (com `X-Test-Green:true`). Coleta `ttfb_ms`, `cf_cache_status`, `wp_rocket_cache`, `x_cache` por item.

### Gates novos (11 e 12)

- **11**: `ttfb_ms > 1500` na 2ª visita (gate absoluto)
- **12**: green com `ttfb_ms > 2x` o prod equivalente (gate comparativo)

### Não-objetivos

- Não medir Core Web Vitals (LCP, CLS, INP) — escopo do Lighthouse, não do smoke
- Não testar URLs com query strings (?eixo=…) — Fase 8 é nav principal, não filtros
- Não medir 2ª/3ª profundidade do menu (megamenu) — escopo é menu principal scrapeado da home
