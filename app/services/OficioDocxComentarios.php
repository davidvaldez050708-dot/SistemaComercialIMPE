<?php

/** Recupera únicamente anotaciones inconsistentes en una NUEVA copia temporal. */
final class OficioDocxComentarios
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const TYPES = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments' => 'comments',
        'http://schemas.microsoft.com/office/2011/relationships/commentsExtended' => 'extended',
        'http://schemas.microsoft.com/office/2016/09/relationships/commentsIds' => 'ids',
        'http://schemas.microsoft.com/office/2018/08/relationships/commentsExtensible' => 'extensible',
    ];

    public static function recuperar($origen, $directorio, $idFallido)
    {
        $zip = new ZipArchive();
        if (@$zip->open($origen, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('COMMENTS_ZIP_UNREADABLE');
        }
        $copia = null;
        try {
            $parts = [];
            $stories = [];
            $definitions = [];
            $anchors = [];
            // Resolver destinos de las relaciones del paquete, no borrar por nombre de archivo.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_ends_with($name, '.rels')) {
                    $rels = self::xml($zip, $name);
                    foreach ($rels->getElementsByTagNameNS(self::REL, 'Relationship') as $rel) {
                        $kind = self::TYPES[$rel->getAttribute('Type')] ?? null;
                        if ($kind === null) {
                            continue;
                        }
                        if ($rel->getAttribute('TargetMode') === 'External') {
                            throw new RuntimeException('COMMENTS_EXTERNAL_RELATION');
                        }
                        $target = self::target($name, $rel->getAttribute('Target'));
                        if (isset($parts[$target]) && $parts[$target] !== $kind) {
                            throw new RuntimeException('COMMENTS_AMBIGUOUS_RELATION');
                        }
                        $parts[$target] = $kind;
                    }
                }
            }
            foreach ($parts as $name => $kind) {
                $doc = self::xml($zip, $name);
                $schemas = [
                    'comments' => [self::W, 'comments'],
                    'extended' => ['http://schemas.microsoft.com/office/word/2012/wordml', 'commentsEx'],
                    'ids' => ['http://schemas.microsoft.com/office/word/2016/wordml/cid', 'commentsIds'],
                    'extensible' => ['http://schemas.microsoft.com/office/word/2018/wordml/cex', 'commentsExtensible'],
                ];
                if ([$doc->documentElement->namespaceURI, $doc->documentElement->localName] !== $schemas[$kind]) {
                    throw new RuntimeException('COMMENTS_INVALID_PART');
                }
                if ($kind === 'comments') {
                    if ($doc->documentElement->namespaceURI !== self::W || $doc->documentElement->localName !== 'comments') {
                        throw new RuntimeException('COMMENTS_INVALID_PART');
                    }
                    foreach ($doc->getElementsByTagNameNS(self::W, 'comment') as $comment) {
                        $id = $comment->getAttributeNS(self::W, 'id');
                        $definitions[$id][] = [$name, $comment];
                    }
                }
                $parts[$name] = ['kind' => $kind, 'doc' => $doc];
            }
            // Todas las historias XML: incluye tablas, encabezados, pies y cuadros de texto.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!str_ends_with($name, '.xml') || isset($parts[$name])) {
                    continue;
                }
                $raw = $zip->getFromIndex($i);
                if (!str_contains($raw, self::W)) {
                    continue;
                }
                $doc = self::xml($zip, $name);
                $nodes = [];
                foreach (['commentRangeStart', 'commentRangeEnd', 'commentReference'] as $type) {
                    foreach ($doc->getElementsByTagNameNS(self::W, $type) as $node) {
                        $id = $node->getAttributeNS(self::W, 'id');
                        $anchors[$id][$type][] = $name;
                        $nodes[] = $node;
                    }
                }
                if ($nodes) {
                    $stories[$name] = ['doc' => $doc, 'nodes' => $nodes];
                }
            }
            $invalid = [];
            foreach (array_unique(array_merge(array_keys($definitions), array_keys($anchors))) as $id) {
                $a = $anchors[$id] ?? [];
                $start = $a['commentRangeStart'] ?? [];
                $end = $a['commentRangeEnd'] ?? [];
                $ref = $a['commentReference'] ?? [];
                // Una referencia puntual sin rango es válida según OOXML.
                $rangeValid = (!$start && !$end) || (count($start) === 1 && count($end) === 1);
                $sameStory = count(array_unique(array_merge($start, $end, $ref))) === 1;
                if (count($definitions[$id] ?? []) !== 1 || count($ref) !== 1 || !$rangeValid || !$sameStory) {
                    $invalid[$id] = true;
                }
            }
            if (!isset($invalid[$idFallido]) || empty($definitions[$idFallido])) {
                throw new RuntimeException('COMMENTS_INCONSISTENCY_NOT_CONFIRMED');
            }
            $paragraphs = [];
            $durable = [];
            $changes = [];
            foreach ($invalid as $id => $_) {
                foreach ($definitions[$id] ?? [] as [$name, $comment]) {
                    foreach ($comment->getElementsByTagNameNS(self::W, 'p') as $p) {
                        $para = $p->getAttributeNS('http://schemas.microsoft.com/office/word/2010/wordml', 'paraId');
                        if ($para !== '') {
                            $paragraphs[strtoupper($para)] = true;
                        }
                    }
                    $comment->parentNode->removeChild($comment);
                    $changes[$name] = $parts[$name]['doc'];
                }
            }
            foreach ($stories as $name => $story) {
                foreach ($story['nodes'] as $node) {
                    if (isset($invalid[$node->getAttributeNS(self::W, 'id')])) {
                        // Nunca eliminar el run/párrafo padre, texto, imagen o tabla.
                        $node->parentNode->removeChild($node);
                        $changes[$name] = $story['doc'];
                    }
                }
            }
            // Prunar índices de comentarios por paraId/durableId; mantener partes y relaciones válidas.
            foreach (['ids', 'extended', 'extensible'] as $kind) {
                foreach ($parts as $name => $part) {
                    if ($part['kind'] !== $kind) {
                        continue;
                    }
                    foreach (iterator_to_array($part['doc']->documentElement->childNodes) as $node) {
                        if (!$node instanceof DOMElement) {
                            continue;
                        }
                        $remove = false;
                        foreach (iterator_to_array($node->attributes) as $attr) {
                            if ($attr->namespaceURI !== $node->namespaceURI) { continue; }
                            if ($attr->localName === 'paraId' && isset($paragraphs[strtoupper($attr->value)])) {
                                $remove = true;
                            } elseif ($attr->localName === 'durableId' && $kind === 'extensible' && isset($durable[strtoupper($attr->value)])) {
                                $remove = true;
                            } elseif ($attr->localName === 'paraIdParent' && isset($paragraphs[strtoupper($attr->value)])) {
                                $node->removeAttributeNode($attr);
                                $changes[$name] = $part['doc'];
                            }
                        }
                        if ($remove) {
                            foreach ($node->attributes as $attr) {
                                if ($attr->localName === 'durableId') {
                                    $durable[strtoupper($attr->value)] = true;
                                }
                            }
                            $node->parentNode->removeChild($node);
                            $changes[$name] = $part['doc'];
                        }
                    }
                }
            }
            $copia = $directorio . '/comentarios_' . bin2hex(random_bytes(8)) . '.docx';
            $input = null;
            $output = null;
            try {
                $input = @fopen($origen, 'rb');
                $output = @fopen($copia, 'xb');
                if (!$input || !$output) { throw new RuntimeException('COMMENTS_COPY_FAILED'); }
                if (@stream_copy_to_stream($input, $output) !== filesize($origen)) {
                    throw new RuntimeException('COMMENTS_COPY_FAILED');
                }
            } finally {
                if (is_resource($input)) { fclose($input); }
                if (is_resource($output)) { fclose($output); }
            }
            $zip->close();
            if (@$zip->open($copia) !== true) {
                throw new RuntimeException('COMMENTS_COPY_INVALID');
            }
            foreach ($changes as $name => $doc) {
                if (!@$zip->addFromString($name, $doc->saveXML())) {
                    throw new RuntimeException('COMMENTS_WRITE_FAILED');
                }
            }
            if (!@$zip->close()) {
                throw new RuntimeException('COMMENTS_CLOSE_FAILED');
            }
            return ['ruta' => $copia, 'comentarios_omitidos' => count($invalid), 'partes' => count($changes)];
        } catch (Throwable $error) {
            if ($copia !== null && is_file($copia)) {
                // Cerrar el ZIP antes de borrar la copia en Windows.
                try { $zip->close(); } catch (Throwable $ignored) { /* Ya cerrado. */ }
                @unlink($copia);
            }
            throw $error;
        } finally {
            try { $zip->close(); } catch (Throwable $ignored) { /* Ya cerrado. */ }
        }
    }

    private static function xml(ZipArchive $zip, $name)
    {
        $value = $zip->getFromName($name);
        if (!is_string($value) || strlen($value) > 16777216 || stripos($value, '<!DOCTYPE') !== false) {
            throw new RuntimeException('COMMENTS_XML_UNSAFE');
        }
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$doc->loadXML($value, LIBXML_NONET) || $doc->doctype !== null) {
                throw new RuntimeException('COMMENTS_XML_INVALID');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $doc;
    }

    private static function target($relation, $target)
    {
        $target = rawurldecode($target);
        if ($target === '' || preg_match('~[\\\\:\x00?#]~', $target)) {
            throw new RuntimeException('COMMENTS_RELATION_UNSAFE');
        }
        $base = str_starts_with($target, '/') ? '' : dirname(dirname($relation));
        $segments = [];
        foreach (explode('/', $base . '/' . ltrim($target, '/')) as $segment) {
            if ($segment === '' || $segment === '.') { continue; }
            if ($segment === '..') {
                if (!$segments) { throw new RuntimeException('COMMENTS_RELATION_UNSAFE'); }
                array_pop($segments);
            } else { $segments[] = $segment; }
        }
        return implode('/', $segments);
    }
}
