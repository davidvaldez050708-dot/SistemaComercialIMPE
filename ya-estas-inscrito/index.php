<?php
// Entrada pública separada del navegador interno del CRM. No cambia sus rutas.
session_start();
require_once dirname(__DIR__) . '/config/db_connection.php';
require_once dirname(__DIR__) . '/app/services/FormularioInscripcionPantalla.php';
require_once dirname(__DIR__) . '/app/models/FormularioRegistroModel.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: same-origin');
$accion = $_GET['accion'] ?? 'mostrar';
if ($accion === 'guardar') {
    FormularioInscripcionPantalla::guardar(true); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405); header('Allow: GET'); exit('Método no permitido.');
}
if ($accion === 'municipios') {
    header('Content-Type: application/json; charset=utf-8');
    $id = filter_var($_GET['estado_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$id) {http_response_code(422); echo json_encode(['ok'=>false,'mensaje'=>'Selecciona un estado.']); exit;}
    try {
        $modelo = new FormularioRegistroModel();
        echo json_encode(['ok'=>true,'municipios'=>$modelo->obtenerMunicipiosActivos($id)],JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(503); echo json_encode(['ok'=>false,'mensaje'=>'No se pudieron consultar los municipios. Vuelve a intentarlo.']);
    }
    exit;
}
$slug = $_GET['programa'] ?? null;
$programaInscripcion = is_string($slug) ? FormularioInscripcionProgramas::porSlug($slug) : null;
if ($accion !== 'mostrar' || !$programaInscripcion) {
    http_response_code(404); exit('Formulario de inscripción no disponible. Solicita el enlace de tu programa.');
}
try {
    $estados = (new FormularioRegistroModel())->obtenerEstadosActivos();
    FormularioInscripcionPantalla::preparar($programaInscripcion, true);
    extract(FormularioInscripcionPantalla::variables(true), EXTR_SKIP);
    require dirname(__DIR__) . '/app/views/formularios/publico_inscripcion_completa.php';
} catch (Throwable $e) {
    error_log('[inscripcion_publica] '.get_class($e));
    http_response_code(503); echo 'No fue posible abrir el formulario. Intenta nuevamente en unos momentos.';
}
