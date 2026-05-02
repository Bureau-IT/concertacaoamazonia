# Dificuldades do Deploy #2 Blue-Green — concertacaoamazonia.com.br

**Data:** 2026-04-13
**Instancia Green:** `i-0f3787bccdd936dac` (t3.large, EIP 54.207.84.130)
**Banco:** `wp_concertacao_20260412` (Aurora MySQL)
**Deploy anterior:** 2026-04-08 (primeiro ciclo blue-green)

---

## Tabela Resumo

| # | Problema | Categoria | Severidade | Tempo Perdido |
|---|----------|-----------|------------|---------------|
| 1 | HML para GREEN impossivel (scripts hardcoded) | Scripts/Automacao | Medio | ~20 min |
| 2 | EIP renomeado no AWS nao bate com .env | AWS/Infra | Alto | ~10 min |
| 3 | SSH host key changed (novo EC2 no mesmo EIP) | AWS/Infra | Baixo | ~2 min |
| 4 | Post-deploy executado do diretorio errado | Scripts/Automacao | Alto | ~10 min |
| 5 | DNS check falha (CloudFront na frente do ALB) | AWS/Infra | Medio | ~10 min |
| 6 | Arquivos S3 no path dev/ mas script busca hml/ | Scripts/Automacao | Alto | ~15 min |
| 7 | DROP command denied no Aurora (falta GRANT) | AWS/Infra | Critico | ~60 min |
| 8 | GRANT via SSH falha (env var local nao disponivel) | Scripts/Automacao | Alto | ~20 min |
| 9 | Post-deploy --scripts syntax errada (= vs espaco) | Scripts/Automacao | Medio | ~15 min |
| 10 | siteurl ainda com cambrasmax.local:8484 | WordPress | Alto | ~30 min |
| 11 | Nginx duplicate directives (script 03) | Scripts/Automacao | Medio | ~15 min |
| 12 | Health check 404 na porta 8080 | AWS/Infra | Alto | ~10 min |
| 13 | Redis INATIVO (drop-in nao instalado) | WordPress | Medio | ~10 min |
| 14 | FPM config path errado (www.conf vs wordpress.conf) | Scripts/Automacao | Medio | ~5 min |
| 15 | CloudFront source-ip ALB rules nao funcionam | Cache/CDN | Alto | ~20 min |
| 16 | CloudFront cacheando blue para requests X-Test-Green | Cache/CDN | Alto | ~20 min |
| 17 | OPcache servindo versao antiga do check-ec2.php | Cache/CDN | Medio | ~10 min |
| 18 | check-ec2.php desatualizado no green (v1.30 vs v1.33) | Processo | Baixo | ~10 min |
| 19 | check-ec2.php cacheado pelo CloudFront | Cache/CDN | Baixo | ~5 min |
| 20 | Uploads 404 (export sem --include-uploads) | Processo | Critico | ~60 min |
| 21 | Permissao negada no S3 sync para green (ubuntu vs www-data) | Permissoes | Medio | ~10 min |
| 22 | Dois processos S3 sync duplicados em prod | Processo | Medio | ~15 min |
| 23 | Target group errado no .env (hml-tg vs green-tg) | AWS/Infra | Alto | ~10 min |
| 24 | Questionamento excessivo durante execucao | Processo | Medio | ~15 min |

---

## Detalhes Expandidos

### 1. HML para GREEN impossivel (scripts hardcoded)

**O que aconteceu:** O usuario pediu para renomear as referencias de `HML` para `GREEN` no `.env` para melhor semantica. Ao investigar, descobriu-se que 21+ scripts validam `ENVIRONMENT` com regex `^(prod|hml|dev)$`, impedindo o uso de `green`.

**Causa raiz:** Os scripts de post-deploy (`post-deploy.sh`, `create-ec2.sh`, e scripts individuais) fazem validacao hardcoded dos valores de ambiente. Nao ha suporte para valores customizados.

**Como foi resolvido:** Manteve-se `_HML` como sufixo das variaveis, adicionando comentarios semanticos no `.env` explicando que HML = GREEN neste contexto.

**Licao / Prevencao:** Considerar refatorar os scripts para aceitar um parametro `--label` ou alias de ambiente. Ou criar uma enum extensivel em vez de regex fixa. Ate la, documentar no `.env` que HML = GREEN.

