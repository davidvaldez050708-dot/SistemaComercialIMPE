<?php
// Vista real con datos simulados, para revisión visual sin una base de datos.
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing')exit(2);
parse_str($argv[1]??'',$_GET);$_GET+=['controller'=>'prospecto','action'=>'index'];
require __DIR__.'/../config/config.php';require __DIR__.'/../app/helpers/PermissionHelper.php';
$_SESSION=['usuario_id'=>4,'rol_id'=>5,'rol'=>'Marketing','nombre'=>'Prueba','apellidos'=>'Marketing','permisos'=>['prospectos.ver_todos']];
if(($_GET['_case']??'')==='denied'){require __DIR__.'/../app/views/prospectos/acceso_denegado.php';exit;}
$tituloPagina='Prospectos';$subtituloPagina='Consulta y organiza los prospectos registrados por estado.';$opcionActiva='prospectos';$mensajeError='';$errorTerritorial='';$errorProcedencia='';$buscarProspectos=$_GET['buscar']??'';$buscarEstadoProspectos=$_GET['buscar_estado']??'';$aliadoFiltroProspectos=$_GET['aliado_id']??'';
$fixture=file_get_contents(__DIR__.'/prospectos_fase1_fixture.php');preg_match('/\$nombres=(\[.*?\]);/s',$fixture,$match);$nombres=eval('return '.$match[1].';');$territoriosProspectos=[];
foreach($nombres as $index=>$nombre){$id=$index+1;$territoriosProspectos[]=['id'=>$id,'nombre'=>$nombre,'total'=>[17=>29,23=>26,11=>3,14=>1][$id]??0];}
$resumenProspectos=['total'=>62,'aliados'=>20,'generales'=>42];$estadosConRegistros=4;$estadoProspectos=null;$vistaProspectos=($_GET['vista']??'')==='todos'?'todos':'estados';
$state=isset($_GET['estado_id'])?(int)$_GET['estado_id']:null;
foreach($territoriosProspectos as $territorio)if($state===$territorio['id'])$estadoProspectos=$territorio;
$mostrarTablaProspectos=$state!==null||$vistaProspectos==='todos';$total=$state!==null?($estadoProspectos['total']??0):62;$pagina=max(1,min((int)($_GET['pagina']??1),max(1,(int)ceil($total/25))));
$registros=[];for($i=0;$i<min(25,max(0,$total-($pagina-1)*25));$i++)$registros[]=['id'=>$i+1,'nombre'=>'Prospecto','apellido'=>'Prueba','created_at'=>'2026-10-23 12:00:00','correo'=>'prueba@example.test','movil'=>'7771234567','movil_secundario'=>'','tel_recomendado'=>'','aliado_nombre'=>'Aliado de otra entidad','perfil_interes'=>'Bachillerato General','estado'=>$estadoProspectos['nombre']??'Morelos','municipio'=>'Cuernavaca','lugar_laboras'=>'Trabajo','cargo_puesto'=>'Cargo','fecha_nacimiento'=>'1990-01-01','origen'=>'PUBLICO'];
$listado=['registros'=>$registros,'total'=>$total,'pagina'=>$pagina,'paginas'=>max(1,(int)ceil($total/25)),'aliados'=>[['id'=>7,'nombre'=>'Aliado de otra entidad']]];
if($state!==null&&!$estadoProspectos)$mensajeError='Estado no encontrado. Selecciona un estado activo del catálogo.';
if(($_GET['_case']??'')==='origin'){$resumenProspectos['aliados']=$resumenProspectos['generales']=null;$errorProcedencia='No está disponible la información de procedencia. Los indicadores de aliados y formularios generales no pueden verificarse.';}
if(($_GET['_case']??'')==='territory'){$territoriosProspectos=[];$estadosConRegistros=null;$errorTerritorial='No fue posible cargar la información territorial. Intenta nuevamente.';}
if(($_GET['_case']??'')==='error'){$mostrarTablaProspectos=true;$mensajeError='No fue posible consultar los registros. Intenta nuevamente.';$listado['registros']=[];}
ob_start();require __DIR__.'/../app/views/layout/dashboard_head.php';require __DIR__.'/../app/views/layout/sidebar.php';require __DIR__.'/../app/views/layout/topbar.php';require __DIR__.'/../app/views/prospectos/index.php';require __DIR__.'/../app/views/layout/dashboard_footer.php';$html=ob_get_clean();
if(getenv('IMPE_PROSPECTOS_HOSTED')==='1'){
    $script=file_get_contents(__DIR__.'/../tools/publicar_tipografia_prospectos_v3_hostinger.sh');preg_match('/css = """(.*?)"""/s',$script,$css);$html=str_replace('.prospectos-page tbody tr:hover', $css[1]."\n.prospectos-page tbody tr:hover",$html);
}
echo $html;
