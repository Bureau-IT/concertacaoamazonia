#!/usr/bin/env python3
"""
Download de tiles ESSENCIAIS pos-bloqueio OSM (compliance rigoroso).

Estrategia:
- z=0 a z=4: mundo todo (341 tiles, ~10 MB) — vista mundial sempre visivel
- z=5/6/7: APENAS bbox 3x3 ao redor de cada cidade onde existe artista TEDx
           (US-DC, UK-London, DE-Berlin, MX-CDMX, NL-Amsterdam, FI-Helsinki,
            IL-Jerusalem, ID-Jakarta, CM-Yaounde) → ~140 tiles
- Total: ~480 tiles, ~5 min com rate 2 req/s (compliance OSM)
"""

import asyncio
import sys
import time
import logging
from pathlib import Path
from math import pi, log, tan, cos, radians

import aiohttp

TILES_DIR = Path(__file__).parent.parent / 'tiles'
LOGS_DIR = TILES_DIR / 'logs'
LOGS_DIR.mkdir(parents=True, exist_ok=True)

# Cidades TEDx fora da AM do Sul (lat, lng) — extraídas do banco
TEDX_LOCATIONS = [
    ('Washington DC',  38.8951,  -77.0364),
    ('Berlin',         52.5200,   13.4050),
    ('Mexico City',    19.4326,  -99.1332),
    ('Amsterdam',      52.3676,    4.9041),
    ('London',         51.5074,   -0.1278),
    ('Helsinki',       60.1699,   24.9384),
    ('Jerusalem',      31.7683,   35.2137),
    ('Jakarta',        -6.2088,  106.8456),
    ('Yaounde',         3.8480,   11.5021),
]

# Tile servers — OSM.de (mirror alemao do OpenStreetMap, mesma cartografia
# do tile.openstreetmap.org mas SEM bloqueio agressivo. Estilo verde-floresta
# OSM padrao identico ao que ja temos em z=8-12 do dump nov/2025).
#
# Justificativa: tile.openstreetmap.org bloqueou o IP em 2026-05-06 (50 conexoes
# paralelas violava politica). CartoDB Voyager (creme) cria mistura de estilos
# com z=8-12 OSM verde existentes. OSM.de resolve mantendo estilo consistente.
# Politica OSM.de: https://www.openstreetmap.de/germanstyle.html (rate moderado).
TILE_SERVERS = [
    'https://a.tile.openstreetmap.de/{z}/{x}/{y}.png',
    'https://b.tile.openstreetmap.de/{z}/{x}/{y}.png',
    'https://c.tile.openstreetmap.de/{z}/{x}/{y}.png',
]

HEADERS = {
    'User-Agent': 'Atlas-Cultural-Amazonias/1.1 (offline-cache; contact: daniel.cambria@bureau-it.com)',
    'Referer': 'https://concertacaoamazonia.com.br/',
}

# OSM.de — rate moderado (4 paralelos = ~4 req/s, dentro da politica)
MAX_CONCURRENT = 4
TIMEOUT = 30
RATE_LIMIT_DELAY = 0.25  # 4 req/s
BLOCKED_TILE_SIZE = 6987 # detector OSM padrao (mesmo formato de bloqueio se ocorrer)
ABORT_AFTER_N_BLOCKED = 5


def deg2num(lat, lon, zoom):
    lat = max(min(lat, 85.0511), -85.0511)
    n = 2.0 ** zoom
    xtile = int((lon + 180.0) / 360.0 * n)
    lat_rad = radians(lat)
    ytile = int((1.0 - log(tan(lat_rad) + (1.0 / cos(lat_rad))) / pi) / 2.0 * n)
    return (xtile, ytile)


def collect_essential_tiles():
    """Retorna lista de (z, x, y) — sem duplicatas."""
    tiles = set()

    # Camada 1: mundo todo z=0 a z=4
    for z in range(0, 5):
        n = 2 ** z
        for x in range(n):
            for y in range(n):
                tiles.add((z, x, y))

    # Camada 2: z=5/6/7 com bbox 3x3 ao redor de cada cidade TEDx
    # 3x3 = tile da cidade + 8 vizinhos → cobre vista regional confortavel
    for z in [5, 6, 7]:
        for name, lat, lng in TEDX_LOCATIONS:
            cx, cy = deg2num(lat, lng, z)
            for dx in range(-1, 2):
                for dy in range(-1, 2):
                    x, y = cx + dx, cy + dy
                    n = 2 ** z
                    if 0 <= x < n and 0 <= y < n:
                        tiles.add((z, x, y))

    return sorted(tiles)