**Categoria:** Scripts/Automacao

---

### 2. EIP renomeado no AWS nao bate com .env

**O que aconteceu:** A criacao da EC2 falhou porque o script nao encontrou o EIP com tag Name `eip-concertacao-hml`. O `describe-addresses` retornava vazio.

**Causa raiz:** O EIP havia sido renomeado no console AWS de `eip-concertacao-hml` para `eip-concertacao-green` durante o deploy anterior, mas o `.env` ainda referenciava o nome antigo.

**Como foi resolvido:** Listou-se todos os EIPs (`aws ec2 describe-addresses`), identificou-se o nome correto `eip-concertacao-green`, e atualizou-se `EIP_ALLOCATION_HML` no `.env`.

**Licao / Prevencao:** Antes de cada deploy, validar que todos os recursos AWS referenciados no `.env` (EIP, TGs, Security Groups) existem com os nomes configurados. Criar um script de pre-flight check.

**Categoria:** AWS/Infra

---

### 3. SSH host key changed (novo EC2 no mesmo EIP)

**O que aconteceu:** Ao tentar SSH para o green, a conexao foi recusada com `REMOTE HOST IDENTIFICATION HAS CHANGED`. O EIP `54.207.84.130` agora aponta para uma instancia diferente.

**Causa raiz:** Comportamento esperado ao reutilizar um EIP com uma nova instancia EC2. A host key da instancia anterior estava cacheada em `~/.ssh/known_hosts`.

**Como foi resolvido:** `ssh-keygen -R 54.207.84.130` seguido de reconexao com `StrictHostKeyChecking=accept-new`.

**Licao / Prevencao:** Incluir a limpeza automatica da known_hosts no script `create-ec2.sh` apos associar o EIP. Ou usar `StrictHostKeyChecking=accept-new` por padrao para IPs de staging.

**Categoria:** AWS/Infra

---

### 4. Post-deploy executado do diretorio errado

**O que aconteceu:** O `post-deploy.sh` falhou com "Diretorio de scripts nao encontrado: ./post-deploy" porque foi executado de `/v2/` em vez de `/v2/ec2-deploy/`.

**Causa raiz:** O script usa caminhos relativos (`./post-deploy/`) e espera ser executado de dentro do diretorio `ec2-deploy/`.

**Como foi resolvido:** Executou-se `cd /Users/dcambria/scripts/server-tools/v2/ec2-deploy` antes de rodar o script.

**Licao / Prevencao:** O `post-deploy.sh` deveria resolver seu proprio diretorio com `$(dirname "$0")` em vez de depender do CWD. Ou validar o CWD no inicio do script e abortar com mensagem clara.

**Categoria:** Scripts/Automacao

---

### 5. DNS check falha (CloudFront na frente do ALB)

**O que aconteceu:** O post-deploy falhou na verificacao DNS: esperava que `concertacaoamazonia.com.br` resolvesse para o IP da instancia green (`54.207.84.130`), mas resolve para IPs do CloudFront (`108.158.x.x`).

**Causa raiz:** O DNS aponta para o CloudFront, que faz proxy para o ALB, que roteia para as instancias. O script de verificacao DNS nao considera essa arquitetura.

**Como foi resolvido:** Executou-se o post-deploy com `--continue-on-error` para pular a verificacao DNS.

**Licao / Prevencao:** O check DNS deveria suportar arquitetura CloudFront: verificar se o DNS aponta para CloudFront E se o ALB tem a instancia registrada no target group correto. Ou oferecer um `--skip-dns-check` mais explicito.

**Categoria:** AWS/Infra

---

### 6. Arquivos S3 no path dev/ mas script busca hml/

**O que aconteceu:** O import do banco falhou com "arquivo nao encontrado" no S3. Os exports do dev foram para `concertacao/dev/sql/` e `concertacao/dev/wp/`, mas o script de import busca em `concertacao/hml/sql/` (baseado no ENVIRONMENT).

**Causa raiz:** Os exports sao feitos pelo ambiente `dev` e vao para o path `dev/`. Quando o post-deploy roda com `ENVIRONMENT=hml`, busca no path `hml/`. Nao ha copia automatica entre paths.

