<?php

class InegiEducacionObjetivoService
{
    private const MAX_DESCARGA_BYTES = 67108864;
    private const CACHE_TTL_SEGUNDOS = 21600;

    private $enlacesIntercensal2025 = null;

    public function obtenerPorEstado(string $claveEstado): array
    {
        $claveEstado = str_pad(trim($claveEstado), 2, '0', STR_PAD_LEFT);

        if (!preg_match('/^(0[1-9]|[12][0-9]|3[0-2])$/', $claveEstado)) {
            return $this->respuestaError('La clave del Estado no es válida.');
        }

        $errores = [];

        foreach ($this->construirCandidatos($claveEstado) as $candidato) {
            $cache = $this->rutaCache($claveEstado, $candidato);
            $datosCache = $this->leerCache($cache);

            if (is_array($datosCache)) {
                return $datosCache;
            }

            $resultado = $this->descargarYProcesar($claveEstado, $candidato);

            if (($resultado['ok'] ?? false) === true) {
                $this->guardarCache($cache, $resultado);
                return $resultado;
            }

            $mensaje = trim((string)($resultado['mensaje'] ?? ''));
            if ($mensaje !== '') {
                $errores[$mensaje] = true;
            }
        }

        $detalle = '';
        if (!empty($errores)) {
            $detalle = ' ' . implode(' ', array_slice(array_keys($errores), 0, 2));
        }

        return $this->respuestaError(
            'No se encontró una fuente educativa oficial de INEGI compatible con el indicador requerido.' .
            $detalle
        );
    }

    private function construirCandidatos(string $claveEstado): array
    {
        $candidatos = [];

        foreach ($this->descubrirIntercensal2025($claveEstado) as $url) {
            $candidatos[] = [
                'id' => 'EIC2025_' . substr(sha1($url), 0, 12),
                'periodo' => 2025,
                'fuente' => 'INEGI - Encuesta Intercensal 2025',
                'producto' => 'EIC',
                'url' => $url,
                'prioridad' => 300
            ];
        }

        $anioActual = (int)date('Y');
        $decadaActual = (int)(floor($anioActual / 10) * 10);

        for ($anio = $decadaActual; $anio >= 2030; $anio -= 10) {
            $candidatos[] = [
                'id' => 'CPV' . $anio,
                'periodo' => $anio,
                'fuente' => 'INEGI - Censo de Población y Vivienda ' . $anio . ' (ITER)',
                'producto' => 'CPV',
                'url' => 'https://www.inegi.org.mx/contenidos/programas/ccpv/' . $anio .
                    '/datosabiertos/iter/iter_' . $claveEstado . '_cpv' . $anio . '_csv.zip',
                'prioridad' => 250
            ];
        }

        $candidatos[] = [
            'id' => 'CPV2020',
            'periodo' => 2020,
            'fuente' => 'INEGI - Censo de Población y Vivienda 2020 (ITER)',
            'producto' => 'CPV',
            'url' => 'https://www.inegi.org.mx/contenidos/programas/ccpv/2020/datosabiertos/iter/' .
                'iter_' . $claveEstado . '_cpv2020_csv.zip',
            'prioridad' => 200
        ];

        usort($candidatos, static function (array $a, array $b): int {
            $periodo = (int)($b['periodo'] ?? 0) <=> (int)($a['periodo'] ?? 0);
            if ($periodo !== 0) {
                return $periodo;
            }

            return (int)($b['prioridad'] ?? 0) <=> (int)($a['prioridad'] ?? 0);
        });

        return $candidatos;
    }

    private function descubrirIntercensal2025(string $claveEstado): array
    {
        if ((int)date('Y') < 2026) {
            return [];
        }

        if ($this->enlacesIntercensal2025 === null) {
            $this->enlacesIntercensal2025 = [];
            $paginas = [
                'https://www.inegi.org.mx/programas/intercensal/2025/',
                'https://www.inegi.org.mx/programas/eic/2025/'
            ];

            foreach ($paginas as $pagina) {
                $html = $this->consultarHtml($pagina);
                if ($html === null) {
                    continue;
                }

                foreach ($this->extraerEnlacesDescarga($html, $pagina) as $url) {
                    $this->enlacesIntercensal2025[$url] = $url;
                }
            }

            $this->enlacesIntercensal2025 = array_values($this->enlacesIntercensal2025);
        }

        if (empty($this->enlacesIntercensal2025)) {
            return [];
        }

        $nombreEstado = $this->slugEstado($claveEstado);
        $seleccionados = [];
        $nacionales = [];

        foreach ($this->enlacesIntercensal2025 as $url) {
            $normalizada = $this->normalizarTextoUrl($url);
            $coincideClave = (bool)preg_match(
                '/(?:^|[^0-9])' . preg_quote($claveEstado, '/') . '(?:[^0-9]|$)/',
                $normalizada
            );
            $coincideNombre = $nombreEstado !== '' && strpos($normalizada, $nombreEstado) !== false;

            if ($coincideClave || $coincideNombre) {
                $seleccionados[] = $url;
                continue;
            }

            if (
                strpos($normalizada, 'nacional') !== false ||
                strpos($normalizada, 'estados_unidos_mexicanos') !== false ||
                strpos($normalizada, 'eum') !== false
            ) {
                $nacionales[] = $url;
            }
        }

        return array_values(array_unique(array_merge($seleccionados, $nacionales)));
    }

