<?php
require __DIR__.'/../../app/services/SeguimientoExcelPlantillaService.php';
$estados=[['id'=>17,'nombre'=>'Morelos'],['id'=>16,'nombre'=>'Michoacán'],['id'=>15,'nombre'=>'Estado de México']];
$municipios=[['id'=>1,'estado_id'=>17,'nombre'=>'Cuernavaca','estado'=>1],['id'=>2,'estado_id'=>17,'nombre'=>'Cuautla','estado'=>1],['id'=>3,'estado_id'=>16,'nombre'=>'Morelia','estado'=>1],['id'=>4,'estado_id'=>15,'nombre'=>'Toluca','estado'=>1],['id'=>5,'estado_id'=>15,'nombre'=>'Valle de Bravo','estado'=>1],['id'=>6,'estado_id'=>21,'nombre'=>'Municipio no autorizado','estado'=>1],['id'=>7,'estado_id'=>17,'nombre'=>'Municipio inactivo','estado'=>0]];
echo SeguimientoExcelPlantillaService::archivo($estados,$municipios);
