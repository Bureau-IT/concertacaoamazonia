# BIT_PENDENCIAS — fila única do site Concertação

> **Documento interno · Bureau de Tecnologia (BIT BPO) · criado em 2026-09-26 · Autoria: Daniel Cambría**
>
> **Arquivo padrão BIT** (prefixo `BIT_` = configuração de projeto do Bureau, presente em todo
> projeto). Site concertacaoamazonia.com.br (multisite: blog 1 na raiz, blog 2 em `/cultura/`).
>
> **Este projeto é um site do Bureau**, e por isso mora em `bit-servertools/docker-dev/sites/<site>/`,
> não em `~/bit-apps/<projeto>/`. A categoria foi autorizada pelo Daniel em 01/09/2026.
>
> **Parâmetros ClickUp deste projeto** — as skills leem daqui, nunca de valores embutidos:
> lista **`901314423885`** (`concertacaoamazonia.com.br`) · pasta **Concertação Amazônia**
> (`901312882141`) · space **BIT | Clientes** (`901310077265`) · workspace `3035595` ·
> tags de gate: **nenhuma** — decisão do Daniel em 26/09/2026, site de cliente com fila plana ·
> campos `AI Documento` / `AI Plano` / `AI Spec`: **não existem nesta lista** — campo customizado
> só nasce pela UI do ClickUp, nunca pela API ·
> statuses: `backlog` · `pausado` · `pronto para iniciar` · `em andamento` · `revisão solicitada` ·
> `concluído`.
> Papéis e IDs do time: `BIT_PAPEIS_DO_TIME.md`.
>
> **Legenda dos marcadores** — `[ ]` backlog · `[-]` pausado · `[>]` pronto para iniciar ·
> `[/]` em andamento · `[?]` revisão solicitada · `[x]` concluído.
>
> **Tipos de tarefa** — `Decisão` · `Obra` · `Operação`.
>
> **Os statuses acima são o padrão de sites, e a lista ainda não chegou nele.** Medido por
> `GET /list/901314423885` em 01/10/2026, a lista tem oito: `backlog` · `pausado` ·
> `pronto para iniciar` · `em andamento` · `revisão técnica` · `revisão de arte` ·
> `resolvido em dev` (os três `type: done`) · `concluído` (`type: closed`). Decisão do Daniel no
> setup de 01/10/2026: **padronizar** no modelo *Bureau Concertação*, como os outros sites desde
> 26/09/2026. Os três intermediários viram `revisão solicitada`; os cinco cards do VLibras que
> estavam em `resolvido em dev` caem lá e aparecem aqui como `[?]`. A troca é na UI do ClickUp
> (o modelo de statuses não se aplica pela API). Até ela acontecer, todo card num dos três
> statuses antigos fica sem marcador, e o sync alerta em vez de escrever.
>
> **O sync ainda não alcança a lista.** O token do par de IA (`bit-ai`) recebe
> `You do not have access to this Space` no space `BIT | Clientes`, medido em 01/10/2026 — o mesmo
> `ACCESS_065` do rascunho de 26/09. Conceder pela UI (compartilhar a pasta Concertação Amazônia
> com bit-ai@bit-bpo.com). Os dados deste setup foram lidos com o token do Daniel.
>
> **O agrupador desta lista não é tag, é o campo "Página do site"** (drop-down
> `5abdce36-7800-4733-b62b-8b69864905db`: GLOBAL · Página Inicial · Sobre Nós · Atuação ·
> Publicações · Conhecimento · Cultura · Contato). As seções abaixo seguem o assunto, não o campo.
>
> **O modelo:** este arquivo é a fonte da verdade do **conteúdo** (o quê, porquê, números,
> histórico); o ClickUp é a projeção **operacional** (responsável, prazo, status, notificação).
> Tarefa que já existe no ClickUp entra aqui **por link**, sem duplicar o texto. Tarefa nova entra
> no ClickUp **antes** da execução. Tarefa cujo executor é a IA vai para o usuário
> `bit-ai@bit-bpo.com` (ID `118117013`). Ao resolver: marcar e mover para "Resolvidas" — nunca
> apagar. A conferência de espelho roda pela skill **`/bit-pendencias:audit`**.
>
> **Quem executa marca `[?]` ao entregar — nunca `[x]`.** O `[x]` é da aprovação: a Ana move o card
> para `concluído` e a linha desce para Resolvidas sozinha. Uma linha `[x]` faria o sync escrever
> `concluído` e pular a revisão que a etapa existe para ter.

## Busca do site (GLOBAL)