    private function extraerEnlacesDescarga(string $html, string $paginaBase): array
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $enlaces = [];

        if (!preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/iu', $html, $coincidencias)) {
            return [];
        }

        foreach ($coincidencias[1] as $href) {
            $url = $this->absolutizarUrl(trim((string)$href), $paginaBase);
            if ($url === null || !$this->esUrlOficialInegi($url)) {
                continue;
            }

            $ruta = strtolower((string)parse_url($url, PHP_URL_PATH));
            $esDescarga = preg_match('/\.(zip|csv)$/i', $ruta) === 1;

            if (!$esDescarga || strpos(strtolower($url), '2025') === false) {
                continue;
            }

            $enlaces[$url] = $url;
        }

        return array_values($enlaces);
    }

    private function descargarYProcesar(string $claveEstado, array $candidato): array
    {
        if (!function_exists('curl_init')) {
            return $this->respuestaError('La extensión cURL de PHP no está disponible para consultar INEGI.');
        }

        $url = trim((string)($candidato['url'] ?? ''));
        if ($url === '' || !$this->esUrlOficialInegi($url)) {
            return $this->respuestaError('La fuente oficial configurada no es válida.');
        }

        $temporal = tempnam(sys_get_temp_dir(), 'inegi_edu_');
        if ($temporal === false) {
            return $this->respuestaError('No fue posible preparar el archivo temporal para INEGI.');
        }

        $archivo = fopen($temporal, 'wb');
        if ($archivo === false) {
            @unlink($temporal);
            return $this->respuestaError('No fue posible preparar la descarga de INEGI.');
        }

        $bytes = 0;
        $exceso = false;
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'SistemaComercialIMPE/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: application/zip,text/csv,application/octet-stream;q=0.9,*/*;q=0.5'
            ],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $datos) use ($archivo, &$bytes, &$exceso): int {
                $longitud = strlen($datos);
                $bytes += $longitud;

                if ($bytes > self::MAX_DESCARGA_BYTES) {
                    $exceso = true;
                    return 0;
                }

                $escritos = fwrite($archivo, $datos);
                return $escritos === false ? 0 : $escritos;
            }
        ]);

        $okCurl = curl_exec($ch);
        $codigoHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        $errorCurl = curl_error($ch);
        unset($ch);
        fflush($archivo);
        fclose($archivo);

        if ($exceso) {
            @unlink($temporal);
            return $this->respuestaError(
                'La fuente detectada supera el tamaño máximo permitido para validación automática.'
            );
        }

        if ($okCurl === false || $errorCurl !== '' || $codigoHttp !== 200) {
            @unlink($temporal);
            return $this->respuestaError(
                'La fuente ' . (int)($candidato['periodo'] ?? 0) .
                ' todavía no está disponible o no respondió correctamente.'
            );
        }

        $resultado = $this->procesarArchivoDescargado(
            $temporal,
            $claveEstado,
            $candidato,
            $contentType,
            $url
        );
        @unlink($temporal);

        return $resultado;
    }

    private function procesarArchivoDescargado(
        string $ruta,
        string $claveEstado,
        array $candidato,
        string $contentType,
        string $url
    ): array {
        $extension = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $firma = @file_get_contents($ruta, false, null, 0, 4);
        $esZip = $extension === 'zip' || strpos($contentType, 'zip') !== false || $firma === "PK\x03\x04";

        if ($esZip) {
            return $this->procesarZip($ruta, $claveEstado, $candidato);
        }

        return $this->procesarCsvRuta(
            $ruta,
            $claveEstado,
            $candidato,
            basename((string)parse_url($url, PHP_URL_PATH))
        );
    }

    private function procesarZip(string $ruta, string $claveEstado, array $candidato): array
    {
        if (!class_exists('ZipArchive')) {
            return $this->respuestaError(
                'La extensión ZIP de PHP no está disponible para leer los datos de INEGI.'
            );
        }

        $zip = new ZipArchive();
        if ($zip->open($ruta) !== true) {
            return $this->respuestaError('INEGI devolvió un archivo que no pudo abrirse como ZIP.');
        }

        try {
            $primerError = '';

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nombre = (string)$zip->getNameIndex($i);
                if (strtolower(substr($nombre, -4)) !== '.csv') {
                    continue;
                }

                $stream = $zip->getStream($nombre);
                if ($stream === false) {
                    continue;
                }

                $resultado = $this->procesarCsvStream(
                    $stream,
                    $claveEstado,
                    $candidato,
                    basename($nombre)
                );
                fclose($stream);

                if (($resultado['ok'] ?? false) === true) {
                    return $resultado;
                }

                if ($primerError === '') {
                    $primerError = trim((string)($resultado['mensaje'] ?? ''));
                }
            }

            return $this->respuestaError(
                'La fuente oficial fue localizada, pero ningún CSV contiene las variables educativas compatibles requeridas.' .
                ($primerError !== '' ? ' ' . $primerError : '')
            );
        } finally {
            $zip->close();
        }
    }

    private function procesarCsvRuta(
        string $ruta,
        string $claveEstado,
        array $candidato,
        string $archivoOrigen
    ): array {
        $stream = fopen($ruta, 'rb');
        if ($stream === false) {
            return $this->respuestaError('No fue posible leer el archivo CSV oficial de INEGI.');
        }

        try {
            return $this->procesarCsvStream($stream, $claveEstado, $candidato, $archivoOrigen);
        } finally {
            fclose($stream);
        }
    }

    private function procesarCsvStream($stream, string $claveEstado, array $candidato, string $archivoOrigen): array
    {
        $primeraLinea = fgets($stream);
        if ($primeraLinea === false) {
            return $this->respuestaError('El archivo oficial no contiene encabezados válidos.');
        }

        $delimitador = $this->detectarDelimitador($primeraLinea);
        rewind($stream);
        $encabezadoOriginal = fgetcsv($stream, 0, $delimitador);

        if (!is_array($encabezadoOriginal) || empty($encabezadoOriginal)) {
            return $this->respuestaError('El archivo oficial no contiene encabezados válidos.');
        }

        $encabezado = [];
        foreach ($encabezadoOriginal as $indice => $campo) {
            $encabezado[$indice] = $this->normalizarCabecera((string)$campo);
        }

        $indices = $this->resolverIndices($encabezado);
        if ($indices === null) {
            return $this->respuestaError(
                'La fuente fue detectada, pero no contiene las variables equivalentes a ENTIDAD, P_15YMAS y P15SEC_CO.'
            );
        }

        $estado = null;
        $municipios = [];
        $sinDetalle = [];

        while (($fila = fgetcsv($stream, 0, $delimitador)) !== false) {
            if (!is_array($fila)) {
                continue;
            }

            $entidad = $this->claveNumerica($fila[$indices['entidad']] ?? '', 2);
            if ($entidad !== $claveEstado) {
                continue;
            }

            $municipio = $indices['municipio'] !== null
                ? $this->claveNumerica($fila[$indices['municipio']] ?? '', 3)
                : null;
            $localidad = $indices['localidad'] !== null
                ? $this->claveNumerica($fila[$indices['localidad']] ?? '', 4)
                : null;

            $metricas = $this->construirMetricas($fila, $indices);
            if (!$this->metricasPrincipalesValidas($metricas)) {
                continue;
            }

            $registro = [
                'clave' => $entidad,
                'nombre' => $indices['nombre_entidad'] !== null
                    ? trim((string)($fila[$indices['nombre_entidad']] ?? ''))
                    : '',
                'metricas' => $metricas
            ];

            if ($municipio === null && $localidad === null) {
                $sinDetalle[] = $registro;
                continue;
            }

            if (($municipio === null || $municipio === '000') && ($localidad === null || $localidad === '0000')) {
                $estado = $registro;
                continue;
            }

            if ($municipio !== null && $municipio !== '000' && ($localidad === null || $localidad === '0000')) {
                $municipios[] = [
                    'clave' => $municipio,
                    'nombre' => $indices['nombre_municipio'] !== null
                        ? trim((string)($fila[$indices['nombre_municipio']] ?? ''))
                        : '',
                    'metricas' => $metricas
                ];
            }
        }

        if ($estado === null && count($sinDetalle) === 1) {
            $estado = $sinDetalle[0];
        }

        if (!is_array($estado)) {
            return $this->respuestaError(
                'La estructura de la fuente es compatible, pero no se pudo identificar de forma inequívoca el total estatal.'
            );
        }

        usort($municipios, static function (array $a, array $b): int {
            return strcmp((string)($a['nombre'] ?? ''), (string)($b['nombre'] ?? ''));
        });

        return [
            'ok' => true,
            'fuente' => trim((string)($candidato['fuente'] ?? '')),
            'periodo' => (string)((int)($candidato['periodo'] ?? 0)),
            'producto' => (string)($candidato['producto'] ?? ''),
            'archivo_origen' => $archivoOrigen,
            'compatibilidad' => 'VALIDADA',
            'metodologia' => [
                'secundaria_completa' =>
                    'Personas de 15 años y más cuya máxima escolaridad corresponde al indicador oficial compatible con P15SEC_CO.',
                'advertencia' =>
                    'La fuente sólo se acepta si conserva variables equivalentes y validables para población de 15 años y más y secundaria completa.'
            ],
            'estado' => $estado,
            'municipios' => $municipios,
            'municipios_total' => count($municipios),
            'generado_at' => date('Y-m-d H:i:s')
        ];
    }

    private function resolverIndices(array $encabezado): ?array
    {
        $mapa = [];
        foreach ($encabezado as $indice => $campo) {
            if ($campo !== '') {
                $mapa[$campo] = (int)$indice;
            }
        }

        $entidad = $this->indiceAlias($mapa, ['ENTIDAD', 'ENT', 'CVE_ENT', 'CVE_ENTIDAD']);
        $p15Mas = $this->indiceAlias($mapa, ['P_15YMAS', 'P15YMAS', 'POB_15YMAS', 'POB15MAS']);
        $secundaria = $this->indiceAlias(
            $mapa,
            ['P15SEC_CO', 'P_15SEC_CO', 'P15SEC_COMPLETA', 'SECUNDARIA_COMPLETA_15_MAS']
        );

        if ($entidad === null || $p15Mas === null || $secundaria === null) {
            return null;
        }

        return [
            'entidad' => $entidad,
            'municipio' => $this->indiceAlias($mapa, ['MUN', 'MUNICIPIO', 'CVE_MUN', 'CVE_MUNICIPIO']),
            'localidad' => $this->indiceAlias($mapa, ['LOC', 'LOCALIDAD', 'CVE_LOC', 'CVE_LOCALIDAD']),
            'nombre_entidad' => $this->indiceAlias($mapa, ['NOM_ENT', 'NOMBRE_ENTIDAD', 'ENTIDAD_NOMBRE']),
            'nombre_municipio' => $this->indiceAlias($mapa, ['NOM_MUN', 'NOMBRE_MUNICIPIO', 'MUNICIPIO_NOMBRE']),
            'p15_mas' => $p15Mas,
            'secundaria' => $secundaria,
            'p15_17' => $this->indiceAlias($mapa, ['P_15A17', 'P15A17']),
            'asiste_15_17' => $this->indiceAlias($mapa, ['P15A17A', 'P_15A17_ASISTE']),
            'p18_24' => $this->indiceAlias($mapa, ['P_18A24', 'P18A24']),
            'asiste_18_24' => $this->indiceAlias($mapa, ['P18A24A', 'P_18A24_ASISTE']),
            'posbasica' => $this->indiceAlias($mapa, ['P18YM_PB', 'P_18YMAS_POSBASICA']),
            'grado_promedio' => $this->indiceAlias($mapa, ['GRAPROES', 'GRADO_PROMEDIO_ESCOLARIDAD'])
        ];
    }

    private function construirMetricas(array $fila, array $indices): array
    {
        $p15Mas = $this->numeroIndice($fila, $indices['p15_mas']);
        $secundaria = $this->numeroIndice($fila, $indices['secundaria']);
        $p15a17 = $this->numeroIndice($fila, $indices['p15_17']);
        $asiste15a17 = $this->numeroIndice($fila, $indices['asiste_15_17']);
        $p18a24 = $this->numeroIndice($fila, $indices['p18_24']);
        $asiste18a24 = $this->numeroIndice($fila, $indices['asiste_18_24']);
        $posbasica = $this->numeroIndice($fila, $indices['posbasica']);
        $gradoPromedio = $this->decimalIndice($fila, $indices['grado_promedio']);

        $fuera15a17 = $this->restaNoNegativa($p15a17, $asiste15a17);
        $fuera18a24 = $this->restaNoNegativa($p18a24, $asiste18a24);
        $p15a24 = $this->suma($p15a17, $p18a24);
        $fuera15a24 = $this->suma($fuera15a17, $fuera18a24);

        return [
            'poblacion_15_mas' => $p15Mas,
            'secundaria_completa' => $secundaria,
            'secundaria_completa_pct' => $this->porcentaje($secundaria, $p15Mas),
            'poblacion_15_17' => $p15a17,
            'asiste_15_17' => $asiste15a17,
            'fuera_15_17' => $fuera15a17,
            'fuera_15_17_pct' => $this->porcentaje($fuera15a17, $p15a17),
            'poblacion_18_24' => $p18a24,
            'asiste_18_24' => $asiste18a24,
            'fuera_18_24' => $fuera18a24,
            'fuera_18_24_pct' => $this->porcentaje($fuera18a24, $p18a24),
            'poblacion_15_24' => $p15a24,
            'fuera_15_24' => $fuera15a24,
            'fuera_15_24_pct' => $this->porcentaje($fuera15a24, $p15a24),
            'educacion_posbasica_18_mas' => $posbasica,
            'grado_promedio_escolaridad' => $gradoPromedio
        ];
    }

    private function metricasPrincipalesValidas(array $metricas): bool
    {
        $poblacion = $metricas['poblacion_15_mas'] ?? null;
        $secundaria = $metricas['secundaria_completa'] ?? null;
        $porcentaje = $metricas['secundaria_completa_pct'] ?? null;

        return is_int($poblacion) && $poblacion > 0 &&
            is_int($secundaria) && $secundaria >= 0 && $secundaria <= $poblacion &&
            is_numeric($porcentaje) && (float)$porcentaje >= 0 && (float)$porcentaje <= 100;
    }

    private function indiceAlias(array $mapa, array $alias): ?int
    {
        foreach ($alias as $nombre) {
            if (array_key_exists($nombre, $mapa)) {
                return (int)$mapa[$nombre];
            }
        }

        return null;
    }

    private function numeroIndice(array $fila, ?int $indice): ?int
    {
        if ($indice === null) {
            return null;
        }

        return $this->numero($fila[$indice] ?? null);
    }

    private function decimalIndice(array $fila, ?int $indice): ?float
    {
        if ($indice === null) {
            return null;
        }

        return $this->decimal($fila[$indice] ?? null);
    }

    private function numero($valor): ?int
    {
        $valor = str_replace([',', ' '], '', trim((string)$valor));

        if ($valor === '' || !preg_match('/^-?\d+(?:\.0+)?$/', $valor)) {
            return null;
        }

        return max(0, (int)$valor);
    }

    private function decimal($valor): ?float
    {
        $valor = str_replace(',', '', trim((string)$valor));

        if ($valor === '' || !is_numeric($valor)) {
            return null;
        }

        return round((float)$valor, 2);
    }

    private function restaNoNegativa(?int $total, ?int $parte): ?int
    {
        if ($total === null || $parte === null) {
            return null;
        }

        return max(0, $total - $parte);
    }

    private function suma(?int $a, ?int $b): ?int
    {
        if ($a === null || $b === null) {
            return null;
        }

        return $a + $b;
    }

    private function porcentaje(?int $parte, ?int $total): ?float
    {
        if ($parte === null || $total === null || $total <= 0) {
            return null;
        }

        return round(($parte / $total) * 100, 2);
    }

    private function claveNumerica($valor, int $longitud): ?string
    {
        $valor = trim((string)$valor);

        if ($valor === '' || !preg_match('/^\d+(?:\.0+)?$/', $valor)) {
            return null;
        }

        return str_pad((string)(int)$valor, $longitud, '0', STR_PAD_LEFT);
    }

    private function detectarDelimitador(string $linea): string
    {
        $candidatos = [',', ';', "\t", '|'];
        $mejor = ',';
        $mayor = -1;

        foreach ($candidatos as $delimitador) {
            $conteo = substr_count($linea, $delimitador);
            if ($conteo > $mayor) {
                $mayor = $conteo;
                $mejor = $delimitador;
            }
        }

        return $mejor;
    }

    private function normalizarCabecera(string $valor): string
    {
        $valor = preg_replace('/^\xEF\xBB\xBF/', '', $valor);
        $valor = strtoupper(trim((string)$valor));
        $valor = str_replace(
            ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ'],
            ['A', 'E', 'I', 'O', 'U', 'U', 'N'],
            $valor
        );
        $valor = preg_replace('/[^A-Z0-9_]+/', '_', $valor) ?? $valor;

        return trim(preg_replace('/_+/', '_', $valor) ?? $valor, '_');
    }

    private function consultarHtml(string $url): ?string
    {
        if (!function_exists('curl_init') || !$this->esUrlOficialInegi($url)) {
            return null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'SistemaComercialIMPE/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml']
        ]);

        $contenido = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        return is_string($contenido) && $contenido !== '' && $codigo >= 200 && $codigo < 400
            ? $contenido
            : null;
    }

    private function absolutizarUrl(string $href, string $base): ?string
    {
        if ($href === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        if (substr($href, 0, 2) === '//') {
            return 'https:' . $href;
        }

        if (substr($href, 0, 1) === '/') {
            return 'https://www.inegi.org.mx' . $href;
        }

        $partes = parse_url($base);
        if (!is_array($partes) || empty($partes['host'])) {
            return null;
        }

        $esquema = $partes['scheme'] ?? 'https';
        $ruta = $partes['path'] ?? '/';
        $directorio = rtrim(str_replace('\\', '/', dirname($ruta)), '/');

        return $esquema . '://' . $partes['host'] .
            ($directorio !== '' ? $directorio : '') . '/' . ltrim($href, '/');
    }

    private function esUrlOficialInegi(string $url): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));

        return $host === 'inegi.org.mx' ||
            ($host !== '' && substr($host, -13) === '.inegi.org.mx');
    }

    private function normalizarTextoUrl(string $url): string
    {
        $valor = strtolower(rawurldecode($url));
        $valor = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $valor
        );
        $valor = preg_replace('/[^a-z0-9]+/', '_', $valor) ?? $valor;

        return trim($valor, '_');
    }

    private function slugEstado(string $clave): string
    {
        $estados = [
            '01' => 'aguascalientes', '02' => 'baja_california', '03' => 'baja_california_sur',
            '04' => 'campeche', '05' => 'coahuila', '06' => 'colima', '07' => 'chiapas',
            '08' => 'chihuahua', '09' => 'ciudad_de_mexico', '10' => 'durango',
            '11' => 'guanajuato', '12' => 'guerrero', '13' => 'hidalgo', '14' => 'jalisco',
            '15' => 'mexico', '16' => 'michoacan', '17' => 'morelos', '18' => 'nayarit',
            '19' => 'nuevo_leon', '20' => 'oaxaca', '21' => 'puebla', '22' => 'queretaro',
            '23' => 'quintana_roo', '24' => 'san_luis_potosi', '25' => 'sinaloa',
            '26' => 'sonora', '27' => 'tabasco', '28' => 'tamaulipas', '29' => 'tlaxcala',
            '30' => 'veracruz', '31' => 'yucatan', '32' => 'zacatecas'
        ];

        return $estados[$clave] ?? '';
    }

    private function rutaCache(string $claveEstado, array $candidato): string
    {
        $id = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($candidato['id'] ?? 'fuente'));

        return ROOT_PATH . '/storage/cache/inegi/educacion_objetivo_' . $id . '_' .
            $claveEstado . '.json';
    }

    private function leerCache(string $ruta): ?array
    {
        if (!is_file($ruta)) {
            return null;
        }

        $mtime = filemtime($ruta);
        if ($mtime === false || (time() - $mtime) > self::CACHE_TTL_SEGUNDOS) {
            return null;
        }

        $contenido = @file_get_contents($ruta);
        $datos = is_string($contenido) ? json_decode($contenido, true) : null;

        return is_array($datos) && ($datos['ok'] ?? false) === true
            ? $datos
            : null;
    }

    private function guardarCache(string $ruta, array $datos): void
    {
        $directorio = dirname($ruta);

        if (!is_dir($directorio)) {
            @mkdir($directorio, 0775, true);
        }

        @file_put_contents(
            $ruta,
            json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    private function respuestaError(string $mensaje): array
    {
        return [
            'ok' => false,
            'mensaje' => $mensaje,
            'fuente' => '',
            'periodo' => '',
            'producto' => '',
            'archivo_origen' => '',
            'estado' => null,
            'municipios' => []
        ];
    }
}
