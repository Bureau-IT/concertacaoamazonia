#!/usr/bin/env python3
"""
Download OSM tiles para América do Sul (versão ultra-agressiva)
Autor: Daniel Cambría + Warp
"""

import os
import sys
import time
import json
import asyncio
import aiohttp
from pathlib import Path
from math import pi, log, tan, atan, sinh, cos, radians
import threading
import logging
from datetime import datetime

# Coordenadas da América do Sul (aproximadas)
# Bounds mais amplos para zoom baixo (visão continental com oceanos)
SOUTH_AMERICA_BOUNDS_WIDE = {
    'north': 12.5,   # Norte da Colômbia/Venezuela
    'south': -56.0,  # Sul da Argentina
    'west': -81.0,   # Oeste do Peru/Equador (inclui oceano Pacífico)
    'east': -34.0    # Leste do Brasil (inclui oceano Atlântico)
}

# Bounds restritos para zooms maiores (exclui maior parte dos oceanos)
SOUTH_AMERICA_BOUNDS_LAND = {
    'north': 12.5,   # Norte da Colômbia/Venezuela
    'south': -56.0,  # Sul da Argentina
    'west': -73.0,   # Oeste do Peru/Equador (exclui Pacífico)
    'east': -35.0    # Leste do Brasil (exclui Atlântico)
}

# Níveis de zoom a baixar (0-12 = ~20GB, 0-10 = ~5GB, 0-8 = ~1GB)
MIN_ZOOM = 5
MAX_ZOOM = 12

def get_bounds_for_zoom(zoom):
    """Retorna bounds apropriados para o nível de zoom"""
    # Zoom mínimo: incluir oceanos para visão continental
    if zoom <= MIN_ZOOM:
        return SOUTH_AMERICA_BOUNDS_WIDE
    # Zooms maiores: focar na massa terrestre
    else:
        return SOUTH_AMERICA_BOUNDS_LAND

# Diretório de saída
TILES_DIR = Path(__file__).parent.parent / 'tiles'
LOGS_DIR = TILES_DIR / 'logs'
LOGS_DIR.mkdir(parents=True, exist_ok=True)

# Tile servers OSM oficial (load balancing a/b/c - MESMO ESTILO VISUAL)
TILE_SERVERS = [
    'https://a.tile.openstreetmap.org/{z}/{x}/{y}.png',
    'https://b.tile.openstreetmap.org/{z}/{x}/{y}.png',
    'https://c.tile.openstreetmap.org/{z}/{x}/{y}.png',
]

# Headers para respeitar a política de uso
HEADERS = {
    'User-Agent': 'Atlas Cultural Amazonias Offline Maps/1.0'
}

# Configurações otimizadas para estabilidade
MAX_CONCURRENT_DOWNLOADS = 50  # Reduzido para evitar rate limiting
CONNECTION_LIMIT_PER_HOST = 20  # Limite por host mais conservador
RETRY_ATTEMPTS = 5  # Mais tentativas para lidar com falhas temporárias
TIMEOUT = 30  # Timeout maior para conexões lentas
RETRY_DELAY_BASE = 0.5  # Delay base para backoff exponencial
RATE_LIMIT_DELAY = 0.02 # Tempo de espera entre requisições (0.02 = 50 req/s)
PROGRESS_UPDATE_INTERVAL = 100  # Atualizar progresso a cada N tiles
PROGRESS_FILE = TILES_DIR / '.progress.json'

def deg2num(lat, lon, zoom):
    """Converter lat/lon para número de tile"""
    # Limitar latitude para evitar erros matemáticos (Mercator limit)
    lat = max(min(lat, 85.0511), -85.0511)
    
    n = 2.0 ** zoom
    xtile = int((lon + 180.0) / 360.0 * n)
    
    # Fórmula alternativa mais estável para ytile
    lat_rad = radians(lat)
    ytile = int((1.0 - log(tan(lat_rad) + (1.0 / cos(lat_rad))) / pi) / 2.0 * n)
    
    return (xtile, ytile)

