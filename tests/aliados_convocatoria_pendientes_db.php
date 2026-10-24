<?php
declare(strict_types=1);

/** Prueba real MySQL 8 / MariaDB 10.11: permisos territoriales y cierre por programa. */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('IMPE_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('IMPE_TEST_DB_PORT') ?: 3306);
$db = new mysqli($host, getenv('IMPE_TEST_DB_USER') ?: 'root',
    getenv('IMPE_TEST_DB_PASSWORD') ?: '', '', $port);
$db->set_charset('utf8mb4');
$name = 'impe_notif_test_'.getmypid();
$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $db->select_db($name);
    $schema = [
        "CREATE TABLE aliados_asignaciones (seguimiento_id INT, cuenta_clave_usuario_id INT, asignado_at DATETIME, activo INT)",
        "CREATE TABLE seguimientos_vinculacion (id INT PRIMARY KEY, estado_id INT, nombre_entidad VARCHAR(180), activo INT)",
        "CREATE TABLE seguimientos_vinculacion_post_envio (seguimiento_id INT, convenio_formalizado_at DATETIME)",
        "CREATE TABLE aliados_manuales (id INT PRIMARY KEY, estado_id INT, nombre_entidad VARCHAR(180), activo INT, created_at DATETIME)",
        "CREATE TABLE formulario_aliado_registro (aliado_id INT, seguimiento_id INT, manual_id INT)",
        "CREATE TABLE formulario_aliado_programas (aliado_id INT, programa_codigo VARCHAR(64) COLLATE utf8mb4_unicode_ci)",
        "CREATE TABLE formulario_programas (codigo VARCHAR(64) COLLATE utf8mb4_unicode_ci, nombre VARCHAR(180), activo INT)",
        "CREATE TABLE marketing_aliado_convocatorias_origen (convocatoria_id INT, origen_tipo VARCHAR(20) COLLATE utf8mb4_general_ci, origen_id INT, programa_codigo VARCHAR(64) COLLATE utf8mb4_general_ci)",
        "CREATE TABLE marketing_aliado_manual_convocatorias (manual_id INT, convocatoria_id INT, programa_codigo VARCHAR(64) COLLATE utf8mb4_unicode_ci)",
        "CREATE TABLE marketing_convenio_convocatorias (seguimiento_id INT, convocatoria_id INT)",
        "CREATE TABLE formulario_aliado_convocatorias (aliado_id INT, convocatoria_id INT, formulario VARCHAR(30), programa_codigo VARCHAR(64) COLLATE utf8mb4_general_ci)",
        "CREATE TABLE convocatorias (id INT PRIMARY KEY, estado INT, tipo_convocatoria VARCHAR(60), subtipo_convocatoria VARCHAR(100))",
        "CREATE TABLE asignaciones_territorio (usuario_id INT, estado_id INT, tipo_asignacion VARCHAR(30), activo INT, fecha_inicio DATE, fecha_fin DATE)"
    ];
    foreach ($schema as $sql) $db->query($sql);
    $seeds = [
        "INSERT INTO aliados_asignaciones VALUES (11,31,'2026-10-24 11:00:00',1),(12,32,'2026-10-24 10:00:00',1),(13,31,'2026-10-24 09:00:00',1)",
        "INSERT INTO seguimientos_vinculacion VALUES (11,17,'Universidad Morelos',1),(12,22,'Escuela Puebla',1),(13,17,'Institución sin programas',1)",
        "INSERT INTO seguimientos_vinculacion_post_envio VALUES (11,'2026-10-24 11:00:00'),(12,'2026-10-24 10:00:00'),(13,'2026-10-24 09:00:00')",
        "INSERT INTO aliados_manuales VALUES (21,17,'Aliado Manual Morelos',1,'2026-10-24 10:00:00'),(22,22,'Aliado Manual Puebla',1,'2026-10-24 10:00:00')",
        "INSERT INTO formulario_aliado_registro VALUES (101,11,NULL),(102,12,NULL),(201,NULL,21),(202,NULL,22)",
        "INSERT INTO formulario_programas VALUES ('ingles','Inglés',1),('bachillerato-general','Bachillerato General',1),('ejecutivas','Titulación Ejecutivas',1)",
        "INSERT INTO formulario_aliado_programas VALUES (101,'ingles'),(101,'bachillerato-general'),(102,'ingles'),(201,'ejecutivas'),(202,'ejecutivas')",
        "INSERT INTO asignaciones_territorio VALUES (31,17,'CUENTA_CLAVE',1,'2026-01-01',NULL),(32,22,'CUENTA_CLAVE',1,'2026-01-01',NULL)"
    ];
    foreach($seeds as $sql) $db->query($sql);

    require_once __DIR__.'/../app/services/AliadoConvocatoriaPendienteService.php';
    $s = new AliadoConvocatoriaPendienteService($db);
    $keys = static function(array $items): array {
        return array_values(array_map(static fn($x) => $x['clave'], $items));
    };
    $a = $s->obtener(31,false);
    $expected = [
        'aliado_convocatoria:institucional:11:ingles',
        'aliado_convocatoria:institucional:11:bachillerato-general',
        'aliado_convocatoria:institucional:13:programas',
        'aliado_convocatoria:manual:21:ejecutivas'
    ];
    if (array_diff($expected,$keys($a)) || count($a)!==4)
        throw new RuntimeException('Cuenta Clave Morelos: '.json_encode($keys($a)));
    foreach ($a as $item) {
        if (!$item['pendiente_operativo'] || !$item['leida'] || $item['id']!==0)
            throw new RuntimeException('Lectura no debe cerrar tareas.');
    }
    $b=$s->obtener(32,false);
    if (count($b)!==2 || in_array('aliado_convocatoria:institucional:11:ingles',$keys($b),true))
        throw new RuntimeException('Alcance territorial Puebla.');
    $m=$s->obtener(40,true);
    if (count($m)!==5 || in_array('aliado_convocatoria:institucional:13:programas',$keys($m),true))
        throw new RuntimeException('Marketing solo publicaciones y nacional.');

    // Una publicación programada para el futuro ya cuenta como creada.
    $db->query("INSERT INTO convocatorias VALUES (501,0,'bachillerato','ingles')");
    $db->query("INSERT INTO marketing_aliado_convocatorias_origen VALUES (501,'institucional',11,'ingles')");
    if (in_array('aliado_convocatoria:institucional:11:ingles',$keys($s->obtener(31,false)),true))
        throw new RuntimeException('Publicación futura generó un falso pendiente.');

    // Los formatos históricos manuales e institucionales no se duplican.
    $db->query("INSERT INTO convocatorias VALUES (502,1,'titulacion','ejecutivas'),(503,1,'bachillerato','bachillerato-general')");
    $db->query("INSERT INTO marketing_aliado_manual_convocatorias VALUES (21,502,'ejecutivas')");
    $db->query("INSERT INTO marketing_convenio_convocatorias VALUES (11,503)");
    $remaining=$keys($s->obtener(31,false));
    if ($remaining!==['aliado_convocatoria:institucional:13:programas'])
        throw new RuntimeException('No cerró tareas publicadas, histórico/manual: '.json_encode($remaining));

    // Existe un enlace de publicación histórico ligado al perfil.
    $db->query("INSERT INTO convocatorias VALUES (504,1,'bachillerato','ingles')");
    $db->query("INSERT INTO formulario_aliado_convocatorias VALUES (102,504,'registro','ingles')");
    if (in_array('aliado_convocatoria:institucional:12:ingles',$keys($s->obtener(32,false)),true))
        throw new RuntimeException('Publicación histórica de formulario no reconocida.');

    // Cambiar responsable/vigencia elimina la notificación territorial.
    $db->query("UPDATE asignaciones_territorio SET activo=0 WHERE usuario_id=31");
    if ($s->obtener(31,false)!==[]) throw new RuntimeException('Asignación caducada continúa notificando.');
    if ($s->obtener(40,true) !== $s->obtener(40,true))
        throw new RuntimeException('Consulta no idempotente.');
    echo "OK: SQL MySQL/MariaDB, cinco fuentes, territorialidad, ausencias, publicación futura e histórica, sin duplicados\n";
} finally {
    $db->query("DROP DATABASE `$name`");
    $db->close();
}
