<?php
if (PHP_SAPI !== 'cli' || !preg_match('/^impe_test_handly[a-z0-9_]*$/D', getenv('IMPE_DB_NAME') ?: '')) { exit(2); }
require_once __DIR__ . '/../app/models/FormularioRegistroModel.php';
require_once __DIR__ . '/../app/services/FormularioAliadoService.php';
$db = (new Database())->connect(false); $modelo = new FormularioRegistroModel();
function comprobar($ok, $mensaje) { if (!$ok) { throw new RuntimeException($mensaje); } echo "OK: $mensaje\n"; }
$token = $db->query("SELECT token FROM formulario_aliados WHERE slug = 'handly-lenguaje-center'")->fetch_assoc()['token'];
$s = new FormularioAliadoService($db); $a = $s->resolver($token);
comprobar(array_column($a['programas'], 'nombre') === ['Bachillerato General','Titulación por experiencia laboral'], 'sólo dos programas Handly');
foreach (['', 'no-valido', str_repeat('0',64), []] as $malo) {
    try { $s->resolver($malo); throw new RuntimeException('Aceptó enlace inválido'); } catch (DomainException $e) {}
}
$datos = ['nombre'=>'Ana','apellido'=>'Prueba','fecha_nacimiento'=>'1990-01-01','movil'=>'7771234567','correo'=>'ana@example.test','perfil_interes'=>'Bachillerato General','lugar_laboras'=>'','cargo_puesto'=>'','estado_id'=>null,'municipio_id'=>null,'pais_nombre'=>'Estados Unidos','estado_provincia'=>'Texas','ciudad'=>'Houston','creado_por'=>null,'aliado_token'=>$token];
foreach (['PUBLICO'=>'formulario_registros','PUBLICO_INSCRIPCION'=>'formulario_inscripciones'] as $origen=>$tabla) {
    $datos['origen']=$origen; $datos['perfil_interes']='Bachillerato General';
    $id = $modelo->guardar($datos); $repetido = $modelo->guardar($datos);
    comprobar($id===$repetido && (int)$db->query("SELECT COUNT(*) n FROM $tabla")->fetch_assoc()['n']===1, 'reenvío idempotente '.$origen);
    foreach (['Inglés','Bachillerato en 2 años','no-existe'] as $programa) {
        $datos['perfil_interes']=$programa;
        try { $modelo->guardar($datos); throw new RuntimeException('Aceptó programa no autorizado'); } catch (DomainException $e) {}
    }
    comprobar((int)$db->query("SELECT COUNT(*) n FROM $tabla")->fetch_assoc()['n']===1, 'rechazo backend sin escrituras '.$origen);
    $datos['perfil_interes']='Titulación por experiencia laboral'; $modelo->guardar($datos);
}
comprobar((int)$db->query('SELECT COUNT(*) n FROM formulario_aliado_procedencias')->fetch_assoc()['n']===4, 'procedencia de ambos flujos y programas');
$fallo=$datos; $fallo['correo']='rollback@example.test';
try { $s->guardar($token,$fallo,'registro',function () use ($db) { $db->query("INSERT INTO formulario_registros (nombre) VALUES ('Rollback')"); throw new RuntimeException('fallo simulado'); }); } catch (RuntimeException $e) {}
comprobar((int)$db->query("SELECT COUNT(*) n FROM formulario_registros WHERE nombre='Rollback'")->fetch_assoc()['n']===0,'rollback transaccional');
$db->query('UPDATE formulario_aliados SET activo=0');
try { $modelo->guardar($datos); throw new RuntimeException('Aceptó aliado desactivado'); } catch (DomainException $e) {}
$db->query('UPDATE formulario_aliados SET activo=1');
$t=str_repeat('b',64); $db->query("INSERT INTO formulario_aliados (slug,nombre,ubicacion,token) VALUES ('otro','Otro','México','$t')"); $otro=$db->insert_id;
$db->query("INSERT INTO formulario_programas VALUES ('ingles','Inglés',1)");
$db->query("INSERT INTO formulario_aliado_programas VALUES ($otro,'ingles')");
comprobar(array_column($s->resolver($t)['programas'],'nombre')===['Inglés'],'selección independiente por aliado');
$db->query("DELETE FROM formulario_aliado_programas WHERE aliado_id=$otro");
try { $s->autorizar($s->resolver($t),'Inglés'); throw new RuntimeException('Lista vacía permitió programa'); } catch (DomainException $e) {}
echo "OK: enlaces, autorizaciones, duplicados, procedencia y transacciones\n";
