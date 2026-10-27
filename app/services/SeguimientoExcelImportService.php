<?php
/**
 * Importación validada de plantilla V3.
 * No genera llamadas, verificaciones, actividades ni convenios en el CRM.
 * Los avances reportados se muestran como antecedentes históricos.
 */
require_once __DIR__.'/../models/SeguimientoVinculacionModel.php';

final class SeguimientoExcelImportService
{
    private $db;
    private $model;
    private const MAX_ROWS=250;
    private const MAX_UPLOAD=2097152;
    private const MAX_UNPACKED=6000000;
    private const HEADERS=[
        'Clave de referencia','Nombre de institución *','Tipo de entidad *','Estado *',
        'Municipio *','Actividad / giro *','Dirección *','Teléfono *',
        'WhatsApp *','Correo institucional *','Sitio web *',
        'Persona de contacto *','Cargo / área *','Paso histórico reportado *',
        'Fecha última gestión','Evidencia / qué se hizo','Próxima acción',
        'Fecha próxima acción','Observaciones'
    ];
    private const TYPES=['EMPRESA','ORGANIZACION','INSTITUCION','SECRETARIA','MUNICIPIO','OTRO'];

    public function __construct(mysqli $db)
    {
        $this->db=$db;
        $this->model=new SeguimientoVinculacionModel($db);
    }

