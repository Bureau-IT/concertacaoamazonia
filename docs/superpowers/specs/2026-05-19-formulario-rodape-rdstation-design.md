# Formulário do Rodapé Unificado + Integração RD Station

> **Status:** pronto para revisão — Parte 1 validada com user (abordagem A); Parte 2 consolidada com base nos relatórios de 3 agentes paralelos sobre a API RD Station.

## Contexto

Hoje o footer do site `concertacao.bureau-it.com` (Concertação Pela Amazônia) renderiza **dois widgets Form do Elementor Pro completamente separados** no template footer ID 72234:

- **Container `d1e32f6` (desktop/tablet)** — "Cadastre-se para receber novidades", layout horizontal compacto, inputs retangulares com borda branca sobre fundo verde, botão à direita. `custom_id` do select: `form_regiao`, label "regiao".
- **Container `3e45cefe` (mobile)** — "Inscreva-se para fazer parte da rede e saber mais sobre nossas iniciativas e eventos:", layout vertical, inputs pill brancos arredondados (border-radius alto), botão centralizado abaixo. `custom_id` do select: `form_email_regiao`, label "estado".

A divergência de `custom_id` entre as duas instâncias é um **bug funcional** silencioso: submissões coletadas via Newsletter caem em colunas diferentes dependendo se a pessoa preencheu no desktop ou no mobile. Além disso, o select de Região tem `"Região"` como uma `<option>` real (não usa `field_options_empty` do Elementor Pro), então um usuário que clica "ENVIAR" sem trocar o select envia o termo literal `"Região"` como se fosse a UF escolhida.

O objetivo é eliminar a duplicação:

1. **Um único widget Form** capaz de renderizar os dois designs distintos via controles responsivos device-aware (Desktop/Tablet/Mobile) — mantendo todas as integrações nativas do Elementor Pro Forms (webhook, MailChimp, ActiveCampaign etc.).
2. **Corrigir o placeholder de Região** para que `"Região"` apareça como prompt do select (`<option value="" disabled selected>`) e não seja enviado como dado.
3. **Adicionar uma Form Action customizada** que envie os submits diretamente para o RD Station Marketing via API Key REST (`POST /platform/conversions`), sem depender de plugin third-party. Detalhamento da escolha API Key vs OAuth na Parte 2.

A solução deve seguir o padrão BIT do projeto: mu-plugin em `wordpress/wp-content/mu-plugins/` + cópia canônica em `docker-dev/common/mu-plugins/` do server-tools, commitado.

---

## Parte 1 — Mu-plugin `bit-elementor-form-responsive.php`

### Abordagem aprovada (Abordagem A)

Estender o widget Form **nativo** do Elementor Pro via hooks (não criar widget novo). Tornar device-aware (`responsive => true`) os controles que hoje não são, e adicionar 1 controle novo:

| Controle | Nível no painel | Tipo | Por que |
|---|---|---|---|
| `form_name` (já existe) | Form widget — Form Fields section | text → **torna-se responsive** | "Cadastre-se..." vs "Inscreva-se..." |
| `placeholder` (já existe) | Repeater field — qualquer tipo | text → **torna-se responsive** | "E-mail" vs "Insira seu melhor email" |
| `field_options_empty` (já existe no Pro p/ select) | Repeater field — só `select` | text → **torna-se responsive** | Placeholder "Região" do select |

A UX no painel usa o **switcher device-aware nativo do Elementor** (ícone 🖥/📱 no topo do painel) — convenção que o editor já conhece de padding/font-size/margin.

### Arquivos

- `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.php` (~150 linhas PHP)
- `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.css` (~80 linhas CSS — consolidar custom CSS inline atual + cobrir os 8 deltas)
- `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.js` (~30 linhas JS — micro-listener `matchMedia` para trocar atributo `placeholder` no resize)
- Cópia canônica para `docker-dev/common/mu-plugins/` (3 arquivos).

### Hooks PHP principais

