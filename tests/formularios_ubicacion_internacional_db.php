<?php
if (PHP_SAPI !== 'cli' || !preg_match('/^impe_test_handly[a-z0-9_]*$/D', getenv('IMPE_DB_NAME') ?: '')) { exit(2); }
require_once __DIR__ . '/../app/models/FormularioRegistroModel.php';
require_once __DIR__ . '/../app/services/FormularioAliadoService.php';
$db = (new Database())->connect(false);
$m = new FormularioRegistroModel();
$token = $db->query("SELECT token FROM formulario_aliados WHERE slug='handly-lenguaje-center'")->fetch_assoc()['token'];
function ubicacionCheck($ok, $mensaje) { if (!$ok) { throw new RuntimeException($mensaje); } echo "OK: $mensaje\n"; }
$datos = ['nombre'=>'Laura','apellido'=>'Prueba','fecha_nacimiento'=>'1990-01-01','movil'=>'7135551234','correo'=>'ubicacion@example.test','perfil_interes'=>'Bachillerato General','lugar_laboras'=>'','cargo_puesto'=>'','estado_id'=>null,'municipio_id'=>null,'pais_nombre'=>'Estados Unidos','estado_provincia'=>'Texas','ciudad'=>'Houston','aliado_token'=>$token];
foreach (['PUBLICO'=>'formulario_registros','PUBLICO_INSCRIPCION'=>'formulario_inscripciones'] as $origen=>$tabla) {
    $datos['origen']=$origen;
    $id=$m->guardar($datos);
    ubicacionCheck($m->guardar($datos)===$id, 'reenvío internacional '.$tabla);
    $r=$db->query("SELECT * FROM $tabla WHERE id=$id")->fetch_assoc();
    ubicacionCheck($r['estado_id']===null && $r['municipio_id']===null && $r['pais_nombre']==='Estados Unidos' && $r['estado_provincia']==='Texas' && $r['ciudad']==='Houston', 'ubicación persistida '.$tabla);
    foreach (['estado_id'=>1,'municipio_id'=>1,'estado_provincia'=>'','ciudad'=>str_repeat('a',121),'pais_nombre'=>'<script>'] as $campo=>$valor) {
        $malo=$datos; $malo[$campo]=$valor;
        try { $m->guardar($malo); throw new RuntimeException('Aceptó ubicación manipulada: '.$campo); } catch (DomainException $e) {}
    }
    $nacional=$datos; unset($nacional['aliado_token']); $nacional['estado_id']=1; $nacional['municipio_id']=1;
    $nid=$m->guardar($nacional);
    $r=$db->query("SELECT * FROM $tabla WHERE id=$nid")->fetch_assoc();
    ubicacionCheck((int)$r['estado_id']===1 && $r['pais_nombre']===null, 'nacional mantiene catálogo '.$tabla);
    $nacional['municipio_id']=9999;
    try { $m->guardar($nacional); throw new RuntimeException('Aceptó municipio falso'); } catch (DomainException $e) {}
    // Limpiar sólo las filas creadas por esta prueba; mantener la fixture para regresiones existentes.
    $db->query("DELETE FROM formulario_aliado_procedencias WHERE tipo='".($origen==='PUBLICO'?'registro':'inscripcion')."' AND registro_id=$id");
    $db->query("DELETE FROM $tabla WHERE id IN ($id,$nid)");
}
