// =============================================================================
// uploads-oac-router.js
// -----------------------------------------------------------------------------
// CloudFront Function (viewer-request) para reescrever URIs do path
// /wp-content/uploads/* (e variantes multisite) para o caminho canonico
// que o S3 OAC espera, em conjunto com OriginPath do behavior.
//
// Contexto (Ciclo 2 — migracao CloudFront-OAC, projeto Concertacao Amazonia):
//   - Origins:
//       * S3-uploads-prod  -> OriginPath "/assets/uploads"
//       * S3-uploads-green -> OriginPath "/green/uploads"
//   - O behavior aponta para 1 origin (escolha externa, fora desta function).
//   - Esta function APENAS reescreve a URI; nao escolhe origin.
//
// Regras:
//   1) Strip de prefixo multisite + /wp-content/uploads/:
//        /wp-content/uploads/2026/04/foo.jpg
//          -> /2026/04/foo.jpg
//        /cultura/wp-content/uploads/sites/2/2026/04/bar.png
//          -> /sites/2/2026/04/bar.png
//   2) URIs que NAO contem /wp-content/uploads/ passam sem alteracao
//      (early return).
//   3) Path traversal / caracteres suspeitos: passthrough sem modificacao
//      (deixa o S3 OAC retornar 403).
//   4) WebP rewrite: desligado por padrao (WEBP_REWRITE = false).
//      Trade-off documentado abaixo.
//
// Runtime: cloudfront-js-2.0 (ES5.1+ subset). Sem let/const/template-strings
// para garantir compatibilidade ampla.
// =============================================================================

// -----------------------------------------------------------------------------
// FLAGS
// -----------------------------------------------------------------------------
//
// WEBP_REWRITE:
//   - false (default): nao tenta servir .webp automatico. Browser pediu .jpg,
//     S3 retorna .jpg. Trade-off: perdemos paridade com nginx que serve
//     .jpg.webp transparentemente quando Accept inclui image/webp.
//   - true: se Accept contem "image/webp" e a URI termina em .jpg/.jpeg/.png,
//     reescreve para "<URI>.webp" (sufixo, nao substituicao).
//     ATENCAO: CloudFront Functions NAO suportam fallback (try/catch HTTP).
//     Se o .webp nao existir no S3, o usuario recebe 403/404 (sem fallback
//     para o original). Por isso so deve ser ativado quando se confirmar
//     que TODOS os arquivos .jpg/.jpeg/.png tem .jpg.webp paralelo no S3.
//
var WEBP_REWRITE = false;

// OAC_HOSTS:
//   Whitelist de Hosts que sao roteados para os origins S3-uploads-prod /
//   S3-uploads-green via OAC. Hosts fora dessa lista fazem passthrough
//   (origin continua sendo o que o behavior aponta, mas a URI nao e reescrita —
//   o que faz o S3 retornar 403 mesmo). A protecao real e ter behaviors com
//   path-patterns que casem APENAS para esses hosts, OU manter este check
//   em conjunto com path-pattern para descartar hosts diferentes (5anos,
//   www apex separados).
//
// Bug observado em 2026-04-30: behavior */wp-content/uploads/* casou paths
// como /rota26-30/wp-content/uploads/sites/3/... do site 5anos
// (Host: www.concertacaoamazonia.com.br) que nao tem uploads no S3 prd-sa,
// gerando 403 publicamente. Fix: passthrough quando Host nao e o apex.
var OAC_HOSTS = {
    'concertacaoamazonia.com.br': true
};

// -----------------------------------------------------------------------------
// HELPERS
// -----------------------------------------------------------------------------

// Detecta path traversal / caracteres suspeitos. Em CF Functions o request.uri
// ja vem normalizado, mas ainda checamos defensivamente.
function isSuspicious(uri) {
    if (!uri) return true;
    if (uri.indexOf('..') !== -1) return true;
    if (uri.indexOf('\\') !== -1) return true;
    if (uri.indexOf('//') !== -1) return true; // dupla barra suspeita apos normalizacao
    return false;
}

