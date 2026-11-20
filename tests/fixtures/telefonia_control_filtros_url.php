<?php
// Vista real, sin sesión, conexiones, SQL ni llamadas.
define('BASE_URL',$argv[1]);
$_GET=json_decode($argv[2]??'{}',true,512,JSON_THROW_ON_ERROR);
$controlTelefonia=[
    'filtros'=>array_merge(['desde'=>date('Y-m-d',strtotime('-10 days')),'hasta'=>date('Y-m-d'),'orden'=>'atenciones'],$_GET),
    'roles'=>['Asesor de Ventas','Analista de Datos'],
    'usuarios'=>[['id'=>22,'nombre'=>'Asesor sintético','rol'=>'Asesor de Ventas']],
    'stats'=>['llamadas_unicas'=>empty($_GET['direccion'])?111:222]
];
$puedeAdministrarExtensiones=true;
require __DIR__.'/../../app/views/telefonia/index.php';
