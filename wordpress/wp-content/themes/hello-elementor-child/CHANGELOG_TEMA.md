# Changelog - Hello Elementor Child Theme

Todas as mudanças notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/),
e este projeto adere ao [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

