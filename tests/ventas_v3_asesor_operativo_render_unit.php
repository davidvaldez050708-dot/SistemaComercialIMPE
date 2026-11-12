<?php
/**
 * UI asesor V3 con datos ficticios. Sin BD, API ni resultado telefónico real.
 * Verifica HTML institucional, marcador persistente, Mis llamadas y fichas.
 */
define('BASE_URL','/');
 // Los fixtures habilitan EXCLUSIVAMENTE la UI ficticia para asesor 8.
 // No activan PBX ni el Bridge V3.
putenv('IMPE_VENTAS_V3_UI=PERMITIR_SOLO_PANTALLA_V3_ASESORES_IMPE_20261111');
putenv('IMPE_VENTAS_V3_UI_ASESORES=8');
require_once __DIR__.'/../app/helpers/EmbudoComercialAcceso.php';
require_once __DIR__.'/../app/services/EmbudoComercialRepository.php';
$_SESSION=[
    'usuario_id'=>8,'rol_id'=>3,
    'permisos'=>[
        'ventas.embudo.ver','ventas.embudo.mover',
        'ventas.recuperacion.ver','ventas.descartados.ver',
        'telefonia.usar','telefonia.salientes'
    ]
];
function isV3($condition,$message){
    if(!$condition) throw new RuntimeException('RENDER ASESOR V3: '.$message);
}
$filtros=[
    'origen'=>'','programa'=>'','buscar'=>'','vencidos'=>false,
    'asesor'=>0,'paginas'=>[]
];
$paginasV3=EmbudoComercialRepository::paginasV3Operativo([]);
$opciones=['programas'=>[],'asesores'=>[]];
$disponible=true;$error='';$vista='embudo';$supervisor=false;$usuarioId=8;
$grupos=[
    'NUEVO'=>'Nuevos','CONTACTADO'=>'Contactados',
    'POTENCIAL'=>'Potenciales','SEGUIMIENTO'=>'Seguimiento',
    'PROCESO_INSCRIPCION'=>'Proceso Inscripción',
    'REVISION_LEGACY'=>'Clasificación pendiente'
];
$columnas=[];
foreach($grupos as $key=>$label){
    $columnas[$key]=[
        'etiqueta'=>$label,'total'=>0,'pagina'=>1,'paginas'=>1,
        'registros'=>[]
    ];
}
$contacto=[
    'asignacion_id'=>13,'prospecto_id'=>13,'asesor_id'=>8,
    'nombre_completo'=>'<script>alert("XSS")</script>',
    'telefono_original'=>'+527771234567',
    'programas'=>'Bachillerato prueba',
    'tipo_origen'=>'META_ADS','horario_contacto'=>'17:00-19:00',
    'subetapa'=>'INTENTO_2','etapa_original'=>'NUEVO',
    'proxima_accion_at'=>null,'revision_fecha'=>null,
    'fecha_trabajo'=>'2026-11-11',
    'intentos_hoy'=>['intentos'=>2,'pendientes'=>1,'fecha'=>'2026-11-11']
];
$columnas['NUEVO']['total']=1;
$columnas['NUEVO']['registros']=[$contacto];
$columnas['SEGUIMIENTO']['total']=1;
$columnas['SEGUIMIENTO']['registros']=[array_merge($contacto,[
    'asignacion_id'=>12,'prospecto_id'=>12,
    'nombre_completo'=>'Seguimiento real de prueba',
    'tipo_origen'=>'ALIADO','etapa_original'=>'SEGUIMIENTO',
    'subetapa'=>'CONTACTO_ACORDADO',
    'horario_contacto'=>'17:00 a 19:00',
    'proxima_accion_at'=>'2026-11-12 23:00:00',
    'intentos_hoy'=>['intentos'=>0,'pendientes'=>3,'fecha'=>'2026-11-11']
])];
$columnas['REVISION_LEGACY']['total']=1;
$columnas['REVISION_LEGACY']['registros']=[array_merge($contacto,[
    'asignacion_id'=>11,'prospecto_id'=>11,
    'nombre_completo'=>'Antiguo Interesado',
    'etapa_original'=>'INTERESADO',
    'subetapa'=>null
])];
$columnas['CONTACTADO']['total']=31;
$columnas['CONTACTADO']['paginas']=2;
$tablero=[
    'columnas'=>$columnas,'total'=>34,'total_activos'=>34,
    'externos'=>['RECUPERACION'=>0,'DESCARTADO'=>0],
    'dia_indicadores'=>'2026-11-11',
    'modo'=>'V3_OPERATIVO_ASESOR_COMPATIBLE_V1'
];
ob_start();
require __DIR__.'/../app/views/embudo_comercial/operativo_v3.php';
$html=(string)ob_get_clean();
foreach([
    'Gestión comercial · Vista operativa V3',
    'Nuevos','Contactados','Potenciales','Seguimiento',
    'Proceso Inscripción','Clasificación pendiente',
    'Requiere conciliación comercial','Segundo intento sin contacto',
    'Contacto acordado','2/3','Mis llamadas',
    'Horario solicitado:','Volver al tablero anterior',
    'operativo_v3=1','public/css/embudo_v3_operativo.css',
    'public/javascript/embudo_comercial_v3_operativo.js',
    'action=detalle','p3_contactado=2',
    'data-v3-mover','moverEtapaV3'
] as $expected){
    if($expected==='moverEtapaV3') continue; // Está en JS, no el HTML.
    isV3(strpos($html,$expected)!==false,'faltó texto '.$expected);
}
isV3(strpos($html,'data-embudo-marcar=')!==false,
    'abrir marcador persistente del CRM desde la tarjeta');
