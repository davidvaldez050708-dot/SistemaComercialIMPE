<?php
declare(strict_types=1);
require_once __DIR__.'/TelefoniaDevolucionVentasService.php';
require_once __DIR__.'/TelefoniaProgramacionVentasService.php';
require_once __DIR__.'/TelefoniaDisponibilidadService.php';

/** Usa la campana, lectura y cadencia compartidas. No incorpora otro polling de avisos. */
final class TelefoniaNotificacionVentasService
{
    private mysqli $db;
    public function __construct(mysqli $db){$this->db=$db;}
    private function rows(string $sql,array $args=[]): array {
        $s=$this->db->prepare($sql);if($args)$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();$r=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $r;
    }
    public function pendientes(int $uid): array {
        $history=new TelefoniaVentasLlamadasService($this->db);
        // Un fallo auxiliar no debe ocultar llamadas ya atribuidas y confirmadas.
        // La lectura conserva propietario, permisos, cierre PBX y resultado.
        try{$calls=$history->listar($uid);}
        catch(Throwable $e){
            error_log('[ventas_avisos_conciliacion] '.get_class($e));
            $calls=$history->listar($uid,[],false);
        }
        // Conciliar estados con el mecanismo existente; aceptar disponibilidad no acredita respuesta.
        try{$active=$this->rows("SELECT extension FROM telefonia_extensiones WHERE usuario_id=? AND activo=1 AND proveedor='ZADARMA' AND permite_entrantes=1",[$uid]);}
        catch(Throwable $e){error_log('[ventas_avisos_extension] '.get_class($e));$active=[];}
        // La sincronización de devoluciones es auxiliar: no debe dejar la
        // campana vacía si falla una operación pendiente de otra extensión.
        if(count($active)===1){
            try{(new TelefoniaDisponibilidadService($this->db))->devoluciones($uid);}
            catch(Throwable $e){error_log('[ventas_avisos_disponibilidad] '.get_class($e));}
        }
        try{$tasks=(new TelefoniaDevolucionVentasService($this->db))->tareas($uid);}
        catch(Throwable $e){error_log('[ventas_avisos_devolucion] '.get_class($e));$tasks=[];}
        $wanted=[];$covered=[];$facts=[];
        $add=static function(string $key,string $ext,string $number,string $date,string $title,string $text,string $url,string $label)use(&$wanted):void {
            $wanted[$key]=['extension'=>$ext,'numero'=>$number,'created_at'=>$date,'titulo'=>$title,'mensaje'=>$text,'url'=>$url,'etiqueta'=>$label];
        };
        $base='index.php?controller=telefonia&action=misLlamadas';
        foreach($calls as $c)$facts[$c['pbx_call_id'].'|'.$c['extension']]=$c;
        foreach($tasks as $t){$scope=$t['referencia'].'|'.$t['destino_extension'];$covered[$scope]=true;if($t['devolucion']!=='PENDIENTE')continue;
            // La devolución AUTOMÁTICA por no respuesta ya no es una tarea real
            // si Zadarma confirmó conversación, haya o no resultado guardado.
            // Antes revivía precisamente al guardar el resultado: regresión.
            if(!empty($facts[$scope]['conectada'])&&str_starts_with($t['nota'],'Transferencia no contestada.'))continue;
            $date=(new DateTimeImmutable($t['updated_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
            $add('ventas:devolucion:'.$t['id'].':'.$t['version'],$t['destino_extension'],$t['telefono'],$date,'Llamada por devolver',$t['nombre'].' · '.$t['nota'],$base.'&vista=devoluciones','Por devolver');
        }
        foreach($calls as $c){$scope=$c['pbx_call_id'].'|'.$c['extension'];$facts[$scope]=$c;
            if(!$c['finalizada']||$c['registro'])continue;
            $missed=$c['direccion']==='Entrante'&&!$c['conectada'];if($missed&&isset($covered[$scope]))continue;
            $add('ventas:llamada:'.$c['id'].($missed?':perdida':':resultado'),$c['extension'],$c['numero'],$c['fecha'],$missed?'Llamada perdida':'Resultado pendiente',
                ($c['nombre']?:$c['numero']).' · '.($missed?'No se confirmó una conversación. Revisa tu llamada.':'Registra lo que ocurrió en esta llamada.'),
                $base.'&pbx_call_id='.rawurlencode($c['pbx_call_id']).'&extension='.rawurlencode($c['extension']),$missed?'Sin respuesta':'Por registrar');
        }
        try{$operations=$this->rows("SELECT o.* FROM telefonia_transferencia_operaciones o WHERE destino_usuario=? AND EXISTS(SELECT 1 FROM telefonia_disponibilidad d WHERE d.destino_usuario=o.destino_usuario AND d.destino_extension=BINARY o.destino_extension AND d.referencia=BINARY o.referencia AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(d.ruta_json),d.ruta_json,'{}'),'$.tipo'))='PROSPECTO') AND estado IN ('SIN_RESPUESTA','SIN_CONFIRMAR') AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) ORDER BY created_at DESC",[$uid]);}
        catch(Throwable $e){error_log('[ventas_avisos_transferencias] '.get_class($e));$operations=[];}
        foreach($operations as $op){$scope=$op['referencia'].'|'.$op['destino_extension'];
            if(!empty($facts[$scope]['conectada'])||isset($covered[$scope])||!empty($facts[$scope]['finalizada']))continue;
            $confirmed=$op['estado']==='SIN_RESPUESTA';$date=(new DateTimeImmutable($op['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
            $add('ventas:transferencia:'.$op['id'].':'.$op['intento'].':'.$op['estado'],$op['destino_extension'],$op['numero'],$date,$confirmed?'Transferencia no contestada':'Transferencia sin confirmación',
                $op['numero'].' · Coordina con Recepción ext. '.$op['origen_extension'].'. No se confirma retorno automático a Recepción.',$base.'&estado=transferidas',$confirmed?'Sin respuesta':'Por confirmar');
        }
        try{$reminders=(new TelefoniaProgramacionVentasService($this->db))->listar($uid);}
        catch(Throwable $e){error_log('[ventas_avisos_programadas] '.get_class($e));$reminders=[];}
        foreach($reminders as $reminder){if($reminder['fecha']>date('Y-m-d H:i:s'))continue;
            $add('ventas:programada:'.$reminder['origen'].':'.$reminder['id'].':'.substr(sha1($reminder['fecha']),0,12),$reminder['extension'],$reminder['telefono'],$reminder['fecha'],'Contacto programado',
                ($reminder['nombre']?:$reminder['telefono']).' · '.$reminder['detalle'],$base.'&vista=agenda','Programada');
        }
        // Las llaves son estables: leer un aviso no se revierte en el siguiente polling.
        $s=$this->db->prepare('INSERT IGNORE INTO telefonia_notificaciones (usuario_id,extension,llave,numero,titulo,mensaje,created_at) VALUES (?,?,?,?,?,?,?)');
        foreach($wanted as $key=>$r){$title=mb_substr($r['titulo'],0,160);$message=mb_substr($r['mensaje'],0,300);$s->bind_param('issssss',$uid,$r['extension'],$key,$r['numero'],$title,$message,$r['created_at']);$s->execute();}$s->close();
        $existing=$this->rows("SELECT id,llave FROM telefonia_notificaciones WHERE usuario_id=? AND leida=0 AND llave LIKE 'ventas:%'",[$uid]);
        $clear=$this->db->prepare('UPDATE telefonia_notificaciones SET leida=1 WHERE usuario_id=? AND id=?');
        foreach($existing as $r)if(!isset($wanted[$r['llave']])){$clear->bind_param('ii',$uid,$r['id']);$clear->execute();}$clear->close();
        // No se infiere una resolución del clic sobre la campana:
        // resultados, devoluciones, agenda y transferencias conservan su fila
        // hasta que desaparecen realmente de $wanted por su estado de negocio.
        $rows=$this->rows("SELECT * FROM telefonia_notificaciones WHERE usuario_id=? AND llave LIKE 'ventas:%' ORDER BY created_at DESC,id DESC LIMIT 200",[$uid]);
        $rows=array_values(array_filter($rows,static function($r)use($wanted){
            if(!isset($wanted[$r['llave']]))return false;
            $informativa=str_ends_with((string)$r['llave'],':perdida');
            return !$informativa||(int)$r['leida']===0;
        }));
        $rows=array_slice($rows,0,20);
        return array_map(static function($r)use($wanted){$meta=$wanted[$r['llave']];
            $kind=str_starts_with($r['llave'],'ventas:devolucion:')?'devolucion':(str_starts_with($r['llave'],'ventas:programada:')?'programada':(str_ends_with($r['llave'],':resultado')?'resultado_pendiente':'llamada_perdida'));
            return [
            'id'=>-(int)$r['id'],'telefonia_id'=>(int)$r['id'],'tipo_evento'=>$kind,'tipo'=>$kind==='resultado_pendiente'?'TELEFONIA_RESULTADO':'TELEFONIA_PERDIDA','leida'=>(int)$r['leida'],
            'titulo'=>$r['titulo'],'mensaje'=>$r['mensaje'],'created_at'=>$r['created_at'],'fecha'=>$r['created_at'],'url'=>$meta['url'],
            'nombre_entidad'=>$r['titulo'].' · '.$r['numero'],'accion'=>$r['mensaje'],'icono'=>'bi-telephone','etiqueta'=>$meta['etiqueta'],'estado'=>'informativa','prioridad'=>1,
            'pendiente_operativo'=>!str_ends_with((string)$r['llave'],':perdida')];},$rows);
    }
}
