<?php
declare(strict_types=1);
require_once __DIR__.'/ventas_contacto_historico_aplicar_30.php';

/**
 * Restauración exclusiva del lote de 30. Se debe verificar que nadie haya
 * editado los 30 registros desde la publicación. No altera WhatsApp, notas,
 * audio, eventos PBX ni ningún otro registro.
 */
final class VentasContactoHistoricoRestaurar30
{
    public static function leer(string $backupFile,string $privateRoot,
                                 string $site): array
    {
        $real=realpath($backupFile);$dir=realpath($privateRoot);
        $web=realpath($site);
        if($real===false||$dir===false||$web===false||
           is_link($backupFile)||!is_file($real)||
           strpos($real,$dir.DIRECTORY_SEPARATOR)!==0||
           $dir===$web||strpos($dir,$web.DIRECTORY_SEPARATOR)===0||
           (fileperms($dir)&0077)!==0||filesize($real)>8*1024*1024)
            throw new RuntimeException('Respaldo privado o ruta inválida.');
        $raw=file_get_contents($real);
        $backup=json_decode((string)$raw,true);
        if(!is_array($backup)||
           ($backup['tipo']??'')!=='BACKUP_PREVIO_CONTACTOS_WHATSAPP_30'||
           count($backup['antes']??[])!==30 ||
           !preg_match('/^[0-9a-f]{64}$/',(string)($backup['plan_sha']??'')))
            throw new RuntimeException('Formato de respaldo incorrecto.');
        $seen=[];
        foreach($backup['antes'] as $record){
            $id=$record['resultado_id']??null;$row=$record['original']??null;
            if(!is_int($id)||$id<=0||isset($seen[$id])||!is_array($row)||
               (int)($row['id']??0)!==$id||
               (string)($row['resultado']??'')!=='ENVIO_WHATSAPP'||
               (string)($row['resultado_principal']??'')!=='ENVIO_WHATSAPP'||
               (string)($row['catalogo_version']??'')!=='3'||
               (string)($row['envio_informacion']??'')!=='WHATSAPP'||
               (int)($row['seleccion_total']??0)!==1||
               (int)($row['seleccion_whatsapp']??0)!==1)
                throw new RuntimeException('Respaldo contradictorio en resultado '.(string)$id);
            $seen[$id]=true;
            foreach(['usuario_id','extension','pbx_call_id'] as $key)
                if((string)($row[$key]??'')!==(string)($record[$key]??''))
                    throw new RuntimeException('Identidad de respaldo distinta: '.$key);
        }
        return $backup;
    }

    private static function actual(mysqli $db,array $entry,bool $lock): array
    {
        $sql="SELECT r.*,d.resultado_principal,d.catalogo_version,
                   d.envio_informacion,d.contacto_antes_corte
              FROM telefonia_ventas_resultados r
              JOIN telefonia_ventas_resultado_detalles d ON d.resultado_id=r.id
              WHERE r.id=? AND r.usuario_id=?
                AND r.extension=BINARY ? AND r.pbx_call_id=BINARY ?";
        if($lock)$sql.=" FOR UPDATE";
        $s=$db->prepare($sql);
        $id=(int)$entry['resultado_id'];$uid=(int)$entry['usuario_id'];
        $ext=(string)$entry['extension'];$pbx=(string)$entry['pbx_call_id'];
        $s->bind_param('iiss',$id,$uid,$ext,$pbx);
        $s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if(count($rows)!==1)
            throw new RuntimeException('Resultado ausente o ambiguo en restore: '.$id);
        return $rows[0];
    }

