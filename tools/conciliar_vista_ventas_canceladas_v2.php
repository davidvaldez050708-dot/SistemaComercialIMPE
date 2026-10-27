<?php
/**
 * Conciliador estrictamente localizado de la vista de Mis llamadas.
 * No requiere BD ni altera el archivo de origen: lee ruta 1 y genera ruta 2.
 * Si la variante personalizada no coincide, ABORTA antes de publicar.
 *
 * php conciliar_vista_ventas_canceladas_v2.php <vista_origen> <vista_candidata>
 */
declare(strict_types=1);

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
if ($text === false || $text === '' || strpos($text, 'data-sales-detail') === false) {
    fwrite(STDERR, "CONFLICTO: no se identifica el botón del resultado comercial. SITIO INTACTO.\n");
    exit(1);
}
$needle = 'TelefoniaVentasLlamadasService::resultadoCapturable($call)';
$count = substr_count($text, $needle);
if ($count > 1) {
    fwrite(STDERR, "CONFLICTO: la condición protegida se repite. SITIO INTACTO.\n");
    exit(1);
}
if ($count === 1) {
    // Idempotencia: conservar íntegra una vista ya ajustada.
    $new = $text;
    $message = 'VISTA YA PROTEGIDA: no se cambia su diseño.';
} else {
    // Sólo dos formas inequívocas de la condición original (orden permutable).
    // Delimitar el condicional PHP, sin etiquetas de cierre en comentarios.
    $ref = '\\$ref\\s*!==\\s*(?:' . "''" . '|"")';
    $end = '\\$call\\s*\\[\\s*(?:' . "'finalizada'" . '|"finalizada")\\s*\\]';
    $expr = '(?:' . $ref . '\\s*&&\\s*' . $end
        . '|' . $end . '\\s*&&\\s*' . $ref . ')';
    $pattern = '~(?P<open><\\?php\\s+if\\s*\\(\\s*)' . $expr
        . '(?P<close>\\s*\\)\\s*:\\s*\\?>)~u';
    $count = preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
    if ($count === 1) {
        $offset = $matches[0][0][1];
        // Verificar que el if realmente protege el botón comercial.
        if (strpos(substr($text, $offset, 850), 'data-sales-detail') === false) {
            fwrite(STDERR, "CONFLICTO: la condición encontrada no corresponde al botón esperado.\n");
            exit(1);
        }
        $replacement = '$ref!==\'\'&&(!empty($call[\'registro\'])||'
            . 'TelefoniaVentasLlamadasService::resultadoCapturable($call))';
        $new = preg_replace_callback($pattern, static function ($m) use ($replacement) {
            return $m['open'] . $replacement . $m['close'];
        }, $text, 1, $replacements);
        if (!is_string($new) || $replacements !== 1) {
            fwrite(STDERR, "CONFLICTO: no pudo efectuarse el cambio único.\n");
            exit(1);
        }
        $message = 'VISTA CONCILIADA: una condición sustituida; diseño preservado.';
    } else {
        // Vista personalizada con condición distinta. No intentar fusionar
        // la plantilla: proteger únicamente el botón comercial existente.
        // La expresión prohíbe consumir otro <button> para evitar envolturas
        // accidentales sobre botones ajenos.
        $buttonPattern = '~<button\b(?:(?!<button\b|</button>).)*'
            . '\bdata-sales-detail\b(?:(?!<button\b|</button>).)*</button>~su';
        $buttons = preg_match_all($buttonPattern, $text, $found, PREG_OFFSET_CAPTURE);
        if ($buttons !== 1 || strpos($text, '$call') === false) {
            fwrite(STDERR, "CONFLICTO: no hay un único botón comercial identificable.\n");
            fwrite(STDERR, "SITIO INTACTO. No se adaptará una estructura ambigua.\n");
            exit(1);
        }
        $button = $found[0][0][0];
        $buttonOffset = $found[0][0][1];
        // El guard aplica también si la vista utiliza otra variable local
        // para la etiqueta; los datos se obtienen del arreglo $call.
        $openGuard = '<?php /* IMPE_VENTAS_GUARD_RESULTADO_V2 */ if ('
            . '!empty($call[\'registro\']) || '
            . 'TelefoniaVentasLlamadasService::resultadoCapturable($call)'
            . '): ?>';
        $new = substr_replace($text, $openGuard . $button . '<?php endif; ?>',
            $buttonOffset, strlen($button));
        $message = 'VISTA PERSONALIZADA: solo se protegió el botón comercial.';
    }
}
if (file_put_contents($output, $new) !== strlen($new)) {
    fwrite(STDERR, "ERROR: no se pudo escribir la copia temporal. SITIO INTACTO.\n");
    exit(1);
}
echo $message . "\n";
