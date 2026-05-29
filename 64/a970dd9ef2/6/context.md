# Session Context

## User Prompts

### Prompt 1

o agente de outra sessão atualizou um projeto irmão do totem virtual e gerou um relatório sobre como atualizou a lista de artistas no atlas cultural.
~/scripts/reports/03-Web/concertacao-atlas-artistas/relatorio-migracao-2026-05-29.html
os dados originais utilizados na atualização estão neste csv
/Users/dcambria/Downloads/2026_Atlas\ Cultural_Versao\ Trabalho_MB_CP\ -\ ATUALIZAÇÃO\ \(4\).csv 
analise o relatório e avalie como atualizar corretamente 
Note também que uma cidade chamada "(Aldeia...

### Prompt 2

Base directory for this skill: /Users/dcambria/.claude/skills/sync-cpt-totem

# Sync CPT Totem-Concertação (genérico)

Sincroniza qualquer Custom Post Type (e dependências) do ambiente local em `~/scripts/server-tools/v2/docker-dev/sites/totem-concertacao/` para o totem WSL acessível via SSH `totem-concertacao`.

Substitui o `sync-artistas.sh` antigo (específico para artistas). O `sync-cpt.sh` é parametrizado por arquivos de config JSON em `sync-configs/<nome>.json` — cada CPT tem suas regras...

### Prompt 3

