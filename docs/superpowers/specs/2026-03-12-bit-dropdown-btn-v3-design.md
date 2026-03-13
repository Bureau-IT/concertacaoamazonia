# Design: BIT Dropdown Button v3.0.0

**Data:** 2026-03-12
**Autor:** Bureau IT
**Status:** Aprovado

---

## Contexto

A página `/publicacoes/` usa botões "DOWNLOAD" com dropdown para múltiplos arquivos (PT/EN). O widget atual (v2.0.0) usa links diretos, expondo URLs de arquivos para rastreamento por buscadores.

Esta versão integra a criptografia nativa do JetElements (plugin CrocoBl/CrocoBlock) para gerar URLs ofuscadas no formato `?jet_download=SHA1_HASH`, impedindo que buscadores rastreiem os links diretos de download.

---

## Arquivos Modificados

| Arquivo | Ação |
|---|---|
| `wordpress/wp-content/mu-plugins/bit-dropdown-btn.php` | Atualizar de v2.0.0 → v3.0.0 |
| `wordpress/wp-content/mu-plugins/bit-dropdown-btn.css` | Ajustes para novos seletores |
| `docker-dev/common/mu-plugins/bit-dropdown-btn.php` | Espelho (cópia após alteração) |
| `docker-dev/common/mu-plugins/bit-dropdown-btn.css` | Espelho (cópia após alteração) |

---

## Mecanismo de Criptografia

O `Jet_Elements_Download_Handler` (JetElements) armazena um mapa `SHA1_HASH → attachment_id` na opção WP `jet_elements_download_button_hashes`. A chave usa `NONCE_SALT`.

**API pública correta (único ponto de entrada):**

```php
// Gera a URL completa com hash — não chamar encrypt_id() diretamente (método private)
jet_elements_download_handler()->get_download_link( $attachment_id )
// Retorna: "https://site.com/?jet_download=abc123sha1hash"
```

O handler intercepta no hook `init` (priority 99), decripta o hash, valida o attachment e serve o arquivo via headers HTTP com streaming em chunks de 1MB.

**Verificação de disponibilidade:**

```php
// Condição correta — class_exists() sozinho não garante que o handler foi inicializado
if ( function_exists( 'jet_elements_download_handler' )
     && jet_elements_download_handler() instanceof Jet_Elements_Download_Handler ) {
    $url = jet_elements_download_handler()->get_download_link( $attachment_id );
} else {
    $url = $item_fallback_url; // link direto sem criptografia
}
```

---

## Widget: `Bit_Dropdown_Btn_Widget`

**Classe:** `Bit_Dropdown_Btn_Widget`
**Slug:** `bit_dropdown_btn`
**Título:** `BIT Download Button`
**Ícone:** `eicon-button`
**Categoria:** `general`

### Tab Content

| Controle | ID | Tipo | Default |
|---|---|---|---|
| Rótulo do botão | `label` | TEXT | `DOWNLOAD` |
| Links (repeater) | `links` | REPEATER | 2 itens padrão (ver abaixo) |
| ↳ Rótulo do item | `item_label` | TEXT | `Português` / `English` |
| ↳ Arquivo (media) | `item_file` | MEDIA | `['id' => 0, 'url' => '']` |
| ↳ URL fallback | `item_fallback_url` | URL | `['url' => '']` |

**Default do repeater (campo `default` no controle `links`):**
```php
'default' => [
    [ 'item_label' => 'Português', 'item_file' => [ 'id' => 0, 'url' => '' ], 'item_fallback_url' => [ 'url' => '' ] ],
    [ 'item_label' => 'English',   'item_file' => [ 'id' => 0, 'url' => '' ], 'item_fallback_url' => [ 'url' => '' ] ],
],
```

**Lógica de URL por item no `render()`:**

O controle MEDIA retorna array `['id' => int, 'url' => string]`. Acessar sempre via `$link['item_file']['id']`.

