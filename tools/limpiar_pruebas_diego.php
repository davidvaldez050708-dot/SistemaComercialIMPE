<?php
declare(strict_types=1);

/** CLI privado. Plan y respaldo fuera de public_html; jamás usa la API del proveedor. */
final class LimpiezaPruebasDiego
{
    private mysqli $db;
    public const PROTECCION=['app/services/TelefoniaLimpiezaGuard.php'=>['17d03adc29084f54b7cdb7b8c09594b9f4da257dc469b674f221b4cdeae227bf']];
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
    private const UID=5;
    private const EXT='101';
    private function columnas(string $table): array {
        return array_column($this->rows('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',[$table]),'COLUMN_NAME');
    }
    private function comprobarTablasControl(): void {
        foreach(['telefonia_crm_exclusiones','telefonia_crm_limpiezas'] as $table){
            if(!$this->tabla($table))throw new RuntimeException('Falta la protección ya existente del CRM: '.$table.'. No se modificó nada.');
            $this->estructura($table);
        }
    }
    private const TEST_IDS=[1351,1353,1354,1356,1357,1358,1359,1360,1361];
    private function cambiar(array $rows,array $fields): array {
        return array_map(static fn($row)=>array_replace($row,$fields),$rows);
    }
    public function plan(?array $cut=null): array {
        $this->comprobarTablasControl();
        $clock=$this->rows('SELECT UTC_TIMESTAMP() utc,NOW() reloj')[0];
        $cut=$cut??['utc'=>$clock['utc'],'db'=>$clock['reloj'],'mx'=>(new DateTimeImmutable($clock['utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s')];
        $users=$this->rows('SELECT id,usuario,rol_id,estado FROM usuarios WHERE id=?',[self::UID]);
        if(count($users)!==1||$users[0]['usuario']!=='diego.bahena'||(int)$users[0]['rol_id']!==4)throw new RuntimeException('La identidad/rol de Diego cambió.');
        $assigned=$this->rows('SELECT * FROM telefonia_extensiones WHERE (usuario_id=? AND activo=1) OR (extension=? AND activo=1) ORDER BY id',[self::UID,self::EXT]);
        if(count($assigned)!==1||(int)$assigned[0]['usuario_id']!==self::UID||$assigned[0]['extension']!==self::EXT)throw new RuntimeException('La extensión 101 ya no está asignada exclusivamente a Diego.');
        $sinceRaw=max((string)($assigned[0]['created_at']??''),(string)($assigned[0]['updated_at']??''));
        if($sinceRaw==='')throw new RuntimeException('Falta la fecha verificable de asignación de 101.');
        $since=(new DateTimeImmutable($sinceRaw,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
        $ids=implode(',',self::TEST_IDS);
        $targets=$this->rows("SELECT * FROM seguimientos_vinculacion WHERE id IN ($ids) ORDER BY id");
        if(count($targets)!==count(self::TEST_IDS))throw new RuntimeException('La lista de nueve seguimientos cambió; se requiere un nuevo inventario.');
        foreach($targets as $s){
            if((int)$s['analista_id']!==self::UID||(int)$s['estado_id']!==7||stripos((string)$s['clave_origen'],'XLSX:')===0||stripos((string)$s['observaciones'],'migrado desde excel')!==false)throw new RuntimeException('Un seguimiento ya no coincide con las pruebas autorizadas de Chiapas.');
        }
        $tables=array_column($this->rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"),'TABLE_NAME');
        $groups=[];$refs=[];$pendingUpdates=[];
        $add=function(string $table,array $rows)use(&$groups,&$refs):void {
            if(!$rows)return;
            $existing=$groups[$table]['filas']??[];$meta=$this->estructura($table);$unique=[];
            foreach(array_merge($existing,$rows) as $r){$unique[self::key($r,$meta['pk'])]=$r;foreach(['pbx_call_id','id_externo','referencia','referencia_llamada'] as $k)if(!empty($r[$k])&&strlen((string)$r[$k])<=100)$refs[(string)$r[$k]]=true;}
            $groups[$table]=$this->grupo($table,array_values($unique));
        };
        $update=function(string $table,array $rows,array $after)use(&$groups,&$pendingUpdates):void {
            if(!$rows)return;
            $g=$this->grupo($table,$rows,'actualizar');$map=[];
            foreach($after as $r)$map[self::key($r,$g['estructura']['pk'])]=$r;
            $g['despues']=$map;if(isset($groups[$table]))$pendingUpdates[$table]=$g;else $groups[$table]=$g;
        };
        // Expedientes revisados: se excluyen por diseño todos los otros estados e importados.
        $add('seguimientos_vinculacion',$targets);
        $allowed=['interacciones_vinculacion','oficios_vinculacion','correos_oficio_vinculacion','correo_respuestas_entrantes','recordatorios_vinculacion','reuniones_vinculacion','reuniones_vinculacion_reprogramaciones','observaciones_seguimiento','marketing_convenio_atencion','marketing_convenio_convocatorias','aliados_asignaciones','aliados_contactos','aliados_convocatorias_envios','aliados_convocatorias_seguimientos','aliados_convocatorias_seguimiento_eventos'];
        foreach($tables as $t){
            $cols=$this->columnas($t);
            if(in_array('seguimiento_id',$cols,true)&&$t!=='seguimientos_vinculacion') {
                $related=$this->rows("SELECT * FROM `$t` WHERE seguimiento_id IN ($ids)");
                if(in_array($t,$allowed,true)||str_starts_with($t,'seguimientos_vinculacion_')||str_starts_with($t,'telefonia_'))$add($t,$related);
                elseif($related&&!in_array($t,['formulario_aliados','formulario_aliado_registro'],true))throw new RuntimeException('Dependencia lógica no revisada: '.$t.'. No se borró nada.');
            }
        }
        // Las ligas, formularios, prospectos y conversaciones pertenecen a sus módulos.
        // Se conserva su contenido y sólo se desvincula el expediente de prueba si la columna admite NULL.
        foreach(['formulario_aliados'=>'seguimiento_id','formulario_aliado_registro'=>'seguimiento_id','whatsapp_conversaciones'=>'aliado_seguimiento_id'] as $t=>$field){
            if(!$this->tabla($t))continue;
            $rows=$this->rows("SELECT * FROM `$t` WHERE `$field` IN ($ids)");
            if($rows){$col=$this->rows('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$t,$field]);if(($col[0]['IS_NULLABLE']??'')!=='YES')throw new RuntimeException('No se puede preservar sin cambios el módulo relacionado: '.$t);$update($t,$rows,$this->cambiar($rows,[$field=>null]));}
        }
        $add('interacciones_vinculacion',$this->rows("SELECT * FROM interacciones_vinculacion WHERE usuario_id=? AND canal IN ('LLAMADA','LLAMADA_IP')",[self::UID]));
        $owned=['telefonia_analista_entrantes','telefonia_analista_webrtc_sesiones','telefonia_borradores','telefonia_devolucion_intentos','telefonia_notificaciones','telefonia_verificacion_evidencias','telefonia_webrtc_grabaciones_internas','notificaciones_convocatorias'];
        foreach($owned as $t)if($this->tabla($t))$add($t,$this->rows("SELECT * FROM `$t` WHERE usuario_id=?",[self::UID]));
        foreach(['telefonia_disponibilidad','telefonia_transferencia_operaciones'] as $t)if($this->tabla($t))$add($t,$this->rows("SELECT * FROM `$t` WHERE (destino_usuario=? AND destino_extension=?) OR (origen_usuario=? AND origen_extension=?)",[self::UID,self::EXT,self::UID,self::EXT]));
        if($this->tabla('telefonia_transferencia_contextos_v2'))$add('telefonia_transferencia_contextos_v2',$this->rows('SELECT * FROM telefonia_transferencia_contextos_v2 WHERE (destino_usuario_id=? AND destino_extension=?) OR (origen_usuario_id=? AND origen_extension=?)',[self::UID,self::EXT,self::UID,self::EXT]));
        // Elimina sólo el tramo local 101. Conserva los tramos de Recepción y de otros usuarios,
        // así como los eventos de grabación compartidos y todos los archivos físicos.
        $events=$this->rows('SELECT * FROM telefonia_zadarma_eventos WHERE internal=? AND received_at>=? ORDER BY id',[self::EXT,$since]);
        foreach(array_keys($refs) as $ref)foreach($this->rows('SELECT * FROM telefonia_zadarma_eventos WHERE internal=? AND pbx_call_id=BINARY ?',[self::EXT,$ref]) as $row)$events[]=$row;
        $uniqueEvents=[];foreach($events as $row)$uniqueEvents[$row['id']]=$row;$events=array_values($uniqueEvents);
        $latest=[];foreach($events as $r){$ref=$r['pbx_call_id'];if(in_array($r['evento'],['NOTIFY_OUT_START','NOTIFY_INTERNAL','NOTIFY_ANSWER'],true))$latest[$ref]['start']=max($latest[$ref]['start']??'',$r['received_at']);if(in_array($r['evento'],['NOTIFY_OUT_END','NOTIFY_END'],true))$latest[$ref]['end']=max($latest[$ref]['end']??'',$r['received_at']);}
        $recent=(new DateTimeImmutable($clock['utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->modify('-15 minutes')->format('Y-m-d H:i:s');
        foreach($latest as $r)if(($r['start']??'')>=$recent&&($r['end']??'')<($r['start']??''))throw new RuntimeException('Llamada reciente de 101 sin cierre confirmado. Espera antes de limpiar.');
        foreach($groups['telefonia_analista_webrtc_sesiones']['filas']??[] as $r)if(empty($r['fecha_fin'])&&$r['created_at']>=date('Y-m-d H:i:s',strtotime($clock['reloj'])-900))throw new RuntimeException('Hay una sesión SDK reciente sin finalizar.');
        $add('telefonia_zadarma_eventos',$events);
        // Avisos de datos: se marcan leídos sin borrar las auditorías de otros expedientes.
        foreach(['observaciones_seguimiento'=>['destinatario_id',['leida'=>1,'leida_at'=>$cut['db']]],'seguimientos_vinculacion_cambios_datos'=>['destinatario_id',['leida_at'=>$cut['db'],'leida_por'=>self::UID]]] as $t=>$rule){
            if(!$this->tabla($t))continue;$rows=$this->rows("SELECT * FROM `$t` WHERE `{$rule[0]}`=? AND seguimiento_id NOT IN ($ids)",[self::UID]);
            $rows=array_values(array_filter($rows,static fn($r)=>empty($r['leida_at'])));
            if($rows)$update($t,$rows,$this->cambiar($rows,$rule[1]));
        }
        // Silenciar avisos ya vencidos sin suprimir las próximas acciones históricas.
        if($this->tabla('recordatorios_vinculacion')) {
            $rows=$this->rows("SELECT * FROM recordatorios_vinculacion WHERE usuario_id=? AND seguimiento_id NOT IN ($ids)",[self::UID]);$before=[];$after=[];
            foreach($rows as $row){$next=$row;$delta=strtotime($row['proxima_accion_at'])-strtotime($cut['db']);
                foreach(['aviso_3h_at'=>10800,'aviso_1h_at'=>3600,'aviso_10m_at'=>600,'aviso_vencida_at'=>0] as $field=>$seconds)if($delta<=$seconds&&array_key_exists($field,$next)&&$next[$field]===null)$next[$field]=$cut['db'];
                if($next!==$row){$before[]=$row;$after[]=$next;}
            }
            if($before)$update('recordatorios_vinculacion',$before,$after);
        }
        if($this->tabla('reuniones_vinculacion')&&in_array('notificado_analista_at',$this->columnas('reuniones_vinculacion'),true)){
            $rows=$this->rows("SELECT * FROM reuniones_vinculacion WHERE analista_id=? AND seguimiento_id NOT IN ($ids) AND notificado_analista_at IS NULL AND fecha_propuesta<=?",[self::UID,$cut['db']]);
            if($rows)$update('reuniones_vinculacion',$rows,$this->cambiar($rows,['notificado_analista_at'=>$cut['db']]));
        }
        // Cierre por relaciones reales: nunca se confía en un borrado CASCADE implícito.
        $fks=$this->rows('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,CONSTRAINT_NAME,ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION');
        $constraints=[];foreach($fks as $fk)$constraints[$fk['TABLE_NAME'].'|'.$fk['CONSTRAINT_NAME']][]=$fk;
        $allowed=array_merge($allowed,['seguimientos_vinculacion_correos','seguimientos_vinculacion_correo_adjuntos','seguimientos_vinculacion_convenio_versiones','seguimientos_vinculacion_cambios_datos','seguimientos_vinculacion_post_envio','telefonia_verificacion_evidencias']);
        do {$changed=false;
            foreach($constraints as $fk){$parent=$fk[0]['REFERENCED_TABLE_NAME'];$child=$fk[0]['TABLE_NAME'];if(!isset($groups[$parent])||$groups[$parent]['accion']!=='delete')continue;
                foreach($groups[$parent]['filas'] as $r){$where=[];$args=[];foreach($fk as $f){$where[]='`'.$f['COLUMN_NAME'].'`=?';$args[]=$r[$f['REFERENCED_COLUMN_NAME']];}
                    foreach($this->rows("SELECT * FROM `$child` WHERE ".implode(' AND ',$where),$args) as $c){
                        $meta=$this->estructura($child);$key=self::key($c,$meta['pk']);$known=false;
                        foreach($groups[$child]['filas']??[] as $existing)if(self::key($existing,$meta['pk'])===$key){$known=true;break;}
                        if($known){if($groups[$child]['accion']==='actualizar'){foreach($fk as $f)if(($groups[$child]['despues'][$key][$f['COLUMN_NAME']]??null)!==null)throw new RuntimeException('Desvinculación incompleta: '.$child);}continue;}
                        if(!in_array($child,$allowed,true))throw new RuntimeException('Dependencia externa no autorizada: '.$child.'. No se borró nada.');
                        if(isset($c['seguimiento_id'])&&!in_array((int)$c['seguimiento_id'],self::TEST_IDS,true))throw new RuntimeException('Dependencia en un expediente protegido: '.$child);
                        $add($child,[$c]);$changed=true;
                    }
                }
            }
        }while($changed);
        // Comprobar vínculos lógicos no declarados como FK (evidencias y borradores de telefonía).
        $interIds=array_map('intval',array_column($groups['interacciones_vinculacion']['filas']??[],'id'));
        if($interIds){$list=implode(',',$interIds);foreach(['telefonia_verificacion_evidencias'] as $t)if($this->tabla($t))$add($t,$this->rows("SELECT * FROM `$t` WHERE interaccion_id IN ($list)"));}
        foreach(array_merge(array_values($groups),array_values($pendingUpdates)) as $g){$t=$g['tabla'];$op=$g['accion']==='delete'?'DELETE':'UPDATE';if($this->rows('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? AND EVENT_MANIPULATION=?',[$t,$op]))throw new RuntimeException('Disparador no revisado en '.$t);}
        $ordered=[];$visiting=[];$visit=function($t)use(&$visit,&$ordered,&$visiting,$groups,$constraints):void{
            if(isset($ordered[$t]))return;if(isset($visiting[$t]))throw new RuntimeException('Dependencias circulares.');$visiting[$t]=true;
            foreach($constraints as $fk)if($fk[0]['REFERENCED_TABLE_NAME']===$t&&isset($groups[$fk[0]['TABLE_NAME']]))$visit($fk[0]['TABLE_NAME']);
            unset($visiting[$t]);$ordered[$t]=$groups[$t];
        };
        ksort($groups);foreach(array_keys($groups) as $t)$visit($t);
        $blocks=[];ksort($refs);foreach(array_keys($refs) as $ref)$blocks[]=['pbx_call_id'=>$ref,'extension'=>self::EXT];
        $protected=$this->rows("SELECT * FROM seguimientos_vinculacion WHERE id NOT IN ($ids) ORDER BY id");
        return ['version'=>5101,'desde_extension'=>$since,'corte'=>$cut,'usuarios'=>$users,'asignacion'=>$assigned,'grupos'=>array_merge(array_values($pendingUpdates),array_values($ordered)),'exclusiones'=>$blocks,'seguimientos_protegidos_sha'=>self::digest($protected),'seguimientos_de_diego_conservados'=>count(array_filter($protected,static fn($r)=>(int)$r['analista_id']===self::UID))];
    }
    public static function resumen(array $plan): array {
        $counts=[];foreach($plan['grupos'] as $g)$counts[$g['tabla']]=['accion'=>$g['accion'],'filas'=>count($g['filas'])];
        return ['objetivo'=>'diego.bahena · cuenta 5 · extensión 101','seguimientos_chiapas'=>self::TEST_IDS,'seguimientos_de_diego_conservados'=>$plan['seguimientos_de_diego_conservados'],'registros'=>$counts,'corte_utc'=>$plan['corte']['utc'],'archivos_fisicos_y_proveedor'=>'Sin cambios','huella'=>self::digest($plan)];
    }
    /** El respaldo conserva el inventario global, pero sólo las filas objetivo autorizan el borrado. */
    private static function digestObjetivo(array $plan): string {
        unset($plan['seguimientos_protegidos_sha'],$plan['seguimientos_de_diego_conservados']);
        return self::digest($plan);
    }
    public static function diferencias(array $old,array $now): array {
        $out=[];
        foreach(['usuarios','asignacion','desde_extension','exclusiones'] as $key)if(self::digest([$old[$key]??null])!==self::digest([$now[$key]??null]))$out['objetivo'][]=$key;
        $a=[];$b=[];foreach($old['grupos'] as $g)$a[$g['tabla'].'|'.$g['accion']]=$g;foreach($now['grupos'] as $g)$b[$g['tabla'].'|'.$g['accion']]=$g;
        foreach(array_unique(array_merge(array_keys($a),array_keys($b))) as $key)if(self::digest([$a[$key]??null])!==self::digest([$b[$key]??null])){
            $fields=[];$before=[];$after=[];$pk=$a[$key]['estructura']['pk']??$b[$key]['estructura']['pk'];
            foreach($a[$key]['filas']??[] as $row)$before[self::key($row,$pk)]=$row;
            foreach($b[$key]['filas']??[] as $row)$after[self::key($row,$pk)]=$row;
            foreach(array_intersect(array_keys($before),array_keys($after)) as $id)foreach(array_unique(array_merge(array_keys($before[$id]),array_keys($after[$id]))) as $field)if(self::digest([$before[$id][$field]??null])!==self::digest([$after[$id][$field]??null]))$fields[$field]=($fields[$field]??0)+1;
            $out['tablas'][$key]=['filas_antes'=>count($a[$key]['filas']??[]),'filas_ahora'=>count($b[$key]['filas']??[]),'campos_modificados'=>$fields];
        }
        $out['otros_seguimientos_cambiaron']=($old['seguimientos_protegidos_sha']??null)!==($now['seguimientos_protegidos_sha']??null);
        return $out;
    }
    private function esquemaLimpieza(): void { $this->comprobarTablasControl(); }

    private function wherePk(array $row,array $pk): array {
        return [implode(' AND ',array_map(static fn($c)=>"`$c` <=> ?",$pk)),array_map(static fn($c)=>$row[$c],$pk)];
    }
    public function aplicar(array $plan,string $id,string $backupSha,bool $transaccionAbierta=false): void {
        if(($plan['version']??0)!==5101)throw new RuntimeException('El plan no pertenece a esta limpieza de la extensión 101.');
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^[a-f0-9]{64}$/D',$backupSha))throw new RuntimeException('Identificación de respaldo inválida.');
        if($this->tabla('telefonia_crm_limpiezas')) {
            $old=$this->rows('SELECT * FROM telefonia_crm_limpiezas WHERE id=?',[$id]);
            if($old&&$old[0]['backup_sha']===$backupSha&&$old[0]['estado']==='APLICADA')return;
            if($old)throw new RuntimeException('Ese plan ya se utilizó con otro respaldo o fue restaurado.');
        }
        $actual=$this->plan($plan['corte']);
        if(self::digestObjetivo($actual)!==self::digestObjetivo($plan))throw new RuntimeException('Los datos cambiaron en las filas objetivo. No se borró nada. Detalle: '.json_encode(self::diferencias($plan,$actual),JSON_UNESCAPED_UNICODE));
        $this->esquemaLimpieza();
        $old=$this->rows('SELECT * FROM telefonia_crm_limpiezas WHERE id=?',[$id]);
        if($old)throw new RuntimeException('Ese plan ya fue aplicado o restaurado. Crear otro plan si es necesario.');
        if(!$transaccionAbierta)$this->db->begin_transaction();
        try {
            // Congelar la identidad/asignación antes de revalidar las filas del plan.
            $this->rows('SELECT id FROM usuarios WHERE id=? FOR UPDATE',[self::UID]);
            $this->rows('SELECT usuario_id FROM telefonia_extensiones WHERE usuario_id=? OR extension=? FOR UPDATE',[self::UID,self::EXT]);
            // Revalidar dentro de la transacción, antes de la primera eliminación.
            $actual=$this->plan($plan['corte']);
            if(self::digestObjetivo($actual)!==self::digestObjetivo($plan))throw new RuntimeException('Cambio concurrente en filas objetivo; limpieza cancelada. Detalle: '.json_encode(self::diferencias($plan,$actual),JSON_UNESCAPED_UNICODE));
            $protectedBefore=$actual['seguimientos_protegidos_sha'];
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
                if($g['accion']==='actualizar') {
                    $after=$g['despues'][self::key($row,$g['estructura']['pk'])];
                    $sql="UPDATE `{$g['tabla']}` SET ".implode(',',array_map(static fn($c)=>"`$c`=?",array_keys($after)))." WHERE $where";
                    $args=array_merge(array_values($after),$args);
                } else $sql="DELETE FROM `{$g['tabla']}` WHERE $where";
                $s=$this->db->prepare($sql);$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();if($s->affected_rows!==1)throw new RuntimeException('No se pudo completar una fila; rollback.');$s->close();
            }
            $protected=$this->rows('SELECT * FROM seguimientos_vinculacion WHERE id NOT IN ('.implode(',',self::TEST_IDS).') ORDER BY id');
            if(self::digest($protected)!==$protectedBefore)throw new RuntimeException('Un expediente protegido cambió; rollback.');
            foreach(['telefonia_analista_entrantes','telefonia_analista_webrtc_sesiones','telefonia_borradores','telefonia_notificaciones','telefonia_verificacion_evidencias'] as $table)if($this->tabla($table)&&$this->rows("SELECT 1 FROM `$table` WHERE usuario_id=? LIMIT 1 FOR UPDATE",[self::UID]))throw new RuntimeException('Apareció actividad nueva de Diego; rollback.');
            if($this->rows("SELECT 1 FROM interacciones_vinculacion WHERE usuario_id=? AND canal IN ('LLAMADA','LLAMADA_IP') LIMIT 1 FOR UPDATE",[self::UID])||$this->rows('SELECT 1 FROM telefonia_zadarma_eventos WHERE internal=? AND received_at>=? LIMIT 1 FOR UPDATE',[self::EXT,$plan['desde_extension']]))throw new RuntimeException('Apareció una llamada nueva; rollback.');
            $s=$this->db->prepare("INSERT INTO telefonia_crm_limpiezas VALUES(?,?,'APLICADA',UTC_TIMESTAMP())");$s->bind_param('ss',$id,$backupSha);$s->execute();$s->close();
            $this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    private static function guardarRespaldo(string $directory,array $plan): array {
        $id=bin2hex(random_bytes(16));$bytes=gzencode(json_encode(['id'=>$id,'plan'=>$plan],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),9);
        $file=$directory.'/plan-diego-'.$id.'.json.gz';$f=fopen($file,'xb');if(!$f)throw new RuntimeException('No se pudo guardar el respaldo bloqueado.');
        try{if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||!fsync($f))throw new RuntimeException('Respaldo incompleto.');}finally{fclose($f);}chmod($file,0600);
        return ['id'=>$id,'sha'=>hash('sha256',$bytes),'archivo'=>$file];
    }
    /** Normaliza únicamente metadatos que el sondeo vuelve a publicar de una transferencia ya cerrada.
     * Nunca se usan estos datos normalizados como respaldo ni para ejecutar/restaurar SQL. */
    private static function normalizarSondeo(array $old,array $now): array {
        $before=[];foreach($old['grupos'] as $g)if($g['tabla']==='telefonia_transferencia_operaciones'&&$g['accion']==='delete')foreach($g['filas'] as $r)$before[(string)$r['id']]=$r;
        foreach($now['grupos'] as &$g){if($g['tabla']!=='telefonia_transferencia_operaciones'||$g['accion']!=='delete')continue;
            foreach($g['filas'] as &$row){$r=$before[(string)$row['id']]??null;if(!$r)continue;
                // Sólo se admite la nueva fecha de revisión de la misma fila; cualquier otro cambio se rechaza.
                if(array_key_exists('updated_at',$r))$row['updated_at']=$r['updated_at'];
                // La versión actual del sondeo repite gmdate() en un rechazo ya DEVUELTO.
                if(($row['estado']??'')==='DEVUELTA'&&($r['estado']??'')==='DEVUELTA'&&!empty($row['destino_fin'])&&!empty($r['destino_fin']))$row['destino_fin']=$r['destino_fin'];
            }unset($row);
        }unset($g);return $now;
    }
    public function aplicarBloqueado(array $reviewed,string $directory): array {
        if(($reviewed['version']??0)!==5101)throw new RuntimeException('Plan ajeno.');
        $this->db->query('SET SESSION innodb_lock_wait_timeout=10');
        $this->db->begin_transaction();$backup=null;
        try {
            $this->rows('SELECT id FROM usuarios WHERE id=? FOR UPDATE',[self::UID]);
            $this->rows('SELECT usuario_id FROM telefonia_extensiones WHERE usuario_id=? OR extension=? FOR UPDATE',[self::UID,self::EXT]);
            // Los sondeos esperan mientras se respalda y retira esta lista exacta de filas.
            foreach($reviewed['grupos'] as $g)foreach($g['filas'] as $row){
                [$where,$args]=$this->wherePk($row,$g['estructura']['pk']);
                $this->rows("SELECT * FROM `{$g['tabla']}` WHERE $where FOR UPDATE",$args);
            }
            $fresh=$this->plan($reviewed['corte']);
            if(self::digestObjetivo(self::normalizarSondeo($reviewed,$fresh))!==self::digestObjetivo($reviewed))throw new RuntimeException('Cambió el objetivo de limpieza; no se borró nada. Detalle: '.json_encode(self::diferencias($reviewed,$fresh),JSON_UNESCAPED_UNICODE));
            // Este respaldo recoge los valores REALES obtenidos bajo bloqueo, incluidos todos los timestamps.
            $backup=self::guardarRespaldo($directory,$fresh);
            $this->aplicar($fresh,$backup['id'],$backup['sha'],true);
            return ['estado'=>'APLICADO: limpieza con respaldo transaccional bloqueado.','plan_privado'=>$backup['archivo'],'resumen'=>self::resumen($fresh)];
        }catch(Throwable $e){$this->db->rollback();throw new RuntimeException($e->getMessage().($backup?' Respaldo conservado: '.$backup['archivo']:''),0,$e);}
    }
    public function restaurar(array $plan,string $id,string $backupSha): void {
        if(($plan['version']??0)!==5101)throw new RuntimeException('Plan de otra limpieza.');
        $run=$this->rows('SELECT * FROM telefonia_crm_limpiezas WHERE id=? AND backup_sha=?',[$id,$backupSha]);
        if(($run[0]['estado']??'')!=='APLICADA')throw new RuntimeException('El respaldo no corresponde a una limpieza aplicada.');
        $this->db->begin_transaction();
        try {
            foreach(array_reverse($plan['grupos']) as $g) {
                if($this->estructura($g['tabla'])!==$g['estructura'])throw new RuntimeException('La estructura cambió; restauración detenida.');
                foreach($g['filas'] as $row) {
                    [$where,$args]=$this->wherePk($row,$g['estructura']['pk']);$now=$this->rows("SELECT * FROM `{$g['tabla']}` WHERE $where FOR UPDATE",$args);
                    if($g['accion']==='actualizar') {
                        $after=$g['despues'][self::key($row,$g['estructura']['pk'])];
                        if(count($now)!==1||self::digest($now[0])!==self::digest($after))throw new RuntimeException('Un registro actualizado cambió; no se sobrescribirá.');
                        $sql="UPDATE `{$g['tabla']}` SET ".implode(',',array_map(static fn($c)=>"`$c`=?",array_keys($row)))." WHERE $where";
                        $values=array_merge(array_values($row),$args);
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
        $a=getopt('',['site:','backup-root:','check','apply:','restore:','compare:','apply-locked:']);
        $root=realpath((string)($a['site']??''));$private=(string)($a['backup-root']??'');
        if(!$root||!is_file($root.'/index.php')||$private===''||!preg_match('~^(?:/|[a-zA-Z]:/)~',str_replace('\\','/',$private)))throw new RuntimeException('Indica --site y --backup-root con rutas absolutas.');
        $parent=realpath(dirname($private));
        if(!$parent)throw new RuntimeException('La carpeta padre del respaldo debe existir.');
        $private=is_dir($private)?realpath($private):$parent.DIRECTORY_SEPARATOR.basename($private);
        $sitePath=rtrim(str_replace('\\','/',$root),'/').'/';$privatePath=rtrim(str_replace('\\','/',$private),'/').'/';
        if(str_starts_with(strtolower($privatePath),strtolower($sitePath)))throw new RuntimeException('El respaldo debe quedar fuera de public_html.');
        if(!is_dir($private)&&!mkdir($private,0700))throw new RuntimeException('No se pudo crear la carpeta privada.');
        chmod($private,0700);
        if(count(array_intersect(['check','apply','restore','compare','apply-locked'],array_keys($a)))!==1)throw new RuntimeException('Usa únicamente --check, --compare PLAN, --apply PLAN, --apply-locked PLAN o --restore PLAN.');
        require_once $root.'/config/db_connection.php';mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=(new Database())->connect(false);$tool=new LimpiezaPruebasDiego($db);
        if(isset($a['check'])) {
            $db->query('START TRANSACTION READ ONLY');try{$plan=$tool->plan();}finally{$db->rollback();}$id=bin2hex(random_bytes(16));$bytes=gzencode(json_encode(['id'=>$id,'plan'=>$plan],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),9);
            $file=$private.'/plan-diego-'.$id.'.json.gz';$f=fopen($file,'xb');if(!$f)throw new RuntimeException('No se pudo guardar el respaldo previo.');
            try{if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||!fsync($f))throw new RuntimeException('Respaldo incompleto.');}finally{fclose($f);}chmod($file,0600);
            echo json_encode(LimpiezaPruebasDiego::resumen($plan)+['plan_privado'=>$file,'estado'=>'CHECK OK: base de datos sin cambios; respaldo previo guardado.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
        }else {
            $file=realpath((string)($a['apply']??$a['restore']??$a['compare']??$a['apply-locked']));
            if(!$file||is_link((string)($a['apply']??$a['restore']??$a['compare']??$a['apply-locked']))||dirname($file)!==$private||!preg_match('/^plan-diego-[a-f0-9]{32}\.json\.gz$/D',basename($file)))throw new RuntimeException('Plan privado no válido.');
            $bytes=file_get_contents($file);$saved=json_decode(gzdecode($bytes),true,512,JSON_THROW_ON_ERROR);$sha=hash('sha256',$bytes);
            if(isset($a['compare'])) {
                $db->query('START TRANSACTION READ ONLY');
                try{$actual=$tool->plan($saved['plan']['corte']);}finally{$db->rollback();}
                echo json_encode(['diferencias'=>LimpiezaPruebasDiego::diferencias($saved['plan'],$actual),'estado'=>'Sólo lectura; no se aplicó ninguna limpieza.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
            }elseif(isset($a['apply'])||isset($a['apply-locked'])) {
                foreach(LimpiezaPruebasDiego::PROTECCION as $path=>$hashes) {
                    $source=is_file($root.'/'.$path)?str_replace("\r\n","\n",file_get_contents($root.'/'.$path)):'';
                    if(!in_array(hash('sha256',$source),$hashes,true))throw new RuntimeException('Protección no instalada o versión diferente: '.$path);
                }
                if(isset($a['apply-locked'])){echo json_encode($tool->aplicarBloqueado($saved['plan'],$private),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;}
                else {$tool->aplicar($saved['plan'],$saved['id'],$sha);echo 'APLICADO: pruebas de Diego retiradas del CRM. Los demás seguimientos, archivos físicos, configuración y proveedor se conservan. Respaldo: '.$file.PHP_EOL;}
            }else {$tool->restaurar($saved['plan'],$saved['id'],$sha);echo 'RESTAURADO: filas originales recuperadas; Zadarma no se modificó.'.PHP_EOL;}
        }
    }catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
}elseif(PHP_SAPI!=='cli'){http_response_code(404);exit;}
