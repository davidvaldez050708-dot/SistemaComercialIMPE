<?php
// Prueba de render real del enlace Embudo -> Mis llamadas, sin BD ni PBX.
// El enlace conserva la asignación y adapta solo el término de búsqueda.
$_SESSION=[
    'usuario_id'=>22,'rol_id'=>3,'permisos'=>[
        'telefonia.usar','ventas.embudo.ver'
    ]
];
function tienePermiso($codigo) {
    return in_array($codigo,$_SESSION['permisos']??[],true);
}
if (!defined('BASE_URL')) define('BASE_URL','/');
final class EmbudoComercialRepository {
    public const ETAPAS=['NUEVO'=>'Nuevos'];
}
$vista='embudo';$supervisor=false;$error='';$disponible=true;
$filtros=[
    'origen'=>'','programa'=>'','buscar'=>'','vencidos'=>false,
    'paginas'=>[],'asesor'=>0
];
$opciones=['programas'=>[],'asesores'=>[]];
function failUnless($yes,$message) {
    if (!$yes) throw new RuntimeException('TEST FALLIDO: '.$message);
}
function buscarEnHtml($telefono) {
    global $vista,$supervisor,$error,$disponible,$filtros,$opciones,$tablero;
    $tablero=['total'=>1,'columnas'=>[
        'NUEVO'=>[
            'etiqueta'=>'Nuevos','total'=>1,'pagina'=>1,'paginas'=>1,
            'registros'=>[[
                'asignacion_id'=>2,'prospecto_id'=>15,
                'nombre_completo'=>'PROSPECTO PBX CONTROLADO',
                'telefono_original'=>$telefono,
                'programas'=>'Prueba','tipo_origen'=>'FORMULARIO_GENERAL',
                'proxima_accion_at'=>null,'fecha_trabajo'=>'2026-11-10',
                'revision_fecha'=>null
            ]]
        ]
    ]];
    ob_start();
    require __DIR__.'/../app/views/embudo_comercial/index.php';
    $html=ob_get_clean();
    failUnless(preg_match('/href="([^"]+)" title="Consultar y registrar resultado en Mis llamadas"/',$html,$m)===1,
        'debe renderizar enlace a Mis llamadas');
    $url=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
    $query=parse_url($url,PHP_URL_QUERY);
    parse_str((string)$query,$params);
    failUnless(($params['controller']??'')==='telefonia' &&
        ($params['action']??'')==='misLlamadas','destino telefónico correcto');
    failUnless(($params['prospecto_asignacion_id']??'')==='2',
        'la asignación explícita 2 debe mantenerse');
    return $params['q']??null;
}
failUnless(buscarEnHtml('+527775083225')==='7775083225',
    'Mexicano +52 debe buscar nacional de 10 dígitos');
failUnless(buscarEnHtml('52 (777) 508-3225')==='7775083225',
    'prefijo 52 con separadores');
failUnless(buscarEnHtml('7775083225')==='7775083225',
    'formato nacional sin cambio');
failUnless(buscarEnHtml('+12025550123')==='+12025550123',
    'internacional fuera de México intacto');
failUnless(buscarEnHtml('+5217775083225')==='+5217775083225',
    'prefijo largo ambiguo intacto');
failUnless(buscarEnHtml('')==='',
    'sin teléfono se mantiene búsqueda vacía');
echo "CHECK OK: búsqueda PBX México en enlace, asignación 2 intacta, sin cambios de telefonía.\n";
