# Otimização de Performance: WP Rocket Preload + TEC is_new_install

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminar dois vetores de slow requests que causaram spike de CPU e downtime transitório em 02/04/2026: (1) WP Rocket preload ativo por sitemap competindo com workers PHP-FPM de tráfego real; (2) `tribe_events_is_new_install()` executando `usort()` a cada request no bootstrap do WordPress.

**Architecture:** Dois fixes independentes e cirúrgicos. Fix 1: desativar `sitemap_preload` via WP-CLI diretamente em produção e limpar a fila pendente (`wp_wpr_rocket_cache` rows pending). Fix 2: mu-plugin que faz short-circuit em `tribe_events_is_new_install()` com transient de 24h, evitando o `usort()` caro. O mu-plugin segue o padrão do repositório — criado em `sites/concertacao/wordpress/wp-content/mu-plugins/` e copiado para `docker-dev/common/mu-plugins/`.

**Tech Stack:** WP-CLI, PHP 8.3, WordPress mu-plugins, SSH produção (`concertacaoamazonia.com.br-prod-sa`), rsync para deploy.

---

## Contexto de Produção

- Servidor: `concertacaoamazonia.com.br-prod-sa` (EC2 i-0c8178fe7ee985cc9)
- WP root: `/var/www/concertacaoamazonia.com.br`
- WP-CLI: `sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br`
- PHP-FPM reload: `sudo systemctl reload php8.3-fpm`
- mu-plugins prod: `/var/www/concertacaoamazonia.com.br/wp-content/mu-plugins/`
- mu-plugins dev: `sites/concertacao/wordpress/wp-content/mu-plugins/`
- mu-plugins canônico: `docker-dev/common/mu-plugins/`

---

## Task 1: Desativar WP Rocket Sitemap Preload e limpar fila

**Files:**
- Nenhum arquivo alterado — operação via WP-CLI em produção

**Contexto:** WP Rocket com `sitemap_preload=1` estava agendando preload de ~3.100 URLs via ActionScheduler, com até 25 workers simultâneos fazendo `curl_exec()` interno. Com `pm.max_children=55`, isso deixava apenas ~30 workers para tráfego real. O site tem tráfego CloudFront alto o suficiente para aquecer o cache naturalmente.

- [ ] **Step 1: Verificar estado atual do sitemap_preload**

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
   eval 'echo get_rocket_option(\"sitemap_preload\");'"
```
Esperado: `1`

- [ ] **Step 2: Desativar sitemap_preload via WP-CLI**

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
   eval 'update_rocket_option(\"sitemap_preload\", 0); echo get_rocket_option(\"sitemap_preload\");'"
```
Esperado: `0`

- [ ] **Step 3: Verificar contagem de rows pendentes antes de limpar**

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
   db query \"SELECT status, COUNT(*) as cnt FROM wp_wpr_rocket_cache GROUP BY status\""
```
Anote os valores.

- [ ] **Step 4: Limpar fila de preload pendente na tabela wp_wpr_rocket_cache**

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
   db query \"DELETE FROM wp_wpr_rocket_cache WHERE status='pending'\""
```

- [ ] **Step 5: Limpar ActionScheduler — jobs rocket_preload pendentes**

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
   db query \"DELETE FROM wp_actionscheduler_actions WHERE hook='rocket_preload_job_preload_url' AND status='pending'\""
```

- [ ] **Step 6: Verificar que a fila foi limpa**

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
   db query \"SELECT status, COUNT(*) as cnt FROM wp_wpr_rocket_cache GROUP BY status\" && \
   sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
   db query \"SELECT COUNT(*) as pendentes FROM wp_actionscheduler_actions WHERE hook='rocket_preload_job_preload_url' AND status='pending'\""
```
Esperado: `pending = 0` em ambas.

- [ ] **Step 7: Aguardar 2 minutos e verificar que não reagendou**

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
   db query \"SELECT COUNT(*) as novos_pending FROM wp_wpr_rocket_cache WHERE status='pending'\""
