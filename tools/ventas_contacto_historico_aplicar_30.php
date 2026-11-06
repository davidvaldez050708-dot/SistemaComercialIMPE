<?php
declare(strict_types=1);

/**
 * Corrección excepcional de 30 resultados WhatsApp V3 confirmados por usuario.
 * SOLO CLI. No DDL, no borrados, no cambios en grabaciones ni eventos PBX.
 * El --check es lectura pura; --apply exige --confirmo-30 y respaldo privado.
 *
 * Mantiene la selección WHATSAPP y la fecha original: se actualiza solamente
 * el resultado principal a CONVERSACION_PERSONA y su catálogo de V3 a V4.
 */
final class VentasContactoHistorico30
{
    public const EXPECTED = 30;
    public const BEFORE = 'ENVIO_WHATSAPP';
    public const AFTER = 'CONVERSACION_PERSONA';

    private static function fail(string $message): void
    {
        throw new RuntimeException($message);
    }

    private static function secureFile(string $path): array
    {
        if (is_link($path) || !is_file($path) || filesize($path)>8*1024*1024)
            self::fail('Archivo no seguro o demasiado grande: '.basename($path));
        $text=file_get_contents($path);
        if ($text===false)self::fail('No se pudo leer archivo privado.');
        $json=json_decode($text,true);
        if(!is_array($json))self::fail('Archivo JSON inválido.');
        return $json;
    }

    public static function verifyPlan(string $planPath,string $auditPath,
                                      string $revisionPath): array
    {
        $plan=self::secureFile($planPath);
        $audit=self::secureFile($auditPath);
        if (is_link($revisionPath) || !is_file($revisionPath))
            self::fail('CSV revisado ausente.');
        if (($plan['tipo']??'')!=='PLAN_REVISION_HISTORICA_NO_APLICADO' ||
            ($audit['tipo']??'')!=='AUDITORIA_SOLO_LECTURA')
            self::fail('Tipo de auditoría o plan incorrecto.');
        if (empty($plan['confirmacion_global_sin_notas']) ||
            ($plan['confirmacion_declarada']??'')!==
                'El responsable confirma los SI/NO del CSV sin descripciones por llamada.')
            self::fail('Falta confirmación global explícita del lote.');
        if (!hash_equals(hash_file('sha256',$auditPath),
                         (string)($plan['auditoria_sha256']??'')) ||
            !hash_equals(hash_file('sha256',$revisionPath),
                         (string)($plan['csv_sha256']??'')))
            self::fail('Plan, auditoría y CSV no coinciden.');
        $rows=$audit['llamadas']??null;
        if(!is_array($rows) || count($rows)!==118 ||
           (int)($audit['resumen']['total']??0)!==118 ||
           (int)($plan['total']??0)!==118 ||
           (int)($plan['confirmadas_por_revision']??0)!==self::EXPECTED ||
           (int)($plan['descartadas_por_revision']??-1)!==0 ||
           (int)($plan['pendientes']??-1)!==88)
            self::fail('El lote debe contener exactamente 30 SI y 88 PENDIENTE.');
        $base=[];
        foreach($rows as $row){
            if(!is_array($row) || !isset($row['resultado_id']) ||
               !is_int($row['resultado_id']) || $row['resultado_id']<=0 ||
               isset($base[$row['resultado_id']]))
                self::fail('ID de auditoría duplicado/inválido.');
            $base[$row['resultado_id']]=$row;
        }
        $accepted=$plan['aprobadas']??null;
        if(!is_array($accepted) || count($accepted)!==self::EXPECTED)
            self::fail('Debe existir exactamente un lote de 30 decisiones SI.');
        $seen=[];
        foreach($accepted as $entry){
            $id=$entry['resultado_id']??null;
            if(!is_int($id) || !isset($base[$id]) || isset($seen[$id]))
                self::fail('Plan contiene ID ajeno o duplicado.');
            $seen[$id]=true;
            $original=$base[$id];
            if(($entry['revision_humana']??'')!=='SI' ||
               ($entry['categoria']??'')!=='PENDIENTE_ESCUCHA_Y_CONFIRMACION_HUMANA' ||
               ($original['categoria']??'')!=='PENDIENTE_ESCUCHA_Y_CONFIRMACION_HUMANA' ||
               empty($original['conexion_pbx']) ||
               empty($original['referencia_grabacion_local']) ||
               (int)($entry['usuario_id']??0)!==(int)($original['usuario_id']??0))
                self::fail('La aprobación no coincide con evidencias de la auditoría: ID '.$id);
            foreach(['extension','pbx_call_id','fecha_llamada'] as $key){
                if((string)($entry[$key]??'')!==(string)($original[$key]??''))
                    self::fail('Identidad distinta para ID '.$id.' en '.$key);
            }
            if(!hash_equals(hash_file('sha256',$auditPath),
                            (string)($entry['auditoria_sha256']??'')))
                self::fail('Huella de auditoría diferente en ID '.$id);
        }
        return ['accepted'=>$accepted,'audit_sha'=>hash_file('sha256',$auditPath),
                'plan_sha'=>hash_file('sha256',$planPath),'csv_sha'=>hash_file('sha256',$revisionPath)];
    }

