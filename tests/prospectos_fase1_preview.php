<?php
// Vista real con datos simulados, para revisión visual sin una base de datos.
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing')exit(2);
parse_str($argv[1]??'',$_GET);$_GET+=['controller'=>'prospecto','action'=>'index'];
require __DIR__.'/../config/config.php';require __DIR__.'/../app/helpers/PermissionHelper.php';
$_SESSION=['usuario_id'=>4,'rol_id'=>5,'rol'=>'Marketing','nombre'=>'Prueba','apellidos'=>'Marketing','permisos'=>['prospectos.ver_todos']];
if(($_GET['_role']??'')==='clave'){$_SESSION['rol']='Cuenta Clave';$_SESSION['rol_id']=6;}
if(($_GET['_case']??'')==='denied'){require __DIR__.'/../app/views/prospectos/acceso_denegado.php';exit;}
$tituloPagina='Prospectos';$subtituloPagina='Consulta y organiza los prospectos registrados por estado.';$opcionActiva='prospectos';$mensajeError='';$errorTerritorial='';$errorProcedencia='';$buscarProspectos=$_GET['buscar']??'';$buscarEstadoProspectos=$_GET['buscar_estado']??'';$aliadoFiltroProspectos=$_GET['aliado_id']??'';
$fixture=file_get_contents(__DIR__.'/prospectos_fase1_fixture.php');preg_match('/\$nombres=(\[.*?\]);/s',$fixture,$match);$nombres=eval('return '.$match[1].';');$territoriosProspectos=[];
foreach($nombres as $index=>$nombre){$id=$index+1;$territoriosProspectos[]=['id'=>$id,'nombre'=>$nombre,'total'=>[11=>29,22=>6][$id]??0];}
$resumenProspectos=['total'=>62,'aliados'=>40,'generales'=>22];$estadosConRegistros=2;$estadoProspectos=null;$vistaProspectos=($_GET['vista']??'')==='todos'?'todos':'estados';
$state=isset($_GET['estado_id'])?(int)$_GET['estado_id']:null;
foreach($territoriosProspectos as $territorio)if($state===$territorio['id'])$estadoProspectos=$territorio;
$programaFiltroProspectos=$_GET['programa']??'';$errorProgramasProspectos='';$errorAliadosProspectos='';
$mostrarTablaProspectos=$state!==null||$vistaProspectos==='todos';
$clave=static fn($origen,$valor)=>$origen.':'.rtrim(strtr(base64_encode($valor),'+/','-_'),'=');
$registros=[];$contexto=[];$aliados=[];$programas=[];
for($i=62;$i>=1;$i--){
 $aliado=$i<=40?($i<=20?7:($i<=29?8:($i<=35?9:$i-26))):null;
 $territorioAliado=[7=>11,8=>11,9=>22][$aliado]??null;
 if($state!==null&&$territorioAliado!==$state)continue;
 $codigo=$i>=27&&$i<=29?'bachillerato-2-anos':($i>=30&&$i<=35?'ingles':'bachillerato-general');
 $perfil=$i>=41&&$i<=45?'Inglés':($i>=54&&$i<=61?'Bachillerato general':($i===62?'Perfil histórico ¿ñ?':'Bachillerato General'));
 $nombre=$i===1?'<img src=x onerror=alert(1)>':'Prospecto '.$i;$correo='prueba'.$i.'@example.test';
 $residencia=$i<=29?'Morelos':($i<=55?'Quintana Roo':($i<=58?'Guanajuato':($i===59?'Jalisco':null)));
 $registros[]=['id'=>$i,'nombre'=>$nombre,'apellido'=>'Prueba','created_at'=>'2026-10-23 12:00:00','correo'=>$correo,'movil'=>'7771234567','movil_secundario'=>'','tel_recomendado'=>'','aliado_id'=>$aliado,'aliado_nombre'=>$aliado===null?null:'Aliado '.$aliado,'programa_codigo'=>$codigo,'perfil_interes'=>$perfil,'estado'=>$residencia,'municipio'=>'Cuernavaca','lugar_laboras'=>'Trabajo','cargo_puesto'=>'Cargo','fecha_nacimiento'=>'1990-01-01','origen'=>'PUBLICO'];
 if($aliado!==null&&$aliado<=13)$aliados[$aliado]=['id'=>$aliado,'nombre'=>in_array($aliado,[7,8])?'Institución duplicada':'Aliado '.$aliado];
}
foreach($registros as $registro){
 if($aliadoFiltroProspectos==='general'&&$registro['aliado_id']!==null)continue;
 if(ctype_digit($aliadoFiltroProspectos)&&(int)$aliadoFiltroProspectos!==$registro['aliado_id'])continue;
 $tipo=$registro['aliado_id']===null?'g':'a';$valor=$tipo==='g'?$registro['perfil_interes']:$registro['programa_codigo'];$key=$clave($tipo,$valor);
 $nombrePrograma=$tipo==='g'?'Registro general · '.$valor:'Aliados · '.(['bachillerato-general'=>'Bachillerato General','bachillerato-2-anos'=>'Bachillerato en 2 años','ingles'=>'Inglés'][$valor]??$valor);
 $programas[$key]=['valor'=>$key,'nombre'=>$nombrePrograma];$registro['filtro_programa']=$key;$contexto[]=$registro;
}
if(!isset($programas[$programaFiltroProspectos]))$programaFiltroProspectos='';
$registros=array_values(array_filter($contexto,static function($r)use($programaFiltroProspectos,$buscarProspectos){
 if($programaFiltroProspectos!==''&&$r['filtro_programa']!==$programaFiltroProspectos)return false;
 return $buscarProspectos===''||array_filter([$r['nombre'],$r['apellido'],$r['movil'],$r['correo']],fn($value)=>mb_stripos($value,$buscarProspectos)!==false);
}));
usort($aliados,static fn($a,$b)=>strcasecmp($a['nombre'],$b['nombre'])?:((int)$a['id']<=>(int)$b['id']));
$total=count($registros);$pagina=max(1,min((int)($_GET['pagina']??1),max(1,(int)ceil($total/25))));
$listado=['registros'=>array_slice($registros,($pagina-1)*25,25),'total'=>$total,'pagina'=>$pagina,'paginas'=>max(1,(int)ceil($total/25)),'aliados'=>array_values($aliados),'programas'=>array_values($programas)];
if(($_GET['_case']??'')==='programs'){$listado['programas']=[];$errorProgramasProspectos='No fue posible cargar los programas.';}
if($state!==null&&!$estadoProspectos)$mensajeError='Estado no encontrado. Selecciona un estado activo del catálogo.';
if(($_GET['_case']??'')==='origin'){$resumenProspectos['aliados']=$resumenProspectos['generales']=null;$listado['programas']=[];$errorProgramasProspectos='Información de procedencia no disponible.';$errorProcedencia='No está disponible la información de procedencia. Los indicadores de aliados y formularios generales no pueden verificarse.';}
if(($_GET['_case']??'')==='territory'){$territoriosProspectos=[];$estadosConRegistros=null;$errorTerritorial='No fue posible cargar la información territorial. Intenta nuevamente.';}
if(($_GET['_case']??'')==='error'){$mostrarTablaProspectos=true;$mensajeError='No fue posible consultar los registros. Intenta nuevamente.';$listado['registros']=[];}
ob_start();require __DIR__.'/../app/views/layout/dashboard_head.php';require __DIR__.'/../app/views/layout/sidebar.php';require __DIR__.'/../app/views/layout/topbar.php';require __DIR__.'/../app/views/prospectos/index.php';require __DIR__.'/../app/views/layout/dashboard_footer.php';$html=ob_get_clean();
if(getenv('IMPE_PROSPECTOS_HOSTED')==='1'){
    $script=file_get_contents(__DIR__.'/../tools/publicar_tipografia_prospectos_v3_hostinger.sh');preg_match('/css = """(.*?)"""/s',$script,$css);$html=str_replace('.prospectos-page tbody tr:hover', $css[1]."\n.prospectos-page tbody tr:hover",$html);
}
echo $html;
