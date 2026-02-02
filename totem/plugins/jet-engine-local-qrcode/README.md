# JetEngine - Local QR Code Generator

Plugin que substitui o gerador de QR Code externo do JetEngine (api.qrserver.com) por uma solução local usando a biblioteca PHP `chillerlan/php-qrcode`.

## Autor
Daniel Cambría + Warp

## Funcionalidades

- ✅ Geração local de QR codes (sem chamadas externas)
- ✅ Mantém compatibilidade total com JetEngine
- ✅ Sistema de cache integrado (transients do WordPress)
- ✅ Suporte a SVG responsivo
- ✅ Configuração de tamanho dinâmica

## Instalação

1. O plugin já está instalado em: `/wp-content/plugins/jet-engine-local-qrcode/`
2. As dependências foram instaladas via Composer
3. Ative o plugin no WordPress:
   ```bash
   wp plugin activate jet-engine-local-qrcode
   ```

## Como funciona

O plugin intercepta as chamadas do callback `jet_engine_get_qr_code` do JetEngine e substitui a geração remota por local usando:

- **Biblioteca**: chillerlan/php-qrcode v5.0
- **Formato**: SVG (escalável e leve)
- **Cache**: 24 horas via transients do WordPress
- **Hook**: `jet-engine/listings/dynamic-field/field-value` (prioridade 999)

## Vantagens sobre a solução anterior

1. **Privacidade**: Dados não são enviados para servidores externos
2. **Performance**: Geração local é mais rápida
3. **Confiabilidade**: Não depende de APIs de terceiros
4. **Offline**: Funciona sem conexão com internet
5. **Customização**: Total controle sobre o formato e qualidade

## Testes

Execute o script de teste:
```bash
wp eval-file /var/www/html/wp-content/plugins/jet-engine-local-qrcode/test-qrcode.php
```

## Compatibilidade

- PHP: >= 8.0
- WordPress: >= 5.0
- JetEngine: >= 2.0
- Elementor: >= 3.0

## Estrutura de arquivos

```
jet-engine-local-qrcode/
├── jet-engine-local-qrcode.php  # Plugin principal
├── composer.json                 # Dependências
├── vendor/                       # Bibliotecas PHP
│   └── chillerlan/php-qrcode/   # Gerador de QR code
├── test-qrcode.php              # Script de testes
└── README.md                     # Esta documentação
```

## Cache

Os QR codes são cacheados usando transients do WordPress:
- **Chave**: `local_qr_` + MD5(tamanho + dados)
- **Duração**: 24 horas (DAY_IN_SECONDS)
- **Limpeza**: `wp transient delete --all` ou `wp cache flush`

## Troubleshooting

### QR code não aparece
```bash
# Limpar cache
wp cache flush
wp transient delete --all
wp elementor flush_css
```

### Verificar se plugin está ativo
```bash
wp plugin list | grep jet-engine-local-qrcode
```

### Testar geração
```bash
wp eval-file /var/www/html/wp-content/plugins/jet-engine-local-qrcode/test-qrcode.php
```

## Licença

GPL-3.0+
