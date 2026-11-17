<?php
require_once __DIR__.'/../app/services/EmbudoComercialRepository.php';
foreach([true,false] as $supervisor){
    $sinFiltro=EmbudoComercialRepository::filtros([],$supervisor);
    foreach(['','0'] as $valor){
        if(EmbudoComercialRepository::filtros(['asesor'=>$valor],$supervisor)!==$sinFiltro){
            throw new RuntimeException('Todos los asesores debe equivaler a no filtrar.');
        }
    }
    $conFiltro=EmbudoComercialRepository::filtros(['asesor'=>'22'],$supervisor);
    if($conFiltro['asesor']!==($supervisor?22:0))throw new RuntimeException('Filtro por asesor incorrecto.');
    foreach(['-1','00','22x','1.5',[],true,0] as $valor){
        try{EmbudoComercialRepository::filtros(['asesor'=>$valor],$supervisor);}
        catch(DomainException $e){continue;}
        throw new RuntimeException('Filtro mal formado aceptado.');
    }
}
echo "CHECK_OK todos los asesores: vacío/0 equivalentes, ID positivo y validación estricta conservada.\n";
