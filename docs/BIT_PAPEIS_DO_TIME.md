# BIT_PAPEIS_DO_TIME — quem responde pelo quê no site Concertação

> **Documento interno · Bureau de Tecnologia (BIT BPO) · criado em 2026-09-26 · Autoria: Daniel Cambría**
>
> **Arquivo padrão BIT** (prefixo `BIT_` = configuração de projeto do Bureau, presente em todo
> projeto): o time do projeto, seus IDs no ClickUp e o mapa de atribuições. As skills de
> ClickUp/pendências **leem os papéis daqui** — nunca os carregam embutidos.
>
> Time declarado pelo Daniel no setup de 26/09/2026; IDs conferidos na API do ClickUp
> (workspace 3035595) no mesmo dia.

## O time

| Pessoa | ClickUp | E-mail | Papel no workspace |
|---|---|---|---|
| **Daniel Cambría** | `3064613` | daniel.cambria@bureau-it.com | owner |
| **Ana Paula Ramos** | `88010598` | anapaula@bureau-it.com | member |
| **Fabricio Ferrari** | `54607867` | fabricio@bureau-it.com | member |

**O executor IA:** tarefas executadas pela IA são atribuídas ao usuário **bit-ai**
(`118117013` · bit-ai@bit-bpo.com) — nunca a uma pessoa que não vai executá-las.

> **Pendência de acesso (26/09/2026, ainda aberta em 01/10/2026):** o `bit-ai` é guest do workspace mas **não tem acesso ao
> space `BIT | Clientes`** (901310077265), onde mora a lista `901314423885` — a API responde
> `ACCESS_065`. Sem isso o sync não lê nem escreve a lista. Conceder pela UI do ClickUp
> (compartilhar o space, ou só a pasta `Concertação Amazônia`, com bit-ai@bit-bpo.com).
>
> Homônimo a não confundir: **Fabrício IA** (`118120825`, guest) é o par de IA do Fabricio, não
> ele.

## O mapa

| Frente | Dono | O que inclui |
|---|---|---|
| **Responsável pelo projeto** | **Daniel** | Decisões, relação com o cliente, deploy em produção, infraestrutura AWS |
| **Ana Paula Ramos** | *a declarar* | — |
| **Fabricio Ferrari** | *a declarar* | — |

As frentes da Ana e do Fabricio ainda não foram declaradas; até lá, tarefa nova vai para o
Daniel, que redistribui.

## A regra de atribuição no ClickUp

1. Um responsável por tarefa, escolhido entre as pessoas acima; tarefa que a IA executa vai para o
   `bit-ai`, com o dono humano na linha `**Dono:**` do `BIT_PENDENCIAS.md`.
2. Assunto que atravessa frentes: o responsável é **quem destrava o próximo passo**.
3. Pendência com dono vive em `BIT_PENDENCIAS.md` **e** tem tarefa na lista `901314423885`;
   o arquivo linka a tarefa (`https://app.clickup.com/t/{id}`).
