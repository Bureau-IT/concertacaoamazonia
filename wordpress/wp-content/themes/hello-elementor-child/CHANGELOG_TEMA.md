# Changelog - Hello Elementor Child Theme

Todas as mudanças notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/),
e este projeto adere ao [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.3.2] - 2026-08-03

### Alterado
- `css/plugins/complianz.css` — o **painel do plugin volta a ser a fonte da
  verdade das cores do cookie banner**. O arquivo tinha 21 declarações com
  `!important` que sobrepunham tudo que estivesse configurado em
  *Complianz → Cookie Banner → Aparência*: mudar cor no admin não surtia
  efeito. Passou de 117 para 55 linhas e não tem mais **nenhum hex literal**.
  - **Onde as cores ficam agora:** `wp_cmplz_cookiebanners` (painel), de onde o
    plugin gera `uploads/complianz/css/banner-N-optin.css` com as variáveis
    `--cmplz_*` no `:root`. Paleta da marca aplicada nos 2 blogs por
    `scripts/set-complianz-palette.php` (novo, idempotente, com dry-run):
    fundo `#F8EAD9`, texto/links `#21191B`, Aceitar sólido `#21191B` sobre
    `#F8EAD9`, Rejeitar/Personalizar/Salvar em outline, toggles
    `#21191B`/`#F8EAD9`/`#B8AA9B` (eram azul `#1e73be` e laranja `#F56E28`
    de fábrica).
  - **Isso resolve na raiz** o motivo pelo qual o v2.3.1 cravou literais: o
    banner era o componente que dependia 100% dos Global Colors, e os kits de
    DEV/HML estão na paleta default de fábrica. Ligado ao painel do Complianz,
    o banner deixa de depender do kit — a divergência de paleta do kit
    continua aberta, mas não afeta mais o banner.
  - **O que sobrou no tema:** só os estados de `:hover`, que o painel não
    expõe. Derivados das próprias `--cmplz_*` via `color-mix()`/inversão, então
    seguem o painel automaticamente. Sem `!important`: `:hover` já tem
    especificidade maior que a regra-base do plugin.
  - **Removido:** bloco morto do `#cn-accept-cookie` (plugin legado
    *cookie-notice*, não instalado) e a regra de `fill` do X de fechar (o SVG
    usa `fill="currentColor"`, já herda `--cmplz_text_color`).
  - Validado com `getComputedStyle` em browser real nos 2 blogs (incluindo
    hover real e a view de categorias): fundo `rgb(248,234,217)`, textos e
    bordas `rgb(33,25,27)`, Aceitar `rgb(33,25,27)`/`rgb(248,234,217)`,
    Rejeitar e Salvar em outline, toggles na paleta. Nenhum texto do banner
    herda cor de fora.

### Pendente
- A cor do rótulo "Sempre ativo" (`--cmplz_category_header_always_active_color`)
  é o literal `green` do próprio plugin e não está no painel — é a única cor
  fora da paleta que restou no banner.

## [2.2.49] - 2026-06-22

### Corrigido
- `css/plugins/tec.css` — overlay "véu" dos cards de **Eventos relacionados** na
  single de evento (seção 7.5). O EPTA inline cravava `#D7DCC0` (verde-oliva
  legado) com `!important` na camada `.epta-light-bg` (overlay absoluto opacity
  .88 por cima das thumbnails) → as imagens dos eventos relacionados apareciam
  com tom esverdeado ("CSS quebrado" relatado). Alinhado ao bege global
  (`--e-global-color-secondary` `#F8EAD9`) com âncora elevada
  (`html body.single-tribe_events`) para vencer o `!important` do inline.
  Validado via Playwright: 4 overlays → `#F8EAD9` + screenshot dos cards limpos.

## [2.2.48] - 2026-06-22

### Corrigido
- `css/plugins/tec.css` — **blindagem de cascata** do hover dos botões da single
  de evento (seção 7.2). O CSS inline do EPTA é injetado no RODAPÉ da página
  (depois do `tec.css`), e como ambos usam `!important`, num empate de
  especificidade a ordem de origem favorecia o inline (hover voltava ao escuro).
  Âncora elevada para `html body.single-tribe_events #epta-template...` (id + 4
  classes + elemento → especificidade estritamente maior que a do inline),
  cobrindo `:hover`, `:focus` e `:active`. Mesma âncora aplicada ao hover dos
  links de compartilhamento. Validado via Playwright (hover real + screenshot):
  botão "+ Google Calendar"/"+ iCal Export" → fundo rosa `#FE78A9` + texto
  escuro `#392E34` no hover.