class TileDownloader:
    """Gerenciador de downloads assíncronos com logging detalhado"""
    
    def __init__(self):
        self.lock = asyncio.Lock()
        self.downloaded_count = 0
        self.failed_count = 0
        self.skipped_count = 0
        self.not_found_count = 0  # Tiles que não existem (404 - água/áreas vazias)
        self.server_index = 0
        self.start_time = None
        self.failed_tiles = []  # Lista de tiles que falharam
        self.error_counts = {}  # Contagem de tipos de erro
        
        # Configurar logging
        log_file = LOGS_DIR / f'download_{datetime.now().strftime("%Y%m%d_%H%M%S")}.log'
        self.logger = logging.getLogger('TileDownloader')
        self.logger.setLevel(logging.INFO)
        
        # Handler para arquivo
        fh = logging.FileHandler(log_file)
        fh.setLevel(logging.DEBUG)
        formatter = logging.Formatter('%(asctime)s [%(levelname)s] %(message)s')
        fh.setFormatter(formatter)
        self.logger.addHandler(fh)
        
        self.logger.info(f"Iniciando download de tiles")
        self.logger.info(f"Configuração: MAX_CONCURRENT={MAX_CONCURRENT_DOWNLOADS}, RETRY={RETRY_ATTEMPTS}, TIMEOUT={TIMEOUT}s")
        
    def get_next_server(self):
        """Rotacionar entre servidores para distribuir carga"""
        server = TILE_SERVERS[self.server_index % len(TILE_SERVERS)]
        self.server_index += 1
        return server
    
    def calculate_eta(self, completed, total, elapsed):
        """Calcular tempo estimado para conclusão"""
        if completed == 0 or elapsed == 0:
            return 0, 0
        
        rate = completed / elapsed
        remaining = total - completed
        eta_seconds = remaining / rate if rate > 0 else 0
        
        return rate, eta_seconds
        
    def tile_exists(self, z, x, y):
        """Verificar se tile já existe no disco"""
        tile_file = TILES_DIR / str(z) / str(x) / f"{y}.png"
        return tile_file.exists()
    
    def format_time(self, seconds):
        """Formatar tempo em formato legível"""
        if seconds < 60:
            return f"{seconds:.0f}s"
        elif seconds < 3600:
            return f"{seconds/60:.1f}min"
        else:
            hours = int(seconds / 3600)
            mins = int((seconds % 3600) / 60)
            return f"{hours}h{mins:02d}min"
    
    async def download_tile_with_retry(self, session, z, x, y):
        """Baixar um tile assíncronamente com retry automático e backoff exponencial"""
        # Verificar se já existe (verificação rápida no disco)
        tile_file = TILES_DIR / str(z) / str(x) / f"{y}.png"
        if tile_file.exists():
            self.skipped_count += 1
            return True, "exists"
        
        output_path = TILES_DIR / str(z) / str(x)
        output_path.mkdir(parents=True, exist_ok=True)
        
        last_error = None
        last_status = None
        
        # Tentar com diferentes servidores e backoff exponencial
        for attempt in range(RETRY_ATTEMPTS):
            server_url = self.get_next_server()
            url = server_url.format(z=z, x=x, y=y)
            
            try:
                async with session.get(url, timeout=aiohttp.ClientTimeout(total=TIMEOUT, connect=10)) as response:
                    last_status = response.status
                    
                    if response.status == 200:
                        content = await response.read()
                        
                        # Validar conteúdo (mínimo 100 bytes para ser um PNG válido)
                        if len(content) < 100:
                            self.logger.warning(f"Tile {z}/{x}/{y}: conteúdo muito pequeno ({len(content)} bytes)")
                            if attempt == RETRY_ATTEMPTS - 1:
                                self._record_failure(z, x, y, "content_too_small")
                                return False, "invalid_content"
                            await asyncio.sleep(RETRY_DELAY_BASE * (2 ** attempt))
                            continue
                        
                        # Escrever de forma síncrona (I/O de disco)
                        await asyncio.to_thread(tile_file.write_bytes, content)
                        self.downloaded_count += 1
                        return True, "downloaded"
                        
                    elif response.status == 404:
                        # Tile não existe (água/área vazia) - isso é normal
                        self.not_found_count += 1
                        return True, "not_found"
                        
                    elif response.status == 429:
                        # Rate limit - esperar mais tempo
                        self.logger.warning(f"Rate limit atingido no tile {z}/{x}/{y}, tentativa {attempt+1}/{RETRY_ATTEMPTS}")
                        if attempt < RETRY_ATTEMPTS - 1:
                            await asyncio.sleep(RETRY_DELAY_BASE * (2 ** (attempt + 2)))  # Esperar mais em caso de 429
                            continue
                        
                    elif response.status in [500, 502, 503, 504]:
                        # Erro do servidor - retry com backoff
                        self.logger.warning(f"Erro servidor {response.status} no tile {z}/{x}/{y}, tentativa {attempt+1}/{RETRY_ATTEMPTS}")
                        if attempt < RETRY_ATTEMPTS - 1:
                            await asyncio.sleep(RETRY_DELAY_BASE * (2 ** attempt))
                            continue
                    
                    elif response.status == 403:
                        # Proibido - pode ser bloqueio temporário
                        self.logger.warning(f"Acesso proibido (403) no tile {z}/{x}/{y}")
                        if attempt < RETRY_ATTEMPTS - 1:
                            await asyncio.sleep(RETRY_DELAY_BASE * (2 ** (attempt + 1)))
                            continue
                    
                    # Outros erros HTTP
                    if attempt == RETRY_ATTEMPTS - 1:
                        error_type = f"http_{response.status}"
                        self._record_failure(z, x, y, error_type)
                        return False, error_type
                        
            except asyncio.TimeoutError:
                last_error = "timeout"
                self.logger.debug(f"Timeout no tile {z}/{x}/{y}, tentativa {attempt+1}/{RETRY_ATTEMPTS}")
                if attempt < RETRY_ATTEMPTS - 1:
                    await asyncio.sleep(RETRY_DELAY_BASE * (2 ** attempt))
                    continue
                    
            except aiohttp.ClientError as e:
                last_error = f"client_error_{type(e).__name__}"
                self.logger.debug(f"Erro cliente no tile {z}/{x}/{y}: {e}, tentativa {attempt+1}/{RETRY_ATTEMPTS}")
                if attempt < RETRY_ATTEMPTS - 1:
                    await asyncio.sleep(RETRY_DELAY_BASE * (2 ** attempt))
                    continue
                    
            except Exception as e:
                last_error = f"error_{type(e).__name__}"
                self.logger.error(f"Erro inesperado no tile {z}/{x}/{y}: {e}")
                if attempt < RETRY_ATTEMPTS - 1:
                    await asyncio.sleep(RETRY_DELAY_BASE * (2 ** attempt))
                    continue
        
        # Se chegou aqui, todas as tentativas falharam
        error_type = last_error or f"http_{last_status}" or "unknown"
        self._record_failure(z, x, y, error_type)
        return False, error_type
    
    def _record_failure(self, z, x, y, error_type):
        """Registrar falha de download"""
        self.failed_count += 1
        self.failed_tiles.append((z, x, y, error_type))
        self.error_counts[error_type] = self.error_counts.get(error_type, 0) + 1