os filtros nao estao filtrando no mapa
[Image #1] precisa corrigir o label do filtro de país
investigue 1.

### Prompt 4

[Image: source: /Users/dcambria/Downloads/Captura de Tela 2026-05-29 às 11.23.19.png]

### Prompt 5

Base directory for this skill: /Users/dcambria/.claude/plugins/cache/claude-plugins-official/superpowers/5.1.0/skills/systematic-debugging

# Systematic Debugging

## Overview

Random fixes waste time and create new bugs. Quick patches mask underlying issues.

**Core principle:** ALWAYS find root cause before attempting fixes. Symptom fixes are failure.

**Violating the letter of this process is violating the spirit of debugging.**

## The Iron Law

```
NO FIXES WITHOUT ROOT CAUSE INVESTIGATI...

### Prompt 6

atualize a lista de estados e localidades conforme consta na lista csv, esta desatualizado no site

### Prompt 7

sim

### Prompt 8

[Image #4] vamos melhorar os labels? Acho que não precisamos colocar Filtrar por ou Filter by, porque são todos filtros. O que me sugere?

### Prompt 9

[Image: source: /var/folders/ng/9fzwjl211j9b9dfvlvqrs5800000gn/T/TemporaryItems/NSIRD_screencaptureui_Bh4Mgy/Captura de Tela 2026-05-29 às 12.26.29.png]

### Prompt 10

alterar a ordem... pais, estado, localidade

### Prompt 11

tem um nome melhor para localidade? pq nao sao cidades, tem terras indigenas, aldeias e cidades misturadas

### Prompt 12

otimo. ajuste tambem em portugues

### Prompt 13

[Image #5] [Image #6] [Image #7] otimo, mas faltou traduzir a lista desses filtros


area de atuacao

  ┌───────────────────┬───────────────────┐
  │        PT         │        EN         │
  ├───────────────────┼───────────────────┤
  │ Arquitetura       │ Architecture      │
  ├───────────────────┼───────────────────┤
  │ Audiovisual       │ Audiovisual       │
  ├───────────────────┼───────────────────┤
  │ Design            │ Design            │
  ├───────────────────┼───────────────────┤...

### Prompt 14

[Image: source: /var/folders/ng/9fzwjl211j9b9dfvlvqrs5800000gn/T/TemporaryItems/NSIRD_screencaptureui_RdBsny/Captura de Tela 2026-05-29 às 13.56.12.png]

[Image: source: /Users/dcambria/Downloads/Captura de Tela 2026-05-29 às 13.56.07.png]

[Image: source: /Users/dcambria/Downloads/Captura de Tela 2026-05-29 às 13.56.02.png]

### Prompt 15

# Poliglota — Guardião das Traduções WPML

Você é o **Poliglota**, guardião das traduções WPML.
Como um diplomata poliglota que conhece os protocolos de cada embaixada,
você navega entre idiomas com precisão cirúrgica — cada `trid` é um tratado
diplomático, cada idioma é um embaixador que precisa ser registrado corretamente.

Sua missão: nunca deixar um post sem tradução, nunca quebrar um `trid`, nunca
usar SQL direto onde existe API pública.

## Sua Personalidade

- **Preciso**: Conhece cada...

### Prompt 16

analise com 3 agentes sobre como atuaizar os filtros restantes usando o term eixo traduzido com wpml

### Prompt 17

cheque

### Prompt 18

Validar Atlas Cultural das Amazônias em ambos os idiomas, ambos os ambientes.

URLs:
- PT: `https://concertacaoamazonia.com.br/cultura/atlas-cultural-das-amazonias/`
- EN: `https://concertacaoamazonia.com.br/cultura/en/cultural-atlas-of-the-amazon/`

## Step 1 — Fechar contexto
Chame `mcp__MCP_DOCKER__browser_close`.

## Step 2 — Coletar 4 estados (PROD-PT, PROD-EN, GREEN-PT, GREEN-EN)

Para cada estado, sequencie: `browser_close` → `browser_run_code` com este snippet (substituindo `URL_AQUI`...

### Prompt 19

[Image #8] termos não estao traduzidos na lista da direita. Alguns artistas dessa lista nao estao clicáveis - falta geolocalização?

### Prompt 20

[Image: source: /Users/dcambria/Downloads/Captura de Tela 2026-05-29 às 16.48.19.png]

### Prompt 21

1. sim, coloque coordenadas. confira se o o geopoint-resolver está posicionando as coordenadas em espiral de fermat por localidade, isso é muito importante.

### Prompt 22

Base directory for this skill: /Users/dcambria/.claude/skills/geopoint-resolver

# GeoPoint Resolver

Resolve localidades para coordenadas geográficas e distribui múltiplos pontos usando a espiral de Fermat — o mesmo algoritmo usado pelo sistema `geopoint` (`supergeomarker.py`).

## Quando usar

- Usuário tem um CSV com localidades (cidade, estado, país) e precisa de coordenadas
- Precisa preencher/corrigir uma coluna `GEOLOCALIZAÇÃO` em um CSV existente
- Quer gerar pontos distribuídos dentr...

### Prompt 23

[Request interrupted by user]

### Prompt 24

mudei de ideia, não despublique. Mas então coloque uma condicional para que não exiba o icone + ao lado do artista que nao tem localidade, ok?

### Prompt 25

país Brasil/Brazil deve estar em primeiro na lista de países. O restante, em ordem alfabética.

está dando 404 em https://concertacao.bureau-it.com/wp-content/uploads/2025/10/qrcode-formulario.svg

### Prompt 26

teste tudo com playwright

### Prompt 27

[Request interrupted by user]

### Prompt 28

sim

### Prompt 29

Bateria smoke pós-deploy. Testa 5 páginas críticas + 2 formulários em prod e green + 1 paridade prod/dev:

| # | Página | URL |
|---|--------|-----|
| 1 | Home | `https://concertacaoamazonia.com.br/` |
| 2 | Atlas PT | `https://concertacaoamazonia.com.br/cultura/atlas-cultural-das-amazonias/` |
| 3 | Atlas EN | `https://concertacaoamazonia.com.br/cultura/en/cultural-atlas-of-the-amazon/` |
| 4 | Espiral | `https://concertacaoamazonia.com.br/conhecimento/espiral-de-conhecimento/` |
| 5 | Event...

### Prompt 30

primeiro faça o deploy do atlas en/pt para prod, depois rode o smoke completo em prod

### Prompt 31

retry

### Prompt 32

[Request interrupted by user]

### Prompt 33

travou?

### Prompt 34

[Request interrupted by user]

### Prompt 35

This session is being continued from a previous conversation that ran out of context. The summary below covers the earlier portion of the conversation.

Summary:
1. Primary Request and Intent:

The conversation spans multiple sequential requests about the Atlas Cultural das Amazônias (WordPress multisite, blog 2 `/cultura/`) on the Concertação Amazônia project:

- **Initial**: Analyze report `~/scripts/reports/03-Web/concertacao-atlas-artistas/relatorio-migracao-2026-05-29.html` and update ar...

### Prompt 36

continue

### Prompt 37

Bateria smoke pós-deploy. Testa 5 páginas críticas + 2 formulários em prod e green + 1 paridade prod/dev:

| # | Página | URL |
|---|--------|-----|
| 1 | Home | `https://concertacaoamazonia.com.br/` |
| 2 | Atlas PT | `https://concertacaoamazonia.com.br/cultura/atlas-cultural-das-amazonias/` |
| 3 | Atlas EN | `https://concertacaoamazonia.com.br/cultura/en/cultural-atlas-of-the-amazon/` |
| 4 | Espiral | `https://concertacaoamazonia.com.br/conhecimento/espiral-de-conhecimento/` |
| 5 | Event...

### Prompt 38

continue

