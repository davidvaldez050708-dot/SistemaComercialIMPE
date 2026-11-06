<?php
declare(strict_types=1);

/**
 * Auditoría PRIVADA de resultados antiguos con SOLO «Se envió por WhatsApp».
 *
 * No hace UPDATE/INSERT/DELETE/CREATE, no interpreta grabaciones, no consulta
 * proveedores externos y no convierte automáticamente un audio en contacto.
 * El archivo JSON incluye referencias PBX: NUNCA publicarlo en public_html.
 */
final class VentasContactoHistoricoAuditoria
{
    private const REQUIRED_TABLES = [
        'telefonia_ventas_resultados',
        'telefonia_ventas_resultado_detalles',
        'telefonia_ventas_resultado_selecciones',
        'telefonia_ventas_llamadas',
        'telefonia_zadarma_eventos',
    ];

    public static function categoria(array $r): string
    {
        if ((string)($r['resultado'] ?? '') !== 'ENVIO_WHATSAPP'
            || (string)($r['catalogo_version'] ?? '') !== '3'
            || (string)($r['envio_informacion'] ?? '') !== 'WHATSAPP'
            || (int)($r['selecciones_total'] ?? 0) !== 1
            || (int)($r['selecciones_whatsapp'] ?? 0) !== 1) {
            return 'NO_ES_ENVIO_WHATSAPP_UNICO_V3';
        }
        if (empty($r['llamada_id'])) return 'LLAMADA_NO_VINCULADA';
        if (empty($r['conexion_pbx'])) return 'SIN_RESPUESTA_PBX_ACREDITADA';
        if (empty($r['grabacion_propia'])) return 'SIN_REFERENCIA_LOCAL_DE_AUDIO';
        return 'PENDIENTE_ESCUCHA_Y_CONFIRMACION_HUMANA';
    }