def count_existing_tiles(tiles_to_check):
    """Contar quantos tiles já existem no disco"""
    existing = 0
    for z, x, y in tiles_to_check:
        tile_file = TILES_DIR / str(z) / str(x) / f"{y}.png"
        if tile_file.exists():
            existing += 1
    return existing

async def download_all_tiles(tiles_to_download, downloader):
    """Baixar todos os tiles usando async/await com processamento em lotes"""
    # Configurar conexão HTTP com limites agressivos
    connector = aiohttp.TCPConnector(
        limit=MAX_CONCURRENT_DOWNLOADS,
        limit_per_host=CONNECTION_LIMIT_PER_HOST,
        ttl_dns_cache=300,
        force_close=False,
        enable_cleanup_closed=True
    )
    
    timeout = aiohttp.ClientTimeout(total=TIMEOUT, connect=5)
    
    async with aiohttp.ClientSession(
        connector=connector,
        headers=HEADERS,
        timeout=timeout
    ) as session:
        
        completed = 0
        last_update = time.time()
        total = len(tiles_to_download)
        
        # Processar em lotes para não sobrecarregar memória
        batch_size = 1000
        
        for batch_start in range(0, total, batch_size):
            batch_end = min(batch_start + batch_size, total)
            batch = tiles_to_download[batch_start:batch_end]
            
            # Criar tasks para este lote
            tasks = [
                downloader.download_tile_with_retry(session, z, x, y)
                for z, x, y in batch
            ]
            
            # Aguardar conclusão do lote
            for coro in asyncio.as_completed(tasks):
                await coro
                completed += 1
                current_time = time.time()
                
                # Atualizar progresso
                if completed % PROGRESS_UPDATE_INTERVAL == 0 or (current_time - last_update) >= 2:
                    elapsed = current_time - downloader.start_time
                    rate, eta_seconds = downloader.calculate_eta(
                        completed,
                        total,
                        elapsed
                    )
                    
                    percent = (completed / total) * 100
                    bar_length = 30
                    filled = int(bar_length * completed / total)
                    bar = '█' * filled + '░' * (bar_length - filled)
                    
                    print(f"\r[{bar}] {percent:.1f}% | "
                          f"{completed:,}/{total:,} | "
                          f"⚡ {rate:.1f} tiles/s | "
                          f"⏱️  ETA: {downloader.format_time(eta_seconds)} | "
                          f"✓ {downloader.downloaded_count:,} | "
                          f"⊘ {downloader.skipped_count:,} | "
                          f"⊙ {downloader.not_found_count:,} | "
                          f"✗ {downloader.failed_count:,}", end='')
                    
                    last_update = current_time
        
        print()  # Nova linha final

