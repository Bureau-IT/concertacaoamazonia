# 2026-05-01 — Espiral EN, preloader Elementor e ajustes de taxonomia

Documenta todas as mudanças aplicadas em **runtime no banco de dados** durante a
sessão de trabalho de 2026-05-01. Não há mudanças de código (widget PHP e SVG
do tema continuam na v1.6.1, commit 7313536bd). Útil para replicar em produção
quando for o momento, e para futura migração.

## 1. Taxonomia `eixos`

### Termos PT criados/renomeados (já feitos pelo usuário antes desta sessão)

Reorganização dos slugs PT para o padrão `eixo-N` espelhando a posição do
segmento no SVG da espiral:

| term_id | name | slug |
|---|---|---|
| 2360 | Espiral: Cidades | `eixo-18` |
| 2013 | Espiral: Ordenamento Territorial e Regularização Fundiária | `eixo-9` |
| 2401 | Espiral: PIQCTs | `eixo-20` |

### Termos EN criados (5 novos)

Vinculados via WPML ao mesmo `trid` dos respectivos PT (parent = `1681`/Spiral):

| trid | term_id PT | term_id EN | name EN | slug EN |
|---|---|---|---|---|
| 21342 | 2013 | **2488** | Spiral: Territorial planning and land tenure regularization | `eixo-9-en` |
| 2050184 | 2479 | **2489** | Spiral: Health | `eixo-17-en` |
| 2050100 | 2463 | **2490** | Spiral: Biodiversity | `eixo-19-en` |
| 1959554 | 2401 | **2491** | Spiral: Indigenous, quilombola and traditional communities | `eixo-20-en` |
| 2050101 | 2464 | **2492** | Spiral: Human rights | `eixo-21-en` |

### Slugs EN realinhados ao padrão `eixo-N-en`

13 slugs renomeados para espelhar o slug PT correspondente:

| term_id EN | name | slug antigo | slug novo |
|---|---|---|---|
| 1641 | Spiral: Development Indicators (OLD) | `eixo-8-en` | `eixo-8-old-en` |
| 1642 | Spiral: Land use and deforestation | `eixo-9-en` | `eixo-8-en` |
| 2488 | Spiral: Territorial planning… | `spiral-territorial-planning-en` | `eixo-9-en` |
| 1644 | Spiral: Infrastructure | `eixo-11-en` | `eixo-10-en` |
| 1645 | Spiral: Communication and media | `eixo-12-en` | `eixo-11-en` |
| 1646 | Spiral: Climate Change | `eixo-13-en` | `eixo-12-en` |
| 1647 | Spiral: International Agenda | `eixo-14-en` | `eixo-13-en` |
| 2387 | Spiral: Education | `spiral-education` | `eixo-14-en` |
| 1651 | Spiral: Bioeconomy | `eixo-16-en` | `eixo-15-en` |
| 1650 | Spiral: Illegalities | `eixo-17-en` | `eixo-16-en` |
| 2489 | Spiral: Health | `spiral-health-en` | `eixo-17-en` |
| 2386 | Spiral: Cities (renomeado de "City") | `city` | `eixo-18-en` |

### Termo renomeado

| term_id | name antigo | name novo |
|---|---|---|
| 2386 | City | **Spiral: Cities** |

## 2. Widget bureau_espiral em postmeta

### Posts atualizados (term_ids 9, 18, 20 corrigidos)

Atualizado `_elementor_data` dos seguintes posts onde o widget `bureau_espiral`
estava com term_ids antigos:

| post_id | tipo | título | mudanças |
|---|---|---|---|
| 2461 | page (PT) | Uma Concertação Pela Amazônia | seg 9: 1823→2013, seg 18: 1130→2360, seg 20: 2466→2401 |
| 92226 | elementor_library (PT) | espiral | mesmas 3 mudanças |
| 79123 | page (EN) | Spiral of Knowledge | widget completo sincronizado do PT 2461 (axes_repeater + typo_repeater + cores), mapeando term_ids PT→EN e labels para inglês |
| 2519 | page (EN) | Amazon Concertation | widget completo sincronizado do PT, term_ids PT→EN, labels EN |

### Outros widgets na página EN 79123 (UI strings traduzidas)

| widget id | tipo | mudança |
|---|---|---|
| `8e1aa89` | jet-smart-filters-active | `filters_label`: "Filtros ativos: " → "Active filters: " |
| `1a6ba01` | jet-listing-grid | `loader_text`: "Carregando mais estudos..." → "Loading more studies..." |
| `bb87a69` | heading (jet-query-count) | "Mostrando %end-item% de %total% estudos cadastrados" → "Showing %end-item% of %total% registered studies" |
| `0781799` | heading (jet-query-count) | "Mostrando [end-item] de um total de %total% estudos cadastrados." → "Showing [end-item] of a total of %total% registered studies." |

## 3. Filtros JetSmartFilters

