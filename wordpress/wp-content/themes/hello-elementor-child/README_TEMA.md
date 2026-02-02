# Hello Elementor Child Theme - Concertação Amazônia

Tema filho personalizado para o site da Concertação Amazônia, baseado no Hello Elementor Theme.

## 🎯 Visão Geral

Este tema contém customizações específicas para o site da Concertação Amazônia, incluindo:
- Customizações avançadas do The Events Calendar
- Templates personalizados
- Funcionalidades específicas do projeto

## 📋 Funcionalidades Principais

### The Events Calendar
- **Categoria "Edital"**: Exibição especial com texto "Edital disponível até: {data final}"
- **Formatação de datas**: Incluído "às" antes do horário
- **Categorias**: Exibição de categorias nos eventos com separador "@"
- **Timezone**: Exibição amigável "Horário de São Paulo"
- **Correções**: "Virtual Evento" → "Evento Virtual"

### Templates
- Override seguro de templates do The Events Calendar
- Compatibilidade com Elementor Pro
- Suporte a WPML para multilíngue

## 🚀 Instalação

1. Faça upload do tema para `/wp-content/themes/`
2. Ative o tema no painel administrativo
3. Certifique-se de que o The Events Calendar está instalado

## 📁 Estrutura do Projeto

```
hello-elementor-child/
├── functions.php              # Funcionalidades principais
├── style.css                  # Estilos do tema filho
├── tribe/                     # Overrides do The Events Calendar
│   └── events/v2/list/event/
│       └── date.php          # Template customizado de data
├── EVENTS_CALENDAR_CUSTOMIZATION.md  # Documentação sobre customizações
├── CHANGELOG.md              # Histórico de versões
└── README.md                 # Este arquivo
```

## 🔧 Desenvolvimento

### Ambiente
- **PHP**: 8.1+
- **WordPress**: 6.x
- **The Events Calendar**: Versão atual
- **Elementor Pro**: Para construção de páginas

### Ferramentas
- **WP-CLI**: Para gerenciamento via linha de comando
- **Git**: Controle de versão
- **Composer**: Gerenciamento de dependências (se necessário)

## 📖 Documentação

- **[CHANGELOG.md](CHANGELOG.md)**: Histórico completo de versões e mudanças
- **[EVENTS_CALENDAR_CUSTOMIZATION.md](EVENTS_CALENDAR_CUSTOMIZATION.md)**: Detalhes das customizações do Events Calendar

## 🔍 Monitoramento

### Logs
- Erros PHP: `/var/log/apache2/error.log`
- Acesso: `/var/log/apache2/access.log`

### Cache
- Redis: Limpeza regular necessária
- WordPress: Cache de objeto e transients

## 🛠️ Manutenção

### Atualizações
- Sempre testar em ambiente de desenvolvimento
- Verificar compatibilidade com plugins
- Manter backup antes de atualizações

### Backup
- Código versionado no Git
- Banco de dados: backup automático recomendado
- Arquivos de mídia: backup regular

## 📞 Suporte

- **Repositório**: https://github.com/Bureau-IT/concertacao-theme
- **Documentação**: Consulte os arquivos MD neste repositório
- **Issues**: Use o sistema de issues do GitHub

## 📄 Licença

Este projeto utiliza a mesma licença do WordPress (GPL v2 ou posterior).

## 🤝 Contribuição

1. Fork o projeto
2. Crie uma branch para sua feature (`git checkout -b feature/nova-feature`)
3. Commit suas mudanças (`git commit -am 'Adiciona nova feature'`)
4. Push para a branch (`git push origin feature/nova-feature`)
5. Abra um Pull Request

## 📈 Versão Atual

**Versão**: 2.1.0  
**Data**: 16 de julho de 2025  
**Status**: Produção

Para detalhes completos das mudanças, consulte o [CHANGELOG.md](CHANGELOG.md).

---

*Desenvolvido para a Concertação Amazônia*

## 📚 Sistema de Memória de Sessões

Este projeto utiliza um sistema de **memória de sessões** para garantir continuidade entre diferentes sessões de trabalho com IA.

### Arquivos de Memória:
- `MEMORY_SESSION_YYYYMMDD.md` - Documentação detalhada de cada sessão
- `CHANGELOG.md` - Histórico de versões e mudanças
- `EVENTS_CALENDAR_CUSTOMIZATION.md` - Documentação técnica específica

### Para Próximas IAs:
1. **SEMPRE** leia os arquivos `MEMORY_SESSION_*` antes de iniciar
2. **CRIE** um novo arquivo de memória para sua sessão
3. **DOCUMENTE** todas as ações realizadas
4. **ATUALIZE** o CHANGELOG com suas modificações

Isso garante que o conhecimento seja preservado e o trabalho continue de forma eficiente.

