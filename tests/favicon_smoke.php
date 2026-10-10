<?php
/**
 * php tests/favicon_smoke.php
 * Impide introducir vistas HTML independientes sin favicon.
 */
$raiz = dirname(__DIR__);
$ico = $raiz . '/public/favicon.ico';
$parcial = $raiz . '/app/views/layout/favicon.php';

if (!is_file($ico) || substr((string)file_get_contents($ico), 0, 4) !== "\x00\x00\x01\x00") {
    fwrite(STDERR, "Favicon ICO ausente o inválido.\n");
    exit(1);
}

if (!is_file($parcial) || strpos((string)file_get_contents($parcial), 'public/favicon.ico') === false) {
    fwrite(STDERR, "Parcial de favicon no encontrado.\n");
    exit(1);
}

$revisar = [$raiz . '/politica-privacidad.php'];
$iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $raiz . '/app/views',
    FilesystemIterator::SKIP_DOTS
));

foreach ($iterador as $archivo) {
    if ($archivo->isFile() && $archivo->getPathname() !== $parcial && strtolower($archivo->getExtension()) === 'php') {
        $revisar[] = $archivo->getPathname();
    }
}

$revisadas = 0;
foreach ($revisar as $archivo) {
    $codigo = (string)file_get_contents($archivo);
    if (!preg_match('/<head\b/i', $codigo)) {
        continue;
    }
    $revisadas++;
    if (strpos($codigo, 'favicon.php') === false) {
        fwrite(STDERR, "Falta incluir el favicon en: " . $archivo . "\n");
        exit(1);
    }
}

echo "Favicon corporativo: OK ({$revisadas} encabezados verificados)\n";
