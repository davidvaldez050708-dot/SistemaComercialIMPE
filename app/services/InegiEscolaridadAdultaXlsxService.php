<?php

require_once __DIR__ . '/../models/EscolaridadAdultaModel.php';
require_once __DIR__ . '/../models/EscolaridadJuvenilModel.php';

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

    public function importarXlsx(string $archivo, string $nombre, string $claveEstado, string $urlFuente, bool $soloJuvenil = false): array
    {
        if (!class_exists('ZipArchive') || !class_exists('DOMDocument') || !class_exists('XMLReader') || !is_readable($archivo)) {
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
            $etapa = 'lectura del XLSX';
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
            if ($soloJuvenil) {
                return $this->importarJuvenil($grupos, $nombre, $claveEstado, $urlFuente);
            }

            // Si el tabulado trae edades 20,21,22,23,24 sin subtotal, agrupar
            // esas cinco filas; si hay subtotal, excluir edades individuales.
            if (!isset($grupos['20-24'])) {
                $cinco = ['20', '21', '22', '23', '24'];
                if (!array_diff($cinco, array_keys($grupos))) {
                    $combinado = array_fill(1, 28, 0);
                    foreach ($cinco as $edad) {
                        foreach ($grupos[$edad] as $col => $valor) {
                            $combinado[$col] += $valor;
                        }
                    }
                    $grupos['20-24'] = $combinado;
                }
            }
            foreach (['20','21','22','23','24'] as $edadIndividual) {
                unset($grupos[$edadIndividual]);
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
                $sinMediaConfirmada += $this->sumar($m, [2, 3, 4, 8, 12, 18]);
                // Niveles o grados insuficientes para confirmar conclusión.
                $incierto18 += $this->sumar($m, [13, 20, 21, 28]);
            }
            if ($base25 <= 0 || $base18 <= $base25 ||
                $sinSuperior > $base25 || $sinMediaConfirmada > $base18) {
                return $this->error('Totales educativos incongruentes; no se guardó ninguna cifra.');
            }

            $etapa = 'preparación de la base de datos';
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
            $metodo18 = 'INEGI B2020_07_08_M (2020): edad desplegada 18 y 19; grupo 20-24; grupos 25-29 hasta 85 años y más, sin duplicar subtotales. Conteo mínimo identificable sin media superior concluida: categorías 2,3,4,8,12 y 1–2 años de bachillerato 18. La duración de estudios técnicos con secundaria (13), la normal básica (21) y los grados no especificados (20,28) no permiten determinar conclusión. NO es un conteo exacto de todas las personas sin media superior concluida; denominador: toda la población 18 años y más. Personas con grado o nivel indeterminado: ' . $incierto18 . '. Criterios: ' . $ref;
            $filas = [
                ['estado_id' => $estadoId, 'codigo_indicador' => EscolaridadAdultaModel::SIN_SUPERIOR_25,
                 'anio' => 2020, 'poblacion_base' => $base25, 'cantidad_personas' => $sinSuperior,
                 'fuente' => self::FUENTE, 'referencia_url' => $urlFuente, 'metodologia' => $metodo25],
                ['estado_id' => $estadoId, 'codigo_indicador' => EscolaridadAdultaModel::SIN_MEDIA_CONCLUIDA_18,
                 'anio' => 2020, 'poblacion_base' => $base18, 'cantidad_personas' => $sinMediaConfirmada,
                 'fuente' => self::FUENTE, 'referencia_url' => $urlFuente, 'metodologia' => $metodo18]
            ];
            $etapa = 'guardado de indicadores en MySQL';
            $modelo->importarLote($filas, 'AUTO_INEGI:' . basename($nombre));
            return [
                'ok' => true, 'estado_id' => $estadoId,
                'indicadores' => 2, 'anio' => 2020,
                'mensaje' => 'INEGI B2020_07_08_M procesado. El indicador 18+ es un mínimo identificable porque algunas categorías no desglosan su conclusión.',
                'cota_minima_18' => true, 'personas_grado_indeterminado' => $incierto18
            ];
        } catch (Throwable $e) {
            error_log('Escolaridad adulta INEGI [' . $etapa . '] ' . get_class($e) . ': ' . $e->getMessage());
            return $this->error('Error durante ' . $etapa . ' (' . get_class($e) . '). Revisa el log de PHP para conocer el motivo.');
        } finally {
            $zip->close();
        }
    }

    /**
     * Calcula únicamente el mínimo identificable del Censo para 15-17.
     * No sumar categorías técnicas con secundaria de duración indeterminada
     * ni grados de bachillerato no especificados.
     */
    public static function calcularJuvenil(array $grupos): array
    {
        $base = $minimo = $indeterminados = 0;
        foreach (['15', '16', '17'] as $edad) {
            $m = $grupos[$edad] ?? null;
            if (!is_array($m) || count($m) !== 28 || (int)($m[1] ?? 0) <= 0) {
                throw new InvalidArgumentException('Falta la edad ' . $edad . ' en el tabulado oficial.');
            }
            $totalNivel = 0;
            foreach ([2, 3, 4, 8, 12, 13, 17, 21, 22, 23, 27, 28] as $categoria) {
                $totalNivel += (int)$m[$categoria];
            }
            if ($totalNivel !== (int)$m[1] ||
                (int)$m[13] !== (int)$m[14] + (int)$m[15] + (int)$m[16] ||
                (int)$m[17] !== (int)$m[18] + (int)$m[19] + (int)$m[20]) {
                throw new InvalidArgumentException('Totales de escolaridad incompatibles a los ' . $edad . ' años.');
            }
            $base += (int)$m[1];
            foreach ([2, 3, 4, 8, 12, 18] as $categoria) {
                $minimo += (int)$m[$categoria];
            }
            foreach ([14, 16, 20, 21, 28] as $categoria) {
                $indeterminados += (int)$m[$categoria];
            }
        }
        if ($base <= 0 || $minimo > $base) {
            throw new InvalidArgumentException('El universo 15-17 no es válido.');
        }
        return [
            'poblacion_base' => $base,
            'cantidad_personas' => $minimo,
            'porcentaje' => round(100 * $minimo / $base, 2),
            'personas_indeterminadas' => $indeterminados
        ];
    }

    private function importarJuvenil(array $grupos, string $nombre, string $claveEstado, string $urlFuente): array
    {
        $datos = self::calcularJuvenil($grupos);
        $modelo = new EscolaridadJuvenilModel();
        if (!$modelo->tablaDisponible()) {
            return $this->error('Falta aplicar la migración 2026_10_08_escolaridad_juvenil.sql.');
        }
        $db = (new Database())->connect();
        $stmt = $db->prepare('SELECT id FROM estados WHERE clave_inegi = ? AND estado = 1 LIMIT 1');
        $stmt->bind_param('s', $claveEstado);
        $stmt->execute();
        $estadoId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
        $stmt->close();
        if ($estadoId <= 0) {
            return $this->error('El Estado no está activo en el CRM o no coincide con la clave INEGI.');
        }
        $metodologia = 'INEGI, Censo de Población y Vivienda 2020, cuadro B2020_07_08_M. ' .
            'Universo: total estatal de sexo Total, edades individuales 15, 16 y 17. ' .
            'Mínimo identificable de personas sin media superior concluida: categorías 2 sin escolaridad, ' .
            '3 preescolar, 4 primaria total, 8 secundaria total, 12 técnicos con primaria terminada ' .
            'y 18 preparatoria/bachillerato con uno o dos grados aprobados. ' .
            'Se excluyen del numerador los grados técnicos con secundaria de duración no comprobable, ' .
            'normal básica y niveles/grados no especificados. ' .
            'La categoría bachillerato con tres o más grados aprobados no se considera inconclusa. ' .
            'Este valor es un mínimo, no un total exhaustivo ni una tasa de abandono escolar. ' .
            'Casos de conclusión no determinable: ' . $datos['personas_indeterminadas'] . '. ' .
            'El porcentaje usa la población total de 15 a 17 años de la entidad.';
        $modelo->importarDesdeInegi(
            $estadoId, 2020, $datos['poblacion_base'], $datos['cantidad_personas'],
            self::FUENTE, $urlFuente, $metodologia, 'AUTO_INEGI:' . basename($nombre)
        );
        return [
            'ok' => true, 'estado_id' => $estadoId, 'indicadores' => 1,
            'anio' => 2020, 'datos' => $datos,
            'mensaje' => 'INEGI: escolaridad juvenil 15–17 sincronizada. La cifra representa un mínimo identificado, no abandono escolar.'
        ];
    }

    private function extraerHoja(ZipArchive $zip, string $nombre, array $compartidas, string $estadoEsperado): array
    {
        // Un archivo estatal puede contener cientos de miles de filas municipales.
        // Leerlo completo en DOM agota la memoria de PHP/XAMPP.
        $rutaZip = realpath((string)$zip->filename);
        if ($rutaZip === false) {
            throw new RuntimeException('El archivo temporal de INEGI no está disponible.');
        }
        $reader = new XMLReader();
        $uri = 'zip://' . str_replace('\\', '/', $rutaZip) . '#' . $nombre;
        if (!$reader->open($uri, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new RuntimeException('No se pudo abrir la hoja XLSX como flujo XML.');
        }
        $estado = '';
        $municipio = '000';
        $sexo = '';
        $grupo = '';
        $estructura = false;
        $totales = [];
        $rechazados = 0;
        $ejemplosRechazo = [];
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }
                $fragmento = $reader->readOuterXML();
                if ($fragmento === '') {
                    continue;
                }
                $dom = new DOMDocument();
                if (!$dom->loadXML($fragmento, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                    continue;
                }
                $xp = new DOMXPath($dom);
                $fila = $dom->documentElement;
                if (!$fila) {
                    continue;
                }
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
            // Las hojas incluyen estratos por tamaño de localidad, no son
            // totales estatales ni municipios: no mezclarlos con el Estado.
            if (preg_match('/habitantes|tama(?:ñ|n)o\\s+de\\s+localidad|rango\\s+de\\s+poblaci[oó]n/iu', $geoMunicipio)) {
                $municipio = '';
                $grupo = '';
            } elseif (preg_match('/^([0-9]{3})\s+.+$/u', $geoMunicipio)) {
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
                } elseif (in_array($detallada, ['20','21','22','23','24'], true)) {
                    $edad = $detallada;
                }
            } elseif ($grupo === '15-19' && in_array($detallada, ['15', '16', '17', '18', '19'], true)) {
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
                $rechazados++;
                if (count($ejemplosRechazo) < 3) {
                    $ejemplosRechazo[] = $edad . ': celdas no numéricas ' .
                        implode(',', array_keys(array_filter($m, static fn($v) => $v === null)));
                }
                continue;
            }
            $totalesNivel = $this->sumar($m, [2, 3, 4, 8, 12, 13, 17, 21, 22, 23, 27, 28]);
            if ($m[1] <= 0 || $totalesNivel !== $m[1] ||
                $m[14] + $m[15] + $m[16] !== $m[13] ||
                $m[18] + $m[19] + $m[20] !== $m[17]) {
                $rechazados++;
                if (count($ejemplosRechazo) < 3) {
                    $ejemplosRechazo[] = $edad . ': total=' . $m[1] .
                        ' sumaNiveles=' . $totalesNivel . ' categoria13=' . $m[13] .
                        ' partes13=' . ($m[14] + $m[15] + $m[16]) .
                        ' categoria17=' . $m[17] .
                        ' partes17=' . ($m[18] + $m[19] + $m[20]) .
                        ' crudos=' . implode(',', array_map(
                            static fn($v) => trim((string)$v), array_slice($celdas, 5, 28, true)
                        ));
                }
                continue;
            }
            if (isset($totales[$edad]) && $totales[$edad] !== $m) {
                throw new RuntimeException('Grupos estatales contradictorios para ' . $edad .
                    ' (previo=' . $totales[$edad][1] . ', actual=' . $m[1] .
                    '; geoEstado=' . ($celdas[1] ?? '') .
                    '; geoMunicipio=' . ($celdas[2] ?? '') .
                    '; sexo=' . ($celdas[3] ?? '') .
                    '; grupo=' . ($celdas[4] ?? '') .
                    '; edadDesplegada=' . ($celdas[5] ?? '') . ').');
            }
            $totales[$edad] = $m;
            }
        } finally {
            $reader->close();
        }
        // El XLSX oficial reúne diferentes cuadros de educación. Las hojas
        // de asistencia/alfabetismo tienen 2-3 métricas y NO corresponden al
        // cuadro B2020_07_08_M (28 métricas): omitirlas, no abortar el libro.
        if ($rechazados > 0 && count($totales) === 0) {
            return [
                'estructura' => false,
                'grupos' => [],
                'diagnostico' => 'Hoja incompatible: ' . $rechazados .
                    ' filas de otra tabla. ' . implode(' | ', $ejemplosRechazo)
            ];
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
        $valor = preg_replace('/\\s+A(?:Ñ|N)OS?\\s+Y\\s+MAS$/u', '+', $valor);
        $valor = str_replace([' AÑOS', ' ANOS'], '', $valor);
        $valor = preg_replace('/^(\\d{1,2})\\s+A\\s+(\\d{1,2})$/', '$1-$2', $valor);
        $valor = str_replace(' ', '', $valor);
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
        // Celdas estadísticas sin valor y guiones suelen representar 0.
        // Sólo aceptamos el resultado si coincide con los totales y subtotales.
        if ($valor === '-' || $valor === '–' || $valor === '') {
            return 0;
        }
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
