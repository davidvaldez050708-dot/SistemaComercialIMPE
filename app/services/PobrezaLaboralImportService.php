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

    private ?array $mapaEntidadesComparables = null;

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

            if (empty($hojas)) {
                return $this->error('El archivo XLSX no contiene hojas legibles.');
            }

            $sharedStrings = $this->obtenerSharedStrings($zip);
            $tabulados = $this->resolverTabulados(
                $zip,
                $hojas,
                $sharedStrings
            );
            $cuadroIngreso = $tabulados['ingreso'];
            $cuadroPobreza = $tabulados['pobreza'];

            $periodoIngreso = $this->obtenerUltimoPeriodo($cuadroIngreso);
            $periodoPobreza = $this->obtenerUltimoPeriodo($cuadroPobreza);

            if ($periodoIngreso === null || $periodoPobreza === null) {
                return $this->error('No fue posible identificar el último periodo disponible en los tabulados.');
            }

            if (
                $periodoIngreso['anio'] !== $periodoPobreza['anio'] ||
                $periodoIngreso['trimestre'] !== $periodoPobreza['trimestre']
            ) {
                return $this->error('Los tabulados de ingreso y pobreza laboral no corresponden al mismo periodo.');
            }

            $ingresos = $this->extraerIngresoReal($cuadroIngreso, $periodoIngreso['fila']);
            $pobreza = $this->extraerPobrezaLaboral($cuadroPobreza, $periodoPobreza['fila']);
            $clavesEsperadas = array_map(
                static fn ($numero) => str_pad((string)$numero, 2, '0', STR_PAD_LEFT),
                range(0, 32)
            );

            ksort($ingresos);
            ksort($pobreza);

            foreach ($clavesEsperadas as $clave) {
                if (!array_key_exists($clave, $ingresos) || !array_key_exists($clave, $pobreza)) {
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
                'hojas_detectadas' => [
                    'ingreso' => $tabulados['nombre_ingreso'],
                    'pobreza' => $tabulados['nombre_pobreza']
                ],
                'datos' => $datos
            ];
        } catch (RuntimeException $error) {
            error_log($error->getMessage());
            return $this->error(
                'No fue posible reconocer con seguridad la estructura actual del XLSX de INEGI. '
                . 'No se modificó información. Detalle: '
                . $error->getMessage()
            );
        } catch (Throwable $error) {
            error_log($error->getMessage());
            return $this->error('No fue posible validar la estructura del archivo XLSX. No se modificó información.');
        } finally {
            $zip->close();
        }
    }

    private function resolverTabulados(
        ZipArchive $zip,
        array $hojas,
        array $sharedStrings
    ): array {
        $rutaIngreso = $this->buscarRutaHojaPorNombre($hojas, self::HOJA_INGRESO);
        $rutaPobreza = $this->buscarRutaHojaPorNombre($hojas, self::HOJA_POBREZA);
        $hojasLeidas = [];

        $leer = function (string $nombre, string $ruta) use ($zip, $sharedStrings, &$hojasLeidas): array {
            if (!isset($hojasLeidas[$ruta])) {
                $hojasLeidas[$ruta] = $this->leerHoja($zip, $ruta, $sharedStrings);
            }

            return $hojasLeidas[$ruta];
        };

        $nombreIngreso = $rutaIngreso !== null
            ? $this->nombreHojaPorRuta($hojas, $rutaIngreso)
            : '';
        $nombrePobreza = $rutaPobreza !== null
            ? $this->nombreHojaPorRuta($hojas, $rutaPobreza)
            : '';
        $ingreso = $rutaIngreso !== null
            ? $leer($nombreIngreso, $rutaIngreso)
            : null;
        $pobreza = $rutaPobreza !== null
            ? $leer($nombrePobreza, $rutaPobreza)
            : null;

        if ($ingreso !== null && $pobreza !== null && $rutaIngreso !== $rutaPobreza) {
            return [
                'ingreso' => $ingreso,
                'pobreza' => $pobreza,
                'nombre_ingreso' => $nombreIngreso,
                'nombre_pobreza' => $nombrePobreza
            ];
        }

        $candidatosIngreso = [];
        $candidatosPobreza = [];

        foreach ($hojas as $nombreHoja => $rutaHoja) {
            $hoja = $leer($nombreHoja, $rutaHoja);
            $puntajeIngreso = $this->puntuarHojaIngreso($nombreHoja, $hoja);
            $puntajePobreza = $this->puntuarHojaPobreza($nombreHoja, $hoja);

            if ($puntajeIngreso >= 6) {
                $candidatosIngreso[] = [
                    'nombre' => $nombreHoja,
                    'ruta' => $rutaHoja,
                    'hoja' => $hoja,
                    'puntaje' => $puntajeIngreso
                ];
            }

            if ($puntajePobreza >= 6) {
                $candidatosPobreza[] = [
                    'nombre' => $nombreHoja,
                    'ruta' => $rutaHoja,
                    'hoja' => $hoja,
                    'puntaje' => $puntajePobreza
                ];
            }
        }

        usort($candidatosIngreso, static fn ($a, $b) => $b['puntaje'] <=> $a['puntaje']);
        usort($candidatosPobreza, static fn ($a, $b) => $b['puntaje'] <=> $a['puntaje']);

        if ($ingreso === null) {
            $candidato = $this->primerCandidatoDistinto($candidatosIngreso, $rutaPobreza);

            if ($candidato !== null) {
                $ingreso = $candidato['hoja'];
                $rutaIngreso = $candidato['ruta'];
                $nombreIngreso = $candidato['nombre'];
            }
        }

        if ($pobreza === null) {
            $candidato = $this->primerCandidatoDistinto($candidatosPobreza, $rutaIngreso);

            if ($candidato !== null) {
                $pobreza = $candidato['hoja'];
                $rutaPobreza = $candidato['ruta'];
                $nombrePobreza = $candidato['nombre'];
            }
        }

        if (
            !is_array($ingreso) ||
            !is_array($pobreza) ||
            $rutaIngreso === null ||
            $rutaPobreza === null ||
            $rutaIngreso === $rutaPobreza
        ) {
            throw new RuntimeException(
                'No se localizaron dos tabulados distintos para ingreso laboral real y pobreza laboral.'
            );
        }

        return [
            'ingreso' => $ingreso,
            'pobreza' => $pobreza,
            'nombre_ingreso' => $nombreIngreso,
            'nombre_pobreza' => $nombrePobreza
        ];
    }

    private function buscarRutaHojaPorNombre(array $hojas, string $nombreBuscado): ?string
    {
        $buscado = $this->textoComparable($nombreBuscado);

        foreach ($hojas as $nombre => $ruta) {
            if ($this->textoComparable($nombre) === $buscado) {
                return $ruta;
            }
        }

        return null;
    }

    private function nombreHojaPorRuta(array $hojas, string $rutaBuscada): string
    {
        foreach ($hojas as $nombre => $ruta) {
            if ($ruta === $rutaBuscada) {
                return $nombre;
            }
        }

        return '';
    }

    private function primerCandidatoDistinto(array $candidatos, ?string $rutaExcluida): ?array
    {
        foreach ($candidatos as $candidato) {
            if (($candidato['ruta'] ?? null) !== $rutaExcluida) {
                return $candidato;
            }
        }

        return null;
    }

    private function puntuarHojaIngreso(string $nombreHoja, array $hoja): int
    {
        $puntaje = 0;
        $nombreComparable = $this->textoComparable($nombreHoja);
        $texto = $this->textoResumenHoja($hoja);
        $filaEntidades = $this->detectarFilaEntidades($hoja, false);

        if (str_contains($nombreComparable, 'cuadro 5')) {
            $puntaje += 6;
        }

        if (str_contains($texto, 'ingreso laboral real')) {
            $puntaje += 5;
        }

        if (str_contains($texto, 'deflactado') && str_contains($texto, 'inpc')) {
            $puntaje += 5;
        }

        if (str_contains($texto, 'per capita')) {
            $puntaje += 2;
        }

        if ($filaEntidades !== null && count($filaEntidades['territorios']) >= 30) {
            $puntaje += 2;
        }

        return $puntaje;
    }

    private function puntuarHojaPobreza(string $nombreHoja, array $hoja): int
    {
        $puntaje = 0;
        $nombreComparable = $this->textoComparable($nombreHoja);
        $texto = $this->textoResumenHoja($hoja);
        $filaEntidades = $this->detectarFilaEntidades($hoja, false);

        if (str_contains($nombreComparable, 'cuadro 9')) {
            $puntaje += 6;
        }

        if (str_contains($texto, 'pobreza laboral')) {
            $puntaje += 5;
        }

        if (
            str_contains($texto, 'ingreso laboral') &&
            str_contains($texto, 'canasta alimentaria')
        ) {
            $puntaje += 6;
        }

        if (str_contains($texto, 'ingreso laboral inferior')) {
            $puntaje += 3;
        }

        if ($filaEntidades !== null && count($filaEntidades['territorios']) >= 30) {
            $puntaje += 2;
        }

        return $puntaje;
    }

    private function textoResumenHoja(array $hoja): string
    {
        $partes = [];
        $filasLeidas = 0;

        foreach ($hoja as $celdas) {
            foreach ($celdas as $valor) {
                $texto = $this->textoComparable($valor);

                if ($texto !== '') {
                    $partes[] = $texto;
                }
            }

            $filasLeidas++;

            if ($filasLeidas >= 20) {
                break;
            }
        }

        return implode(' ', $partes);
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

        ksort($filas);
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

    private function obtenerUltimoPeriodo(array $hoja): ?array
    {
        $columnas = $this->detectarColumnasPeriodo($hoja);

        if ($columnas === null) {
            return null;
        }

        $anioActual = null;
        $periodos = [];

        foreach ($hoja as $numeroFila => $celdas) {
            if ((int)$numeroFila <= $columnas['fila_encabezado']) {
                continue;
            }

            $anioCandidato = $this->anioValido($celdas[$columnas['columna_anio']] ?? null);

            if ($anioCandidato !== null) {
                $anioActual = $anioCandidato;
            }

            $trimestre = $this->trimestreNumero(
                $celdas[$columnas['columna_trimestre']] ?? null
            );

            if ($anioActual === null || $trimestre === null) {
                continue;
            }

            $periodos[] = [
                'anio' => $anioActual,
                'trimestre' => $trimestre,
                'trimestre_romano' => $this->trimestreRomano($trimestre),
                'fila' => (int)$numeroFila
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

    private function detectarColumnasPeriodo(array $hoja): ?array
    {
        foreach ($hoja as $numeroFila => $celdas) {
            $columnaAnio = null;
            $columnaTrimestre = null;

            foreach ($celdas as $columna => $valor) {
                $comparable = $this->textoComparable($valor);

                if ($comparable === 'ano' || $comparable === 'anio') {
                    $columnaAnio = (int)$columna;
                }

                if (
                    $comparable === 'trimestre' ||
                    $comparable === 'periodo' ||
                    $comparable === 'periodo trimestral'
                ) {
                    $columnaTrimestre = (int)$columna;
                }
            }

            if ($columnaAnio !== null && $columnaTrimestre !== null) {
                return [
                    'fila_encabezado' => (int)$numeroFila,
                    'columna_anio' => $columnaAnio,
                    'columna_trimestre' => $columnaTrimestre
                ];
            }
        }

        $conteos = [];

        foreach ($hoja as $numeroFila => $celdas) {
            foreach ($celdas as $columnaAnio => $valorAnio) {
                if ($this->anioValido($valorAnio) === null) {
                    continue;
                }

                foreach ($celdas as $columnaTrimestre => $valorTrimestre) {
                    if ((int)$columnaAnio === (int)$columnaTrimestre) {
                        continue;
                    }

                    if ($this->trimestreNumero($valorTrimestre) === null) {
                        continue;
                    }

                    $distancia = abs((int)$columnaTrimestre - (int)$columnaAnio);

                    if ($distancia > 3) {
                        continue;
                    }

                    $clave = (int)$columnaAnio . ':' . (int)$columnaTrimestre;
                    $conteos[$clave] = ($conteos[$clave] ?? 0) + 1;
                }
            }
        }

        if (empty($conteos)) {
            return null;
        }

        arsort($conteos, SORT_NUMERIC);
        $clave = (string)array_key_first($conteos);

        if (($conteos[$clave] ?? 0) < 2) {
            return null;
        }

        [$columnaAnio, $columnaTrimestre] = array_map('intval', explode(':', $clave));

        return [
            'fila_encabezado' => 0,
            'columna_anio' => $columnaAnio,
            'columna_trimestre' => $columnaTrimestre
        ];
    }

    private function extraerIngresoReal(array $hoja, int $filaDatos): array
    {
        $encabezado = $this->detectarFilaEntidades($hoja, true);
        $filaValores = $hoja[$filaDatos] ?? [];

        if ($encabezado === null || empty($filaValores)) {
            throw new RuntimeException('No se localizaron las geografías o la fila del último periodo en el tabulado de ingreso.');
        }

        $territorios = $encabezado['territorios'];
        usort($territorios, static fn ($a, $b) => $a['columna'] <=> $b['columna']);
        $maxColumna = max(array_keys($filaValores + ($hoja[$encabezado['fila']] ?? [])));
        $resultado = [];

        foreach ($territorios as $indice => $territorio) {
            $inicio = $territorio['columna'];
            $fin = isset($territorios[$indice + 1])
                ? $territorios[$indice + 1]['columna'] - 1
                : $maxColumna;
            $candidatos = [];

            for ($columna = $inicio; $columna <= $fin; $columna++) {
                $textoColumna = $this->textoEncabezadoColumna(
                    $hoja,
                    $columna,
                    $encabezado['fila'],
                    min($encabezado['fila'] + 8, $filaDatos - 1)
                );
                $puntaje = $this->puntuarColumnaIngreso($textoColumna);

                if ($puntaje > 0) {
                    $candidatos[] = [
                        'columna' => $columna,
                        'puntaje' => $puntaje
                    ];
                }
            }

            if (empty($candidatos)) {
                throw new RuntimeException(
                    'No se encontró con seguridad la columna de ingreso laboral real para '
                    . $territorio['nombre'] . '.'
                );
            }

            usort($candidatos, static function ($a, $b) {
                $comparacion = $b['puntaje'] <=> $a['puntaje'];
                return $comparacion !== 0
                    ? $comparacion
                    : ($a['columna'] <=> $b['columna']);
            });

            $mejor = $candidatos[0];

            if ($mejor['puntaje'] < 5) {
                throw new RuntimeException(
                    'El encabezado de ingreso laboral real cambió para '
                    . $territorio['nombre'] . '.'
                );
            }

            if (
                isset($candidatos[1]) &&
                $candidatos[1]['puntaje'] === $mejor['puntaje']
            ) {
                throw new RuntimeException(
                    'El tabulado contiene más de una columna posible de ingreso laboral real para '
                    . $territorio['nombre'] . '.'
                );
            }

            $valor = $filaValores[$mejor['columna']] ?? null;

            if (!is_numeric($valor)) {
                throw new RuntimeException('El tabulado de ingreso contiene un valor no disponible en el último periodo.');
            }

            $resultado[$territorio['clave']] = (float)$valor;
        }

        if (count($resultado) !== 33) {
            throw new RuntimeException('El tabulado de ingreso no contiene las 32 entidades y la referencia nacional completas.');
        }

        ksort($resultado);
        return $resultado;
    }

    private function extraerPobrezaLaboral(array $hoja, int $filaDatos): array
    {
        $encabezado = $this->detectarFilaEntidades($hoja, true);
        $filaValores = $hoja[$filaDatos] ?? [];

        if ($encabezado === null || empty($filaValores)) {
            throw new RuntimeException('No se localizaron las geografías o la fila del último periodo en el tabulado de pobreza laboral.');
        }

        $resultado = [];

        foreach ($encabezado['territorios'] as $territorio) {
            $valor = $filaValores[$territorio['columna']] ?? null;

            if (!is_numeric($valor)) {
                throw new RuntimeException(
                    'El tabulado de pobreza laboral contiene un valor no disponible para '
                    . $territorio['nombre'] . '.'
                );
            }

            $resultado[$territorio['clave']] = (float)$valor;
        }

        if (count($resultado) !== 33) {
            throw new RuntimeException('El tabulado de pobreza laboral no contiene las 32 entidades y la referencia nacional completas.');
        }

        ksort($resultado);
        return $resultado;
    }

    private function detectarFilaEntidades(array $hoja, bool $exigirCompleta): ?array
    {
        $mejor = null;

        foreach ($hoja as $numeroFila => $celdas) {
            $territorios = [];
            $clavesVistas = [];

            foreach ($celdas as $columna => $valor) {
                $clave = $this->clavePorNombre($valor);

                if ($clave === null) {
                    continue;
                }

                if (isset($clavesVistas[$clave])) {
                    continue;
                }

                $clavesVistas[$clave] = true;
                $territorios[] = [
                    'columna' => (int)$columna,
                    'nombre' => $this->normalizarEspacios($valor),
                    'clave' => $clave
                ];
            }

            $cantidad = count($territorios);

            if ($mejor === null || $cantidad > count($mejor['territorios'])) {
                $mejor = [
                    'fila' => (int)$numeroFila,
                    'territorios' => $territorios
                ];
            }
        }

        if ($mejor === null) {
            return null;
        }

        $minimo = $exigirCompleta ? 33 : 20;

        if (count($mejor['territorios']) < $minimo) {
            return null;
        }

        if ($exigirCompleta && count($mejor['territorios']) !== 33) {
            throw new RuntimeException('No se reconocieron exactamente las 32 entidades y la referencia nacional en los encabezados.');
        }

        return $mejor;
    }

    private function textoEncabezadoColumna(
        array $hoja,
        int $columna,
        int $filaInicio,
        int $filaFin
    ): string {
        if ($filaFin < $filaInicio) {
            $filaFin = $filaInicio;
        }

        $partes = [];

        for ($fila = $filaInicio; $fila <= $filaFin; $fila++) {
            $texto = $this->textoComparable($hoja[$fila][$columna] ?? '');

            if ($texto !== '') {
                $partes[] = $texto;
            }
        }

        return implode(' ', array_unique($partes));
    }

    private function puntuarColumnaIngreso(string $texto): int
    {
        $texto = $this->textoComparable($texto);
        $puntaje = 0;

        if (str_contains($texto, 'deflactado') && str_contains($texto, 'inpc')) {
            $puntaje += 8;
        }

        if (str_contains($texto, 'ingreso laboral real')) {
            $puntaje += 6;
        }

        if (str_contains($texto, 'ingreso real')) {
            $puntaje += 4;
        }

        if (str_contains($texto, 'per capita')) {
            $puntaje += 3;
        }

        if (str_contains($texto, 'pesos')) {
            $puntaje += 1;
        }

        if (str_contains($texto, 'nominal') && !str_contains($texto, 'real')) {
            $puntaje -= 6;
        }

        return max(0, $puntaje);
    }

    private function clavePorNombre($valor): ?string
    {
        $comparable = $this->textoComparable($valor);

        if ($comparable === '') {
            return null;
        }

        if ($this->mapaEntidadesComparables === null) {
            $mapa = [];

            foreach ($this->mapaEntidades as $nombre => $clave) {
                $mapa[$this->textoComparable($nombre)] = $clave;
            }

            $alias = [
                'coahuila' => '05',
                'cdmx' => '09',
                'estado de mexico' => '15',
                'michoacan' => '16',
                'veracruz' => '30'
            ];

            foreach ($alias as $nombre => $clave) {
                $mapa[$this->textoComparable($nombre)] = $clave;
            }

            $this->mapaEntidadesComparables = $mapa;
        }

        return $this->mapaEntidadesComparables[$comparable] ?? null;
    }

    private function anioValido($valor): ?int
    {
        if (!is_numeric($valor)) {
            return null;
        }

        $anio = (int)$valor;
        return $anio >= 2000 && $anio <= 2100 ? $anio : null;
    }

    private function trimestreNumero($valor): ?int
    {
        if (is_numeric($valor)) {
            $numero = (int)$valor;
            return $numero >= 1 && $numero <= 4 ? $numero : null;
        }

        $texto = $this->textoComparable($valor);
        $mapa = [
            'i' => 1,
            'ii' => 2,
            'iii' => 3,
            'iv' => 4,
            '1t' => 1,
            '2t' => 2,
            '3t' => 3,
            '4t' => 4,
            't1' => 1,
            't2' => 2,
            't3' => 3,
            't4' => 4,
            'primer trimestre' => 1,
            'primero trimestre' => 1,
            'segundo trimestre' => 2,
            'tercer trimestre' => 3,
            'cuarto trimestre' => 4
        ];

        if (isset($mapa[$texto])) {
            return $mapa[$texto];
        }

        if (preg_match('/^(?:trimestre )?([1-4])$/', $texto, $coincidencias)) {
            return (int)$coincidencias[1];
        }

        return null;
    }

    private function trimestreRomano(int $trimestre): string
    {
        return [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV'][$trimestre] ?? '';
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
        $texto = $this->normalizarEspacios($valor);

        if ($texto === '') {
            return '';
        }

        $texto = strtr($texto, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'
        ]);

        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);

            if ($ascii !== false) {
                $texto = $ascii;
            }
        }

        $texto = strtolower($texto);
        $texto = preg_replace('/[^a-z0-9]+/', ' ', $texto) ?? $texto;
        return trim(preg_replace('/\s+/', ' ', $texto) ?? $texto);
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
