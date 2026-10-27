<?php
declare(strict_types=1);
/** Diagnóstico CLI de sólo lectura. No migra ni reasigna registros. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['site:', 'estado:']);
$site = realpath((string)($options['site'] ?? ''));
$estado = (int)($options['estado'] ?? 0);
if (!$site || !is_file($site.'/index.php') || $estado <= 0) {
    fwrite(STDERR, "Uso: php verificar_cartera_territorial.php --site RUTA --estado ID\n"); exit(1);
}
try {
    require_once $site.'/config/db_connection.php';
    require_once $site.'/app/helpers/SeguimientoTerritorialScope.php';
    require_once $site.'/app/models/SeguimientoVinculacionModel.php';
    $db = (new Database())->connect(false);
    $db->query('START TRANSACTION READ ONLY');
    $model = new SeguimientoVinculacionModel($db);
    $stmt = $db->prepare('SELECT id FROM seguimientos_vinculacion WHERE estado_id=? AND activo=1 ORDER BY id');
    $stmt->bind_param('i', $estado); $stmt->execute();
    $esperados = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC),'id'));
    $stmt->close();
    $equipo = SeguimientoTerritorialScope::analistasVigentes($db, $estado);
    $resultados = [];
    foreach ($equipo as $persona) {
        $id = (int)$persona['id'];
        $filas = $model->obtenerSeguimientosAnalistaEstado($id,$estado);
        $ids = array_map('intval',array_column($filas,'id')); sort($ids);
        if ($ids !== $esperados) throw new RuntimeException('La cartera no coincide para el usuario '.$id);
        $resultados[] = ['usuario_id'=>$id,'seguimientos_accesibles'=>count($ids)];
    }
    $stmt = $db->prepare('SELECT analista_id AS autor_registro,COUNT(*) AS registros FROM seguimientos_vinculacion WHERE estado_id=? AND activo=1 GROUP BY analista_id ORDER BY analista_id');
    $stmt->bind_param('i',$estado); $stmt->execute();
    $autores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    $db->rollback();
    echo json_encode(['estado_id'=>$estado,'expedientes_unicos'=>count($esperados),'equipo_actual'=>$resultados,'autoria_conservada'=>$autores,'resultado'=>$equipo?'CHECK OK: todos los analistas vigentes acceden a la misma cartera; sin escrituras.':'SIN EQUIPO ACTIVO: no hay analistas vigentes para este territorio.'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    if (isset($db)) $db->rollback();
    // No imprimir SQL, credenciales ni contenido de expedientes.
    fwrite(STDERR, 'ERROR DE COMPROBACIÓN: '.$e->getMessage().PHP_EOL); exit(1);
}
