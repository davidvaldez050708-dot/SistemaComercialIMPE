<?php
/**
 * Prueba de paginación del HTML real sin BD ni API.
 * 25 llamadas -> 10 / 10 / 5, enlaces, contadores y selección por llamada.
 */
declare(strict_types=1);
if (!defined('BASE_URL')) define('BASE_URL', '/crm/');
$_SESSION['csrf_token'] = 'csrf-local-test';

$registrosPrueba = [];
for ($n = 1; $n <= 25; $n++) {
    $registrosPrueba[] = [
        'pbx_call_id' => 'in_000000000000' . str_pad((string)$n, 3, '0', STR_PAD_LEFT),
        'fuente' => 'PBX',
        'tipo_llamada' => 'ENTRANTE',
        'clasificacion' => 'PENDIENTE',
        'numero' => '550000' . str_pad((string)$n, 4, '0', STR_PAD_LEFT),
        'fecha' => '2026-10-14 10:49:28',
        'duracion' => 11,
        'estado_pbx' => 'ended',
        'contestada' => true
    ];
}
function renderPaginaEntrantes(array $registros, array $query): string
{
    $_GET = $query;
    $bandejaEntrantes = ['extension' => '101', 'llamadas' => $registros];
    $errorEntrantes = '';
    $estadosEntrantes = [];
    ob_start();
    require __DIR__ . '/../app/views/seguimiento_vinculacion/llamadas_entrantes.php';
    return (string)ob_get_clean();
}
function exige(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ERROR paginación llamadas: " . $message . "\n");
        exit(1);
    }
}
function comprobarTarjetas(string $html, int $expected): void
{
    exige(substr_count($html, 'class="linkage-call-history-card linkage-incoming-card"') === $expected,
        "Número de tarjetas incorrecto: esperaba $expected.");
}
function paginaTieneRegistro(string $html, int $n): bool
{
    $id = 'in_000000000000' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
    return str_contains($html, 'data-incoming-row="' . $id . '"');
}

$primera = renderPaginaEntrantes($registrosPrueba, []);
comprobarTarjetas($primera, 10);
exige(paginaTieneRegistro($primera, 1) && paginaTieneRegistro($primera, 10) &&
    !paginaTieneRegistro($primera, 11), 'La primera página no muestra 1–10.');
exige(str_contains($primera, 'Mostrando 1–10') && str_contains($primera, 'de 25 llamadas'),
    'Faltan rango y total en la primera página.');
exige(str_contains($primera, 'aria-current="page"') &&
    str_contains($primera, '&amp;pagina=2#incomingHistoryTitle') &&
    str_contains($primera, '&amp;pagina=3#incomingHistoryTitle'),
    'Faltan números de página 1/2/3.');
exige(substr_count($primera, 'data-call-id=') === 10,
    'La primera página debe conservar exactamente diez acciones de registro.');
exige(str_contains($primera, '<strong>25</strong>'),
    'Los totales del resumen no pueden depender del tamaño de página.');
exige(str_contains($primera, 'rel="next"') && !str_contains($primera, 'rel="prev"'),
    'Controles siguiente/anterior incorrectos en la primera página.');

$segunda = renderPaginaEntrantes($registrosPrueba, ['pagina' => '2']);
comprobarTarjetas($segunda, 10);
exige(!paginaTieneRegistro($segunda, 10) &&
    paginaTieneRegistro($segunda, 11) && paginaTieneRegistro($segunda, 20) &&
    !paginaTieneRegistro($segunda, 21), 'La segunda página no muestra 11–20.');
exige(str_contains($segunda, 'Mostrando 11–20') &&
    str_contains($segunda, 'rel="next"') && str_contains($segunda, 'rel="prev"'),
    'La navegación intermedia no conserva enlaces y rangos.');

$tercera = renderPaginaEntrantes($registrosPrueba, ['pagina' => 3]);
comprobarTarjetas($tercera, 5);
exige(paginaTieneRegistro($tercera, 21) && paginaTieneRegistro($tercera, 25) &&
    !paginaTieneRegistro($tercera, 20), 'La última página no muestra 21–25.');
exige(str_contains($tercera, 'Mostrando 21–25') &&
    !str_contains($tercera, 'rel="next"') && str_contains($tercera, 'rel="prev"'),
    'La última página muestra un botón siguiente inválido.');

foreach (['0', '-5', 'abcdef', '1.5'] as $invalidPage) {
    $html = renderPaginaEntrantes($registrosPrueba, ['pagina' => $invalidPage]);
    comprobarTarjetas($html, 10);
    exige(paginaTieneRegistro($html, 1), 'Páginas inválidas deben regresar a la primera.');
}
$fueraDeRango = renderPaginaEntrantes($registrosPrueba, ['pagina' => '999']);
comprobarTarjetas($fueraDeRango, 5);
exige(paginaTieneRegistro($fueraDeRango, 25),
    'Página superior al máximo debe acotarse a la última.');

$seleccion = renderPaginaEntrantes($registrosPrueba, [
    'sdk_session_id' => $registrosPrueba[24]['pbx_call_id']
]);
comprobarTarjetas($seleccion, 5);
exige(paginaTieneRegistro($seleccion, 25) &&
    str_contains($seleccion, 'data-auto-call="' . $registrosPrueba[24]['pbx_call_id'] . '"'),
    'Los enlaces directos a una llamada deben localizar la página que la contiene.');

$soloDiez = renderPaginaEntrantes(array_slice($registrosPrueba, 0, 10), []);
comprobarTarjetas($soloDiez, 10);
exige(!str_contains($soloDiez, 'class="linkage-incoming-pagination"'),
    'No mostrar paginación si hay exactamente 10 llamadas.');
$ninguna = renderPaginaEntrantes([], []);
comprobarTarjetas($ninguna, 0);
exige(str_contains($ninguna, 'Aún no hay llamadas recibidas'),
    'El estado vacío debe mantenerse.');

$muchaHistoria = renderPaginaEntrantes(
    array_merge($registrosPrueba, array_fill(0, 105, $registrosPrueba[0])),
    ['pagina' => 7]
);
comprobarTarjetas($muchaHistoria, 10);
exige(str_contains($muchaHistoria, 'class="linkage-incoming-pagination-gap"'),
    'Faltan puntos suspensivos para historial de muchas páginas.');
echo "Paginación recibidas: OK (10/página, totales, extremos, enlaces directos, vacío, elipsis).\n";
