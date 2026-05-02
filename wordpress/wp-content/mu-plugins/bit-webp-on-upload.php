<?php
/**
 * Plugin Name: BIT WebP/AVIF On Upload
 * Description: Gera derivados WebP/AVIF assincronos apos upload de imagem.
 *              Usa proc_open com array de args (sem shell, sem injection risk).
 *              SOMENTE EM DEV — em prod, geracao acontece via d5-generate-webp.sh
 *              no post-deploy. Guard impede execucao fora de development.
 * Version: 1.0.0
 * Author: Daniel Cambria — Bureau de Tecnologia
 */

if (!defined('ABSPATH')) {
    exit;
}

// ===== DEV-ONLY GUARD =====
// Este mu-plugin NAO deve rodar em prod. Em prod, a geracao de WebP/AVIF
// acontece via post-deploy (d5-generate-webp.sh) — uploads diretos via admin
// em prod sao raros e cobertos pelo proximo deploy.
//
// Detecta dev via:
//   1. wp_get_environment_type() === 'development' (WP 5.5+ nativo)
//   2. Fallback: constante WP_ENV === 'dev' (Dockerfile dev seta isso)
if (function_exists('wp_get_environment_type')) {
    if (wp_get_environment_type() !== 'development') {
        return;
    }
} elseif (!defined('WP_ENV') || WP_ENV !== 'dev') {
    return;
}

const BIT_WEBP_HOOK = 'bit_webp_generate_for_attachment';

add_filter('wp_generate_attachment_metadata', function ($metadata, $attachment_id) {
    if (!wp_attachment_is_image($attachment_id)) {
        return $metadata;
    }
    wp_schedule_single_event(time() + 5, BIT_WEBP_HOOK, [(int) $attachment_id]);
    return $metadata;
}, 99, 2);

add_action(BIT_WEBP_HOOK, 'bit_webp_run_for_attachment', 10, 1);

function bit_webp_run_for_attachment($attachment_id) {
    $file = get_attached_file($attachment_id);
    if (!$file || !file_exists($file)) {
        return;
    }
    $mime = mime_content_type($file);
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        return;
    }

    $files = [$file];
    $meta = wp_get_attachment_metadata($attachment_id);
    $dir = dirname($file);
    if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
        foreach ($meta['sizes'] as $size) {
            if (!empty($size['file'])) {
                $files[] = $dir . '/' . $size['file'];
            }
        }
    }

    foreach ($files as $f) {
        if (file_exists($f)) {
            bit_webp_convert_one($f);
        }
    }
}

function bit_webp_convert_one($raster) {
    $mime = mime_content_type($raster);
    $is_png = ($mime === 'image/png');

    // CMYK fallback: cwebp/avifenc nao suportam JPEG CMYK. Pre-converte para
    // sRGB tmp via ImageMagick (preferindo IM7 magick, fallback IM6 convert).
    $input = $raster;
    $tmp_srgb = null;
    if ($mime === 'image/jpeg') {
        $colorspace = bit_webp_identify_colorspace($raster);
        if ($colorspace === 'CMYK') {
            $tmp_srgb = tempnam(sys_get_temp_dir(), 'webp_cmyk_') . '.jpg';
            $im_bin = bit_webp_locate_imagemagick();
            if ($im_bin && bit_webp_proc_run([$im_bin, $raster, '-colorspace', 'sRGB', '-strip', $tmp_srgb])) {
                $input = $tmp_srgb;
            } else {
                @unlink($tmp_srgb);
                $tmp_srgb = null;
            }
        }
    }

    // WebP
    if (!file_exists($raster . '.webp')) {
        $args = $is_png
            ? ['cwebp', '-lossless', '-metadata', 'none', '-quiet', $input, '-o', $raster . '.webp']
            : ['cwebp', '-q', '80', '-metadata', 'none', '-quiet', $input, '-o', $raster . '.webp'];
        bit_webp_proc_run($args);
    }

    // AVIF
    if (!file_exists($raster . '.avif')) {
        $args = ['avifenc', '--min', '30', '--max', '35', '--speed', '6', '--jobs', '1',
                 $input, $raster . '.avif'];
        bit_webp_proc_run($args);
    }

    // Cleanup tmp CMYK
    if ($tmp_srgb && file_exists($tmp_srgb)) {
        @unlink($tmp_srgb);
    }

    // Pos-validacao: descarta se derivado >= raster (browser cai no raster).
    foreach (['webp', 'avif'] as $fmt) {
        $out = $raster . '.' . $fmt;
        if (file_exists($out) && filesize($out) >= filesize($raster)) {
            @unlink($out);
            error_log("[bit-webp] descartado $out (>= raster)");
        }
    }
}

function bit_webp_identify_colorspace($file) {
    return bit_webp_proc_run_capture(['identify', '-format', '%[colorspace]', $file]);
}

/**
 * Localiza binario ImageMagick (magick para IM7, convert para IM6).
 * Procura no PATH manualmente (sem shell). Retorna caminho absoluto ou null.
 */
function bit_webp_locate_imagemagick() {
    $path_env = getenv('PATH') ?: '/usr/bin:/usr/local/bin:/bin';
    $dirs = explode(PATH_SEPARATOR, $path_env);
    foreach (['magick', 'convert'] as $bin) {
        foreach ($dirs as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $bin;
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
    }
    return null;
}

/**
 * proc_open com array de args (PHP 7.4+) — bypassa shell, zero injection.
 * Retorna true se exit code 0, false caso contrario. Loga stderr em falha.
 */
function bit_webp_proc_run(array $args) {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($args, $descriptors, $pipes);
    if (!is_resource($proc)) {
        error_log('[bit-webp] proc_open falhou: ' . implode(' ', $args));
        return false;
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        error_log('[bit-webp] exit ' . $code . ' args=' . implode(' ', $args) . ' stderr=' . trim($err));
        return false;
    }
    return true;
}

/**
 * proc_open com captura de stdout. Retorna stdout trimmed ou string vazia.
 */
function bit_webp_proc_run_capture(array $args) {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($args, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return '';
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return trim((string) $stdout);
}
