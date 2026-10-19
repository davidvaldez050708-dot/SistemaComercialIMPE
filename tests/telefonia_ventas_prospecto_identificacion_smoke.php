<?php
declare(strict_types=1);
require_once __DIR__.'/../app/services/TelefoniaDisponibilidadService.php';
assert_options(ASSERT_ACTIVE, 1);
$validos=[' Mariana López '=>'Mariana López','Ana'=>'Ana',' Prospecto sin identificar '=>'Prospecto sin identificar'];
foreach($validos as $entrada=>$esperado){
    if(TelefoniaDisponibilidadService::normalizarNombreProspecto($entrada)!==$esperado){
        fwrite(STDERR,"No se normalizó el nombre esperado.\n");exit(1);
    }
}
foreach(['','  ','A',str_repeat('a',151)] as $entrada){
    try{
        TelefoniaDisponibilidadService::normalizarNombreProspecto($entrada);
        fwrite(STDERR,"Se aceptó identificación inválida.\n");exit(1);
    }catch(DomainException $ok){}
}
echo "Ventas prospecto: nombre obligatorio, límites y normalización: OK\n";
