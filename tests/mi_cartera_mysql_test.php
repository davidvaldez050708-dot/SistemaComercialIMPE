<?php
/**
 * Integration tests in a DISPOSABLE MySQL database.
 * NEVER run with credentials or host of an actual CRM production database.
 */
require_once __DIR__.'/../app/services/MiCarteraRepository.php';

if (getenv('IMPE_MI_CARTERA_DB_TEST')!=='YES') {
    fwrite(STDERR,"Requiere IMPE_MI_CARTERA_DB_TEST=YES en MySQL desechable.\n");exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(getenv('MYSQL_HOST')?:'127.0.0.1',
    getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:'','',
    (int)(getenv('MYSQL_PORT')?:3306));
$db->set_charset('utf8mb4');
$name='impe_cartera_test_'.bin2hex(random_bytes(6));
$checks=0;
$check=static function(bool $ok,string $mensaje) use (&$checks):void {
    if (!$ok)throw new RuntimeException('FALLO: '.$mensaje);
    $checks++;
};
try {
    $db->query("CREATE DATABASE `$name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($name);
    $db->query('CREATE TABLE roles(id INT PRIMARY KEY,nombre VARCHAR(80),estado TINYINT) ENGINE=InnoDB');
    $db->query('CREATE TABLE usuarios(id INT PRIMARY KEY,nombre VARCHAR(80),apellidos VARCHAR(80),rol_id INT,estado TINYINT) ENGINE=InnoDB');
    $db->query('CREATE TABLE permisos(id INT AUTO_INCREMENT PRIMARY KEY,modulo VARCHAR(100),codigo VARCHAR(150) UNIQUE,nombre VARCHAR(100),descripcion TEXT,estado TINYINT) ENGINE=InnoDB');
    $db->query('CREATE TABLE rol_permisos(rol_id INT,permiso_id INT,PRIMARY KEY(rol_id,permiso_id)) ENGINE=InnoDB');
    $db->query('CREATE TABLE prospectos_comerciales(
        id BIGINT UNSIGNED PRIMARY KEY,nombre VARCHAR(80),apellidos VARCHAR(80),
        nombre_completo VARCHAR(255),telefono_original VARCHAR(64),correo VARCHAR(190),
        pais_nombre VARCHAR(100),estado_provincia VARCHAR(100),ciudad VARCHAR(100),
        verificacion_estado VARCHAR(24)) ENGINE=InnoDB');
    $db->query('CREATE TABLE prospecto_asignaciones (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        prospecto_id BIGINT UNSIGNED NOT NULL,asesor_id INT NOT NULL,
        asignado_at DATETIME NOT NULL,fecha_trabajo DATE NULL,
        proxima_accion_at DATETIME NULL,finalizado_at DATETIME NULL,
        vigente TINYINT UNSIGNED NULL,estado VARCHAR(24),
        UNIQUE KEY uq_pa_vigente(prospecto_id,vigente),
        KEY idx_pa_asesor_fecha (asesor_id,fecha_trabajo)) ENGINE=InnoDB');
    $db->query('CREATE TABLE prospecto_origenes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        prospecto_id BIGINT UNSIGNED NOT NULL,origen_padre_id BIGINT UNSIGNED NULL,
        tipo_origen VARCHAR(24),aliado_nombre VARCHAR(255),formulario_nombre VARCHAR(255),
        fecha_registro_original DATETIME NULL) ENGINE=InnoDB');
    $db->query('CREATE TABLE prospecto_intereses (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        prospecto_id BIGINT UNSIGNED NOT NULL,nombre_original VARCHAR(255),
        clasificacion_estado VARCHAR(24)) ENGINE=InnoDB');
    $db->query('CREATE TABLE prospecto_preferencias_contacto (
        origen_id BIGINT UNSIGNED PRIMARY KEY,
        valor_original TEXT,dias_semana VARCHAR(32),hora_desde TIME,
        hora_hasta TIME,zona_horaria VARCHAR(64),interpretacion_estado VARCHAR(32)
    ) ENGINE=InnoDB');

    $db->query("INSERT INTO roles VALUES(1,'Administrador',1),(3,'Asesor de Ventas',1),(4,'Analista de Datos',1)");
    $db->query("INSERT INTO usuarios VALUES(3,'Cecilia','Prueba',3,1),(4,'Denisse','Prueba',3,1),(5,'Diana','Test',4,1)");
    $db->query("INSERT INTO prospectos_comerciales VALUES
        (101,'Ana','A','Ana A','5511000001','ana@example.com','México','Morelos','Cuernavaca','SIN_VERIFICAR'),
        (102,'Bea','B','Bea B','5511000002','bea@example.com','México','Morelos','Jiutepec','SIN_VERIFICAR'),
        (103,'Carla','C','Carla C','5511000003','carla@example.com','México','Morelos','Cuernavaca','SIN_VERIFICAR'),
        (104,'Dora','D','Dora D','5511000004','dora@example.com','México','Morelos','Jiutepec','SIN_VERIFICAR')");
    $hoy=(new DateTimeImmutable('now',new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
    $db->query("INSERT INTO prospecto_asignaciones
        (prospecto_id,asesor_id,asignado_at,fecha_trabajo,vigente,estado)
        VALUES (101,3,UTC_TIMESTAMP(),'$hoy',1,'ASIGNADO'),
               (102,3,UTC_TIMESTAMP(),NULL,1,'REASIGNADO'),
               (103,4,UTC_TIMESTAMP(),NULL,1,'ASIGNADO'),
               (104,3,UTC_TIMESTAMP(),NULL,NULL,'FINALIZADO')");
    $db->query("INSERT INTO prospecto_intereses(prospecto_id,nombre_original,clasificacion_estado) VALUES
        (101,'Bachillerato','VALIDADO'),(102,'Titulación','VALIDADO'),
        (103,'Programa privado','VALIDADO')");
    $db->query("INSERT INTO prospecto_origenes(prospecto_id,tipo_origen,formulario_nombre) VALUES
        (101,'META_ADS','Meta prueba'),(102,'ALIADO','Aliado prueba'),(103,'ALIADO','Reservado')");
    $db->query("INSERT INTO prospecto_preferencias_contacto(origen_id,valor_original,interpretacion_estado) VALUES
        (1,'Mañanas','VALIDADO'),(3,'No visible','VALIDADO')");

    $migracion=file_get_contents(__DIR__.'/../database/migrations/2026_11_06_ventas_mi_cartera_permiso.sql');
    $check(is_string($migracion),'SQL permiso disponible');
    $db->multi_query($migracion);
    do {if($rs=$db->store_result())$rs->free();} while($db->more_results()&&$db->next_result());
    $permiso=(int)$db->query("SELECT id FROM permisos WHERE codigo='prospectos.mi_cartera' AND estado=1")->fetch_assoc()['id'];
    $check($permiso>0,'permiso activo');
    $check((int)$db->query("SELECT COUNT(*) n FROM rol_permisos WHERE rol_id=3 AND permiso_id=$permiso")->fetch_assoc()['n']===1,
        'Asesor de Ventas recibe Mi cartera');
    $check((int)$db->query("SELECT COUNT(*) n FROM permisos WHERE codigo='prospectos.ver_todos'")->fetch_assoc()['n']===0,
        'la migración no crea acceso al almacén general');

    $repo=new MiCarteraRepository($db);
    $check($repo->disponible(),'esquema de lectura');
    $l=$repo->listar(3,['vista'=>'todos','buscar'=>'','pagina'=>1]);
    $check($l['total']===2,'solo dos asignaciones vigentes del asesor 3');
    $check(count($l['registros'])===2,'dos registros del mismo asesor');
    $ids=array_map('intval',array_column($l['registros'],'prospecto_id'));
    sort($ids);
    $check($ids===[101,102],'nunca aparece el prospecto de otro asesor o histórico');
    $check($l['resumen']['total']===2,'contador sin fuga');
    $check($l['resumen']['sin_fecha']===1,'sin programación');
    $check($l['resumen']['atencion']===1,'hoy solo 101');
    $check($repo->detalle(3,101)!==null,'ficha propia autorizada');
    $check($repo->detalle(3,103)===null,'ficha de otro asesor denegada');
    $check($repo->detalle(3,104)===null,'ficha histórica no vigente denegada');
    $check($repo->detalle(4,101)===null,'otro asesor no ve la ficha de 3');
    $check(count($repo->detalle(4,103)['preferencias'])===1,'solo datos del prospecto autorizado');
    $check($repo->listar(3,['vista'=>'todos','buscar'=>'Carla','pagina'=>1])['total']===0,'búsqueda no consulta otra cartera');
    $check($repo->listar(3,['vista'=>'todos','buscar'=>'Ana','pagina'=>1])['total']===1,'búsqueda propia');
    $check($repo->listar(3,['vista'=>'sin_fecha','buscar'=>'','pagina'=>1])['total']===1,'sin fecha propio');
    $check($repo->listar(3,['vista'=>'hoy','buscar'=>'','pagina'=>1])['total']===1,'hoy propio');
    $check($repo->listar(5,['vista'=>'todos','buscar'=>'','pagina'=>1])['total']===0,'sin asignaciones no ve datos');
    $check($repo->listar(3,['vista'=>'todos','buscar'=>'','pagina'=>999])['pagina']===1,'paginación limitada');
    foreach ([
        ['vista'=>'propios_de_otro','buscar'=>'','pagina'=>1],
        ['vista'=>'todos','buscar'=>['array'],'pagina'=>1],
        ['vista'=>'todos','buscar'=>'','pagina'=>-1],
    ] as $f) {
        try {MiCarteraRepository::filtros($f);throw new RuntimeException('Filtro inválido aceptado');}
        catch (DomainException $e) {$checks++;}
    }
    // Revocar y repetir: no restaurar un permiso retirado por Roles y permisos.
    $db->query("DELETE FROM rol_permisos WHERE rol_id=3 AND permiso_id=$permiso");
    $db->multi_query($migracion);
    do {if($rs=$db->store_result())$rs->free();} while($db->more_results()&&$db->next_result());
    $check((int)$db->query("SELECT COUNT(*) n FROM rol_permisos WHERE rol_id=3 AND permiso_id=$permiso")->fetch_assoc()['n']===0,
        'repetir migración no restituye permiso revocado');
    $check((int)$db->query("SELECT COUNT(*) n FROM prospecto_asignaciones")->fetch_assoc()['n']===4,
        'ninguna asignación modificada');
    echo "OK: $checks verificaciones de aislamiento, fechas, filtros y permisos.\n";
} finally {
    $db->select_db('information_schema');
    $db->query("DROP DATABASE IF EXISTS `$name`");
    $db->close();
}
