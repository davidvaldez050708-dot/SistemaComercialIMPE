<?php
// Sólo CLI: resume mediciones posteriores a esta publicación, sin parámetros personales.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$site = realpath($argv[1] ?? '');
if (!$site || !is_file($site.'/index.php')) exit("RAIZ_NO_VALIDA\n");
$private = dirname($site).'/impe-private/';
$mark = json_decode(@file_get_contents($private.'consultas-repetidas-publicado.json') ?: '', true);
$since = (int)($mark['desde'] ?? 0);
if (!$since) exit("SIN_MARCA_DE_PUBLICACION\n");
$groups = [];
foreach (@file($private.'perfil-peticiones.jsonl', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $r = json_decode($line, true);
    if (!is_array($r) || strtotime((string)($r['utc'] ?? '')) < $since) continue;
    $route = $r['ruta'] ?? '';
    if (!is_string($route) || !preg_match('/^[a-zA-Z0-9_.]{3,145}$/D', $route)) continue;
    $groups[(($r['metodo'] ?? '') === 'POST' ? 'POST' : 'GET').' '.$route][] = $r;
}
if (!$groups) exit("SIN_MUESTRAS_NUEVAS: recorre los módulos y repite este informe.\n");
uasort($groups, static fn($a,$b) => max(array_column($b,'total_ms')) <=> max(array_column($a,'total_ms')));
echo "PERFIL_POSTERIOR: milisegundos y consultas; sin datos personales\n";
foreach (array_slice($groups,0,16,true) as $route=>$rows) {
    usort($rows,static fn($a,$b) => $a['total_ms'] <=> $b['total_ms']);
    $middle=$rows[intdiv(count($rows),2)];$last=$rows[count($rows)-1];
    $queries=array_column($rows,'sql_envios');sort($queries);
    echo $route.' N='.count($rows).' MEDIANA_MS='.(float)$middle['total_ms'].' MAX_MS='.(float)$last['total_ms'].
        ' SQL_MEDIANA='.(int)($queries[intdiv(count($queries),2)]??0)."\n";
    echo ' MAXIMO: ';
    foreach($last['fases_ms']??[] as $key=>$value) if(preg_match('/^[a-z_]+$/D',(string)$key)) echo $key.'='.(float)$value.' ';
    echo 'SQL='.(int)($last['sql_envios']??0)."\n";
}
