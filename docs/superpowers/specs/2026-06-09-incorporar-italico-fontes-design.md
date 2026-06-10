# Incorporar itálico às fontes do site — Design

- **Data:** 2026-06-09
- **Autor:** Daniel Cambría
- **Site:** Concertação Amazônica (concertacaoamazonia.com.br)
- **Ambiente de aplicação inicial:** DEV (`cambrasmax.local:8484`) — **prod só após validação**
- **Tema:** `hello-elementor-child`

## Problema

Textos que pedem itálico (`<em>`, `<i>`, `<cite>`, itálico configurado no
editor) aparecem **retos** no corpo do site. A causa é a combinação de dois
fatores:

1. O child theme declara `font-synthesis: none` em `css/base.css` (regra `* {}`,
   linhas 19–21). Isso impede o navegador de **fabricar** itálico/oblíquo e
   negrito. A regra foi posta deliberadamente para impedir **fake-bold** de
   pesos inexistentes da Just Sans (300/500/600/700/900) — ver comentário
   2.0 no `base.css`.
2. A fonte do corpo — **Just Sans** — **não possui itálico desenhado**. A
   fundição Just Creative oferece 8 estilos da família, **todos verticais**
   (confirmado em MyFonts, 2026-06-09). Não existe `JustSans-Italic` para
   comprar ou baixar.

Resultado: `font-style: italic` no corpo é silenciosamente ignorado.

### Estado atual das famílias

| Família | Papel | Aplicada via | Itálico desenhado |
|---------|-------|--------------|:---:|
| **Franie** | Títulos/display (`primary`) | `--e-global-typography-primary-font-family` | ✓ sim (Regular/Bold + Italic/BoldItalic, arquivos já no tema) |
| **Just Sans** | Corpo do texto (`text`) | `--e-global-typography-text-font-family` (vale `"Just Sans"`) | ✗ **não existe** |
| **Roboto** | Labels do SVG da espiral (`secondary`/`accent`) | SVG inline + global | n/a (não é texto corrido) |

Verificado no kit ativo `2553`: a Just Sans é a tipografia global `text`, e é
sempre aplicada no CSS gerado do Elementor por
`font-family: var(--e-global-typography-text-font-family)`.

## Objetivo

Fazer o itálico funcionar no corpo do texto **sem** reabrir a porta para
fake-bold e **sem** alterar a identidade tipográfica (Just Sans permanece a
fonte do corpo).

## Solução — duas camadas

### Camada 1 — Oblíquo sintético cirúrgico para a Just Sans (base universal)

Como não há itálico desenhado, a aproximação legítima é o **oblíquo sintético**:
o navegador inclina a própria Just Sans (~12°) quando `font-style: italic` é
pedido. Para não reativar fake-bold, reabilitamos **apenas a síntese de
itálico** (`font-synthesis: style`, não `weight`), e **apenas** para a Just
Sans — a Franie continua intacta com `font-synthesis: none` herdado e seus
arquivos itálicos verdadeiros.

Âncoras de seleção (robustas, casam com como o Elementor aplica a fonte):

- Elementos cuja `font-family` resolve para a variável global de texto
  (`--e-global-typography-text-font-family`).
- `<em>`, `<i>`, `<cite>` dentro do conteúdo do corpo.

A síntese fica restrita a `style` — fake-bold continua proibido em **ambos** os
pesos reais da Just Sans (400 e 800). O oblíquo vale para os dois pesos, então
negrito-itálico (`<strong><em>`) renderiza como negrito inclinado.

### Camada 2 — Franie Italic para ênfase editorial (premium, opt-in)

A Franie já tem itálico verdadeiro (cursivo, desenhado). Expomos uma classe
utilitária para usos editoriais escolhidos a dedo (citações de obras, epígrafes),
onde se queira itálico verdadeiro em vez de oblíquo:

```css
.bit-emphasis-serif,
.bit-emphasis-serif em,
.bit-emphasis-serif i {
    font-family: 'Franie', serif;
    font-style: italic;
}
```

Aplicável por classe num widget/trecho específico no Elementor. Não afeta o
corpo geral.

## Arquivos tocados

1. `wordpress/wp-content/themes/hello-elementor-child/css/base.css`
   — regra `font-synthesis: style` cirúrgica para Just Sans (seção nova logo
   após a regra 2.0) + classe `.bit-emphasis-serif`.
2. `wordpress/wp-content/themes/hello-elementor-child/style.css` — bump de
   versão (regra do projeto).
3. **Nenhum arquivo de fonte novo.**

## Fora de escopo (YAGNI)

- Remover o `font-synthesis: none` global — **não**, quebraria a proteção de
  fake-bold e afetaria a Franie.
- Itálico para Roboto/Plus Jakarta Sans — não usadas em texto corrido.
- Comprar/obter itálico verdadeiro da Just Sans — **não existe**.
- Deploy em produção neste passo — primeiro validação em dev.

## Validação (DEV)

- Flush de cache dev; abrir `cambrasmax.local:8484`.
- Num trecho `<em>` em Just Sans: confirmar transição reto → inclinado via
  `getComputedStyle().fontStyle` e inclinação real renderizada
  (bounding/screenshot antes-depois).
- Confirmar que Franie em itálico segue usando o arquivo verdadeiro (não
  sintético) e que fake-bold da Just Sans continua proibido.

## Riscos e mitigações

- **Risco:** regra de seleção ampla demais inclina onde não deveria.
  **Mitigação:** restringir a `style` (sem `weight`) e ancorar na variável de
  texto global + `em/i/cite`; validar visualmente em dev antes de prod.
- **Risco:** oblíquo é estética diferente de itálico verdadeiro.
  **Mitigação:** é a única aproximação possível (a fonte não tem itálico);
  Camada 2 oferece itálico verdadeiro (Franie) onde a sofisticação importar.