    private static function norm($value): string
    {
        $v=mb_strtolower(trim((string)$value),'UTF-8');
        $v=strtr($v,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        $v=preg_replace('/[^a-z0-9]+/u',' ',$v);
        return trim(preg_replace('/\s+/',' ',$v));
    }

    /** Solo la primera hoja "Instituciones" de la plantilla oficial. */
    public function leerXlsx(array $file): array
    {
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK ||
            !is_uploaded_file((string)($file['tmp_name']??'')) ||
            (int)($file['size']??0)<100 ||
            (int)($file['size']??0)>self::MAX_UPLOAD ||
            !preg_match('/\.xlsx$/i',(string)($file['name']??''))) {
            throw new DomainException('Selecciona una plantilla XLSX válida de máximo 2 MB.',422);
        }
        if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
            throw new DomainException('La extensión ZIP/XML de PHP no está disponible. No se importó ningún registro.',503);
        }
        $zip=new ZipArchive();
        if ($zip->open($file['tmp_name'])!==true) {
            throw new DomainException('El archivo no es un XLSX legible.',422);
        }
        try {
            $total=0;
            for($i=0;$i<$zip->numFiles;$i++){
                $z=$zip->statIndex($i);
                $total+=(int)($z['size']??0);
                if($total>self::MAX_UNPACKED)throw new DomainException('El XLSX excede el límite de contenido descomprimido.',422);
                if(str_contains((string)$z['name'],'../')||str_starts_with((string)$z['name'],'/')){
                    throw new DomainException('El XLSX contiene una ruta inválida.',422);
                }
            }
            $book=$this->xml((string)$zip->getFromName('xl/workbook.xml'));
            $xpath=new DOMXPath($book);
            $xpath->registerNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $sheet=$xpath->query('//x:sheet[@name="Instituciones"]')->item(0);
            if(!$sheet)throw new DomainException('Falta la hoja Instituciones de la plantilla V3.',422);
            $relation=$sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');
            $rels=$this->xml((string)$zip->getFromName('xl/_rels/workbook.xml.rels'));
            $target='';
            foreach($rels->getElementsByTagName('Relationship') as $rel){
                if($rel->getAttribute('Id')===$relation && !$rel->getAttribute('TargetMode')){
                    $target=(string)$rel->getAttribute('Target');break;
                }
            }
            $target=ltrim(str_replace('\\','/',$target),'/');
            if(str_starts_with($target,'xl/'))$target=substr($target,3);
            if(!preg_match('#^worksheets/sheet[0-9]+\.xml$#D',$target)){
                throw new DomainException('La hoja Instituciones no corresponde a una plantilla válida.',422);
            }
            $worksheet=$this->xml((string)$zip->getFromName('xl/'.$target));
            $shared=[];
            $raw=$zip->getFromName('xl/sharedStrings.xml');
            if($raw!==false) {
                $ss=$this->xml($raw);$xp=new DOMXPath($ss);
                $xp->registerNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                foreach($xp->query('//x:si') as $si){
                    $str='';foreach($xp->query('.//x:t',$si) as $t)$str.=$t->textContent;
                    $shared[]=$str;
                    if(count($shared)>10000)throw new DomainException('Exceso de cadenas en el Excel.',422);
                }
            }
            $xp=new DOMXPath($worksheet);
            $xp->registerNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $matrix=[];
            foreach($xp->query('//x:sheetData/x:row') as $row) {
                $number=(int)$row->getAttribute('r');
                if($number<1||$number>self::MAX_ROWS+1){
                    // Celdas vacías decorativas no se consideran registros.
                    if($number<1)throw new DomainException('Fila de XLSX inválida.',422);
                }
                $cells=array_fill(0,19,'');
                foreach($xp->query('./x:c',$row) as $cell){
                    if($xp->query('./x:f',$cell)->length){
                        throw new DomainException('No se aceptan fórmulas en la plantilla, fila '.$number.'.',422);
                    }
                    $ref=(string)$cell->getAttribute('r');
                    if(!preg_match('/^([A-Z]+)[0-9]+$/D',$ref,$m))continue;
                    $col=0;foreach(str_split($m[1]) as $ch)$col=$col*26+ord($ch)-64;
                    if($col<1||$col>19)continue;
                    $type=(string)$cell->getAttribute('t');
                    if($type==='inlineStr'){
                        $parts=$xp->query('.//x:is//x:t',$cell);$str='';
                        foreach($parts as $part)$str.=$part->textContent;
                    }else{
                        $value=$xp->query('./x:v',$cell)->item(0);
                        $str=$value?(string)$value->textContent:'';
                        if($type==='s')$str=$shared[(int)$str]??'';
                    }
                    $cells[$col-1]=trim($str);
                }
                if($number===1){
                    foreach(self::HEADERS as $i=>$header)if(self::norm($cells[$i])!==self::norm($header)){
                        throw new DomainException('La columna '.($i+1).' no corresponde a la plantilla V3: '.$header.'.',422);
                    }
                } elseif($number>1 && implode('', $cells)!==''){
                    if($number>self::MAX_ROWS+1)throw new DomainException('La plantilla admite como máximo 250 instituciones.',422);
                    $matrix[]=['fila'=>$number,'celdas'=>$cells];
                }
            }
            if(count($matrix)>self::MAX_ROWS)throw new DomainException('La plantilla supera 250 instituciones.',422);
            if(!$matrix)throw new DomainException('El Excel no contiene instituciones para importar.',422);
            return $matrix;
        }finally{$zip->close();}
    }

    private function xml(string $contents): DOMDocument
    {
        if($contents===''||strlen($contents)>self::MAX_UNPACKED){
            throw new DomainException('El contenido XLSX está incompleto.',422);
        }
        $dom=new DOMDocument();
        $previous=libxml_use_internal_errors(true);
        try {
            if(!$dom->loadXML($contents,LIBXML_NONET|LIBXML_NOBLANKS)){
                throw new DomainException('Una parte del XLSX no contiene XML válido.',422);
            }
        } finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
        return $dom;
    }

    private function resolverUbicacion(string $estado,string $municipio,array $scope): array
    {
        $match=[];
        foreach($scope as $s)if(self::norm($s['nombre'])===self::norm($estado))$match[]=$s;
        if(count($match)!==1)throw new DomainException('Estado fuera de los territorios asignados.',422);
        $id=(int)$match[0]['id'];
        $stmt=$this->db->prepare('SELECT id,nombre FROM municipios WHERE estado_id=? AND estado=1');
        $stmt->bind_param('i',$id);$stmt->execute();
        $found=[];
        foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $m){
            if(self::norm($m['nombre'])===self::norm($municipio))$found[]=$m;
        }
        if(count($found)!==1)throw new DomainException('Municipio no encontrado o ambiguo para '.$estado.'.',422);
        return [$id,(int)$found[0]['id']];
    }

    private function comprobarFila(array $cells,array $scope,int $analista): array
    {
        foreach(range(1,13) as $idx){
            if(trim((string)$cells[$idx])==='')throw new DomainException('Falta el campo obligatorio «'.self::HEADERS[$idx].'».',422);
        }
        foreach($cells as $idx=>$value) {
            if(mb_strlen($value,'UTF-8')>($idx===18?1500:($idx===15?900:240))) {
                throw new DomainException('Texto demasiado largo: '.self::HEADERS[$idx].'.',422);
            }
            if(preg_match('/^[=+\-@]/u',$value) && $idx!==14 && $idx!==17){
                throw new DomainException('No se admiten valores que comienzan con fórmulas en '.self::HEADERS[$idx].'.',422);
            }
        }
        $type=self::norm($cells[2]);
        $map=['institucion'=>'INSTITUCION','empresa'=>'EMPRESA','organizacion'=>'ORGANIZACION','secretaria'=>'SECRETARIA','municipio'=>'MUNICIPIO','otro'=>'OTRO'];
        $type=$map[$type]??strtoupper($cells[2]);
        if(!in_array($type,self::TYPES,true))throw new DomainException('Tipo de entidad no reconocido.',422);
        [$estado,$municipio]=$this->resolverUbicacion($cells[3],$cells[4],$scope);
        if(!filter_var($cells[9],FILTER_VALIDATE_EMAIL))throw new DomainException('Correo institucional inválido.',422);
        $site=trim($cells[10]);
        if(!preg_match('#^https?://#i',$site))$site='https://'.$site;
        if(!filter_var($site,FILTER_VALIDATE_URL)||mb_strlen($site)>255)throw new DomainException('Sitio web inválido.',422);
        if(!preg_match('/^\s*(0[0-9]|1[0-3])(?:\s*[|.\-]\s*|\s*$)/',$cells[13],$m)){
            throw new DomainException('Selecciona un paso histórico del catálogo (00–13).',422);
        }
        $step=(int)$m[1];
        foreach([7,8] as $idx){
            $digits=preg_replace('/\D/','',$cells[$idx]);
            if(strlen($digits)<8||strlen($digits)>15)throw new DomainException('Número de teléfono/WhatsApp inválido.',422);
        }
        return [
            'estado_id'=>$estado,'municipio_id'=>$municipio,'analista_id'=>$analista,
            'tipo'=>$type,'nombre'=>trim($cells[1]),'telefono'=>trim($cells[7]),
            'whatsapp'=>trim($cells[8]),'correo'=>mb_strtolower(trim($cells[9]),'UTF-8'),
            'contacto'=>trim($cells[11]),'cargo'=>trim($cells[12]),'actividad'=>trim($cells[5]),
            'direccion'=>trim($cells[6]),'sitio'=>$site,'paso'=>$step,
            'referencia'=>trim($cells[0]),'fecha_ultima'=>trim($cells[14]),
            'evidencia'=>trim($cells[15]),'accion'=>trim($cells[16]),
            'fecha_proxima'=>trim($cells[17]),'observaciones'=>trim($cells[18])
        ];
    }

    private static function clave(array $r): string
    {
        return hash('sha256',implode('|',[$r['estado_id'],$r['tipo'],self::norm($r['nombre']),$r['municipio_id']]));
    }

    /** Coteja seguimientos de todos los orígenes y analistas del mismo estado. */
    private function coincidencias(array $r): array
    {
        $sql='SELECT id, nombre_entidad, tipo_entidad, municipio_id, telefono_fuente,telefono_verificado,
                     correo_fuente,correo_verificado,clave_origen,analista_id,activo
              FROM seguimientos_vinculacion
              WHERE estado_id=? AND (LOWER(TRIM(nombre_entidad))=LOWER(TRIM(?))
                OR LOWER(TRIM(correo_fuente))=LOWER(TRIM(?))
                OR LOWER(TRIM(correo_verificado))=LOWER(TRIM(?))
                OR telefono_fuente=? OR telefono_verificado=?
                OR clave_origen=?) LIMIT 30';
        $key='XLSX:'.self::clave($r);
        $stmt=$this->db->prepare($sql);
        $stmt->bind_param('issssss',$r['estado_id'],$r['nombre'],$r['correo'],$r['correo'],$r['telefono'],$r['telefono'],$key);
        $stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $exact=[];$possible=[];
        foreach($rows as $s){
            $sameName=self::norm($s['nombre_entidad'])===self::norm($r['nombre']);
            $sameType=(string)$s['tipo_entidad']===$r['tipo'];
            $sameMunicipality=(int)$s['municipio_id']===$r['municipio_id'];
            $sameKey=(string)$s['clave_origen']===$key;
            if($sameKey||($sameName&&$sameType&&$sameMunicipality))$exact[]=$s;
            elseif($sameName||strcasecmp((string)$s['correo_fuente'],$r['correo'])===0||
                strcasecmp((string)$s['correo_verificado'],$r['correo'])===0||
                preg_replace('/\D/','',(string)$s['telefono_fuente'])===preg_replace('/\D/','',$r['telefono'])){
                $possible[]=$s;
            }
        }
        if($exact)return ['estado'=>'existente','id'=>(int)$exact[0]['id'],'mensaje'=>'Institución previamente registrada; se omite sin alterar su expediente.'];
        if($possible||count($rows)>=30)return ['estado'=>'revisar','id'=>null,'mensaje'=>'Posible coincidencia de nombre o contacto: revisar manualmente.'];
        return ['estado'=>'nueva','id'=>null,'mensaje'=>'Lista para importar.'];
    }

    public function previsualizar(array $rows,array $territorios,int $analista,int $usuario): array
    {
        $result=[];$seen=[];
        foreach($rows as $line){
            $num=$line['fila'];
            try {
                $r=$this->comprobarFila($line['celdas'],$territorios,$analista);
                $key=self::clave($r);
                if(isset($seen[$key])){
                    $state=['estado'=>'revisar','id'=>null,'mensaje'=>'Repetida dentro del Excel (fila '.$seen[$key].').'];
                }else{
                    $seen[$key]=$num;$state=$this->coincidencias($r);
                }
                $result[]=['fila'=>$num,'institucion'=>$r['nombre'],'estado'=>$state['estado'],
                    'mensaje'=>$state['mensaje'],'id'=>$state['id'],'registro'=>$r];
            }catch(DomainException $e){
                $result[]=['fila'=>$num,'institucion'=>mb_substr((string)($line['celdas'][1]??''),0,130),
                    'estado'=>'invalida','mensaje'=>$e->getMessage(),'id'=>null,'registro'=>null];
            }
        }
        $counts=array_count_values(array_column($result,'estado'));
        return ['filas'=>$result,'resumen'=>[
            'nuevas'=>$counts['nueva']??0,'existentes'=>$counts['existente']??0,
            'revisar'=>$counts['revisar']??0,'invalidas'=>$counts['invalida']??0,
            'total'=>count($result)
        ]];
    }

    public function confirmar(array $preview,array $scope,int $analista,int $usuario): array
    {
        // Exclusión mutua entre cargas paralelas, además de verificar cada fila de nuevo.
        $locked=(int)($this->db->query("SELECT GET_LOCK('impe_seguimiento_excel_import_v1',10) AS adquirido")
            ->fetch_assoc()['adquirido']??0);
        if($locked!==1)throw new DomainException('Hay otra importación en curso. Intenta nuevamente.',409);
        $saved=0;$skipped=0;$ids=[];$model=$this->model;
        try {
            $this->db->begin_transaction();
            foreach($preview as $line){
                if($line['estado']!=='nueva')continue;
                $r=$line['registro'];
                $allowed=array_filter($scope,static fn($territorio)=>(int)$territorio['id']===(int)$r['estado_id']);
                if(!$allowed||$r['analista_id']!==$analista)throw new DomainException('El permiso territorial cambió; revisión necesaria.',403);
                $check=$this->coincidencias($r);
                if($check['estado']!=='nueva'){$skipped++;continue;}
                $notes="Importación Excel V3 · Paso histórico reportado: ".str_pad((string)$r['paso'],2,'0',STR_PAD_LEFT).
                    " (declarado, no verificado).". "\n".
                    "IMPE_ETAPA_REPORTADA_EXCEL_V1=".str_pad((string)$r['paso'],2,'0',STR_PAD_LEFT);
                foreach(['referencia'=>'Referencia del analista','fecha_ultima'=>'Última gestión histórica reportada',
                    'evidencia'=>'Evidencia histórica declarada','accion'=>'Próxima acción declarada',
                    'fecha_proxima'=>'Fecha futura declarada','observaciones'=>'Observaciones del Excel'] as $key=>$label){
                    if($r[$key]!=='')$notes.="\n".$label.': '.$r[$key];
                }
                $key=self::clave($r);
                $payload=['estado_id'=>$r['estado_id'],'municipio_id'=>$r['municipio_id'],
                    'analista_id'=>$analista,'usuario_id'=>$usuario,'clave_origen'=>'XLSX:'.$key,
                    'tipo_entidad'=>$r['tipo'],'nombre'=>$r['nombre'],'telefono'=>$r['telefono'],
                    'whatsapp'=>$r['whatsapp'],'correo'=>$r['correo'],'contacto_nombre'=>$r['contacto'],
                    'contacto_cargo'=>$r['cargo'],'datos_verificados'=>0,'estado_seguimiento'=>'NUEVO',
                    'motivo_descarte'=>'','observaciones'=>$notes,'ultima_actividad_at'=>null,
                    'proxima_accion_at'=>null];
                $id=(int)$model->crearSeguimientoManual($payload,true);
                $stmt=$this->db->prepare('UPDATE seguimientos_vinculacion SET actividad_giro=?,direccion_fuente=?,sitio_web_fuente=? WHERE id=?');
                $stmt->bind_param('sssi',$r['actividad'],$r['direccion'],$r['sitio'],$id);
                $stmt->execute();
                $ids[]=$id;$saved++;
            }
            $this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
        finally{$this->db->query("SELECT RELEASE_LOCK('impe_seguimiento_excel_import_v1')");}
        return ['insertadas'=>$saved,'omitidas_adicionales'=>$skipped,'ids'=>$ids];
    }
}