```php
// 1. Tornar 'form_name' responsive no widget Form
add_action('elementor/element/form/section_form_fields/before_section_end', function ($element, $args) {
    $element->update_control('form_name', ['responsive' => true]);
}, 10, 2);

// 2. Tornar 'placeholder' e 'field_options_empty' responsive nos fields (repeater)
add_action('elementor/element/form/section_form_fields/before_section_end', function ($element, $args) {
    $form_fields = $element->get_controls('form_fields');
    if (isset($form_fields['fields']['placeholder'])) {
        $form_fields['fields']['placeholder']['responsive'] = true;
    }
    if (isset($form_fields['fields']['field_options_empty'])) {
        $form_fields['fields']['field_options_empty']['responsive'] = true;
    }
    // update via set_settings — Elementor API exata será validada ao implementar
}, 11, 2);

// 3. Filter no render — injetar data-attrs com valores tablet/mobile
add_filter('elementor/widget/render_content', function ($content, $widget) {
    if ($widget->get_name() !== 'form') return $content;
    // parsear $widget->get_settings_for_display() — pegar form_name_tablet, form_name_mobile,
    // placeholders por field por device — e injetar como data-bit-placeholder-{tablet,mobile}
    return $content;
}, 10, 2);
```

### Renderização do placeholder responsivo

Como `placeholder` é atributo HTML (não estilo CSS), `@media` não consegue trocá-lo. Solução escolhida:

- PHP renderiza o atributo `placeholder` com o valor **Desktop** + 2 data-attrs: `data-bit-placeholder-tablet` e `data-bit-placeholder-mobile`.
- JS de ~30 linhas com `matchMedia('(max-width: 767px)')` e `matchMedia('(max-width: 1024px)')` troca o atributo `placeholder` no load e em resize/orientation change.
- Mesma lógica para `<option>` do select (substitui texto da primeira `<option disabled selected>`).

Performance: enqueueado só nas páginas com widget Form (via `wp_enqueue_script` dentro do hook de render).

### CSS — 8 deltas mapeados

Todos cobertos por media queries dentro de `bit-elementor-form-responsive.css`:

| # | Propriedade | Desktop | Mobile |
|---|---|---|---|
| 1 | Heading text | (controle PHP) | (controle PHP) |
| 2 | Placeholder email | (controle PHP) | (controle PHP) |
| 3 | Layout fields | `flex-direction: row` | `flex-direction: column` |
| 4 | Border-radius input | `4px` | `24px` (pill) |
| 5 | Background input | `transparent` | `#fff` |
| 6 | Cor placeholder | branco fade | verde menta fade |
| 7 | Posição botão | inline final | centralizado em row própria |
| 8 | Largura botão | flex (~20%) | compacto (~30%, centered) |

CSS escopado em `.elementor-widget-form.bit-form-responsive` — classe adicionada via filter `elementor/widget/render_content` somente quando o widget tem ID/classe esperada (evita afetar OUTROS forms do site).

### Migração dos 2 widgets atuais → 1 widget

Script de migração standalone `scripts/unify-footer-form.php` (idempotente, com `--dry-run`):

1. Carregar `_elementor_data` do template footer ID 72234.
2. Localizar containers `d1e32f6` e `3e45cefe`.
3. Manter o widget Form do desktop (`d1e32f6`) como base — tem o `custom_id` correto (`form_regiao`).
4. Para cada controle responsivo (form_name, placeholder, field_options_empty): copiar o valor do widget mobile para o sufixo `_mobile` do widget desktop.
5. Remover o container mobile (`3e45cefe`).
6. Remover a classe `hidden-mobile` / restrições `hide_*` do desktop.
7. Salvar `_elementor_data` com `wp_slash(wp_json_encode(...))` (lição: `[[feedback_elementor_data_wp_slash_required]]`).
8. `wp elementor flush-css && warm-up explícito do template 72234` (lição: `[[feedback_elementor_flush_css_warmup]]`).
9. Validar com Playwright (gate visual desktop + mobile + tablet em `cambrasmax.local:8484`).

### Backwards compat / paridade dev↔prod

- Mu-plugin é DEV-ONLY até validar em dev; depois copiar para `docker-dev/common/mu-plugins/` e fazer deploy via phase3/share deploy para HML/PROD.
- Antes do deploy prod do script de unificação: backup do template 72234 via `wp post get 72234 --field=content > backup-template-72234-pre-unify.txt`.

