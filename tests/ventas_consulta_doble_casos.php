<?php
require_once dirname(__DIR__).'/app/services/VentasActividadRepository.php';
require_once dirname(__DIR__).'/app/services/VentasActividadService.php';
require_once dirname(__DIR__).'/app/services/SupervisionLlamadasRepository.php';
require_once dirname(__DIR__).'/app/services/VentasActividadPdfView.php';
require_once dirname(__DIR__).'/app/services/VentasDesempenoPdfView.php';
$catalogo=TelefoniaResultadoVentasCatalogo::class;
$r=$svc->obtener(8,'pbx-doble-prueba-3')['registro'];
$questions=(int)$db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
$batch=$catalogo::completarRegistros($db,array_fill(0,600,$r));
$delta=(int)$db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value']-$questions;
vcheck(count($batch)===600&&$delta<=5,'Lectura por lotes evita una consulta por tarjeta');
vcheck($catalogo::seleccionesRegistro($r)===['PROSPECTO_POTENCIAL','WHATSAPP'],'Consulta conserva ambas selecciones en orden');
vcheck($catalogo::coincideRegistro($r,'WHATSAPP')&&$catalogo::coincideRegistro($r,'PROSPECTO_POTENCIAL'),'Filtro encuentra primer o segundo resultado');
vcheck(!$catalogo::coincideRegistro($r,'SMS'),'No inventa un envío no registrado');
$v2=$svc->obtener(8,'pbx-catalogo-both')['registro'];
vcheck(count($catalogo::seleccionesRegistro($v2))===3&&$catalogo::coincideRegistro($v2,'SMS'),'Consulta respeta los tres datos históricos V2');
$legacy=$svc->obtener(8,'pbx-prospecto-001')['registro'];
vcheck($catalogo::seleccionesRegistro($legacy)===['SOLICITA_INFORMACION'],'Histórico legado no recibe acciones nuevas');
$falseOwner=array_replace($r,['usuario_id'=>9]);$falseExt=array_replace($r,['extension'=>'999']);$falseRef=array_replace($r,['pbx_call_id'=>'out-ajeno']);
foreach([$falseOwner,$falseExt,$falseRef] as $bad){unset($bad['catalogo_version'],$bad['resultados_seleccionados']);$got=$catalogo::completarRegistro($db,$bad);vcheck(!isset($got['resultados_seleccionados']),'Un ID no basta para consultar detalles de otro propietario o tramo');}
$list=$svc->listar(8,[],false);$entry=array_values(array_filter($list,static fn($c)=>$c['pbx_call_id']==='pbx-doble-prueba-3'))[0];
vcheck($entry['registro']['resultados_seleccionados']===['PROSPECTO_POTENCIAL','WHATSAPP'],'Historial propio recibe las dos etiquetas por lotes');
$day=substr($entry['fecha'],0,10);$period=['fecha_desde'=>$day,'fecha_hasta'=>$day];
$repo=new VentasActividadRepository($db);$rows=$repo->llamadas([8],$period);$entry=array_values(array_filter($rows,static fn($c)=>$c['pbx_call_id']==='pbx-doble-prueba-3'))[0];
vcheck($entry['registro']['resultados_seleccionados']===['PROSPECTO_POTENCIAL','WHATSAPP'],'Repositorio de reportes incluye detalle autorizado');
$metrics=VentasActividadMetricas::calcular([$entry,$entry],[['id'=>8,'nombre_completo'=>'Asesor Uno']],$period);
vcheck($metrics['resumen']['llamadas']===1&&$metrics['resumen']['registradas']===1&&$metrics['resumen']['conversaciones_confirmadas']===1,'Una llamada con dos resultados sigue contando una sola vez');
vcheck($metrics['resultados']['PROSPECTO_POTENCIAL']===1&&$metrics['resultados']['WHATSAPP']===1,'Distribución muestra cada resultado sin multiplicar llamadas');
// Infraestructura de acceso sintética. Ninguna credencial ni usuario de producción.
$db->query('ALTER TABLE usuarios ADD foto_perfil VARCHAR(100) NULL');
$db->query('CREATE TABLE roles(id INT PRIMARY KEY,estado INT)');$db->query('INSERT INTO roles VALUES(1,1),(2,1),(3,1),(4,1),(6,1)');
$db->query("INSERT INTO usuarios(id,estado,rol_id,nombre,apellidos) VALUES(1,1,1,'Admin','Prueba'),(2,1,2,'Dirección','Prueba'),(20,1,6,'Cuenta Clave','Uno'),(21,1,6,'Cuenta Clave','Dos')");
$db->query('CREATE TABLE estados(id INT PRIMARY KEY,nombre VARCHAR(80),estado INT)');$db->query("INSERT INTO estados VALUES(17,'Estado de prueba',1)");
$db->query('CREATE TABLE asignaciones_territorio(id INT PRIMARY KEY,usuario_id INT,estado_id INT,tipo_asignacion VARCHAR(30),cuenta_clave_asignacion_id INT NULL,activo INT,fecha_inicio DATE NULL,fecha_fin DATE NULL)');
$db->query("INSERT INTO asignaciones_territorio VALUES(100,20,17,'CUENTA_CLAVE',NULL,1,NULL,NULL),(101,8,17,'ASESOR',100,1,NULL,NULL),(200,21,17,'CUENTA_CLAVE',NULL,1,NULL,NULL),(201,9,17,'ASESOR',200,1,NULL,NULL)");
$codes=['llamadas.supervision.ver','llamadas.supervision.audio','reportes.ver','reportes.ventas','reportes.ventas.global','reportes.ventas.equipo','reportes.ventas.propio'];
foreach($codes as $i=>$p){$id=100+$i;$s=$db->prepare('INSERT INTO permisos VALUES(?,?,1)');$s->bind_param('is',$id,$p);$s->execute();$s->close();foreach([1,2,3,6] as $role)$db->query("INSERT INTO rol_permisos VALUES($role,$id)");}
if(!$db->query("SHOW COLUMNS FROM telefonia_contactos_personales LIKE 'proxima_accion_at'")->num_rows)$db->query('ALTER TABLE telefonia_contactos_personales ADD proxima_accion_at DATETIME NULL');
if(!$db->query("SHOW COLUMNS FROM telefonia_ventas_resultados LIKE 'recordatorio_atendido_at'")->num_rows)$db->query('ALTER TABLE telefonia_ventas_resultados ADD recordatorio_atendido_at DATETIME NULL');
$f=['area'=>'asesores','persona_id'=>8,'desde'=>$day,'hasta'=>$day,'direccion'=>'','resultado'=>'WHATSAPP','buscar'=>'','pagina'=>1];
$sup=new SupervisionLlamadasRepository($db);$before=$db->query('SELECT * FROM telefonia_ventas_resultados ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$beforeDetails=$db->query('SELECT * FROM telefonia_ventas_resultado_detalles ORDER BY resultado_id')->fetch_all(MYSQLI_ASSOC);
$beforeSelections=$db->query('SELECT * FROM telefonia_ventas_resultado_selecciones ORDER BY resultado_id,posicion')->fetch_all(MYSQLI_ASSOC);
foreach([1,2,20] as $uid){$out=$sup->listado($uid,$f);vcheck($out['total']>0,'Administrador, Dirección y Cuenta Clave propio pueden filtrar el segundo resultado: '.$uid);
 $c=array_values(array_filter($out['llamadas'],static fn($c)=>$c['pbx_call_id']==='pbx-doble-prueba-3'))[0];vcheck(str_contains($c['resultado_texto'],'Prospecto potencial')&&str_contains($c['resultado_texto'],'WhatsApp'),'Supervisión presenta las dos etiquetas');
 $detail=$sup->obtener($uid,$c['key']);vcheck($detail['resultado_texto']===$c['resultado_texto'],'Detalle y listado de Supervisión coinciden');}
vreject(fn()=>$sup->listado(21,$f),'Cuenta Clave del mismo estado pero otro equipo no puede consultar');
vreject(fn()=>$sup->obtener(21,'venta:'.$entry['id']),'Tampoco accede por identificador directo');
vreject(fn()=>$sup->listado(8,$f),'El asesor no obtiene supervisión por permiso aislado');
$service=new VentasActividadService($db);$filters=['periodo'=>'personalizado','fecha_desde'=>$day,'fecha_hasta'=>$day,'persona_id'=>8,'resultado'=>'WHATSAPP'];
foreach([[1,1],[2,2],[20,6],[8,3]] as [$uid,$role]){$_SESSION=['usuario_id'=>$uid,'rol_id'=>$role,'nombre'=>'Prueba','rol'=>'Prueba'];$d=$service->preparar($filters,false);vcheck(count($d['llamadas'])>0,'Reportes por segundo resultado en alcance autorizado');foreach($d['llamadas'] as $c)vcheck($catalogo::coincideRegistro($c['registro'],'WHATSAPP'),'Todas las filas cumplen el resultado seleccionado');}
$_SESSION=['usuario_id'=>21,'rol_id'=>6];vreject(fn()=>$service->preparar($filters,false),'Reporte no permite otro equipo aunque comparta estado');
$_SESSION=['usuario_id'=>8,'rol_id'=>3];vreject(fn()=>$service->preparar(array_replace($filters,['persona_id'=>9]),false),'Asesor no cambia a otra persona por URL');
vcheck($before===$db->query('SELECT * FROM telefonia_ventas_resultados ORDER BY id')->fetch_all(MYSQLI_ASSOC),'Consultas no modifican resultados originales');
vcheck($beforeDetails===$db->query('SELECT * FROM telefonia_ventas_resultado_detalles ORDER BY resultado_id')->fetch_all(MYSQLI_ASSOC)&&$beforeSelections===$db->query('SELECT * FROM telefonia_ventas_resultado_selecciones ORDER BY resultado_id,posicion')->fetch_all(MYSQLI_ASSOC),'Los detalles y ambas selecciones también permanecen intactos');
$_SESSION=['usuario_id'=>1,'rol_id'=>1,'nombre'=>'Supervisor','rol'=>'Administrador'];$d=$service->preparar($filters,false);
vcheck($sup->listado(1,$f)['total']===count($d['llamadas']),'Supervisión y Reportes incluyen el mismo corte de WhatsApp, incluido el resultado legado explícito');
$html=(new VentasActividadPdfView())->html($d,'','');vcheck(str_contains($html,'Prospecto potencial · Se envió por WhatsApp'),'PDF de Reportes conserva ambos resultados en el detalle');
vcheck(str_contains($html,'Las categorías no se suman como llamadas adicionales'),'PDF explica el conteo de categorías');
if($out=getenv('IMPE_TEST_CONSULTA_OUT')){
    $d['ranking']=array_map(static fn($p)=>$p+['posicion'=>1,'indice'=>null],$d['personas_resumen']);$d['reconocimientos']=[];$d['criterios']=['Los resultados múltiples no duplican llamadas.'];
    if(!is_dir($out))mkdir($out,0700,true);
    file_put_contents($out.'/datos.json',json_encode(['actividad'=>$d,'historial'=>[$entry],'supervision'=>$sup->listado(1,$f)['llamadas']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}
echo "Consulta múltiple: históricos, propietario, lotes, filtros, roles, equipo, métricas y PDF coherentes; sin escrituras.\n";