**Como foi resolvido:** Copiou-se manualmente os arquivos no S3: `aws s3 cp s3://bucket/concertacao/dev/sql/arquivo.sql.gz s3://bucket/concertacao/hml/sql/`.

**Licao / Prevencao:** O script de export deveria aceitar um parametro `--target-env` que copia o arquivo para o path do ambiente de destino. Ou o post-deploy deveria aceitar um `--s3-path` customizado. Automatizar a copia de arquivos entre paths como parte do checklist de deploy.

**Categoria:** Scripts/Automacao

---

### 7. DROP command denied no Aurora (falta GRANT)

**O que aconteceu:** O import do banco falhou com `DROP command denied to user 'concertacao-v2'`. O dump contem `DROP TABLE IF EXISTS` mas o user nao tem privilegio de DROP no banco novo.

**Causa raiz:** O user `concertacao-v2` tem `ALL PRIVILEGES` nos bancos antigos (`wp_concertacao_20250316`, `wp_concertacao_20260402_PROD`) mas nao no recem-criado `wp_concertacao_20260412`. O Aurora exige GRANT explicito por banco; `CREATE ON *.*` nao da ALL no banco criado.

**Como foi resolvido:** Obteve-se a senha do user `admin` do Aurora (armazenada numa variavel de ambiente local `AURORA_ADMIN_PASS_CONCERTACAO`), executou-se `GRANT ALL PRIVILEGES ON wp_concertacao_20260412.* TO 'concertacao-v2'@'%' WITH GRANT OPTION; FLUSH PRIVILEGES;` via SSH na instancia prod.

**Licao / Prevencao:**
1. Armazenar a senha do admin do Aurora no Secrets Manager (nao apenas localmente)
2. Incluir o GRANT automaticamente no script que cria o banco (`CREATE DATABASE` seguido de `GRANT`)
3. O script de criacao de banco deveria validar que o user tem permissoes antes de prosseguir

**Categoria:** AWS/Infra

---

### 8. GRANT via SSH falha (env var local nao disponivel)

**O que aconteceu:** O comando `ssh prod "mysql -p'${AURORA_ADMIN_PASS_CONCERTACAO}'"` resultou em "Access denied for user 'admin' (using password: NO)" — a variavel local nao foi expandida no contexto SSH.

**Causa raiz:** Variaveis de ambiente locais (definidas em `.bash_profile`) nao sao passadas automaticamente para sessoes SSH. A expansao `${VAR}` dentro de aspas duplas no SSH acontece localmente, mas a variavel nao estava carregada no shell nao-interativo do Claude Code.

**Como foi resolvido:** Leu-se a variavel localmente primeiro: `PASS="$(source ~/.bash_profile; echo "$AURORA_ADMIN_PASS_CONCERTACAO")"` e depois passou-se como literal para o SSH: `ssh prod "mysql -p'$PASS' -e ..."`.

**Licao / Prevencao:** Nunca usar `${VAR}` diretamente em comandos SSH. Sempre capturar o valor localmente antes. Considerar um helper `ssh_with_secret()` que faz isso automaticamente.

**Categoria:** Scripts/Automacao

---

### 9. Post-deploy --scripts syntax errada (= vs espaco)

**O que aconteceu:** O comando `./post-deploy.sh --scripts=09` nao executou nada — exibiu o help do script como se a flag fosse invalida.

**Causa raiz:** O `post-deploy.sh` usa `--scripts "9"` (espaco entre flag e valor), nao `--scripts=09` (sinal de igual). Alem disso, o numero do script deve ser sem zero a esquerda.

**Como foi resolvido:** Usou-se a sintaxe correta: `--scripts "9"`.

**Licao / Prevencao:** Documentar a sintaxe exata no `--help` do script. Idealmente, suportar ambas as formas (`--scripts=9` e `--scripts 9`). Usar `getopt` ou `getopts` para parsing robusto de argumentos.

**Categoria:** Scripts/Automacao

---

### 10. siteurl ainda com cambrasmax.local:8484

**O que aconteceu:** Apos o import do banco e autoconfigure, o `siteurl` continuava como `cambrasmax.local:8484` em diversas tabelas (principalmente `wp_blogs`). 40.864 substituicoes pendentes.

