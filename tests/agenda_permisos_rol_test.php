<?php

// Ejecutar únicamente desde agenda_comentarios_mysql_test.py.
if (getenv('AGENDA_TEST_SCHEMA') !== 'agenda_roles_test' || empty($argv[1])) {
    exit(2);
}
require $argv[1] . '/app/models/RolModel.php';

function comprobarPermisosAgenda(bool $condicion, string $caso): void
{
    if (!$condicion) {
        throw new RuntimeException($caso);
    }
}

$db = (new Database())->connect();
$db->query('CREATE TABLE roles (id INT PRIMARY KEY, nombre VARCHAR(50), estado TINYINT)');
$db->query('CREATE TABLE permisos (id INT AUTO_INCREMENT PRIMARY KEY, modulo VARCHAR(80),
    codigo VARCHAR(100) UNIQUE, nombre VARCHAR(120), descripcion VARCHAR(255), estado TINYINT)');
$db->query('CREATE TABLE rol_permisos (rol_id INT, permiso_id INT, PRIMARY KEY (rol_id, permiso_id))');
$db->query("INSERT INTO roles VALUES (1,'Administrador',1),(2,'Director de Operaciones',1),
    (4,'Analista de Datos',1),(6,'Cuenta Clave',1),(9,'Marketing',1),(10,'Rol adicional',1),(12,'RH',1)");
$db->query("INSERT INTO permisos (modulo,codigo,nombre,estado) VALUES ('Reuniones','reuniones.ver','Ver reuniones',1)");
$db->query('INSERT INTO rol_permisos SELECT 6,id FROM permisos');

$modelo = new RolModel();
$modelo->inicializarPermisosSistema();
$permisosDe = static fn (int $rol) => $modelo->obtenerCodigosPermisosPorRol($rol);
foreach ([1, 2, 4, 6, 9, 10] as $rol) {
    comprobarPermisosAgenda(in_array('agenda.ver', $permisosDe($rol), true), "Agenda inicial en rol $rol");
}
comprobarPermisosAgenda(in_array('agenda.comentarios.crear', $permisosDe(6), true), 'Cuenta Clave conserva la creación');
comprobarPermisosAgenda(!in_array('agenda.comentarios.ver', $permisosDe(10), true), 'Un rol nuevo en Agenda empieza con calendario');

$enviarAdministrador = 'agenda.comentarios.enviar_administrador';
foreach ([1=>false, 2=>true, 6=>true, 9=>false, 12=>true] as $rol => $esperado) {
    comprobarPermisosAgenda(in_array($enviarAdministrador, $permisosDe($rol), true) === $esperado,
        "Asignación inicial de envío al Administrador para el rol $rol");
}
$envioId = array_flip($modelo->obtenerPermisosPorRol(6))[$enviarAdministrador];
$marketing = array_keys($modelo->obtenerPermisosPorRol(9));
$_SESSION = ['rol_id' => 2];
comprobarPermisosAgenda($modelo->actualizarPermisosRol(9, array_merge($marketing, [$envioId])),
    'Un gestor delegado puede guardar otros permisos de Marketing');
comprobarPermisosAgenda(!in_array($enviarAdministrador, $permisosDe(9), true), 'Un gestor delegado no puede conceder este permiso');
$_SESSION['rol_id'] = 1;
comprobarPermisosAgenda($modelo->actualizarPermisosRol(9, array_merge($marketing, [$envioId])),
    'El Administrador puede activar el permiso en otro rol');
$_SESSION['rol_id'] = 2;
comprobarPermisosAgenda($modelo->actualizarPermisosRol(9, $marketing) &&
    in_array($enviarAdministrador, $permisosDe(9), true), 'El guardado delegado conserva el permiso');
$_SESSION['rol_id'] = 1;
comprobarPermisosAgenda($modelo->actualizarPermisosRol(9, $marketing) &&
    !in_array($enviarAdministrador, $permisosDe(9), true), 'El Administrador puede desactivar el permiso');

$ids = array_flip($modelo->obtenerPermisosPorRol(1));
$adminAntes = $permisosDe(1);
$_SESSION = ['rol_id' => 2];
comprobarPermisosAgenda(!$modelo->actualizarPermisosRol(1, []), 'Otro rol no modifica al Administrador');
$_SESSION['rol_id'] = 1;
comprobarPermisosAgenda($modelo->actualizarPermisosRol(1, [$ids['agenda.comentarios.ver']]), 'Guardar permisos de Agenda del Administrador');
comprobarPermisosAgenda($modelo->actualizarPermisosRol(6, [$ids['reuniones.ver']]), 'Revocar Agenda de Cuenta Clave');
$modelo->inicializarPermisosSistema();
$adminDespues = $permisosDe(1);
$sinAgenda = static fn (array $permisos) => array_values(array_filter($permisos, static fn ($c) => strpos($c, 'agenda.') !== 0));
comprobarPermisosAgenda($sinAgenda($adminAntes) === $sinAgenda($adminDespues), 'Conserva la política ajena a Agenda');
comprobarPermisosAgenda(!in_array('agenda.ver', $adminDespues, true), 'La sincronización respeta revocación del Administrador');
comprobarPermisosAgenda(!in_array('agenda.ver', $permisosDe(6), true), 'La sincronización respeta revocación de Cuenta Clave');
comprobarPermisosAgenda(!in_array($enviarAdministrador, $permisosDe(6), true), 'El catálogo no repone el permiso revocado de Cuenta Clave');
comprobarPermisosAgenda(in_array('reuniones.ver', $permisosDe(6), true), 'Reuniones conserva su propio permiso');

require dirname(__DIR__) . '/app/helpers/PermissionHelper.php';
define('BASE_URL', '/');
$_SESSION['usuario_id'] = 7;
$_SESSION['permisos'] = $adminDespues;
$rolSeleccionado = ['id' => 1, 'nombre' => 'Administrador', 'estado' => 1];
$permisosAgrupados = $modelo->obtenerPermisosAgrupados();
$permisosRol = $modelo->obtenerPermisosPorRol(1);
ob_start();
require dirname(__DIR__) . '/app/views/roles/panel_permisos.php';
$html = ob_get_clean();
comprobarPermisosAgenda(
    preg_match('/data-permission-code="agenda\.ver"[^>]*>/', $html, $agenda) &&
        !str_contains($agenda[0], 'disabled') && !str_contains($agenda[0], 'checked'),
    'La casilla de Agenda revocada está disponible para volver a habilitarla'
);
comprobarPermisosAgenda(
    preg_match('/data-permission-code="roles\.asignar_permisos"[^>]*disabled/', $html) === 1,
    'Los permisos generales del Administrador siguen protegidos en el panel'
);
$rolSeleccionado = ['id' => 9, 'nombre' => 'Marketing', 'estado' => 1];
$permisosRol = $modelo->obtenerPermisosPorRol(9);
foreach ([1 => false, 2 => true] as $rolActor => $bloqueada) {
    $_SESSION['rol_id'] = $rolActor;
    ob_start();
    require dirname(__DIR__) . '/app/views/roles/panel_permisos.php';
    $html = ob_get_clean();
    comprobarPermisosAgenda(
        preg_match('/data-permission-code="agenda\.comentarios\.enviar_administrador"[^>]*>/', $html, $casilla) &&
            str_contains($casilla[0], 'disabled') === $bloqueada,
        "Edición de la casilla de envío para el rol gestor $rolActor"
    );
}
echo "OK: Agenda por rol, administración protegida y revocaciones persistentes en MariaDB.\n";
