# Envio de `cf_setor` ao RD Station + correção das tags dos footers

**Data:** 2026-08-03
**Autor:** Daniel Cambría / Bureau de Tecnologia Ltda.
**Componente:** `mu-plugins/bit-elementor-form-rdstation` (v1.2.0 → v1.3.0)
**Spec anterior:** `docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md`

## Problema

Dois defeitos independentes na integração com o RD Station, ambos nos mesmos
formulários:

1. **O setor não chega ao RD.** O select `field_8aee261` ("setor") é
   **obrigatório** nos 4 footers, o visitante sempre preenche, e o valor é
   descartado — o plugin v1.2.0 só sabe mapear email, nome, organização e UF.
2. **Os leads de newsletter entram marcados como teste.** Os 4 footers ao vivo
   enviam `tags: ["teste-bit"]`. O commit `725bcfa378` corrigiu o
   `conversion_identifier` de teste mas deixou as tags atrás.

## Escopo

**Dentro:** enviar `cf_setor` (com normalização PT/EN) e corrigir as tags dos
6 formulários ao vivo.

**Fora, por decisão:** `cf_assunto` e `cf_mensagem`. Os campos `assunto` e
`mensagem` dos formulários de Contato são conteúdo de atendimento, não atributo
de segmentação de lead — o lugar deles é o email do formulário, que já os
recebe. Também fora: generalizar o mapeamento de campos para um repeater
`custom_id → cf_*`. Sem um terceiro caso concreto, é generalização
especulativa, e um repeater genérico não saberia normalizar PT/EN.

## Inventário dos formulários ao vivo

Levantado em 2026-08-03 varrendo `_elementor_data` por `bit_rdstation` nos dois
blogs. Todos os outros IDs encontrados eram `post_type=revision`.

| blog | post ID | tipo | título | `field_8aee261` | tags hoje |
|---|---|---|---|---|---|
| 1 | 672 | page | Contato | não | `contato,concertacao-amazonia` |
| 1 | 3626 | page | Contact | não | `contato,concertacao-amazonia` |
| 1 | 72234 | elementor_library | Footer | sim | `teste-bit` |
| 1 | 72921 | elementor_library | Footer EN | sim | `teste-bit` |
| 2 | 89361 | elementor_library | Rodapé | sim | `teste-bit` |
| 2 | 89785 | elementor_library | Rodapé EN | sim | `teste-bit` |

## Decisões de design

### `cf_setor` é campo de texto livre no RD, não lista de opções

O `field_8aee261` é um `select` obrigatório: só 5 valores podem chegar ao
plugin. A validação que uma lista no RD faria já está feita a montante, e
redundância aqui não é gratuita — se alguém adicionar uma 6ª opção no
formulário e esquecer de espelhar no painel, uma lista devolve HTTP 400 e **a
conversão inteira se perde** (o lead não entra: nem email, nem tags). Com texto
livre o pior caso é um valor novo aparecendo no painel: falha visível em vez de
silenciosa.

Contrapartida registrada: o drift passa a ser silencioso na direção oposta. Um
typo no formulário vira um valor novo no RD em vez de ser rejeitado, e o mapa de
normalização dentro do plugin passa a ser a única fonte de verdade da
cardinalidade. Aceitável porque o mapa fica versionado no git, enquanto a lista
do painel do RD não fica.

**Pré-requisito de operação:** confirmar no painel que o `cf_setor` já existente
é do tipo texto. O RD normalmente não permite trocar o tipo depois de criado —
se nasceu lista, ou o mapa é ancorado nos valores exatos dela, ou é preciso um
campo novo.

### Valor canônico em português

O canônico é o label PT (`Setor Público`), não um slug (`setor-publico`), porque
quem lê esse campo é o time de marketing dentro do painel do RD.

### Valor fora do mapa: enviar cru + log `warn`

Divergente do `cf_uf`, que descarta valores inválidos — e a divergência é
proposital. O `cf_uf` tem contrato semântico real: precisa ser uma das 27
siglas, e `FORA DO BRASIL` genuinamente não é uma UF, então enviar sujaria o
campo. O `cf_setor` não tem contrato nenhum: é texto livre por decisão. Descartar
aqui significaria jogar fora a resposta de um campo **obrigatório** porque o
*nosso* mapa ficou velho, e reintroduziria exatamente a falha silenciosa que a
escolha de texto livre eliminou. O valor cru no painel é o sinal de que o mapa
precisa de manutenção.

### Tags: procedência somada à semântica

`bit-website-integration` é marcador de procedência e **soma** às tags
semânticas em vez de substituí-las, preservando a capacidade de segmentar
newsletter vs contato no RD.

| formulários | tags depois |
|---|---|
| 4 footers | `newsletter`, `concertacao-amazonia`, `footer-form`, `bit-website-integration` |
| Contato PT/EN | `contato`, `concertacao-amazonia`, `bit-website-integration` |

## Arquitetura

### Parte 1 — Plugin (v1.3.0)