    public static function consultar(mysqli $db): array
    {
        foreach (self::REQUIRED_TABLES as $table) {
            $q=$db->prepare('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $q->bind_param('s',$table);$q->execute();
            $found=(int)$q->get_result()->fetch_assoc()['n'];$q->close();
            if ($found!==1) throw new RuntimeException('Falta una tabla de telefonía; no se genera listado parcial: '.$table);
        }
        // Esta consulta no toca prospectos, números telefónicos ni audio.
        // Los subqueries están acotados a ID de resultado y PBX + extensión
        // exacta; un CDR o evento de otra extensión no acredita contacto.
        $sql="SELECT r.id resultado_id,r.usuario_id,r.extension,r.pbx_call_id,
                 r.resultado, r.created_at fecha_registro,
                 d.catalogo_version,d.envio_informacion,
                 c.id llamada_id,c.fecha fecha_llamada,c.direccion,
                 (SELECT COUNT(*) FROM telefonia_ventas_resultado_selecciones s
                     WHERE s.resultado_id=r.id) selecciones_total,
                 (SELECT COUNT(*) FROM telefonia_ventas_resultado_selecciones s
                     WHERE s.resultado_id=r.id AND s.codigo='WHATSAPP') selecciones_whatsapp,
                 EXISTS(SELECT 1 FROM telefonia_zadarma_eventos e
                     WHERE e.pbx_call_id=BINARY r.pbx_call_id
                       AND e.internal=BINARY r.extension AND e.evento='NOTIFY_ANSWER')
                     conexion_pbx,
                 EXISTS(SELECT 1 FROM telefonia_zadarma_eventos e
                     WHERE e.pbx_call_id=BINARY r.pbx_call_id
                       AND e.internal=BINARY r.extension
                       AND e.is_recorded=1
                       AND e.call_id_with_rec IS NOT NULL
                       AND e.call_id_with_rec<>'')
                     grabacion_propia
              FROM telefonia_ventas_resultados r
              JOIN telefonia_ventas_resultado_detalles d ON d.resultado_id=r.id
              LEFT JOIN telefonia_ventas_llamadas c
                ON c.usuario_id=r.usuario_id AND c.extension=BINARY r.extension
                    AND c.pbx_call_id=BINARY r.pbx_call_id
              WHERE r.resultado='ENVIO_WHATSAPP'
                AND d.catalogo_version='3'
              ORDER BY r.id ASC LIMIT 5001";
        $rows=$db->query($sql)->fetch_all(MYSQLI_ASSOC);
        if(count($rows)>5000)throw new RuntimeException('Hay más de 5000 registros; evitar auditoría parcial. Acotar antes de continuar.');
        $items=[];
        foreach($rows as $r){
            $category=self::categoria($r);
            $items[]=[
                'resultado_id'=>(int)$r['resultado_id'],
                'usuario_id'=>(int)$r['usuario_id'],
                'extension'=>(string)$r['extension'],
                'pbx_call_id'=>(string)$r['pbx_call_id'],
                'fecha_llamada'=>$r['fecha_llamada'],
                'fecha_registro'=>(string)$r['fecha_registro'],
                'direccion'=>$r['direccion'],
                'categoria'=>$category,
                'conexion_pbx'=>(bool)$r['conexion_pbx'],
                'referencia_grabacion_local'=>(bool)$r['grabacion_propia'],
                'revision_humana'=>'PENDIENTE',
                'observacion_revision'=>'',
            ];
        }
        return $items;
    }

    public static function resumen(array $items): array
    {
        $summary=['total'=>count($items),'categorias'=>[],'por_usuario'=>[]];
        foreach($items as $row){
            $category=$row['categoria'];
            $summary['categorias'][$category]=($summary['categorias'][$category]??0)+1;
            $key=(string)$row['usuario_id'];
            if(!isset($summary['por_usuario'][$key]))
                $summary['por_usuario'][$key]=[
                    'extension'=>$row['extension'],'candidatas'=>0,
                    'pendientes_escucha'=>0,
                ];
            ++$summary['por_usuario'][$key]['candidatas'];
            if($category==='PENDIENTE_ESCUCHA_Y_CONFIRMACION_HUMANA')
                ++$summary['por_usuario'][$key]['pendientes_escucha'];
        }
        ksort($summary['por_usuario']);
        return $summary;
    }

    public static function guardar(string $site,string $root,array $items): string
    {
        $site=realpath($site);
        if($site===false)throw new RuntimeException('No existe la ruta del sitio.');
        $sourceDir=rtrim($root,DIRECTORY_SEPARATOR);
        if($sourceDir===''||is_link($sourceDir))
            throw new RuntimeException('La ruta del diagnóstico no es segura.');
        // Resolver rutas con ".." ANTES de crear archivos o carpetas.
        $destDir=realpath($sourceDir);
        if($destDir===false){
            $parent=realpath(dirname($sourceDir));
            if($parent===false||!is_dir($parent))
                throw new RuntimeException('El directorio padre del diagnóstico debe existir.');
            $destDir=$parent.DIRECTORY_SEPARATOR.basename($sourceDir);
        }
        if($destDir===$site||
            strncmp($destDir,$site.DIRECTORY_SEPARATOR,strlen($site)+1)===0)
            throw new RuntimeException('El diagnóstico debe quedar fuera de public_html.');
        if(!is_dir($destDir) && !mkdir($destDir,0700) && !is_dir($destDir))
            throw new RuntimeException('No fue posible crear carpeta privada.');
        chmod($destDir,0700);
        $file=$destDir.'/ventas_whatsapp_historico_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.json';
        $document=[
            'tipo'=>'AUDITORIA_SOLO_LECTURA',
            'fecha_generacion'=>date(DATE_ATOM),
            'advertencia'=>'Grabación y ANSWERED no prueban conversación humana. Escuchar y confirmar cada llamada.',
            'decision_por_defecto'=>'PENDIENTE; no aplicar correcciones desde este archivo sin revisión individual',
            'resumen'=>self::resumen($items),
            'llamadas'=>$items,
        ];
        $raw=json_encode($document,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $previous=umask(0077);
        try {
            if(file_put_contents($file,$raw,LOCK_EX)===false)
                throw new RuntimeException('No fue posible escribir la auditoría privada.');
            chmod($file,0600);
        } finally {umask($previous);}
        return $file;
    }
}

if(PHP_SAPI==='cli' && isset($_SERVER['SCRIPT_FILENAME']) &&
   realpath((string)$_SERVER['SCRIPT_FILENAME'])===__FILE__){
    try{
        $opts=getopt('', ['site:','out-dir:','audit']);
        if(empty($opts['site'])||!array_key_exists('audit',$opts))
            throw new InvalidArgumentException('Uso: php script.php --audit --site=/ruta/public_html [--out-dir=/ruta/privada]');
        $site=realpath((string)$opts['site']);
        if($site===false||!is_file($site.'/index.php'))
            throw new RuntimeException('La ruta no corresponde al CRM.');
        require_once $site.'/config/db_connection.php';
        mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
        $db=(new Database())->connect(false);
        $db->query('START TRANSACTION READ ONLY');
        try{$items=VentasContactoHistoricoAuditoria::consultar($db);$db->commit();}
        catch(Throwable $e){$db->rollback();throw $e;}
        $out=(string)($opts['out-dir']??(getenv('HOME').'/diagnosticos-impe-contacto-historico'));
        $file=VentasContactoHistoricoAuditoria::guardar($site,$out,$items);
        $summary=VentasContactoHistoricoAuditoria::resumen($items);
        echo "SOLO LECTURA: ". $summary['total']." registros antiguos de WhatsApp.\n";
        foreach($summary['categorias'] as $status=>$count)
            echo $status.': '.$count."\n";
        foreach($summary['por_usuario'] as $id=>$data)
            echo 'ASESOR ID '.$id.' - EXT '.$data['extension'].
                 ' - CANDIDATAS '.$data['candidatas'].
                 ' - PARA ESCUCHAR '.$data['pendientes_escucha']."\n";
        echo "AUDITORIA PRIVADA: ".$file."\n";
        echo "SITIO Y BASE INTACTOS. Ningún registro reclasificado.\n";
    }catch(Throwable $e){
        fwrite(STDERR,"ERROR: ".$e->getMessage()."\n");exit(1);
    }
}
