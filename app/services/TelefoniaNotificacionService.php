<?php
declare(strict_types=1);
require_once __DIR__.'/TelefoniaTransferenciaOperacionService.php';
require_once __DIR__.'/TelefoniaEntrantesAnalistaService.php';
require_once __DIR__.'/TelefoniaRecepcionMarketingService.php';
require_once __DIR__.'/TelefoniaDisponibilidadService.php';
final class TelefoniaNotificacionService
{
    private mysqli $db;
    /** Claves de obligaciones verificadas en esta consulta; leer no las resuelve. */
    private array $operativasActuales=[];
    private static function esOperativa(string $llave): bool {
        return str_starts_with($llave,'devolucion:') || str_starts_with($llave,'transferencia:');
    }
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
    private function esVentas(int $user): bool {
        $s=$this->db->prepare('SELECT id FROM usuarios WHERE id=? AND rol_id=3 AND estado=1');$s->bind_param('i',$user);$s->execute();$yes=$s->get_result()->num_rows===1;$s->close();return $yes;
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
        $this->operativasActuales=[];
        if($this->esVentas($user)){require_once __DIR__.'/TelefoniaNotificacionVentasService.php';(new TelefoniaNotificacionVentasService($this->db))->pendientes($user);return;}
        $ext=$this->extension($user);if($ext==='')return;
        foreach((new TelefoniaDisponibilidadService($this->db))->devoluciones($user) as $task){
            if(($task['devolucion']??'')!=='PENDIENTE')continue;
            $this->operativasActuales['devolucion:'.$task['id'].':'.$task['version']]=true;
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
            $q=$this->db->prepare("SELECT id FROM telefonia_transferencia_operaciones WHERE destino_usuario=? AND referencia=? AND (modo='DIRECTA' OR (modo='CONSULTADA' AND estado='DEVUELTA'))");$ref=(string)$c['pbx_call_id'];$q->bind_param('is',$user,$ref);$q->execute();$tracked=$q->get_result()->fetch_assoc();$q->close();if($tracked)continue;
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
                // Una devolución verdaderamente atendida cierra también el aviso
                // antiguo del origen, no sólo el aviso del destinatario.
                if($confirmed){
                    $q=$this->db->prepare("SELECT devolucion FROM telefonia_disponibilidad
                        WHERE origen_usuario=? AND destino_usuario=? AND referencia=?
                          AND devolucion IN ('PENDIENTE','ATENDIDA') ORDER BY id DESC LIMIT 1");
                    $sourceUser=(int)$op['origen_usuario'];$destUser=(int)$op['destino_usuario'];
                    $ref=(string)$op['referencia'];
                    $q->bind_param('iis',$sourceUser,$destUser,$ref);$q->execute();
                    $task=$q->get_result()->fetch_assoc();$q->close();
                    if(($task['devolucion']??'')==='ATENDIDA')continue;
                }
                // Cuando la PBX confirmó que no contestaron, el aviso anterior
                // de 'sin confirmar' para el mismo intento ya está superado.
                if($confirmed){
                    $obsolete='transferencia:'.$op['id'].':'.$op['intento'].':SIN_CONFIRMAR';
                    $clear=$this->db->prepare('UPDATE telefonia_notificaciones SET leida=1 WHERE usuario_id=? AND extension=? AND llave=?');
                    $clear->bind_param('iss',$user,$ext,$obsolete);
                    $clear->execute();$clear->close();
                }
                // Una devolución asignada ya tiene su aviso propio con acceso a Llamar.
                if(!$source&&$confirmed){$q=$this->db->prepare("SELECT id FROM telefonia_disponibilidad WHERE origen_usuario=? AND destino_usuario=? AND referencia=? AND devolucion IN ('PENDIENTE','ATENDIDA')");$q->bind_param('iis',$op['origen_usuario'],$user,$op['referencia']);$q->execute();$task=$q->get_result()->fetch_assoc();$q->close();if($task)continue;}
                $message=$confirmed?($source?'La extensión '.$op['destino_extension'].' no contestó a '.$op['numero'].'. Coordina la devolución; no se confirma retorno a recepción.':'No contestaste la transferencia de '.$op['numero'].'. Coordina con recepción antes de llamar.'):'La respuesta de la extensión '.$op['destino_extension'].' aún no está confirmada. Coordina con recepción y revisa el historial antes de llamar.';
                $llave='transferencia:'.$op['id'].':'.$op['intento'].':'.$op['estado'];
                $this->operativasActuales[$llave]=true;
                $this->agregar($user,$ext,$llave,$op['numero'],$date,$confirmed?'Transferencia no contestada':'Transferencia sin confirmación',$message);continue;
            }
            if($source||$op['estado']!=='DEVUELTA'||!empty($op['destino_atendida']))continue;
            $date=(new DateTimeImmutable($op['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
            $llave='consulta:'.$op['id'].':'.$op['intento'];
            $this->operativasActuales[$llave]=true;
            $this->agregar($user,$ext,$llave,$op['numero'],$date,'Transferencia sin respuesta','Recepción intentó comunicarte con '.$op['numero'].'. Coordina con la extensión '.$op['origen_extension'].' antes de devolver la llamada.');
        }
        }catch(Throwable $e){error_log('[campana_transferencias] '.get_class($e));}
    }
    public function pendientes(int $user,bool $recepcion): array {
        if($this->esVentas($user)){require_once __DIR__.'/TelefoniaNotificacionVentasService.php';return (new TelefoniaNotificacionVentasService($this->db))->pendientes($user);}
        $this->sincronizar($user,$recepcion);$ext=$this->extension($user);
        // Las obligaciones pendientes siguen en la campana aunque estén leídas.
        // Una alerta informativa de llamada perdida sí se puede archivar al leer.
        // Filtrar por estados vivos ANTES del LIMIT para no desplazar alertas recientes.
        $s=$this->db->prepare("SELECT * FROM telefonia_notificaciones
            WHERE usuario_id=? AND extension=? AND
            (leida=0 OR llave LIKE 'devolucion:%' OR llave LIKE 'transferencia:%')
            ORDER BY id DESC LIMIT 200");
        $s->bind_param('is',$user,$ext);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        $rows=array_values(array_filter($rows,function($row){
            $llave=(string)$row['llave'];
            return self::esOperativa($llave)?isset($this->operativasActuales[$llave]):(int)$row['leida']===0;
        }));
        $rows=array_slice($rows,0,20);
        return array_map(function($r)use($recepcion){
            $llave = (string)$r['llave'];
            $callback = str_starts_with($llave, 'devolucion:');
            $unconfirmed = str_ends_with($llave, ':SIN_CONFIRMAR');
            // Mantener cada alerta en el módulo operativo correspondiente.
            // Sólo las llamadas con un PBX concreto llevan al registro exacto;
            // una operación de transferencia no garantiza ficha/gravación.
            $url = 'index.php?controller=telefonia&action=misLlamadas&vista='
                . ($callback ? 'devoluciones' : ($recepcion ? 'recepcion' : 'recibidas'));
            if (!$recepcion && str_starts_with($llave, 'llamada:')) {
                $pbx = substr($llave, strlen('llamada:'));
                if ($pbx !== '') {
                    $url .= '&detalle=1&pbx_call_id=' . rawurlencode($pbx);
                }
            }
            return ['id'=>-(int)$r['id'],'telefonia_id'=>(int)$r['id'],'tipo_evento'=>'llamada_perdida','tipo'=>'TELEFONIA_PERDIDA','leida'=>(int)$r['leida'],
                'titulo'=>$r['titulo'],'mensaje'=>$r['mensaje'],'created_at'=>$r['created_at'],'url'=>$url,
                'nombre_entidad'=>$r['titulo'].' · '.$r['numero'],'accion'=>$r['mensaje'],'icono'=>$callback?'bi-telephone-forward':'bi-telephone-x','etiqueta'=>$callback?'Por devolver':($unconfirmed?'Por confirmar':'Sin respuesta'),'estado'=>'informativa','fecha'=>$r['created_at'],'prioridad'=>1,
                'pendiente_operativo'=>self::esOperativa($llave)];
        },$rows);
    }
    public function leer(int $user,int $id): bool {
        if($this->esVentas($user)){
            $s=$this->db->prepare('UPDATE telefonia_notificaciones SET leida=1 WHERE usuario_id=? AND id=?');$s->bind_param('ii',$user,$id);$s->execute();$s->close();
            $s=$this->db->prepare('SELECT id FROM telefonia_notificaciones WHERE usuario_id=? AND id=?');$s->bind_param('ii',$user,$id);$s->execute();$ok=$s->get_result()->num_rows===1;$s->close();return $ok;
        }
        $ext=$this->extension($user);$s=$this->db->prepare('UPDATE telefonia_notificaciones SET leida=1 WHERE usuario_id=? AND extension=? AND id=?');
        $s->bind_param('isi',$user,$ext,$id);$ok=$s->execute();$s->close();return $ok;
    }
    public function leerTodas(int $user): void {
        if($this->esVentas($user)){$s=$this->db->prepare('UPDATE telefonia_notificaciones SET leida=1 WHERE usuario_id=?');$s->bind_param('i',$user);$s->execute();$s->close();return;}
        $ext=$this->extension($user);$s=$this->db->prepare('UPDATE telefonia_notificaciones SET leida=1 WHERE usuario_id=? AND extension=?');$s->bind_param('is',$user,$ext);$s->execute();$s->close();
    }
}