- [ ] **Painel de busca EN mostra o template PT (4360)** — a tradução 5638 ficou para trás no
  visual e nunca é exibida. Refazer o 5638 a partir do 4360 preservando os textos EN.
  **Dono: Daniel** · **Executor: bit-ai**
  **Tipo: obra · Estimativa: 3h**
  ClickUp: <https://app.clickup.com/t/86akrdgec>
- [ ] **Filtro da página /sobre-nos/participantes/ não filtra** (JSF 5098, dev e prod, medido em
  25/09/2026). Por isso o link de participante da busca leva à página sem pré-filtro.
  **Dono: Daniel** · **Executor: bit-ai**
  **Tipo: obra · Estimativa: 2h**
  ClickUp: <https://app.clickup.com/t/86akrdged>
- [?] **Participantes saem da busca, por ora** — decisão do Daniel em 02/10/2026: a busca não deve
  encontrar participantes neste momento. A fonte `bit_participantes` (CCT do JetEngine, blog 1) sai
  do `bit-crossblog-search` em dev e em prod, junto com a aba. O espelho em
  `bit-servertools/docker-dev/common/mu-plugins/` é repositório vizinho e fica a pedido.
  **Entregue em 02/10/2026:** 1.6.1 (`23cd4889cb`) em dev e em prod (MD5 igual ao commit, FPM
  recarregado). Em prod, `/busca/?s=Deborah Vieitas` responde "Nenhum resultado"; as abas de
  `amazonia` ficam em Tudo · Estudos · Notícias · Eventos · Cultura · Exposições, e o REST do
  dropdown não devolve a fonte nem quando ela é pedida. Religar:
  `BIT_CROSSBLOG_SEARCH_WITH_PARTICIPANTS`.
  **Dono: Daniel** · **Executor: bit-ai**
  **Tipo: obra · Estimativa: 2h**
  ClickUp: <https://app.clickup.com/t/86akrx2w4>
- [ ] **Anomalias de conteúdo achadas pela busca** — duplicatas e lixo listados na memória
  `project_anomalias_conteudo_busca_2026_09`; a galeria-1 fica no banco (decisão de 25/09).
  **Dono: Daniel**
  **Tipo: decisão**
  ClickUp: <https://app.clickup.com/t/86akrdgee>

## Redirecionamentos

- [?] **Redirects dos 100 dias em prod igual ao dev** — o Redirection de prod diverge do dev em
  duas regras, medido em 02/10/2026 sobre as 53: a 160 (`/100-dias/`) ainda aponta para
  `https://www.concertacaoamazonia.com.br/100-dias/`, e no dev aponta para o estudo
  `/estudos/100-primeiros-dias-de-governo-propostas-para-uma-agenda-integrada-das-amazonias/`; a
  176 (estudo → `-2`) está `enabled` em prod e `disabled` no dev. O slug do estudo em prod é o
  limpo, então a 176 aponta para um 404.
  **Entregue em 02/10/2026:** a 160 foi atualizada e a 176 desligada pela API do Redirection
  (`Red_Item`). Agora as 53 regras são iguais nos dois lados (diff vazio), e o CloudFront do
  `/100-dias/` foi invalidado. Em prod, `/100-dias/` dá um 301 para o estudo, que responde 200.
  **Dono: Daniel** · **Executor: bit-ai**
  **Tipo: operação · Estimativa: 1h**
  ClickUp: <https://app.clickup.com/t/86akrx2wg>

## Acessibilidade e conformidade (GLOBAL)

- [?] Auditar VLibras v7.8.0 nos outros sites BIT (www-concertacao, totem) — o www-concertacao
  foi resolvido em 31/08/2026 (spec de contrato 4/4 em prod, comentário no card); o totem ficou
  para depois por decisão. https://app.clickup.com/t/86ak84f6z
- [?] VLibras v7.8.0 · T1 spec de contrato — https://app.clickup.com/t/86ak7ef8x
- [?] VLibras v7.8.0 · T2 CSS 2.11.0 — https://app.clickup.com/t/86ak7efdd
- [?] VLibras v7.8.0 · T3 JS 2.11.0 — https://app.clickup.com/t/86ak7effu
- [?] VLibras v7.8.0 · T4 PHP 2.13.0 — https://app.clickup.com/t/86ak7efj2
- [?] VLibras v7.8.0 · T5 espelhar no site e validar em dev — https://app.clickup.com/t/86ak7efn5

  Subtarefas da reintegração do VLibras, hoje em `resolvido em dev`; a tarefa-mãe (86ak7ef5a) está
  concluída. Viram `revisão solicitada` com a padronização da lista.
