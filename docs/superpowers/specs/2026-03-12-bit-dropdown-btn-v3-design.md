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

```
encrypt_id($id) → sha1(NONCE_SALT . $id) → salva mapa → retorna hash
URL gerada: home_url('/?jet_download=' . $hash)
```

O handler intercepta no hook `init` (priority 99), decripta o hash, valida o attachment e serve o arquivo via headers HTTP com streaming em chunks de 1MB.

**Fallback:** se `Jet_Elements_Download_Handler` não estiver disponível (JetElements inativo), o widget usa `item_fallback_url` como link direto sem criptografia.

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
| Links (repeater) | `links` | REPEATER | 2 itens padrão |
| ↳ Rótulo do item | `item_label` | TEXT | `Português` |
| ↳ Arquivo (media) | `item_file` | MEDIA | — |
| ↳ URL fallback | `item_fallback_url` | URL | — |

**Lógica de URL por item:**
1. Se `item_file` tem ID → `encrypt_id(id)` → `?jet_download=HASH`
2. Senão → `item_fallback_url` (link direto)
3. Se nenhum → item não renderizado

### Tab Style

#### Seção: Botão — Dimensões

| Controle | ID | Tipo | Seletor CSS |
|---|---|---|---|
| Largura | `btn_width` | SLIDER (px/%) | `{{WRAPPER}} .dropdown-btn-toggle { width: {{SIZE}}{{UNIT}}; }` |
| Border radius | `btn_border_radius` | DIMENSIONS | `{{WRAPPER}} .dropdown-btn-toggle { border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; }` |
| Padding | `btn_padding` | DIMENSIONS | `{{WRAPPER}} .dropdown-btn-toggle { padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; }` |
| Box shadow | `btn_box_shadow` | BOX_SHADOW | `{{WRAPPER}} .dropdown-btn-toggle` |

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
| Borda hover | `color_bdr_hv` | `--btn-normal-border-hv` |

#### Seção: Ícone

| Controle | ID | Tipo | Seletor/Var |
|---|---|---|---|
| Ícone | `icon` | ICONS | renderizado via `Icons_Manager::render_icon()` |
| Tamanho | `icon_size` | SLIDER (px) | `{{WRAPPER}} .dropdown-btn-icon svg { width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}}; }` |
| Cor normal | `color_icn` | COLOR | `--btn-normal-icn` |
| Cor hover | `color_icn_hv` | COLOR | `--btn-normal-icon-hv` |

**Default do ícone:** SVG inline do círculo com seta (path do `--ucpa-icon-download`) pré-configurado como default no controle ICONS via `skin: 'caret'` ou SVG embutido como valor padrão.

#### Seção: Tipografia

| Controle | ID | Tipo | Seletor |
|---|---|---|---|
| Label botão | `label_typography` | TYPOGRAPHY | `{{WRAPPER}} .dropdown-btn-label` |
| Itens menu | `menu_typography` | TYPOGRAPHY | `{{WRAPPER}} .dropdown-btn-menu a` |

#### Seção: Menu Dropdown

| Controle | ID | Tipo | Seletor/Var |
|---|---|---|---|
| Largura mínima | `menu_min_width` | SLIDER (px/%) | `{{WRAPPER}} .dropdown-btn-menu { min-width: {{SIZE}}{{UNIT}}; }` |
| Padding item | `menu_item_padding` | DIMENSIONS | `{{WRAPPER}} .dropdown-btn-menu a { padding: ... }` |
| Fundo | `menu_color_bg` | COLOR | `{{WRAPPER}} .dropdown-btn-menu { background-color: {{VALUE}}; }` |
| Texto | `menu_color_txt` | COLOR | `{{WRAPPER}} .dropdown-btn-menu a { color: {{VALUE}}; }` |
| Texto hover | `menu_color_txt_hv` | COLOR | `{{WRAPPER}} .dropdown-btn-menu a:hover { color: {{VALUE}}; }` |
| Fundo hover | `menu_color_bg_hv` | COLOR | `{{WRAPPER}} .dropdown-btn-menu a:hover { background-color: {{VALUE}}; }` |
| Cor borda | `menu_color_bdr` | COLOR | `{{WRAPPER}} .dropdown-btn-menu { border-color: {{VALUE}}; }` |
| Cor separador | `menu_color_divider` | COLOR | `{{WRAPPER}} .dropdown-btn-menu a { border-bottom-color: {{VALUE}}; }` |

---

## Render HTML

```html
<div class="dropdown-btn-wrapper">
  <div class="dropdown-btn-container">
    <button class="dropdown-btn-toggle" type="button">
      <div class="dropdown-btn-content">
        <span class="dropdown-btn-label">DOWNLOAD</span>
        <span class="dropdown-btn-icon">
          <!-- Icons_Manager::render_icon() output -->
        </span>
      </div>
    </button>
    <div class="dropdown-btn-menu">
      <a href="/?jet_download=abc123..." rel="noopener">Português</a>
      <a href="/?jet_download=def456..." rel="noopener">English</a>
    </div>
  </div>
</div>
```

---

## CSS — Ajustes em `bit-dropdown-btn.css`

- Remover regras `width: 220px` hardcoded do `.dropdown-btn-toggle` (passa a ser controlado via Elementor)
- Remover `padding: 15px 25px` hardcoded (idem)
- Remover `border-radius: 4px` hardcoded onde sobrepõe o controle Elementor
- Adicionar: `.dropdown-btn-icon svg { display: block; }` para normalizar SVG inline
- Manter: hover, dropdown menu, transições

---

## Verificação

1. No Elementor, buscar "BIT Download Button" → widget aparece
2. Inserir widget → Tab Content: configurar label + 2 links com attachments da mídia
3. Verificar URLs geradas: devem ser `?jet_download=HASH` (não IDs diretos)
4. Hover: dropdown abre, cores mudam, ícone muda de cor
5. Tab Style → todos os controles refletem no preview
6. Controle Typography → tipografia aplica nos seletores corretos
7. Download efetivo: clicar em link → arquivo serve corretamente
8. Fallback: remover attachment, preencher só `item_fallback_url` → link direto funciona
9. JetElements inativo: widget renderiza com fallback URL sem erro fatal
