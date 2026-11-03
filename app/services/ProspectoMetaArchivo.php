<?php
require_once __DIR__.'/../helpers/ProspectoCentralDatos.php';
/**
 * Lector limitado de exportaciones de clientes potenciales de Meta.
 * CSV/TSV UTF-8, UTF-16 LE/BE, Windows-1252; XLSX (primera hoja).
 * No ejecuta fórmulas, macros, enlaces, ni carga contenido remoto.
 */
final class ProspectoMetaArchivo
{
    public const MAX_BYTES=8388608;
    public const MAX_ROWS=5000;
    public const MAX_UNCOMPRESSED=33554432;
    private const COLUMNAS=['id','created_time','full_name','phone_number'];
    private const OPCIONALES=['ad_id','ad_name','adset_id','adset_name','campaign_id',
        'campaign_name','form_id','form_name','is_organic','platform','estado','retailer_item_id'];

    public static function leer(string $path,string $nombre): array
    {
        if (!is_file($path) || filesize($path)>self::MAX_BYTES || filesize($path)<10) {
            throw new DomainException('El archivo está vacío o supera el límite de 8 MB.');
        }
        $ext=strtolower(pathinfo($nombre,PATHINFO_EXTENSION));
        if (!in_array($ext,['','csv','tsv','txt','xlsx'],true))
            throw new DomainException('Utiliza CSV, TSV o Excel .xlsx. Convierte archivos .xls antiguos a .xlsx.');
        $fh=fopen($path,'rb');$magic=fread($fh,4);fclose($fh);
        if (str_starts_with($magic,'PK')) {
            if ($ext!=='' && $ext!=='xlsx') throw new DomainException('La extensión no coincide con el formato Excel.');
            $matriz=self::leerXlsx($path);
        } else {
            if ($ext==='xlsx') throw new DomainException('El archivo no tiene una estructura Excel .xlsx válida.');
            $matriz=self::leerCsv($path);
        }
        if (count($matriz)<2) throw new DomainException('El archivo no contiene clientes potenciales.');
        $cabecera=array_shift($matriz);
        $mapa=[];$duplicada=false;
        foreach ($cabecera as $i=>$col) {
            $key=strtolower(trim(self::quitarBom((string)$col)));
            if ($key==='') continue;
            if (isset($mapa[$key])) $duplicada=true;
            $mapa[$key]=$i;
        }
        if ($duplicada || array_diff(self::COLUMNAS,array_keys($mapa)))
            throw new DomainException('Faltan encabezados de Meta obligatorios: id, created_time, full_name o phone_number.');
        $filas=[];$errores=[];$duplicados=0;$vistos=[];$campanias=[];$filasRechazadas=0;
        foreach ($matriz as $n=>$valores) {
            $numero=$n+2;
            $row=[];
            foreach (array_merge(self::COLUMNAS,self::OPCIONALES) as $col) {
                $valor=isset($mapa[$col])?(string)($valores[$mapa[$col]]??''):'';
                $row[$col]=self::limpiar($valor,($col==='full_name'||str_ends_with($col,'name'))?255:190);
            }
            // Meta exporta IDs con prefijos como l:, c:, f: y el teléfono como p:+52.
            $id=self::quitarBom($row['id']);
            if (str_starts_with(strtolower($id),'l:')) $id=substr($id,2);
            $id=trim($id);
            $telefono=preg_replace('/^p:/i','',$row['phone_number']);
            $creado=self::fechaUtc($row['created_time']);
            $nombreCompleto=$row['full_name'];
            $valido=preg_match('/^[0-9]{1,19}$/D',$id) && $creado!==null
                && $nombreCompleto!=='' && mb_strlen($nombreCompleto)<=255
                && ProspectoCentralDatos::telefono($telefono)!==null
                && mb_strlen($telefono)<=64;
            if (!$valido) {
                $filasRechazadas++;
                if (count($errores)<20) $errores[]='Fila '.$numero.': revisa ID, fecha, nombre o teléfono.';
                continue;
            }
            if (isset($vistos[$id])) {$duplicados++;continue;}
            $vistos[$id]=true;
            // No interpretar nombres de campaña como programa académico.
            $campana=$row['campaign_name']!==''?$row['campaign_name']:'Campaña no especificada';
            $clave=hash('sha256',$row['campaign_id']!==''?'id:'.$row['campaign_id']:'nombre:'.$campana);
            if (!isset($campanias[$clave])) {
                $campanias[$clave]=['clave'=>$clave,'id'=>$row['campaign_id'],'nombre'=>$campana,'total'=>0];
            }
            $campanias[$clave]['total']++;
            $row['id']=$id;
            $row['phone_number']=$telefono;
            $row['fecha_utc']=$creado;
            $row['campania_clave']=$clave;
            $row['linea']=$numero;
            $filas[]=$row;
        }
        if (!$filas) throw new DomainException('No hay filas importables. Revisa los identificadores, fechas y teléfonos de Meta.');
        uasort($campanias,static fn($a,$b)=>$b['total']<=>$a['total']);
        return [
            'filas'=>$filas,
            'campanias'=>array_values($campanias),
            'total_filas'=>count($matriz),
            'validas'=>count($filas),
            'repetidas_archivo'=>$duplicados,
            'rechazadas'=>$filasRechazadas,
            'errores'=>$errores,
            'muestra'=>array_slice($filas,0,12),
        ];
    }

