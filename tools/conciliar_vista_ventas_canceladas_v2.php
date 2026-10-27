<?php
declare(strict_types=1);
/*
 * Conciliación localizada de Mis llamadas: solo agrega una condición
 * alrededor del botón comercial existente. Nunca modifica la vista origen.
 */
if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Uso: php conciliar_vista_ventas_canceladas_v2.php origen.php candidato.php\n");
    exit(2);
}
$input = $argv[1];
$output = $argv[2];
if (!is_file($input) || is_link($input) || $input === $output) {
    fwrite(STDERR, "ERROR: vista de origen no válida. SITIO INTACTO.\n");
    exit(1);
}
$text = file_get_contents($input);
if (!is_string($text) || $text === '' || strpos($text, 'data-sales-detail') === false) {
    fwrite(STDERR, "CONFLICTO: no se encontró el botón comercial. SITIO INTACTO.\n");
    exit(1);
}
$marker = 'IMPE_VENTAS_GUARD_RESULTADO_V3';
if (strpos($text, $marker) !== false) {
    if (substr_count($text, $marker) !== 1) {
        fwrite(STDERR, "CONFLICTO: el marcador del ajuste aparece repetido. SITIO INTACTO.\n");
        exit(1);
    }
    $new = $text;
    $message = 'VISTA YA ADAPTADA: se conservan todos los estilos.';
} else {
    // Reconocer solo un elemento button completo con su atributo comercial.
    // No depende del número de referencias a la función en toda la vista.
    $buttonPattern = '~<button\b(?:(?!<button\b|</button).)*\bdata-sales-detail\b'
        . '(?:(?!<button\b|</button).)*</button\s*>~si';
    $foundCount = preg_match_all($buttonPattern, $text, $found, PREG_OFFSET_CAPTURE);
    if ($foundCount !== 1 || strpos($text, '$call') === false) {
        fwrite(STDERR, "CONFLICTO: no se reconoce un único botón comercial. SITIO INTACTO.\n");
        exit(1);
    }
    $button = $found[0][0][0];
    $offset = $found[0][0][1];
    // Si el botón es condicional, la regla adicional sigue siendo segura.
    // Una llamada ya registrada mantiene Consultar resultado.
    $guardOpen = '<?php /* ' . $marker . ' */ if ('
        . '!empty($call[\'registro\']) || '
        . 'TelefoniaVentasLlamadasService::resultadoCapturable($call)'
        . '): ?>';
    $new = substr_replace($text, $guardOpen . $button . '<?php endif; ?>',
        $offset, strlen($button));
    $message = 'VISTA CONCILIADA: solo el botón comercial protegido; estilos intactos.';
}
if (file_put_contents($output, $new) !== strlen($new)) {
    fwrite(STDERR, "ERROR: no se pudo escribir la vista de prueba. SITIO INTACTO.\n");
    exit(1);
}
echo $message . "\n";