```
Esperado: `0`.

---

## Task 2: mu-plugin bit-tec-install-cache.php

**Files:**
- Criar: `sites/concertacao/wordpress/wp-content/mu-plugins/bit-tec-install-cache.php`
- Copiar para: `docker-dev/common/mu-plugins/bit-tec-install-cache.php`
- Deploy prod: rsync para `/var/www/concertacaoamazonia.com.br/wp-content/mu-plugins/`

**Contexto:** `tribe_events_is_new_install()` é chamada via hook `after_setup_theme` a cada request. Faz `tribe_get_option('previous_ecp_versions')` + `usort($versions, 'version_compare')` sobre 10 versões. Resultado sempre `false` neste site (há versões anteriores). O slow log mostra `usort()` em `install.php:14` centenas de vezes/hora.

**Estratégia:** mu-plugins carregam antes de plugins regulares. Se a função ainda não foi definida pelo TEC, podemos sobrescrevê-la com `function_exists()`. Caso contrário, usamos `add_filter('tribe_events_is_new_install', ...)` se o TEC aplicar esse filtro.

- [ ] **Step 1: Verificar se TEC usa apply_filters em tribe_events_is_new_install**

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "grep -n 'apply_filters\|tribe_events_is_new_install' \
   /var/www/concertacaoamazonia.com.br/wp-content/plugins/the-events-calendar/src/functions/utils/install.php"
```

Se a saída mostrar `apply_filters('tribe_events_is_new_install', ...)` → usar versão A (filtro).
Se não mostrar `apply_filters` → usar versão B (function_exists override).

- [ ] **Step 2A: Criar mu-plugin via filtro (se apply_filters existe)**

Se o Step 1 mostrou `apply_filters`, criar `sites/concertacao/wordpress/wp-content/mu-plugins/bit-tec-install-cache.php`:

```php
<?php
/**
 * Plugin Name: BIT TEC Install Cache
 * Description: Cache 24h de tribe_events_is_new_install() via filtro — evita usort() a cada request.
 * Version: 1.0.0
 * Author: Bureau de Tecnologia
 *
 * Spike CPU 02/04/2026: usort() em TEC install.php:14 aparecia centenas de vezes/hora no slow log.
 * A função sempre retorna false neste site (previous_ecp_versions tem 10 entradas).
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'tribe_events_is_new_install', 'bit_tec_install_cache', 1 );

function bit_tec_install_cache() {
	$cache_key = 'bit_tec_is_new_install';
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return (bool) $cached;
	}

	// Chamar original sem o filtro para calcular o valor real.
	remove_filter( 'tribe_events_is_new_install', 'bit_tec_install_cache', 1 );
	$result = tribe_events_is_new_install();
	add_filter( 'tribe_events_is_new_install', 'bit_tec_install_cache', 1 );

	set_transient( $cache_key, $result ? '1' : '0', DAY_IN_SECONDS );

	return $result;
}
```

- [ ] **Step 2B: Criar mu-plugin via function_exists (se NÃO há apply_filters)**

Se o Step 1 NÃO mostrou `apply_filters`, criar `sites/concertacao/wordpress/wp-content/mu-plugins/bit-tec-install-cache.php`:

```php
<?php
/**
 * Plugin Name: BIT TEC Install Cache
 * Description: Override de tribe_events_is_new_install() com transient 24h — evita usort() a cada request.
 * Version: 1.0.1
 * Author: Bureau de Tecnologia
 *
 * mu-plugins carregam antes de plugins regulares, então function_exists() retorna false aqui.
 * Spike CPU 02/04/2026: usort() em TEC install.php:14 aparecia centenas de vezes/hora no slow log.
 * A função sempre retorna false neste site (previous_ecp_versions tem 10 entradas).
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tribe_events_is_new_install' ) ) {
	function tribe_events_is_new_install() {
		$cache_key = 'bit_tec_is_new_install';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$previous_versions = array_filter( (array) tribe_get_option( 'previous_ecp_versions', [] ) );
		usort( $previous_versions, 'version_compare' );
		$result = empty( $previous_versions );

		set_transient( $cache_key, $result ? '1' : '0', DAY_IN_SECONDS );

		return $result;
	}
}
```

