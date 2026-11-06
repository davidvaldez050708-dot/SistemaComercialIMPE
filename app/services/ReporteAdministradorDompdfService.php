<?php
/** Renderiza el DOCX administrativo ya preparado. Sólo recursos embebidos; sin red ni procesos. */
final class ReporteAdministradorDompdfService
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private DOMXPath $xp;
    private array $imagenes = [];

    public function convertir(string $docx, string $directorio): array
    {
        try {
            $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
            if (!is_file($autoload)) throw new RuntimeException('Falta el autoloader de Dompdf.');
            require_once $autoload;
            if (!class_exists(\Dompdf\Dompdf::class)) throw new RuntimeException('Dompdf no está disponible.');
            $html = $this->html($docx);
            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('chroot', dirname(__DIR__, 2));
            $pdf = new \Dompdf\Dompdf($options);
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->setPaper('A4', 'portrait');
            $pdf->render();
            $canvas = $pdf->getCanvas();
            $font = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
            $canvas->page_text(42, 816, 'Grupo Porcayo - Sistema de Gestión Comercial', $font, 7, [.43,.46,.52]);
            $canvas->page_text(480, 816, '{PAGE_NUM} / {PAGE_COUNT}', $font, 7, [.43,.46,.52]);
            $bytes = $pdf->output();
            if (strncmp($bytes, '%PDF-', 5)!==0) throw new RuntimeException('Salida PDF no válida.');
            $ruta = $directorio.DIRECTORY_SEPARATOR.pathinfo($docx, PATHINFO_FILENAME).'.pdf';
            if (file_put_contents($ruta, $bytes)!==strlen($bytes)) throw new RuntimeException('No se pudo escribir el PDF temporal.');
            return ['ok'=>true,'ruta_pdf'=>$ruta,'conversor'=>'DOMPDF'];
        } catch (Throwable $e) {
            return ['ok'=>false,'mensaje'=>'No fue posible generar el PDF de usuarios. Intenta nuevamente.',
                'mensaje_tecnico'=>$e->getMessage()];
        }
    }

    public function html(string $docx): string
    {
        $zip = new ZipArchive();
        if ($zip->open($docx)!==true) throw new RuntimeException('No se pudo leer el reporte DOCX preparado.');
        $this->imagenes=[];
        try {
            $logo=$zip->getFromName('word/media/image1.png');
            if (is_string($logo) && strlen($logo)<=5*1024*1024 && strncmp($logo,"\x89PNG",4)===0) {
                $this->imagenes['rId1']='data:image/png;base64,'.base64_encode($logo);
            }
            $header=$zip->getFromName('word/header1.xml');
            $body=$zip->getFromName('word/document.xml');
            if (!is_string($body)) throw new RuntimeException('El DOCX no contiene documento.');
            $encabezado=is_string($header)?$this->parte($header, '/w:hdr'):'';
            $contenido=$this->parte($body, '/w:document/w:body');
        } finally { $zip->close(); }
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>' .
            '@page{margin:148pt 38pt 42pt}body{font-family:"DejaVu Sans",sans-serif;font-size:9pt;color:#16223b;line-height:1.25}' .
            'p{margin:0 0 4pt}table{width:100%;border-collapse:collapse;table-layout:fixed;margin:0 0 8pt}' .
            'td,th{vertical-align:top;padding:5pt;overflow-wrap:break-word}tr{page-break-inside:avoid}thead{display:table-header-group}' .
            'img{max-width:100%}.report-header{position:fixed;top:-116pt;left:0;right:0;height:96pt;border-bottom:2pt solid #273a8a;padding-bottom:8pt}' .
            '</style></head><body><div class="report-header">'.$encabezado.'</div>'.$contenido.'</body></html>';
    }

    private function parte(string $xml, string $raiz): string
    {
        $doc=new DOMDocument();
        if (!$doc->loadXML($xml, LIBXML_NONET)) throw new RuntimeException('XML de reporte inválido.');
        $this->xp=new DOMXPath($doc);
        $this->xp->registerNamespace('w', self::W);
        $this->xp->registerNamespace('a','http://schemas.openxmlformats.org/drawingml/2006/main');
        $this->xp->registerNamespace('r','http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $this->xp->registerNamespace('wp','http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');
        $node=$this->xp->query($raiz)->item(0);
        if (!$node) throw new RuntimeException('Estructura de reporte inválida.');
        return $this->hijos($node);
    }

    private function valor(string $path, DOMNode $node, string $attr='val'): string
    {
        $el=$this->xp->query($path,$node)->item(0);
        return $el instanceof DOMElement?$el->getAttributeNS(self::W,$attr):'';
    }

    private static function e(string $s): string {return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function color(string $s): string {return preg_match('/^[a-f0-9]{6}$/iD',$s)?'#'.$s:'';}

    private function hijos(DOMNode $node): string
    {
        $out='';
        foreach ($node->childNodes as $child) {
            if ($child->namespaceURI!==self::W) continue;
            if ($child->localName==='p') $out.=$this->parrafo($child);
            elseif ($child->localName==='tbl') $out.=$this->tabla($child);
        }
        return $out;
    }

    private function parrafo(DOMNode $p): string
    {
        $align=$this->valor('./w:pPr/w:jc',$p);$style='';
        if ($this->xp->query('./w:pPr/w:keepNext',$p)->length) $style.='page-break-after:avoid;';
        if (in_array($align,['left','right','center','justify'],true)) $style.='text-align:'.$align.';';
        $after=$this->valor('./w:pPr/w:spacing',$p,'after');
        if (ctype_digit($after)) $style.='margin-bottom:'.min(18,(int)$after/20).'pt;';
        $out='';
        foreach ($this->xp->query('./w:r|./w:hyperlink/w:r',$p) as $r) {
            $text='';
            foreach ($r->childNodes as $n) {
                if ($n->localName==='t') $text.=self::e($n->textContent);
                elseif ($n->localName==='br') $text.='<br>';
                elseif ($n->localName==='tab') $text.=' ';
            }
            $rStyle='';$size=$this->valor('./w:rPr/w:sz',$r);
            if (ctype_digit($size)) $rStyle.='font-size:'.max(6,min(24,(int)$size/2)).'pt;';
            $color=self::color($this->valor('./w:rPr/w:color',$r));
            if ($color!=='') $rStyle.='color:'.$color.';';
            if ($this->xp->query('./w:rPr/w:b',$r)->length && $this->valor('./w:rPr/w:b',$r)!=='0') $rStyle.='font-weight:bold;';
            $out.='<span style="'.$rStyle.'">'.$text.'</span>';
            foreach ($this->xp->query('.//a:blip',$r) as $blip) {
                $id=$blip->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','embed');
                if (isset($this->imagenes[$id])) $out.='<img alt="Identidad institucional" width="98" src="'.$this->imagenes[$id].'">';
            }
        }
        return '<p style="'.$style.'">'.($out?:'&#160;').'</p>';
    }

    private function tabla(DOMNode $tbl): string
    {
        $widths=[];
        foreach ($this->xp->query('./w:tblGrid/w:gridCol',$tbl) as $col) $widths[]=(int)$col->getAttributeNS(self::W,'w');
        $sum=array_sum($widths);$out='<table><colgroup>';
        foreach ($widths as $width) $out.='<col style="width:'.($sum?round(100*$width/$sum,2):0).'%">';
        $out.='</colgroup>';$head='';$body='';
        foreach ($this->xp->query('./w:tr',$tbl) as $tr) {
            $header=$this->xp->query('./w:trPr/w:tblHeader',$tr)->length>0;
            $row='<tr>';
            foreach ($this->xp->query('./w:tc',$tr) as $tc) {
                $fill=self::color($this->valor('./w:tcPr/w:shd',$tc,'fill'));
                $style=$fill!==''?'background-color:'.$fill.';':'';
                $cellWidth=$this->valor('./w:tcPr/w:tcW',$tc,'w');
                if ($sum>0 && ctype_digit($cellWidth)) $style.='width:'.round(100*(int)$cellWidth/$sum,2).'%;';
                if ($this->xp->query('./w:tcPr/w:tcBorders/*',$tc)->length) $style.='border:0.5pt solid #e5e9ef;';
                $span=$this->valor('./w:tcPr/w:gridSpan',$tc);
                $tag=$header?'th':'td';
                $row.='<'.$tag.' style="'.$style.'"'.(ctype_digit($span)?' colspan="'.(int)$span.'"':'').'>'.$this->hijos($tc).'</'.$tag.'>';
            }
            $row.='</tr>';
            if ($header) $head.=$row;else $body.=$row;
        }
        return $out.($head!==''?'<thead>'.$head.'</thead>':'').'<tbody>'.$body.'</tbody></table>';
    }
}
