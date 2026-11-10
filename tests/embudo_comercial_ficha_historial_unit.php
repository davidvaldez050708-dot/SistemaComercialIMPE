<?php
// Ficha de Gestión comercial: solo presentaciones, no MySQL ni Telefonía real.
require_once __DIR__.'/../app/services/EmbudoComercialPresentacion.php';
function testEmbudo($ok,$mensaje){if (!$ok) throw new RuntimeException('ERROR: '.$mensaje);}

testEmbudo(EmbudoComercialPresentacion::fechaUtc('2026-11-12 23:00:00')===
    '12 nov 2026, 5:00 p. m.','UTC de próxima acción -> México');
testEmbudo(EmbudoComercialPresentacion::fechaUtc('2026-11-10 14:35:33')===
    '10 nov 2026, 8:35 a. m.','UTC de movimiento -> México');
testEmbudo(EmbudoComercialPresentacion::fechaUtc(null)==='Sin programar',
    'fecha nula');
testEmbudo(EmbudoComercialPresentacion::fechaLocal('2026-11-11')==='11 nov 2026',
    'fecha de revisión sin conversión horaria');
testEmbudo(EmbudoComercialPresentacion::fechaLocal('2026-02-31')==='Fecha no válida',
    'calendario inválido');
testEmbudo(EmbudoComercialPresentacion::fechaUtc('texto arbitrario')==='Fecha no disponible',
    'no imprimir valores arbitrarios como fechas');

// Búsqueda de historial: 10 dígitos hacen match con PBX nacional y +52.
// No modifica el destino de marcación ni el teléfono almacenado.
testEmbudo(EmbudoComercialPresentacion::buscarTelefonoEnLlamadas('+527775083225')===
    '7775083225','México +52 a búsqueda nacional');
testEmbudo(EmbudoComercialPresentacion::buscarTelefonoEnLlamadas('+52 (777) 508-3225')===
    '7775083225','México con separadores');
testEmbudo(EmbudoComercialPresentacion::buscarTelefonoEnLlamadas('527775083225')===
    '7775083225','México sin signo internacional');
testEmbudo(EmbudoComercialPresentacion::buscarTelefonoEnLlamadas('7775083225')===
    '7775083225','nacional sin cambios');
testEmbudo(EmbudoComercialPresentacion::buscarTelefonoEnLlamadas('+12025550123')===
    '+12025550123','internacional fuera de México intacto');
testEmbudo(EmbudoComercialPresentacion::buscarTelefonoEnLlamadas('+5217775083225')===
    '+5217775083225','prefijo de 13 dígitos intacto');
testEmbudo(EmbudoComercialPresentacion::buscarTelefonoEnLlamadas(null)==='',
    'sin teléfono sin búsqueda');
testEmbudo(EmbudoComercialPresentacion::buscarTelefonoEnLlamadas('12345')==='12345',
    'teléfono parcial no reescrito');

if (!defined('BASE_URL')) define('BASE_URL','/');
function tienePermiso($codigo) { return false; }
final class EmbudoComercialRepository {
    public const ETAPAS=[
        'NUEVO'=>'Nuevos','RECUPERACION'=>'En recuperación',
        'SEGUIMIENTO'=>'Seguimiento'
    ];
}
$vista='embudo';$error='';$supervisor=false;
$prospecto=[
    'id'=>14,'nombre_completo'=>'PRUEBA EMBUDO FASE 2A - NO CONTACTAR',
    'etapa'=>'SEGUIMIENTO','telefono_original'=>null,'correo'=>null,
    'pais_nombre'=>'México','estado_provincia'=>null,'ciudad'=>null,
    'asesor_id'=>22,'asignacion_id'=>1,
    'asignado_at'=>'2026-11-09 23:37:36',
    'fecha_trabajo'=>'2026-11-09',
    'proxima_accion_at'=>'2026-11-12 23:00:00',
    'programas'=>[],'origenes'=>[],'preferencias'=>[],
    'historial_truncado'=>false,'historial_movimientos'=>[
        [
            'id'=>2,'etapa_anterior'=>'RECUPERACION','etapa_nueva'=>'SEGUIMIENTO',
            'motivo'=>'Prueba interna <script>alert(1)</script>',
            'usuario_id'=>22,'creado_at'=>'2026-11-10 14:40:37',
            'autor'=>'Asesor <img src=x>'
        ],
        [
            'id'=>1,'etapa_anterior'=>'NUEVO','etapa_nueva'=>'RECUPERACION',
            'motivo'=>'Prueba sin llamada','usuario_id'=>22,
            'creado_at'=>'2026-11-10 14:35:33','autor'=>''
        ]
    ]
];
ob_start();
require __DIR__.'/../app/views/embudo_comercial/detalle.php';
$html=ob_get_clean();
testEmbudo(strpos($html,'Historial de movimientos')!==false,'mostrar sección de historial');
testEmbudo(strpos($html,'2 movimientos')!==false,'mostrar conteo correcto');
testEmbudo(strpos($html,'En recuperación')!==false,'etiqueta de recuperación');
testEmbudo(strpos($html,'Seguimiento')!==false,'etiqueta de seguimiento');
testEmbudo(strpos($html,'10 nov 2026, 8:35 a. m.')!==false,'fecha legible de evento');
testEmbudo(strpos($html,'12 nov 2026, 5:00 p. m.')!==false,'fecha próxima acción');
testEmbudo(strpos($html,'<script>alert(1)</script>')===false,'XSS en motivo bloqueado');
testEmbudo(strpos($html,'&lt;script&gt;')!==false,'escapado de motivo');
testEmbudo(strpos($html,'<img src=x>')===false,'XSS en nombre de autor bloqueado');
testEmbudo(strpos($html,'&lt;img src=x&gt;')!==false,'escapado de autor');
testEmbudo(strpos($html,'Usuario #22')!==false,'fallback cuando falta autor');
testEmbudo(strpos($html,'datetime="2026-11-10T14:40:37Z"')!==false,'fecha UTC semántica');
testEmbudo(strpos($html,'<ol class="impe-embudo-timeline">')!==false,'lista cronológica');

$prospecto['historial_movimientos']=[];
ob_start();require __DIR__.'/../app/views/embudo_comercial/detalle.php';$empty=ob_get_clean();
testEmbudo(strpos($empty,'Todavía no se han registrado cambios de etapa.')!==false,
    'estado vacío de la ficha');
testEmbudo(strpos($empty,'<ol class="impe-embudo-timeline">')===false,
    'sin lista ficticia');

$prospecto['historial_movimientos']=[[
    'id'=>1,'etapa_anterior'=>'NUEVO','etapa_nueva'=>'RECUPERACION',
    'motivo'=>'Prueba','usuario_id'=>22,'creado_at'=>'2026-11-10 14:35:33','autor'=>''
]];
$prospecto['historial_truncado']=true;
ob_start();require __DIR__.'/../app/views/embudo_comercial/detalle.php';$truncated=ob_get_clean();
testEmbudo(strpos($truncated,'Los anteriores permanecen registrados')!==false,
    'avisar truncamiento cuando ocurra');
echo "CHECK OK: horario México, historial, XSS, autor y estado vacío.\n";