def main():
    print("=== Download de Tiles OSM - Máxima Eficiência ===")
    print(f"Zoom levels: {MIN_ZOOM}-{MAX_ZOOM}")
    print(f"Destino: {TILES_DIR}")
    print(f"Downloads paralelos: {MAX_CONCURRENT_DOWNLOADS}")
    print(f"Servidores: {len(TILE_SERVERS)} (load balancing)")
    print(f"Taxa máxima: ~{1/RATE_LIMIT_DELAY:.0f} req/s")
    print()
    
    # Coletar todos os tiles necessários
    print("Calculando tiles necessários...")
    all_tiles = []
    for zoom in range(MIN_ZOOM, MAX_ZOOM + 1):
        bounds = get_bounds_for_zoom(zoom)
        nw_tile = deg2num(bounds['north'], bounds['west'], zoom)
        se_tile = deg2num(bounds['south'], bounds['east'], zoom)
        
        x_range = range(min(nw_tile[0], se_tile[0]), max(nw_tile[0], se_tile[0]) + 1)
        y_range = range(min(nw_tile[1], se_tile[1]), max(nw_tile[1], se_tile[1]) + 1)
        
        for x in x_range:
            for y in y_range:
                all_tiles.append((zoom, x, y))
        
        # Informar bounds usados
        if zoom == MIN_ZOOM:
            print(f"  Zoom {zoom}: bounds amplos (inclui oceanos para visão continental)")
        elif zoom == MIN_ZOOM + 1:
            print(f"  Zoom {zoom}+: bounds restritos (foco em massa terrestre)")
    
    print(f"Total de tiles: {len(all_tiles)}")
    
    # Verificar tiles já existentes
    print("Verificando tiles já baixados...")
    existing_tiles = count_existing_tiles(all_tiles)
    tiles_to_download = [t for t in all_tiles if not (TILES_DIR / str(t[0]) / str(t[1]) / f"{t[2]}.png").exists()]
    
    print(f"Tiles já existentes: {existing_tiles}")
    print(f"Tiles a baixar: {len(tiles_to_download)}")
    print()
    print("AVISO: Este processo pode demorar várias horas e baixar vários GB de dados.")
    print("Certifique-se de ter espaço em disco suficiente e uma conexão estável.")
    print("Os tiles já baixados serão preservados.")
    print()
    
    if len(tiles_to_download) == 0:
        print("Todos os tiles já foram baixados!")
        return
    
    response = input("Deseja continuar? (s/N): ")
    if response.lower() != 's':
        print("Download cancelado.")
        return
    
    # Iniciar download assíncrono ULTRA-AGRESSIVO
    downloader = TileDownloader()
    downloader.start_time = time.time()
    start_time = downloader.start_time
    
    print(f"\n🚀 Iniciando download otimizado de {len(tiles_to_download):,} tiles...")
    print(f"⚡ {MAX_CONCURRENT_DOWNLOADS} conexões simultâneas (async/await)")
    print(f"🎯 {len(TILE_SERVERS)} servidores em rotação")
    print(f"🔄 {RETRY_ATTEMPTS} tentativas por tile com backoff exponencial")
    print(f"⏱️  Timeout: {TIMEOUT}s por requisição")
    print(f"📝 Logs salvos em: {LOGS_DIR}\n")
    
    # Executar download assíncrono
    asyncio.run(download_all_tiles(tiles_to_download, downloader))
    
    elapsed = time.time() - start_time
    avg_rate = (downloader.downloaded_count + downloader.skipped_count) / elapsed if elapsed > 0 else 0
    
    print(f"\n\n{'='*60}")
    print(f"✅ Download Completo!")
    print(f"{'='*60}")
    print(f"⏱️  Tempo total: {downloader.format_time(elapsed)}")
    print(f"⚡ Taxa média: {avg_rate:.1f} tiles/s")
    print(f"")
    print(f"📊 Estatísticas:")
    print(f"   ✓ Tiles baixados:       {downloader.downloaded_count:,}")
    print(f"   ⊘ Tiles já existentes:  {existing_tiles:,}")
    print(f"   ⊙ Tiles não existem:    {downloader.not_found_count:,} (404 - água/áreas vazias)")
    print(f"   ⊗ Tiles pulados:        {downloader.skipped_count:,}")
    print(f"   ✗ Tiles com falha real: {downloader.failed_count:,}")
    print(f"   ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")
    print(f"   📦 Total baixados OK:   {existing_tiles + downloader.downloaded_count:,}")
    print(f"   📍 Total processados:   {existing_tiles + downloader.downloaded_count + downloader.not_found_count + downloader.skipped_count + downloader.failed_count:,}")
    print(f"")
    
    try:
        total_size = sum(f.stat().st_size for f in TILES_DIR.rglob('*.png'))
        print(f"💾 Tamanho total: {total_size / (1024**3):.2f} GB")
        if downloader.downloaded_count > 0:
            avg_size = (total_size - existing_tiles * 20000) / downloader.downloaded_count
            print(f"📏 Tamanho médio por tile: {avg_size / 1024:.1f} KB")
    except Exception as e:
        print(f"⚠️  Não foi possível calcular tamanho total")
    
    # Salvar lista de tiles falhados para análise
    if downloader.failed_count > 0:
        failed_file = LOGS_DIR / f'failed_tiles_{datetime.now().strftime("%Y%m%d_%H%M%S")}.json'
        failed_data = {
            'total_failed': downloader.failed_count,
            'error_counts': downloader.error_counts,
            'failed_tiles': [{'z': z, 'x': x, 'y': y, 'error': err} for z, x, y, err in downloader.failed_tiles[:1000]]  # Limitar a 1000
        }
        with open(failed_file, 'w') as f:
            json.dump(failed_data, f, indent=2)
        print(f"\n📄 Lista de tiles falhados salva em: {failed_file}")
        print(f"\n🔍 Tipos de erro encontrados:")
        for error_type, count in sorted(downloader.error_counts.items(), key=lambda x: x[1], reverse=True):
            print(f"   {error_type}: {count:,}")
    
    downloader.logger.info(f"Download concluído: {downloader.downloaded_count} tiles baixados, {downloader.failed_count} falhas")
    print(f"\n{'='*60}")

if __name__ == '__main__':
    main()
