<?php
declare(strict_types=1);

// Regresión: después de convertir UTC a hora de México, el SQL ya no
// garantiza el orden cronológico entre llamadas WebRTC y llamadas locales.
$llamadas = [
    ['id' => 21, 'fecha_inicio' => '2026-10-15 18:26:00'],
    ['id' => 22, 'fecha_inicio' => '2026-10-15 15:49:00'],
    ['id' => 23, 'fecha_inicio' => '2026-10-15 18:34:00'],
    ['id' => 24, 'fecha_inicio' => '2026-10-15 18:34:00'],
];
$src = file_get_contents(__DIR__ . '/../prueba_telefonia/api/llamadas_seguimiento.php');
if (!str_contains($src, 'usort($llamadas, static function') ||
    strpos($src, 'usort($llamadas, static function') >
        strpos($src, "$" . "etapaHistorial = 'generar_respuesta';")) {
    fwrite(STDERR, "FALLO: orden final de llamadas ausente\n");
    exit(1);
}
usort($llamadas, static function (array $a, array $b): int {
    $ordenFecha = strcmp((string)$b['fecha_inicio'], (string)$a['fecha_inicio']);
    return $ordenFecha !== 0 ? $ordenFecha : ($b['id'] <=> $a['id']);
});
if (array_column($llamadas, 'id') !== [24, 23, 21, 22]) {
    fwrite(STDERR, "FALLO: orden cronológico incorrecto\n");
    exit(1);
}
echo "Historial expediente ordenado: OK\n";