    private static function limpiar(string $v,int $max): string
    {
        $v=trim(self::quitarBom($v));
        if (!mb_check_encoding($v,'UTF-8')) throw new DomainException('El archivo contiene caracteres no válidos.');
        if (str_contains($v,"\0") || mb_strlen($v)>$max) throw new DomainException('Una celda supera el tamaño permitido o contiene caracteres no válidos.');
        return $v;
    }

    private static function quitarBom(string $v): string
    {
        return preg_replace('/^\xEF\xBB\xBF/u','',$v);
    }

    private static function fechaUtc(string $v): ?string
    {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D',$v)) return null;
        try {
            $d=new DateTimeImmutable($v);
            if ((int)$d->format('Y')<2000 || (int)$d->format('Y')>2100 || $d->format('Y-m-d\TH:i:s')!==substr($v,0,19)) return null;
            return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {return null;}
    }

    private static function leerCsv(string $path): array
    {
        $data=file_get_contents($path);
        if (str_starts_with($data,"\xff\xfe")) $data=iconv('UTF-16LE','UTF-8',substr($data,2));
        elseif (str_starts_with($data,"\xfe\xff")) $data=iconv('UTF-16BE','UTF-8',substr($data,2));
        elseif (strpos(substr($data,0,512),"\0")!==false)
            throw new DomainException('Codificación de texto no compatible.');
        if ($data===false) throw new DomainException('No se pudo interpretar la codificación del archivo.');
        $data=self::quitarBom($data);
        if (!mb_check_encoding($data,'UTF-8')) {
            $data=iconv('Windows-1252','UTF-8',$data);
            if ($data===false || !mb_check_encoding($data,'UTF-8'))
                throw new DomainException('Codificación de texto no reconocida.');
        }
        $primera=strtok($data,"\r\n");
        $separadores=["\t",',',';'];
        usort($separadores,static fn($a,$b)=>substr_count($primera,$b)<=>substr_count($primera,$a));
        $delim=$separadores[0];
        if (substr_count($primera,$delim)<3)
            throw new DomainException('No se identificaron las columnas delimitadas de Meta.');
        $stream=fopen('php://temp/maxmemory:2097152','w+');
        fwrite($stream,$data);rewind($stream);unset($data);
        $out=[];
        while (($cols=fgetcsv($stream,0,$delim,'"','\\'))!==false) {
            if (count($cols)===1 && trim((string)$cols[0])==='') continue;
            if (count($cols)>80) throw new DomainException('El archivo contiene demasiadas columnas.');
            $out[]=$cols;
            if (count($out)>self::MAX_ROWS+1) throw new DomainException('Se permiten máximo 5,000 registros por archivo.');
        }
        fclose($stream);
        return $out;
    }

    private static function leerXlsx(string $path): array
    {
        if (!class_exists('ZipArchive') || !class_exists('DOMDocument'))
            throw new DomainException('El servidor requiere las extensiones PHP ZIP y XML para leer Excel .xlsx.');
        $zip=new ZipArchive();
        if ($zip->open($path)!==true) throw new DomainException('El archivo Excel no se puede abrir.');
        try {
            $total=0;
            for ($i=0;$i<$zip->numFiles;$i++) {
                $meta=$zip->statIndex($i);
                $total+=(int)($meta['size']??0);
                if ($total>self::MAX_UNCOMPRESSED || $zip->numFiles>1000)
                    throw new DomainException('Excel supera el límite de seguridad al descomprimirse.');
                if ((int)($meta['size']??0)>self::MAX_UNCOMPRESSED)
                    throw new DomainException('El archivo Excel contiene una hoja demasiado grande.');
            }
            $workbook=self::xml($zip->getFromName('xl/workbook.xml'));
            $rels=self::xml($zip->getFromName('xl/_rels/workbook.xml.rels'));
            $sheets=$workbook->getElementsByTagName('sheet');
            if (!$sheets->length) throw new DomainException('Excel no contiene hojas.');
            $relation=$sheets->item(0)->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');
            $ruta=null;
            foreach ($rels->getElementsByTagName('Relationship') as $r) {
                if ($r->getAttribute('Id')!==$relation) continue;
                if ($r->getAttribute('TargetMode')==='External') throw new DomainException('Hoja externa no permitida.');
                $target=ltrim(str_replace('\\','/',$r->getAttribute('Target')),'/');
                $ruta=str_starts_with($target,'xl/')?$target:'xl/'.$target;
                break;
            }
            if (!$ruta || str_contains($ruta,'..') || !preg_match('#^xl/worksheets/[a-zA-Z0-9_-]+\.xml$#D',$ruta))
                throw new DomainException('La hoja de Excel no tiene una ruta válida.');
            $strings=[];
            $xmlStrings=$zip->getFromName('xl/sharedStrings.xml');
            if ($xmlStrings!==false) {
                $doc=self::xml($xmlStrings);
                foreach ($doc->getElementsByTagName('si') as $s) {
                    $value='';
                    foreach ($s->getElementsByTagName('t') as $t) $value.=$t->textContent;
                    $strings[]=$value;
                    if (count($strings)>50000) throw new DomainException('Excel excede el catálogo de textos permitido.');
                }
            }
            $hoja=self::xml($zip->getFromName($ruta));
            $out=[];
            foreach ($hoja->getElementsByTagName('row') as $row) {
                $cells=[];
                foreach ($row->getElementsByTagName('c') as $cell) {
                    $coord=$cell->getAttribute('r');
                    if (!preg_match('/^([A-Z]{1,3})[0-9]+$/D',$coord,$m)) continue;
                    $index=0;
                    foreach (str_split($m[1]) as $letter) $index=$index*26+ord($letter)-64;
                    $index--;
                    if ($index>=80) throw new DomainException('Excel contiene más de 80 columnas.');
                    $type=$cell->getAttribute('t');
                    if ($type==='e') throw new DomainException('Excel contiene celdas con errores.');
                    if ($type==='f') throw new DomainException('No se permiten fórmulas.');
                    foreach ($cell->getElementsByTagName('f') as $_) throw new DomainException('No se permiten fórmulas.');
                    $value='';
                    if ($type==='inlineStr') {
                        foreach ($cell->getElementsByTagName('t') as $t) $value.=$t->textContent;
                    } else {
                        $v=$cell->getElementsByTagName('v')->item(0);
                        $value=$v?$v->textContent:'';
                        if ($type==='s') {
                            if (!ctype_digit($value)||!array_key_exists((int)$value,$strings)) throw new DomainException('Texto compartido no válido.');
                            $value=$strings[(int)$value];
                        }
                    }
                    if (isset($cells[$index])) throw new DomainException('Excel contiene columnas duplicadas.');
                    $cells[$index]=$value;
                }
                if (!$cells) continue;
                $out[]=array_replace(array_fill(0,max(array_keys($cells))+1,''),$cells);
                if (count($out)>self::MAX_ROWS+1) throw new DomainException('Se permiten máximo 5,000 registros por archivo.');
            }
            return $out;
        } finally {$zip->close();}
    }

    private static function xml($content): DOMDocument
    {
        if ($content===false || $content===null || strlen($content)>self::MAX_UNCOMPRESSED || stripos($content,'<!DOCTYPE')!==false || stripos($content,'<!ENTITY')!==false)
            throw new DomainException('Estructura XML de Excel no permitida.');
        $doc=new DOMDocument('1.0','UTF-8');
        $previous=libxml_use_internal_errors(true);
        try {
            if (!$doc->loadXML($content,LIBXML_NONET|LIBXML_COMPACT))
                throw new DomainException('El archivo Excel contiene una hoja no válida.');
            return $doc;
        } finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
}
