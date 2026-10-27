<?php
putenv('IMPE_BASE_URL=http://ventas.test/');
require __DIR__.'/../config/config.php';
require ROOT_PATH.'/app/services/VentasActividadMetricas.php';require ROOT_PATH.'/app/helpers/VentasActividadAccess.php';
$modo=$argv[1]??'html';$esVistaDesempeno=in_array($modo,['desempeno','pdf_desempeno','analistas'],true);
$role=str_starts_with($argv[2]??'','global')?2:3;
$_SESSION=['usuario_id'=>301,'rol_id'=>$role,'rol'=>$role===3?'Asesor de Ventas':'Director de Operaciones','nombre'=>'Persona','apellidos'=>'de prueba',
    'permisos'=>['reportes.ver','reportes.ventas','reportes.ventas.propio','reportes.ventas.global','reportes.exportar','desempeno.ver','desempeno.ver_propio','desempeno.ver_global','desempeno.exportar']];
$people=[['id'=>301,'nombre_completo'=>'María Fernanda García Hernández','foto_perfil'=>'','activo'=>1]];
if ($role===2) $people[]=['id'=>302,'nombre_completo'=>'Asesor de prueba sin actividad','foto_perfil'=>'','activo'=>1];
$period=VentasActividadMetricas::periodo(['periodo'=>'semana']);
if (($argv[2]??'')==='global_long') {
    $hoy=new DateTimeImmutable('today',new DateTimeZone('America/Mexico_City'));
    $period=VentasActividadMetricas::periodo(['periodo'=>'personalizado','fecha_desde'=>$hoy->modify('-365 days')->format('Y-m-d'),'fecha_hasta'=>$hoy->format('Y-m-d')]);
}
$rows=[];$today=$period['fecha_hasta'];
$count=str_starts_with($argv[1]??'','pdf')?90:32;
for($i=0;$i<$count;$i++) $rows[]=['id'=>$i+1,'usuario_id'=>301,'extension'=>'150','pbx_call_id'=>'fixture-call-'.str_pad((string)$i,6,'0',STR_PAD_LEFT),
    'fecha'=>$today.' 10:'.sprintf('%02d',$i%60).':00','direccion'=>$i%2?'Entrante':'Saliente','conectada'=>true,'finalizada'=>true,'segundos'=>$i===1?null:13,
    'nombre'=>'Prospecto de prueba','numero'=>'5550000000','origen_extension'=>$i%2?'100':'','solicitud_id'=>$i%2?1:null,
    'registro'=>['resultado'=>$i%3?'INTERESADO':'CONVERSACION_PERSONA','prospecto_nombre'=>'Prospecto de prueba',
        'observacion'=>$i===0?str_repeat('Información de seguimiento con observaciones extensas para verificar el salto de página. ',20):'Observación <script>no ejecutar</script> de prueba.']];
$actividad=VentasActividadMetricas::calcular($rows,$people,$period)+['area'=>'asesores','area_label'=>'Asesores','es_desempeno'=>$esVistaDesempeno,
    'vista'=>$role===3?'propio':'global','periodo'=>$period,'personas'=>$people,'persona_id'=>$role===3?301:0,'direccion'=>'','resultado'=>'','generado'=>true,
    'generado_por'=>'Persona de prueba','generado_por_rol'=>$_SESSION['rol'],'fecha_generacion'=>date('d/m/Y H:i'),
    'organizacion'=>['disponible'=>true,'consultado_at'=>'26/10/2026 12:00','programadas'=>2,'vencidas'=>1,'devoluciones'=>1,'devoluciones_vencidas'=>1,'personas'=>[301=>['programadas'=>2,'vencidas'=>1,'devoluciones'=>1,'devoluciones_vencidas'=>1]]] ];
if ($esVistaDesempeno) {
    require ROOT_PATH.'/app/services/VentasDesempenoService.php';
    $actividad=VentasDesempenoService::presentar($actividad+['estado_id'=>0,'territorios'=>[['id'=>1,'nombre'=>'Morelos']]]);
}
if (str_starts_with($argv[1]??'','pdf')) {
    if ($argv[1]==='pdf_analista') {
        $actividad=array_replace($actividad,['area'=>'analistas','area_label'=>'Analistas','vista'=>'propio',
            'ranking'=>[['nombre_completo'=>'Analista de prueba','llamadas_realizadas'=>10,'llamadas_con_contacto'=>4,'tasa_contacto'=>40,
                'interacciones'=>12,'llamadas_efectivas'=>3,'indice'=>null]],
            'resumen'=>['llamadas_realizadas'=>10,'llamadas_con_contacto'=>4,'tasa_contacto'=>40,'interacciones'=>12,'llamadas_efectivas'=>3],
            'criterios'=>[],'tendencia'=>[],'territorios'=>[],'reconocimientos'=>[]]);
    }
    require ROOT_PATH.'/app/services/DesempenoPdfService.php';
    $pdf=(new DesempenoPdfService())->generar($actividad);
    if (!$pdf['ok']) throw new RuntimeException(json_encode($pdf));
    file_put_contents($argv[3],$pdf['contenido_pdf']);echo $pdf['nombre_archivo'];exit;
}
$desempeno=true;$errorActividad='';$urlActividadPdf=BASE_URL.'index.php?'.http_build_query(['controller'=>$esVistaDesempeno?'desempeno':'reporte','action'=>$esVistaDesempeno?'exportarPdf':'ventasPdf','area'=>$modo==='analistas'?'analistas':'asesores','periodo'=>'semana','persona_id'=>$role===3?301:0]);
echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/bootstrap.css"><link rel="stylesheet" href="/public/css/global.css"><link rel="stylesheet" href="/public/css/dashboard.css"><link rel="stylesheet" href="/icons/bootstrap-icons.css"><body style="padding:16px;background:#f4f7fa">';
if ($esVistaDesempeno) {
    $desempeno=$actividad;$urlExportarPdf=$urlActividadPdf;$errorDesempeno='';
    echo '<link rel="stylesheet" href="/public/css/desempeno.css">';
    if ($modo==='analistas') {
        $desempeno['area']='analistas';$desempeno['area_label']='Analistas';
        $desempeno['ranking']=[];$desempeno['resumen']=[];$desempeno['reconocimientos']=[];$desempeno['tendencia']=[];
    }
    require ROOT_PATH.'/app/views/desempeno/index.php';
    echo '<script src="/public/javascript/desempeno.js"></script>';
} else require ROOT_PATH.'/app/views/reportes/ventas_actividad.php';
echo '</body></html>';