## [2.2.47] - 2026-06-22

### Corrigido
- `css/plugins/tec.css` — **página single de evento** (`/event/<slug>/`, renderizada
  pelo plugin EPTA com `epta-template-1`) — paridade de paleta com o resto do site
  (seção 7, nova):
  - **Causa-raiz:** o CSS inline do EPTA referencia variáveis LEGADAS
    `--ucpa-color-offwhite|white|accent` que não existem mais no site (a paleta
    migrou para os Global Colors do Elementor) → fundo, boxes da sidebar, células
    do countdown e botões ficavam sem cor (var indefinida).
  - **Fix:** definidos os 3 aliases legados mapeados para os Global Colors atuais
    (`--e-global-color-secondary` bege, `-f589ade` branco, `-accent` rosa),
    escopados a `body.single-tribe_events`. Assim o inline do EPTA passa a resolver.
  - **Fundo BEGE** (`#F8EAD9`) reforçado no body/wrapper/sidebar (paridade com a home).
  - **Botões "+ Google Calendar" / "+ iCal Export":** base branca com texto/borda
    escuros (`#392E34`); HOVER vira ROSA (`#FE78A9`) com texto escuro legível —
    corrige bug do EPTA inline que pintava texto escuro sobre fundo escuro (rótulo
    sumia no hover).
  - **Links de compartilhamento** (`.epta-share-area`): hover em rosa accent.
  - **Títulos de seção da sidebar** ("Detalhes"/"Local"/"Organizador"): o
    `epta-style.css` cravava caixa preta (`#222222`); alinhado para fundo
    transparente + texto escuro.
  - **Caixa de avisos** (`.tribe-events-notices`): substituído o `#D7DCC0` legado
    pelo bege global.
  - Validado via getComputedStyle (browser real) na single de evento.

## [2.2.46] - 2026-06-22

### Corrigido
- `css/plugins/tec.css` — botão "Adicionar agenda" (subscribe dropdown do TEC)
  no estado BASE (fechado): texto e borda vinham verdes (`#0B4334`) do TEC
  nativo (`.tribe-common-c-btn-border`); alinhados à cor de texto (`#392E34`).
  Estados hover/aberto (fundo rosa) preservados.

## [2.2.45] - 2026-06-22

### Modificado
- `css/plugins/tec.css` — paridade de paleta da página de eventos (TEC) com a home:
  - **Datepicker:** dia marcado/hoje/hover agora usa o accent global (rosa `#FE78A9`)
    em vez do verde `#0B4334` do TEC; grid, título do mês e setas com fundo
    transparente (eliminado o grid preto herdado do botão global do kit Elementor)
    e texto na cor de texto (`#392E34`).
  - **Página de eventos:** fundo bege (`#F8EAD9`) igual ao da home; títulos de
    evento, data do edital e ícones na cor de texto (`#392E34`) em vez de verde;
    botão "ENCONTRAR EVENTOS" com fundo primário escuro (`#21191B`); caret do
    botão "Adicionar agenda" na cor de texto.

## [2.2.0] - 2025-07-16

### Adicionado
- Documentação consolidada `EVENTS_CALENDAR_CUSTOMIZATION.md`
- Seção de troubleshooting detalhada
- Guia de deployment e rollback
- Exemplos de código completos

### Modificado
- Consolidação dos relatórios de customização em um único arquivo
- README.md atualizado com nova estrutura
- Referências de documentação atualizadas

### Removido
- Arquivo `CUSTOMIZACAO_EVENTS_CALENDAR.md` (consolidado)
- Templates page-* não utilizados:
  - `page-artes.php`
  - `page-eixo.php`
  - `page-mensagens.php`
  - `page-quem-somos.php`
  - `page-rede.php`
  - `page-typeform.php`

### Organização
- Estrutura de documentação simplificada
- Limpeza de arquivos desnecessários
- Padronização de nomenclatura

## [2.1.0] - 2025-07-16

### Adicionado
- Configuração completa do repositório Git
- Chave SSH para deploy automático
- Migração para repositório `Bureau-IT/concertacao-theme`

### Modificado
- Remote do Git atualizado para novo repositório
- Limpeza completa de arquivos de backup (.bak, .backup)

### Removido
- Arquivos de backup desnecessários
- Referências ao repositório antigo

## [2.0.0] - 2025-07-16

### Adicionado
- Sistema completo de customização do The Events Calendar
- Suporte especial para categoria "Edital" com texto personalizado
- Exibição de categorias nos eventos
- Formatação aprimorada de datas e horários
- Suporte a timezone "Horário de São Paulo"
- Documentação técnica completa

