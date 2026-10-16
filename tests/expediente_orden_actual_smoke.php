<?php
declare(strict_types=1);
$path = __DIR__ . '/../prueba_telefonia/api/llamadas_seguimiento.php';
$src = file_get_contents($path);
$marker = 'usort($llamadas, static function';
if (substr_count($src, $marker) !== 1
    || strpos($src, $marker) > strpos($src, "$" . "etapaHistorial = 'generar_respuesta';")) {
    fwrite(STDERR, "FALLO: orden final ausente o duplicado\n");
    exit(1);
}
$llamadas = [
    ['id' => 21, 'fecha_inicio' => '2026-10-15 18:26:00'],
    ['id' => 22, 'fecha_inicio' => '2026-10-15 15:49:00'],
    ['id' => 23, 'fecha_inicio' => '2026-10-15 18:34:00'],
    ['id' => 24, 'fecha_inicio' => '2026-10-15 18:34:00'],
];
usort($llamadas, static function (array $a, array $b): int {
    $v = strcmp((string)($b['fecha_inicio'] ?? ''), (string)($a['fecha_inicio'] ?? ''));
    return $v !== 0 ? $v : ((int)$b['id'] <=> (int)$a['id']);
});
if (array_column($llamadas, 'id') !== [24,23,21,22]) {
    fwrite(STDERR, "FALLO: orden inconsistente\n");
    exit(1);
}
echo "Historial expediente ordenado: OK\n";
