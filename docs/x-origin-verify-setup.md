# X-Origin-Verify Setup

## Proteção estrutural anti CDN fronting attack

Proteção em duas camadas: CloudFront injeta um header secreto em todas as
requests ao origin; o nginx rejeita qualquer request sem esse header.

Distribuição CloudFront: `E2F1QD7E7YOYEB`
EC2 (origin): `i-0c8178fe7ee985cc9`
Autor: Daniel Cambría — Bureau de Tecnologia Ltda.

---

## 1. No CloudFront (console AWS)

1. Acesse: **CloudFront → Distributions → E2F1QD7E7YOYEB**
2. Aba **Origins** → selecione o origin do EC2 → **Edit**
3. Em **Add custom header**, adicionar:
   - Header name: `X-Origin-Verify`
   - Value: *(ver arquivo `.x-origin-verify.secret` na raiz do projeto)*
4. Salvar e aguardar o deploy da distribuição (Status: Deployed)

---

## 2. No nginx (adicionar ao vhost principal)

Inserir **após** os blocos de `allow`/`deny` existentes e **antes** do
primeiro bloco `location /`:

```nginx
# X-Origin-Verify: rejeitar requests que não venham pelo CloudFront
# Substituir <SECRET_UUID> pelo valor de .x-origin-verify.secret
if ($http_x_origin_verify != "<SECRET_UUID>") {
    return 403;
}
```

No servidor EC2, o arquivo de configuração fica em:
```
/etc/nginx/conf.d/concertacaoamazonia.com.br.conf
```

Após editar, testar e recarregar:
```bash
nginx -t && systemctl reload nginx
```

---

## 3. Exceções necessárias (health checks do ALB)

O ALB faz health check diretamente no EC2, sem passar pelo CloudFront.
Portanto, os endpoints de health check devem ficar **antes** do bloco
`if ($http_x_origin_verify ...)`:

```nginx
# Health check do ALB — permitir sem X-Origin-Verify
location = /healthz {
    access_log off;
    return 200 "ok\n";
    add_header Content-Type text/plain;
}

# A partir daqui, exigir o header
if ($http_x_origin_verify != "<SECRET_UUID>") {
    return 403;
}
```

---

## 4. Validação após deploy

```bash
# Request sem header — deve retornar 403
curl -I https://concertacaoamazonia.com.br/ -H "Host: concertacaoamazonia.com.br" \
  --resolve "concertacaoamazonia.com.br:443:<IP_EC2>"

# Request com header correto — deve retornar 200
curl -I https://concertacaoamazonia.com.br/ \
  -H "X-Origin-Verify: $(cat .x-origin-verify.secret)" \
  --resolve "concertacaoamazonia.com.br:443:<IP_EC2>"

# Via CloudFront — deve retornar 200 normalmente
curl -I https://concertacaoamazonia.com.br/
```

---

## 5. Rotação do secret

Se o secret for comprometido:
1. Gerar novo UUID: `python3 -c "import uuid; print(str(uuid.uuid4()))"`
2. Atualizar no CloudFront (console) → aguardar deploy
3. Atualizar no nginx → recarregar
4. Atualizar o arquivo `.x-origin-verify.secret`

A ordem garante zero downtime: CloudFront passa a enviar o novo valor
antes de o nginx começar a exigi-lo (deploy CloudFront leva ~5 min).
