<?php
declare(strict_types=1);
// Herramienta CLI de entrega. Nunca se incorpora al router ni recibe solicitudes web.
if(PHP_SAPI!=='cli' || count($argv)!==4)exit(2);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
const WA_SCOPE_CODES=['whatsapp.gestionar_conversaciones','whatsapp.ver_todas','whatsapp.responder_todas'];
function estadoWa(mysqli $db,bool $lock=false): array {
    $q="SELECT id,modulo,codigo,nombre,descripcion,estado FROM permisos WHERE codigo IN ('whatsapp.gestionar_conversaciones','whatsapp.ver_todas','whatsapp.responder_todas') ORDER BY id".($lock?' FOR UPDATE':'');
    $rows=$db->query($q)->fetch_all(MYSQLI_ASSOC);
    if(count($rows)!==3 || count(array_unique(array_column($rows,'codigo')))!==3)throw new RuntimeException('Catálogo incompatible.');
    $ids=array_map('intval',array_column($rows,'id'));$marks=implode(',',$ids);
    $relations=$db->query("SELECT rol_id,permiso_id FROM rol_permisos WHERE permiso_id IN ($marks) ORDER BY rol_id,permiso_id".($lock?' FOR UPDATE':''))->fetch_all(MYSQLI_ASSOC);
    return ['permisos'=>$rows,'relaciones'=>$relations];
}
function guardarWa(string $path,array $data): void {
    if(file_exists($path))throw new RuntimeException('El respaldo de permisos ya existe.');
    $tmp=tempnam(dirname($path),'.wa-scope-');
    try {
        chmod($tmp,0600);$f=fopen($tmp,'wb');
        try{fwrite($f,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));fflush($f);if(function_exists('fsync'))fsync($f);}finally{fclose($f);}
        if(!rename($tmp,$path))throw new RuntimeException('No se pudo verificar el respaldo.');
    }finally{if(is_file($tmp))unlink($tmp);}
}
try {
    [$script,$site,$mode,$snapshot]=$argv;
    require $site.'/config/db_connection.php';$db=(new Database())->connect(false);
    foreach(['permisos','rol_permisos','roles'] as $table) {
        $s=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $s->bind_param('s',$table);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
        if(!$r || strtoupper($r['ENGINE'])!=='INNODB')throw new RuntimeException('Motor de permisos incompatible.');
    }
    if($mode==='check') {
        estadoWa($db);echo json_encode(['ok'=>true,'modo'=>'check']);exit;
    }
    $db->begin_transaction();
    try {
        $before=estadoWa($db,true);
        if($mode==='apply') {
            $ids=array_column($before['permisos'],'id','codigo');
            $global=(int)$ids['whatsapp.gestionar_conversaciones'];$duplicate=(int)$ids['whatsapp.ver_todas'];$reply=(int)$ids['whatsapp.responder_todas'];
            // Conservar consultas globales explícitas de los demás roles, sin conceder envío.
            $db->query("INSERT IGNORE INTO rol_permisos(rol_id,permiso_id)
                SELECT rp.rol_id,$global FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id
                WHERE rp.permiso_id=$duplicate AND p.estado=1 AND rp.rol_id<>3");
            $db->query("DELETE FROM rol_permisos WHERE permiso_id=$duplicate
                OR (rol_id=3 AND permiso_id IN ($global,$reply)) OR (rol_id=1 AND permiso_id=$reply)");
            $db->query("UPDATE permisos SET nombre='Consultar todas las conversaciones',
                descripcion='Consultar las conversaciones del equipo. No permite responder ni configurar canales.' WHERE id=$global");
            $db->query("UPDATE permisos SET descripcion='Requiere consultar todas las conversaciones y permiso para enviar mensajes.' WHERE id=$reply");
            $db->query("UPDATE permisos SET estado=0 WHERE id=$duplicate");
            $after=estadoWa($db,true);
            guardarWa($snapshot,['version'=>1,'site'=>realpath($site),'motivo'=>'whatsapp-consulta-unica-y-alcance-asesor-v1','utc'=>gmdate('c'),'before'=>$before,'after'=>$after]);
            $db->commit();echo json_encode(['ok'=>true,'modo'=>'apply']);exit;
        }
        if(!in_array($mode,['verify','restore'],true))throw new RuntimeException('Modo inválido.');
        $backup=json_decode(file_get_contents($snapshot),true,512,JSON_THROW_ON_ERROR);
        if(($backup['version']??null)!==1 || ($backup['site']??'')!==realpath($site))throw new RuntimeException('Respaldo no válido.');
        if($before!==$backup['before'] && $before!==$backup['after'])throw new RuntimeException('Los permisos cambiaron después de la publicación. No se sobrescriben.');
        if($mode==='restore' && $before!==$backup['before']) {
            $ids=array_map('intval',array_column($before['permisos'],'id'));$marks=implode(',',$ids);
            $oldIds=array_map('intval',array_column($backup['before']['permisos'],'id'));
            if($ids!==$oldIds)throw new RuntimeException('Identidades de permisos distintas.');
            $s=$db->prepare('UPDATE permisos SET modulo=?,nombre=?,descripcion=?,estado=? WHERE id=?');
            foreach($backup['before']['permisos'] as $r){$s->bind_param('sssii',$r['modulo'],$r['nombre'],$r['descripcion'],$r['estado'],$r['id']);$s->execute();}$s->close();
            $db->query("DELETE FROM rol_permisos WHERE permiso_id IN ($marks)");
            $s=$db->prepare('INSERT INTO rol_permisos(rol_id,permiso_id) VALUES(?,?)');
            foreach($backup['before']['relaciones'] as $r){if(!in_array((int)$r['permiso_id'],$ids,true))throw new RuntimeException('Relación fuera del alcance.');$s->bind_param('ii',$r['rol_id'],$r['permiso_id']);$s->execute();}$s->close();
            if(estadoWa($db,true)!==$backup['before'])throw new RuntimeException('No se verificó la reversión.');
        }
        $db->commit();echo json_encode(['ok'=>true,'modo'=>$mode]);
    }catch(Throwable $e){$db->rollback();throw $e;}
}catch(Throwable $e){
    fwrite(STDERR,'WHATSAPP_PERMISOS_ERROR: no se pudo verificar o actualizar el alcance; se conservan los cambios posteriores de permisos.'.PHP_EOL);exit(2);
}
