<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * Perfil local REDMEX. Lee siempre el DOCX preparado, nunca texto de la BD.
 * No es un conversor Word universal: falla antes de renderizar si pierde información.
 */
final class OficioRedmexPdfRenderer
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const NS = [
        'w' => self::W,
        'wp' => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
        'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
        'pic' => 'http://schemas.openxmlformats.org/drawingml/2006/picture',
        'wpg' => 'http://schemas.microsoft.com/office/word/2010/wordprocessingGroup',
        'wps' => 'http://schemas.microsoft.com/office/word/2010/wordprocessingShape',
        'mc' => 'http://schemas.openxmlformats.org/markup-compatibility/2006',
        'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
    ];
    private $zip;
    private $xp;
    private $rels;
    private $styles = [];
    private $defaults;
    private $drawings = [];
    private $fonts = [];
    private $needed = [];
    private $texts = [];
    private $fontNamespace;

    public function convertir(string $docx, string $directory): array
    {
        set_error_handler(static function ($severity) {
            if (!(error_reporting() & $severity)) { return true; }
            throw new RuntimeException('REDMEX_PHP_WARNING');
        }, E_WARNING | E_NOTICE);
        try {
            // FontMetrics conserva una caché estática por familia en el proceso.
            // Separar nombres evita reutilizar rutas temporales de otra conversión.
            $this->fontNamespace = 'redmex_' . bin2hex(random_bytes(8)) . '_';
            $reference = dirname(__DIR__, 2) . '/storage/templates/oficio_general_redmex.docx';
            $profile = new self();
            $profile->open($reference);
            try {
                $expected = $profile->graphics();
                $fontSources = $profile->fontSources();
            } finally { $profile->close(); }
            $this->needed = [];
            $this->texts = [];
            $this->drawings = [];
            $this->open($docx);
            try {
                $actual = $this->graphics();
                $fingerprint = static function ($graphics) {
                    return array_map(static function ($g) {
                        foreach ($g['images'] as &$image) {
                            $image['data'] = hash('sha256', $image['data']);
                        }
                        return $g;
                    }, $graphics);
                };
                $this->ensure($fingerprint($actual) === $fingerprint($expected), 'GRAPHICS_PROFILE');
                // Cada documento conserva sus imágenes. El perfil sólo comprueba compatibilidad.
                $this->drawings = $actual;
                $selectedFonts = $this->fontSources();
                foreach ($selectedFonts as $key => $data) {
                    $this->ensure(isset($fontSources[$key]) && hash_equals(hash('sha256',$fontSources[$key]),hash('sha256',$data)), 'FONT_PROFILE');
                }
                $this->fonts = $selectedFonts + $fontSources;
                $html = $this->html();
                $options = new \Dompdf\Options();
                $options->setChroot($directory);
                $options->setTempDir($directory);
                $options->setFontDir($directory);
                $options->setFontCache($directory);
                $options->setIsRemoteEnabled(false);
                $options->setIsPhpEnabled(false);
                $options->setIsJavascriptEnabled(false);
                $options->setIsFontSubsettingEnabled(true);
                $options->setFontHeightRatio(1.0);
                $pdf = new \Dompdf\Dompdf($options);
                foreach ($this->needed as $key => $style) {
                    $this->ensure(isset($this->fonts[$key]), 'FONT_MISSING');
                    $data = $this->fonts[$key];
                    $path = $directory . '/font_' . hash('sha256', $data) . '.ttf';
                    $this->ensure(file_put_contents($path, $data) === strlen($data), 'FONT_WRITE');
                    $font = \FontLib\Font::load($path);
                    $this->ensure($font !== null, 'FONT_READ');
                    try {
                        $font->parse();
                        $rights = (int)$font->getData('OS/2','fsType');
                        $this->ensure(($rights & 0x0202) === 0, 'FONT_EMBEDDING');
                        if ($rights & 0x0100) { $options->setIsFontSubsettingEnabled(false); }
                        $map = $font->getUnicodeCharMap();
                        foreach (mb_str_split(implode('', $this->texts[$key] ?? [])) as $char) {
                            $cp = mb_ord($char,'UTF-8');
                            $this->ensure($cp <= 32 || isset($map[$cp]) && $map[$cp] !== 0, 'FONT_GLYPH');
                        }
                    } finally { $font->close(); }
                    $this->ensure($pdf->getFontMetrics()->registerFont($style, $path), 'FONT_REGISTER');
                }
                $pdf->setCallbacks([['event'=>'end_frame', 'f'=>function ($frame) {
                    $node=$frame->get_node();
                    if ($node instanceof DOMElement && $node->hasAttribute('data-redmex-seal')) {
                        [$x,$y,$w,$h]=$frame->get_border_box();
                        $this->ensure($x>=0 && $x+$w<=612.5 && $y>=17 && $y+$h<=762.5,'SEAL_OVERFLOW');
                    }
                    if ($frame->get_node()->nodeType !== XML_TEXT_NODE || preg_match('/^\s*$/u',$frame->get_node()->textContent)) { return; }
                    [$x,$y,$w,$h] = $frame->get_border_box();
                    $this->ensure($x>=53.5 && $x+$w<=594.5 && $y>=12 && $y+$h<=762.5, 'CONTENT_OVERFLOW');
                }]]);
                $pdf->setPaper('letter', 'portrait');
                $pdf->loadHtml($html, 'UTF-8');
                $warnings = count($GLOBALS['_dompdf_warnings'] ?? []);
                $pdf->render();
                $this->ensure(count($GLOBALS['_dompdf_warnings'] ?? []) === $warnings, 'RENDER_WARNING');
                $bytes = $pdf->output();
                $this->ensure(strlen($bytes) > 1000 && str_starts_with($bytes, '%PDF-')
                    && preg_match('/%%EOF\s*$/D', substr($bytes, -1024)), 'PDF_INVALID');
                $path = $directory . '/oficio_redmex.pdf';
                $this->ensure(file_put_contents($path, $bytes) === strlen($bytes), 'PDF_WRITE');
                return ['ok' => true, 'ruta_pdf' => $path, 'conversor' => 'REDMEX_DOMPDF'];
            } finally { $this->close(); }
        } catch (Throwable $error) {
            $code = preg_match('/^REDMEX_[A-Z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'REDMEX_RENDER';
            error_log('OFICIO_PDF ' . $code . ' ' . get_class($error));
            return ['ok' => false, 'mensaje' => 'Este DOCX no puede convertirse conservando el formato REDMEX local. Conserva el DOCX y solicita la revisión de su diseño. No se ha generado el PDF.', 'mensaje_tecnico' => $code];
        } finally { restore_error_handler(); }
    }

    private function ensure($condition, string $code): void
    {
        if (!$condition) { throw new RuntimeException('REDMEX_' . $code); }
    }

    private function xml(string $data): DOMDocument
    {
        $this->ensure(strlen($data) <= 8 * 1024 * 1024 && !preg_match('/<!DOCTYPE|<!ENTITY/i', $data), 'XML_UNSAFE');
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $ok = $doc->loadXML($data, LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $this->ensure($ok, 'XML_INVALID');
        return $doc;
    }

    private function xpath(DOMDocument $doc): DOMXPath
    {
        $xp = new DOMXPath($doc);
        foreach (self::NS as $prefix => $uri) { $xp->registerNamespace($prefix, $uri); }
        return $xp;
    }

    private function open(string $path): void
    {
        $this->zip = new ZipArchive();
        $this->ensure($this->zip->open($path, ZipArchive::RDONLY) === true, 'ZIP_OPEN');
        $total = 0;
        $this->ensure($this->zip->numFiles <= 1000, 'ZIP_LIMIT');
        $names = [];
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $s = $this->zip->statIndex($i); $name = $s['name'];
            $this->ensure(!isset($names[$name]) && !preg_match('~(^/|\\\\|(^|/)\.\.(/|$))~', $name), 'ZIP_PATH');
            $names[$name] = true; $total += $s['size'];
            $this->ensure($s['size'] <= 12 * 1024 * 1024 && $total <= 64 * 1024 * 1024, 'ZIP_LIMIT');
            if (str_ends_with($name, '.rels')) { $this->relationships($name); }
        }
        $doc = $this->xml($this->read('word/document.xml'));
        $this->xp = $this->xpath($doc);
        $this->ensure(!$this->xp->query('/w:document/*[not(self::w:body)]')->length,'DOCUMENT_BACKGROUND');
        // Elegir exactamente una representación; los fallbacks de Word duplican imágenes.
        foreach (iterator_to_array($this->xp->query('//mc:AlternateContent')) as $alt) {
            $choice = $this->xp->query('./mc:Choice', $alt)->item(0);
            $this->ensure($choice !== null, 'ALTERNATE_CONTENT');
            foreach (iterator_to_array($choice->childNodes) as $child) { $alt->parentNode->insertBefore($child, $alt); }
            $alt->parentNode->removeChild($alt);
        }
        $this->rels = $this->relationships('word/_rels/document.xml.rels');
        $this->ensure($this->xp->query('//w:sectPr')->length === 1, 'SECTIONS');
        $section = $this->xp->query('//w:sectPr')->item(0);
        $this->checkChildren($section,['w:pgSz','w:pgMar','w:pgNumType','w:cols']);
        $this->ensure(in_array($this->attr('w:cols','num',$section,'1'),['1'],true)
            && in_array($this->attr('w:pgSz','orient',$section,'portrait'),['portrait'],true),'PAGE_LAYOUT');
        $this->ensure($this->attr('w:pgSz', 'w', $section) === '12240'
            && $this->attr('w:pgSz', 'h', $section) === '15840', 'PAGE_SIZE');
        foreach (['top'=>340, 'right'=>360, 'bottom'=>0, 'left'=>1080, 'header'=>720, 'footer'=>720, 'gutter'=>0] as $key=>$value) {
            $this->ensure((int)$this->attr('w:pgMar', $key, $section) === $value, 'MARGINS');
        }
        $this->ensure(!$this->xp->query('//w:headerReference|//w:footerReference|//w:footnoteReference|//w:endnoteReference|//w:ins|//w:del|//w:object|//w:pict|//w:sdt|//w:fldSimple|//w:hyperlink')->length, 'STRUCTURE');
        $styles = $this->xpath($this->xml($this->read('word/styles.xml')));
        $this->defaults = $styles->query('//w:docDefaults/w:rPrDefault/w:rPr')->item(0);
        $this->styles = [];
        foreach ($styles->query('//w:style') as $style) { $this->styles[$style->getAttributeNS(self::W, 'styleId')] = $style; }
        $normal = $this->styles['Normal'] ?? null;
        $this->ensure($normal && !$this->one('w:pPr|w:rPr|w:basedOn',$normal), 'DEFAULT_STYLE');
        $defaultP = $styles->query('//w:docDefaults/w:pPrDefault/w:pPr')->item(0);
        if ($defaultP) { $this->checkChildren($defaultP,['w:widowControl']); }
    }

    private function close(): void
    {
        if ($this->zip instanceof ZipArchive) { $this->zip->close(); $this->zip = null; }
    }

    private function read(string $part): string
    {
        $data = $this->zip->getFromName($part);
        $this->ensure(is_string($data), 'PART_MISSING');
        return $data;
    }

    private function relationships(string $part): array
    {
        $rels = [];
        foreach ($this->xml($this->read($part))->documentElement->childNodes as $r) {
            if (!($r instanceof DOMElement)) { continue; }
            $this->ensure($r->getAttribute('TargetMode') !== 'External', 'EXTERNAL_RELATION');
            $target = $r->getAttribute('Target');
            // No extracción al disco ni resolución de rutas fuera del paquete.
            if (preg_match('~/(image|font)$~D', $r->getAttribute('Type'))) {
                $this->ensure(!preg_match('~(^/|:|\\\\|(^|/)\.\.(/|$))~', $target), 'RELATION_PATH');
            }
            $rels[$r->getAttribute('Id')] = 'word/' . $target;
        }
        return $rels;
    }

    private function one(string $path, ?DOMNode $node = null): ?DOMElement
    {
        $xpath = $node ? $this->xpath($node->ownerDocument) : $this->xp;
        $found = $xpath->query($path, $node)->item(0);
        return $found instanceof DOMElement ? $found : null;
    }

    private function attr(string $path, string $attribute, DOMNode $node, string $default = ''): string
    {
        $el = $this->one($path, $node);
        return $el && $el->hasAttributeNS(self::W, $attribute) ? $el->getAttributeNS(self::W, $attribute) : $default;
    }

    private function graphics(): array
    {
        $result = [];
        $this->ensure(!$this->xp->query('//wp:inline')->length, 'INLINE_GRAPHICS');
        foreach ($this->xp->query('//wp:anchor') as $anchor) {
            $h = $this->one('wp:positionH', $anchor); $v = $this->one('wp:positionV', $anchor);
            $extent = $this->one('wp:extent', $anchor);
            $this->ensure($h && $v && $extent && $this->one('wp:wrapNone', $anchor), 'ANCHOR');
            $this->ensure($anchor->getAttribute('hidden') !== '1' && $anchor->getAttribute('simplePos') !== '1', 'ANCHOR_FLAGS');
            $this->ensure($this->one('wp:posOffset', $h) && $this->one('wp:posOffset', $v), 'ANCHOR_ALIGN');
            $x = (float)$h->textContent / 12700; $y = (float)$v->textContent / 12700;
            $w = (float)$extent->getAttribute('cx') / 12700; $height = (float)$extent->getAttribute('cy') / 12700;
            $g = ['x'=>$x, 'y'=>$y, 'w'=>$w, 'h'=>$height, 'horizontal'=>$h->getAttribute('relativeFrom'), 'vertical'=>$v->getAttribute('relativeFrom'), 'images'=>[]];
            $graphic = $this->one('a:graphic/a:graphicData', $anchor);
            $this->ensure($graphic !== null, 'GRAPHIC_DATA');
            $group = $this->one('wpg:wgp', $graphic);
            if ($group) { $this->group($group, [0, 0, $w, $height], $g['images']); }
            else {
                $pic = $this->one('pic:pic', $graphic); $this->ensure($pic !== null, 'GRAPHIC_TYPE');
                $this->picture($pic, [0, 0, $w, $height], $g['images']);
            }
            $result[] = $g;
        }
        $this->ensure(count($result) === 3, 'GRAPHICS_COUNT');
        return $result;
    }

    private function group(DOMElement $group, array $box, array &$images): void
    {
        $xf = $this->one('wpg:grpSpPr/a:xfrm', $group);
        $properties=$this->one('wpg:grpSpPr',$group);
        if ($properties) { $this->checkChildren($properties,['a:xfrm']); }
        $this->ensure($xf && !$xf->hasAttribute('rot') && !$xf->hasAttribute('flipH') && !$xf->hasAttribute('flipV'), 'GROUP_TRANSFORM');
        $off = $this->one('a:chOff', $xf); $ext = $this->one('a:chExt', $xf);
        $this->ensure($off && $ext && (float)$ext->getAttribute('cx') > 0 && (float)$ext->getAttribute('cy') > 0, 'GROUP_COORDS');
        [$bx,$by,$bw,$bh] = $box;
        $sx = $bw / (float)$ext->getAttribute('cx'); $sy = $bh / (float)$ext->getAttribute('cy');
        foreach ($group->childNodes as $node) {
            if (!($node instanceof DOMElement)) { continue; }
            if ($node->namespaceURI === self::NS['wpg'] && in_array($node->localName, ['cNvGrpSpPr','cNvPr','grpSpPr'], true)) { continue; }
            if ($node->localName === 'wsp' && $node->namespaceURI === self::NS['wps']) {
                $this->ensure(!$this->xp->query('.//w:t|.//a:t', $node)->length && $this->one('wps:spPr/a:noFill', $node)
                    && $this->one('wps:spPr/a:ln/a:noFill', $node), 'SHAPE_CONTENT');
                $this->checkChildren($this->one('wps:spPr',$node),['a:xfrm','a:prstGeom','a:noFill','a:ln']);
                continue; // Rectángulos transparentes vacíos de agrupación, sin contenido visible.
            }
            $transform = $this->one($node->localName === 'pic' ? 'pic:spPr/a:xfrm' : 'wpg:grpSpPr/a:xfrm', $node);
            $o = $transform ? $this->one('a:off', $transform) : null;
            $e = $transform ? $this->one('a:ext', $transform) : null;
            $this->ensure($o && $e, 'SHAPE_TRANSFORM');
            $childBox = [$bx + ((float)$o->getAttribute('x') - (float)$off->getAttribute('x'))*$sx,
                $by + ((float)$o->getAttribute('y') - (float)$off->getAttribute('y'))*$sy,
                (float)$e->getAttribute('cx')*$sx, (float)$e->getAttribute('cy')*$sy];
            if ($node->namespaceURI === self::NS['wpg'] && $node->localName === 'grpSp') { $this->group($node, $childBox, $images); }
            elseif ($node->namespaceURI === self::NS['pic'] && $node->localName === 'pic') { $this->picture($node, $childBox, $images); }
            else { $this->ensure(false, 'SHAPE_TYPE'); }
        }
    }

    private function picture(DOMElement $pic, array $box, array &$images): void
    {
        $sp = $this->one('pic:spPr',$pic); $fill = $this->one('pic:blipFill',$pic);
        $this->ensure($sp && $fill, 'PICTURE_PROPERTIES');
        $this->checkChildren($sp,['a:xfrm','a:prstGeom','a:noFill','a:ln']);
        $this->checkChildren($fill,['a:blip','a:srcRect','a:stretch']);
        $geom=$this->one('a:prstGeom',$sp);
        $this->ensure($geom && $geom->getAttribute('prst')==='rect', 'PICTURE_GEOMETRY');
        $line=$this->one('a:ln',$sp);
        if ($line) { $this->checkChildren($line,['a:noFill']); }
        $xf = $this->one('pic:spPr/a:xfrm', $pic);
        $this->ensure($xf && !$xf->hasAttribute('rot') && !$xf->hasAttribute('flipH') && !$xf->hasAttribute('flipV'), 'PICTURE_TRANSFORM');
        $crop = $this->one('pic:blipFill/a:srcRect', $pic);
        $this->ensure(!$crop || !$crop->hasAttributes(), 'PICTURE_CROP');
        $blip = $this->one('pic:blipFill/a:blip', $pic);
        $this->ensure($blip !== null, 'PICTURE_BLIP');
        $this->checkChildren($blip,['a:alphaModFix']);
        $alpha=$this->one('a:alphaModFix',$blip);
        $this->ensure(!$alpha || !$alpha->hasAttributes(), 'PICTURE_ALPHA');
        $id = $blip ? $blip->getAttributeNS(self::NS['r'], 'embed') : '';
        $this->ensure(isset($this->rels[$id]), 'PICTURE_RELATION');
        $data = $this->read($this->rels[$id]);
        $size = @getimagesizefromstring($data);
        $this->ensure($size && in_array($size['mime'], ['image/png','image/jpeg'], true), 'PICTURE_FORMAT');
        $images[] = ['box'=>$box, 'mime'=>$size['mime'], 'data'=>$data];
    }

    private function fontSources(): array
    {
        $result = [];
        $fonts = $this->xpath($this->xml($this->read('word/fontTable.xml')));
        $rels = $this->zip->locateName('word/_rels/fontTable.xml.rels') === false ? [] : $this->relationships('word/_rels/fontTable.xml.rels');
        foreach ($fonts->query('//w:font') as $font) {
            foreach (['embedRegular'=>'normal', 'embedBold'=>'bold', 'embedItalic'=>'italic', 'embedBoldItalic'=>'bold_italic'] as $tag=>$variant) {
                $el = $fonts->query('w:' . $tag, $font)->item(0);
                if (!$el) { continue; }
                $id = $el->getAttributeNS(self::NS['r'], 'id');
                $guid = str_replace(['{','}','-'], '', $el->getAttributeNS(self::W, 'fontKey'));
                $this->ensure(isset($rels[$id]) && preg_match('/^[a-fA-F0-9]{32}$/D', $guid), 'FONT_KEY');
                $data = $this->read($rels[$id]); $key = strrev(hex2bin($guid));
                $this->ensure(strlen($data)>=32, 'FONT_LENGTH');
                for ($i=0; $i<32; $i++) { $data[$i] = $data[$i] ^ $key[$i%16]; }
                $this->ensure(substr($data,0,4) === "\x00\x01\x00\x00", 'FONT_TYPE');
                $result[$font->getAttributeNS(self::W,'name') . '/' . $variant] = $data;
            }
        }
        return $result;
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    private function imageHtml(array $g, bool $seal = false): string
    {
        $html = '';
        foreach ($g['images'] as $img) {
            [$x,$y,$w,$h] = $img['box'];
            $html .= '<img '.($seal?'data-redmex-seal="1" ':'').'style="position:absolute;left:'.$x.'pt;top:'.$y.'pt;width:'.$w.'pt;height:'.$h.'pt" src="data:'.$img['mime'].';base64,'.base64_encode($img['data']).'">';
        }
        return $html;
    }

    private function html(): string
    {
        $this->ensure(count($this->drawings) === 3, 'PROFILE');
        $fixed = ''; $index=0; $body=''; $closing=false;
        foreach ($this->xp->query('/w:document/w:body/*') as $el) {
            if ($el->localName === 'sectPr') { continue; }
            $hasSeal = false;
            foreach ($this->xp->query('.//wp:anchor', $el) as $anchor) {
                $g = $this->drawings[$index++];
                if ($g['vertical'] === 'page') {
                    $fixed .= '<div style="position:fixed;left:'.($g['x']-54).'pt;top:'.($g['y']-100).'pt">'.$this->imageHtml($g).'</div>';
                } else { $hasSeal=true; }
            }
            if ($hasSeal) { $body .= '<div class="closing">'; $closing=true; }
            if ($el->localName === 'p') {
                $body .= $this->paragraph($el, $hasSeal ? $this->drawings[2] : null);
            } elseif ($el->localName === 'tbl') { $body .= $this->table($el); }
            else { $this->ensure(false, 'BODY_ELEMENT'); }
        }
        if ($closing) { $body .= '</div>'; }
        // El margen inferior reserva el pie; nunca recorta ni reduce el texto para encajarlo.
        return '<!doctype html><html><head><meta charset="utf-8"><style>@page{size:letter portrait;margin:100pt 18pt 30pt 54pt}body{margin:0;padding:0}.first-flow{margin-top:-83pt}p{margin:0;position:relative} .closing{page-break-inside:avoid}table{border-collapse:collapse;table-layout:fixed;width:100%}td{vertical-align:top;padding:0}img{border:0}</style></head><body>'.$fixed.'<div class="first-flow">'.$body.'</div></body></html>';
    }

    private function checkChildren(DOMNode $node, array $allowed): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) { $this->ensure(in_array($child->nodeName, $allowed, true), 'UNSUPPORTED_ELEMENT'); }
        }
    }

    private function paragraph(DOMElement $p, ?array $seal = null): string
    {
        $this->checkChildren($p, ['w:pPr','w:r','w:bookmarkStart','w:bookmarkEnd','w:proofErr','w:commentRangeStart','w:commentRangeEnd']);
        $pr = $this->one('w:pPr',$p);
        if ($pr) { $this->checkChildren($pr,['w:pBdr','w:rPr','w:spacing','w:ind','w:jc','w:widowControl','w:keepNext','w:keepLines','w:pageBreakBefore','w:pStyle']); }
        $styleId = $this->attr('w:pPr/w:pStyle','val',$p,'Normal');
        $this->ensure($styleId === 'Normal', 'PARAGRAPH_STYLE');
        foreach ($this->xp->query('w:pPr/w:pBdr/*',$p) as $border) {
            $this->ensure(in_array($border->getAttributeNS(self::W,'val'),['nil','none'],true),'PARAGRAPH_BORDER');
        }
        foreach (['spacing'=>['before','after','line','lineRule'], 'ind'=>['left','right','firstLine','hanging']] as $tag=>$allowed) {
            $el=$this->one('w:pPr/w:'.$tag,$p);
            if ($el) { foreach ($el->attributes as $a) { $this->ensure(in_array($a->localName,$allowed,true), 'PARAGRAPH_PROPERTY'); } }
        }
        $align = $this->attr('w:pPr/w:jc','val',$p,'left');
        $this->ensure(in_array($align,['left','right','center','both'],true),'ALIGNMENT');
        $left = (float)$this->attr('w:pPr/w:ind','left',$p,'0')/20;
        $right = (float)$this->attr('w:pPr/w:ind','right',$p,'0')/20;
        $before = (float)$this->attr('w:pPr/w:spacing','before',$p,'0')/20;
        $after = (float)$this->attr('w:pPr/w:spacing','after',$p,'0')/20;
        $indent = ((float)$this->attr('w:pPr/w:ind','firstLine',$p,'0')-(float)$this->attr('w:pPr/w:ind','hanging',$p,'0'))/20;
        $rule = $this->attr('w:pPr/w:spacing','lineRule',$p,'auto');
        $line = (float)$this->attr('w:pPr/w:spacing','line',$p,'240');
        $this->ensure(in_array($rule,['auto','exact','atLeast'],true) && $left>=0 && $right>=0 && $left+$right<540, 'PARAGRAPH_METRICS');
        $text = ''; $font = $this->fontStyle($this->defaults);
        foreach ($p->childNodes as $run) {
            if (!($run instanceof DOMElement) || $run->nodeName !== 'w:r') { continue; }
            $this->checkChildren($run,['w:rPr','w:t','w:br','w:cr','w:tab','w:drawing','w:commentReference','w:lastRenderedPageBreak']);
            $rp = $this->one('w:rPr', $run); $f=$this->fontStyle($rp, $font);
            $value='';
            foreach ($run->childNodes as $n) {
                if ($n->nodeName === 'w:t') {
                    $value .= $this->e($n->textContent);
                    $variant=($f['weight']==='bold'?'bold':'').($f['style']==='italic'?($f['weight']==='bold'?'_italic':'italic'):'');
                    $this->texts[$f['family'].'/'.($variant?:'normal')][]=$n->textContent;
                }
                elseif ($n->nodeName === 'w:br' || $n->nodeName === 'w:cr') {
                    $this->ensure(!$n->hasAttributeNS(self::W,'type') || $n->getAttributeNS(self::W,'type')==='textWrapping', 'PAGE_BREAK'); $value.='<br>';
                } elseif ($n->nodeName === 'w:tab') { $this->ensure(false, 'TAB_LAYOUT'); }
            }
            if ($value!=='') { $text.='<span style="white-space:pre-wrap;'.$this->fontCss($f).'">'.$value.'</span>'; }
        }
        $empty = $text==='';
        if ($empty) { $font=$this->fontStyle($this->one('w:pPr/w:rPr',$p),$font); }
        // Dompdf multiplica line-height por las métricas reales de la fuente.
        // Evitar multiplicarlas dos veces (provocaría páginas adicionales).
        $factor = $font['family']==='Times New Roman' ? 1.04 : 1.0;
        $lineHeight=$rule==='auto' ? $font['size']*$factor*$line/240 : $line/20;
        $this->ensure($lineHeight >= $font['size'] && $lineHeight<=100, 'LINE_HEIGHT');
        $css=$this->fontCss($font).';line-height:'.$lineHeight.'pt;margin-left:'.$left.'pt;margin-right:'.$right.'pt;padding-top:'.$before.'pt;margin-bottom:'.$after.'pt;text-indent:'.$indent.'pt;text-align:'.($align==='both'?'justify':$align);
        if ($empty) { $text='&#160;'; }
        foreach (['pageBreakBefore'=>'page-break-before:always','keepNext'=>'page-break-after:avoid','keepLines'=>'page-break-inside:avoid'] as $tag=>$property) {
            $el=$this->one('w:pPr/w:'.$tag,$p);
            if ($el && !in_array($el->getAttributeNS(self::W,'val'),['0','false','off'],true)) { $css.=';'.$property; }
        }
        if ($seal) { $text.='<span style="position:absolute;left:'.$seal['x'].'pt;top:'.$seal['y'].'pt">'.$this->imageHtml($seal,true).'</span>'; }
        return '<p style="'.$css.'">'.$text.'</p>';
    }

    private function fontStyle(?DOMElement $pr, ?array $base = null): array
    {
        $f=$base ?? ['family'=>'Cambria','size'=>11.0,'weight'=>'normal','style'=>'normal','color'=>'000000','underline'=>false];
        if (!$pr) { return $f; }
        $this->checkChildren($pr,['w:rFonts','w:b','w:bCs','w:i','w:iCs','w:sz','w:szCs','w:color','w:noProof','w:lang','w:u']);
        $f['family']=$this->attr('w:rFonts','ascii',$pr,$f['family']);
        $rf=$this->one('w:rFonts',$pr);
        if ($rf) {
            foreach ($rf->attributes as $a) { $this->ensure(in_array($a->localName,['ascii','hAnsi','eastAsia','cs'],true),'FONT_ATTRIBUTE'); }
            $this->ensure($this->attr('w:rFonts','hAnsi',$pr,$f['family'])===$f['family'],'FONT_SCRIPT');
        }
        $f['size']=(float)$this->attr('w:sz','val',$pr,(string)($f['size']*2))/2;
        foreach (['b'=>'weight','i'=>'style'] as $tag=>$prop) {
            $el=$this->one('w:'.$tag,$pr);
            if ($el) { $f[$prop]=in_array($el->getAttributeNS(self::W,'val'),['0','false','off'],true) ? 'normal' : ($tag==='b'?'bold':'italic'); }
        }
        $f['color']=$this->attr('w:color','val',$pr,$f['color']);
        if ($this->one('w:u',$pr)) {
            $underline=$this->attr('w:u','val',$pr,'single');
            $this->ensure(in_array($underline,['none','single'],true),'UNDERLINE');
            $f['underline']=$underline==='single';
        }
        $this->ensure(in_array($f['family'],['Cambria','Times New Roman','Arial','Georgia','Calibri'],true)
            && $f['size']>=6 && $f['size']<=36 && preg_match('/^[a-fA-F0-9]{6}$/D',$f['color']), 'FONT_STYLE');
        return $f;
    }

    private function fontCss(array $f): string
    {
        $variant=($f['weight']==='bold'?'bold':'').($f['style']==='italic'?($f['weight']==='bold'?'_italic':'italic'):'');
        $family=$this->fontNamespace.str_replace(' ','_',$f['family']);
        $this->needed[$f['family'].'/'.($variant?:'normal')]=['family'=>$family,'weight'=>$f['weight'],'style'=>$f['style']];
        return 'font-family:'.$family.';font-size:'.$f['size'].'pt;font-weight:'.$f['weight'].';font-style:'.$f['style'].';color:#'.$f['color'].';text-decoration:'.($f['underline']?'underline':'none');
    }

    private function table(DOMElement $table): string
    {
        $this->checkChildren($table,['w:tblPr','w:tblGrid','w:tr']);
        $this->ensure(!$this->xp->query('.//w:tbl//w:tbl|.//w:vMerge|.//w:gridSpan|.//w:tblStyle|.//w:tblpPr|.//wp:anchor',$table)->length,'TABLE_LAYOUT');
        $pr=$this->one('w:tblPr',$table);
        if ($pr) { $this->checkChildren($pr,['w:tblW','w:tblLayout','w:tblCellMar']); }
        foreach (['top','left','bottom','right'] as $side) {
            $margin=$this->one('w:tblPr/w:tblCellMar/w:'.$side,$table);
            $this->ensure($margin && $margin->getAttributeNS(self::W,'type')==='dxa' && $margin->getAttributeNS(self::W,'w')==='0','TABLE_MARGINS');
        }
        $grid=$this->xp->query('w:tblGrid/w:gridCol',$table); $widths=[];
        foreach ($grid as $col) { $widths[]=(float)$col->getAttributeNS(self::W,'w')/20; }
        $this->ensure(count($widths)>0 && array_sum($widths)<=540 && min($widths)>0,'TABLE_WIDTH');
        $tw=$this->one('w:tblPr/w:tblW',$table);
        $this->ensure(!$tw || ($tw->getAttributeNS(self::W,'type')==='dxa' && abs((float)$tw->getAttributeNS(self::W,'w')/20-array_sum($widths))<0.1)
            || ($tw->getAttributeNS(self::W,'type')==='auto' && $tw->getAttributeNS(self::W,'w')==='0'), 'TABLE_WIDTH_TYPE');
        $layout=$this->one('w:tblPr/w:tblLayout',$table);
        $this->ensure($layout && $layout->getAttributeNS(self::W,'type')==='fixed','TABLE_AUTOFIT');
        $html='<table style="width:'.array_sum($widths).'pt">';
        foreach ($this->xp->query('w:tr',$table) as $row) {
            $this->checkChildren($row,['w:trPr','w:tc']);
            $this->ensure(!$this->xp->query('w:trPr/*',$row)->length,'TABLE_ROW');
            $cells=$this->xp->query('w:tc',$row); $this->ensure($cells->length===count($widths),'TABLE_CELLS');
            $html.='<tr>';
            foreach ($cells as $i=>$cell) {
                $this->checkChildren($cell,['w:tcPr','w:p']);
                $cp=$this->one('w:tcPr',$cell); if ($cp) { $this->checkChildren($cp,['w:tcW']); }
                $cw=$this->one('w:tcPr/w:tcW',$cell);
                $this->ensure($cw && $cw->getAttributeNS(self::W,'type')==='dxa' && abs((float)$cw->getAttributeNS(self::W,'w')/20-$widths[$i])<0.1,'TABLE_CELL_WIDTH');
                $html.='<td style="width:'.$widths[$i].'pt">';
                foreach ($this->xp->query('w:p',$cell) as $p) { $html.=$this->paragraph($p); }
                $html.='</td>';
            }
            $html.='</tr>';
        }
        return $html.'</table>';
    }
}
