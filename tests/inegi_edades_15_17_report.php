<?php
require_once __DIR__ . '/../app/services/InegiEscolaridadAdultaXlsxService.php';
$archivo=$argv[1]??''; $clave=$argv[2]??'';
if(!is_file($archivo)||filesize($archivo)<10000)throw new RuntimeException('Sin XLSX INEGI');
$zip=new ZipArchive();
if($zip->open($archivo,ZipArchive::CHECKCONS)!==true)throw new RuntimeException('XLSX inválido');
$parser=new InegiEscolaridadAdultaXlsxService();
$strings=new ReflectionMethod($parser,'cadenas');
$leer=new ReflectionMethod($parser,'leerFila');
$normalizarEdad=new ReflectionMethod($parser,'normalizarEdad');
$normalizarTexto=new ReflectionMethod($parser,'normalizarTexto');
$entero=new ReflectionMethod($parser,'entero');
$compartidas=$strings->invoke($parser,$zip);
$resultado=[];
try {
for($i=0;$i<$zip->numFiles;$i++){
 $hoja=(string)$zip->getNameIndex($i);
 if(!preg_match('#^xl/worksheets/sheet\d+\.xml$#i',$hoja))continue;
 $reader=new XMLReader();
 $ruta=str_replace('\\','/',realpath($archivo));
 if(!$reader->open('zip://'.$ruta.'#'.$hoja,null,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))continue;
 $estado='';$municipio='000';$sexo='';$grupo='';$edades=[];
 try {
  while($reader->read()){
   if($reader->nodeType!==XMLReader::ELEMENT||$reader->localName!=='row')continue;
   $dom=new DOMDocument();
   if(!$dom->loadXML($reader->readOuterXML(),LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))continue;
   $xp=new DOMXPath($dom); $c=$leer->invoke($parser,$xp,$dom->documentElement,$compartidas);
   if(!$c)continue;
   if(preg_match('/^([0-9]{2})\s+.+$/u',trim((string)($c[1]??'')),$m)){$estado=$m[1];$municipio='000';$grupo='';}
   $geo=trim((string)($c[2]??''));
   if(preg_match('/^([0-9]{3})\s+.+$/u',$geo,$m)){$municipio=$m[1];$grupo='';}
   elseif(preg_match('/habitantes|tama(?:ñ|n)o\s+de\s+localidad/iu',$geo)){$municipio='';$grupo='';}
   elseif(in_array(mb_strtoupper($geo,'UTF-8'),['ENTIDAD FEDERATIVA','TOTAL','ESTADO'],true)){$municipio='000';$grupo='';}
   if(trim((string)($c[3]??''))!=='')$sexo=$normalizarTexto->invoke($parser,(string)$c[3]);
   if(trim((string)($c[4]??''))!=='')$grupo=$normalizarEdad->invoke($parser,(string)$c[4]);
   $edad=$normalizarEdad->invoke($parser,(string)($c[5]??''));
   if($estado!==$clave||$municipio!=='000'||$sexo!=='TOTAL'||$grupo!=='15-19'||!in_array($edad,['15','16','17'],true))continue;
   $m=[];
   for($n=1;$n<=28;$n++)$m[$n]=$entero->invoke($parser,$c[5+$n]??null);
   if(in_array(null,$m,true))continue;
   $total=array_sum(array_map(static fn($k)=>$m[$k],[2,3,4,8,12,13,17,21,22,23,27,28]));
   if($m[1]<=0||$total!==$m[1]||$m[13]!==$m[14]+$m[15]+$m[16]||$m[17]!==$m[18]+$m[19]+$m[20])continue;
   if(isset($edades[$edad])&&$edades[$edad]!==$m)throw new RuntimeException("Doble fila edad $edad en $hoja");
   $edades[$edad]=$m;
  }
 } finally {$reader->close();}
 if(!$edades)continue;
 echo "Hoja $hoja: edades ".implode(',',array_keys($edades))."\n";
 foreach($edades as $edad=>$m){
  if(isset($resultado[$edad])&&$resultado[$edad]!==$m)throw new RuntimeException("Discrepancia entre hojas en edad $edad");
  $resultado[$edad]=$m;
 }
}
if(count($resultado)!==3||array_diff(['15','16','17'],array_keys($resultado)))throw new RuntimeException('Faltan edades: '.implode(',',array_keys($resultado)));
$sum=array_fill(1,28,0);
foreach(['15','16','17'] as $edad){
 $m=$resultado[$edad];
 $min=$m[2]+$m[3]+$m[4]+$m[8]+$m[12]+$m[18];
 echo 'EDAD='.json_encode(['edad'=>$edad,'poblacion'=>$m[1],'minimo_sin_media_superior_concluida'=>$min,'porcentaje_minimo'=>round($min*100/$m[1],4),'bach_3omas'=>$m[19],'tec_secundaria_3omas'=>$m[15],'tec_secundaria_1a2'=>$m[14],'bach_1a2'=>$m[18],'bach_no_esp'=>$m[20],'nivel_no_esp'=>$m[28],'medidas'=>$m],JSON_UNESCAPED_UNICODE)."\n";
 foreach($m as $k=>$v)$sum[$k]+=$v;
}
$base=$sum[1];$min=$sum[2]+$sum[3]+$sum[4]+$sum[8]+$sum[12]+$sum[18];
$acred=$sum[15]+$sum[19]+$sum[22]+$sum[23]+$sum[27];
echo 'RESUMEN='.json_encode(['estado'=>$clave,'poblacion_15_17'=>$base,'sin_media_superior_concluida_minimo_identificable'=>$min,'porcentaje_minimo_15_17'=>round(100*$min/$base,6),'acreditacion_3omas_y_superior'=>$acred,'clasificacion_no_determinada'=>$base-$min-$acred,'tecnicos_sec_1a2'=>$sum[14],'prepa_1a2'=>$sum[18],'prepa_3omas'=>$sum[19],'sin_esc'=>$sum[2],'preescolar'=>$sum[3],'primaria'=>$sum[4],'secundaria'=>$sum[8],'tecnico_prim'=>$sum[12],'tec_secundaria'=>$sum[13],'bach_total'=>$sum[17],'no_especificado'=>$sum[28],'suma_niveles'=>array_sum(array_map(static fn($k)=>$sum[$k],[2,3,4,8,12,13,17,21,22,23,27,28]))],JSON_UNESCAPED_UNICODE)."\n";
} finally {$zip->close();}
