<?php
// Solo BD aislada creada por formulario_aliados_fixture.php; nunca el CRM real.
if (PHP_SAPI !== 'cli' || !preg_match('/^impe_test_handly[a-z0-9_]*$/D', getenv('IMPE_DB_NAME') ?: '')) { exit(2); }
require_once __DIR__ . '/../app/models/AliadoInternacionalModel.php';
require_once __DIR__ . '/../config/db_connection.php';
function verificar($ok, $mensaje) {
    if (!$ok) { throw new RuntimeException($mensaje); }
    echo "OK: $mensaje\n";
}
$db = (new Database())->connect(false);
$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION'");
$modelo = new AliadoInternacionalModel($db);
$antes = [];
foreach (['formulario_aliados','formulario_aliado_programas','formulario_aliado_procedencias','formulario_registros','formulario_inscripciones'] as $t) {
    $antes[$t] = $db->query("SELECT * FROM $t ORDER BY 1")->fetch_all(MYSQLI_ASSOC);
}
$externos = $modelo->obtenerAliados();
$handly = array_values(array_filter($externos, fn($a) => $a['slug'] === 'handly-lenguaje-center'))[0];
verificar($handly['programas'] === 'Bachillerato General / Titulación por experiencia laboral', 'dos programas en consulta estricta');
$respuestas = $modelo->obtenerRespuestas();
verificar(count($respuestas) === 4, 'ambos formularios consultados mediante procedencia');
foreach ($respuestas as $fila) {
    $tabla = $fila['tipo'] === 'registro' ? 'formulario_registros' : 'formulario_inscripciones';
    $original = $db->query("SELECT correo FROM $tabla WHERE id=" . (int)$fila['registro_id'])->fetch_assoc();
    verificar($fila['correo'] === $original['correo'], 'correo corresponde al tipo y registro originales');
}
foreach ($antes as $t => $filas) {
    verificar($filas === $db->query("SELECT * FROM $t ORDER BY 1")->fetch_all(MYSQLI_ASSOC), 'consulta conserva íntegros ' . $t);
}
$db->begin_transaction();
try {
    $id = (int)$handly['id'];
    $db->query("UPDATE formulario_programas SET activo=0 WHERE codigo='bachillerato-general'");
    $a = array_values(array_filter($modelo->obtenerAliados(), fn($a) => (int)$a['id'] === $id))[0];
    verificar($a['programas'] === 'Titulación por experiencia laboral', 'programa inactivo no se anuncia como autorizado');
    $db->query("UPDATE formulario_aliados SET activo=0 WHERE id=$id");
    verificar(count($modelo->obtenerRespuestas()) === 4, 'historial persiste al revocar aliado y programa');
    $db->query("UPDATE formulario_aliados SET seguimiento_id=123 WHERE id=$id");
    verificar(count($modelo->obtenerRespuestas()) === 0, 'no expone respuestas de aliados territoriales');
    $db->query("UPDATE formulario_aliados SET seguimiento_id=NULL WHERE id=$id");
    $db->query("INSERT INTO formulario_registros (nombre,correo) VALUES ('GENERAL NO MOSTRAR','general@example.test')");
    verificar(count($modelo->obtenerRespuestas()) === 4, 'excluye capturas generales sin procedencia');
    // Colisión intencionada: mismo id en ambas tablas, diferente persona.
    $rid = (int)$respuestas[0]['registro_id'];
    $db->query("UPDATE formulario_registros SET nombre='SOLO REGISTRO' WHERE id=$rid");
    $db->query("UPDATE formulario_inscripciones SET nombre='SOLO INSCRIPCION' WHERE id=$rid");
    foreach ($modelo->obtenerRespuestas() as $f) {
        if ((int)$f['registro_id'] === $rid) {
            verificar($f['nombre'] === ($f['tipo'] === 'registro' ? 'SOLO REGISTRO' : 'SOLO INSCRIPCION'), 'IDs iguales no mezclan personas');
        }
    }
} finally { $db->rollback(); }

require_once __DIR__.'/../app/helpers/PermissionHelper.php';
$_SESSION=['usuario_id'=>1,'rol_id'=>1,'permisos'=>['aliados.ver']];
// Render real de la vista: datos escapados, enlaces originales, contacto y estado.
$errorExternos = '';
$externos = [$handly];
$externos[0]['nombre'] = '<script>alert(1)</script>';
$respuestas = [['created_at'=>'2026-10-19','aliado'=>'<img src=x onerror=alert(1)>','tipo'=>'registro','nombre'=>'<script>','apellido'=>'Prueba','programa'=>'Bachillerato General','correo'=>'<correo>','movil'=>'777']];
ob_start(); require __DIR__ . '/../app/views/aliados/internacionales.php'; $html = ob_get_clean();
verificar(strpos($html, 'Adalid Kuribeña') !== false && strpos($html, '7775635090') !== false, 'contacto y teléfono de Handly visibles');
verificar(strpos($html, '<script>alert(1)</script>') === false && strpos($html, '<img src=x') === false, 'datos de aliado y prospectos escapados');
verificar(substr_count($html, 'aliado=' . $handly['token']) === 4, 'dos enlaces y dos botones conservan token');
$externos[0]['activo'] = 0;
ob_start(); require __DIR__ . '/../app/views/aliados/internacionales.php'; $html = ob_get_clean();
verificar(strpos($html, 'aliado=' . $handly['token']) === false, 'aliado inactivo no ofrece enlaces');

// Ejecuta el controlador real en procesos separados: sus salidas exit no ocultan fallos.
foreach (['anonimo'=>401, 'sin-permiso'=>403, 'otro-rol'=>403, 'admin'=>200] as $caso=>$esperado) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/aliados_internacionales_acceso.php') . ' ' . escapeshellarg($caso);
    exec($cmd, $salida, $codigo);
    verificar($codigo === 0, 'controlador responde a ' . $caso . ' con ' . $esperado);
}

$db->query('RENAME TABLE formulario_aliado_procedencias TO prueba_procedencias_oculta');
try {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/aliados_internacionales_acceso.php') . ' sin-estructura', $salida, $codigo);
    verificar($codigo === 0, 'estructura incompleta responde 503 sin mostrar enlaces parciales');
} finally { $db->query('RENAME TABLE prueba_procedencias_oculta TO formulario_aliado_procedencias'); }
