<?php
require_once __DIR__ . '/../app/services/InegiEscolaridadAdultaXlsxService.php';
$archivo = $argv[1] ?? '';
$zip = new ZipArchive();
if ($zip->open($archivo) !== true) {
    throw new RuntimeException('XLSX nacional ilegible');
}
$parser = new InegiEscolaridadAdultaXlsxService();
$strings = (new ReflectionMethod($parser, 'cadenas'))->invoke($parser, $zip);
$filas = new ReflectionMethod($parser, 'leerFila');
foreach (range(1, 15) as $numero) {
    $hoja = "xl/worksheets/sheet{$numero}.xml";
    if ($zip->locateName($hoja) === false) continue;
    $reader = new XMLReader();
    if (!$reader->open('zip://' . str_replace('\\','/',realpath($archivo)) . '#' . $hoja, null, LIBXML_NONET)) continue;
    $impresas = 0; $n = 0; $oax = 0;
    echo "\n### {$hoja}\n";
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;
        $n++;
        if ($n > 2500) break;
        $dom = new DOMDocument();
        if (!$dom->loadXML($reader->readOuterXML(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) continue;
        $xp = new DOMXPath($dom);
        $c = $filas->invoke($parser, $xp, $dom->documentElement, $strings);
        if ($c === []) continue;
        $texto = implode(' | ', array_slice($c, 0, 10, true));
        $esOax = stripos($texto, 'oaxaca') !== false || preg_match('/\b20\s+Oax/iu', $texto);
        if ($n <= 11 || ($esOax && $oax < 2)) {
            echo $n . ': ' . substr(json_encode(array_slice($c, 0, 11, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),0,650) . "\n";
            $impresas++;
        }
        if ($esOax) $oax++;
    }
    echo "Leidas={$n} ejemplos={$impresas} Oaxaca={$oax}\n";
    $reader->close();
}
$zip->close();