### Modificado
- Formato de datas: incluído "às" antes do horário
- Texto "Virtual Evento" corrigido para "Evento Virtual"
- Separador de categoria alterado de "#" para "@"
- Timezone display melhorado (de "-3" para "Horário de São Paulo")

### Corrigido
- Problemas de internacionalização com wp_date()
- Escape HTML indesejado na exibição de datas
- Problemas de permissão no sistema de arquivos
- Compatibilidade com WPML

### Técnico
- Implementação de filtros WordPress:
  - `tribe_events_get_the_excerpt`
  - `tribe_get_start_date`
  - `tribe_get_end_date`
  - `tribe_events_event_schedule_details`
- Override de templates do The Events Calendar
- Função personalizada `formatar_data_evento()`
- Lógica condicional para categoria "Edital"

## [1.2.0] - 2025-07-16

### Análise de Templates
- Auditoria completa dos templates de página
- Identificação de templates page-* não utilizados
- Verificação no banco de dados via WP-CLI

### Descobertas
- 89 páginas publicadas no site
- Maioria usa template `default`
- Apenas 2 páginas usam `elementor_canvas`
- 6 templates page-* legados identificados (removidos na v2.2.0)

### Recomendações Implementadas
- Remoção de templates não utilizados
- Foco em templates default e elementor_canvas
- Manutenção do uso do Elementor Pro

## [1.1.0] - 2025-07-15

### Infraestrutura
- Configuração inicial do ambiente de desenvolvimento
- Verificação de permissões do sistema
- Configuração do usuário www-data
- Preparação para override de templates

### Segurança
- Implementação de backups automáticos
- Configuração de permissões adequadas
- Proteção contra sobrescrita de customizações

## [1.0.0] - Data Base

### Inicial
- Tema Hello Elementor Child configurado
- Estrutura básica do WordPress
- Configuração inicial do The Events Calendar

---

## Formato das Versões

### [MAJOR.MINOR.PATCH]
- **MAJOR**: Mudanças que quebram compatibilidade
- **MINOR**: Novas funcionalidades mantendo compatibilidade
- **PATCH**: Correções de bugs e melhorias menores

### Tipos de Mudanças
- **Adicionado**: Para novas funcionalidades
- **Modificado**: Para mudanças em funcionalidades existentes
- **Depreciado**: Para funcionalidades que serão removidas
- **Removido**: Para funcionalidades removidas
- **Corrigido**: Para correções de bugs
- **Segurança**: Para vulnerabilidades corrigidas
- **Técnico**: Para detalhes de implementação
- **Organização**: Para melhorias estruturais

## Próximas Versões Planejadas

### [2.3.0] - Em Planejamento
- Melhorias na estilização CSS
- Suporte a mais categorias especiais
- Otimizações de performance

### [2.4.0] - Em Planejamento
- Integração com APIs externas
- Campos personalizados avançados
- Testes automatizados

---

## Suporte e Manutenção

### Monitoramento
- Logs de erro PHP: `/var/log/apache2/error.log`
- Logs de acesso: `/var/log/apache2/access.log`
- Cache Redis: Limpeza regular necessária

### Backup
- Todos os arquivos estão versionados no Git
- Backup automático do banco de dados recomendado
- Documentação técnica mantida atualizada

### Contato
- Repositório: https://github.com/Bureau-IT/concertacao-theme
- Documentação técnica: `EVENTS_CALENDAR_CUSTOMIZATION.md`
- Ambiente: Servidor Ubuntu com Apache, PHP 8.1, WordPress 6.x

---

*Última atualização: 16 de julho de 2025*

## [2.2.2] - 2025-07-16

### Otimizações
- **CSS**: Otimizado `style.css` removendo ~30% do código desnecessário
  - Removidos estilos para `.tribe-events-widget` (não utilizado)
  - Removidos estilos gerais para `.tribe-events-calendar-month` (mantido apenas tooltips)
  - Removido seletor `.ectbe-ev-cate` (classe inexistente)
  - Mantidos apenas estilos essenciais para `.event-category` e `.event-edital`
  - Preservada responsividade e garantia de visibilidade

### Análise Técnica
- Verificação de uso real dos estilos CSS através de análise do HTML gerado
- Confirmação de que templates customizados estão funcionando corretamente
- Foco em estilos que realmente impactam a funcionalidade

### Resultado
- CSS mais limpo e eficiente
- Funcionalidade mantida integralmente
- Redução de 67 para 47 linhas de CSS
- Melhor manutenibilidade do código

