<?php

class RezagoEducativoImportService
{
    private const MAX_ARCHIVO_BYTES = 16777216;

    private array $mapaEntidades = [
        'Estados Unidos Mexicanos' => '00',
        'Aguascalientes' => '01',
        'Baja California' => '02',
        'Baja California Sur' => '03',
        'Campeche' => '04',
        'Coahuila' => '05',
        'Coahuila de Zaragoza' => '05',
        'Colima' => '06',
        'Chiapas' => '07',
        'Chihuahua' => '08',
        'Ciudad de México' => '09',
        'Durango' => '10',
        'Guanajuato' => '11',
        'Guerrero' => '12',
        'Hidalgo' => '13',
        'Jalisco' => '14',
        'México' => '15',
        'Estado de México' => '15',
        'Michoacán' => '16',
        'Michoacán de Ocampo' => '16',
        'Morelos' => '17',
        'Nayarit' => '18',
        'Nuevo León' => '19',
        'Oaxaca' => '20',
        'Puebla' => '21',
        'Querétaro' => '22',
        'Quintana Roo' => '23',
        'San Luis Potosí' => '24',
        'Sinaloa' => '25',
        'Sonora' => '26',
        'Tabasco' => '27',
        'Tamaulipas' => '28',
        'Tlaxcala' => '29',
        'Veracruz' => '30',
        'Veracruz de Ignacio de la Llave' => '30',
        'Yucatán' => '31',
        'Zacatecas' => '32'
    ];

    public function leerArchivo(string $rutaArchivo): array
    {
        $rutaArchivo = trim($rutaArchivo);

        if ($rutaArchivo === '' || !is_file($rutaArchivo) || !is_readable($rutaArchivo)) {
            return $this->error('No fue posible leer el archivo seleccionado.');
        }

        if (strtolower(pathinfo($rutaArchivo, PATHINFO_EXTENSION)) !== 'xlsx') {
            return $this->error('El archivo debe estar en formato XLSX.');
        }

        $tamano = filesize($rutaArchivo);

        if ($tamano === false || $tamano <= 0 || $tamano > self::MAX_ARCHIVO_BYTES) {
            return $this->error('El archivo XLSX supera el tamaño permitido o está vacío.');
        }

        if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
            return $this->error('El servidor no tiene habilitado el soporte necesario para leer archivos XLSX.');
        }

        $zip = new ZipArchive();
        $abierto = $zip->open($rutaArchivo);

        if ($abierto !== true) {
            return $this->error('El archivo seleccionado no es un XLSX válido o está dañado.');
        }

