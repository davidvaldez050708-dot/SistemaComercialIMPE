<?php
declare(strict_types=1);
require_once __DIR__.'/TelefoniaTransferenciaOperacionService.php';
require_once __DIR__.'/TelefoniaEntrantesAnalistaService.php';
require_once __DIR__.'/TelefoniaRecepcionMarketingService.php';
require_once __DIR__.'/TelefoniaDisponibilidadService.php';
final class TelefoniaNotificacionService
{
    private mysqli $db;
    public function __construct(?mysqli $db=null){
        $this->db=$db??(new Database())->connect();
        $this->db->query("CREATE TABLE IF NOT EXISTS telefonia_notificaciones (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,usuario_id INT NOT NULL,
            extension VARCHAR(12) NOT NULL,llave VARCHAR(150) NOT NULL,numero VARCHAR(40) NOT NULL,
            titulo VARCHAR(160) NOT NULL,mensaje VARCHAR(300) NOT NULL,created_at DATETIME NOT NULL,
            leida TINYINT NOT NULL DEFAULT 0,UNIQUE KEY uk_tel_aviso(usuario_id,extension,llave),
            KEY idx_tel_aviso(usuario_id,extension,leida)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    private function extension(int $user): string {
        $s=$this->db->prepare("SELECT extension FROM telefonia_extensiones WHERE usuario_id=? AND activo=1 AND proveedor='ZADARMA' AND permite_entrantes=1 LIMIT 1");
        $s->bind_param('i',$user);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return (string)($r['extension']??'');
    }
    private function agregar(int $user,string $ext,string $key,string $number,string $date,string $title,string $message): void {
        $s=$this->db->prepare('INSERT IGNORE INTO telefonia_notificaciones (usuario_id,extension,llave,numero,titulo,mensaje,created_at) VALUES (?,?,?,?,?,?,?)');
        $s->bind_param('issssss',$user,$ext,$key,$number,$title,$message,$date);$s->execute();$s->close();
    }
    public function sincronizar(int $user,bool $recepcion): void {
        $ext=$this->extension($user);if($ext==='')return;
        foreach((new TelefoniaDisponibilidadService($this->db))->devoluciones($user) as $task){
            $date=(new DateTimeImmutable($task['updated_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
            $this->agregar($user,$ext,'devolucion:'.$task['id'].':'.$task['version'],$task['telefono'],$date,'Llamada por devolver',$task['contacto'].' · Recepción dejó sus datos y el motivo.');
        }
        $s=$this->db->prepare("UPDATE telefonia_notificaciones n JOIN telefonia_disponibilidad d ON n.llave=CONCAT('devolucion:',d.id,':',d.version) SET n.leida=1 WHERE n.usuario_id=? AND n.extension=? AND d.destino_usuario=n.usuario_id AND d.devolucion='ATENDIDA'");
        $s->bind_param('is',$user,$ext);$s->execute();$s->close();
        try{
        $history=$recepcion?(new TelefoniaRecepcionMarketingService($this->db))->resumen($user):(new TelefoniaEntrantesAnalistaService($this->db))->listar($user);
        foreach($history['llamadas'] as $c){
            if(($c['fuente']??'')==='OPERACION')continue; // Tiene su aviso exacto por intento debajo.
            $missed=$recepcion?($c['tipo']==='Entrante'&&$c['estado']==='Perdida'):
                (($c['tipo_llamada']??'')!=='INTERNA' && empty($c['contestada']) && in_array($c['estado_pbx'],['ended'],true));
            if(!$missed)continue;
            $q=$this->db->prepare("SELECT id FROM telefonia_transferencia_operaciones WHERE destino_usuario=? AND referencia=? AND modo='DIRECTA'");$ref=(string)$c['pbx_call_id'];$q->bind_param('is',$user,$ref);$q->execute();$tracked=$q->get_result()->fetch_assoc();$q->close();if($tracked)continue;
            $number=(string)($c['numero']??'Número privado');
            $this->agregar($user,$ext,'llamada:'.$c['pbx_call_id'],$number,(string)$c['fecha'],'Llamada perdida',$number.' · Revisa el historial y coordina su devolución.');
        }
        }catch(Throwable $e){error_log('[campana_historial_llamadas] '.get_class($e));}
        try{
        foreach((new TelefoniaTransferenciaOperacionService($this->db))->delUsuario($user) as $op){
            $direct=$op['modo']==='DIRECTA';$source=(int)$op['origen_usuario']===$user;
            if($direct){
                if(!in_array($op['estado'],['SIN_RESPUESTA','SIN_CONFIRMAR'],true)){
                    if(!empty($op['destino_atendida'])){$clear=$this->db->prepare("UPDATE telefonia_notificaciones SET leida=1 WHERE usuario_id=? AND extension=? AND llave IN (?,?)");$k1='transferencia:'.$op['id'].':'.$op['intento'].':SIN_RESPUESTA';$k2='transferencia:'.$op['id'].':'.$op['intento'].':SIN_CONFIRMAR';$clear->bind_param('isss',$user,$ext,$k1,$k2);$clear->execute();$clear->close();}continue;
                }
                $date=(new DateTimeImmutable($op['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
                $confirmed=$op['estado']==='SIN_RESPUESTA';
                // Una devolución asignada ya tiene su aviso propio con acceso a Llamar.
                if(!$source&&$confirmed){$q=$this->db->prepare("SELECT id FROM telefonia_disponibilidad WHERE origen_usuario=? AND destino_usuario=? AND referencia=? AND devolucion IN ('PENDIENTE','ATENDIDA')");$q->bind_param('iis',$op['origen_usuario'],$user,$op['referencia']);$q->execute();$task=$q->get_result()->fetch_assoc();$q->close();if($task)continue;}
                $message=$confirmed?($source?'La extensión '.$op['destino_extension'].' no contestó a '.$op['numero'].'. Coordina la devolución; no se confirma retorno a recepción.':'No contestaste la transferencia de '.$op['numero'].'. Coordina con recepción antes de llamar.'):'La respuesta de la extensión '.$op['destino_extension'].' aún no está confirmada. Coordina con recepción y revisa el historial antes de llamar.';
                $this->agregar($user,$ext,'transferencia:'.$op['id'].':'.$op['intento'].':'.$op['estado'],$op['numero'],$date,$confirmed?'Transferencia no contestada':'Transferencia sin confirmación',$message);continue;
            }
            if($source||$op['estado']!=='DEVUELTA'||!empty($op['destino_atendida']))continue;
            $date=(new DateTimeImmutable($op['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
            $this->agregar($user,$ext,'consulta:'.$op['id'].':'.$op['intento'],$op['numero'],$date,'Transferencia sin respuesta','Recepción intentó comunicarte con '.$op['numero'].'. Coordina con la extensión '.$op['origen_extension'].' antes de devolver la llamada.');
        }
        }catch(Throwable $e){error_log('[campana_transferencias] '.get_class($e));}
    }
    public function pendientes(int $user,bool $recepcion): array {
        $this->sincronizar($user,$recepcion);$ext=$this->extension($user);
        $s=$this->db->prepare('SELECT * FROM telefonia_notificaciones WHERE usuario_id=? AND extension=? AND leida=0 ORDER BY id DESC LIMIT 20');
        $s->bind_param('is',$user,$ext);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        return array_map(function($r)use($recepcion){
            $url=$recepcion?'index.php?controller=home&action=index#recepcion-telefonica':'index.php?controller=seguimientoVinculacion&action=llamadasEntrantes';
            $unconfirmed=str_ends_with($r['llave'],':SIN_CONFIRMAR');$callback=str_starts_with($r['llave'],'devolucion:');if($callback)$url='index.php?controller=telefonia&action=devoluciones';
            return ['id'=>-(int)$r['id'],'telefonia_id'=>(int)$r['id'],'tipo_evento'=>'llamada_perdida','tipo'=>'TELEFONIA_PERDIDA','leida'=>0,
                'titulo'=>$r['titulo'],'mensaje'=>$r['mensaje'],'created_at'=>$r['created_at'],'url'=>$url,
                'nombre_entidad'=>$r['titulo'].' · '.$r['numero'],'accion'=>$r['mensaje'],'icono'=>$callback?'bi-telephone-forward':'bi-telephone-x','etiqueta'=>$callback?'Por devolver':($unconfirmed?'Por confirmar':'Sin respuesta'),'estado'=>'informativa','fecha'=>$r['created_at'],'prioridad'=>1];
        },$rows);
    }
    public function leer(int $user,int $id): bool {
        $ext=$this->extension($user);$s=$this->db->prepare('UPDATE telefonia_notificaciones SET leida=1 WHERE usuario_id=? AND extension=? AND id=?');
        $s->bind_param('isi',$user,$ext,$id);$ok=$s->execute();$s->close();return $ok;
    }
    public function leerTodas(int $user): void {
        $ext=$this->extension($user);$s=$this->db->prepare('UPDATE telefonia_notificaciones SET leida=1 WHERE usuario_id=? AND extension=?');$s->bind_param('is',$user,$ext);$s->execute();$s->close();
    }
}
