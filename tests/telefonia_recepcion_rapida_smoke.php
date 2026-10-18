<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/services/TelefoniaEnrutamientoService.php';

function checkReception(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "ERROR recepcion rapida: $message\n");
        exit(1);
    }
}
checkReception(TelefoniaEnrutamientoService::numeroNacional('+52 777 788 5173') === '7777885173',
    'El identificador +52 debe conservar los diez dígitos.');
checkReception(TelefoniaEnrutamientoService::numeroNacional('5217777885173') === '7777885173',
    'El prefijo móvil 521 debe normalizarse.');
checkReception(TelefoniaEnrutamientoService::numeroNacional('(777) 788-5173') === '7777885173',
    'Aceptar presentación local de diez dígitos.');
checkReception(TelefoniaEnrutamientoService::numeroNacional('101') === '',
    'Nunca buscar extensiones internas como instituciones.');
checkReception(TelefoniaEnrutamientoService::numeroNacional('numero privado') === '',
    'No inferir identidad sin caller ID.');
checkReception(TelefoniaEnrutamientoService::numeroNacional(str_repeat('1', 19)) === '',
    'Rechazar números demasiado largos.');

$service = file_get_contents(__DIR__.'/../app/services/TelefoniaEnrutamientoService.php');
$controller = file_get_contents(__DIR__.'/../app/controllers/TelefoniaController.php');
$routing = file_get_contents(__DIR__.'/../public/javascript/telefonia_enrutamiento.js');
$css = file_get_contents(__DIR__.'/../public/css/telefonia_enrutamiento.css');
$html = file_get_contents(__DIR__.'/../app/views/telefonia/shell.php');
foreach ([
    'servicio' => $service,
    'controlador' => $controller,
    'interfaz' => $routing,
    'estilos' => $css,
    'plantilla' => $html
] as $name => $text) checkReception(is_string($text), "Archivo $name no encontrado.");
foreach ([
    'telefono_fuente', 'telefono_verificado',
    's.analista_id', 's.estado_id',
    "count(\$registros) >= 60",
    'estado_seguimiento', 'responsables'
] as $marker) checkReception(strpos($service, $marker) !== false, "Búsqueda carece de $marker");
checkReception(strpos($controller, 'identificarOrigen($usuarioId, $rol, $telefonoOrigen)') !== false,
    'La consulta por teléfono no llega al servidor.');
checkReception(strpos($routing, 'String(active.phone') !== false,
    'Se utiliza el teléfono de la llamada actual.');
checkReception(strpos($routing, 'telefono_origen:checkPhone ? phone') !== false,
    'No se utiliza el número recibido.');
checkReception(strpos($routing, 'if (recognized.length && !confirmedRecognition)') !== false,
    'El origen sugerido debe confirmarse antes de transferir.');
checkReception(strpos($routing, 'document.body.appendChild(pane)') !== false,
    'El formulario debe salir del modal telefónico.');
checkReception(strpos($routing, 'visible.length === 1 ? visible[0]') !== false,
    'El único responsable debe sugerirse en llamadas nuevas.');
checkReception(strpos($routing, "lookupWrap.hidden = !lookupWrap.hidden") !== false,
    'La búsqueda manual es opcional y desplegable.');
checkReception(strpos($css, 'position: fixed') !== false &&
    strpos($css, 'max-height: min(530px') !== false,
    'El panel debe permanecer independiente y dentro del viewport.');
checkReception(strpos($css, ':has(.impe-routing-panel') === false,
    'No debe aumentar el alto del teléfono al desplegar transferencia.');
checkReception(strpos($html, 'data-routing-identification') !== false &&
    strpos($html, 'data-routing-search-toggle') !== false,
    'No está presente el bloque de identificación rápida.');
echo "Recepción rápida: OK (número nacional, ambiguos, confirmación, panel lateral, búsqueda opcional).\n";
