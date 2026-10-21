<?php
declare(strict_types=1);
require_once __DIR__.'/../app/services/TelefoniaEnrutamientoService.php';

function testAssert(bool $yes, string $message): void {
    if (!$yes) { fwrite(STDERR, "ERROR transferencia: ".$message."\n"); exit(1); }
}
testAssert(TelefoniaEnrutamientoService::categoriaRol('Marketing') === 'RECEPCION',
    'Marketing debe operar Recepción.');
testAssert(TelefoniaEnrutamientoService::categoriaRol('Analista de Datos') === 'ANALISTA',
    'El Analista debe tener regla de escalamiento.');
testAssert(TelefoniaEnrutamientoService::categoriaRol('Asesor') === 'VENTAS',
    'Asesor comercial requiere su Cuenta Clave.');
testAssert(TelefoniaEnrutamientoService::categoriaRol('Asesor de Ventas') === 'VENTAS',
    'Nombre alternativo del rol de Ventas.');
testAssert(TelefoniaEnrutamientoService::categoriaRol('Cuenta Clave') === 'OTRO',
    'Cuenta Clave no obtiene permisos extra por este flujo.');

$files = [
 'service' => file_get_contents(__DIR__.'/../app/services/TelefoniaEnrutamientoService.php'),
 'controller' => file_get_contents(__DIR__.'/../app/controllers/TelefoniaController.php'),
 'host' => file_get_contents(__DIR__.'/../public/javascript/telefonia_host.js'),
 'shell' => file_get_contents(__DIR__.'/../app/views/telefonia/shell.php'),
 'capture' => file_get_contents(__DIR__.'/../app/views/telefonia/captura_panel.php'),
 'phone' => file_get_contents(__DIR__.'/../public/javascript/telefonia_shell.js'),
 'routing' => file_get_contents(__DIR__.'/../public/javascript/telefonia_enrutamiento.js'),
 'availability' => file_get_contents(__DIR__.'/../public/javascript/telefonia_disponibilidad.js'),
 'sales' => file_get_contents(__DIR__.'/../public/javascript/telefonia_persistente.js'),
 'territory' => file_get_contents(__DIR__.'/../app/models/TerritorioModel.php'),
 'territoryController' => file_get_contents(__DIR__.'/../app/controllers/TerritorioController.php'),
 'territoryView' => file_get_contents(__DIR__.'/../app/views/territorios/equipo.php'),
 'received' => file_get_contents(__DIR__.'/../app/services/TelefoniaEntrantesAnalistaService.php')
];
foreach($files as $name=>$contents) testAssert($contents !== false, "Falta archivo $name.");

foreach ([
 'service'=>[
  "a.tipo_asignacion = 'CUENTA_CLAVE'",
  'origen.cuenta_clave_asignacion_id = a.id',
  "origen.tipo_asignacion = '\$clase'",
  'a.usuario_id <> ?',
  "te.permite_entrantes = 1",
  "s.estado_seguimiento <> 'DESCARTADO'",
  "No estás autorizado para transferir",
  "Selecciona primero el estado"
 ],
 'controller'=>[
  'public function autorizarTransferencia()',
  'public function validarOrdenTransferencia()',
  'rutaAceptada(',
  'disponibilidad->consumir(',
  'hash_equals',
  'random_bytes(24)',
  'expires',
  "unset(\$_SESSION['telefonia_transferencia_autorizada'])",
  'TelefoniaContactosService::validarToken'
 ],
 'host'=>[
  'ejecutarTransferenciaAutorizada',
  'config.validarTransferenciaUrl',
  'sendTransferDtmf(',
  "publish({message:'La llamada cambió",
  'transferToken'
 ],
 'shell'=>[
  'data-shell-transfer-toggle',
  'data-shell-transfer-panel',
  'data-routing-destination',
  'captura_panel.php',
  'telefonia_enrutamiento.js'
 ],
 'capture'=>[
  'data-shell-capture-panel',
  'data-routing-capture-form'
 ],
 'phone'=>[
  'getActiveCall: function',
  'requestAuthorizedTransfer: function',
  'const workingCall = Boolean(ongoing'
 ],
 'routing'=>[
  'window.IMPE_AVAILABILITY.watch(data.solicitud)',
  "intencion:'SOLICITAR'",
  'drafts.save(Number(cfg.userId),ids,data',
  'drafts?.localSave(Number(cfg.userId),draftRefs,values())',
  "item.estado_id",
  'Confirma primero el estado',
  "selected.usuario_id"
 ],
 'availability'=>[
  'requestAuthorizedTransfer(data.token)',
  "watched.estado!=='ACEPTADA'",
  'disponibilidad_id:op.id',
  'csrf_token:cfg.routingCsrf'
 ],
 'sales'=>[
  'IMPE_TELEPHONY_TRANSFER_CSRF',
  'autorizarTransferencia',
  "transferToken:String(data.token || '')"
 ],
 'territory'=>[
  'public function reasociarAsesorCuentaClave(',
  "asesor.tipo_asignacion = 'ASESOR'",
  'cuenta.estado_id = asesor.estado_id',
  'registrarMovimientoTerritorial'
 ],
 'territoryController'=>[
  'public function vincularAsesorCuentaClave()',
  "hash_equals",
  "territorios.asignar"
 ],
 'territoryView'=>[
  'vincularAsesorCuentaClave',
  'cuenta_clave_asignacion_id',
  'csrf_token'
 ],
 'received'=>[
  'telefono_capturado', 'correo_capturado',
  'filter_var($correoCapturado, FILTER_VALIDATE_EMAIL)',
  "'telefono' => \$telefonoInstitucional",
 ]
] as $name=>$items) {
    foreach($items as $item) {
        testAssert(str_contains($files[$name], $item), "$name carece de $item");
    }
}
testAssert(!str_contains($files['host'], 'sendTransferDtmf(\n                payload.extension'),
    'Nunca emitir DTMF desde dato sin revalidar.');

// Transferir no usa el token particular de la agenda personal.
$controller = $files['controller'];
$inicioTransferencia = strpos($controller, 'private function validarTransferenciaPost(): void');
$inicioAutorizacion = strpos($controller, 'public function autorizarTransferencia()', $inicioTransferencia ?: 0);
testAssert($inicioTransferencia !== false && $inicioAutorizacion !== false,
    'No se encuentra el validador del endpoint de transferencias.');
$validador = substr($controller, $inicioTransferencia, $inicioAutorizacion - $inicioTransferencia);
testAssert(str_contains($validador, "(string)(\$_SESSION['csrf_token'] ?? '')"),
    'Transferir debe usar el token de sesión que entrega shell y host.');
testAssert(str_contains($validador, 'hash_equals($csrfSesion, $csrfRecibido)'),
    'Mantener comparación constante de CSRF.');
testAssert(!str_contains($validador, 'TelefoniaContactosService::validarToken'),
    'No se puede verificar transferencia con el token diferente de contactos.');
testAssert(str_contains($files['shell'], "'routingCsrf' => (string)\$_SESSION['csrf_token']"),
    'El panel debe enviar el token de sesión compartido.');

testAssert(!str_contains($files['availability'],'sendTransferDtmf'), 'La solicitud de disponibilidad no emite tonos.');

echo "Transferencias: OK (roles, territorios, consentimiento, CSRF, token único, PBX y formulario).\n";