**Causa raiz:** O script `a1-wordpress-autoconfigure.sh` faz search-replace em `wp_options`, mas nao cobre todas as tabelas do multisite (especialmente `wp_blogs`, `wp_site`). O dump veio do ambiente dev com URLs do dev.

**Como foi resolvido:** Executou-se `wp search-replace "cambrasmax.local:8484" "concertacaoamazonia.com.br" --all-tables --precise` manualmente.

**Licao / Prevencao:** O script `a1-wordpress-autoconfigure.sh` (ou `09-importdatabase.sh`) deveria rodar `search-replace --all-tables` automaticamente apos o import. Especialmente em multisite, onde `wp_blogs` e `wp_site` contem URLs que precisam ser atualizadas.

**Categoria:** WordPress

---

### 11. Nginx duplicate directives (script 03)

**O que aconteceu:** Nginx recusou iniciar (ou mostrou warnings) devido a diretivas duplicadas: `types_hash_max_size` aparecia na linha 31 (4096, inserido pelo Bureau) e linha 127 (2048, bloco padrao Ubuntu).

**Causa raiz:** O script `03-nginx-sites.sh` insere o bloco Bureau de configuracao no topo do `nginx.conf`, mas nao remove o bloco padrao do Ubuntu que ja contem as mesmas diretivas (types_hash_max_size, gzip, etc.).

**Como foi resolvido:** Removeu-se manualmente o bloco duplicado (linhas 121-159) com `sed -i "121,159d"`.

**Licao / Prevencao:** O script `03-nginx-sites.sh` deveria limpar o bloco padrao do Ubuntu apos inserir o bloco Bureau. Ou usar uma abordagem de template completo em vez de insert/append.

**Categoria:** Scripts/Automacao

---

### 12. Health check 404 na porta 8080

**O que aconteceu:** O ALB health check na porta 8080 retornava 404. A instancia green nao passava no health check.

**Causa raiz:** O health check do ALB espera um arquivo fisico em `/var/www/health/health.txt` (servido pelo primeiro server block do nginx na porta 8080). O arquivo nao existia porque nenhum script o criava automaticamente.

**Como foi resolvido:** Criou-se o diretorio e arquivo manualmente: `sudo mkdir -p /var/www/health && echo "bureau-it.com === OK" | sudo tee /var/www/health/health.txt`.

**Licao / Prevencao:** Incluir a criacao do `health.txt` no script `03-nginx-sites.sh` ou criar um script dedicado para health check setup. O health check path e o arquivo devem ser criados como parte do provisionamento base.

**Categoria:** AWS/Infra

---

### 13. Redis INATIVO (drop-in nao instalado)

**O que aconteceu:** Apos a validacao, Redis aparecia como INATIVO. O plugin `redis-cache` estava ativado, mas `wp redis enable` nao criou o arquivo drop-in (`object-cache.php`).

**Causa raiz:** O `wp redis enable` falhou silenciosamente. O drop-in `object-cache.php` precisa ser copiado do diretorio do plugin para `wp-content/`. Possivelmente permissoes ou estado do plugin impediram a copia automatica.

**Como foi resolvido:** Copiou-se manualmente o drop-in do diretorio do plugin: `cp wp-content/plugins/redis-cache/includes/object-cache.php wp-content/object-cache.php`.

**Licao / Prevencao:** O script `a1-wordpress-autoconfigure.sh` deveria verificar se o drop-in existe apos ativar o plugin, e copiar manualmente se necessario. Adicionar verificacao de Redis como parte do health check automatizado.

**Categoria:** WordPress

---

### 14. FPM config path errado (www.conf vs wordpress.conf)

**O que aconteceu:** Ao tentar fazer o override do FPM para 10 workers, o arquivo `/etc/php/8.3/fpm/pool.d/www.conf` nao existia.

**Causa raiz:** O script `02-php.sh` cria o pool como `wordpress.conf` (nao `www.conf`), que e o padrao customizado do Bureau IT.

**Como foi resolvido:** Editou-se `/etc/php/8.3/fpm/pool.d/wordpress.conf` em vez de `www.conf`.

**Licao / Prevencao:** Documentar no CLAUDE.md que o nome do pool file e `wordpress.conf`. Ou criar um helper que detecta automaticamente o nome do pool file.