---

## Parte 2 — Integração RD Station (mu-plugin `bit-elementor-form-rdstation.php`)

> Pesquisa consolidada de 3 agentes paralelos (relatórios completos em `/Users/dcambria/.claude/plans/image-5-image-6-magical-lightning-agent-{ae9a161c70e9caa22,a2f8a67c2b3b2de69,a1e49fbb675f2ed90}.md`).

### Decisão de autenticação: API Key (não OAuth 2.0)

A integração é **single-tenant** (uma conta RD do cliente, BIT controla o site). API Key elimina toda a complexidade de OAuth — sem refresh token, sem cron, sem encryption-at-rest, sem callback de autorização. Trade-off aceitável: API Key tem limite de 5/conta, mas só usamos 1. OAuth ficaria sobre-engineered para o caso.

**Credenciais já provisionadas pelo user:**

Os tokens estão hoje em `docker-dev/sites/concertacao/.env`:

```
RDSTATION_PUBLIC_TOKEN=<provisionado>
RDSTATION_PRIVATE_TOKEN=<provisionado>
```

**Decisão de storage — mover para o `.env` raiz LINKED_ENV.** Padrão do projeto (auditado): o `.env` raiz `/v2/.env.concertacaoamazonia.com.br.sa` é onde ficam secrets multi-ambiente com sufixo `_DEV/_HML/_PROD` (modelo: `SMTP_PASSWORD_*`, `DB_PASS_*`, `REDIS_PASSWORD_*`). O `.env` do site só tem secrets exclusivamente locais (`MYSQL_ROOT_PASSWORD`, `OPCACHE_INVALIDATE_TOKEN`).

Como os tokens RD Station são **os mesmos em dev/hml/prod** (mesma conta RD da Concertação), mas precisam estar disponíveis nos 3 ambientes para o `wp-config.php` definir as constantes corretas no deploy phase3, **mover para o raiz** segue a convenção e habilita deploy automático:

| Variável (raiz LINKED_ENV) | Tipo RD | Notas |
|---|---|---|
| `RDSTATION_PRIVATE_TOKEN_DEV` | API Key server-side | mesmo valor da prod (conta única) |
| `RDSTATION_PRIVATE_TOKEN_HML` | API Key server-side | mesmo valor |
| `RDSTATION_PRIVATE_TOKEN_PROD` | API Key server-side | mesmo valor |
| `RDSTATION_PUBLIC_TOKEN_DEV` | UUID de tracking client-side | reservado (tracker JS futuro) |
| `RDSTATION_PUBLIC_TOKEN_HML` | UUID de tracking client-side | reservado |
| `RDSTATION_PUBLIC_TOKEN_PROD` | UUID de tracking client-side | reservado |

> Alternativa considerada: **uma única var não-sufixada** (`RDSTATION_PRIVATE_TOKEN`) sem `_DEV/_HML/_PROD`. Funcionaria porque os 3 valores são idênticos, mas quebra convenção e impede uso futuro de chave de teste em dev. Trade-off pequeno mas escolho seguir o padrão.

Operações concretas:

1. Mover as 2 linhas de `docker-dev/sites/concertacao/.env` para `/Users/dcambria/scripts/server-tools/v2/.env.concertacaoamazonia.com.br.sa` com sufixo `_DEV`, `_HML`, `_PROD` (mesmo valor nas 3 — escrever via `env-writer-helper.sh`, único responsável por mexer em `.env`).
2. Remover as 2 linhas originais do `.env` do site.
3. O `wp-config.php` (gerado via bootstrap) já tem padrão de pegar do ambiente sufixado via `config-helper.sh`: `define( 'RDSTATION_PRIVATE_TOKEN', getenv( 'RDSTATION_PRIVATE_TOKEN_' . strtoupper( $env ) ) ?: '' );` — adicionar no template `wp-config.php` ou no helper de pós-bootstrap que injeta constantes (igual `SMTP_*`).
4. Verificar com `grep RDSTATION docker-dev/common/scripts/` se algum script já contempla; senão adicionar.

A constante PHP final lida pelo mu-plugin é `RDSTATION_PRIVATE_TOKEN` (sem sufixo) — o helper resolve o sufixo `_DEV/_HML/_PROD` em build time.

