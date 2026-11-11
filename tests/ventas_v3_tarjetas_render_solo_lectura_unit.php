<?php
/**
 * Renderiza únicamente la vista alternativa V3 con datos ficticios.
 * NO utiliza BD ni sistema de sesión, no carga panel de producción.
 */
define('BASE_URL','/');
require_once __DIR__.'/../app/services/EmbudoComercialPoliticaPropuesta.php';
function testVista($cond,string $mensaje): void {
    if(!$cond) throw new RuntimeException('RENDER V3: '.$mensaje);
}
$etapas=EmbudoComercialPoliticaPropuesta::ETAPAS_ACTIVAS;
$columnas=[];
foreach($etapas as $clave=>$nombre) {
    $columnas[$clave]=[
        'etiqueta'=>$nombre,'total'=>0,'registros'=>[],'truncado'=>false
    ];
}
$columnas['REVISION_LEGACY']=[
    'etiqueta'=>'Clasificación pendiente','total'=>1,
    'registros'=>[[
        'nombre_completo'=>'Prospecto legado','etapa_original'=>'INTERESADO',
        'intentos_hoy'=>['intentos'=>2,'pendientes'=>1,'fecha'=>'2026-11-11']
    ]],'truncado'=>false
];
$columnas['NUEVO']['total']=1;
$columnas['NUEVO']['registros']=[[
    'nombre_completo'=>'<script>alert("X")</script>',
    'programas'=>'Bachillerato ficticio',
    'subetapa'=>'INTENTO_2',
    'proxima_accion_at'=>'2026-11-13 23:00:00',
    'revision_fecha'=>null,
    'intentos_hoy'=>['intentos'=>2,'pendientes'=>1,'fecha'=>'2026-11-11']
]];
$columnas['SEGUIMIENTO']['total']=1;
$columnas['SEGUIMIENTO']['registros']=[[
    'nombre_completo'=>'Seguimiento ficticio',
    'programas'=>'Programa prueba',
    'subetapa'=>'CONTACTO_ACORDADO',
    'proxima_accion_at'=>'2026-11-13 23:00:00',
    'revision_fecha'=>null,
    'intentos_hoy'=>['intentos'=>0,'pendientes'=>3,'fecha'=>'2026-11-11']
]];
$tablero=[
    'dia_indicadores'=>'2026-11-11',
    'columnas'=>$columnas,'total_activos'=>3,
    'externos'=>['RECUPERACION'=>0,'DESCARTADO'=>0],
    'modo'=>'PREVISUALIZACION_SIN_ESCRITURAS'
];
$filtros=[
    'origen'=>'','programa'=>'','buscar'=>'','vencidos'=>false,
    'asesor'=>0
];
$opciones=['programas'=>[],'asesores'=>[]];
$error='';
$disponible=true;
$vista='embudo';
ob_start();
require __DIR__.'/../app/views/embudo_comercial/previsualizacion_v3.php';
$html=(string)ob_get_clean();
foreach([
    'Vista previa de las cinco etapas',
    'Sin contacto hoy',
    '2/3',
    'aria-valuenow="2"',
    'Primer intento', // La ausencia de esta opción específica se revisa abajo
] as $expected) {
    if($expected==='Primer intento') continue;
    testVista(strpos($html,$expected)!==false,'falta '.$expected);
}
testVista(strpos($html,'Segundo intento sin contacto')!==false,
    'subetapa real se presenta');
testVista(strpos($html,'Contacto acordado')!==false,
    'otra subetapa en Seguimiento');
testVista(strpos($html,'13 nov 2026')!==false,
    'próxima tarea visible en horario de México');
testVista(strpos($html,'&lt;script&gt;')!==false,
    'contenido HTML escapado antes de presentarse');
testVista(strpos($html,'<script>alert("X")</script>')===false,
    'sin HTML peligroso');
testVista(strpos($html,'Clasificación pendiente: 1')!==false,
    'casos legacy visibles fuera de las cinco columnas');
testVista(strpos($html,'IMPE_VENTAS_V3_PILOTO')===false,
    'la vista de supervisor no cambia configuración de piloto');
testVista(strpos($html,'embudo_v3_indicadores.css')!==false,
    'cargar estilos exclusivos de vista previa');
foreach(['draggable="true"','data-mover-tarjeta',
    'impe-embudo-mover-form','data-telefonia-marcar',
    'javascript:','<form method="post"'] as $forbidden) {
    testVista(strpos($html,$forbidden)===false,
        'no emitir controles operativos ni XSS: '.$forbidden);
}
$repo=file_get_contents(__DIR__.'/../app/services/EmbudoComercialRepository.php');
$service=file_get_contents(__DIR__.'/../app/services/EmbudoComercialV3IndicadoresVista.php');
testVista(strpos($repo,'intentosDelDia(array_values($idsVisibles),$diaLocal)')!==false,
    'consultar un único lote de hasta 120 asignaciones');
foreach(['->begin_transaction(', '->commit(', '->rollback(',
    'INSERT INTO ', 'UPDATE ', 'DELETE FROM ', 'ALTER TABLE ', 'CREATE TABLE '] as $write) {
    testVista(strpos($service,$write)===false,
        'el lector PBX no modifica datos: '.$write);
}
echo "CHECK OK: cinco etapas, 2/3 PBX, subetapas, próxima acción México, escapado HTML, clases aisladas y sin controles de llamada o arrastre.\n";
