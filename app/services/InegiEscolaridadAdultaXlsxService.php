<?php

require_once __DIR__ . '/../models/EscolaridadAdultaModel.php';

/**
 * Importa los indicadores estatales a partir del tabulado censal B2020_07_08_M.
 *
 * El indicador de 18+ es un MÍNIMO verificable: algunas categorías del Censo
 * (normal básica y grados sin especificar) no acreditan ni descartan conclusión.
 */
class InegiEscolaridadAdultaXlsxService
{
    private const FUENTE = 'INEGI - Censo de Población y Vivienda 2020, B2020_07_08_M';
    private const GRUPOS_25 = [
        '25-29', '30-34', '35-39', '40-44', '45-49', '50-54',
        '55-59', '60-64', '65-69', '70-74', '75-79', '80-84', '85+'
    ];
    private const GRUPOS_18 = ['18', '19', '20-24'];

    public function importarXlsx(string $archivo, string $nombre, string $claveEstado, string $urlFuente): array
    {
        if (!class_exists('ZipArchive') || !class_exists('DOMDocument') || !is_readable($archivo)) {
            return $this->error('Servidor sin soporte ZIP/XML o archivo ilegible.');
        }
        $claveEstado = str_pad(preg_replace('/\D+/', '', $claveEstado) ?? '', 2, '0', STR_PAD_LEFT);
        if (!preg_match('/^(0[1-9]|[12][0-9]|3[0-2])$/', $claveEstado)) {
            return $this->error('Clave de entidad INEGI inválida.');
        }
        $urlPartes = parse_url($urlFuente);
        $host = strtolower((string)($urlPartes['host'] ?? ''));
        if (($urlPartes['scheme'] ?? '') !== 'https' ||
            !($host === 'inegi.org.mx' || str_ends_with($host, '.inegi.org.mx'))) {
            return $this->error('La referencia descargada no pertenece a INEGI.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archivo, ZipArchive::CHECKCONS) !== true) {
            return $this->error('INEGI no entregó un XLSX válido.');
        }
        try {
            $compartidas = $this->cadenas($zip);
            $grupos = [];
            $estructura = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $hoja = (string)$zip->getNameIndex($i);
                if (!preg_match('#^xl/worksheets/sheet\d+\.xml$#i', $hoja)) {
                    continue;
                }
                $resultado = $this->extraerHoja($zip, $hoja, $compartidas, $claveEstado);
                $estructura = $estructura || $resultado['estructura'];
                foreach ($resultado['grupos'] as $edad => $medidas) {
                    // Un total estatal repetido no debe sumarse dos veces.
                    if (isset($grupos[$edad]) && $grupos[$edad] !== $medidas) {
                        return $this->error('El archivo contiene totales estatales contradictorios para ' . $edad . '.');
                    }
                    $grupos[$edad] = $medidas;
                }
            }
            if (!$estructura || !$grupos) {
                return $this->error('No se identificó el cruce estatal edad × escolaridad B2020_07_08_M.');
            }
            $faltantes = array_diff(array_merge(self::GRUPOS_18, self::GRUPOS_25), array_keys($grupos));
            if ($faltantes) {
                return $this->error('Tabulado incompleto para el Estado ' . $claveEstado . ': faltan ' . implode(', ', $faltantes) . '.');
            }

            $base25 = $sinSuperior = $base18 = $sinMediaConfirmada = $incierto18 = 0;
            foreach (self::GRUPOS_25 as $edad) {
                $m = $grupos[$edad];
                $base25 += $m[1];
                $sinSuperior += $this->sumar($m, [2, 3, 4, 8, 12, 13, 17, 21]);
            }
            foreach (array_merge(self::GRUPOS_18, self::GRUPOS_25) as $edad) {
                $m = $grupos[$edad];
                $base18 += $m[1];
                // Educación previa a media superior + 1–2 grados de nivel medio superior.
                $sinMediaConfirmada += $this->sumar($m, [2, 3, 4, 8, 12, 14, 18]);
                // Niveles o grados insuficientes para confirmar conclusión.
                $incierto18 += $this->sumar($m, [16, 20, 21, 28]);
            }
            if ($base25 <= 0 || $base18 <= $base25 ||
                $sinSuperior > $base25 || $sinMediaConfirmada > $base18) {
                return $this->error('Totales educativos incongruentes; no se guardó ninguna cifra.');
            }

            $modelo = new EscolaridadAdultaModel();
            if (!$modelo->tablaDisponible()) {
                return $this->error('Falta aplicar la migración de indicadores de escolaridad adulta.');
            }
            $db = (new Database())->connect();
            $stmt = $db->prepare('SELECT id FROM estados WHERE clave_inegi = ? AND estado = 1 LIMIT 1');
            $stmt->bind_param('s', $claveEstado);
            $stmt->execute();
            $estadoId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
            $stmt->close();
            if ($estadoId <= 0) {
                return $this->error('El Estado no está activo o no tiene clave INEGI en el CRM.');
            }

            $ref = 'https://www.inegi.org.mx/contenidos/programas/ccpv/2020/doc/Censo2020_criterios_tabulados_CPV_est_mun.pdf';
            $metodo25 = 'INEGI B2020_07_08_M (2020): grupos de 25 a 29 hasta 85 años y más, sólo filas Total estatal y sexo Total. Suma de categorías 2,3,4,8,12,13,17,21 de niveles que no acreditan estudios superiores; se excluyen no especificados del numerador. Denominador: población total 25 años y más.';
            $metodo18 = 'INEGI B2020_07_08_M (2020): edad desplegada 18 y 19; grupo 20-24; grupos 25-29 hasta 85 años y más, sin duplicar subtotales. Conteo mínimo identificable sin media superior concluida: categorías 2,3,4,8,12 y 1–2 años aprobados de media superior 14,18. No se infiere conclusión en normal básica ni en grados no especificados 16,20,28. NO es un conteo exacto de todas las personas sin media superior concluida; denominador: toda la población 18 años y más. Personas con grado o nivel indeterminado: ' . $incierto18 . '. Criterios: ' . $ref;
            $filas = [
                ['estado_id' => $estadoId, 'codigo_indicador' => EscolaridadAdultaModel::SIN_SUPERIOR_25,
                 'anio' => 2020, 'poblacion_base' => $base25, 'cantidad_personas' => $sinSuperior,
                 'fuente' => self::FUENTE, 'referencia_url' => $urlFuente, 'metodologia' => $metodo25],
                ['estado_id' => $estadoId, 'codigo_indicador' => EscolaridadAdultaModel::SIN_MEDIA_CONCLUIDA_18,
                 'anio' => 2020, 'poblacion_base' => $base18, 'cantidad_personas' => $sinMediaConfirmada,
                 'fuente' => self::FUENTE, 'referencia_url' => $urlFuente, 'metodologia' => $metodo18]
            ];
            $modelo->importarLote($filas, 'AUTO_INEGI:' . basename($nombre));
            return [
                'ok' => true, 'estado_id' => $estadoId,
                'indicadores' => 2, 'anio' => 2020,
                'mensaje' => 'INEGI B2020_07_08_M procesado. El indicador 18+ es un mínimo identificable porque algunas categorías no desglosan su conclusión.',
                'cota_minima_18' => true, 'personas_grado_indeterminado' => $incierto18
            ];
        } catch (Throwable $e) {
            error_log('Escolaridad adulta INEGI: ' . $e->getMessage());
            return $this->error('El tabulado INEGI no pudo ser procesado o guardado.');
        } finally {
            $zip->close();
        }
    }

