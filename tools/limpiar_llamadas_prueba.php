<?php
declare(strict_types=1);

/** CLI privado. Plan y respaldo fuera de public_html; jamás usa la API del proveedor. */
final class LimpiezaLlamadasPrueba
{
    private mysqli $db;
    public const PROTECCION=[
        'app/services/TelefoniaLimpiezaGuard.php'=>['17d03adc29084f54b7cdb7b8c09594b9f4da257dc469b674f221b4cdeae227bf'],
        'app/services/ZadarmaWebhookEventStoreService.php'=>['81d4aa5b90e25b1ae99902f117485543700904aee6776c47a3ca77e5e7e42067'],
        'app/services/TelefoniaVentasLlamadasService.php'=>['951e7082bf3fc676815eb66298c6b0f9aaa4a83d952988932d978b9682347f1d'],
        'app/services/TelefoniaRecepcionMarketingService.php'=>['3ea80f2a09a7aa16852963940b39a6bc92c074dd355486bbcc9fef5fdec7db46'],
        'app/services/TelefoniaNotificacionService.php'=>['1c2cea7838c75c3e90f4e118180f67c5b11b461d5b14fc516c3389b940e6d3b6','e41aa780935cf14bfd9cb7a98c80ad3a40d372b95aed1039cdcc2c1889952785'],
        'app/services/TelefoniaNotificacionVentasService.php'=>['71c9121ff66d2fd7e72ed0421835e0aacab6269a5ceccfba4ce4f17d4b3cf295'],
        'app/services/TelefoniaControlService.php'=>['e5c7121cf54e991c53729ec0071325b260f7e07c4a4b81e03c726e97d5e05f82'],
    ];
    private const OWNED=[
        'telefonia_ventas_devolucion_intentos'=>'utc',
        'telefonia_devolucion_intentos'=>'utc',
        'telefonia_borradores'=>'',
        'telefonia_ventas_prospecto_llamada'=>'',
        'telefonia_ventas_evidencia'=>'',
        'telefonia_ventas_resultados'=>'db',
        'telefonia_ventas_marcaciones'=>'db',
        'telefonia_ventas_llamadas'=>'mx',
        'telefonia_recepcion_resultados'=>'db',
        'telefonia_notificaciones'=>'mx',
        'telefonia_webrtc_grabaciones_internas'=>'db'
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
    public function plan(?array $cut=null): array {
        $clock=$this->rows('SELECT DATABASE() db,UTC_TIMESTAMP() utc,NOW() reloj')[0];
        $cut=$cut??['utc'=>$clock['utc'],'db'=>$clock['reloj'],'mx'=>(new DateTimeImmutable($clock['utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s')];
        $users=$this->rows('SELECT id,rol_id FROM usuarios WHERE rol_id IN (3,7) ORDER BY id');
        if(!$users)throw new RuntimeException('No hay cuentas de Asesor o Marketing/Recepción identificadas.');
        $uids=array_fill_keys(array_map('intval',array_column($users,'id')),true);$extensions=[];
        foreach($this->leer('telefonia_extensiones') as $e)if(isset($uids[(int)$e['usuario_id']])&&$e['proveedor']==='ZADARMA') {
            if(!preg_match('/^[0-9]{3,6}$/D',$e['extension']))continue;
            $extensions[$e['extension']]=$e;
        }
        // No atribuir una extensión que también tiene otro propietario activo.
        foreach($this->leer('telefonia_extensiones') as $e)if(!isset($uids[(int)$e['usuario_id']])&&!empty($e['activo'])&&isset($extensions[$e['extension']]))throw new RuntimeException('Hay una extensión compartida con otro rol; revisar antes de limpiar.');
        $groups=[];$refs=[];$pairs=[];$protected=[];$protectedSdk=[];
        $pair=static function(string $ref,string $ext)use(&$refs,&$pairs):void {
            if($ref==='')return;
            if(!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$ref))throw new RuntimeException('Referencia telefónica no reconocida; no se borró nada.');
            $refs[$ref]=true;if($ext!=='')$pairs[$ref][$ext]=true;
        };
        foreach(self::OWNED as $t=>$zone) {
            $selected=[];
            foreach($this->leer($t) as $r) {
                if(!isset($uids[(int)$r['usuario_id']]))continue;
                $date=$t==='telefonia_ventas_llamadas'?($r['fecha']??''):($r['created_at']??'');
                if($zone!==''&&$date!==''&&$date>$cut[$zone])continue;
                if($t!=='telefonia_notificaciones')$pair((string)($r['pbx_call_id']??''),(string)($r['extension']??$r['extension_destino']??''));
                $selected[]=$r;
            }
            if($selected)$groups[$t]=$this->grupo($t,$selected);
        }
        foreach(['telefonia_disponibilidad','telefonia_transferencia_operaciones'] as $t) {
            $selected=[];
            foreach($this->leer($t) as $r) {
                if($r['created_at']>$cut['utc'])continue;
                $source=isset($uids[(int)$r['origen_usuario']]);$dest=isset($uids[(int)$r['destino_usuario']]);
                if($source)$pair($r['referencia'],$r['origen_extension']);
                if($dest)$pair($r['referencia'],$r['destino_extension']);
                if($source&&$dest)$selected[]=$r;
                else {
                    $protected[$r['referencia']]=true;
                    if(!empty($r['sdk_consulta'])){$protectedSdk[$r['sdk_consulta']]=true;$protectedSdk[$r['sdk_consulta'].'-external']=true;}
                    if($dest&&!$source)throw new RuntimeException('Hay transferencias hacia Recepción/Ventas originadas en otro rol. Se requiere revisar ese vínculo antes de limpiar.');
                }
            }
            if($selected)$groups[$t]=$this->grupo($t,$selected);
        }
        foreach($this->leer('interacciones_vinculacion') as $r)if(($r['canal']??'')==='LLAMADA_IP'&&($r['id_externo']??'')!=='')$protected[$r['id_externo']]=true;
        foreach($this->leer('telefonia_analista_entrantes') as $r)if($r['pbx_call_id']!=='')$protected[$r['pbx_call_id']]=true;
        foreach($this->leer('telefonia_analista_webrtc_sesiones') as $r)$protectedSdk[$r['session_id']]=true;
        foreach($this->leer('telefonia_webrtc_grabaciones_internas') as $r)if(!isset($uids[(int)$r['usuario_id']])||isset($protectedSdk[$r['session_id']]))$protected[$r['pbx_call_id']]=true;
        $events=$this->leer('telefonia_zadarma_eventos');
        foreach($events as $r) {
            $ext=(string)$r['internal'];$e=$extensions[$ext]??null;
            if(!$e||$r['received_at']>$cut['mx'])continue;
            $since=max((string)$e['created_at'],(string)$e['updated_at']);
            if($r['received_at']>=$since&&(($r['call_start']??'')===''||$r['call_start']>=$since))$pair($r['pbx_call_id'],$ext);
        }
        // Preservar todas las evidencias de un tramo ajeno o de propiedad incierta.
        foreach($events as $r)if(isset($refs[$r['pbx_call_id']])&&$r['internal']!==''&&!isset($pairs[$r['pbx_call_id']][$r['internal']]))$protected[$r['pbx_call_id']]=true;
        foreach($groups['telefonia_webrtc_grabaciones_internas']['filas']??[] as $r)if(isset($protectedSdk[$r['session_id']]))$protected[$r['pbx_call_id']]=true;
        if(isset($groups['telefonia_webrtc_grabaciones_internas'])) {
            $groups['telefonia_webrtc_grabaciones_internas']['filas']=array_values(array_filter($groups['telefonia_webrtc_grabaciones_internas']['filas'],static fn($r)=>!isset($protected[$r['pbx_call_id']])&&!isset($protectedSdk[$r['session_id']])));
        }
        $deleteEvents=array_values(array_filter($events,static fn($r)=>isset($refs[$r['pbx_call_id']])&&!isset($protected[$r['pbx_call_id']])&&$r['received_at']<=$cut['mx']));
        if($deleteEvents)$groups['telefonia_zadarma_eventos']=$this->grupo('telefonia_zadarma_eventos',$deleteEvents);
        $contacts=[];
        foreach($this->leer('telefonia_contactos_personales') as $r)if(isset($uids[(int)$r['usuario_id']])&&!empty($r['proxima_accion_at']))$contacts[]=$r;
        if($contacts)$groups['telefonia_contactos_personales']=$this->grupo('telefonia_contactos_personales',$contacts,'agenda');
        // Jamás desactivar claves foráneas ni confiar en una cascada desconocida.
        foreach($groups as $t=>$g)if($g['accion']==='delete'&&$g['filas']) {
            if($this->rows('SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME=?',[$t]))throw new RuntimeException('Hay claves foráneas hacia '.$t.'. Revisar dependencias antes de borrar.');
        }
        $blocks=[];$shared=0;
        foreach(array_keys($refs) as $ref) {
            if(isset($protected[$ref])){++$shared;foreach(array_keys($pairs[$ref]??[]) as $ext)$blocks[]=['pbx_call_id'=>$ref,'extension'=>(string)$ext];}
            else $blocks[]=['pbx_call_id'=>$ref,'extension'=>''];
        }
        usort($blocks,static fn($a,$b)=>strcmp($a['pbx_call_id'].'|'.$a['extension'],$b['pbx_call_id'].'|'.$b['extension']));
        ksort($groups);
        return ['version'=>1,'base'=>$clock['db'],'corte'=>$cut,'usuarios'=>$users,'grupos'=>array_values($groups),'exclusiones'=>$blocks,'compartidas_protegidas'=>$shared];
    }
    public static function resumen(array $plan): array {
        $counts=[];foreach($plan['grupos'] as $g)$counts[$g['tabla']]=['accion'=>$g['accion']==='agenda'?'quitar_programacion_sin_borrar_contacto':'eliminar','filas'=>count($g['filas'])];
        return ['corte_utc'=>$plan['corte']['utc'],'cuentas_recepcion_asesores'=>count($plan['usuarios']),'registros'=>$counts,'llamadas_compartidas_conservadas_para_otros_roles'=>$plan['compartidas_protegidas'],'exclusiones'=>count($plan['exclusiones']),'huella'=>self::digest($plan)];
    }
    private function esquemaLimpieza(): void {
        $this->db->query("CREATE TABLE IF NOT EXISTS telefonia_crm_exclusiones(pbx_call_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,extension VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,limpieza_id CHAR(32) CHARACTER SET ascii NOT NULL,PRIMARY KEY(pbx_call_id,extension)) ENGINE=InnoDB");
        $this->db->query("CREATE TABLE IF NOT EXISTS telefonia_crm_limpiezas(id CHAR(32) CHARACTER SET ascii PRIMARY KEY,backup_sha CHAR(64) CHARACTER SET ascii NOT NULL,estado VARCHAR(12) NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB");
    }
    private function wherePk(array $row,array $pk): array {
        return [implode(' AND ',array_map(static fn($c)=>"`$c` <=> ?",$pk)),array_map(static fn($c)=>$row[$c],$pk)];
    }
    public function aplicar(array $plan,string $id,string $backupSha): void {
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
        require_once $root.'/config/db_connection.php';mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=(new Database())->connect(false);$tool=new LimpiezaLlamadasPrueba($db);
        if(isset($a['check'])) {
            $plan=$tool->plan();$id=bin2hex(random_bytes(16));$bytes=gzencode(json_encode(['id'=>$id,'plan'=>$plan],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),9);
            $file=$private.'/plan-'.$id.'.json.gz';$f=fopen($file,'xb');if(!$f)throw new RuntimeException('No se pudo guardar el respaldo previo.');
            try{if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||!fsync($f))throw new RuntimeException('Respaldo incompleto.');}finally{fclose($f);}chmod($file,0600);
            echo json_encode(LimpiezaLlamadasPrueba::resumen($plan)+['plan_privado'=>$file,'estado'=>'CHECK OK: base de datos sin cambios; respaldo previo guardado.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
        }else {
            $file=realpath((string)($a['apply']??$a['restore']));
            if(!$file||is_link((string)($a['apply']??$a['restore']))||dirname($file)!==$private||!preg_match('/^plan-[a-f0-9]{32}\.json\.gz$/D',basename($file)))throw new RuntimeException('Plan privado no válido.');
            $bytes=file_get_contents($file);$saved=json_decode(gzdecode($bytes),true,512,JSON_THROW_ON_ERROR);$sha=hash('sha256',$bytes);
            if(isset($a['apply'])) {
                foreach(LimpiezaLlamadasPrueba::PROTECCION as $path=>$hashes) {
                    $source=is_file($root.'/'.$path)?str_replace("\r\n","\n",file_get_contents($root.'/'.$path)):'';
                    if(!in_array(hash('sha256',$source),$hashes,true))throw new RuntimeException('Protección no instalada o versión diferente: '.$path);
                }
                $tool->aplicar($saved['plan'],$saved['id'],$sha);echo 'APLICADO: registros de prueba retirados del CRM. Zadarma no se modificó. Respaldo: '.$file.PHP_EOL;
            }else {$tool->restaurar($saved['plan'],$saved['id'],$sha);echo 'RESTAURADO: filas originales recuperadas; Zadarma no se modificó.'.PHP_EOL;}
        }
    }catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
}elseif(PHP_SAPI!=='cli'){http_response_code(404);exit;}
