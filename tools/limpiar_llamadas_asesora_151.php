<?php
declare(strict_types=1);

/** CLI privado. Plan y respaldo fuera de public_html; jamás usa la API del proveedor. */
final class LimpiezaLlamadasAsesora151
{
    private mysqli $db;
    public const PROTECCION=[
        'app/services/TelefoniaLimpiezaGuard.php'=>['17d03adc29084f54b7cdb7b8c09594b9f4da257dc469b674f221b4cdeae227bf'],
        'app/services/TelefoniaVentasLlamadasService.php'=>['27368e9c7a9f661cc2638364353ca23b3065bf7a172452e43d284386c264ede2'],
    ];
    public function __construct(mysqli $db) { $this->db=$db; }
    private function rows(string $sql,array $args=[]): array {
        $s=$this->db->prepare($sql);if($args)$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();$r=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $r;
    }
    private function tabla(string $t): bool {
        return (bool)$this->rows('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$t]);
    }
    private function leer(string $t): array {
        if(!$this->tabla($t))return [];
        $r=$this->rows("SELECT * FROM `$t` LIMIT 100001");
        if(count($r)>100000)throw new RuntimeException('Más de 100,000 filas en '.$t.'. Se requiere un corte más acotado.');
        return $r;
    }
    private function estructura(string $t): array {
        $info=$this->rows('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$t]);
        if(($info[0]['ENGINE']??'')!=='InnoDB')throw new RuntimeException('La tabla '.$t.' debe permitir transacciones.');
        $pk=$this->rows("SHOW INDEX FROM `$t` WHERE Key_name='PRIMARY'");usort($pk,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
        if(!$pk)throw new RuntimeException('Falta clave primaria verificable en '.$t);
        $create=$this->rows("SHOW CREATE TABLE `$t`")[0]['Create Table'];
        return ['pk'=>array_column($pk,'Column_name'),'sha'=>hash('sha256',preg_replace('/ AUTO_INCREMENT=\d+/','',$create))];
    }
    private static function key(array $row,array $pk): string {
        return json_encode(array_map(static fn($k)=>$row[$k],$pk),JSON_THROW_ON_ERROR);
    }
    public static function digest(array $data): string { return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
    private function grupo(string $t,array $rows,string $action='delete'): array {
        $s=$this->estructura($t);usort($rows,static fn($a,$b)=>strcmp(self::key($a,$s['pk']),self::key($b,$s['pk'])));
        return ['tabla'=>$t,'accion'=>$action,'estructura'=>$s,'filas'=>$rows];
    }
    /** Objetivo fijo: ninguna opción permite ampliar esta limpieza a otras cuentas. */
    private const UID=15;
    private const EXT='151';
    private function columnas(string $table): array {
        return array_column($this->rows('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',[$table]),'COLUMN_NAME');
    }
    private function comprobarTablasControl(): void {
        foreach(['telefonia_crm_exclusiones','telefonia_crm_limpiezas'] as $table){
            if(!$this->tabla($table))throw new RuntimeException('Falta la protección ya existente del CRM: '.$table.'. No se modificó nada.');
            $this->estructura($table);
        }
    }
    public function plan(?array $cut=null): array {
        $this->comprobarTablasControl();
        $clock=$this->rows('SELECT DATABASE() db,UTC_TIMESTAMP() utc,NOW() reloj')[0];
        $cut=$cut??['utc'=>$clock['utc'],'db'=>$clock['reloj'],'mx'=>(new DateTimeImmutable($clock['utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s')];
        $users=$this->rows('SELECT id,nombre,apellidos,rol_id,estado FROM usuarios WHERE id=?',[self::UID]);
        if(count($users)!==1||(int)$users[0]['rol_id']!==3)throw new RuntimeException('La cuenta 15 ya no corresponde a una asesora; limpieza bloqueada.');
        $name=mb_strtolower(trim($users[0]['nombre'].' '.$users[0]['apellidos']));
        if(!preg_match('/^yazm[ií]n\s+hurtado$/u',$name))throw new RuntimeException('La cuenta 15 no coincide con Yazmin Hurtado; no se borró nada.');
        $assigned=$this->rows("SELECT usuario_id,extension,proveedor,activo,created_at,updated_at FROM telefonia_extensiones WHERE (usuario_id=? AND activo=1) OR (extension=? AND activo=1) ORDER BY usuario_id,extension",[self::UID,self::EXT]);
        if(count($assigned)!==1||(int)$assigned[0]['usuario_id']!==self::UID||$assigned[0]['extension']!==self::EXT||$assigned[0]['proveedor']!=='ZADARMA')throw new RuntimeException('La asignación vigente no es única para Yazmin y la extensión 151.');
        $groups=[];$refs=[];
        $pair=static function(string $ref)use(&$refs):void{
            if($ref==='')return;
            if(!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$ref))throw new RuntimeException('Referencia no reconocida. No se borró nada.');
            $refs[$ref]=true;
        };
        // Las evidencias PBX permanecen completas, incluidos los tramos de Recepción.
        // Sólo se excluyen las referencias antiguas de esta extensión en el CRM.
        $since=(new DateTimeImmutable(max($assigned[0]['created_at'],$assigned[0]['updated_at']),new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
        $events=$this->rows('SELECT pbx_call_id,evento,received_at FROM telefonia_zadarma_eventos WHERE internal=? AND received_at>=? AND received_at<=? ORDER BY id',[self::EXT,$since,$cut['mx']]);
        $state=[];
        foreach($events as $event){$pair((string)$event['pbx_call_id']);$state[$event['pbx_call_id']][]=$event;}
        $recent=(new DateTimeImmutable($clock['utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->modify('-15 minutes')->format('Y-m-d H:i:s');
        foreach($state as $rows){
            $started='';$ended='';
            foreach($rows as $r){if(in_array($r['evento'],['NOTIFY_OUT_START','NOTIFY_INTERNAL','NOTIFY_ANSWER'],true))$started=max($started,$r['received_at']);if(in_array($r['evento'],['NOTIFY_OUT_END','NOTIFY_END'],true))$ended=max($ended,$r['received_at']);}
            if($started>=$recent&&$ended<$started)throw new RuntimeException('Hay una llamada reciente sin cierre confirmado en la extensión 151. Finalízala y espera su confirmación antes de limpiar.');
        }
        $tables=array_column($this->rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"),'TABLE_NAME');
        $resultIds=[];$callIds=[];
        foreach(['telefonia_ventas_resultados','telefonia_ventas_llamadas'] as $table){
            if(!$this->tabla($table))continue;
            $rows=$this->rows("SELECT * FROM `$table` WHERE usuario_id=? AND extension=?",[self::UID,self::EXT]);
            $selected=[];foreach($rows as $r){$date=$table==='telefonia_ventas_llamadas'?$r['fecha']:($r['created_at']??'');$zone=$table==='telefonia_ventas_llamadas'?'mx':'db';if($date!==''&&$date>$cut[$zone])continue;$selected[]=$r;$pair((string)$r['pbx_call_id']);}
            if($selected)$groups[$table]=$this->grupo($table,$selected);
            if($table==='telefonia_ventas_resultados')$resultIds=array_map('intval',array_column($selected,'id'));else $callIds=array_map('intval',array_column($selected,'id'));
        }
        foreach($tables as $table){
            if(isset($groups[$table])||in_array($table,['telefonia_ventas_resultados','telefonia_ventas_llamadas'],true))continue;
            $commercial=str_starts_with($table,'telefonia_ventas_');
            if(!$commercial&&!in_array($table,['telefonia_borradores','telefonia_devolucion_intentos','telefonia_notificaciones','telefonia_webrtc_grabaciones_internas'],true))continue;
            $cols=$this->columnas($table);$selected=[];
            // Las notas pertenecen al resultado aunque su autor sea un supervisor.
            if($commercial&&(in_array('resultado_id',$cols,true)||in_array('llamada_id',$cols,true))){
                $ids=in_array('resultado_id',$cols,true)?$resultIds:$callIds;
                $field=in_array('resultado_id',$cols,true)?'resultado_id':'llamada_id';
                if($ids)$selected=$this->rows("SELECT * FROM `$table` WHERE `$field` IN (".implode(',',array_fill(0,count($ids),'?')).')',$ids);
            // Se seleccionan registros de llamada por propietario Y extensión.
            }elseif(in_array('usuario_id',$cols,true)&&in_array('extension',$cols,true)&&(in_array('pbx_call_id',$cols,true)||$table==='telefonia_notificaciones'||$table==='telefonia_borradores')){
                foreach($this->rows("SELECT * FROM `$table` WHERE usuario_id=? AND extension=?",[self::UID,self::EXT]) as $r){
                    if(!empty($r['created_at'])&&$r['created_at']>$cut[$table==='telefonia_notificaciones'?'mx':'db'])continue;
                    if($table==='telefonia_notificaciones'&&!preg_match('/^(?:llamada:|transferencia:|consulta:|devolucion:|ventas:(?:llamada:|transferencia:|devolucion:|programada:resultado:))/',(string)$r['llave']))continue;
                    if($table==='telefonia_ventas_marcaciones'&&empty($r['pbx_call_id'])&&$r['created_at']>date('Y-m-d H:i:s',strtotime($clock['reloj'])-90))throw new RuntimeException('Hay una marcación muy reciente sin referencia. Espera a que termine antes de limpiar.');
                    $pair((string)($r['pbx_call_id']??''));$selected[]=$r;
                }
            }
            if($selected)$groups[$table]=$this->grupo($table,$selected);
        }
        // Metadatos de las transferencias asociadas a la cuenta objetivo.
        foreach(['telefonia_disponibilidad','telefonia_transferencia_operaciones'] as $table){
            if(!$this->tabla($table))continue;
            $rows=$this->rows("SELECT * FROM `$table` WHERE ((destino_usuario=? AND destino_extension=?) OR (origen_usuario=? AND origen_extension=?)) AND created_at<=?",[self::UID,self::EXT,self::UID,self::EXT,$cut['utc']]);
            foreach($rows as $r)$pair((string)$r['referencia']);
            if($rows)$groups[$table]=$this->grupo($table,$rows);
        }
        // Quitar sólo el recordatorio que sea espejo exacto de un resultado borrado.
        // Los contactos y citas independientes de la agenda permanecen intactos.
        $agenda=[];
        if($this->tabla('telefonia_contactos_personales')&&in_array('proxima_accion_at',$this->columnas('telefonia_contactos_personales'),true)){
            foreach($groups['telefonia_ventas_resultados']['filas']??[] as $r){
                if(empty($r['contacto_id'])||empty($r['proxima_accion_at'])||!empty($r['recordatorio_atendido_at']))continue;
                foreach($this->rows('SELECT * FROM telefonia_contactos_personales WHERE usuario_id=? AND id=? AND proxima_accion_at=?',[self::UID,$r['contacto_id'],$r['proxima_accion_at']]) as $a)$agenda[$a['id']]=$a;
            }
            if($agenda)$groups['telefonia_contactos_personales']=$this->grupo('telefonia_contactos_personales',array_values($agenda),'agenda');
        }
        if($agenda&&$this->tabla('telefonia_notificaciones')){
            $notices=[];foreach($groups['telefonia_notificaciones']['filas']??[] as $r)$notices[$r['id']]=$r;
            foreach($this->rows('SELECT * FROM telefonia_notificaciones WHERE usuario_id=?',[self::UID]) as $r){
                if(preg_match('/^ventas:programada:agenda:(\d+):/',(string)$r['llave'],$m)&&isset($agenda[(int)$m[1]])&&$r['created_at']<=$cut['mx'])$notices[$r['id']]=$r;
            }
            if($notices)$groups['telefonia_notificaciones']=$this->grupo('telefonia_notificaciones',array_values($notices));
        }
        // Ni las cascadas ni las dependencias desconocidas se eliminan implícitamente.
        $fks=$this->rows('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,CONSTRAINT_NAME,ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION');
        $constraints=[];foreach($fks as $fk)$constraints[$fk['TABLE_NAME'].'|'.$fk['CONSTRAINT_NAME']][]=$fk;
        foreach($groups as $table=>$group){
            $operation=$group['accion']==='delete'?'DELETE':'UPDATE';
            if($this->rows('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? AND EVENT_MANIPULATION=?',[$table,$operation]))throw new RuntimeException('Hay un disparador en '.$table.'. Se requiere revisar sus efectos antes de limpiar.');
            if($group['accion']!=='delete')continue;
            foreach($constraints as $constraint){
                if($constraint[0]['REFERENCED_TABLE_NAME']!==$table)continue;
                $child=$constraint[0]['TABLE_NAME'];
                foreach($group['filas'] as $parent){
                    $where=[];$args=[];
                    foreach($constraint as $fk){$where[]='`'.$fk['COLUMN_NAME'].'`=?';$args[]=$parent[$fk['REFERENCED_COLUMN_NAME']];}
                    $children=$this->rows("SELECT * FROM `$child` WHERE ".implode(' AND ',$where),$args);
                    foreach($children as $row){
                        if(!isset($groups[$child])||!in_array(self::key($row,$groups[$child]['estructura']['pk']),array_map(static fn($r)=>self::key($r,$groups[$child]['estructura']['pk']),$groups[$child]['filas']),true))throw new RuntimeException('Dependencia no incluida en el respaldo: '.$child.'. No se borró nada.');
                    }
                }
            }
        }
        // Orden de borrado: hijos antes que padres; restauración en orden inverso.
        $ordered=[];$visiting=[];$visit=function(string $table)use(&$visit,&$ordered,&$visiting,$groups,$constraints):void{
            if(isset($ordered[$table]))return;if(isset($visiting[$table]))throw new RuntimeException('Dependencias circulares; se requiere revisión.');$visiting[$table]=true;
            foreach($constraints as $constraint)if($constraint[0]['REFERENCED_TABLE_NAME']===$table&&isset($groups[$constraint[0]['TABLE_NAME']]))$visit($constraint[0]['TABLE_NAME']);
            unset($visiting[$table]);$ordered[$table]=$groups[$table];
        };
        ksort($groups);foreach(array_keys($groups) as $table)$visit($table);
        $blocks=[];ksort($refs);foreach(array_keys($refs) as $ref)$blocks[]=['pbx_call_id'=>$ref,'extension'=>self::EXT];
        return ['version'=>151,'base'=>$cut['db'],'corte'=>$cut,'usuarios'=>$users,'asignacion'=>$assigned,'grupos'=>array_values($ordered),'exclusiones'=>$blocks,'compartidas_protegidas'=>count($refs)];
    }
    public static function resumen(array $plan): array {
        $counts=[];foreach($plan['grupos'] as $g)$counts[$g['tabla']]=['accion'=>$g['accion']==='agenda'?'quitar_solo_recordatorio_ligado_a_llamada':'eliminar','filas'=>count($g['filas'])];
        return ['objetivo'=>'Yazmin Hurtado · cuenta 15 · extensión 151','corte_utc'=>$plan['corte']['utc'],'registros'=>$counts,'exclusiones_solo_extension_151'=>count($plan['exclusiones']),'eventos_pbx'=>'Se conservan completos','agenda_y_otras_cuentas'=>'Sin cambios','huella'=>self::digest($plan)];
    }
    private function esquemaLimpieza(): void { $this->comprobarTablasControl(); }

    private function wherePk(array $row,array $pk): array {
        return [implode(' AND ',array_map(static fn($c)=>"`$c` <=> ?",$pk)),array_map(static fn($c)=>$row[$c],$pk)];
    }
    public function aplicar(array $plan,string $id,string $backupSha): void {
        if(($plan['version']??0)!==151)throw new RuntimeException('El plan no pertenece a esta limpieza de la extensión 151.');
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^[a-f0-9]{64}$/D',$backupSha))throw new RuntimeException('Identificación de respaldo inválida.');
        if($this->tabla('telefonia_crm_limpiezas')) {
            $old=$this->rows('SELECT * FROM telefonia_crm_limpiezas WHERE id=?',[$id]);
            if($old&&$old[0]['backup_sha']===$backupSha&&$old[0]['estado']==='APLICADA')return;
            if($old)throw new RuntimeException('Ese plan ya se utilizó con otro respaldo o fue restaurado.');
        }
        if(self::digest($this->plan($plan['corte']))!==self::digest($plan))throw new RuntimeException('Los datos cambiaron desde la comprobación. Crear otro plan; no se borró nada.');
        $this->esquemaLimpieza();
        $old=$this->rows('SELECT * FROM telefonia_crm_limpiezas WHERE id=?',[$id]);
        if($old)throw new RuntimeException('Ese plan ya fue aplicado o restaurado. Crear otro plan si es necesario.');
        $this->db->begin_transaction();
        try {
            // Congelar la identidad/asignación antes de revalidar las filas del plan.
            $this->rows('SELECT id FROM usuarios WHERE id=? FOR UPDATE',[self::UID]);
            $this->rows('SELECT usuario_id FROM telefonia_extensiones WHERE usuario_id=? OR extension=? FOR UPDATE',[self::UID,self::EXT]);
            // Revalidar dentro de la transacción, antes de la primera eliminación.
            if(self::digest($this->plan($plan['corte']))!==self::digest($plan))throw new RuntimeException('Cambio concurrente; limpieza cancelada.');
            foreach($plan['grupos'] as $g)foreach($g['filas'] as $row) {
                [$where,$args]=$this->wherePk($row,$g['estructura']['pk']);
                $locked=$this->rows("SELECT * FROM `{$g['tabla']}` WHERE $where FOR UPDATE",$args);
                if(count($locked)!==1||self::digest($locked[0])!==self::digest($row))throw new RuntimeException('Una fila cambió; limpieza cancelada.');
            }
            foreach($plan['exclusiones'] as $r) {
                $s=$this->db->prepare('INSERT IGNORE INTO telefonia_crm_exclusiones VALUES(?,?,?)');$s->bind_param('sss',$r['pbx_call_id'],$r['extension'],$id);$s->execute();$s->close();
            }
            foreach($plan['grupos'] as $g)foreach($g['filas'] as $row) {
                [$where,$args]=$this->wherePk($row,$g['estructura']['pk']);
                $keepUpdated=array_key_exists('updated_at',$row)?',updated_at=updated_at':'';
                $sql=$g['accion']==='agenda'?"UPDATE `{$g['tabla']}` SET proxima_accion_at=NULL$keepUpdated WHERE $where":"DELETE FROM `{$g['tabla']}` WHERE $where";
                $s=$this->db->prepare($sql);$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();if($s->affected_rows!==1)throw new RuntimeException('No se pudo completar una fila; rollback.');$s->close();
            }
            $s=$this->db->prepare("INSERT INTO telefonia_crm_limpiezas VALUES(?,?,'APLICADA',UTC_TIMESTAMP())");$s->bind_param('ss',$id,$backupSha);$s->execute();$s->close();
            $this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function restaurar(array $plan,string $id,string $backupSha): void {
        if(($plan['version']??0)!==151)throw new RuntimeException('Plan de otra limpieza.');
        $run=$this->rows('SELECT * FROM telefonia_crm_limpiezas WHERE id=? AND backup_sha=?',[$id,$backupSha]);
        if(($run[0]['estado']??'')!=='APLICADA')throw new RuntimeException('El respaldo no corresponde a una limpieza aplicada.');
        $this->db->begin_transaction();
        try {
            foreach(array_reverse($plan['grupos']) as $g) {
                if($this->estructura($g['tabla'])!==$g['estructura'])throw new RuntimeException('La estructura cambió; restauración detenida.');
                foreach($g['filas'] as $row) {
                    [$where,$args]=$this->wherePk($row,$g['estructura']['pk']);$now=$this->rows("SELECT * FROM `{$g['tabla']}` WHERE $where FOR UPDATE",$args);
                    if($g['accion']==='agenda') {
                        if(count($now)!==1||$now[0]['proxima_accion_at']!==null||(int)$now[0]['usuario_id']!==(int)$row['usuario_id'])throw new RuntimeException('La agenda cambió; no se sobrescribirá.');
                        $keepUpdated=array_key_exists('updated_at',$row)?',updated_at=updated_at':'';
                        $sql="UPDATE `{$g['tabla']}` SET proxima_accion_at=?$keepUpdated WHERE $where";$values=array_merge([$row['proxima_accion_at']],$args);
                    }else {
                        if($now)throw new RuntimeException('La clave ya tiene datos posteriores; no se sobrescribirá.');
                        $columns=array_keys($row);$sql="INSERT INTO `{$g['tabla']}` (`".implode('`,`',$columns).'`) VALUES('.implode(',',array_fill(0,count($columns),'?')).')';$values=array_values($row);
                    }
                    $s=$this->db->prepare($sql);$s->bind_param(str_repeat('s',count($values)),...$values);$s->execute();$s->close();
                }
            }
            $s=$this->db->prepare('DELETE FROM telefonia_crm_exclusiones WHERE limpieza_id=?');$s->bind_param('s',$id);$s->execute();$s->close();
            $s=$this->db->prepare("UPDATE telefonia_crm_limpiezas SET estado='RESTAURADA' WHERE id=?");$s->bind_param('s',$id);$s->execute();$s->close();$this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
}

if(PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try {
        umask(0077);
        $a=getopt('',['site:','backup-root:','check','apply:','restore:']);
        $root=realpath((string)($a['site']??''));$private=(string)($a['backup-root']??'');
        if(!$root||!is_file($root.'/index.php')||$private===''||!preg_match('~^(?:/|[a-zA-Z]:/)~',str_replace('\\','/',$private)))throw new RuntimeException('Indica --site y --backup-root con rutas absolutas.');
        $parent=realpath(dirname($private));
        if(!$parent)throw new RuntimeException('La carpeta padre del respaldo debe existir.');
        $private=is_dir($private)?realpath($private):$parent.DIRECTORY_SEPARATOR.basename($private);
        $sitePath=rtrim(str_replace('\\','/',$root),'/').'/';$privatePath=rtrim(str_replace('\\','/',$private),'/').'/';
        if(str_starts_with(strtolower($privatePath),strtolower($sitePath)))throw new RuntimeException('El respaldo debe quedar fuera de public_html.');
        if(!is_dir($private)&&!mkdir($private,0700))throw new RuntimeException('No se pudo crear la carpeta privada.');
        chmod($private,0700);
        if(count(array_intersect(['check','apply','restore'],array_keys($a)))!==1)throw new RuntimeException('Usa únicamente --check, --apply PLAN o --restore PLAN.');
        require_once $root.'/config/db_connection.php';mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=(new Database())->connect(false);$tool=new LimpiezaLlamadasAsesora151($db);
        if(isset($a['check'])) {
            $plan=$tool->plan();$id=bin2hex(random_bytes(16));$bytes=gzencode(json_encode(['id'=>$id,'plan'=>$plan],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),9);
            $file=$private.'/plan-'.$id.'.json.gz';$f=fopen($file,'xb');if(!$f)throw new RuntimeException('No se pudo guardar el respaldo previo.');
            try{if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||!fsync($f))throw new RuntimeException('Respaldo incompleto.');}finally{fclose($f);}chmod($file,0600);
            echo json_encode(LimpiezaLlamadasAsesora151::resumen($plan)+['plan_privado'=>$file,'estado'=>'CHECK OK: base de datos sin cambios; respaldo previo guardado.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
        }else {
            $file=realpath((string)($a['apply']??$a['restore']));
            if(!$file||is_link((string)($a['apply']??$a['restore']))||dirname($file)!==$private||!preg_match('/^plan-[a-f0-9]{32}\.json\.gz$/D',basename($file)))throw new RuntimeException('Plan privado no válido.');
            $bytes=file_get_contents($file);$saved=json_decode(gzdecode($bytes),true,512,JSON_THROW_ON_ERROR);$sha=hash('sha256',$bytes);
            if(isset($a['apply'])) {
                foreach(LimpiezaLlamadasAsesora151::PROTECCION as $path=>$hashes) {
                    $source=is_file($root.'/'.$path)?str_replace("\r\n","\n",file_get_contents($root.'/'.$path)):'';
                    if(!in_array(hash('sha256',$source),$hashes,true))throw new RuntimeException('Protección no instalada o versión diferente: '.$path);
                }
                $tool->aplicar($saved['plan'],$saved['id'],$sha);echo 'APLICADO: llamadas de Yazmin (cuenta 15, extensión 151) retiradas del CRM. Archivos del sistema, otras cuentas y Zadarma intactos. Respaldo: '.$file.PHP_EOL;
            }else {$tool->restaurar($saved['plan'],$saved['id'],$sha);echo 'RESTAURADO: filas originales recuperadas; Zadarma no se modificó.'.PHP_EOL;}
        }
    }catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
}elseif(PHP_SAPI!=='cli'){http_response_code(404);exit;}
