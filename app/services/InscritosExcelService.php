<?php
/**
 * Archivo XLSX real, sin CSV renombrado ni dependencias externas.
 * Requiere ZipArchive. Todas las celdas son texto OOXML (inlineStr):
 * nombres que comiencen con =, +, - o @ JAMÁS son fórmulas.
 */
final class InscritosExcelService
{
    public const COLUMNAS = [
        'Folio','Fecha de registro','Programa','Nombre(s)','Apellidos',
        'Correo electrónico','Teléfono móvil','CURP','Fecha de nacimiento',
        'Sexo en CURP','Nombre de los padres','Calle','Número','Ciudad',
        'Código postal','Municipio','Entidad federativa','Empresa donde labora',
        'Cargo o puesto','Perfil de evaluación','Fecha de inscripción',
        'Origen','Comprobante recibido','Consentimiento registrado'
    ];

    /** Nombre del programa suministrado por el catálogo del CRM. */
    private static function programa(string $slug): string
    {
        $programa = FormularioInscripcionProgramas::porSlug($slug);
        return (string)($programa['nombre'] ?? $slug);
    }

    private static function fechaLocal(string $utc): string
    {
        if ($utc === '') return '';
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('America/Mexico_City'))
                ->format('d/m/Y H:i');
        } catch (Throwable $e) { return $utc; }
    }

    public static function fila(array $r): array
    {
        $datos = json_decode((string)($r['datos'] ?? ''), true);
        if (!is_array($datos)) $datos=[];
        $v = static function (string $key) use ($datos): string {
            return is_scalar($datos[$key] ?? null) ? (string)$datos[$key] : '';
        };
        return [
            (string)($r['id'] ?? ''),
            self::fechaLocal((string)($r['created_at'] ?? '')),
            self::programa((string)($r['programa_slug'] ?? '')),
            (string)($r['nombre'] ?? ''),
            (string)($r['apellido'] ?? ''),
            (string)($r['correo'] ?? ''),
            (string)($r['movil'] ?? ''),
            $v('curp'), $v('fecha_nacimiento'), $v('genero'),
            $v('nombre_padres'), $v('calle'), $v('numero'), $v('ciudad'),
            $v('codigo_postal'), $v('municipio'), $v('entidad_federativa'),
            $v('empresa_labora'), $v('cargo_puesto'), $v('perfil_evaluacion'),
            $v('fecha_inscripcion'), (string)($r['origen'] ?? ''),
            !empty($r['comprobante_mime']) ? 'Recibido (pendiente de revisión)' : 'Sin archivo',
            !empty($r['aceptado_at']) ? 'Aceptado' : 'Sin registro'
        ];
    }

    /** OOXML string segura, con caracteres ilegales excluidos. */
    private static function xml($texto): string
    {
        $limpio = mb_convert_encoding((string)$texto, 'UTF-8', 'UTF-8');
        $limpio = preg_replace('/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{FFFE}\x{FFFF}]/u', '', $limpio);
        return htmlspecialchars(mb_substr((string)$limpio,0,32767,'UTF-8'),
            ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function letra(int $indice): string
    {
        $s='';
        for ($i=$indice;$i>0;$i=(int)floor(($i-1)/26)) {
            $s=chr(65+(($i-1)%26)).$s;
        }
        return $s;
    }

    private static function celda(int $col, int $fila, string $texto, int $estilo = 0): string
    {
        $ref = self::letra($col) . $fila;
        return '<c r="'.$ref.'" s="'.$estilo.'" t="inlineStr"><is><t xml:space="preserve">'
            . self::xml($texto) . '</t></is></c>';
    }

    public static function crear(array $registros, array $filtros): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('La exportación Excel requiere la extensión PHP ZIP.');
        }

        $cantidad=count($registros);
        $ultima = self::letra(count(self::COLUMNAS));
        $lineas = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">',
            '<dimension ref="A1:'.$ultima.max(5,$cantidad+4).'"/>',
            '<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>',
            '<sheetFormatPr defaultRowHeight="17"/>',
            '<cols><col min="1" max="1" width="10" customWidth="1"/>'
            .'<col min="2" max="3" width="25" customWidth="1"/>'
            .'<col min="4" max="7" width="25" customWidth="1"/>'
            .'<col min="8" max="8" width="24" customWidth="1"/>'
            .'<col min="9" max="24" width="24" customWidth="1"/></cols>',
            '<sheetData>'
        ];
        $filtroTxt = [];
        if ($filtros['programa'] !== '') $filtroTxt[]='Programa: '.self::programa($filtros['programa']);
        if ($filtros['desde'] !== '') $filtroTxt[]='Desde: '.$filtros['desde'];
        if ($filtros['hasta'] !== '') $filtroTxt[]='Hasta: '.$filtros['hasta'];
        if ($filtros['buscar'] !== '') $filtroTxt[]='Búsqueda: '.$filtros['buscar'];
        $subtitulo = $filtroTxt ? implode(' · ', $filtroTxt) : 'Todos los programas y fechas';
        $lineas[]='<row r="1" ht="30" customHeight="1">'.self::celda(1,1,'INSCRIPCIONES RECIBIDAS',1).'</row>';
        $lineas[]='<row r="2">'.self::celda(1,2,$subtitulo,2).'</row>';
        $lineas[]='<row r="3">'.self::celda(1,3,'Total: '.$cantidad.' · Fecha de registro: horario de México · Comprobante recibido no significa pago validado',2).'</row>';
        $titulos = '';
        foreach (self::COLUMNAS as $i=>$titulo) $titulos.=self::celda($i+1,4,$titulo,3);
        $lineas[]='<row r="4" ht="28" customHeight="1">'.$titulos.'</row>';
        foreach ($registros as $indice=>$r) {
            $fila=$indice+5;
            $celdas='';
            foreach (self::fila($r) as $j=>$valor) {
                $celdas.=self::celda($j+1,$fila,(string)$valor,($indice%2===0)?4:5);
            }
            $lineas[]='<row r="'.$fila.'">'.$celdas.'</row>';
        }
        $lineas[]='</sheetData>';
        $lineas[]='<mergeCells count="3"><mergeCell ref="A1:'.$ultima.'1"/>'
            .'<mergeCell ref="A2:'.$ultima.'2"/><mergeCell ref="A3:'.$ultima.'3"/></mergeCells>';
        $lineas[]='<autoFilter ref="A4:'.$ultima.max(4,$cantidad+4).'"/>';
        $lineas[]='<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>';
        $lineas[]='</worksheet>';

        $destino=tempnam(sys_get_temp_dir(),'impe-inscritos-');
        if ($destino===false) throw new RuntimeException('No fue posible preparar la exportación.');
        $zip=new ZipArchive();
        try {
            if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE)!==true) {
                throw new RuntimeException('No se pudo abrir el libro Excel.');
            }
            $archivos=[
                '[Content_Types].xml'=> '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',
                '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
                'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="Inscritos" sheetId="1" r:id="rId1"/></sheets></workbook>',
                'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
                'xl/worksheets/sheet1.xml'=>implode("\n",$lineas),
                'xl/styles.xml'=> '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<fonts count="3"><font><sz val="10"/><name val="Aptos"/></font>'
                .'<font><b/><sz val="15"/><color rgb="FF223A84"/><name val="Aptos"/></font>'
                .'<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Aptos"/></font></fonts>'
                .'<fills count="4"><fill><patternFill patternType="none"/></fill>'
                .'<fill><patternFill patternType="gray125"/></fill>'
                .'<fill><patternFill patternType="solid"><fgColor rgb="FF223A84"/><bgColor indexed="64"/></patternFill></fill>'
                .'<fill><patternFill patternType="solid"><fgColor rgb="FFF2F6FC"/><bgColor indexed="64"/></patternFill></fill></fills>'
                .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="6"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>'
                .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                .'<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0"/>'
                .'<xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0"/>'
                .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
                .'</styleSheet>'
            ];
            foreach ($archivos as $nombre=>$contenido) {
                if (!$zip->addFromString($nombre,$contenido)) {
                    throw new RuntimeException('No se pudo escribir la hoja Excel.');
                }
            }
            if (!$zip->close()) throw new RuntimeException('No se pudo finalizar el libro Excel.');
            return $destino;
        } catch (Throwable $e) {
            @$zip->close();
            @unlink($destino);
            throw $e;
        }
    }
}
