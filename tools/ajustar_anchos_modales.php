<?php
/**
 * Parche selectivo del CSS de Hostinger para restaurar anchos de modales.
 * Uso: php tools/ajustar_anchos_modales.php CSS_ORIGEN CSS_PREPARADO
 * No sobrescribe el archivo de origen y aborta ante modificaciones desconocidas.
 */
if (PHP_SAPI !== 'cli' || ($argc ?? 0) !== 3) {
    fwrite(STDERR, "Uso: php tools/ajustar_anchos_modales.php CSS_ORIGEN CSS_PREPARADO\n");
    exit(2);
}

$origen = (string)$argv[1];
$salida = (string)$argv[2];
if (!is_file($origen) || is_link($origen) || $origen === $salida) {
    fwrite(STDERR, "ERROR: archivo origen no válido.\n");
    exit(2);
}
$css = file_get_contents($origen);
if ($css === false) {
    fwrite(STDERR, "ERROR: no se puede leer el CSS.\n");
    exit(2);
}

$reglas = [
    [
        <<<'OLD'
.admin-content .dropdown-menu,
.admin-content .offcanvas,
.admin-content .modal-dialog {
    max-width: 100vw;
}
OLD,
        <<<'NEW'
/* No limitar los diálogos aquí: cada módulo conserva su tamaño previsto. */
.admin-content .dropdown-menu,
.admin-content .offcanvas {
    max-width: 100vw;
}
NEW,
    ],
    [
        <<<'OLD'
    .admin-content .modal-dialog:not(.modal-fullscreen-sm-down) {
        margin-right: 12px;
        margin-left: 12px;
        max-width: calc(100vw - 24px);
    }
OLD,
        <<<'NEW'
    /* Limitar el ancho disponible sin reemplazar el máximo de cada diálogo. */
    .admin-content .modal-dialog:not(.modal-fullscreen-sm-down) {
        width: calc(100% - 24px);
        margin-right: auto;
        margin-left: auto;
    }
NEW,
    ],
];
foreach ($reglas as $indice => [$anterior, $nuevo]) {
    if (substr_count($css, $nuevo) === 1 && !str_contains($css, $anterior)) {
        continue;
    }
    if (substr_count($css, $anterior) !== 1 || str_contains($css, $nuevo)) {
        fwrite(STDERR, "CONFLICTO: bloque de modal " . ($indice + 1) . " no reconocido. Sin cambios.\n");
        exit(1);
    }
    $css = str_replace($anterior, $nuevo, $css);
}

$anteriorDuplicado = <<<'OLD'
    /* Evita modales más anchos que el visor (sin ocultar su contenido). */
    .admin-content .modal-dialog:not(.modal-fullscreen-sm-down) {
        width: auto;
        max-width: calc(100vw - 24px);
    }

OLD;
if (substr_count($css, $anteriorDuplicado) > 1) {
    fwrite(STDERR, "CONFLICTO: ajustes móviles duplicados. Sin cambios.\n");
    exit(1);
}
$css = str_replace($anteriorDuplicado, '', $css);

if (str_contains($css, '<<<<<<<') || str_contains($css, '>>>>>>>') ||
    !str_contains($css, '.admin-content .modal-dialog:not(.modal-fullscreen-sm-down) {') ||
    str_contains($css, '.admin-content .offcanvas,' . "\n" . '.admin-content .modal-dialog {')) {
    fwrite(STDERR, "ERROR: validación de CSS fallida. Sin cambios.\n");
    exit(1);
}
if (file_put_contents($salida, $css, LOCK_EX) === false) {
    fwrite(STDERR, "ERROR: no se pudo preparar el CSS.\n");
    exit(1);
}
echo "OK: CSS validado; se conservan los tamaños específicos de cada modal.\n";
