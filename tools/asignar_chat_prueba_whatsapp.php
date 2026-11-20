<?php
/** Solo el chat entrante Prueba CRM 2 del canal de prueba confirmado. No asigna prospectos. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

function waPruebaRows(mysqli $db, string $sql, array $args=[]): array
{
    $stmt=$db->prepare($sql);
    try {
        if($args) {
            $types=''; foreach($args as $arg)$types.=is_int($arg)?'i':'s';
            $stmt->bind_param($types,...$args);
        }
        $stmt->execute(); return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally { $stmt->close(); }
}

function waAsignarChatPrueba(mysqli $db, bool $apply, string $site, string $backupRoot=''): array
{
    $backup=null;
    $locked=false;
    try {
        if($apply) {
            $root=realpath($backupRoot);
            $site=realpath($site);
            if(!$root || !$site || !is_dir($root) || $root===$site ||
                str_starts_with($root.DIRECTORY_SEPARATOR,$site.DIRECTORY_SEPARATOR)) {
                throw new DomainException('El respaldo debe ser un directorio existente fuera del sitio público.');
            }
            $lock=waPruebaRows($db,"SELECT GET_LOCK('impe_wa_chat_prueba_1508758925647776',5) AS ok");
            if((int)($lock[0]['ok']??0)!==1)throw new DomainException('La prueba está ocupada; no se hicieron cambios.');
            $locked=true;
        }
        $db->begin_transaction($apply?0:MYSQLI_TRANS_START_READ_ONLY);
        $suffix=$apply?' FOR UPDATE':'';
        $accounts=waPruebaRows($db,"SELECT a.id,a.usuario_id,u.nombre,u.apellidos,u.estado,
            r.nombre AS rol,r.estado AS rol_activo
            FROM whatsapp_cuentas a JOIN usuarios u ON u.id=a.usuario_id
            JOIN roles r ON r.id=u.rol_id
            WHERE a.phone_number_id=? AND a.tipo='PRUEBA' AND a.activo=1".$suffix,['1508758925647776']);
        if(count($accounts)!==1)throw new DomainException('El canal debe estar activo, ser de prueba y tener usuario asignado.');
        $account=$accounts[0];
        $name=mb_strtolower(trim($account['nombre'].' '.$account['apellidos']),'UTF-8');
        if($name!=='prueba llamadas' || $account['rol']!=='Asesor de Ventas' ||
            (int)$account['estado']!==1 || (int)$account['rol_activo']!==1) {
            throw new DomainException('El usuario del canal no corresponde al asesor activo prueba llamadas.');
        }
        $permissions=waPruebaRows($db,"SELECT DISTINCT p.codigo FROM permisos p
            JOIN rol_permisos rp ON rp.permiso_id=p.id JOIN usuarios u ON u.rol_id=rp.rol_id
            WHERE u.id=? AND p.estado=1 AND p.codigo IN ('whatsapp.ver','whatsapp.enviar')",[(int)$account['usuario_id']]);
        if(count($permissions)!==2)throw new DomainException('El asesor requiere Ver conversaciones y Enviar mensajes. No se concedieron permisos.');
        $conversations=waPruebaRows($db,"SELECT c.* FROM whatsapp_conversaciones c WHERE c.cuenta_id=?
            AND EXISTS(SELECT 1 FROM whatsapp_mensajes m WHERE m.conversacion_id=c.id
                AND m.direccion='ENTRANTE' AND m.tipo='TEXT' AND m.contenido=?)
            ORDER BY c.id LIMIT 2".$suffix,[(int)$account['id'],'Prueba CRM 2']);
        if(count($conversations)!==1)throw new DomainException('Debe existir un único chat con el mensaje entrante Prueba CRM 2 en el canal nuevo.');
        $conversation=$conversations[0];
        if(!empty($conversation['aliado_seguimiento_id']))throw new DomainException('El chat ya pertenece a un seguimiento; se conserva.');
        $links=waPruebaRows($db,'SELECT prospecto_id FROM whatsapp_conversacion_prospectos WHERE conversacion_id=?'.$suffix,[(int)$conversation['id']]);
        if($links)throw new DomainException('El chat ya está enlazado a un prospecto; su cartera se conserva.');
        $uid=(int)$account['usuario_id'];$owner=(int)($conversation['responsable_usuario_id']??0);
        if($owner>0 && $owner!==$uid)throw new DomainException('La conversación ya tiene otro responsable; se conserva.');
        $result=['conversacion_id'=>(int)$conversation['id'],'asesor_id'=>$uid,'asesor'=>'prueba llamadas',
            'ya_asignada'=>$owner===$uid];
        if(!$apply || $owner===$uid) {
            $db->rollback(); return $result;
        }
        $backup=$root.'/chat-prueba-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
        $record=['site'=>$site,'database'=>waPruebaRows($db,'SELECT DATABASE() AS nombre')[0]['nombre'],
            'cuenta_id'=>(int)$account['id'],'phone_number_id'=>'1508758925647776',
            'conversacion_id'=>(int)$conversation['id'],'responsable_anterior'=>$conversation['responsable_usuario_id'],
            'responsable_nuevo'=>$uid,'operacion'=>'asignar solo conversación de prueba; no modifica prospectos'];
        $data=json_encode($record,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        $handle=fopen($backup,'x');
        if(!$handle)throw new RuntimeException('No se pudo crear el respaldo privado.');
        try { if(fwrite($handle,$data)!==strlen($data) || !fflush($handle))throw new RuntimeException('No se pudo escribir el respaldo.'); }
        finally { fclose($handle); }
        chmod($backup,0600);
        if(file_get_contents($backup)!==$data)throw new RuntimeException('El respaldo no pasó la verificación.');
        $stmt=$db->prepare('UPDATE whatsapp_conversaciones SET responsable_usuario_id=? WHERE id=? AND COALESCE(responsable_usuario_id,0)=0');
        $id=(int)$conversation['id'];$stmt->bind_param('ii',$uid,$id);$stmt->execute();
        if($stmt->affected_rows!==1)throw new RuntimeException('La conversación cambió; se revierte la prueba.');
        $stmt->close();$db->commit();$result['respaldo']=$backup;return $result;
    } catch(Throwable $e) {
        try{$db->rollback();}catch(Throwable $ignored){}
        throw $e;
    } finally {
        if($locked)try{$db->query("SELECT RELEASE_LOCK('impe_wa_chat_prueba_1508758925647776')");}catch(Throwable $ignored){}
    }
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===realpath(__FILE__)) {
    umask(0077);mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $options=getopt('',['site:','backup-root:','check','apply']);
    try {
        if(isset($options['check'])===isset($options['apply']) || !isset($options['site']))throw new DomainException('Usa --site=RUTA y --check o --apply.');
        $site=realpath($options['site']);if(!$site || !is_file($site.'/index.php'))throw new DomainException('Sitio no válido.');
        require $site.'/config/db_connection.php';
        $db=(new Database())->connect(false);
        $result=waAsignarChatPrueba($db,isset($options['apply']),$site,(string)($options['backup-root']??''));
        echo (isset($options['apply'])?'ASIGNACION_OK':'CHECK_OK').' '.json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
    } catch(DomainException $e) {fwrite(STDERR,'BLOQUEADO: '.$e->getMessage().PHP_EOL);exit(1);}
    catch(Throwable $e) {fwrite(STDERR,'NO_COMPLETADO: se conserva la asignación anterior; no se muestran datos privados.'.PHP_EOL);exit(1);}
}