```php
$attachment_id = (int) ( $link['item_file']['id'] ?? 0 );
$fallback_url  = $link['item_fallback_url']['url'] ?? '';

if ( $attachment_id > 0
     && function_exists( 'jet_elements_download_handler' )
     && jet_elements_download_handler() instanceof Jet_Elements_Download_Handler ) {
    $href = jet_elements_download_handler()->get_download_link( $attachment_id );
} elseif ( ! empty( $fallback_url ) ) {
    $href = esc_url( $fallback_url );
} else {
    continue; // item sem URL válida — não renderiza
}
```

### Tab Style

#### Seção: Botão — Dimensões

| Controle | ID | Tipo | Default | Seletor CSS |
|---|---|---|---|---|
| Largura | `btn_width` | SLIDER (px/%) | `220px` | `{{WRAPPER}} .dropdown-btn-toggle { width: {{SIZE}}{{UNIT}}; }` |
| Border radius | `btn_border_radius` | DIMENSIONS | `4px` (todos lados) | `{{WRAPPER}} .dropdown-btn-toggle { border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; }` |
| Padding | `btn_padding` | DIMENSIONS | `15px` top/bottom, `25px` left/right | `{{WRAPPER}} .dropdown-btn-toggle { padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; }` |
| Box shadow | `btn_box_shadow` | BOX_SHADOW | — | `{{WRAPPER}} .dropdown-btn-toggle` |

#### Seção: Cores — Normal

| Controle | ID | CSS var |
|---|---|---|
| Fundo | `color_bg` | `--btn-normal-bg` |
| Texto | `color_txt` | `--btn-normal-txt` |
| Borda | `color_bdr` | `--btn-normal-bdr` |

#### Seção: Cores — Hover

| Controle | ID | CSS var |
|---|---|---|
| Fundo hover | `color_bg_hv` | `--btn-normal-bg-hv` |
| Texto hover | `color_txt_hv` | `--btn-normal-txt-hv` |
| Borda hover | `color_bdr_hv` | `--btn-normal-border-hv` (manter nome existente — inconsistência herdada do CSS) |

#### Seção: Ícone

| Controle | ID | Tipo | Default | Seletor/Var |
|---|---|---|---|---|
| Ícone | `icon` | ICONS | `['value' => 'eicon-download', 'library' => 'eicons']` | renderizado via `Icons_Manager::render_icon()` |
| Tamanho | `icon_size` | SLIDER (px) | `30px` | `{{WRAPPER}} .dropdown-btn-icon svg, {{WRAPPER}} .dropdown-btn-icon i { width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}}; }` |
| Cor normal | `color_icn` | COLOR | — | `{{WRAPPER}} { --btn-normal-icn: {{VALUE}}; }` |
| Cor hover | `color_icn_hv` | COLOR | — | `{{WRAPPER}} { --btn-normal-icon-hv: {{VALUE}}; }` |

**Fallback de ícone:** quando o controle `icon` estiver vazio ou não retornar HTML, renderizar o SVG hardcoded do círculo com seta (mesmo padrão da v2, via método privado `get_default_icon_svg()`). Isso garante que o botão sempre exibe um ícone sem depender da seleção do usuário.

O SVG padrão (do `--ucpa-icon-download`):
```php
private function get_default_icon_svg(): string {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 22 22" '
         . 'aria-hidden="true" focusable="false" fill="currentColor">'
         . '<path fill-rule="evenodd" d="M11,0C4.92,0,0,4.92,0,11s4.92,11,11,11,11-4.92,11-11'
         . 'S17.08,0,11,0Zm.13,8.42h3.5l-1.75,3.03-1.75,3.03-1.75-3.03-1.75-3.03h3.5Z"/>'
         . '</svg>';
}
```

#### Seção: Tipografia

| Controle | ID | Tipo | Seletor |
|---|---|---|---|
| Label botão | `label_typography` | TYPOGRAPHY | `{{WRAPPER}} .dropdown-btn-label` |
| Itens menu | `menu_typography` | TYPOGRAPHY | `{{WRAPPER}} .dropdown-btn-menu a` |

#### Seção: Menu Dropdown

