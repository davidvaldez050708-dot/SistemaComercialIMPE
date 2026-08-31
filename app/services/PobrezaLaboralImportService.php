<?php

class PobrezaLaboralImportService
{
    private const MAX_ARCHIVO_BYTES = 8388608;
    private const HOJA_INGRESO = 'Cuadro 5';
    private const HOJA_POBREZA = 'Cuadro 9';

    private array $mapaEntidades = [
        'Estados Unidos Mexicanos' => '00',
        'Aguascalientes' => '01',
        'Baja California' => '02',
        'Baja California Sur' => '03',
        'Campeche' => '04',
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
        'Veracruz de Ignacio de la Llave' => '30',
        'Yucatán' => '31',
        'Zacatecas' => '32'
    ];

    public function leerArchivo(string $rutaArchivo): array
    {
        $rutaArchivo = trim($rutaArchivo);

        if (
            $rutaArchivo === '' ||
            !is_file($rutaArchivo) ||
            !is_readable($rutaArchivo)
        ) {
            return $this->error('No fue posible leer el archivo seleccionado.');
        }

        if (strtolower(pathinfo($rutaArchivo, PATHINFO_EXTENSION)) !== 'xlsx') {
            return $this->error('El archivo debe estar en formato XLSX.');
        }

        $tamano = filesize($rutaArchivo);

        if ($tamano === false || $tamano <= 0 || $tamano > self::MAX_ARCHIVO_BYTES) {
            return $this->error('El archivo XLSX no tiene un tamaño válido.');
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

            if (!isset($hojas[self::HOJA_INGRESO], $hojas[self::HOJA_POBREZA])) {
                return $this->error('El archivo no contiene los tabulados requeridos: Cuadro 5 y Cuadro 9.');
            }

            $sharedStrings = $this->obtenerSharedStrings($zip);
            $cuadroIngreso = $this->leerHoja(
                $zip,
                $hojas[self::HOJA_INGRESO],
                $sharedStrings
            );
            $cuadroPobreza = $this->leerHoja(
                $zip,
                $hojas[self::HOJA_POBREZA],
                $sharedStrings
            );

            $periodoIngreso = $this->obtenerUltimoPeriodo($cuadroIngreso, 8);
            $periodoPobreza = $this->obtenerUltimoPeriodo($cuadroPobreza, 7);

            if ($periodoIngreso === null || $periodoPobreza === null) {
                return $this->error('No fue posible identificar el último periodo disponible en los tabulados.');
            }

            if (
                $periodoIngreso['anio'] !== $periodoPobreza['anio'] ||
                $periodoIngreso['trimestre'] !== $periodoPobreza['trimestre']
            ) {
                return $this->error('Cuadro 5 y Cuadro 9 no corresponden al mismo periodo.');
            }

            $ingresos = $this->extraerIngresoReal($cuadroIngreso, $periodoIngreso['fila']);
            $pobreza = $this->extraerPobrezaLaboral($cuadroPobreza, $periodoPobreza['fila']);
            $clavesEsperadas = array_map(
                static fn ($numero) => str_pad((string)$numero, 2, '0', STR_PAD_LEFT),
                range(0, 32)
            );

            if (array_keys($ingresos) !== array_keys($pobreza)) {
                ksort($ingresos);
                ksort($pobreza);
            }

            foreach ($clavesEsperadas as $clave) {
                if (!isset($ingresos[$clave], $pobreza[$clave])) {
                    return $this->error('El archivo no contiene las 32 entidades y la referencia nacional completas.');
                }
            }

            if (count($ingresos) !== 33 || count($pobreza) !== 33) {
                return $this->error('El archivo debe contener exactamente 32 entidades y la referencia nacional.');
            }

            $nombresPorClave = array_flip($this->mapaEntidades);
            $datos = [];

            foreach ($clavesEsperadas as $clave) {
                $ingreso = $ingresos[$clave];
                $porcentajePobreza = $pobreza[$clave];

                if (!is_numeric($ingreso) || (float)$ingreso <= 0 || (float)$ingreso > 1000000) {
                    return $this->error('El archivo contiene un valor de ingreso laboral no válido.');
                }

                if (
                    !is_numeric($porcentajePobreza) ||
                    (float)$porcentajePobreza < 0 ||
                    (float)$porcentajePobreza > 100
                ) {
                    return $this->error('El archivo contiene un porcentaje de pobreza laboral no válido.');
                }

                $datos[] = [
                    'clave_geografica' => $clave,
                    'nombre' => $nombresPorClave[$clave] ?? '',
                    'ingreso_laboral_real_per_capita' => round((float)$ingreso, 2),
                    'pobreza_laboral' => round((float)$porcentajePobreza, 2)
                ];
            }

            return [
                'ok' => true,
                'periodo' => [
                    'anio' => $periodoIngreso['anio'],
                    'trimestre' => $periodoIngreso['trimestre'],
                    'trimestre_romano' => $periodoIngreso['trimestre_romano']
                ],
                'fuente' => 'INEGI - Pobreza Laboral (PL)',
                'archivo_origen' => basename($rutaArchivo),
                'total_geografias' => count($datos),
                'datos' => $datos
            ];
        } catch (Throwable $error) {
            error_log($error->getMessage());
            return $this->error('No fue posible validar la estructura del archivo XLSX.');
        } finally {
            $zip->close();
        }
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
        $xpathWorkbook->registerNamespace(
            'x',
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
        );
        $xpathRelaciones = new DOMXPath($relaciones);
        $xpathRelaciones->registerNamespace(
            'r',
            'http://schemas.openxmlformats.org/package/2006/relationships'
        );

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

            if (str_starts_with($destino, 'xl/')) {
                $ruta = $destino;
            } else {
                $ruta = 'xl/' . preg_replace('#^(\.\./)+#', '', $destino);
            }

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
        $xpath->registerNamespace(
            'x',
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
        );
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
            throw new RuntimeException('No fue posible leer uno de los tabulados requeridos.');
        }