**Categoria:** Scripts/Automacao

---

### 15. CloudFront source-ip ALB rules nao funcionam

**O que aconteceu:** As regras ALB 210 e 240 usam `source-ip` condition com IPs NordVPN (`45.11.82.0/24`), mas nao faziam match quando acessado via browser.

**Causa raiz:** O CloudFront esta na frente do ALB. O ALB ve o IP do edge node CloudFront (ex: `108.158.x.x`), nao o IP real do cliente. As regras `source-ip` nunca fazem match com o IP do cliente quando ha CloudFront no caminho.

**Como foi resolvido:** Criou-se uma regra ALB priority 5 com condition `X-Test-Green: true` (header HTTP) em vez de `source-ip`. Configurou-se o CloudFront para forward este header ao origin.

**Licao / Prevencao:** **Nunca usar `source-ip` no ALB quando CloudFront esta na frente.** Usar sempre header-based routing (X-Test-Green) ou custom header do CloudFront. Documentar esta limitacao no .env e no spec de deploy.

**Categoria:** Cache/CDN

---

### 16. CloudFront cacheando blue para requests X-Test-Green

**O que aconteceu:** Mesmo com o header X-Test-Green sendo enviado e forward ao ALB (via origin request policy), o CloudFront continuava servindo a pagina cacheada do blue.

**Causa raiz:** O header `X-Test-Green` foi adicionado a origin request policy (para ser forwarded ao origin), mas nao a cache policy. Sem o header na cache key, o CloudFront trata requests com e sem o header como identicos — mesma cache entry.

**Como foi resolvido:** Adicionou-se `X-Test-Green` a cache policy `WP-Dynamic-ShortTTL-NoQS` em `HeadersConfig`, criando cache keys separadas para requests com e sem o header. Depois invalidou-se o cache com `/*`.

**Licao / Prevencao:** **Ao usar headers para routing no ALB atras do CloudFront, SEMPRE adicionar o header tanto na origin request policy QUANTO na cache policy.** Origin request policy = forward ao origin. Cache policy = diferenciar no cache.

**Categoria:** Cache/CDN

---

### 17. OPcache servindo versao antiga do check-ec2.php

**O que aconteceu:** O arquivo `check-ec2.php` foi atualizado no disco para v1.33, mas o browser continuava vendo v1.30.

**Causa raiz:** OPcache com `file_cache` ativo persiste bytecode compilado no disco (`/var/cache/php-opcache/*.bin`). Mesmo com o arquivo fonte atualizado, o FPM serve o bytecode antigo ate o proximo revalidate (ou indefinidamente se `validate_timestamps` esta desativado em prod).

**Como foi resolvido:** `sudo find /var/cache/php-opcache -name '*.bin' -delete` seguido de `sudo systemctl reload php8.3-fpm`.

**Licao / Prevencao:** Apos qualquer deploy de arquivo PHP em producao/staging: (1) deletar `.bin` do file_cache, (2) reload FPM. Nesta ordem. Nunca confiar apenas em `wp eval 'opcache_reset()'` (roda no SAPI CLI, nao afeta pool FPM). Automatizar no script de deploy de tema.

**Categoria:** Cache/CDN

---

### 18. check-ec2.php desatualizado no green (v1.30 vs v1.33)

**O que aconteceu:** O green mostrava check-ec2.php v1.30, mas prod ja tinha v1.33.

**Causa raiz:** O wp-content exportado do dev continha a versao antiga (v1.30). O check-ec2.php e um submodule git separado e sua versao no dev nao acompanhava as mudancas feitas diretamente em prod no deploy anterior.

**Como foi resolvido:** Copiou-se o arquivo de prod para green via `scp`. Depois atualizou-se o submodule local e commitou-se no repositorio.

**Licao / Prevencao:** Apos cada deploy, sincronizar o submodule check-ec2 no repositorio local. O check-ec2.php deveria ser deployado via post-deploy (script dedicado), nao pelo wp-content export.

**Categoria:** Processo

---

### 19. check-ec2.php cacheado pelo CloudFront

**O que aconteceu:** Mesmo apos atualizar check-ec2.php no green, o CloudFront continuava servindo a versao antiga (cache hit).