- [ ] Conformidade reCAPTCHA — aviso de privacidade no site —
  https://app.clickup.com/t/86aj5k1xq
- [?] Corrigir tags HTML — a regra do gate 31 passa em 18/18 páginas de prod, medido em
  02/10/2026 (um `h1`, hierarquia sem salto, `<main>`, e `<article>` nos singles de CPT); em
  19/05/2026 eram 2/18. https://app.clickup.com/t/86ahjtwnw

## Formulários e RD Station

- [-] Automatizar dupla validação de e-mails do form do rodapé —
  https://app.clickup.com/t/86afcr8pw
- [-] Integrar n8n às respostas dos formulários Rota 26-30 —
  https://app.clickup.com/t/86agf9r76
## Layout mobile

- [>] Mobile 5 pilares — reconstruir a mecânica do mobile (corte da foto de fundo) —
  https://app.clickup.com/t/86aj4640e
- [>] Mobile 5 pilares — sugestão: quadros em duas colunas — https://app.clickup.com/t/86aj462hd

## IA

- [>] Modelos de IA — https://app.clickup.com/t/86a6w49u8
- [-] Montar RAG de exemplo para busca de estudos — https://app.clickup.com/t/86a6w4an1
- [-] Montar modelo de agente para catalogar a redezona — https://app.clickup.com/t/86a6w4a0u

## Remanescentes da virada de 2025

Cards abertos desde junho/2025, sem atualização desde então. Ficam por link até o Daniel decidir
se fecham ou voltam à fila.

- [ ] Alterações de Menu — https://app.clickup.com/t/86a9g6191
- [?] Solicitar mini biografia de artista — no dev, 1.292 dos 1.311 artistas do Atlas (PT+EN)
  têm texto; 19 estão sem. https://app.clickup.com/t/86a9g6154

## Resolvidas

- [x] **Deploy da busca cross-blog em produção** — abas por categoria, página `/busca/`, fontes do
  blog 2. Subiu com o blue-green (fases 7 e 8 `completed` no state). Medido em 01/10/2026:
  `https://concertacaoamazonia.com.br/busca/?s=amazonia` responde 200 com as 7 abas
  (`bit-busca__tabs`) e `Cache-Control: no-store`. Falta conferir a versão do mu-plugin em prod
  (a `main` está na 1.6.0; o SSH caiu em timeout neste dia) e rodar o spec abaixo.
  **Dono: Daniel** · **Executor: bit-ai**
  **Tipo: operação · Estimativa: 3h**
  Evidência: `cd testes && BASE_URL=https://concertacaoamazonia.com.br npx playwright test 13-busca-crossblog.spec.js`
  ClickUp: <https://app.clickup.com/t/86akrdgeb>
  *(concluído no card em 02/10/2026)*

- [x] Ajuste de footer — newsletter — https://app.clickup.com/t/86ajzn0rc
      *(concluído no card em 02/10/2026)*

- [x] Segmentar contatos do site no RD Station — https://app.clickup.com/t/86aedgrc8
      *(concluído no card em 02/10/2026)*

- [x] Ajustar pesquisa do site — "a pesquisa, quando está logado no admin, não desaparece".
  https://app.clickup.com/t/86aegb40j
  *(concluído no card em 02/10/2026)*

Índice de uma linha por item. O histórico completo vive no card e no `git log`.

- [x] Busca cross-blog 1.0–1.6.0 em dev (fontes do blog 2, página /busca/, abas, trava de site, troca sem recarregar) — 25/09–01/10/2026
- [x] Recolocar a lupa no header — https://app.clickup.com/t/86aap1657 — card fechado até 01/10/2026
- [x] Espelho do `bit-crossblog-search` em `bit-servertools/common/mu-plugins` — PR #60 mesclado (1.5.1), PR #59 fechado; o canônico está na 1.6.0 — 01/10/2026
- [x] Checagem geral sobre alteração de textos principais — https://app.clickup.com/t/86a9g618t — lembrete de processo da virada de 2025, sem objeto depois dela — 02/10/2026
- [x] Checagem geral sobre modificações estruturais — https://app.clickup.com/t/86a9g6155 — idem — 02/10/2026
- [x] Primeira auditoria do espelho (`/bit-pendencias:audit`): lista padronizada (6 statuses), bit-ai com acesso, 22 casadas, 4 cards criados pelo sync (86akrdgeb–86akrdgee), 0 órfãs, 0 deriva — 01/10/2026
