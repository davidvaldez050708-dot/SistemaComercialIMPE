<?php
// Servicios sintéticos para probar el controlador/vista reales sin ninguna DB o proveedor.
require_once __DIR__.'/VentasActividadMetricas.php';
final class VentasActividadAccess {
    public static function alcance(bool $desempeno=false): string {return isset($_GET['denegado'])?'':(isset($_GET['propio'])?'propio':'global');}
}
function tienePermiso($codigo): bool {return !isset($_GET['sin_exportar']);}
final class VentasActividadService {
    public function preparar(array $f,bool $desempeno=false,bool $generar=true): array {
        $p=VentasActividadMetricas::periodo($f);$scope=VentasActividadAccess::alcance();
        $people=[['id'=>8,'nombre_completo'=>'Asesor Uno','activo'=>1],['id'=>9,'nombre_completo'=>'Asesor Dos','activo'=>1]];
        $uid=$scope==='propio'?8:(int)($f['persona_id']??0);
        $selected=$uid?array_values(array_filter($people,static fn($u)=>$u['id']===$uid)):$people;
        $today=(new DateTimeImmutable('today',new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
        $calls=[];
        foreach([['BUZON_VOZ','WHATSAPP'],['PROSPECTO_POTENCIAL','SMS']] as $i=>$codes)$calls[]=[
            'id'=>$i+1,'usuario_id'=>8,'extension'=>'150','pbx_call_id'=>'pbx-prueba-'.$i,'fecha'=>$today.' 08:0'.$i.':00',
            'direccion'=>$i?'Entrante':'Saliente','conectada'=>true,'finalizada'=>true,'segundos'=>15,'nombre'=>'Prueba',
            'numero'=>'5555550101','registro'=>['resultado'=>$codes[0],'resultados_seleccionados'=>$codes,'prospecto_nombre'=>'Prueba','observacion'=>'Notas de prueba']];
        $direction=(string)($f['direccion']??'');$result=(string)($f['resultado']??'');
        $calls=array_values(array_filter($calls,static fn($c)=>($direction===''||$c['direccion']===$direction)&&TelefoniaResultadoVentasCatalogo::coincideRegistro($c['registro'],$result)));
        return VentasActividadMetricas::calcular($generar?$calls:[],$selected,$p)+[
            'vista'=>$scope,'persona_id'=>$uid,'personas'=>$scope==='propio'?$selected:$people,'periodo'=>$p,'organizacion'=>[],
            'direccion'=>$direction,'resultado'=>$result,'es_desempeno'=>$desempeno,'generado'=>$generar,
            'generado_por'=>'Supervisor Prueba','generado_por_rol'=>'Administrador','fecha_generacion'=>date('d/m/Y H:i')];
    }
}
