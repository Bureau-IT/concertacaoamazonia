# CLAUDE.md - Concertacao Amazonica

Site WordPress do projeto Concertacao Amazonica.

## Tema Ativo

- Parent: Hello Elementor
- Child: hello-elementor-child

## Padrao de Variaveis CSS (IMPORTANTE)

**Fonte da verdade: Global Colors do Elementor**

As variaveis CSS do tema DEVEM ser pareadas com os Global Colors do Elementor.
Nunca defina cores diretamente - sempre referencie a variavel global do Elementor.

**Regras:**

- O nome da variavel (--color-extra-1) deve corresponder ao nome no painel Global Colors
- O comentario deve indicar qual Global Color e referenciado
- Nunca use valores hex diretamente - sempre use var(--e-global-color-*)
- Mudancas de paleta sao feitas apenas no Elementor, propagam automaticamente

## Padroes de Codigo

- CSS: SEMPRE usar variaveis pareadas com Global Colors do Elementor
- PHP: Prefixo bureau_it_ para funcoes customizadas
- Hooks TEC: tribe_events_* para customizacoes de eventos