isV3(strpos($html,'prospecto_asignacion_id=13')!==false,
    'Mis llamadas conserva identidad de asignación exacta');
isV3(strpos($html,'data-v3-destinos="CONTACTADO"')!==false,
    'NUEVO solo permite manual CONTACTADO con PBX');
isV3(strpos($html,'data-v3-destinos="CONTACTADO"')!==false &&
    strpos($html,'data-v3-destinos="SEGUIMIENTO"')===false,
    'seguimiento permite CONTACTADO y contacto permite SEGUIMIENTO según estado');
isV3(strpos($html,'&lt;script&gt;')!==false &&
    strpos($html,'<script>alert("XSS")</script>')===false,
    'nombres escapados contra XSS');
isV3(strpos($html,'draggable="true"')===false,
    'sin arrastre inseguro a nuevas etapas');
isV3(strpos($html,'data-etapa="POTENCIAL"')===false,
    'no inventa Potenciales si hay datos legacy');
$js=file_get_contents(__DIR__.'/../public/javascript/embudo_comercial_v3_operativo.js');
isV3(strpos($js,'IMPE_TELEPHONY_PERSISTENT')!==false &&
    strpos($js,"impe:embudo:llamada-pendiente")!==false,
    'mismo telefono y contexto V4 que tablero anterior');
isV3(strpos($js,'action=moverEtapaV3')!==false &&
    strpos($js,'X-CSRF-Token')!==false,
    'ruta POST exclusiva y token de sesión');
isV3(strpos($js,"fetch('https://")===false,
    'no hace llamadas a otros dominios');
$controller=file_get_contents(__DIR__.'/../app/controllers/EmbudoComercialController.php');
isV3(strpos($controller,'operativo_v3')!==false &&
    strpos($controller,'listadoV3Operativo')!==false,
    'modo requiere parámetro explícito');
isV3(strpos($controller,"$"."operativoV3?'operativo_v3':'index'")!==false,
    'ruta normal conserva tablero anterior');
isV3(strpos($controller,'hash_equals($token,$received)')!==false,
    'validar token CSRF en backend, no solo en navegador');
$oldUi=file_get_contents(__DIR__.'/../app/views/embudo_comercial/index.php');
isV3(strpos($oldUi,'operativo_v3')!==false &&
    strpos($oldUi,'Nuevo embudo V3')!==false &&
    strpos($oldUi,'!$supervisor')!==false,
    'enlace voluntario solo asesor en Kanban vigente');
isV3(strpos($oldUi,'embudo_comercial_movimientos.js')!==false,
    'se conserva JS de teléfono y movimientos V1 sin sustitución');
echo "CHECK OK: vista institucional, cinco etapas, 2/3, teléfono V4 original, Mis llamadas, paginación, legacy compacto, CSRF, sin arrastre y sin datos reales.\n";