    private static function sqlRow(mysqli $db,array $entry,bool $forUpdate): array
    {
        $query="SELECT r.*,d.resultado_principal,d.envio_informacion,
                   d.contacto_antes_corte,d.catalogo_version,
                   c.id llamada_id,c.fecha fecha_llamada,c.direccion,
                   (SELECT COUNT(*) FROM telefonia_ventas_resultado_selecciones s
                       WHERE s.resultado_id=r.id) seleccion_total,
                   (SELECT COUNT(*) FROM telefonia_ventas_resultado_selecciones s
                       WHERE s.resultado_id=r.id AND s.codigo='WHATSAPP') seleccion_whatsapp,
                   EXISTS(SELECT 1 FROM telefonia_zadarma_eventos e
                       WHERE e.pbx_call_id=BINARY r.pbx_call_id
                         AND e.internal=BINARY r.extension AND e.evento='NOTIFY_ANSWER') tiene_answer,
                   EXISTS(SELECT 1 FROM telefonia_zadarma_eventos e
                       WHERE e.pbx_call_id=BINARY r.pbx_call_id
                         AND e.internal=BINARY r.extension AND e.is_recorded=1
                         AND e.call_id_with_rec IS NOT NULL AND e.call_id_with_rec<>'') tiene_audio
                FROM telefonia_ventas_resultados r
                JOIN telefonia_ventas_resultado_detalles d ON d.resultado_id=r.id
                JOIN telefonia_ventas_llamadas c ON c.usuario_id=r.usuario_id
                  AND c.extension=BINARY r.extension
                  AND c.pbx_call_id=BINARY r.pbx_call_id
                WHERE r.id=? AND r.usuario_id=?
                  AND r.extension=BINARY ? AND r.pbx_call_id=BINARY ?";
        if($forUpdate)$query.=" FOR UPDATE";
        $s=$db->prepare($query);
        $id=$entry['resultado_id'];$uid=$entry['usuario_id'];
        $ext=$entry['extension'];$pbx=$entry['pbx_call_id'];
        $s->bind_param('iiss',$id,$uid,$ext,$pbx);
        $s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if(count($rows)!==1)self::fail('Llamada ausente/ambigua: resultado ID '.$id);
        $r=$rows[0];
        if ((string)$r['resultado']!==self::BEFORE ||
            (string)$r['resultado_principal']!==self::BEFORE ||
            (string)$r['catalogo_version']!=='3' ||
            (string)$r['envio_informacion']!=='WHATSAPP' ||
            (string)$r['contacto_antes_corte']!=='' ||
            (int)$r['seleccion_total']!==1 ||
            (int)$r['seleccion_whatsapp']!==1 ||
            (int)$r['tiene_answer']!==1 ||
            (int)$r['tiene_audio']!==1 ||
            (string)$r['fecha_llamada']!==(string)$entry['fecha_llamada'] ||
            (string)$r['direccion']!=='Saliente')
            self::fail('Estado vigente cambió o sin evidencia suficiente: resultado ID '.$id);
        return $r;
    }

    public static function inspect(mysqli $db,array $plan,bool $forUpdate): array
    {
        $snapshot=[];
        foreach($plan['accepted'] as $entry){
            $row=self::sqlRow($db,$entry,$forUpdate);
            $snapshot[]=['resultado_id'=>$entry['resultado_id'],
                         'usuario_id'=>$entry['usuario_id'],
                         'extension'=>$entry['extension'],
                         'pbx_call_id'=>$entry['pbx_call_id'],
                         'original'=>$row];
        }
        return $snapshot;
    }

    public static function privateBackup(string $site,string $folder,array $payload): string
    {
        $site=realpath($site);
        if($site===false||!is_file($site.'/index.php'))
            self::fail('Ruta del CRM incorrecta.');
        $folder=rtrim($folder,DIRECTORY_SEPARATOR);
        if($folder===''||is_link($folder))self::fail('Directorio privado inválido.');
        $real=realpath($folder);
        if($real===false)self::fail('El directorio de respaldo privado debe existir.');
        if($real===$site || strpos($real,$site.DIRECTORY_SEPARATOR)===0 ||
           (fileperms($real)&0077)!==0)
            self::fail('Respaldo requiere carpeta privada 0700, fuera de public_html.');
        $name='backup_contactos_30_'.substr($payload['plan_sha'],0,16).'_'.bin2hex(random_bytes(4)).'.json';
        $dest=$real.DIRECTORY_SEPARATOR.$name;
        $handle=fopen($dest,'x');
        if(!$handle)self::fail('No se puede crear respaldo.');
        chmod($dest,0600);
        try {
            $content=json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if(fwrite($handle,$content)!==strlen($content))
                self::fail('No se pudo escribir el respaldo completo.');
            fflush($handle);
        }finally{fclose($handle);}
        return $dest;
    }