| Aspecto | API Key (escolhido) | OAuth 2.0 (descartado) |
|---|---|---|
| Setup | 1 constante em `wp-config.php` (`RDSTATION_PRIVATE_TOKEN`) | App no AppStore + callback + dialog + token exchange + refresh |
| Storage | só `RDSTATION_PRIVATE_TOKEN` em wp-config (via `.env`) | `client_id`/`client_secret` em wp-config + `refresh_token`/`access_token` em option encrypted |
| Renovação | nunca | a cada 23h (cron) |
| Endpoint | `POST /platform/conversions?api_key=X` | `POST /platform/events` com Bearer token |
| Limitação | só `event_type=CONVERSION` (suficiente p/ newsletter) | qualquer evento custom |
| Linhas de código | ~120 | ~400 |

### Endpoint usado

```http
POST https://api.rd.services/platform/conversions?api_key={RDSTATION_PRIVATE_TOKEN}
Content-Type: application/json

{
  "event_type": "CONVERSION",
  "event_family": "CDP",
  "payload": {
    "conversion_identifier": "newsletter-footer-concertacao",
    "email": "lead@example.com",
    "cf_uf": "PA",
    "tags": ["newsletter", "concertacao-amazonia", "footer-form"],
    "legal_bases": [
      { "category": "communications", "type": "consent", "status": "granted" }
    ]
  }
}
```

Sucesso: `HTTP 200`. Erro: `400` (payload malformado) / `401` (api_key inválida) / `429` (rate limit — 120 req/min, folgado pra newsletter).

### Custom fields RD — pré-requisito manual

`cf_*` **precisa ser pré-cadastrado** no painel RD ou via `POST /platform/contacts/fields` antes do primeiro submit (payload com cf desconhecido é ignorado silenciosamente).

Recomendação:

| api_identifier | name | data_type | presentation_type | conteúdo |
|---|---|---|---|---|
| `cf_uf` | "UF" | STRING | TEXT_INPUT | sigla UF do Brasil (AC, AM, PA, etc.) |
| `cf_consent_source` | "Origem do Consentimento" | STRING | TEXT_INPUT | URL completa onde o consentimento foi dado |
| `cf_consent_timestamp` | "Timestamp do Consentimento" | STRING | TEXT_INPUT | ISO 8601 do momento do submit |

**Setup inicial**: documentar em `docs/superpowers/specs/2026-05-19-formulario-rodape-unificado-design.md` como tarefa one-shot manual (ou criar script `scripts/rdstation-bootstrap-fields.php` que faz POST nos 3 fields, idempotente — checa se já existe via GET antes).

### Arquitetura: Form Action customizada (não hook `new_record`)

Em vez de hook `elementor_pro/forms/new_record` (genérico, dispara pra todo form), criamos uma **Form Action** que o user escolhe na aba "Actions After Submit" do widget Form. Vantagens:

- Aparece como toggle no editor — só forms que tiverem "RD Station (BIT)" selecionado disparam.
- Controles próprios na aba (conversion_identifier, mapping de fields, tags) — sem código.
- Falha do RD **nunca** quebra o submit do form (graceful degradation): mesmo se a API retornar 4xx/5xx ou timeout, a Action retorna sem chamar `add_error_message`. Tudo vai para o log.

### Estrutura da Form Action

Classe `BIT\RDStation\Form_Action` estendendo `\ElementorPro\Modules\Forms\Classes\Action_Base`, registrada via:

```php
add_action('elementor_pro/forms/actions/register', function ($registrar) {
    $registrar->register(new \BIT\RDStation\Form_Action());
});
```

Métodos implementados:

| Método | O que faz |
|---|---|
| `get_name()` | retorna `'bit_rdstation'` (id interno) |
| `get_label()` | retorna `'RD Station (BIT)'` (label no painel) |
| `register_settings_section($widget)` | adiciona controles: `bit_rd_conversion_identifier` (text, default `newsletter-footer-concertacao`), `bit_rd_email_field` (select dos field IDs), `bit_rd_uf_field` (select dos field IDs), `bit_rd_tags` (text CSV), `bit_rd_consent_field` (select acceptance field opcional) |
| `run($record, $ajax_handler)` | monta payload, POST via `wp_remote_post` (timeout=8), trata resposta, loga sempre — **NÃO chama `add_error_message` em falha do RD** (graceful: submit do form não pode quebrar porque RD está fora do ar) |
| `on_export($element)` | retorna os controles para serem limpos no export do template |

