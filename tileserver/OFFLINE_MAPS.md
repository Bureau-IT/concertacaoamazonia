# Mapas Offline - Atlas Cultural das Amazônias

Este documento explica como configurar os mapas do JetEngine para funcionar completamente offline.

## Arquitetura

- **Tile Server**: Nginx servindo tiles estáticos na porta 8080
- **Tiles**: Arquivos PNG do OpenStreetMap armazenados localmente
- **Plugin WordPress**: `mu-plugins/jetengine-offline-maps.php` redireciona requests para o servidor local

## Como Baixar os Tiles

### Opção 1: Script Python (Recomendado)

```bash
cd /Users/dcambria/scripts/concertacao/server-tools/docker-dev
python3 scripts/download-tiles.py
```

**Configuração atual:**
- **Região**: América do Sul completa
- **Zoom levels**: 5-12 (pode ser ajustado no script)
- **Tamanho estimado**: ~2-5 GB
- **Tempo estimado**: 2-4 horas (dependendo da conexão)

### Opção 2: Download Manual

Baixe tiles de outras fontes (ex: extrair de aplicativos offline, espelhos OSM, etc) e coloque na estrutura:

```
tiles/
├── 5/
│   ├── 123/
│   │   ├── 456.png
│   │   └── 457.png
├── 6/
└── ...
```

## Estrutura de Diretórios

```
docker-dev/
├── tiles/              # Tiles do mapa (PNG files)
│   ├── 5/             # Zoom level 5
│   ├── 6/             # Zoom level 6
│   └── ...
├── tileserver/        # Configuração do Nginx
│   └── nginx.conf     # Config com CORS habilitado
└── wordpress/
    └── wp-content/
        └── mu-plugins/
            └── jetengine-offline-maps.php
```

## URLs

- **WordPress**: http://localhost
- **Tile Server**: http://localhost:8080
- **Health Check**: http://localhost:8080/health
- **Exemplo de Tile**: http://localhost:8080/tiles/5/10/15.png

## Verificar Status

```bash
# Verificar se tile server está rodando
docker ps | grep tileserver

# Testar health check
curl http://localhost:8080/health

# Ver logs
docker logs wp-dev-tileserver
```

## Personalização

### Ajustar Zoom Levels

Edite `scripts/download-tiles.py`:

```python
MIN_ZOOM = 5   # Zoom mínimo (mundo)
MAX_ZOOM = 12  # Zoom máximo (ruas)
```

**Tabela de tamanhos aproximados:**
- Zoom 0-8: ~1 GB (países)
- Zoom 0-10: ~5 GB (estados)
- Zoom 0-12: ~20 GB (cidades)
- Zoom 0-14: ~80 GB (ruas detalhadas)

### Ajustar Região

Edite coordenadas em `scripts/download-tiles.py`:

```python
SOUTH_AMERICA_BOUNDS = {
    'north': 12.5,
    'south': -56.0,
    'west': -81.0,
    'east': -34.0
}
```

Para Amazônia apenas:
```python
AMAZON_BOUNDS = {
    'north': 5.0,
    'south': -20.0,
    'west': -80.0,
    'east': -45.0
}
```

## Troubleshooting

### Tiles não aparecem

1. Verificar se tile server está rodando: `docker ps | grep tileserver`
2. Verificar se tiles existem: `ls -la tiles/`
3. Verificar logs: `docker logs wp-dev-tileserver`
4. Testar URL diretamente: `curl http://localhost:8080/tiles/5/10/15.png`

### Erro de CORS

- Verificar se `tileserver/nginx.conf` tem headers CORS corretos
- Reiniciar tile server: `docker-compose restart tileserver`

### Tiles quebrados/404

- Nem todos os tiles existem (áreas de água retornam 404)
- Isso é normal e o Leaflet lida automaticamente

## Produção

Para usar em produção, altere as URLs no plugin:

```php
// Em mu-plugins/jetengine-offline-maps.php
return 'http://SEU_DOMINIO:8080/tiles/{z}/{x}/{y}.png';
```

Ou configure proxy reverso no Nginx principal.

## Atualização dos Tiles

Para atualizar tiles periodicamente:

```bash
# Remove tiles antigos
rm -rf tiles/*

# Baixa novamente
python3 scripts/download-tiles.py
```

## Licença

Tiles do OpenStreetMap são © OpenStreetMap contributors, licenciados sob ODbL.
Respeite a [política de uso](https://operations.osmfoundation.org/policies/tiles/).
