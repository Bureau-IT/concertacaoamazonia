# ADR: Configuração híbrida de uploads em prod (Concertação)

**Data:** 2026-05-02
**Status:** Proposed (await user decision)
**Decisores:** Daniel Cambría
**Contexto:** Pós-incidente preloader 2026-05-02

## Contexto

A produção do Concertação opera hoje em uma configuração **híbrida** para uploads:

- Plugin **S3-Uploads** está **instalado e ativo** no `wp-config.php`, porém com a constante `S3_UPLOADS_USE_LOCAL=true` — ou seja, o filtro `upload_dir` continua apontando para o filesystem local (`/var/www/.../wp-content/uploads`), e nenhum upload novo é gravado no bucket pelo PHP.
- O CloudFront `E2F1QD7E7YOYEB` tem um behavior dedicado para `wp-content/uploads/*` que aponta para a **origem S3** `concertacaoamazonia-com-br-wp-static-prd-sa` via OAC, com `OriginPath=/assets/uploads`.
- Um sync periódico (e o `phase7-cutover.sh` step 1c) mantém o bucket atualizado a partir do filesystem local.
- Resultado prático: o navegador puxa imagens do CloudFront (que serve do S3), mas o WordPress, ao chamar `get_attached_file()`/`wp_get_attachment_metadata()`, lê do **FS local**.

Essa discrepância foi a **causa raiz do incidente preloader 2026-05-02**: após cutover blue→green, o bucket tinha o asset, mas a nova instância green não recebeu o arquivo no FS local — `get_attached_file()` retornou um path inexistente, e o widget falhou. A mitigação atual (`phase7-cutover.sh` step 1e v1.6.3+, hardening de filtro em v1.6.4) sincroniza S3→FS local automaticamente em todo cutover, eliminando o gap.

**Limitação do step 1e:** roda apenas em phase7-cutover. Deploys de uploads via `std share deploy`, rsync direto ou import wp-content NÃO disparam — operador precisa sincronizar manualmente nessas vias.

Historicamente, essa configuração existe porque migramos para CDN antes de avaliar o custo/risco de ativar S3-Uploads completo. O bucket OAC ficou pronto, mas o switch `USE_LOCAL` nunca foi virado.

## Forças/Trade-offs

- **Performance:** CloudFront serve uploads sem tocar EC2 — cache-hit ratio alto, TTFB baixo. Excelente.
- **Custo:** S3 storage (~26GB) + traffic CloudFront é mais barato que servir mídia direto do EC2 com EBS. [a investigar números mensais reais]
- **Complexidade:** duas fontes de verdade (FS local + bucket) precisam ficar sincronizadas. Drift é silencioso até alguém chamar `get_attached_file()`.
- **Vendor lock parcial:** plugin S3-Uploads desligado mas bucket OAC ativo — meio-termo confuso para quem chega novo no projeto.
- **Stateful EC2:** uploads no FS local impedem horizontal scaling fácil (cada instância precisa ter cópia).

## Opções consideradas

### Opção A — Manter o status quo (uploads híbrido + step 1e)

- **Vantagens:** já implementado, funciona, performance via CDN preservada, custo conhecido.
- **Desvantagens:** complexidade carregada, drift latente, todo novo plugin/mu-plugin precisa ser auditado para ver se depende de path local.
- **Estado:** em produção desde 2026-04-30, com mitigação automática a partir de 2026-05-02.

### Opção B — Ativar S3-Uploads de verdade (`USE_LOCAL=false`)

- **Vantagens:** uma única fonte da verdade (S3), `get_attached_file()` retorna path S3 via stream wrapper, FS local zerado, EC2 stateless.
- **Desvantagens:** todas as operações de upload do WP passam por S3 API (latência adicional, custo de PUT/GET); plugins legacy (EWWW, JetEngine media) podem quebrar; rollback é caro.
- **Custo:** [a investigar — GetObject ~$0.0004/1k requests + PUT ~$0.005/1k]
- **Risco:** alto.

### Opção C — Reverter — uploads só no FS local, CloudFront direto no EC2 ALB

