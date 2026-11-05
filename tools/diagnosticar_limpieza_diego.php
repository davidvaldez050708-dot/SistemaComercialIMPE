<?php
declare(strict_types=1);
/** Inventario privado y exclusivamente de lectura. No carga servicios que creen tablas. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args=getopt('', ['site:']);
$site=realpath((string)($args['site']??''));
if (!$site || !is_file($site.'/config/db_connection.php')) { fwrite(STDERR,"Sitio inválido.\n");exit(1); }
require $site.'/config/db_connection.php';
$db=(new Database())->connect(false);
$read=static function(string $sql,array $params=[])use($db):array {
    $s=$db->prepare($sql);
    if($params)$s->bind_param(str_repeat('s',count($params)),...$params);
    $s->execute();return $s->get_result()->fetch_all(MYSQLI_ASSOC);
};
try {
    $db->query('START TRANSACTION READ ONLY');
    $users=$read('SELECT id,usuario,rol_id,estado FROM usuarios WHERE BINARY usuario=?',['diego.bahena']);
    if(count($users)!==1)throw new RuntimeException('La cuenta exacta diego.bahena no es única. No se modificó nada.');
    $uid=(int)$users[0]['id'];
    $ext=$read('SELECT extension,activo FROM telefonia_extensiones WHERE usuario_id=? ORDER BY extension',[$uid]);
    $columns=$read('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION');
    $schemas=[];
    foreach($columns as $c)$schemas[$c['TABLE_NAME']][]=$c;
    $selected=[];
    foreach($schemas as $table=>$cols){
        $names=array_column($cols,'COLUMN_NAME');
        if(preg_match('/^(telefonia_|seguimientos_vinculacion|interacciones_vinculacion|notificaciones|recordatorios_vinculacion|reuniones_vinculacion|oficios_vinculacion|correos_oficio_vinculacion|aliados_|formulario_aliados)/',$table)||in_array('seguimiento_id',$names,true)||in_array('interaccion_id',$names,true)) {
            if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Nombre de tabla no reconocido.');
            $item=['columnas'=>$names,'pk'=>array_column(array_filter($cols,static fn($c)=>$c['COLUMN_KEY']==='PRI'),'COLUMN_NAME')];
            foreach(['usuario_id','analista_id','destino_usuario','origen_usuario'] as $field)if(in_array($field,$names,true)) {
                $item['filas_de_diego'][$field]=(int)$read("SELECT COUNT(*) n FROM `$table` WHERE `$field`=?",[$uid])[0]['n'];
            }
            $selected[$table]=$item;
        }
    }
    $states=$read('SELECT id,nombre FROM estados WHERE nombre=?',['Chiapas']);
    if(count($states)!==1)throw new RuntimeException('Chiapas no se resolvió de forma única.');
    $follows=$read("SELECT s.id,s.estado_id,s.estado_seguimiento,s.ultima_interaccion_at,
        CASE WHEN UPPER(COALESCE(s.clave_origen,'')) LIKE 'XLSX:%' THEN 1 ELSE 0 END origen_excel,
        CASE WHEN LOWER(COALESCE(s.observaciones,'')) LIKE '%migrado desde excel%' THEN 1 ELSE 0 END nota_migracion,
        (SELECT i.canal FROM interacciones_vinculacion i WHERE i.seguimiento_id=s.id ORDER BY i.fecha_inicio DESC,i.id DESC LIMIT 1) ultimo_canal,
        (SELECT COUNT(*) FROM interacciones_vinculacion i WHERE i.seguimiento_id=s.id) interacciones
        FROM seguimientos_vinculacion s WHERE s.analista_id=? AND s.estado_id=? ORDER BY s.id",[$uid,$states[0]['id']]);
    $outside=$read('SELECT estado_id,COUNT(*) cantidad FROM seguimientos_vinculacion WHERE analista_id=? AND estado_id<>? GROUP BY estado_id',[$uid,$states[0]['id']]);
    $fks=$read('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION');
    $fks=array_values(array_filter($fks,static fn($f)=>isset($selected[$f['TABLE_NAME']])||isset($selected[$f['REFERENCED_TABLE_NAME']])));
    $versions=[];
    foreach(['app/controllers/SeguimientoVinculacionController.php','app/services/SeguimientoRutaOperativaService.php','app/services/SeguimientoHistorialImportadoService.php','app/services/TelefoniaActividadService.php','app/services/TelefoniaLimpiezaGuard.php','app/services/TelefoniaEntrantesAnalistaService.php','app/models/DesempenoModel.php'] as $p)$versions[$p]=is_file($site.'/'.$p)?hash_file('sha256',$site.'/'.$p):null;
    echo json_encode(['cuenta'=>$users[0],'extensiones'=>$ext,'chiapas'=>$states[0],
        'seguimientos_chiapas'=>$follows,'seguimientos_otros_estados_protegidos'=>$outside,
        'tablas'=>$selected,'relaciones'=>$fks,'versiones'=>$versions,
        'estado'=>'Sólo lectura. Ninguna eliminación, actualización, sincronización ni llamada al proveedor.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
} catch(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);}
finally {$db->rollback();}
