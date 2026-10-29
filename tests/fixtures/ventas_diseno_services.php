<?php
require_once __DIR__.'/VentasActividadMetricas.php';
final class VentasActividadAccess {
    public static function alcance(bool $desempeno=false): string {return isset($_GET['propio'])?'propio':'global';}
}
function tienePermiso($codigo): bool {return !isset($_GET['sin_exportar']);}
final class VentasActividadService {
    public function preparar(array $f,bool $desempeno=false,bool $generar=true): array {
        $period=VentasActividadMetricas::periodo($f);$scope=VentasActividadAccess::alcance();
        $people=isset($_GET['sin_personas'])?[]:[['id'=>8,'nombre_completo'=>'Asesora Uno','activo'=>1],['id'=>9,'nombre_completo'=>'Asesora Dos','activo'=>1],['id'=>10,'nombre_completo'=>'Asesora Sin Actividad','activo'=>1]];
        $uid=$scope==='propio'?8:(int)($f['persona_id']??0);$selected=$uid?array_values(array_filter($people,static fn($p)=>$p['id']===$uid)):$people;
        $today=(new DateTimeImmutable('today',new DateTimeZone('America/Mexico_City')))->format('Y-m-d');$calls=[];
        $choices=[['PROSPECTO_POTENCIAL','WHATSAPP'],['BUZON_VOZ','SMS'],['COMUNICACION_INTERRUMPIDA','WHATSAPP'],['INSCRITO'],['SIN_RESPUESTA']];
        for($i=0;$i<54;$i++){
            $codes=$choices[$i%count($choices)];$primary=$codes[0]==='COMUNICACION_INTERRUMPIDA'?'COMUNICACION_INTERRUMPIDA_PERSONA':$codes[0];
            $reg=['resultado'=>$primary,'resultados_seleccionados'=>$codes,'prospecto_nombre'=>$i%3?'Prospecto de prueba':'Institución de prueba con un nombre extenso para revisar la presentación',
                'observacion'=>'Solicitó información sobre los requisitos y horarios. Se acordó enviar los detalles y retomar el contacto en la fecha indicada.'];
            if($primary==='COMUNICACION_INTERRUMPIDA_PERSONA')$reg['contacto_antes_corte']='SI';
            if($i===3){unset($reg['resultados_seleccionados']);$reg['catalogo_version']='2';$reg['envio_informacion']='WHATSAPP_SMS';}
            $calls[]=['id'=>$i+1,'usuario_id'=>$i%3?8:9,'extension'=>$i%3?'150':'151','pbx_call_id'=>'pbx-diseno-'.$i,'fecha'=>$today.sprintf(' 08:%02d:00',$i),
                'direccion'=>$i%4?'Saliente':'Entrante','conectada'=>!in_array($primary,['BUZON_VOZ','SIN_RESPUESTA'],true),'finalizada'=>true,'segundos'=>$i%4?80:0,'nombre'=>'Prueba',
                'numero'=>'5555550101','registro'=>$reg];
        }
        usort($calls,static fn($a,$b)=>strcmp($b['fecha'],$a['fecha']));
        $direction=(string)($f['direccion']??'');$result=(string)($f['resultado']??'');
        $calls=array_values(array_filter($calls,static fn($c)=>($direction===''||$c['direccion']===$direction)&&TelefoniaResultadoVentasCatalogo::coincideRegistro($c['registro'],$result)));
        $org=['disponible'=>true,'consultado_at'=>date('d/m/Y H:i'),'programadas'=>6,'vencidas'=>2,'devoluciones'=>4,'devoluciones_vencidas'=>1,'personas'=>[
            8=>['programadas'=>4,'vencidas'=>2,'devoluciones'=>3,'devoluciones_vencidas'=>1],9=>['programadas'=>2,'vencidas'=>0,'devoluciones'=>1,'devoluciones_vencidas'=>0],10=>[]]];
        return VentasActividadMetricas::calcular($generar?$calls:[],$selected,$period)+[
            'vista'=>$scope,'persona_id'=>$uid,'personas'=>$scope==='propio'?$selected:$people,'periodo'=>$period,'organizacion'=>$org,
            'direccion'=>$direction,'resultado'=>$result,'es_desempeno'=>$desempeno,'generado'=>$generar,
            'generado_por'=>'Supervisor Prueba','generado_por_rol'=>'Administrador','fecha_generacion'=>date('d/m/Y H:i')];
    }
}
