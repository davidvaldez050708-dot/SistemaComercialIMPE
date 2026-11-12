<?php
/**
 * Render real de las bandejas V3, con datos sintéticos y sin conexión SQL.
 * Valida botones habilitados por propiedad/permisos, teléfonos y bloqueos.
 */
define('BASE_URL','/');
require_once __DIR__.'/../app/helpers/EmbudoComercialAcceso.php';
$_SESSION=[
    'usuario_id'=>8,'rol_id'=>3,'permisos'=>[
        'ventas.embudo.ver','ventas.embudo.mover',
        'ventas.recuperacion.ver','ventas.recuperacion.gestionar',
        'ventas.descartados.ver','ventas.descartados.gestionar',
        'telefonia.usar','telefonia.salientes'
    ]
];
function verificarBandejas(bool $ok,string $desc): void {
    if (!$ok) throw new RuntimeException('RENDER BANDEJAS V3: '.$desc);
}
$filtros=[
    'origen'=>'','programa'=>'','buscar'=>'','vencidos'=>false,'asesor'=>0,
    'paginas'=>[]
];
$opciones=['programas'=>[],'asesores'=>[]];
$error='';$disponible=true;
$sample=[
  'asignacion_id'=>151,'prospecto_id'=>700,'asesor_id'=>8,
  'nombre_completo'=>'<script>alert("XSS")</script>',
  'programas'=>'Bachillerato','fecha_trabajo'=>'2026-11-12',
  'revision_fecha'=>'2026-11-13','horario_contacto'=>'17:00-19:00',
  'telefono_original'=>'+527771234567','etapa_original'=>'RECUPERACION',
  'subetapa'=>'PENDIENTE_REVISION'
];
$blocked=array_merge($sample,[
  'asignacion_id'=>152,'prospecto_id'=>701,'nombre_completo'=>'No llamar',
  'telefono_original'=>'+527771234599','subetapa'=>'NO_CONTACTAR'
]);
$legacy=array_merge($sample,[
  'asignacion_id'=>153,'prospecto_id'=>702,'nombre_completo'=>'Legacy en contacto',
  'etapa_original'=>'EN_CONTACTO','subetapa'=>null
]);
$colRecuperacion=[
  'etiqueta'=>'En recuperación','total'=>3,'pagina'=>1,'paginas'=>1,
  'registros'=>[$sample,$blocked,$legacy]
];
$colDescartado=[
  'etiqueta'=>'Descartados','total'=>1,'pagina'=>1,'paginas'=>1,
  'registros'=>[array_merge($sample,[
    'etapa_original'=>'DESCARTADO','subetapa'=>'NO_INTERESADO',
    'asignacion_id'=>154,'prospecto_id'=>703
  ])]
];
$tablero=['columnas'=>[
  'RECUPERACION'=>$colRecuperacion,'DESCARTADO'=>$colDescartado
]];
$vista='recuperacion';
ob_start();
require __DIR__.'/../app/views/embudo_comercial/bandejas_v3.php';
$html=(string)ob_get_clean();
verificarBandejas(substr_count($html,'data-v3b-llamar=')===1,
    'solo un registro de Recuperación válido puede llamar');
verificarBandejas(strpos($html,'data-v3b-llamar=&quot;+527771234599&quot;')===false &&
    strpos($html,'data-v3b-llamar="+527771234599"')===false,
    'No contactar jamás tiene botón de llamada');
verificarBandejas(substr_count($html,'data-v3b-accion="REACTIVAR"')===1,
    'no reactivar No contactar ni legado');
verificarBandejas(strpos($html,'prospecto_asignacion_id=151')!==false,
    'Mis llamadas conserva asignación exacta');
verificarBandejas(strpos($html,'&lt;script&gt;alert')!==false &&
    strpos($html,'<script>alert("XSS")</script>')===false,
    'escape del nombre en tarjeta y dataset');
verificarBandejas(strpos($html,'No contactar')!==false,
    'etiqueta institucional de restricción');
verificarBandejas(strpos($html,'id="impe-v3b-dialog"')!==false,
    'modal de recuperación está disponible con permiso');
verificarBandejas(strpos($html,'value="NO_CONTACTAR"')===false,
    'no habilitar estado sin consentimiento omnicanal');
$vista='descartados';
ob_start();
require __DIR__.'/../app/views/embudo_comercial/bandejas_v3.php';
$descartados=(string)ob_get_clean();
verificarBandejas(strpos($descartados,'data-v3b-llamar=')===false &&
    strpos($descartados,'data-v3b-accion="REACTIVAR"')===false,
    'descartados nunca marca ni reactiva');
verificarBandejas(strpos($descartados,'prospecto_asignacion_id=154')!==false,
    'descartados conserva acceso a historial de llamadas');
echo "CHECK_OK: bandejas reales, PBX, Mis llamadas, legado, XSS y NO_CONTACTAR protegido.\n";