    public static function apply(mysqli $db,array $snapshot): void
    {
        $stmt=$db->prepare("UPDATE telefonia_ventas_resultados
            SET resultado=?, updated_at=?
            WHERE id=? AND usuario_id=? AND extension=BINARY ?
              AND pbx_call_id=BINARY ? AND resultado=? AND updated_at=?");
        $detail=$db->prepare("UPDATE telefonia_ventas_resultado_detalles
            SET resultado_principal=?, catalogo_version='4'
            WHERE resultado_id=? AND resultado_principal=? AND catalogo_version='3'
              AND envio_informacion='WHATSAPP'");
        $new=self::AFTER;$old=self::BEFORE;
        foreach($snapshot as $one){
            $row=$one['original'];
            $id=(int)$one['resultado_id'];$uid=(int)$one['usuario_id'];
            $ext=(string)$one['extension'];$pbx=(string)$one['pbx_call_id'];
            $updated=(string)$row['updated_at'];
            $stmt->bind_param('ssiissss',$new,$updated,$id,$uid,$ext,$pbx,$old,$updated);
            $stmt->execute();
            if($stmt->affected_rows!==1)
                self::fail('Resultado concurrente o rechazado: ID '.$id);
            $detail->bind_param('sis',$new,$id,$old);
            $detail->execute();
            if($detail->affected_rows!==1)
                self::fail('Detalle concurrente o rechazado: ID '.$id);
        }
        $stmt->close();$detail->close();
    }
}

if(PHP_SAPI==='cli' && isset($_SERVER['SCRIPT_FILENAME']) &&
   realpath((string)$_SERVER['SCRIPT_FILENAME'])===__FILE__){
    try{
        $flags=getopt('',['site:','plan:','audit:','revision:','backup-root:',
                          'check','apply','confirmo-30']);
        foreach(['site','plan','audit','revision','backup-root'] as $required)
            if(empty($flags[$required]))throw new InvalidArgumentException('Falta --'.$required);
        $doCheck=isset($flags['check']);$doApply=isset($flags['apply']);
        if($doCheck===$doApply)throw new InvalidArgumentException('Elige --check o --apply.');
        if($doApply&&!isset($flags['confirmo-30']))
            throw new InvalidArgumentException('Para aplicar se requiere --confirmo-30.');
        $site=realpath($flags['site']);
        if($site===false||!is_file($site.'/index.php'))
            throw new RuntimeException('Ruta de CRM no reconocida.');
        foreach(['plan','audit','revision'] as $key){
            $item=realpath($flags[$key]);
            if($item===false||strpos($item,$site.DIRECTORY_SEPARATOR)===0)
                throw new RuntimeException('El documento privado --'.$key.' no debe estar en public_html.');
        }
        $plan=VentasContactoHistorico30::verifyPlan($flags['plan'],$flags['audit'],$flags['revision']);
        require_once $site.'/config/db_connection.php';
        mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
        $db=(new Database())->connect(false);
        $db->query($doCheck?'START TRANSACTION READ ONLY':'START TRANSACTION');
        $backup=null;
        try{
            $snapshot=VentasContactoHistorico30::inspect($db,$plan,$doApply);
            echo "CONCILIADOS: ".count($snapshot)." resultados WhatsApp V3 con audio propio y ANSWER PBX.\n";
            if($doCheck){
                $db->commit();
                echo "CHECK OK: 30 autorizables, 88 pendientes intactos. Sin escrituras SQL.\n";
            }else{
                $backup=VentasContactoHistorico30::privateBackup($site,$flags['backup-root'],[
                    'tipo'=>'BACKUP_PREVIO_CONTACTOS_WHATSAPP_30',
                    'fecha'=>date(DATE_ATOM),
                    'declaracion'=>'Responsable confirmó las 30 decisiones SI sin observación individual.',
                    'plan_sha'=>$plan['plan_sha'],'audit_sha'=>$plan['audit_sha'],
                    'csv_sha'=>$plan['csv_sha'],'antes'=>$snapshot,
                ]);
                echo "RESPALDO PRIVADO: ".$backup."\n";
                VentasContactoHistorico30::apply($db,$snapshot);
                $db->commit();
                echo "APLICADO: 30 conversaciones confirmadas; selección WHATSAPP conservada; 88 pendientes intactos.\n";
                echo "SIN SQL DDL, sin borrados, sin tocar audios/webhooks ni datos de otros registros.\n";
            }
        }catch(Throwable $e){$db->rollback();throw $e;}
    }catch(Throwable $e){
        fwrite(STDERR,"ERROR: ".$e->getMessage()."\n");
        exit(1);
    }
}