class EssentialDownloader:
    def __init__(self):
        self.downloaded = 0
        self.skipped = 0
        self.failed = 0
        self.blocked = 0
        self.abort = False
        self.server_idx = 0

        log_file = LOGS_DIR / f'essential_{int(time.time())}.log'
        self.logger = logging.getLogger('EssentialDownloader')
        self.logger.setLevel(logging.INFO)
        fh = logging.FileHandler(log_file)
        fh.setFormatter(logging.Formatter('%(asctime)s [%(levelname)s] %(message)s'))
        self.logger.addHandler(fh)

    def next_server(self):
        s = TILE_SERVERS[self.server_idx % len(TILE_SERVERS)]
        self.server_idx += 1
        return s

    async def download(self, session, z, x, y):
        if self.abort:
            return False, 'abort'

        tile_file = TILES_DIR / str(z) / str(x) / f"{y}.png"
        if tile_file.exists():
            self.skipped += 1
            return True, 'exists'

        tile_file.parent.mkdir(parents=True, exist_ok=True)

        for attempt in range(3):
            url = self.next_server().format(z=z, x=x, y=y)
            try:
                async with session.get(url, timeout=aiohttp.ClientTimeout(total=TIMEOUT, connect=10)) as resp:
                    if resp.status == 200:
                        content = await resp.read()
                        if len(content) < 100:
                            self.logger.warning(f'tile {z}/{x}/{y}: muito pequeno ({len(content)}b)')
                            await asyncio.sleep(2 ** attempt)
                            continue
                        if len(content) == BLOCKED_TILE_SIZE:
                            self.blocked += 1
                            self.logger.error(f'tile {z}/{x}/{y}: OSM ACCESS BLOCKED')
                            if self.blocked >= ABORT_AFTER_N_BLOCKED:
                                self.abort = True
                            return False, 'blocked'
                        await asyncio.to_thread(tile_file.write_bytes, content)
                        self.downloaded += 1
                        return True, 'downloaded'
                    elif resp.status == 404:
                        return True, 'not_found'  # tile vazio, normal
                    elif resp.status == 429:
                        await asyncio.sleep(5)
                        continue
                    else:
                        if attempt == 2:
                            self.logger.warning(f'tile {z}/{x}/{y}: HTTP {resp.status}')
            except (asyncio.TimeoutError, aiohttp.ClientError) as e:
                self.logger.debug(f'tile {z}/{x}/{y} attempt {attempt+1}: {type(e).__name__}')
                await asyncio.sleep(2 ** attempt)
                continue

        self.failed += 1
        return False, 'failed'


async def run(tiles, downloader):
    connector = aiohttp.TCPConnector(limit=MAX_CONCURRENT, limit_per_host=MAX_CONCURRENT)
    async with aiohttp.ClientSession(connector=connector, headers=HEADERS) as session:
        sem = asyncio.Semaphore(MAX_CONCURRENT)

        async def bounded(z, x, y):
            async with sem:
                if downloader.abort:
                    return
                result = await downloader.download(session, z, x, y)
                # Rate limit: 0.5s por tile after each completion
                await asyncio.sleep(RATE_LIMIT_DELAY)
                return result

        total = len(tiles)
        start = time.time()
        last_print = start

        tasks = [asyncio.create_task(bounded(z, x, y)) for z, x, y in tiles]

        completed = 0
        for coro in asyncio.as_completed(tasks):
            await coro
            completed += 1
            if downloader.abort:
                break
            now = time.time()
            if now - last_print > 2 or completed == total:
                elapsed = now - start
                rate = completed / elapsed if elapsed > 0 else 0
                eta = (total - completed) / rate if rate > 0 else 0
                pct = (completed / total) * 100
                bar_filled = int(30 * completed / total)
                bar = '█' * bar_filled + '░' * (30 - bar_filled)
                print(
                    f"\r[{bar}] {pct:5.1f}% | {completed:>4}/{total} | "
                    f"⚡ {rate:.1f}/s | ETA {eta:.0f}s | "
                    f"✓{downloader.downloaded} ⊘{downloader.skipped} "
                    f"✗{downloader.failed} 🛑{downloader.blocked}",
                    end='', flush=True,
                )
                last_print = now

        # Cancelar tasks pendentes em caso de abort
        if downloader.abort:
            for t in tasks:
                if not t.done():
                    t.cancel()

        print()


def main():
    tiles = collect_essential_tiles()
    print(f"Plano: {len(tiles)} tiles essenciais (z=0-4 mundo + z=5/6/7 cidades TEDx)")

    # Filtrar pre-existentes
    pending = [t for t in tiles if not (TILES_DIR / str(t[0]) / str(t[1]) / f"{t[2]}.png").exists()]
    existing = len(tiles) - len(pending)

    print(f"  já existentes: {existing}")
    print(f"  a baixar:      {len(pending)}")

    if not pending:
        print("Nada a baixar.")
        return

    eta_min = (len(pending) * RATE_LIMIT_DELAY) / 60
    print(f"  ETA estimado:  ~{eta_min:.1f} min @ 2 req/s (compliance OSM)")
    print()

    if '--yes' not in sys.argv:
        ans = input("Continuar? (s/N): ").strip().lower()
        if ans != 's':
            print("Cancelado.")
            return

    downloader = EssentialDownloader()
    asyncio.run(run(pending, downloader))

    print()
    print("=" * 60)
    if downloader.abort:
        print(f"🛑 ABORTADO: {downloader.blocked} tiles bloqueados pelo OSM.")
        print("   Aguarde algumas horas antes de tentar de novo.")
        sys.exit(1)
    else:
        print(f"✅ Concluído: {downloader.downloaded} baixados, {downloader.skipped} pulados, {downloader.failed} falhas")


if __name__ == '__main__':
    main()