### Storage de credenciais

Padrão BIT confirmado pelo agente 3 (lendo 45 mu-plugins do projeto): **toda credencial em `wp-config.php` como constante**. Zero settings page no admin. Modelo: `ses-mailer.php`.

Constantes em `wp-config.php` (multisite — colocar no escopo global, não por blog). As 2 primeiras vêm direto do `.env` via bootstrap; as 2 opcionais o operador define manualmente quando precisar:

```php
// vindas do .env via bootstrap (já provisionadas pelo user)
define( 'RDSTATION_PRIVATE_TOKEN', '...' );   // obrigatório — API Key server-side
define( 'RDSTATION_PUBLIC_TOKEN',  '...' );   // reservado (tracker JS futuro)

// opcionais — adicionadas manualmente quando necessário
define( 'BIT_RDSTATION_DEFAULT_TAGS', 'newsletter,concertacao' );   // tags injetadas em todo submit
define( 'BIT_RDSTATION_DEBUG',        false );                       // opt-in pra log verboso
```

Sem painel admin. Se faltar `RDSTATION_PRIVATE_TOKEN`, mu-plugin inicializa em "modo passivo" (Action ainda aparece no editor mas faz no-op + log warning a cada submit). O `RDSTATION_PUBLIC_TOKEN` não é lido por esta integração — fica esperando uso futuro.

### Logging

- Sempre loga 1 linha por submit (success / fail) em `wp-content/uploads/bit-rdstation-logs/YYYY-MM-DD.log` (gitignored — uploads não é versionado).
- `BIT_RDSTATION_DEBUG=true` → dump completo do payload + resposta.
- Rotação manual (se virar problema, expandir depois — YAGNI).

### LGPD — checkbox obrigatório no form

O form footer hoje **não tem checkbox de consentimento**. Para conformidade com LGPD ao enviar lead pro RD:

- Adicionar um `acceptance` field (Elementor Pro nativo) ao widget unificado, com texto pequeno tipo "Concordo em receber comunicações da Concertação Pela Amazônia."
- Field obrigatório no mobile e desktop (responsive — sempre visível).
- A Form Action lê esse field via `bit_rd_consent_field` setting; se não marcado, `legal_bases[].status="declined"` e ainda assim envia (RD cria o lead, mas não enviará marketing).
- Adicional: `cf_consent_source` = `home_url() + '/'`, `cf_consent_timestamp` = `wp_date('c')` (ISO 8601).

### Arquivos da Parte 2

- `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php` (~250 linhas PHP)
- `docker-dev/common/mu-plugins/bit-elementor-form-rdstation.php` (cópia canônica)
- `scripts/rdstation-bootstrap-fields.php` (~80 linhas — one-shot idempotente pra criar cf_uf/cf_consent_*)

Sem JS, sem CSS (Action é só backend; os controles do painel são renderizados pelo Elementor).

### Pontos que precisam validação real (marcados [NÃO VERIFICADO] pelos agentes)

1. Schema exato de erro 400/422 — testar com curl payload inválido em conta real.
2. Comportamento com email em opt-out — testar com email descadastrado em conta sandbox.
3. Se `cf_consent_source` / `cf_consent_timestamp` chegam ao painel do RD como esperado.

Decisão: validar manualmente com curl + conta sandbox antes de wire-up no form de produção. Sem ambiente de teste oficial — RD oferece **conta Pro gratuita pra Integration Partner** (5k leads) que serve de sandbox.

### Roadmap futuro (fora desta entrega)

- Migrar pra OAuth se aparecer 2º site BIT integrando (multi-tenant justifica complexidade).
- Adicionar `/platform/events/batch` se acumular submits offline (PWA / form com retry queue).
- Webhook RD → WP pra capturar opt-outs (necessário se a Concertação fizer reativação por email transacional).

