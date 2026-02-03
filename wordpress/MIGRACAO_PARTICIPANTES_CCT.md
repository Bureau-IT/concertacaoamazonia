# Migração: CPT participantes → CCT participantes_cct

**Data:** 2026-02-02
**Ambiente:** DEV (Docker local)
**Status:** Concluído

---

## Resumo da Migração

| Item | CPT Original | CCT Novo |
|------|-------------|----------|
| **Registros migrados** | 1.287 | 1.287 |
| **Tabela** | `wp_posts` + `wp_postmeta` | `wp_jet_cct_participantes_cct` |
| **Query JetEngine** | ID 47 (posts) | ID 71 (CCT) |
| **CCT ID** | - | 70 |

---

## Estrutura do CCT

**Tabela:** `wp_jet_cct_participantes_cct`

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `_ID` | bigint (PK) | ID auto-increment |
| `cct_status` | text | Status do item (publish, draft, etc.) |
| `item_title` | text | Nome do participante |
| `item_content` | longtext | Conteúdo/descrição |
| `item_thumbnail` | bigint | ID da imagem de destaque |
| `organizacao` | text | Organização/empresa |
| `link_de_acesso` | text | Link de acesso externo |
| `cct_author_id` | bigint | ID do autor |
| `cct_created` | datetime | Data de criação |
| `cct_modified` | datetime | Data de modificação |

---

## IDs Importantes

| Recurso | ID | Uso |
|---------|:--:|-----|
| CCT participantes_cct | **70** | Custom Content Type no JetEngine |
| Query Participantes CCT | **71** | Nova query para listar CCT |
| Query filter Participantes (antiga) | **47** | Query original do CPT (deprecada) |

---

## Próximos Passos

### 1. Atualizar Listings

Os listings que usavam o CPT `participantes` precisam ser atualizados para usar o CCT:

1. Acesse: **JetEngine > Listings**
2. Localize listings que usam `participantes`
3. Altere a fonte de dados:
   - De: `Posts` > `participantes`
   - Para: `Custom Content Type` > `participantes_cct`
4. Ajuste os campos dinâmicos:
   - `post_title` → `item_title`
   - `post_content` → `item_content`
   - `_thumbnail_id` → `item_thumbnail`

### 2. Atualizar Queries

Se algum widget Elementor usa a Query ID 47 diretamente:

1. Acesse o template Elementor
2. Localize o widget Listing Grid
3. Altere a Query de **47** para **71**

### 3. Verificação no Admin

1. **JetEngine > Custom Content Types > participantes_cct**
   - Verificar se todos os 1.287 itens estão listados
   - Confirmar campos preenchidos corretamente

2. **JetEngine > Query Builder > Query Participantes CCT (ID: 71)**
   - Testar a query no preview
   - Confirmar ordenação por `item_title` ASC

### 4. Teste no Frontend

1. Acesse páginas que listam participantes
2. Verifique se a lista está correta
3. Confirme a ordenação alfabética
4. Teste filtros (se houver)

### 5. Rollback (se necessário)

O CPT original `participantes` **NÃO foi removido**. Para reverter:

1. Volte os listings para usar CPT `participantes`
2. Volte as queries para ID 47
3. O CCT pode ser removido depois

**Dados do CPT original:**
- 1.287 posts ainda existem em `wp_posts`
- Meta fields ainda existem em `wp_postmeta`

### 6. Limpeza Final (após validação em produção)

**Somente após confirmação de funcionamento em PROD:**

1. Remover CPT `participantes` do JetEngine
2. Limpar posts antigos do `wp_posts`
3. Limpar meta fields órfãos do `wp_postmeta`
4. Remover Query ID 47 (opcional, manter como backup)

---

## Comparativo de Performance

### Antes (CPT)
```sql
SELECT p.*, pm1.meta_value AS organizacao, pm2.meta_value AS link
FROM wp_posts p
LEFT JOIN wp_postmeta pm1 ON p.ID = pm1.post_id AND pm1.meta_key = 'organizacao'
LEFT JOIN wp_postmeta pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'link-de-acesso'
WHERE p.post_type = 'participantes' AND p.post_status = 'publish'
ORDER BY p.post_title ASC
```
- **JOINs:** 2
- **Tabelas:** 2 (posts + postmeta)

### Depois (CCT)
```sql
SELECT * FROM wp_jet_cct_participantes_cct
WHERE cct_status = 'publish'
ORDER BY item_title ASC
```
- **JOINs:** 0
- **Tabelas:** 1

**Ganho estimado:** 2-5x mais rápido em queries complexas

---

## Arquivos Criados/Removidos

| Arquivo | Status | Descrição |
|---------|--------|-----------|
| `create-participantes-cct.php` | ✅ Removido | Script para criar CCT |
| `migrate-participantes-to-cct.php` | ✅ Removido | Script de migração de dados |
| `create-participantes-cct-query.php` | ✅ Removido | Script para criar Query |
| `MIGRACAO_PARTICIPANTES_CCT.md` | ✅ Criado | Esta documentação |

---

## URLs de Referência

- **CCT no Admin:** https://cambrasmax.local:8484/wp-admin/admin.php?page=jet-engine-cct&cpt_action=edit&id=70
- **Query no Admin:** https://cambrasmax.local:8484/wp-admin/admin.php?page=jet-engine-query&query_action=edit&id=71
- **Listar CCT Items:** https://cambrasmax.local:8484/wp-admin/admin.php?page=jet-cct-participantes_cct

---

## Contato

Migração realizada por: **Claude Code**
Solicitado por: Daniel Cambría