- [ ] **Step 3: Copiar para mu-plugins canônico**

```bash
cp sites/concertacao/wordpress/wp-content/mu-plugins/bit-tec-install-cache.php \
   /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/bit-tec-install-cache.php
```

- [ ] **Step 4: Deploy para produção via rsync**

Executar a partir de `/Users/dcambria/scripts/server-tools/v2/docker-dev/`:

```bash
rsync -avz \
  sites/concertacao/wordpress/wp-content/mu-plugins/bit-tec-install-cache.php \
  concertacaoamazonia.com.br-prod-sa:/var/www/concertacaoamazonia.com.br/wp-content/mu-plugins/
```

- [ ] **Step 5: Reload PHP-FPM**

```bash
ssh concertacaoamazonia.com.br-prod-sa "sudo systemctl reload php8.3-fpm"
```

- [ ] **Step 6: Forçar primeiro request para popular o transient**

```bash
curl -s -o /dev/null -w "%{http_code}" https://concertacaoamazonia.com.br/
```
Esperado: `200`

- [ ] **Step 7: Verificar que o transient foi criado**

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
   eval 'var_dump(get_transient(\"bit_tec_is_new_install\"));'"
```
Esperado: `string(1) "0"` (false = não é nova instalação).

- [ ] **Step 8: Verificar que o slow log não registra mais usort() em install.php (após 5 min)**

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo tail -200 /var/log/php-fpm-wordpress-slow.log | grep -c 'install.php'"
```
Esperado: `0` nas entradas mais recentes.

---

## Task 3: Commit e atualização do CLAUDE.md

**Files:**
- Modificar: `sites/concertacao/CLAUDE.md` — adicionar entry na tabela de mu-plugins

- [ ] **Step 1: Adicionar linha na tabela de mu-plugins do CLAUDE.md**

Em `sites/concertacao/CLAUDE.md`, seção `## mu-plugins específicos deste site`, adicionar ao final da tabela:

```markdown
| `bit-tec-install-cache.php` | Cache 24h de `tribe_events_is_new_install()` — elimina `usort()` custoso a cada request (spike CPU 02/04/2026) |
```

- [ ] **Step 2: Commit a partir do repositório server-tools**

```bash
cd /Users/dcambria/scripts/server-tools/v2 && \
git add \
  docker-dev/common/mu-plugins/bit-tec-install-cache.php \
  docker-dev/sites/concertacao/wordpress/wp-content/mu-plugins/bit-tec-install-cache.php \
  docker-dev/sites/concertacao/CLAUDE.md && \
git commit -m "perf(concertacao): cacheia tribe_events_is_new_install() — elimina usort() por request

Spike CPU 02/04/2026: usort() em TEC install.php:14 aparecia centenas de vezes/hora.
A função sempre retorna false neste site (10 versões em previous_ecp_versions).
Transient de 24h elimina o custo de query + sort a cada request.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Verificação Final (após 30 minutos)

- [ ] **PHP-FPM slow counter**

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo systemctl status php8.3-fpm --no-pager | grep 'slow:'"
```
Comparar com baseline: antes era `slow: 14` em ~20 minutos de uptime.

- [ ] **Zero novos jobs rocket_preload pendentes**

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
   db query \"SELECT COUNT(*) FROM wp_wpr_rocket_cache WHERE status='pending'\""
```
Esperado: `0`.

- [ ] **Transient TEC ainda existe (não expirou)**

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
   eval 'var_dump(get_transient(\"bit_tec_is_new_install\"));'"
```
Esperado: `string(1) "0"`.
