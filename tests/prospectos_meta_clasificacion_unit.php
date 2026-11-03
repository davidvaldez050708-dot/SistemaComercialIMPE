<?php
require __DIR__.'/../app/services/ProspectoMetaArchivo.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$cols=['id','created_time','full_name','phone_number','form_id','form_name','campaign_id','campaign_name'];
$row=['l:99880001','2026-11-01T17:59:45-06:00','Persona Ficticia','p:+525500000001','f:1','Titulacion Exp','c:1','GENERAL Morelos'];
$csv=static function($rows,$delimiter="\t"){$f=fopen('php://temp','w+');foreach($rows as $r)fputcsv($f,$r,$delimiter,'"','');rewind($f);$s=stream_get_contents($f);fclose($f);return $s;};
$file=tempnam(sys_get_temp_dir(),'meta-clasificacion-');
try {
    foreach(['UTF-16LE'=>"\xff\xfe",'UTF-16BE'=>"\xfe\xff",'UTF-8'=>''] as $encoding=>$bom){
        $first=$row;$first[0]=" \xef\xbb\xbf\xef\xbb\xbfl:99880001";
        $header=$cols;$header[0]="\xef\xbb\xbfid";
        $raw=$csv([$header,$first,$row]);
        file_put_contents($file,$bom.($encoding==='UTF-8'?$raw:iconv('UTF-8',$encoding,$raw)));
        $data=ProspectoMetaArchivo::leer($file,'Meta_sin_extension');
        check($data['validas']===1&&$data['rechazadas']===0&&$data['repetidas_archivo']===1,'BOM + dedupe '.$encoding);
        check($data['filas'][0]['id_original']==='l:99880001','ID Meta original conservado');
    }
    $first=$row;$first[2]="Persona\xef\xbb\xbfFicticia";
    file_put_contents($file,$csv([$cols,$first],','));$data=ProspectoMetaArchivo::leer($file,'f.csv');
    check($data['filas'][0]['full_name']===$first[2],'BOM interior legítimo conservado');
    $first=$row;$first[2]='Persona Fictícia';
    file_put_contents($file,iconv('UTF-8','Windows-1252',$csv([$cols,$first],',')));
    check(ProspectoMetaArchivo::leer($file,'f.csv')['validas']===1,'Windows1252 no se elimina con regex Unicode inválida');
    $bad=[];foreach([0,1,2,3] as $i){$v=$row;$v[$i]='';$bad[]=$v;}
    file_put_contents($file,$csv([$cols,$row,...$bad]));$data=ProspectoMetaArchivo::leer($file,'f.tsv');
    check($data['rechazadas']===4,'Validaciones conservadas');
    foreach(['ID','Fecha','Nombre','Teléfono'] as $i=>$field)check(str_contains($data['errores'][$i],$field),'Error específico '.$field);
    check(!str_contains(implode(' ',$data['errores']),'99880001'),'Errores sin datos personales');
    $rows=[$row];$second=$row;$second[0]='l:99880002';$second[6]='c:2';$second[7]='MANUEL P BACHILLERATO';$rows[]=$second;
    $third=$row;$third[0]='l:99880003';$third[4]='f:2';$third[5]='Otro formulario';$rows[]=$third;
    file_put_contents($file,$csv([$cols,...$rows]));$data=ProspectoMetaArchivo::leer($file,'f.tsv');
    check(count($data['formularios'])===2&&count($data['campanias'])===2,'Varios formularios y campaña compartida');
    check($data['formularios'][0]['total']===2,'Cantidad por formulario');
    check(count($data['campanias'][0]['advertencias'])===0,'GENERAL o localidad no infieren programa');
    check(count($data['campanias'][1]['advertencias'])===1,'Advertencia académica discreta');
    $f1=$data['formularios'][0]['clave'];$f2=$data['formularios'][1]['clave'];$c1=$data['campanias'][0]['clave'];$c2=$data['campanias'][1]['clave'];
    $catalog=[['codigo'=>'programa-a','nombre'=>'Programa A'],['codigo'=>'programa-b','nombre'=>'Programa B']];
    $map=ProspectoMetaClasificacion::resolver($data,[],[$f1=>'programa-a',$f2=>'programa-b'],[],$catalog);
    check(ProspectoMetaClasificacion::programaFila($map,$data['filas'][0])['codigo']==='programa-a','Herencia formulario1');
    check(ProspectoMetaClasificacion::programaFila($map,$data['filas'][2])['codigo']==='programa-b','Herencia formulario2 en misma campaña');
    $map=ProspectoMetaClasificacion::resolver($data,[$c2=>'programa-b'],[$f1=>'programa-a'],[$c2=>'1'],$catalog);
    check(ProspectoMetaClasificacion::programaFila($map,$data['filas'][1])['codigo']==='programa-b','Excepción gana al formulario');
    $map=ProspectoMetaClasificacion::resolver($data,[$c2=>''],[$f1=>'programa-a'],[$c2=>'1'],$catalog);
    check(ProspectoMetaClasificacion::programaFila($map,$data['filas'][1])['codigo']===null,'Pendiente explícito gana al formulario');
    $legacy=ProspectoMetaClasificacion::resolver($data,[$c1=>'programa-b'],null,[],$catalog);
    check(ProspectoMetaClasificacion::programaFila($legacy,$data['filas'][0])['codigo']==='programa-b','Carga anterior compatible');
    foreach([[[],[$f1=>'inventado'],[]],[[],['desconocido'=>'programa-a'],[]],[[$c2=>[]],[],[$c2=>1]],[[],[],[$c1=>'mal']]] as [$campaigns,$forms,$flags]){
        $rejected=false;try{ProspectoMetaClasificacion::resolver($data,$campaigns,$forms,$flags,$catalog);}catch(DomainException $e){$rejected=true;}
        check($rejected,'Servidor rechaza clasificación manipulada');
    }
    echo "PASS BOM UTF16LE/BE, UTF8, Windows1252, errores por campo, formulario, excepciones, pendientes, legado y validación del catálogo.\n";
}finally{@unlink($file);}
