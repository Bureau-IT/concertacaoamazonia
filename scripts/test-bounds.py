#!/usr/bin/env python3
"""
Testar novos bounds sem fazer download - dry run
Autor: Daniel Cambría + Warp
"""

from math import radians, log, tan, cos

# Bounds configurados
SOUTH_AMERICA_BOUNDS_WIDE = {
    'north': 12.5,
    'south': -56.0,
    'west': -81.0,
    'east': -34.0
}

SOUTH_AMERICA_BOUNDS_LAND = {
    'north': 12.5,
    'south': -56.0,
    'west': -73.0,
    'east': -35.0
}

MIN_ZOOM = 5
MAX_ZOOM = 12

def deg2num(lat, lon, zoom):
    """Converter lat/lon para número de tile"""
    lat = max(min(lat, 85.0511), -85.0511)
    n = 2.0 ** zoom
    xtile = int((lon + 180.0) / 360.0 * n)
    lat_rad = radians(lat)
    ytile = int((1.0 - log(tan(lat_rad) + (1.0 / cos(lat_rad))) / 3.14159265359) / 2.0 * n)
    return (xtile, ytile)

def get_bounds_for_zoom(zoom):
    """Retorna bounds apropriados para o nível de zoom"""
    if zoom <= MIN_ZOOM:
        return SOUTH_AMERICA_BOUNDS_WIDE
    else:
        return SOUTH_AMERICA_BOUNDS_LAND

def main():
    print("=" * 70)
    print("🧪 TESTE DE BOUNDS - DRY RUN")
    print("=" * 70)
    print()
    
    total_tiles_old = 0
    total_tiles_new = 0
    
    print("📊 Comparação de Tiles por Zoom Level:")
    print("-" * 70)
    print(f"{'Zoom':<6} {'Bounds':<15} {'X-Range':<15} {'Y-Range':<10} {'Tiles':<15}")
    print("-" * 70)
    
    for zoom in range(MIN_ZOOM, MAX_ZOOM + 1):
        # Bounds antigos (sempre wide)
        nw_old = deg2num(SOUTH_AMERICA_BOUNDS_WIDE['north'], SOUTH_AMERICA_BOUNDS_WIDE['west'], zoom)
        se_old = deg2num(SOUTH_AMERICA_BOUNDS_WIDE['south'], SOUTH_AMERICA_BOUNDS_WIDE['east'], zoom)
        x_count_old = abs(se_old[0] - nw_old[0]) + 1
        y_count_old = abs(se_old[1] - nw_old[1]) + 1
        tiles_old = x_count_old * y_count_old
        total_tiles_old += tiles_old
        
        # Bounds novos (dinâmicos)
        bounds_new = get_bounds_for_zoom(zoom)
        bounds_name = "WIDE" if zoom <= MIN_ZOOM else "LAND"
        nw_new = deg2num(bounds_new['north'], bounds_new['west'], zoom)
        se_new = deg2num(bounds_new['south'], bounds_new['east'], zoom)
        x_count_new = abs(se_new[0] - nw_new[0]) + 1
        y_count_new = abs(se_new[1] - nw_new[1]) + 1
        tiles_new = x_count_new * y_count_new
        total_tiles_new += tiles_new
        
        reduction = tiles_old - tiles_new
        reduction_pct = (reduction / tiles_old * 100) if tiles_old > 0 else 0
        
        print(f"{zoom:<6} {bounds_name:<15} {x_count_new:<15,} {y_count_new:<10,} {tiles_new:<15,}", end='')
        if reduction > 0:
            print(f" (-{reduction:,}, -{reduction_pct:.1f}%)")
        else:
            print()
    
    print("-" * 70)
    print()
    
    # Resumo
    total_reduction = total_tiles_old - total_tiles_new
    total_reduction_pct = (total_reduction / total_tiles_old * 100) if total_tiles_old > 0 else 0
    
    print("=" * 70)
    print("📈 RESUMO")
    print("=" * 70)
    print(f"Total tiles (bounds antigos): {total_tiles_old:>20,}")
    print(f"Total tiles (bounds novos):   {total_tiles_new:>20,}")
    print(f"Redução de tentativas:        {total_reduction:>20,} (-{total_reduction_pct:.1f}%)")
    print()
    
    # Estimativas baseadas na taxa de 404 observada (62%)
    estimated_404_reduction = int(total_reduction * 0.62)
    estimated_real_tiles = int(total_tiles_new * 0.315)  # Taxa observada: 206934/656065
    
    print("💡 ESTIMATIVAS (baseado em execução anterior):")
    print(f"Redução estimada de 404s:     {estimated_404_reduction:>20,}")
    print(f"Tiles reais esperados:        {estimated_real_tiles:>20,}")
    print()
    
    # Tempo estimado
    avg_rate = 18.9  # tiles/s da execução anterior
    estimated_time_seconds = total_tiles_new / avg_rate
    estimated_hours = estimated_time_seconds / 3600
    
    print(f"⏱️  TEMPO ESTIMADO (@ {avg_rate} tiles/s):")
    if estimated_hours < 1:
        print(f"Duração estimada:             {estimated_time_seconds/60:>20.1f} minutos")
    else:
        hours = int(estimated_hours)
        minutes = int((estimated_hours - hours) * 60)
        print(f"Duração estimada:             {hours:>19}h {minutes:02d}min")
    
    print()
    print("=" * 70)
    print("✅ Configuração de bounds otimizada!")
    print(f"   - Zoom {MIN_ZOOM}: mantém oceanos (visão continental)")
    print(f"   - Zoom {MIN_ZOOM+1}-{MAX_ZOOM}: foca em massa terrestre")
    print(f"   - Economia: ~{estimated_404_reduction:,} tentativas desnecessárias")
    print("=" * 70)

if __name__ == '__main__':
    main()
