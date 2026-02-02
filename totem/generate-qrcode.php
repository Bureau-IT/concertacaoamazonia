<?php
// Script para gerar QR code usando a biblioteca local

$url = "https://docs.google.com/forms/d/e/1FAIpQLSecu3wxq-omoPDi0Cj6mVrOqrHiZpM1cF2mYa0wYeaDEbo0wg/viewform";
$output_file = "/tmp/qrcode-formulario.svg";

// Carregar biblioteca
require_once '/var/www/html/wp-content/plugins/jet-engine-local-qrcode/vendor/autoload.php';

try {
    $options = new \chillerlan\QRCode\QROptions([
        'version'           => 5,
        'outputType'        => \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG,
        'outputBase64'      => false,
        'eccLevel'          => \chillerlan\QRCode\QRCode::ECC_L,
        'scale'             => 10,
        'svgViewBoxSize'    => 300,
        'addQuietzone'      => true,
        'quietzoneSize'     => 2,
    ]);
    
    $qrcode = new \chillerlan\QRCode\QRCode($options);
    $svg = $qrcode->render($url);
    
    // Salvar arquivo
    file_put_contents($output_file, $svg);
    
    echo "✓ QR Code gerado com sucesso!\n";
    echo "URL: $url\n";
    echo "Arquivo: $output_file\n";
    echo "Tamanho: " . strlen($svg) . " bytes\n";
    
} catch (Exception $e) {
    echo "✗ Erro: " . $e->getMessage() . "\n";
    exit(1);
}