**Causa raiz:** O check-ec2.php e um arquivo dinamico (PHP) mas o CloudFront nao diferencia `.php` de outros recursos; cacheia tudo com o mesmo TTL.

**Como foi resolvido:** Invalidacao manual do path `/check-ec2.php` no CloudFront.

**Licao / Prevencao:** Adicionar um behavior especifico no CloudFront para `check-ec2.php` com CachingDisabled. Ou usar um header `Cache-Control: no-cache, no-store` no response do check-ec2.php.

**Categoria:** Cache/CDN

---

### 20. Uploads 404 (export sem --include-uploads)

**O que aconteceu:** Apos o deploy do green, imagens retornavam 404 (`Manoel-Lima-11.jpg`, `A-floresta-e-seus-misterios...webp`, etc.).

**Causa raiz:** O wp-content foi exportado com `--skip-uploads` (default). O green nao tinha nenhum arquivo de upload. NAO existe filesystem compartilhado entre instancias EC2 — cada instancia tem seu proprio filesystem. O plugin S3 Uploads esta desativado em prod.

**Como foi resolvido:** Sincronizou-se os uploads de prod para S3 (`aws s3 sync uploads/ s3://bucket/concertacao/uploads/`) e depois de S3 para green (`aws s3 sync s3://bucket/concertacao/uploads/ /var/www/.../wp-content/uploads/`).

**Licao / Prevencao:**
1. **SEMPRE exportar uploads** com `--include-uploads` no wp-content export
2. Ou incluir no post-deploy um script que sincroniza uploads via S3 automaticamente
3. Documentar no spec de deploy: "Nao ha filesystem compartilhado entre instancias"
4. O script de export deveria avisar quando uploads sao omitidos

**Categoria:** Processo

---

### 21. Permissao negada no S3 sync para green (ubuntu vs www-data)

**O que aconteceu:** O `aws s3 sync` de S3 para green falhou com "Permission denied" ao tentar escrever no diretorio de uploads.

**Causa raiz:** O sync foi executado como user `ubuntu` (padrao SSH), mas o diretorio `/var/www/.../wp-content/uploads/` pertence a `www-data`. O user `ubuntu` nao tem permissao de escrita nesse diretorio.

**Como foi resolvido:** Matou-se o processo, alterou-se temporariamente o owner do diretorio para `ubuntu` (`sudo chown -R ubuntu:ubuntu uploads/`), executou-se o sync, e depois `chown -R www-data:www-data uploads/`.

**Licao / Prevencao:** Executar sync de arquivos sempre com `sudo` ou via `sudo -u www-data`. Ou criar o helper `sync-uploads-to-instance.sh` que ja trata permissoes automaticamente.

**Categoria:** Permissoes

---

### 22. Dois processos S3 sync duplicados em prod

**O que aconteceu:** A contagem de objetos no S3 ultrapassou o esperado (85.292 objetos / 16GB vs 66.510 / 11GB estimados). Dois processos `aws s3 sync` estavam rodando em paralelo no prod.

**Causa raiz:** O primeiro sync foi iniciado via SSH em background no Mac (que pode ter caido), e o segundo via `nohup` diretamente na instancia. Ambos rodaram simultaneamente no mesmo source/destination.

**Como foi resolvido:** O `aws s3 sync` e idempotente, entao o resultado final foi correto. Porem, causou uso de banda desnecessario e confusao no monitoramento de progresso.

**Licao / Prevencao:** Verificar se ja existe um processo de sync rodando antes de iniciar outro: `pgrep -f "s3 sync"`. Usar um lock file (`/tmp/s3-sync.lock`) para evitar execucao duplicada. Sempre usar `nohup` para operacoes longas em vez de depender da sessao SSH.

**Categoria:** Processo

---

### 23. Target group errado no .env (hml-tg vs green-tg)

**O que aconteceu:** A instancia green foi registrada no `concertacao-hml-tg`, mas as regras ALB 210 e 240 apontavam para `concertacao-green-tg`. O `concertacao-green-tg` estava vazio.

**Causa raiz:** O `.env` tinha `TARGET_GROUP_HML="concertacao-hml-tg"` (nome antigo), mas as regras ALB foram criadas apontando para `concertacao-green-tg` (nome novo). A instancia seguiu o `.env` e foi para o TG errado.