**`bit-elementor-form-rdstation.php`:** bump de `VERSION` e do header
`Version:` para `1.3.0`.

**`bit-elementor-form-rdstation/class-form-action.php`:**

1. **Novo controle** em `register_settings_section()`, inserido entre
   `bit_rd_uf_field` e `bit_rd_tags`:

   ```php
   $widget->add_control(
       'bit_rd_sector_field',
       [
           'label'       => 'Campo Setor (custom_id)',
           'type'        => \Elementor\Controls_Manager::TEXT,
           'default'     => '',
           'placeholder' => 'field_8aee261',
           'description' => 'custom_id do field select de setor. Vazio = nao envia cf_setor.',
       ]
   );
   ```

   Mesmo tipo, mesmo default vazio e mesma semântica de "vazio = não envia" dos
   quatro controles que já existem.

2. **Mapa de normalização** como `private const SECTOR_MAP` da classe. Chaves em
   minúsculas via `mb_strtolower` — `Setor Público` tem acento e `strtolower`
   não dá conta:

   | chave (qualquer casing) | valor enviado |
   |---|---|
   | `public sector` · `setor público` | `Setor Público` |
   | `private sector` · `setor privado` | `Setor Privado` |
   | `civil society` · `sociedade civil` | `Sociedade Civil` |
   | `press` · `imprensa` | `Imprensa` |
   | `academia` | `Academia` |

3. **Leitura e montagem** em `run()`: `$sector_field` / `$sector_raw` junto com
   as outras leituras; bloco de montagem depois do `cf_uf` e antes das `tags`.
   Lookup no mapa; se ausente, envia `sanitize_text_field()` do valor cru e loga
   `warn`.

O whitelist `$br_states` do `cf_uf` fica exatamente onde está. Está funcionando,
e mexer nele não serve a este objetivo.

### Parte 2 — Configuração dos formulários

O controle novo nasce vazio, então nenhum formulário passa a enviar `cf_setor`
sozinho. Um script idempotente em `scripts/` faz **inserção cirúrgica no JSON
cru** do `_elementor_data`:

- **Nunca** `json_decode` + re-encode — re-encode introduz drift de bytes no
  eldata. A operação é substituição de string no JSON cru: acha
  `"bit_rd_uf_field":"form_regiao_desk"` e insere
  `"bit_rd_sector_field":"field_8aee261",` adjacente.
- Escrita com `wp_slash` — sem isso o `_elementor_data` grava `NULL`.
- Tags: substituição do valor de `bit_rd_tags` no mesmo passo, nos 6 posts.
- Idempotente: se `bit_rd_sector_field` já existir com o valor certo, não toca.
- Alcance assimétrico, e proposital: o `bit_rd_sector_field` entra **só nos 4
  footers** (os formulários de Contato não têm campo de setor, e a âncora
  `bit_rd_uf_field` nem existe neles); as tags são corrigidas nos **6** posts.
- Blog 2 exige `--url="https://cambrasmax.local:8484/cultura/"`; sem isso opera
  no blog 1 silenciosamente.

Script versionado em vez de 6 edições manuais no editor do Elementor, que
ninguém consegue auditar depois. Roda em dev e depois em prod.

## Validação

1. `std formtest submit` num footer PT e num footer EN.
2. Conferir `/var/log/bit-rdstation/YYYY-MM-DD.log`: `OK 200` e ausência de
   `warn` de setor. Requer `BIT_RDSTATION_DEBUG=true` para ver as linhas `info`.
3. Conferir no painel do RD que o lead tem `cf_setor` preenchido com o canônico
   PT e as 4 tags — inclusive no submit EN, provando a normalização.
4. Verificar que o `cf_setor` do submit EN é `Setor Público` e não
   `Public Sector`. É o critério que distingue esta implementação de enviar o
   label cru.
5. Estender o **Gate 55** do `/smoke` com asserção de `cf_setor` no payload.

## Deploy

1. Plugin: copiar para `docker-dev/common/mu-plugins/` e commitar no
   server-tools (regra do projeto para todo mu-plugin).
2. Prod: rsync do mu-plugin + `systemctl reload php8.3-fpm` — sem o reload o
   OPcache do pool FPM continua servindo o bytecode da v1.2.0.
3. Rodar o script de configuração em prod (os 6 posts, nos dois blogs).
4. Invalidação de cache não é necessária: a mudança é server-side no submit, não
   altera HTML renderizado.

## Riscos

| risco | mitigação |
|---|---|
| `cf_setor` no RD é lista, não texto | Verificar no painel antes de implementar. Se for lista, ancorar o mapa nos valores exatos dela. |
| Inserção no JSON cru corrompe o eldata | Substituição de string sem re-encode + `wp_slash`; script idempotente; backup do `_elementor_data` dos 6 posts antes de escrever. |
| Opção nova no formulário sem entrada no mapa | Valor cru é enviado (não descartado) e loga `warn` — aparece no painel do RD. |
| Script roda no blog errado | `--url=` obrigatório para o blog 2; o script valida `get_current_blog_id()` antes de escrever. |