- **Vantagens:** simplicidade máxima, uma fonte da verdade, sem step 1e, sem bucket, sem OAC.
- **Desvantagens:** EC2 serve uploads (mais carga, banda egress); perde isolamento; backup do EBS é menos eficiente que versionamento S3.
- **Custo:** mais transferência egress EC2, menos storage S3. [a investigar]
- **Risco:** médio (regressão de performance, perda do isolamento de mídia em backups).

### Opção D — Migrar para S3-Uploads completo + remover EBS de uploads

- **Vantagens:** stateless EC2, scaling horizontal trivial, backup unificado em S3 versionado.
- **Desvantagens:** migração demorada (auditoria de plugins, testes em HML, janela de cutover), riscos durante transição, plugins legacy precisam revalidar leitura/escrita via stream wrapper.
- **Risco:** alto na transição, mas estratégico no longo prazo.

## Decisão (Proposta)

**Manter a Opção A** com sentinelas de monitoramento e re-avaliar em 90 dias.

Justificativa: incidente é raro (1 em 60 dias), mitigação automática (step 1e) já está em produção desde v1.6.4, e o custo/risco das Opções B/D é desproporcional para um padrão de incidência baixo. A Opção C perde a otimização CDN sem ganho proporcional.

## Consequências

- Próximos cutovers blue↔green executam auto-sync S3→FS via step 1e — sem incidentes esperados.
- Toda mudança em mu-plugins/temas que chame `get_attached_file()` ou `wp_get_attachment_metadata()` deve documentar a dependência do FS local.
- Próximos sites EC2 com mesma topologia herdam essa complexidade — vale documentar como REQUISITO arquitetural no `bootstrap`/setup.
- Memos `feedback_preloader_filesystem_local.md` e `feedback_s3_uploads_off_sync_required.md` permanecem como referência operacional.

## Sentinelas

- **Drift bucket vs FS:** comparar contagem precisa de objetos
  ```bash
  AWS_PROFILE=Concertação aws s3api list-objects-v2 \
    --bucket concertacaoamazonia-com-br-wp-static-prd-sa \
    --prefix assets/uploads/ --query 'KeyCount' --output text
  ssh concertacaoamazonia.com.br-prod-sa \
    "find /var/www/concertacaoamazonia.com.br/wp-content/uploads -type f | wc -l"
  # Diff esperado: <5% (justifica WP Rocket cache, thumbs Elementor regenerados em runtime)
  ```
- **Integridade attachment vs FS** (gate que detectaria o próprio incidente — executar semanal via cron):
  ```bash
  ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data wp \
    --path=/var/www/concertacaoamazonia.com.br eval '
    \$ids = get_posts([\"post_type\"=>\"attachment\",\"posts_per_page\"=>50,
                      \"orderby\"=>\"rand\",\"fields\"=>\"ids\"]);
    \$missing = array_filter(\$ids, fn(\$id) => !file_exists(get_attached_file(\$id)));
    echo count(\$missing) . \" of \" . count(\$ids) . \" missing on FS\";'"
  # Esperado: 0 of 50. Qualquer >0 abre review imediato.
  ```
- **CloudWatch:** alarme em 4xx ratio do behavior `wp-content/uploads/*` (>1% por 1h dispara review).
- **Smoke test:** gate 11/12 (warm-up de menu) detecta TTFB anormal pós-cutover.

## Re-avaliação

**Quando:** 90 dias (2026-08-02) ou após próximo incidente similar.
**Quem:** Daniel Cambría.
**Triggers automáticos:**
- CloudWatch alarme 4xx do behavior uploads superar 1% por 1h → abrir review imediato.
- Sentinela "Integridade attachment vs FS" reportar `>0 missing` → review.
- Instalação de novo plugin/mu-plugin que escreva em `/uploads` → revisar dependência de path local antes de deploy.

## Referências

- Incidente preloader 2026-05-02 — memo `feedback_preloader_filesystem_local.md`
- Memo `feedback_cf_oac_green_to_assets_swap.md`
- Memo `feedback_s3_uploads_off_sync_required.md`
- `phase7-cutover.sh` v1.6.4 — step 1e (auto-sync S3→FS local)
- Cycle 4 CF-OAC migration log: `ec2-deploy/cloudfront-configs/.circle4-cutover-state.txt`