        try {
            $hojas = $this->obtenerRutasHojas($zip);
            $sharedStrings = $this->obtenerSharedStrings($zip);
            $geografias = [];

            foreach ($hojas as $nombreHoja => $rutaHoja) {
                $nombreHojaNormalizado = $this->normalizarEspacios($nombreHoja);

                if (
                    $nombreHojaNormalizado === '' ||
                    stripos($nombreHojaNormalizado, 'IP ') === 0 ||
                    in_array($nombreHojaNormalizado, ['Índice', 'Indice', 'Gráficas', 'Graficas', 'Gráfica 1', 'Grafica 1'], true)
                ) {
                    continue;
                }

                $hoja = $this->leerHoja($zip, $rutaHoja, $sharedStrings);
                $resultadoHoja = $this->extraerRezagoDeHoja($hoja);

                if ($resultadoHoja === null) {
                    continue;
                }

                $clave = $resultadoHoja['clave_geografica'];

                if (isset($geografias[$clave])) {
                    throw new RuntimeException('El archivo contiene una geografía duplicada para rezago educativo.');
                }

                $geografias[$clave] = $resultadoHoja;
            }

            $clavesEsperadas = array_map(
                static fn ($numero) => str_pad((string)$numero, 2, '0', STR_PAD_LEFT),
                range(0, 32)
            );

            foreach ($clavesEsperadas as $clave) {
                if (!isset($geografias[$clave])) {
                    return $this->error('El archivo no contiene las 32 entidades y la referencia nacional completas.');
                }
            }

            if (count($geografias) !== 33) {
                return $this->error('El archivo debe contener exactamente 32 entidades y la referencia nacional.');
            }

            ksort($geografias);
            $periodosReferencia = null;

            foreach ($geografias as $geografia) {
                $periodos = array_keys($geografia['periodos']);
                sort($periodos, SORT_NUMERIC);

                if ($periodosReferencia === null) {
                    $periodosReferencia = $periodos;
                    continue;
                }

                if ($periodos !== $periodosReferencia) {
                    return $this->error('Los territorios del archivo no contienen los mismos periodos de rezago educativo.');
                }
            }

            if (empty($periodosReferencia)) {
                return $this->error('No fue posible identificar periodos válidos de rezago educativo.');
            }

            $datos = [];

            foreach ($clavesEsperadas as $clave) {
                $geografia = $geografias[$clave];

                foreach ($periodosReferencia as $anio) {
                    $periodo = $geografia['periodos'][$anio] ?? null;

                    if (!is_array($periodo)) {
                        return $this->error('El archivo contiene periodos incompletos de rezago educativo.');
                    }

                    $cantidad = $periodo['cantidad_personas'] ?? null;
                    $porcentaje = $periodo['porcentaje'] ?? null;

                    if (!is_int($cantidad) || $cantidad < 0 || $cantidad > 200000000) {
                        return $this->error('El archivo contiene una cantidad de personas con rezago educativo no válida.');
                    }

                    if (!is_numeric($porcentaje) || (float)$porcentaje < 0 || (float)$porcentaje > 100) {
                        return $this->error('El archivo contiene un porcentaje de rezago educativo no válido.');
                    }

                    $datos[] = [
                        'clave_geografica' => $clave,
                        'nombre' => $geografia['nombre'],
                        'anio' => (int)$anio,
                        'cantidad_personas' => $cantidad,
                        'porcentaje' => round((float)$porcentaje, 2)
                    ];
                }
            }

            $ultimoPeriodo = max($periodosReferencia);

            return [
                'ok' => true,
                'fuente' => 'INEGI - Pobreza Multidimensional',
                'archivo_origen' => basename($rutaArchivo),
                'periodos' => array_values(array_map('intval', $periodosReferencia)),
                'ultimo_periodo' => (int)$ultimoPeriodo,
                'total_periodos' => count($periodosReferencia),
                'total_geografias' => 33,
                'total_registros' => count($datos),
                'datos' => $datos
            ];
        } catch (Throwable $error) {
            error_log($error->getMessage());
            return $this->error('No fue posible reconocer la estructura del archivo oficial de rezago educativo.');
        } finally {
            $zip->close();
        }
    }

    private function extraerRezagoDeHoja(array $hoja): ?array
    {
        if (empty($hoja)) {
            return null;
        }

        $filaRezago = null;
        $nombreGeografia = '';
        $filaCabeceras = null;

        foreach ($hoja as $numeroFila => $celdas) {
            foreach ($celdas as $valor) {
                $texto = $this->normalizarEspacios($valor);

                if ($texto === '') {
                    continue;
                }

                if ($nombreGeografia === '') {
                    $clave = $this->clavePorNombre($texto);

                    if ($clave !== null) {
                        $nombreGeografia = $texto;
                    }
                }

                if ($this->textoComparable($texto) === 'rezago educativo') {
                    $filaRezago = (int)$numeroFila;
                }
            }
        }

        if ($filaRezago === null || $nombreGeografia === '') {
            return null;
        }

        for ($fila = $filaRezago - 1; $fila >= 1; $fila--) {
            $celdas = $hoja[$fila] ?? [];
            $tienePoblacion = false;
            $tienePorcentaje = false;

            foreach ($celdas as $valor) {
                $comparable = $this->textoComparable($valor);
                $tienePoblacion = $tienePoblacion || $comparable === 'poblacion';
                $tienePorcentaje = $tienePorcentaje || $comparable === 'porcentaje';
            }

            if ($tienePoblacion && $tienePorcentaje) {
                $filaCabeceras = $fila;
                break;
            }
        }

        if ($filaCabeceras === null) {
            throw new RuntimeException('No se encontraron los encabezados Población y Porcentaje.');
        }

        $filaAnios = null;

        for ($fila = $filaCabeceras + 1; $fila < $filaRezago; $fila++) {
            $aniosEncontrados = 0;

            foreach (($hoja[$fila] ?? []) as $valor) {
                if ($this->anioValido($valor) !== null) {
                    $aniosEncontrados++;
                }
            }

            if ($aniosEncontrados >= 2) {
                $filaAnios = $fila;
                break;
            }
        }

        if ($filaAnios === null) {
            throw new RuntimeException('No se encontraron los años disponibles del tabulado.');
        }

        $cabeceras = $hoja[$filaCabeceras] ?? [];
        $columnaPoblacion = null;
        $columnaPorcentaje = null;
        $columnasCabecera = [];

        foreach ($cabeceras as $columna => $valor) {
            $comparable = $this->textoComparable($valor);

            if ($comparable === '') {
                continue;
            }

            $columnasCabecera[(int)$columna] = $comparable;

            if ($comparable === 'poblacion') {
                $columnaPoblacion = (int)$columna;
            } elseif ($comparable === 'porcentaje') {
                $columnaPorcentaje = (int)$columna;
            }
        }

        if ($columnaPoblacion === null || $columnaPorcentaje === null) {
            throw new RuntimeException('No se localizaron las columnas de población y porcentaje.');
        }

        ksort($columnasCabecera);
        $columnasInicio = array_keys($columnasCabecera);
        $finPoblacion = $this->finBloque($columnaPoblacion, $columnasInicio, $hoja);
        $finPorcentaje = $this->finBloque($columnaPorcentaje, $columnasInicio, $hoja);
        $aniosFila = $hoja[$filaAnios] ?? [];
        $valoresRezago = $hoja[$filaRezago] ?? [];
        $poblacionPorAnio = [];
        $porcentajePorAnio = [];

        for ($columna = $columnaPoblacion; $columna <= $finPoblacion; $columna++) {
            $anio = $this->anioValido($aniosFila[$columna] ?? null);

            if ($anio === null) {
                continue;
            }

            $valor = $valoresRezago[$columna] ?? null;

            if (!is_numeric($valor)) {
                throw new RuntimeException('Existe un valor de población no disponible en rezago educativo.');
            }

            $poblacionPorAnio[$anio] = (float)$valor;
        }

        for ($columna = $columnaPorcentaje; $columna <= $finPorcentaje; $columna++) {
            $anio = $this->anioValido($aniosFila[$columna] ?? null);

            if ($anio === null) {
                continue;
            }

            $valor = $valoresRezago[$columna] ?? null;

            if (!is_numeric($valor)) {
                throw new RuntimeException('Existe un porcentaje no disponible en rezago educativo.');
            }

            $porcentajePorAnio[$anio] = (float)$valor;
        }

        ksort($poblacionPorAnio);
        ksort($porcentajePorAnio);

        if (array_keys($poblacionPorAnio) !== array_keys($porcentajePorAnio) || empty($poblacionPorAnio)) {
            throw new RuntimeException('Los años de población y porcentaje no coinciden en rezago educativo.');
        }

        $multiplicador = $this->detectarMultiplicadorPoblacion($hoja, $filaCabeceras);
        $periodos = [];

        foreach ($poblacionPorAnio as $anio => $cantidadBase) {
            $cantidadPersonas = (int)round($cantidadBase * $multiplicador);
            $porcentaje = $porcentajePorAnio[$anio];

            if ($cantidadPersonas < 0 || $porcentaje < 0 || $porcentaje > 100) {
                throw new RuntimeException('El tabulado contiene valores fuera de rango.');
            }

            $periodos[(int)$anio] = [
                'cantidad_personas' => $cantidadPersonas,
                'porcentaje' => round($porcentaje, 2)
            ];
        }

        $clave = $this->clavePorNombre($nombreGeografia);

        if ($clave === null) {
            throw new RuntimeException('No fue posible identificar una entidad federativa del archivo.');
        }

        return [
            'clave_geografica' => $clave,
            'nombre' => $this->nombreCanonicoPorClave($clave),
            'periodos' => $periodos
        ];
    }

    private function detectarMultiplicadorPoblacion(array $hoja, int $filaCabeceras): int
    {
        for ($fila = max(1, $filaCabeceras - 5); $fila < $filaCabeceras; $fila++) {
            foreach (($hoja[$fila] ?? []) as $valor) {
                $comparable = $this->textoComparable($valor);

                if (str_contains($comparable, 'millones de personas')) {
                    return 1000000;
                }

                if (str_contains($comparable, 'miles de personas')) {
                    return 1000;
                }

                if (str_contains($comparable, 'personas')) {
                    return 1;
                }
            }
        }

        throw new RuntimeException('No fue posible identificar la unidad de población del tabulado.');
    }

    private function finBloque(int $inicio, array $iniciosCabeceras, array $hoja): int
    {
        sort($iniciosCabeceras, SORT_NUMERIC);

        foreach ($iniciosCabeceras as $columna) {
            if ($columna > $inicio) {
                return $columna - 1;
            }
        }

        $maxima = 0;

        foreach ($hoja as $fila) {
            if (!empty($fila)) {
                $maxima = max($maxima, max(array_keys($fila)));
            }
        }

        return $maxima;
    }

    private function anioValido($valor): ?int
    {
        if (!is_numeric($valor)) {
            return null;
        }

        $anio = (int)round((float)$valor);
        return $anio >= 2000 && $anio <= 2100 ? $anio : null;
    }

    private function clavePorNombre(string $nombre): ?string
    {
        $buscado = $this->textoComparable($nombre);

        foreach ($this->mapaEntidades as $nombreMapa => $clave) {
            if ($this->textoComparable($nombreMapa) === $buscado) {
                return $clave;
            }
        }

        return null;
    }

    private function nombreCanonicoPorClave(string $clave): string
    {
        $preferidos = [
            '00' => 'Estados Unidos Mexicanos',
            '01' => 'Aguascalientes',
            '02' => 'Baja California',
            '03' => 'Baja California Sur',
            '04' => 'Campeche',
            '05' => 'Coahuila de Zaragoza',
            '06' => 'Colima',
            '07' => 'Chiapas',
            '08' => 'Chihuahua',
            '09' => 'Ciudad de México',
            '10' => 'Durango',
            '11' => 'Guanajuato',
            '12' => 'Guerrero',
            '13' => 'Hidalgo',
            '14' => 'Jalisco',
            '15' => 'México',
            '16' => 'Michoacán de Ocampo',
            '17' => 'Morelos',
            '18' => 'Nayarit',
            '19' => 'Nuevo León',
            '20' => 'Oaxaca',
            '21' => 'Puebla',
            '22' => 'Querétaro',
            '23' => 'Quintana Roo',
            '24' => 'San Luis Potosí',
            '25' => 'Sinaloa',
            '26' => 'Sonora',
            '27' => 'Tabasco',
            '28' => 'Tamaulipas',
            '29' => 'Tlaxcala',
            '30' => 'Veracruz de Ignacio de la Llave',
            '31' => 'Yucatán',
            '32' => 'Zacatecas'
        ];

        return $preferidos[$clave] ?? '';
    }

    private function obtenerRutasHojas(ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relsXml === false) {
            throw new RuntimeException('El archivo XLSX no contiene la estructura de libro esperada.');
        }

        $workbook = $this->cargarXml($workbookXml);
        $relaciones = $this->cargarXml($relsXml);
        $xpathWorkbook = new DOMXPath($workbook);
        $xpathWorkbook->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xpathRelaciones = new DOMXPath($relaciones);
        $xpathRelaciones->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $destinos = [];

        foreach ($xpathRelaciones->query('//r:Relationship') as $relacion) {
            if (!$relacion instanceof DOMElement) {
                continue;
            }

            $id = $relacion->getAttribute('Id');
            $destino = $relacion->getAttribute('Target');

            if ($id !== '' && $destino !== '') {
                $destinos[$id] = $destino;
            }
        }

        $hojas = [];

        foreach ($xpathWorkbook->query('//x:sheets/x:sheet') as $hoja) {
            if (!$hoja instanceof DOMElement) {
                continue;
            }

            $nombre = $this->normalizarEspacios($hoja->getAttribute('name'));
            $relId = $hoja->getAttributeNS(
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                'id'
            );

            if ($nombre === '' || $relId === '' || !isset($destinos[$relId])) {
                continue;
            }

            $destino = str_replace('\\', '/', $destinos[$relId]);
            $destino = ltrim($destino, '/');
            $ruta = str_starts_with($destino, 'xl/')
                ? $destino
                : 'xl/' . preg_replace('#^(\.\./)+#', '', $destino);
            $hojas[$nombre] = $ruta;
        }

        return $hojas;
    }

    private function obtenerSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $documento = $this->cargarXml($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $cadenas = [];

        foreach ($xpath->query('//x:si') as $si) {
            $partes = [];

            foreach ($xpath->query('.//x:t', $si) as $texto) {
                $partes[] = $texto->textContent;
            }

            $cadenas[] = implode('', $partes);
        }

        return $cadenas;
    }

    private function leerHoja(ZipArchive $zip, string $ruta, array $sharedStrings): array
    {
        $xml = $zip->getFromName($ruta);

        if ($xml === false || strlen($xml) > 20 * 1024 * 1024) {
            throw new RuntimeException('No fue posible leer una de las hojas del archivo.');
        }

        $documento = $this->cargarXml($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $filas = [];

        foreach ($xpath->query('//x:sheetData/x:row') as $fila) {
            if (!$fila instanceof DOMElement) {
                continue;
            }

            $numeroFila = (int)$fila->getAttribute('r');

            if ($numeroFila <= 0 || $numeroFila > 200) {
                continue;
            }

            foreach ($xpath->query('./x:c', $fila) as $celda) {
                if (!$celda instanceof DOMElement) {
                    continue;
                }

                $referencia = $celda->getAttribute('r');
                $columna = $this->indiceColumna($referencia);

                if ($columna <= 0 || $columna > 100) {
                    continue;
                }

                $filas[$numeroFila][$columna] = $this->valorCelda($celda, $xpath, $sharedStrings);
            }
        }

        return $filas;
    }

    private function valorCelda(DOMElement $celda, DOMXPath $xpath, array $sharedStrings)
    {
        $tipo = $celda->getAttribute('t');

        if ($tipo === 'inlineStr') {
            $partes = [];

            foreach ($xpath->query('./x:is//x:t', $celda) as $texto) {
                $partes[] = $texto->textContent;
            }

            return implode('', $partes);
        }

        $valorNodo = $xpath->query('./x:v', $celda)->item(0);

        if (!$valorNodo) {
            return null;
        }

        $valor = $valorNodo->textContent;

        if ($tipo === 's') {
            return $sharedStrings[(int)$valor] ?? '';
        }

        if ($tipo === 'str') {
            return $valor;
        }

        if ($tipo === 'b') {
            return $valor === '1';
        }

        return is_numeric($valor) ? (float)$valor : $valor;
    }

    private function indiceColumna(string $referencia): int
    {
        if (!preg_match('/^([A-Z]+)\d+$/i', $referencia, $coincidencias)) {
            return 0;
        }

        $letras = strtoupper($coincidencias[1]);
        $indice = 0;

        for ($i = 0, $longitud = strlen($letras); $i < $longitud; $i++) {
            $indice = ($indice * 26) + (ord($letras[$i]) - 64);
        }

        return $indice;
    }

    private function normalizarEspacios($valor): string
    {
        $texto = trim((string)$valor);
        return preg_replace('/\s+/u', ' ', $texto) ?? $texto;
    }

    private function textoComparable($valor): string
    {
        $textoBase = $this->normalizarEspacios($valor);
        $texto = function_exists('mb_strtolower')
            ? mb_strtolower($textoBase, 'UTF-8')
            : strtolower($textoBase);
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u'
        ]);
        return $texto;
    }

    private function cargarXml(string $xml): DOMDocument
    {
        $anterior = libxml_use_internal_errors(true);
        $documento = new DOMDocument();
        $cargado = $documento->loadXML(
            $xml,
            LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        if (!$cargado) {
            throw new RuntimeException('El archivo contiene XML inválido.');
        }

        return $documento;
    }

    private function error(string $mensaje): array
    {
        return [
            'ok' => false,
            'mensaje' => $mensaje,
            'datos' => []
        ];
    }
}