| Controle | ID | Tipo | Default | Seletor |
|---|---|---|---|---|
| Largura mínima | `menu_min_width` | SLIDER (px/%) | — | `{{WRAPPER}} .dropdown-btn-menu { min-width: {{SIZE}}{{UNIT}}; }` |
| Padding item | `menu_item_padding` | DIMENSIONS | `12px` top/bottom, `20px` left/right | `{{WRAPPER}} .dropdown-btn-menu a { padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; }` |
| Fundo | `menu_color_bg` | COLOR | — | `{{WRAPPER}} .dropdown-btn-menu { background-color: {{VALUE}}; }` |
| Texto | `menu_color_txt` | COLOR | — | `{{WRAPPER}} .dropdown-btn-menu a { color: {{VALUE}}; }` |
| Texto hover | `menu_color_txt_hv` | COLOR | — | `{{WRAPPER}} .dropdown-btn-menu a:hover { color: {{VALUE}}; }` |
| Fundo hover | `menu_color_bg_hv` | COLOR | — | `{{WRAPPER}} .dropdown-btn-menu a:hover { background-color: {{VALUE}}; }` |
| Cor borda | `menu_color_bdr` | COLOR | — | `{{WRAPPER}} .dropdown-btn-menu { border-color: {{VALUE}}; }` |
| Cor separador | `menu_color_divider` | COLOR | — | `{{WRAPPER}} .dropdown-btn-menu a { border-bottom-color: {{VALUE}}; }` |

---

## Render HTML

```html
<div class="dropdown-btn-wrapper">
  <div class="dropdown-btn-container">
    <button class="dropdown-btn-toggle" type="button">
      <div class="dropdown-btn-content">
        <span class="dropdown-btn-label">DOWNLOAD</span>
        <span class="dropdown-btn-icon">
          <!-- Icons_Manager::render_icon() ou get_default_icon_svg() -->
        </span>
      </div>
    </button>
    <div class="dropdown-btn-menu">
      <!-- link criptografado -->
      <a href="/?jet_download=abc123sha1" rel="nofollow noopener">Português</a>
      <!-- fallback direto (JetElements inativo) -->
      <a href="https://exemplo.com/file.pdf" rel="nofollow noopener">English</a>
    </div>
  </div>
</div>
```

**Atributos `rel`:**
- Links criptografados (`?jet_download=`): `rel="nofollow noopener"` — o objetivo explícito é impedir rastreamento por buscadores
- Links fallback (URL direta): `rel="nofollow noopener"` igualmente
- Se `item_fallback_url` tiver `is_external: true`: adicionar também `target="_blank"`

---

## CSS — Ajustes em `bit-dropdown-btn.css`

Remover regras hardcoded que serão substituídas pelos seletores Elementor (o controle com `default` garante que o valor sempre existe):

- `width: 220px` em `.dropdown-btn-container button.dropdown-btn-toggle`
- `padding: 15px 25px` no mesmo seletor
- `border-radius: 4px` nas regras normal e hover do toggle (manter apenas o `border-radius: 4px 4px 0 0` do hover que é lógico de layout)

Adicionar:
- `.dropdown-btn-icon svg, .dropdown-btn-icon i { display: block; width: 100%; height: 100%; }` — normaliza tanto SVG inline quanto `<i>` de Font Awesome

---

## Verificação

1. No Elementor, buscar "BIT Download Button" → widget aparece na barra lateral
2. Inserir widget → Tab Content: configurar label + 2 links com attachments da biblioteca de mídia
3. Verificar URLs geradas no HTML: devem conter `?jet_download=` (não IDs numéricos diretos)
4. Clicar em link de download → arquivo serve corretamente (sem 404)
5. Hover: dropdown abre, cores mudam, ícone muda de cor
6. Tab Style → todos os controles refletem no preview do Elementor
7. Controle Typography → tipografia aplica nos seletores corretos
8. Fallback: deixar `item_file` vazio, preencher só `item_fallback_url` → link direto funciona com `rel="nofollow noopener"`
9. JetElements inativo: desativar plugin JetElements → widget renderiza usando `item_fallback_url` sem erro fatal (verificar que não há `Call to undefined function jet_elements_download_handler()`)
10. Mirror: confirmar que `docker-dev/common/mu-plugins/bit-dropdown-btn.php` e `.css` foram copiados e commitados no repo server-tools