### Filtro EN 33352 (Filtro Dropdown: Espiral de Conhecimento EN)

| meta_key | valor anterior | valor novo |
|---|---|---|
| `_data_exclude_include` | array de 17 IDs com 3 órfãos (1822, 1643, 1641) | array de 21 IDs válidos espelhando o filtro PT 26869 (com mapeamento via WPML trid) |
| `_show_empty_terms` | `false` | `true` (igual ao PT — mostra mesmo termos com count=0) |
| `_terms_orderby` | (vazio) | `name` |
| `_filter_label` | (vazio) | `Select axis` |
| `_active_label` | (vazio) | `Select axis` |

Lista de term_ids EN no filtro 33352 após a atualização:
`1647, 1638, 1651, 1649, 1645, 2387, 1635, 1650, 1644, 1646, 1636, 1637, 1639, 1640, 1642, 2488, 2386, 2490, 2492, 2489, 2491`

## 4. Elementor Page Transitions (preloader)

### Attachment criado

| ID | título | arquivo | uso |
|---|---|---|---|
| **92305** | Espiral Concertação (Preloader) | `/wp-content/uploads/2026/03/espiral-concertacao-preloader.svg` | Ícone do preloader Elementor |

O SVG do preloader é uma versão modificada de `espiral-concertacao.svg` com:
- ViewBox cropped ao bounding box exato do desenho: `32.98 195.68 203.9 203.9`
- Style inline com CSS vars do Elementor:
  - `color: var(--e-preloader-color, #47DEA8)`
  - `width: var(--e-preloader-size, 60px)`
  - `height: var(--e-preloader-size, 60px)`
  - `animation: var(--e-preloader-animation, none) var(--e-preloader-animation-duration, 1000ms) linear infinite`
- Mantém `fill="currentColor"` no `<svg>` raiz para herança da cor.

> **Por que existe**: corrige bug do Elementor em "Custom SVG Icons" no preloader
> onde o `<i>` vazio recebe a animação/cor mas o `<svg>` inline (irmão) não.
> O style inline no SVG faz ele pegar diretamente das CSS vars do `<e-page-transition>`.

### Settings de Page Transitions — Kit blog 1 (raiz, kit_id 2553)

```php
settings_page_transitions_preloader_type = 'icon'
settings_page_transitions_preloader_icon = ['value' => ['url' => '...preloader.svg', 'id' => 92305], 'library' => 'svg']
settings_page_transitions_preloader_color = '#262834'
settings_page_transitions_preloader_size = ['unit' => 'px', 'size' => 56]
settings_page_transitions_preloader_animation = 'eicon-spin'
settings_page_transitions_background_color = '#262834'
// + outros (preloader_image, preloader_width, preloader_max_width, preloader_opacity, animation_duration, exit_animation, etc.)
```

### Settings de Page Transitions — Kit blog 2 (`/cultura/`, kit_id 5)

Sincronizado com blog 1 — mesmos 14 settings copiados, com remoção de
`settings_page_transitions_preloader_animation_duration` (que estava em 400ms,
não existe no blog 1, default Elementor é 1000ms).

> Network Media Library (mu-plugin) garante que o attachment 92305 é
> referenciável de ambos os blogs.

## 5. Replicação para produção

Estas mudanças foram feitas **em DEV (cambrasmax.local:8484)** e replicadas para
**concertacao.bureau-it.com (tunnel)** automaticamente (mesmo banco).

Para replicar em **produção real (concertacaoamazonia.com.br)**:

1. Importar/criar os 5 termos EN com WPML linkagem (script
   `/tmp/poliglota-create-translations.php` desta sessão).
2. Realinhar slugs EN (script `poliglota-realign-slugs.php`).
3. Copiar `espiral-concertacao-preloader.svg` para `/wp-content/uploads/2026/03/`.
4. Criar attachment correspondente.
5. Atualizar settings dos kits de Page Transitions (blogs 1 e 2).
6. Atualizar `_data_exclude_include` do filtro 33352 (precisa do ID
   correspondente em prod).
7. Atualizar postmeta dos posts 2461, 92226, 79123, 2519 (IDs podem diferir em
   prod — confirmar via WPML trid).
8. Limpar caches: `_elementor_inline_svg` do attachment + Object Cache + WP
   Rocket + CSS Elementor regen para todos os posts afetados.

Comando único de flush pós-deploy:
```bash
wp eval '
// Limpa cache do SVG do preloader
delete_post_meta($preloader_attachment_id, "_elementor_inline_svg");
// Regenera CSS Elementor
foreach ([2461, 79123, 92226, 2519, 26826, 2553, 5] as $id) {
    if (class_exists("\\Elementor\\Core\\Files\\CSS\\Post")) {
        (new \Elementor\Core\Files\CSS\Post($id))->update();
    }
}
'
wp cache flush
wp eval 'if (function_exists("rocket_clean_domain")) rocket_clean_domain();'
```