    public static function comprobar(mysqli $db,array $backup,bool $lock): void
    {
        foreach($backup['antes'] as $entry){
            $old=$entry['original'];
            $actual=self::actual($db,$entry,$lock);
            if((string)$actual['resultado']!=='CONVERSACION_PERSONA' ||
               (string)$actual['resultado_principal']!=='CONVERSACION_PERSONA' ||
               (string)$actual['catalogo_version']!=='4')
                throw new RuntimeException('El registro ya no conserva el estado publicado: ID '.$entry['resultado_id']);
            foreach(['id','usuario_id','extension','pbx_call_id',
                     'observacion','created_at','updated_at',
                     'envio_informacion','contacto_antes_corte'] as $key)
                if((string)($actual[$key]??'')!==(string)($old[$key]??''))
                    throw new RuntimeException('Cambios posteriores: no restaurar ID '.$entry['resultado_id'].' campo '.$key);
        }
    }

    public static function restaurar(mysqli $db,array $backup): void
    {
        $first=$db->prepare("UPDATE telefonia_ventas_resultados
            SET resultado='ENVIO_WHATSAPP',updated_at=?
            WHERE id=? AND usuario_id=? AND extension=BINARY ?
              AND pbx_call_id=BINARY ? AND resultado='CONVERSACION_PERSONA'
              AND updated_at=?");
        $second=$db->prepare("UPDATE telefonia_ventas_resultado_detalles
            SET resultado_principal='ENVIO_WHATSAPP',catalogo_version='3'
            WHERE resultado_id=? AND resultado_principal='CONVERSACION_PERSONA'
              AND catalogo_version='4' AND envio_informacion='WHATSAPP'");
        foreach($backup['antes'] as $entry){
            $id=(int)$entry['resultado_id'];$uid=(int)$entry['usuario_id'];
            $ext=(string)$entry['extension'];$pbx=(string)$entry['pbx_call_id'];
            $updated=(string)$entry['original']['updated_at'];
            $first->bind_param('siisss',$updated,$id,$uid,$ext,$pbx,$updated);
            $first->execute();
            if($first->affected_rows!==1)
                throw new RuntimeException('Cambio concurrente en restauración ID '.$id);
            $second->bind_param('i',$id);$second->execute();
            if($second->affected_rows!==1)
                throw new RuntimeException('Detalle distinto en restauración ID '.$id);
        }
        $first->close();$second->close();
    }
}

if(PHP_SAPI==='cli' && isset($_SERVER['SCRIPT_FILENAME']) &&
   realpath((string)$_SERVER['SCRIPT_FILENAME'])===__FILE__){
 try {
    $args=getopt('',['site:','backup-root:','backup:','check',
                     'restore','confirmo-restaurar-30']);
    foreach(['site','backup-root','backup'] as $field)
        if(empty($args[$field]))throw new InvalidArgumentException('Falta --'.$field);
    $check=isset($args['check']);$restore=isset($args['restore']);
    if($check===$restore)
        throw new InvalidArgumentException('Usa exactamente --check o --restore.');
    if($restore&&!isset($args['confirmo-restaurar-30']))
        throw new InvalidArgumentException('Falta confirmación de restauración.');
    $site=realpath($args['site']);
    if(!$site||!is_file($site.'/index.php'))
        throw new RuntimeException('Sitio no reconocido.');
    $backup=VentasContactoHistoricoRestaurar30::leer(
        $args['backup'],$args['backup-root'],$site);
    require_once $site.'/config/db_connection.php';
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db=(new Database())->connect(false);
    $db->query($check?'START TRANSACTION READ ONLY':'START TRANSACTION');
    try {
       VentasContactoHistoricoRestaurar30::comprobar($db,$backup,$restore);
       if($restore){
          VentasContactoHistoricoRestaurar30::restaurar($db,$backup);
          $db->commit();
          echo "RESTAURADO: 30 resultados originales, WhatsApp conservado.\n";
       }else{
          $db->commit();
          echo "CHECK OK: respaldo exacto para restaurar 30 contactos; sitio y BD intactos.\n";
       }
    } catch(Throwable $e) {$db->rollback();throw $e;}
 }catch(Throwable $e){
    fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");
    exit(1);
 }
}
