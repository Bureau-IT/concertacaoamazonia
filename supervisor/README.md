# Supervisor - Gerenciamento de Processos Docker

Sistema de controle de processos para o ambiente de desenvolvimento WordPress em Docker.

---

## 📖 O que é Supervisor?

**Supervisor** é um sistema de controle de processos que permite:
- ✅ Iniciar múltiplos processos automaticamente
- ✅ Manter processos rodando (reinicia automaticamente se caírem)
- ✅ Gerenciar logs separados para cada processo
- ✅ Controlar prioridade e ordem de inicialização

---

## 🎯 Para que serve no projeto?

Em containers Docker, **só pode haver 1 processo principal** (PID 1). O Supervisor resolve isso executando múltiplos serviços simultaneamente:

```
Container Docker
└── supervisord (PID 1)
    ├── PHP-FPM (processa requisições PHP)
    └── Cron (tarefas agendadas)
```

### Sem Supervisor ❌
```bash
# Só poderia rodar UM processo:
CMD ["php-fpm8.3"]
# OU
CMD ["cron"]
```

### Com Supervisor ✅
```bash
# Um processo gerencia todos os outros:
CMD ["supervisord"]
  → Inicia PHP-FPM
  → Inicia Cron
  → Monitora ambos
  → Reinicia se necessário
```

---

## ⚙️ Processos Gerenciados

### 1. PHP-FPM (priority=5)

**O que faz:** Processa requisições PHP do WordPress

```ini
[program:php-fpm]
command=/usr/sbin/php-fpm8.3 --nodaemonize
autostart=true
autorestart=true
```

**Logs:**
- `/var/log/supervisor/php-fpm.log` - Output normal
- `/var/log/supervisor/php-fpm.error.log` - Erros
- Rotação: 10MB, 3 backups

### 2. Cron (priority=10)

**O que faz:** Executa tarefas agendadas do WordPress

```ini
[program:cron]
command=/usr/sbin/cron -f
autostart=true
autorestart=true
```

**Necessário para:**
- wp-cron (publicações agendadas, atualizações)
- Backups automáticos
- Limpeza de cache
- Tarefas personalizadas

**Logs:**
- `/var/log/supervisor/cron.log` - Output normal
- `/var/log/supervisor/cron.error.log` - Erros
- Rotação: 5MB, 2 backups

---

## 📁 Estrutura de Arquivos

```
docker-dev/
├── supervisor/
│   ├── README.md              # Este arquivo
│   └── supervisord.conf       # Configuração principal
│
├── logs/supervisor/           # Logs dos processos
│   ├── supervisord.log        # Log do supervisor
│   ├── php-fpm.log
│   ├── php-fpm.error.log
│   ├── cron.log
│   └── cron.error.log
│
├── Dockerfile                 # Instala supervisor
└── docker-compose.yml         # Monta volumes
```

---

## 🔧 Comandos Úteis

### Verificar Status dos Processos

```bash
# Dentro do container
docker exec -it wp-dev supervisorctl status

# Esperado:
# cron                    RUNNING   pid 123, uptime 0:05:00
# php-fpm                 RUNNING   pid 124, uptime 0:05:00
```

### Reiniciar Processo Específico

```bash
# PHP-FPM
docker exec -it wp-dev supervisorctl restart php-fpm

# Cron
docker exec -it wp-dev supervisorctl restart cron

# Todos
docker exec -it wp-dev supervisorctl restart all
```

### Ver Logs em Tempo Real

```bash
# PHP-FPM
docker exec -it wp-dev supervisorctl tail -f php-fpm

# Cron
docker exec -it wp-dev supervisorctl tail -f cron

# Supervisor
docker exec -it wp-dev supervisorctl tail -f supervisord
```

### Parar/Iniciar Processos

```bash
# Parar
docker exec -it wp-dev supervisorctl stop php-fpm

# Iniciar
docker exec -it wp-dev supervisorctl start php-fpm

# Recarregar configuração
docker exec -it wp-dev supervisorctl reread
docker exec -it wp-dev supervisorctl update
```

---

## 🐛 Troubleshooting

### Processo não inicia

```bash
# Verificar logs
docker exec -it wp-dev cat /var/log/supervisor/supervisord.log

# Verificar configuração
docker exec -it wp-dev cat /etc/supervisor/conf.d/supervisord.conf
```

### PHP-FPM não responde

```bash
# Ver status
docker exec -it wp-dev supervisorctl status php-fpm

# Ver logs de erro
docker exec -it wp-dev tail -f /var/log/supervisor/php-fpm.error.log

# Reiniciar
docker exec -it wp-dev supervisorctl restart php-fpm
```

### Cron não executa tarefas

```bash
# Verificar se está rodando
docker exec -it wp-dev supervisorctl status cron

# Ver logs
docker exec -it wp-dev tail -f /var/log/supervisor/cron.log

# Testar crontab
docker exec -it wp-dev crontab -l
```

---

## 📝 Configuração

### Adicionar Novo Processo

Edite `supervisord.conf`:

```ini
[program:meu-processo]
command=/caminho/para/comando
autostart=true
autorestart=true
priority=15
stdout_logfile=/var/log/supervisor/meu-processo.log
stderr_logfile=/var/log/supervisor/meu-processo.error.log
user=www-data
```

### Prioridades

Ordem de inicialização (menor = inicia primeiro):
- `5` - PHP-FPM (mais importante)
- `10` - Cron
- `15+` - Processos adicionais

---

## 🔗 Referências

- [Documentação Oficial do Supervisor](http://supervisord.org/)
- [Supervisor no Docker - Best Practices](https://docs.docker.com/config/containers/multi-service_container/)

---

## 👨‍💻 Autor

**Daniel Cambría + Warp**