// Verifica se o cliente aceita image/webp.
function acceptsWebp(headers) {
    if (!headers || !headers.accept || !headers.accept.value) return false;
    return headers.accept.value.toLowerCase().indexOf('image/webp') !== -1;
}

// Retorna true se a URI termina em .jpg, .jpeg ou .png (case-insensitive).
function isWebpCandidate(uri) {
    var lower = uri.toLowerCase();
    if (lower.length < 5) return false;
    if (lower.lastIndexOf('.jpg')  === lower.length - 4) return true;
    if (lower.lastIndexOf('.jpeg') === lower.length - 5) return true;
    if (lower.lastIndexOf('.png')  === lower.length - 4) return true;
    return false;
}

// -----------------------------------------------------------------------------
// HANDLER
// -----------------------------------------------------------------------------
function handler(event) {
    var request = event.request;
    var uri = request.uri;

    // Defensive checks
    if (!uri || uri.charAt(0) !== '/') {
        return request;
    }
    if (isSuspicious(uri)) {
        return request;
    }

    // Host whitelist: so reescreve para sites cujos uploads vivem no S3 prd-sa.
    // Outros hosts (www.* = 5anos, etc) tem uploads em filesystem proprio.
    var host = (request.headers && request.headers.host && request.headers.host.value) || '';
    host = host.toLowerCase();
    if (!OAC_HOSTS[host]) {
        return request;
    }

    // -------------------------------------------------------------------------
    // 1) Detectar /wp-content/uploads/ no path.
    //    Pode estar:
    //      (a) na raiz: /wp-content/uploads/...
    //      (b) atras de prefixo multisite: /<blog>/wp-content/uploads/...
    //    Tudo o que NAO matchar -> passthrough.
    // -------------------------------------------------------------------------
    var marker = '/wp-content/uploads/';
    var idx = uri.indexOf(marker);

    if (idx === -1) {
        // URI nao mira uploads -> nao mexer.
        return request;
    }

    // Garante que o que vem antes do marker (se existir) eh um unico segmento
    // simples [a-z0-9_-]+ (slug de blog WPMS) ou string vazia.
    var prefix = uri.substring(0, idx); // ex: "" ou "/cultura"
    if (prefix.length > 0) {
        // prefix deve ser exatamente "/<slug>"
        // slug: caracteres a-z, A-Z, 0-9, _, -
        if (prefix.charAt(0) !== '/') return request;
        var slug = prefix.substring(1);
        if (slug.length === 0) return request;
        if (slug.indexOf('/') !== -1) return request; // multi-segmento -> passthrough
        // valida caracteres permitidos
        var ok = true;
        for (var i = 0; i < slug.length; i++) {
            var c = slug.charAt(i);
            var isAlpha = (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z');
            var isDigit = (c >= '0' && c <= '9');
            var isAllowed = isAlpha || isDigit || c === '_' || c === '-';
            if (!isAllowed) { ok = false; break; }
        }
        if (!ok) return request;
    }

    // -------------------------------------------------------------------------
    // 2) Construir nova URI: tudo apos /wp-content/uploads/, com / na frente.
    // -------------------------------------------------------------------------
    var rest = uri.substring(idx + marker.length); // ex: "2026/04/foo.jpg" ou "sites/2/2026/04/bar.png"
    if (rest.length === 0) {
        // URI exatamente "/wp-content/uploads/" sem nada -> passthrough (S3 listing nao deve ocorrer com OAC).
        return request;
    }
    var newUri = '/' + rest;

    // -------------------------------------------------------------------------
    // 3) WebP rewrite opcional.
    // -------------------------------------------------------------------------
    if (WEBP_REWRITE && isWebpCandidate(newUri) && acceptsWebp(request.headers)) {
        newUri = newUri + '.webp';
    }

    request.uri = newUri;
    return request;
}