    private function extraerHoja(ZipArchive $zip, string $nombre, array $compartidas, string $estadoEsperado): array
    {
        $xml = $zip->getFromName($nombre);
        if ($xml === false || $xml === '') {
            return ['estructura' => false, 'grupos' => []];
        }
        $dom = new DOMDocument();
        $previo = libxml_use_internal_errors(true);
        $correcto = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);
        if (!$correcto) {
            return ['estructura' => false, 'grupos' => []];
        }
        $xp = new DOMXPath($dom);
        $filas = $xp->query('//*[local-name()="row"]');
        $estado = '';
        $municipio = '000';
        $sexo = '';
        $grupo = '';
        $estructura = false;
        $totales = [];
        if ($filas === false) {
            return ['estructura' => false, 'grupos' => []];
        }
        foreach ($filas as $fila) {
            $celdas = $this->leerFila($xp, $fila, $compartidas);
            if (!$celdas) {
                continue;
            }
            $texto = mb_strtolower(implode(' ', $celdas), 'UTF-8');
            if (str_contains($texto, 'población') && str_contains($texto, 'escolaridad')) {
                $estructura = true;
            }
            if (preg_match('/^([0-9]{2})\s+.+$/u', trim((string)($celdas[1] ?? '')), $m)) {
                $estado = $m[1];
                $municipio = '000';
                $grupo = '';
            }
            $geoMunicipio = trim((string)($celdas[2] ?? ''));
            if (preg_match('/^([0-9]{3})\s+.+$/u', $geoMunicipio)) {
                $municipio = substr($geoMunicipio, 0, 3);
                $grupo = '';
            } elseif (in_array(mb_strtoupper($geoMunicipio, 'UTF-8'), ['ENTIDAD FEDERATIVA', 'TOTAL', 'ESTADO'], true)) {
                $municipio = '000';
                $grupo = '';
            }
            if (trim((string)($celdas[3] ?? '')) !== '') {
                $sexo = $this->normalizarTexto((string)$celdas[3]);
            }
            if (trim((string)($celdas[4] ?? '')) !== '') {
                $grupo = $this->normalizarEdad((string)$celdas[4]);
            }
            if ($estado !== $estadoEsperado || $municipio !== '000' || $sexo !== 'TOTAL') {
                continue;
            }
            $edad = '';
            $detallada = $this->normalizarEdad((string)($celdas[5] ?? ''));
            if (in_array($grupo, self::GRUPOS_25, true)) {
                if ($detallada === '' || $detallada === 'TOTAL') {
                    $edad = $grupo;
                }
            } elseif ($grupo === '20-24') {
                if ($detallada === '' || $detallada === 'TOTAL') {
                    $edad = '20-24';
                }
            } elseif ($grupo === '15-19' && ($detallada === '18' || $detallada === '19')) {
                $edad = $detallada;
            }
            if ($edad === '') {
                continue;
            }
            $m = [];
            for ($n = 1; $n <= 28; $n++) {
                $m[$n] = $this->entero($celdas[5 + $n] ?? null);
            }
            if (in_array(null, $m, true)) {
                continue;
            }
            $totalesNivel = $this->sumar($m, [2, 3, 4, 8, 12, 13, 17, 21, 22, 23, 27, 28]);
            if ($m[1] <= 0 || $totalesNivel !== $m[1] ||
                $m[14] + $m[15] + $m[16] !== $m[13] ||
                $m[18] + $m[19] + $m[20] !== $m[17]) {
                continue;
            }
            if (isset($totales[$edad]) && $totales[$edad] !== $m) {
                throw new RuntimeException('Grupos estatales contradictorios.');
            }
            $totales[$edad] = $m;
        }
        return ['estructura' => $estructura || count($totales) > 0, 'grupos' => $totales];
    }

    private function cadenas(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false || $xml === '') {
            return [];
        }
        $doc = new DOMDocument();
        if (!$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return [];
        }
        $xp = new DOMXPath($doc);
        $salida = [];
        foreach ($xp->query('//*[local-name()="si"]') as $nodo) {
            $valor = '';
            foreach ($xp->query('.//*[local-name()="t"]', $nodo) as $texto) {
                $valor .= $texto->textContent;
            }
            $salida[] = trim($valor);
        }
        return $salida;
    }

    private function leerFila(DOMXPath $xp, DOMNode $fila, array $compartidas): array
    {
        $celdas = [];
        foreach ($xp->query('./*[local-name()="c"]', $fila) as $celda) {
            if (!preg_match('/^([A-Z]+)\d+$/i', $celda->getAttribute('r'), $m)) {
                continue;
            }
            $indice = 0;
            foreach (str_split(strtoupper($m[1])) as $letra) {
                $indice = $indice * 26 + (ord($letra) - 64);
            }
            $tipo = $celda->getAttribute('t');
            if ($tipo === 'inlineStr') {
                $valor = '';
                foreach ($xp->query('.//*[local-name()="t"]', $celda) as $nodo) {
                    $valor .= $nodo->textContent;
                }
            } else {
                $v = $xp->query('./*[local-name()="v"]', $celda)->item(0);
                $raw = $v ? (string)$v->textContent : '';
                $valor = ($tipo === 's' && ctype_digit(trim($raw))) ?
                    ($compartidas[(int)trim($raw)] ?? '') : $raw;
            }
            $celdas[$indice] = trim((string)$valor);
        }
        return $celdas;
    }

    private function normalizarEdad(string $valor): string
    {
        $valor = $this->normalizarTexto($valor);
        $valor = str_replace([' AÑOS Y MÁS', ' ANOS Y MAS'], '+', $valor);
        $valor = str_replace([' AÑOS', ' ANOS', ' '], '', $valor);
        $valor = str_replace(['–', '—'], '-', $valor);
        if (preg_match('/^(\d{1,2})-(\d{1,2})$/', $valor, $m)) {
            return (int)$m[1] . '-' . (int)$m[2];
        }
        if (preg_match('/^(\d{1,2})$/', $valor, $m)) {
            return (string)(int)$m[1];
        }
        if (preg_match('/^(\d{1,2})\+$/', $valor, $m)) {
            return (int)$m[1] . '+';
        }
        return $valor === 'TOTAL' ? 'TOTAL' : '';
    }

    private function normalizarTexto(string $valor): string
    {
        return strtr(mb_strtoupper(trim($valor), 'UTF-8'), [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U'
        ]);
    }

    private function entero($valor): ?int
    {
        $valor = str_replace([',', ' '], '', trim((string)$valor));
        return preg_match('/^\d+(?:\.0+)?$/', $valor) ? (int)$valor : null;
    }

    private function sumar(array $m, array $categorias): int
    {
        return array_sum(array_map(static fn(int $i): int => $m[$i], $categorias));
    }

    private function error(string $mensaje): array
    {
        return ['ok' => false, 'mensaje' => $mensaje];
    }
}
