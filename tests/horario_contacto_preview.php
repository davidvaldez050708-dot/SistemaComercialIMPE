<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing')exit(2);
require __DIR__.'/../config/config.php';
$_SESSION=['usuario_id'=>4,'rol'=>'Marketing'];
$estados=[['id'=>1,'nombre'=>'Morelos']];$perfilesInteres=['Bachillerato General','Inglés'];
$registroAction=BASE_URL.'index.php?controller=formulario'.(($argv[1]??'')==='interno'?'':'Publico').'&action=guardarRegistro';
$municipiosUrl=BASE_URL.'index.php?controller=formularioPublico&action=municipios';
if(($argv[1]??'')==='interno'){
    require __DIR__.'/horario_contacto_internal.php';
}else require __DIR__.'/../app/views/formularios/publico_registro.php';
