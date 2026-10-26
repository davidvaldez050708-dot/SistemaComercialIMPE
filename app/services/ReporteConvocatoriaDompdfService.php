<?php

use Dompdf\Dompdf;
use Dompdf\Options;

/** Renderizador privado del reporte de convocatorias; no configura otros PDF. */
class ReporteConvocatoriaDompdfService
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private $xpath;

    public function generar(array $elementos, array $datos, DOMDocument $plantilla, string $logo): array
    {
        if (!class_exists(Dompdf::class) || !class_exists(Options::class)) {
            throw new RuntimeException('Dompdf no está disponible.');
        }
        $temp = sys_get_temp_dir() . '/impe-convocatorias-pdf-' . bin2hex(random_bytes(8));
        if (!mkdir($temp, 0700)) {
            throw new RuntimeException('No hay directorio temporal de escritura.');
        }
        try {
            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('isJavascriptEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('tempDir', $temp);
            $options->set('fontCache', $temp);
            // Sólo se usan el PNG embebido y las fuentes que ya trae Dompdf.
            $options->set('chroot', $temp);
            $pdf = new Dompdf($options);
            $this->xpath = new DOMXPath($elementos[0]->ownerDocument);
            $this->xpath->registerNamespace('w', self::W);
            $html = '';
            foreach ($elementos as $elemento) {
                $html .= $this->nodo($elemento);
            }
            $p = new DOMXPath($plantilla);
            $p->registerNamespace('w', self::W);
            $size = $p->query('//w:sectPr/w:pgSz')->item(0);
            $margins = $p->query('//w:sectPr/w:pgMar')->item(0);
            $width = $size ? (int)$size->getAttributeNS(self::W, 'w') / 20 : 595.45;
            $height = $size ? (int)$size->getAttributeNS(self::W, 'h') / 20 : 841.7;
            $left = $margins ? (int)$margins->getAttributeNS(self::W, 'left') / 20 : 72;
            $right = $margins ? (int)$margins->getAttributeNS(self::W, 'right') / 20 : 72;
            if ($width < 400 || $height < 500 || $width - $left - $right < 300) {
                throw new RuntimeException('Dimensiones de plantilla no admitidas.');
            }
            $entries = [
                ['Sistema de Gestión Comercial', 6.5, true, [0.15, 0.23, 0.54]],
                ['Reporte de Convocatorias', 10.5, true, [0.09, 0.13, 0.23]],
                ['Publicaciones, vigencias, cobertura territorial y alertas', 7, false, [0.43, 0.45, 0.50]]
            ];
            foreach (['generado_por' => 'Generado por', 'generado_por_rol' => 'Rol', 'fecha_generacion' => 'Fecha'] as $key => $label) {
                $value = trim((string)($datos[$key] ?? ''));
                if ($value !== '') $entries[] = [$label . ': ' . $value, 6, false, [0.43, 0.45, 0.50]];
            }
            $logoSize = getimagesizefromstring($logo);
            if (!$logoSize || $logoSize[2] !== IMAGETYPE_PNG) throw new RuntimeException('Logotipo PNG inválido.');
            $logoWidth = min(88, 85 * $logoSize[0] / $logoSize[1]);
            $logoHeight = $logoWidth * $logoSize[1] / $logoSize[0];
            $logoFile = $temp . '/logo.png';
            if (file_put_contents($logoFile, $logo) !== strlen($logo)) throw new RuntimeException('No se pudo preparar el logotipo.');
            $css = '@page{margin:145pt ' . $right . 'pt 55pt ' . $left . 'pt}' .
                'body{font-family:Arial,"DejaVu Sans",sans-serif;font-size:8pt;color:#16223B;line-height:1.15}' .
                'table.datos{width:100%;table-layout:fixed;border-collapse:collapse;margin:0}' .
                'table.datos td{vertical-align:middle;word-wrap:break-word}thead{display:table-header-group}' .
                'thead tr{page-break-after:avoid}tbody tr:first-child{page-break-before:avoid}' .
                'tr{page-break-inside:avoid}p{margin:0;word-wrap:break-word}.bloque{page-break-inside:avoid}';
            $pdf->setPaper([0, 0, $width, $height]);
            $pdf->loadHtml('<!doctype html><html lang="es"><head><meta charset="utf-8"><style>' . $css . '</style></head><body>' . $html . '</body></html>', 'UTF-8');
            $pdf->render();
            $pdf->getCanvas()->page_script(static function ($page, $pages, $canvas, $metrics) use ($left, $right, $entries, $logoFile, $logoWidth, $logoHeight) {
                $canvas->image($logoFile, $left, 27, $logoWidth, $logoHeight);
                $canvas->line($left, 132, $canvas->get_width() - $right, 132, [0.15, 0.23, 0.54], 2);
                $headerY = 31;
                $maxWidth = ($canvas->get_width() - $left - $right) * .65;
                foreach ($entries as [$entry, $size, $bold, $color]) {
                    $headerFont = $metrics->getFont('DejaVu Sans', $bold ? 'bold' : 'normal');
                    $line = '';
                    foreach (preg_split('/\s+/u', $entry) as $word) {
                        $next = $line === '' ? $word : $line . ' ' . $word;
                        if ($line !== '' && $metrics->getTextWidth($next, $headerFont, $size) > $maxWidth) {
                            $canvas->text($canvas->get_width() - $right - $metrics->getTextWidth($line, $headerFont, $size), $headerY, $line, $headerFont, $size, $color);
                            $headerY += $size * 1.4;$line = $word;
                        } else $line = $next;
                    }
                    if ($headerY + $size > 128) throw new RuntimeException('Metadatos demasiado extensos para la cabecera.');
                    $canvas->text($canvas->get_width() - $right - $metrics->getTextWidth($line, $headerFont, $size), $headerY, $line, $headerFont, $size, $color);
                    $headerY += $size * 1.4;
                }
                $font = $metrics->getFont('DejaVu Sans', 'normal');
                $y = $canvas->get_height() - 32;
                $canvas->line($left, $y - 6, $canvas->get_width() - $right, $y - 6, [0.90, 0.91, 0.94], .5);
                $canvas->text($left, $y, 'Grupo Porcayo · Sistema de Gestión Comercial', $font, 6.5, [0.15, 0.23, 0.54]);
                $text = 'Página ' . $page . ' de ' . $pages;
                $canvas->text($canvas->get_width() - $right - $metrics->getTextWidth($text, $font, 6.5), $y, $text, $font, 6.5, [0.43, 0.45, 0.50]);
            });
            $bytes = $pdf->output();
            if (strlen($bytes) < 100 || strncmp($bytes, '%PDF-', 5) !== 0) {
                throw new RuntimeException('Salida PDF vacía o inválida.');
            }
            return ['ok' => true, 'contenido_pdf' => $bytes, 'conversor' => 'DOMPDF_PHP'];
        } finally {
            // Sólo temporales propios; no se escribe en plantillas ni en vendor.
            foreach (glob($temp . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($temp);
        }
    }

    private function valor(DOMNode $node, string $path, string $attribute = 'val', string $default = ''): string
    {
        $element = $this->xpath->query($path, $node)->item(0);
        return $element instanceof DOMElement ? $element->getAttributeNS(self::W, $attribute) : $default;
    }

    private function nodo(DOMNode $node): string
    {
        if ($node->localName === 'p') {
            $style = 'margin-top:' . ((float)$this->valor($node, './w:pPr/w:spacing', 'before') / 20) . 'pt;margin-bottom:' . ((float)$this->valor($node, './w:pPr/w:spacing', 'after') / 20) . 'pt;';
            $align = $this->valor($node, './w:pPr/w:jc');
            if (in_array($align, ['left', 'right', 'center'], true)) {
                $style .= 'text-align:' . $align . ';';
            }
            if ($this->xpath->query('./w:pPr/w:keepNext', $node)->length) {
                $style .= 'page-break-after:avoid;';
            }
            if ($this->xpath->query('./w:pPr/w:pBdr/w:left', $node)->length) {
                $style .= 'border-left:2.25pt solid #273A8A;padding-left:8pt;';
            }
            $text = '';
            foreach ($this->xpath->query('./w:r', $node) as $run) {
                $size = (float)$this->valor($run, './w:rPr/w:sz');
                $color = $this->valor($run, './w:rPr/w:color');
                $runStyle = $size > 0 ? 'font-size:' . ($size / 2) . 'pt;' : '';
                if (preg_match('/^[a-f0-9]{6}$/i', $color)) {
                    $runStyle .= 'color:#' . $color . ';';
                }
                if ($this->xpath->query('./w:rPr/w:b', $run)->length) {
                    $runStyle .= 'font-weight:bold;';
                }
                foreach ($this->xpath->query('./w:t', $run) as $t) {
                    $text .= '<span style="' . $runStyle . '">' . nl2br($this->e($t->textContent)) . '</span>';
                }
            }
            return '<p style="' . $style . '">' . ($text !== '' ? $text : '&#160;') . '</p>';
        }
        if ($node->localName !== 'tbl') {
            throw new RuntimeException('Elemento no admitido en el reporte de convocatorias.');
        }
        $bordered = $this->xpath->query('./w:tblPr/w:tblBorders', $node)->length > 0;
        $rows = $this->xpath->query('./w:tr', $node);
        $header = '';
        $body = '';
        $total = max(1, (int)$this->valor($node, './w:tblPr/w:tblW', 'w'));
        foreach ($rows as $row) {
            $rowHeight = (float)$this->valor($row, './w:trPr/w:trHeight') / 20;
            $cells = '';
            foreach ($this->xpath->query('./w:tc', $row) as $cell) {
                $span = max(1, (int)$this->valor($cell, './w:tcPr/w:gridSpan'));
                $width = (int)$this->valor($cell, './w:tcPr/w:tcW', 'w') / $total * 100;
                $color = $this->valor($cell, './w:tcPr/w:shd', 'fill');
                $style = 'width:' . $width . '%;border:' . ($bordered ? '.25pt solid #E5E9EF' : '0') . ';';
                $style .= $rowHeight > 0 ? 'padding:0;height:' . $rowHeight . 'pt;line-height:1pt;font-size:1pt;' : 'padding:3pt 4pt;';
                if (preg_match('/^[a-f0-9]{6}$/i', $color)) {
                    $style .= 'background-color:#' . $color . ';';
                }
                $contents = '';
                // Filas de la gráfica: sólo sombreado, sin altura mínima del texto.
                if (!$rowHeight) {
                    foreach ($this->xpath->query('./w:p', $cell) as $paragraph) {
                        $contents .= $this->nodo($paragraph);
                    }
                }
                $cells .= '<td colspan="' . $span . '" style="' . $style . '">' . $contents . '</td>';
            }
            $html = '<tr>' . $cells . '</tr>';
            if ($this->xpath->query('./w:trPr/w:tblHeader', $row)->length) {
                $header .= $html;
            } else {
                $body .= $html;
            }
        }
        return '<table class="datos' . ($header === '' ? ' bloque' : '') . '">' . ($header !== '' ? '<thead>' . $header . '</thead>' : '') . '<tbody>' . $body . '</tbody></table>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
