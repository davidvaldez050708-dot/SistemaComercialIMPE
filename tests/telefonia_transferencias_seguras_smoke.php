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
 'phone' => file_get_contents(__DIR__.'/../public/javascript/telefonia_shell.js'),
 'routing' => file_get_contents(__DIR__.'/../public/javascript/telefonia_enrutamiento.js'),
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
  "'cuenta_clave_asignacion_id'",
  "origen.tipo_asignacion = '$clase'",
  'a.usuario_id <> ?',
  "te.permite_entrantes = 1",
  "s.estado_seguimiento <> 'DESCARTADO'",
  "No estás autorizado para transferir",
  "Selecciona primero el estado"
 ],
 'controller'=>[
  'public function autorizarTransferencia()',
  'public function validarOrdenTransferencia()',
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
  'data-shell-capture-panel',
  'data-routing-capture-form',
  'telefonia_enrutamiento.js'
 ],
 'phone'=>[
  'getActiveCall: function',
  'requestAuthorizedTransfer: function',
  'const workingCall = Boolean(ongoing'
 ],
 'routing'=>[
  'requestAuthorizedTransfer(data.token)',
  'if (confirmDirect !== key)',
  "sessionStorage.setItem(keyFor(current.id)",
  "item.estado_id",
  'Selecciona primero',
  "selected.usuario_id"
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
echo "Transferencias: OK (roles, territorios, consentimiento, CSRF, token único, PBX y formulario).\n";