        $documento = $this->cargarXml($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace(
            'x',
            'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
        );
        $filas = [];

        foreach ($xpath->query('//x:sheetData/x:row') as $fila) {
            if (!$fila instanceof DOMElement) {
                continue;
            }

            $numeroFila = (int)$fila->getAttribute('r');

            if ($numeroFila <= 0) {
                continue;
            }

            foreach ($xpath->query('./x:c', $fila) as $celda) {
                if (!$celda instanceof DOMElement) {
                    continue;
                }

                $referencia = $celda->getAttribute('r');
                $columna = $this->indiceColumna($referencia);

                if ($columna <= 0) {
                    continue;
                }

                $filas[$numeroFila][$columna] = $this->valorCelda(
                    $celda,
                    $xpath,
                    $sharedStrings
                );
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
            $indice = (int)$valor;
            return $sharedStrings[$indice] ?? '';
        }

        if ($tipo === 'str') {
            return $valor;
        }

        if ($tipo === 'b') {
            return $valor === '1';
        }

        if (is_numeric($valor)) {
            return (float)$valor;
        }

        return $valor;
    }

    private function obtenerUltimoPeriodo(array $hoja, int $filaInicio): ?array
    {
        $anioActual = null;
        $periodos = [];
        $mapaTrimestres = [
            'I' => 1,
            'II' => 2,
            'III' => 3,
            'IV' => 4
        ];

        if (empty($hoja)) {
            return null;
        }

        $ultimaFila = max(array_keys($hoja));

        for ($fila = $filaInicio; $fila <= $ultimaFila; $fila++) {
            $valorAnio = $hoja[$fila][1] ?? null;
            $valorTrimestre = strtoupper($this->normalizarEspacios($hoja[$fila][2] ?? ''));

            if (is_numeric($valorAnio)) {
                $anioCandidato = (int)$valorAnio;

                if ($anioCandidato >= 2000 && $anioCandidato <= 2100) {
                    $anioActual = $anioCandidato;
                }
            }

            if ($anioActual === null || !isset($mapaTrimestres[$valorTrimestre])) {
                continue;
            }

            $periodos[] = [
                'anio' => $anioActual,
                'trimestre' => $mapaTrimestres[$valorTrimestre],
                'trimestre_romano' => $valorTrimestre,
                'fila' => $fila
            ];
        }

        if (empty($periodos)) {
            return null;
        }

        usort($periodos, static function ($a, $b) {
            $comparacionAnio = $b['anio'] <=> $a['anio'];
            return $comparacionAnio !== 0
                ? $comparacionAnio
                : ($b['trimestre'] <=> $a['trimestre']);
        });

        return $periodos[0];
    }

    private function extraerIngresoReal(array $hoja, int $filaDatos): array
    {
        $encabezadosTerritorio = $hoja[6] ?? [];
        $subencabezados = $hoja[7] ?? [];
        $filaValores = $hoja[$filaDatos] ?? [];
        $columnasTerritorio = [];

        foreach ($encabezadosTerritorio as $columna => $nombre) {
            if ((int)$columna <= 2) {
                continue;
            }

            $nombreNormalizado = $this->normalizarEspacios($nombre);

            if ($nombreNormalizado === '' || !isset($this->mapaEntidades[$nombreNormalizado])) {
                continue;
            }

            $columnasTerritorio[] = [
                'columna' => (int)$columna,
                'nombre' => $nombreNormalizado,
                'clave' => $this->mapaEntidades[$nombreNormalizado]
            ];
        }

        if (count($columnasTerritorio) !== 33) {
            throw new RuntimeException('Cuadro 5 no contiene las geografías esperadas.');
        }

        usort($columnasTerritorio, static fn ($a, $b) => $a['columna'] <=> $b['columna']);
        $resultado = [];
        $maxColumna = max(array_keys($encabezadosTerritorio + $subencabezados + $filaValores));

        foreach ($columnasTerritorio as $indice => $territorio) {
            $inicio = $territorio['columna'];
            $fin = isset($columnasTerritorio[$indice + 1])
                ? $columnasTerritorio[$indice + 1]['columna'] - 1
                : $maxColumna;
            $columnaIngreso = null;

            for ($columna = $inicio; $columna <= $fin; $columna++) {
                $subtitulo = $this->normalizarEspacios($subencabezados[$columna] ?? '');

                if (
                    $subtitulo !== '' &&
                    stripos($subtitulo, 'deflactado con el INPC') !== false
                ) {
                    $columnaIngreso = $columna;
                    break;
                }
            }

            if ($columnaIngreso === null) {
                throw new RuntimeException('No se encontró la columna de ingreso real deflactado con INPC.');
            }

            $valor = $filaValores[$columnaIngreso] ?? null;

            if (!is_numeric($valor)) {
                throw new RuntimeException('Cuadro 5 contiene un valor no disponible en el último periodo.');
            }

            $resultado[$territorio['clave']] = (float)$valor;
        }

        ksort($resultado);
        return $resultado;
    }

    private function extraerPobrezaLaboral(array $hoja, int $filaDatos): array
    {
        $encabezados = $hoja[6] ?? [];
        $filaValores = $hoja[$filaDatos] ?? [];
        $resultado = [];

        foreach ($encabezados as $columna => $nombre) {
            if ((int)$columna <= 2) {
                continue;
            }

            $nombreNormalizado = $this->normalizarEspacios($nombre);

            if ($nombreNormalizado === '' || !isset($this->mapaEntidades[$nombreNormalizado])) {
                continue;
            }

            $valor = $filaValores[(int)$columna] ?? null;

            if (!is_numeric($valor)) {
                throw new RuntimeException('Cuadro 9 contiene un valor no disponible en el último periodo.');
            }

            $resultado[$this->mapaEntidades[$nombreNormalizado]] = (float)$valor;
        }

        if (count($resultado) !== 33) {
            throw new RuntimeException('Cuadro 9 no contiene las geografías esperadas.');
        }

        ksort($resultado);
        return $resultado;
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
