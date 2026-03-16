# Session Context

## User Prompts

### Prompt 1

Implement the following plan:

# Deploy DB Local → Produção (concertacaoamazonia.com.br)

## Contexto

O banco de dados local Docker (cambrasmax.local:8484) precisa ser enviado para o servidor EC2 de produção (52.67.96.50, SSH alias: `concertacaoamazonia.com.br-prod-sa`). Após o import, é necessário executar search-replace das URLs locais pelas URLs de produção.

## Passos

### 1. Exportar DB local
```bash
/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh export-db -...

### Prompt 2

Base directory for this skill: /Users/dcambria/.claude/skills/save-report

# Skill: save-report

## Quando usar

Sempre que um relatório for gerado durante uma conversa — seja de saúde de infraestrutura, análise de performance, diagnóstico, otimização, migração, ou qualquer documento de entrega técnica.

## Estrutura de diretórios obrigatória

```
~/scripts/reports/[área bureau]/[nome do programa]/relatorio-[tipo]-YYYY-MM-DD.[ext]
```

## Áreas bureau válidas

| Código | Nome |
|--------|----...

### Prompt 3

<task-notification>
<task-id>b91kf43xq</task-id>
<tool-use-id>toolu_01LfkhyeemSTJoRCZcmZWMVZ</tool-use-id>
<output-file>/private/tmp/claude-501/-Users-dcambria-scripts-server-tools-v2-docker-dev-sites-concertacao/f1e4c3c2-e6fa-464b-b622-b417d4f388e0/tasks/b91kf43xq.output</output-file>
<status>completed</status>
<summary>Background command "Upload DB dump to EC2 via SCP" completed (exit code 0)</summary>
</task-notification>
Read the output file to retrieve the result: /private/tmp/claude-501...

### Prompt 4

algumas imagens estao faltando no servidor, como https://concertacaoamazonia.com.br/wp-content/uploads/2026/03/ACFP_Logos_Color.jpg, entre outras. rode um rsync pra transferir as imagens que estao faltando

### Prompt 5

o a11y também está desatualizado

### Prompt 6

<task-notification>
<task-id>bw626auu4</task-id>
<tool-use-id>REDACTED</tool-use-id>
<output-file>/private/tmp/claude-501/-Users-dcambria-scripts-server-tools-v2-docker-dev-sites-concertacao/f1e4c3c2-e6fa-464b-b622-b417d4f388e0/tasks/bw626auu4.output</output-file>
<status>completed</status>
<summary>Background command "Rsync uploads to EC2 (background, only missing files)" completed (exit code 0)</summary>
</task-notification>
Read the output file to retrieve the result:...

### Prompt 7

sim, lupa de cursor (magnifier) do bureau-a11y. o a11y mais atualizado está rodando localmente em https://concertacao.bureau-it.com/

### Prompt 8

[Image: source: /var/folders/ng/9fzwjl211j9b9dfvlvqrs5800000gn/T/TemporaryItems/NSIRD_screencaptureui_LR2GRC/Captura de Tela 2026-03-16 às 09.11.13.png]

### Prompt 9

[Request interrupted by user]

### Prompt 10

local está certo!

