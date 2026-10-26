<?php
putenv('IMPE_BASE_URL=https://fundacionrededumex.org/');
require __DIR__.'/correo_convocatorias_test.php';
$registration=BASE_URL.'index.php?controller=formularioPublico&action=registro&aliado='.str_repeat('a',64);
$enrollment=str_replace('action=registro','action=inscripcion',$registration);
foreach([$registration=>'registro',$enrollment=>'inscripcion'] as $url=>$type) {
    $link=CorreoConvocatoriaEnlace::obtener(['enlace_ref'=>$url]);
    cvCheck($link['url']===$url && $link['tipo']===$type,'URL original y tipo exactos');
    cvCheck(str_contains(CorreoConvocatoriaEnlace::html($link),'href="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'"'),'Enlace clicable con escape HTML y parámetros intactos');
}
$encoded=str_replace('action=registro','action=%72egistro',$registration);
cvCheck(CorreoConvocatoriaEnlace::obtener(['enlace_ref'=>$encoded])['url']===$encoded,'No normalizar ni reescribir parámetros originales');
$caps=str_replace('https://fundacionrededumex.org','HTTPS://FUNDACIONREDEDUMEX.ORG',$registration);
cvCheck(CorreoConvocatoriaEnlace::obtener(['enlace_ref'=>$caps])['url']===$caps,'Host/esquema válidos sin reescritura');
foreach(['','javascript:alert(1)','https://ajeno.example/index.php?controller=formularioPublico&action=registro',
    str_replace('fundacionrededumex.org','fundacionrededumex.org.ajeno.test',$registration),
    str_replace('https://','http://',$registration),str_replace('https://','https://usuario:clave@',$registration),
    str_replace('/index.php','/interno/config.php',$registration),str_replace('action=registro','action=guardarRegistro',$registration),
    $registration.'&password=PRIVADO',$registration.'&token=PRIVADO',$registration.'&action=inscripcion',
    $registration.'&redirect=https://ajeno.example',$registration.'#password=PRIVADO',$registration.'%0d%0a',
    str_replace('aliado=','aliado[]=',$registration),str_replace(str_repeat('a',64),'invalido',$registration)] as $url) {
    cvCheck(CorreoConvocatoriaEnlace::obtener(['enlace_ref'=>$url])===null,'URL no autorizada/credenciales/ambigüedad no se expone');
}
// Lectura exclusivamente por ID: no crear perfiles ni llamar generadores de enlaces.
$db=new CvDb();$db->rows=[['id'=>1,'titulo'=>'QA Registro','estado'=>1,'imagen'=>'','enlace_registro'=>$registration],
    ['id'=>2,'titulo'=>'QA Inscripción','estado'=>1,'imagen'=>'','enlace_registro'=>$enrollment],
    ['id'=>3,'titulo'=>'QA sin enlace','estado'=>1,'imagen'=>'','enlace_registro'=>'']];
$composition=new CorreoConvocatoriaComposicion(new CorreoConvocatoriaConsultaModel($db));
$one=$composition->seleccionar(['convocatoria_id'=>1]);$two=$composition->seleccionar(['convocatoria_id'=>2]);
cvCheck($one['enlace_ref']===$registration && $two['enlace_ref']===$enrollment,'SELECT del ID conserva la fuente original');
cvCheck(!isset($composition->publico($one)['enlace_ref']) && $composition->publico($one)['enlace_publico']['url']===$registration,'Sólo se expone URL validada');
$manual='Texto completo con enlace manual https://ejemplo.test/mio';
$data=['cuerpo'=>$manual,'adjuntos'=>[['ruta'=>__FILE__,'nombre'=>'manual.txt','mime'=>'text/plain']]];
$capture=new MarketingSender();$sender=$composition->envio($one,$capture);$sender->enviar($data);
cvCheck($capture->last['cuerpo']===$manual && $capture->last['adjuntos']===$data['adjuntos'],'No tocar texto, enlaces ni archivos manuales');
cvCheck(substr_count($capture->last['html_adicional'],'Acceder al formulario de registro')===1,'Bloque automático único');
$sender->enviar($data);cvCheck(substr_count($capture->last['html_adicional'],'Acceder al formulario de registro')===1,'Repreparar no acumula enlaces');
$composition->envio($two,$capture)->enviar($data);
cvCheck(!str_contains($capture->last['html_adicional'],'Acceder al formulario de registro') && str_contains($capture->last['html_adicional'],'Acceder al formulario de inscripción'),'Cambiar reemplaza sólo bloque automático');
$none=$composition->seleccionar(['convocatoria_id'=>3]);
cvCheck($composition->envio($none,$capture)->enviar($data)['ok'] && empty($capture->last['html_adicional']),'Sin enlace no bloquea ni inventa URL');
$private=sys_get_temp_dir().'/impe-enlace-'.bin2hex(random_bytes(5));mkdir($private,0700,true);putenv('IMPE_MAIL_STATE_DIR='.$private);
try {
    $composition->registrar(7,101,$one);
    cvCheck($composition->publico($composition->leer(7,101))['enlace_publico']['url']===$registration,'Borrador conserva URL exacta con persistencia existente');
    $db->rows[0]['enlace_registro']=$enrollment;
    cvCheck($composition->publico($composition->leer(7,101))['enlace_publico']['url']===$registration,'Consultar borrador no reconstruye desde datos nuevos');
    $composition->registrar(7,102,$one,true);$composition->registrar(7,102,$two,true);
    cvCheck($composition->leer(7,102)['enlace_ref']===$registration,'Historial aceptado inmutable');
    cvCheck($composition->publico(['id'=>1])['enlace_publico']===null,'Historial anterior no adquiere enlaces inventados');
} finally {
    foreach(glob($private.'/*') as $file) { unlink($file); }rmdir($private);putenv('IMPE_MAIL_STATE_DIR');
}
echo "OK enlace: Registro/Inscripción por ID, URL exacta/escape, dominio/ruta/parámetros autorizados, credenciales rechazadas, texto/adjuntos intactos, cambio/sin enlace, borrador e historial existente. Sólo datos ficticios.\n";
