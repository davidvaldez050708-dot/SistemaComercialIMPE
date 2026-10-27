<?php
putenv('IMPE_BASE_URL=http://director.test/');require __DIR__.'/../config/config.php';
require __DIR__.'/../app/services/VentasActividadMetricas.php';
$_SESSION=['usuario_id'=>20,'nombre'=>'Susana Molina','rol_id'=>2,'rol'=>'Director de Operaciones'];
$modo=$argv[1]??'full';
$p=array_fill_keys(['equipo','territorios','seguimientos','agenda','convenios','convocatorias','aliados','prospectos','reportes','desempeno','llamadas','ventas'],true);
$tableroDireccion=['permisos'=>$p,'resumen'=>['equipo'=>[3=>12,4=>8,6=>3],'territorios'=>32,'seguimientos'=>147,'convenios'=>29,'convocatorias'=>68,'atenciones'=>8],
    'atenciones'=>[],'agenda'=>[],'ventas'=>['resumen'=>['llamadas'=>56,'conectadas'=>42,'conversaciones'=>38,'por_registrar'=>4,'segundos'=>4920,'duraciones_confirmadas'=>39]],'cobertura'=>[],'avisos'=>[],'generado_at'=>'2026-10-26 09:30:00'];
for($i=1;$i<=6;$i++)$tableroDireccion['atenciones'][]=['id'=>$i,'estado_id'=>1,'tipo_atencion'=>$i%2?'atrasado':'hoy','nombre_entidad'=>'Institución de Educación Superior y Formación Profesional <script>no ejecutar</script>','motivo_atencion'=>'Acción programada vencida','analista_nombre'=>'María Fernanda García Hernández','estado_nombre'=>'Baja California Sur','fecha_referencia'=>'2026-10-26 10:00:00'];
for($i=1;$i<=3;$i++)$tableroDireccion['agenda'][]=['id'=>$i,'fecha_propuesta'=>'2026-10-28 12:00:00','nombre_entidad'=>'Universidad de Formación Interdisciplinaria','analista_nombre'=>'Ana María López','estado_nombre'=>'Ciudad de México','estado'=>'SOLICITADA'];
for($i=1;$i<=32;$i++)$tableroDireccion['cobertura'][]=['id'=>$i,'nombre'=>$i===1?'Michoacán de Ocampo':'Estado '.$i,'cuentas'=>1,'analistas'=>2,'asesores'=>3,'seguimientos'=>10];
if($modo==='none')$tableroDireccion=['permisos'=>[],'resumen'=>[],'avisos'=>[]];
if($modo==='limited'){$tableroDireccion['permisos']=['territorios'=>true];$tableroDireccion['resumen']=['territorios'=>32];foreach($tableroDireccion['cobertura'] as &$s)$s['seguimientos']=null;unset($s);}
if($modo==='error')$tableroDireccion=['permisos'=>['seguimientos'=>true,'agenda'=>true],'resumen'=>[],'atenciones'=>null,'agenda'=>null,'avisos'=>['No se pudo actualizar las prioridades de vinculación. Puedes volver a cargar el Inicio.']];
if($modo==='zeros'){$tableroDireccion['resumen']['atenciones']=0;$tableroDireccion['atenciones']=[];$tableroDireccion['agenda']=[];}
echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/bootstrap.css"><link rel="stylesheet" href="/public/css/global.css"><link rel="stylesheet" href="/public/css/dashboard.css"><link rel="stylesheet" href="/public/css/dashboard_analista.css"><link rel="stylesheet" href="/public/css/dashboard_analista_boceto.css"><link rel="stylesheet" href="/public/css/responsive_sistema.css"><link rel="stylesheet" href="/icons/bootstrap-icons.css"><body style="padding:20px;background:#f5f7fa"><main class="admin-content" style="margin:0">';
require ROOT_PATH.'/app/views/dashboard/director_operaciones.php';echo '</main></body></html>';