---

## Verificação end-to-end

### Parte 1 — Form responsivo

1. **Dev** (`cambrasmax.local:8484`): abrir o footer no editor Elementor, ativar Mobile no switcher device-aware, verificar que `form_name` mostra placeholder `_mobile` e que campos têm border-radius pill.
2. **Migration script**: rodar `--dry-run` primeiro, validar diff JSON, depois aplicar.
3. **Playwright gate** comparando screenshots desktop/tablet/mobile do footer antes vs depois.
4. **Submit funcional**: preencher email + região, verificar via `std wp db query "SELECT * FROM wp_e_submissions ORDER BY id DESC LIMIT 3;"` que `form_regiao` recebeu o `value` da UF (ex: `AC`) e NÃO `"Região"`.
5. **Smoke /smoke**: gates 26 (WPML orphan) e 28 (stale paths Elementor) precisam continuar verdes. Gate de form (29 — `bypass_header=OK`) precisa funcionar.

### Parte 2 — RD Station

1. **Curl manual à API com API Key**: enviar payload mínimo (email + conversion_identifier) em conta sandbox antes de wire-up no form. Documentar resposta de sucesso e estrutura de erro 400 real (resolve um dos `[NÃO VERIFICADO]`).
2. **Bootstrap dos custom fields**: rodar `scripts/rdstation-bootstrap-fields.php` (`GET` para verificar existência, `POST` se não existir). Conferir no painel RD que `cf_uf`, `cf_consent_source` e `cf_consent_timestamp` aparecem nos campos do contato.
3. **Submit funcional**: preencher o form footer em dev com `BIT_RDSTATION_DEBUG=true`, verificar log em `wp-content/uploads/bit-rdstation-logs/YYYY-MM-DD.log` (1 linha sucesso + dump completo do payload/resposta).
4. **Confirmação no painel RD**: o contato aparece em "Leads", com `cf_uf=PA`, tags aplicadas e conversão "newsletter-footer-concertacao" no histórico de eventos do lead.
5. **Teste de falha graceful**: comentar `define( 'RDSTATION_PRIVATE_TOKEN', ... )` no `wp-config.php` temporariamente, submeter form — o submit do form **precisa continuar funcionando** (resposta 200 no admin-ajax, mensagem de sucesso visível) e o log precisa ter 1 linha de warning. Form **não pode quebrar** quando RD falha.
6. **LGPD**: confirmar via curl manual que `legal_bases[].status="granted"` chega como esperado e que o lead aparece como opt-in no painel RD.

---

## Critical files

### Novos
- `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.php`
- `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.css`
- `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.js`
- `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php` (Parte 2)
- `docker-dev/common/mu-plugins/bit-elementor-form-responsive.{php,css,js}` (cópia canônica)
- `docker-dev/common/mu-plugins/bit-elementor-form-rdstation.php` (Parte 2)
- `scripts/unify-footer-form.php`
- `scripts/rdstation-bootstrap-fields.php` (one-shot, idempotente, cria `cf_uf`/`cf_consent_source`/`cf_consent_timestamp`)

### Alterados
- `_elementor_data` do template footer ID 72234 (via script de migração — Parte 1)
- `/Users/dcambria/scripts/server-tools/v2/.env.concertacaoamazonia.com.br.sa` — adicionar `RDSTATION_PRIVATE_TOKEN_{DEV,HML,PROD}` e `RDSTATION_PUBLIC_TOKEN_{DEV,HML,PROD}` via `env-writer-helper.sh`
- `docker-dev/sites/concertacao/.env` — remover as 2 linhas `RDSTATION_*` (movidas para o raiz LINKED_ENV)
- `docker-dev/common/scripts/bootstrap.sh` ou template `wp-config.php` — adicionar export das constantes `RDSTATION_PRIVATE_TOKEN` e `RDSTATION_PUBLIC_TOKEN` lendo do `_{ENV}` sufixado (igual já é feito com `SMTP_*` e `GTM_CONTAINER_ID`)

### Não alterados
- Tema `hello-elementor-child` (CSS específico continua isolado no mu-plugin).
- Outros mu-plugins.