**Como foi resolvido:** Registrou-se a instancia no `concertacao-green-tg` e atualizou-se o `.env`: `TARGET_GROUP_HML="concertacao-green-tg"`.

**Licao / Prevencao:** Incluir no pre-flight check a validacao de que o TG referenciado no `.env` e o mesmo TG referenciado pelas regras ALB. Ou usar um unico TG e alternar instancias em vez de alternar regras.

**Categoria:** AWS/Infra

---

### 24. Questionamento excessivo durante execucao

**O que aconteceu:** O usuario criticou: "voce tem que ser direto ao ponto na execucao do deploy. ficar questionando se eh interativo ou nao, e sinal de falta de planejamento."

**Causa raiz:** Falta de mapeamento previo de quais scripts sao interativos e quais flags sao necessarias para modo nao-interativo.

**Como foi resolvido:** Feedback incorporado — execucao passou a ser direta, sem perguntas desnecessarias.

**Licao / Prevencao:** Antes de iniciar o deploy, mapear todos os scripts que serao executados, suas flags, e seus requisitos. Documentar o runbook completo com comandos exatos. Nao perguntar o que pode ser deduzido pelo contexto.

**Categoria:** Processo

---

## Padroes Recorrentes

### 1. Desalinhamento entre .env e estado real da AWS
Problemas #2, #6, #23 foram causados por `.env` desatualizado em relacao ao estado real dos recursos AWS (EIP renomeado, TG renomeado, paths S3 diferentes). **Acao:** Criar script de validacao pre-deploy que verifica cada recurso AWS referenciado no `.env`.

### 2. Scripts que nao cobrem cenario CloudFront
Problemas #5, #15, #16, #19 aconteceram porque scripts/configuracoes assumem acesso direto ALB↔browser, sem considerar CloudFront no caminho. **Acao:** Revisar todos os scripts de validacao e routing para suportar arquitetura CloudFront.

### 3. Cache em multiplas camadas
Problemas #16, #17, #19 envolvem cache em diferentes camadas (CloudFront, OPcache, browser). **Acao:** Criar checklist de invalidacao pos-deploy: (1) OPcache file_cache, (2) FPM reload, (3) WP Rocket, (4) CloudFront invalidacao cirurgica.

### 4. Permissoes e credenciais
Problemas #7, #8, #21 envolvem falta de permissoes ou credenciais indisponiveis. **Acao:** Centralizar credenciais no Secrets Manager. Incluir GRANT automatico ao criar banco. Sempre usar `sudo` para operacoes em diretorios de sistema.

### 5. Uploads como dependencia critica
Problema #20 causou a maior perda de tempo. **Acao:** Tornar `--include-uploads` o default nos exports destinados a deploy. Sem filesystem compartilhado, uploads sao dependencia critica.

---

## Comparacao com Deploy #1 (2026-04-08)

| Aspecto | Deploy #1 | Deploy #2 | Melhoria |
|---------|-----------|-----------|----------|
| GRANT Aurora | N/A (mesmo banco) | Bloqueador de ~60 min | Automatizar GRANT |
| SSH host key | Ocorreu | Ocorreu novamente | Automatizar no create-ec2.sh |
| S3 path mismatch | Provavelmente nao | Ocorreu (~15 min) | Automatizar copia entre paths |
| CloudFront routing | N/A (primeiro deploy) | ~40 min (source-ip + cache key) | X-Test-Green permanente |
| Uploads 404 | Desconhecido | ~60 min | Incluir uploads no export |
| Nginx duplicate | Provavelmente nao | ~15 min | Limpar bloco padrao no script |

---

## Recomendacoes Prioritarias para Deploy #3

1. **Script de pre-flight check**: Validar .env vs AWS (EIPs, TGs, Security Groups, Secrets Manager)
2. **GRANT automatico**: Incluir no script que cria o banco Aurora
3. **Export com uploads**: Tornar `--include-uploads` padrao ou criar sync automatico via S3
4. **X-Test-Green padrao**: Manter header na cache/origin policy do CloudFront permanentemente
5. **Invalidacao automatica**: Script pos-deploy que limpa OPcache file_cache + reload FPM + WP Rocket cirurgico
6. **Runbook com comandos exatos**: Documentar cada comando, flag, e path antes de comecar
