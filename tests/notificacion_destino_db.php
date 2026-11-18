<?php
// Ejecutado por supervision_comercial_v3_db.php dentro de MariaDB sintética.
sesionSupervision();
$cuenta=0;
foreach (range(100,120) as $id) {
    $loc=$repo->localizarNotificacion(1,true,$id,1);
    comprobarSupervision($loc!==null && $loc['vista']==='embudo','localizar asesor supervisado');
    $pagina=$loc['p3_nuevo'];
    $lista=$repo->listadoV3Operativo(1,EmbudoComercialRepository::filtros([],true),['NUEVO'=>$pagina],true);
    comprobarSupervision(in_array($id,array_map('intval',array_column($lista['columnas']['NUEVO']['registros'],'asignacion_id')),true),'rango coincide con orden del tablero');
    if($pagina>1)$cuenta++;
}
comprobarSupervision($cuenta>0,'localiza también fuera de primera página');
sesionSupervision(false);
comprobarSupervision($repo->localizarNotificacion(22,false,7,1)===null,'no revela prospecto de otro asesor');
comprobarSupervision($repo->localizarNotificacion(22,false,11,1)===null,'no revela cierre de otro asesor');
comprobarSupervision($repo->localizarNotificacion(22,false,12,1)===null,'no reutiliza asignación histórica');
comprobarSupervision($repo->localizarNotificacion(22,false,99999,1)===null,'id inexistente');
$loc=$repo->localizarNotificacion(22,false,4,1);
comprobarSupervision($loc['aviso_actividad']==='pendiente' && $loc['destacar']===4,'abrir mantiene pendiente y ciclo');
$loc=$repo->localizarNotificacion(22,false,4,99999);
comprobarSupervision($loc['aviso_actividad']==='actualizada','aviso antiguo muestra actividad reprogramada');
$loc=$repo->localizarNotificacion(22,false,3,1);
comprobarSupervision($loc['aviso_actividad']==='sin_pendiente','aviso completado muestra estado vigente');
$loc=$repo->localizarNotificacion(22,false,8,1);
comprobarSupervision($loc['vista']==='recuperacion' && $loc['aviso_actividad']==='otra_seccion','cambio a recuperación');
$loc=$repo->localizarNotificacion(22,false,10,1);
comprobarSupervision($loc['action']==='expedientes' && $loc['pagina']===1,'cierre va a expedientes');
sesionSupervision();
$loc=$repo->localizarNotificacion(1,true,9,1);
comprobarSupervision($loc['vista']==='descartados','supervisión localiza descarte');
$_SESSION=['usuario_id'=>22,'rol_id'=>3,'permisos'=>['ventas.embudo.ver']];
comprobarSupervision($repo->localizarNotificacion(22,false,8,1)===null,'permiso propio de bandeja');
$_SESSION=['usuario_id'=>22,'rol_id'=>3,'permisos'=>[]];
rechazarSupervision(fn()=>$repo->localizarNotificacion(22,false,1,1));
sesionSupervision();
echo "CHECK_OK destino: página exacta, etapa vigente, cierres, ciclo, permisos e inexistentes; sólo lecturas.\n";
