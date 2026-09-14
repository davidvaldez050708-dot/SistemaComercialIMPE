<?php

class InegiEducacionObjetivoService
{
    private const MAX_DESCARGA_BYTES = 67108864;
    private const CACHE_TTL_SEGUNDOS = 21600;
    private const CACHE_VERSION = 'v3_perfil_completo';
    private const MAX_LINEAS_CABECERA = 50;

    private $enlacesIntercensal2025 = null;

    public function obtenerPorEstado(string $claveEstado): array
    {
        $claveEstado = str_pad(trim($claveEstado), 2, '0', STR_PAD_LEFT);

        if (!preg_match('/^(0[1-9]|[12][0-9]|3[0-2])$/', $claveEstado)) {
            return $this->respuestaError('La clave del Estado no es válida.');
        }

        $errores = [];

        foreach ($this->construirCandidatos($claveEstado) as $candidato) {
            $cache = $this->rutaCache($claveEstado, (string)$candidato['id']);
            $cacheDatos = $this->leerCache($cache);

            if ($cacheDatos !== null) {
                return $cacheDatos;
            }

            $resultado = ($candidato['tipo'] ?? '') === 'arcgis'
                ? $this->consultarArcGis($claveEstado, $candidato)
                : $this->descargarArchivo($claveEstado, $candidato);

            if (($resultado['ok'] ?? false) === true) {
                $this->guardarCache($cache, $resultado);
                return $resultado;
            }

            $mensaje = trim((string)($resultado['mensaje'] ?? ''));
            if ($mensaje !== '') {
                $errores[$mensaje] = true;
            }
        }

        $detalle = empty($errores)
            ? ''
            : ' ' . implode(' ', array_slice(array_keys($errores), 0, 2));

        return $this->respuestaError(
            'No se encontró una fuente educativa oficial de INEGI compatible con el perfil requerido.' .
            $detalle
        );
    }

    private function construirCandidatos(string $claveEstado): array
    {
        $candidatos = [];

        foreach ($this->descubrirIntercensal2025($claveEstado) as $url) {
            $candidatos[] = [
                'id' => 'EIC2025_' . substr(sha1($url), 0, 12),
                'tipo' => 'archivo',
                'periodo' => 2025,
                'producto' => 'EIC',
                'fuente' => 'INEGI - Encuesta Intercensal 2025',
                'url' => $url,
                'prioridad' => 400
            ];
        }

        $decadaActual = (int)(floor((int)date('Y') / 10) * 10);
        for ($anio = $decadaActual; $anio >= 2030; $anio -= 10) {
            $candidatos[] = [
                'id' => 'CPV' . $anio,
                'tipo' => 'archivo',
                'periodo' => $anio,
                'producto' => 'CPV',
                'fuente' => 'INEGI - Censo de Población y Vivienda ' . $anio . ' (ITER)',
                'url' => 'https://www.inegi.org.mx/contenidos/programas/ccpv/' . $anio .
                    '/datosabiertos/iter/iter_' . $claveEstado . '_cpv' . $anio . '_csv.zip',
                'prioridad' => 350
            ];
        }

        // Para 2020 usamos primero el FeatureServer oficial. A diferencia de la
        // implementación anterior, aquí se solicitan todas las variables que
        // utiliza la ficha de Población objetivo educativa y también los totales
        // municipales.
        $candidatos[] = [
            'id' => 'CPV2020_ARCGIS_FULL',
            'tipo' => 'arcgis',
            'periodo' => 2020,
            'producto' => 'CPV',
            'fuente' => 'INEGI - Censo de Población y Vivienda 2020 (ITER)',
            'url' => 'https://lcidsig.inegi.org.mx/server/rest/services/Hosted/' .
                'conjunto_de_datos_iter_' . $claveEstado . '_2020/FeatureServer/0/query',
            'prioridad' => 250
        ];

        $candidatos[] = [
            'id' => 'CPV2020_ZIP_FULL',
            'tipo' => 'archivo',
            'periodo' => 2020,
            'producto' => 'CPV',
            'fuente' => 'INEGI - Censo de Población y Vivienda 2020 (ITER)',
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

    private function consultarArcGis(string $claveEstado, array $candidato): array
    {
        $url = (string)$candidato['url'] . '?' . http_build_query([
            'where' => 'entidad=' . (int)$claveEstado . ' AND loc=0',
            'outFields' => implode(',', [
                'entidad', 'nom_ent', 'mun', 'nom_mun', 'loc',
                'p_15ymas', 'p15sec_co',
                'p_15a17', 'p15a17a',
                'p_18a24', 'p18a24a',
                'p18ym_pb', 'graproes'
            ]),
            'returnGeometry' => 'false',
            'resultRecordCount' => 1000,
            'orderByFields' => 'mun ASC',
            'f' => 'json'
        ], '', '&', PHP_QUERY_RFC3986);

        $datos = $this->getJson($url);

        if (!is_array($datos) || !empty($datos['error'])) {
            return $this->respuestaError(
                'No fue posible consultar el servicio educativo oficial del Censo 2020.'
            );
        }

        $estado = null;
        $municipios = [];

        foreach (($datos['features'] ?? []) as $feature) {
            $raw = is_array($feature['attributes'] ?? null)
                ? $feature['attributes']
                : null;

            if ($raw === null) {
                continue;
            }

            $registro = $this->normalizarRegistro($raw);
            $entidad = $this->claveNumerica($registro['ENTIDAD'] ?? '', 2);
            $municipio = $this->claveNumerica($registro['MUN'] ?? '', 3);
            $localidad = $this->claveNumerica($registro['LOC'] ?? '', 4);

            if ($entidad !== $claveEstado || $localidad !== '0000') {
                continue;
            }

            $metricas = $this->construirMetricasRegistro($registro);
            if (!$this->metricasPrincipalesValidas($metricas)) {
                continue;
            }

            if ($municipio === '000') {
                $estado = [
                    'clave' => $entidad,
                    'nombre' => trim((string)($registro['NOM_ENT'] ?? '')),
                    'metricas' => $metricas
                ];
                continue;
            }

            $municipios[] = [
                'clave' => $municipio,
                'nombre' => trim((string)($registro['NOM_MUN'] ?? '')),
                'metricas' => $metricas
            ];
        }

        if (!is_array($estado)) {
            return $this->respuestaError(
                'INEGI respondió, pero no devolvió un total estatal con las variables educativas requeridas.'
            );
        }

        if (!$this->metricasPerfilCompletas($estado['metricas'])) {
            return $this->respuestaError(
                'La fuente 2020 respondió sin todas las variables necesarias para el perfil educativo.'
            );
        }

        $this->ordenarMunicipios($municipios);

        return $this->respuestaExito(
            $candidato,
            'FeatureServer/0',
            $estado,
            $municipios
        );
    }

    private function descargarArchivo(string $claveEstado, array $candidato): array
    {
        $url = trim((string)($candidato['url'] ?? ''));

        if ($url === '' || !$this->esUrlOficialInegi($url) || !function_exists('curl_init')) {
            return $this->respuestaError('La fuente oficial configurada no está disponible.');
        }

        $temporal = tempnam(sys_get_temp_dir(), 'inegi_edu_');
        if ($temporal === false) {
            return $this->respuestaError('No fue posible preparar la descarga de INEGI.');
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
            CURLOPT_WRITEFUNCTION => static function ($curl, $bloque) use ($archivo, &$bytes, &$exceso) {
                $longitud = strlen((string)$bloque);
                $bytes += $longitud;

                if ($bytes > self::MAX_DESCARGA_BYTES) {
                    $exceso = true;
                    return 0;
                }

                $escritos = fwrite($archivo, (string)$bloque);
                return $escritos === false ? 0 : $escritos;
            }
        ]);

        $okCurl = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
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

        if ($okCurl === false || $errorCurl !== '' || $http !== 200) {
            @unlink($temporal);
            return $this->respuestaError(
                'La fuente ' . (int)($candidato['periodo'] ?? 0) .
                ' todavía no está disponible o no respondió correctamente.'
            );
        }

        $rutaUrl = (string)parse_url($url, PHP_URL_PATH);
        $firma = @file_get_contents($temporal, false, null, 0, 4);
        $esZip = strtolower(pathinfo($rutaUrl, PATHINFO_EXTENSION)) === 'zip' ||
            strpos($contentType, 'zip') !== false ||
            $firma === "PK\x03\x04";

        $resultado = $esZip
            ? $this->procesarZip($temporal, $claveEstado, $candidato)
            : $this->procesarCsvRuta($temporal, $claveEstado, $candidato, basename($rutaUrl));

        @unlink($temporal);
        return $resultado;
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

        $entradas = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = (string)$zip->getNameIndex($i);
            if (strtolower(substr($nombre, -4)) !== '.csv') {
                continue;
            }

            $base = strtolower(basename($nombre));
            $peso = 0;
            $peso += strpos($base, 'conjunto_de_datos') !== false ? 100 : 0;
            $peso += strpos($base, 'iter') !== false ? 50 : 0;
            $peso -= (strpos($base, 'diccionario') !== false || strpos($base, 'catalogo') !== false) ? 100 : 0;

            $entradas[] = [
                'nombre' => $nombre,
                'peso' => $peso
            ];
        }

        usort($entradas, static function (array $a, array $b): int {
            return (int)$b['peso'] <=> (int)$a['peso'];
        });

        $primerError = '';

        foreach ($entradas as $entrada) {
            $stream = $zip->getStream((string)$entrada['nombre']);
            if ($stream === false) {
                continue;
            }

            $resultado = $this->procesarCsv(
                $stream,
                $claveEstado,
                $candidato,
                basename((string)$entrada['nombre'])
            );
            fclose($stream);

            if (($resultado['ok'] ?? false) === true) {
                $zip->close();
                return $resultado;
            }

            if ($primerError === '') {
                $primerError = trim((string)($resultado['mensaje'] ?? ''));
            }
        }

        $zip->close();

        return $this->respuestaError(
            'La fuente oficial fue localizada, pero ningún CSV contiene el perfil educativo compatible.' .
            ($primerError !== '' ? ' ' . $primerError : '')
        );
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

        $resultado = $this->procesarCsv($stream, $claveEstado, $candidato, $archivoOrigen);
        fclose($stream);
        return $resultado;
    }

    private function procesarCsv($stream, string $claveEstado, array $candidato, string $archivoOrigen): array
    {
        $cabecera = $this->buscarCabecera($stream);
        if ($cabecera === null) {
            return $this->respuestaError(
                'No se localizaron encabezados compatibles con las variables educativas requeridas.'
            );
        }

        $delimitador = $cabecera['delimitador'];
        $indices = $cabecera['indices'];
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

            $municipio = $indices['municipio'] === null
                ? null
                : $this->claveNumerica($fila[$indices['municipio']] ?? '', 3);
            $localidad = $indices['localidad'] === null
                ? null
                : $this->claveNumerica($fila[$indices['localidad']] ?? '', 4);

            $metricas = $this->construirMetricasFila($fila, $indices);
            if (!$this->metricasPrincipalesValidas($metricas)) {
                continue;
            }

            $registro = [
                'clave' => $entidad,
                'nombre' => $indices['nombre_entidad'] === null
                    ? ''
                    : trim((string)($fila[$indices['nombre_entidad']] ?? '')),
                'metricas' => $metricas
            ];

            if ($municipio === null && $localidad === null) {
                $sinDetalle[] = $registro;
                continue;
            }

            if (($municipio === null || $municipio === '000') &&
                ($localidad === null || $localidad === '0000')) {
                $estado = $registro;
                continue;
            }

            if ($municipio !== null && $municipio !== '000' &&
                ($localidad === null || $localidad === '0000')) {
                $municipios[] = [
                    'clave' => $municipio,
                    'nombre' => $indices['nombre_municipio'] === null
                        ? ''
                        : trim((string)($fila[$indices['nombre_municipio']] ?? '')),
                    'metricas' => $metricas
                ];
            }
        }

        if ($estado === null && count($sinDetalle) === 1) {
            $estado = $sinDetalle[0];
        }

        if (!is_array($estado)) {
            return $this->respuestaError(
                'La estructura es compatible, pero no se pudo identificar el total estatal.'
            );
        }

        // Esta vista necesita el perfil completo. Si una fuente futura conserva
        // sólo P_15YMAS y P15SEC_CO, no se acepta para esta ficha; se continúa
        // con la siguiente fuente oficial compatible para evitar mostrar guiones.
        if (!$this->metricasPerfilCompletas($estado['metricas'])) {
            return $this->respuestaError(
                'La fuente detectada no incluye todas las variables del perfil educativo.'
            );
        }

        $this->ordenarMunicipios($municipios);

        return $this->respuestaExito(
            $candidato,
            $archivoOrigen,
            $estado,
            $municipios
        );
    }

    private function buscarCabecera($stream): ?array
    {
        $delimitadores = [',', ';', "\t", '|'];

        for ($linea = 1; $linea <= self::MAX_LINEAS_CABECERA; $linea++) {
            $texto = fgets($stream);
            if ($texto === false) {
                break;
            }

            $texto = preg_replace('/^\xEF\xBB\xBF/', '', (string)$texto);
            if (trim($texto) === '') {
                continue;
            }

            foreach ($delimitadores as $delimitador) {
                $campos = str_getcsv($texto, $delimitador);
                if (count($campos) < 3) {
                    continue;
                }

                $normalizados = array_map([$this, 'normalizarCabecera'], $campos);
                $indices = $this->resolverIndices($normalizados);

                if ($indices !== null) {
                    return [
                        'delimitador' => $delimitador,
                        'indices' => $indices,
                        'linea' => $linea
                    ];
                }
            }
        }

        return null;
    }

    private function resolverIndices(array $cabecera): ?array
    {
        $mapa = [];

        foreach ($cabecera as $indice => $campo) {
            if ($campo !== '') {
                $mapa[$campo] = (int)$indice;
            }
        }

        $entidad = $this->indiceAlias($mapa, [
            'ENTIDAD', 'ENT', 'CVE_ENT', 'CVE_ENTIDAD', 'CLAVE_ENTIDAD'
        ]);
        $p15Mas = $this->indiceAlias($mapa, [
            'P_15YMAS', 'P15YMAS', 'POB_15YMAS', 'POB15MAS'
        ]);
        $secundaria = $this->indiceAlias($mapa, [
            'P15SEC_CO', 'P_15SEC_CO', 'P15SEC_COMPLETA', 'SECUNDARIA_COMPLETA_15_MAS'
        ]);

        if ($entidad === null || $p15Mas === null || $secundaria === null) {
            return null;
        }

        return [
            'entidad' => $entidad,
            'municipio' => $this->indiceAlias($mapa, [
                'MUN', 'MUNICIPIO', 'CVE_MUN', 'CVE_MUNICIPIO'
            ]),
            'localidad' => $this->indiceAlias($mapa, [
                'LOC', 'LOCALIDAD', 'CVE_LOC', 'CVE_LOCALIDAD'
            ]),
            'nombre_entidad' => $this->indiceAlias($mapa, [
                'NOM_ENT', 'NOMBRE_ENTIDAD', 'ENTIDAD_NOMBRE'
            ]),
            'nombre_municipio' => $this->indiceAlias($mapa, [
                'NOM_MUN', 'NOMBRE_MUNICIPIO', 'MUNICIPIO_NOMBRE'
            ]),
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

    private function construirMetricasRegistro(array $registro): array
    {
        return $this->armarMetricas(
            $this->numero($registro['P_15YMAS'] ?? null),
            $this->numero($registro['P15SEC_CO'] ?? null),
            $this->numero($registro['P_15A17'] ?? null),
            $this->numero($registro['P15A17A'] ?? null),
            $this->numero($registro['P_18A24'] ?? null),
            $this->numero($registro['P18A24A'] ?? null),
            $this->numero($registro['P18YM_PB'] ?? null),
            $this->decimal($registro['GRAPROES'] ?? null)
        );
    }

    private function construirMetricasFila(array $fila, array $indices): array
    {
        return $this->armarMetricas(
            $this->numeroIndice($fila, $indices['p15_mas']),
            $this->numeroIndice($fila, $indices['secundaria']),
            $this->numeroIndice($fila, $indices['p15_17']),
            $this->numeroIndice($fila, $indices['asiste_15_17']),
            $this->numeroIndice($fila, $indices['p18_24']),
            $this->numeroIndice($fila, $indices['asiste_18_24']),
            $this->numeroIndice($fila, $indices['posbasica']),
            $this->decimalIndice($fila, $indices['grado_promedio'])
        );
    }

    private function armarMetricas(
        ?int $p15Mas,
        ?int $secundaria,
        ?int $p15a17,
        ?int $asiste15a17,
        ?int $p18a24,
        ?int $asiste18a24,
        ?int $posbasica,
        ?float $gradoPromedio
    ): array {
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

    private function metricasPerfilCompletas(array $metricas): bool
    {
        foreach ([
            'poblacion_15_17', 'asiste_15_17', 'fuera_15_17', 'fuera_15_17_pct',
            'poblacion_18_24', 'asiste_18_24', 'fuera_18_24', 'fuera_18_24_pct',
            'poblacion_15_24', 'fuera_15_24', 'fuera_15_24_pct',
            'educacion_posbasica_18_mas', 'grado_promedio_escolaridad'
        ] as $clave) {
            if (!array_key_exists($clave, $metricas) || $metricas[$clave] === null) {
                return false;
            }
        }

        return true;
    }

    private function ordenarMunicipios(array &$municipios): void
    {
        usort($municipios, static function (array $a, array $b): int {
            $valorA = (int)($a['metricas']['fuera_15_24'] ?? 0);
            $valorB = (int)($b['metricas']['fuera_15_24'] ?? 0);

            if ($valorA === $valorB) {
                return strcmp((string)($a['nombre'] ?? ''), (string)($b['nombre'] ?? ''));
            }

            return $valorB <=> $valorA;
        });
    }

    private function respuestaExito(
        array $candidato,
        string $archivoOrigen,
        array $estado,
        array $municipios
    ): array {
        return [
            'ok' => true,
            'fuente' => trim((string)($candidato['fuente'] ?? 'INEGI')),
            'periodo' => (string)((int)($candidato['periodo'] ?? 0)),
            'producto' => (string)($candidato['producto'] ?? ''),
            'archivo_origen' => $archivoOrigen,
            'compatibilidad' => 'PERFIL_COMPLETO_VALIDADO',
            'metodologia' => [
                'secundaria_completa' =>
                    'Personas de 15 años y más cuya máxima escolaridad son 3 grados aprobados de secundaria (P15SEC_CO).',
                'fuera_15_17' =>
                    'Población de 15 a 17 años menos quienes reportaron asistir a la escuela.',
                'fuera_18_24' =>
                    'Población de 18 a 24 años menos quienes reportaron asistir a la escuela.',
                'advertencia' =>
                    'Los indicadores de escolaridad máxima y asistencia representan grupos distintos y no deben sumarse como si fueran las mismas personas.'
            ],
            'estado' => $estado,
            'municipios' => $municipios,
            'municipios_total' => count($municipios),
            'generado_at' => date('Y-m-d H:i:s')
        ];
    }

    private function descubrirIntercensal2025(string $claveEstado): array
    {
        if ((int)date('Y') < 2026) {
            return [];
        }

        if ($this->enlacesIntercensal2025 === null) {
            $this->enlacesIntercensal2025 = [];

            foreach ([
                'https://www.inegi.org.mx/programas/intercensal/2025/',
                'https://www.inegi.org.mx/programas/eic/2025/'
            ] as $pagina) {
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

        $slugEstado = $this->slugEstado($claveEstado);
        $seleccionados = [];
        $nacionales = [];

        foreach ($this->enlacesIntercensal2025 as $url) {
            $normalizada = $this->normalizarTextoUrl($url);
            $coincideClave = (bool)preg_match(
                '/(?:^|[^0-9])' . preg_quote($claveEstado, '/') . '(?:[^0-9]|$)/',
                $normalizada
            );
            $coincideNombre = $slugEstado !== '' && strpos($normalizada, $slugEstado) !== false;

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
            if (preg_match('/\.(zip|csv)$/i', $ruta) !== 1) {
                continue;
            }

            if (strpos(strtolower($url), '2025') === false) {
                continue;
            }

            $enlaces[$url] = $url;
        }

        return array_values($enlaces);
    }

    private function consultarHtml(string $url): ?string
    {
        if (!$this->esUrlOficialInegi($url) || !function_exists('curl_init')) {
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

        $texto = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);

        if ($texto === false || $error !== '' || $http < 200 || $http >= 300) {
            return null;
        }

        return (string)$texto;
    }

    private function getJson(string $url): ?array
    {
        if (!$this->esUrlOficialInegi($url) || !function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'SistemaComercialIMPE/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json,text/plain;q=0.9,*/*;q=0.5']
        ]);

        $texto = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);

        if ($texto === false || $error !== '' || $http < 200 || $http >= 300) {
            return null;
        }

        $datos = json_decode((string)$texto, true);
        return is_array($datos) ? $datos : null;
    }

    private function normalizarRegistro(array $registro): array
    {
        $salida = [];

        foreach ($registro as $clave => $valor) {
            $salida[$this->normalizarCabecera((string)$clave)] = $valor;
        }

        return $salida;
    }

    private function normalizarCabecera($valor): string
    {
        $valor = preg_replace('/^\xEF\xBB\xBF/', '', (string)$valor);
        $valor = strtoupper(trim((string)$valor));
        $valor = preg_replace('/[^A-Z0-9_]+/', '_', $valor) ?? '';
        return trim($valor, '_');
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
        return $indice === null ? null : $this->numero($fila[$indice] ?? null);
    }

    private function decimalIndice(array $fila, ?int $indice): ?float
    {
        return $indice === null ? null : $this->decimal($fila[$indice] ?? null);
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
        return $a === null || $b === null ? null : $a + $b;
    }

    private function porcentaje(?int $parte, ?int $total): ?float
    {
        if ($parte === null || $total === null || $total <= 0) {
            return null;
        }

        return round(($parte / $total) * 100, 2);
    }

    private function claveNumerica($valor, int $longitud): string
    {
        $valor = preg_replace('/\D+/', '', trim((string)$valor)) ?? '';
        return str_pad($valor === '' ? '0' : $valor, $longitud, '0', STR_PAD_LEFT);
    }

    private function rutaCache(string $claveEstado, string $candidatoId): string
    {
        $id = preg_replace('/[^A-Za-z0-9_-]+/', '_', $candidatoId) ?? 'fuente';

        return ROOT_PATH . '/storage/cache/inegi/educacion_objetivo_' .
            self::CACHE_VERSION . '_' . $claveEstado . '_' . $id . '.json';
    }

    private function leerCache(string $ruta): ?array
    {
        if (!is_file($ruta)) {
            return null;
        }

        $mtime = @filemtime($ruta);
        if ($mtime === false || (time() - $mtime) > self::CACHE_TTL_SEGUNDOS) {
            @unlink($ruta);
            return null;
        }

        $contenido = @file_get_contents($ruta);
        $datos = is_string($contenido) ? json_decode($contenido, true) : null;

        if (!is_array($datos) || ($datos['ok'] ?? false) !== true) {
            @unlink($ruta);
            return null;
        }

        $metricas = $datos['estado']['metricas'] ?? null;
        if (!is_array($metricas) || !$this->metricasPerfilCompletas($metricas)) {
            @unlink($ruta);
            return null;
        }

        return $datos;
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

    private function esUrlOficialInegi(string $url): bool
    {
        $partes = parse_url($url);
        if (!is_array($partes)) {
            return false;
        }

        $esquema = strtolower((string)($partes['scheme'] ?? ''));
        $host = strtolower((string)($partes['host'] ?? ''));

        if ($esquema !== 'https' || $host === '') {
            return false;
        }

        return $host === 'inegi.org.mx' ||
            substr($host, -13) === '.inegi.org.mx';
    }

    private function absolutizarUrl(string $href, string $base): ?string
    {
        if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:')) {
            return null;
        }

        if (preg_match('#^https://#i', $href)) {
            return $href;
        }

        $basePartes = parse_url($base);
        if (!is_array($basePartes) || empty($basePartes['host'])) {
            return null;
        }

        $origen = 'https://' . $basePartes['host'];

        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }

        if (str_starts_with($href, '/')) {
            return $origen . $href;
        }

        $rutaBase = (string)($basePartes['path'] ?? '/');
        $directorio = rtrim(str_replace('\\', '/', dirname($rutaBase)), '/');

        return $origen . ($directorio !== '' ? $directorio : '') . '/' . ltrim($href, '/');
    }

    private function normalizarTextoUrl(string $valor): string
    {
        $valor = urldecode(strtolower($valor));

        $reemplazos = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n'
        ];

        $valor = strtr($valor, $reemplazos);
        return preg_replace('/[^a-z0-9]+/', '_', $valor) ?? '';
    }

    private function slugEstado(string $clave): string
    {
        $estados = [
            '01' => 'aguascalientes',
            '02' => 'baja_california',
            '03' => 'baja_california_sur',
            '04' => 'campeche',
            '05' => 'coahuila',
            '06' => 'colima',
            '07' => 'chiapas',
            '08' => 'chihuahua',
            '09' => 'ciudad_de_mexico',
            '10' => 'durango',
            '11' => 'guanajuato',
            '12' => 'guerrero',
            '13' => 'hidalgo',
            '14' => 'jalisco',
            '15' => 'mexico',
            '16' => 'michoacan',
            '17' => 'morelos',
            '18' => 'nayarit',
            '19' => 'nuevo_leon',
            '20' => 'oaxaca',
            '21' => 'puebla',
            '22' => 'queretaro',
            '23' => 'quintana_roo',
            '24' => 'san_luis_potosi',
            '25' => 'sinaloa',
            '26' => 'sonora',
            '27' => 'tabasco',
            '28' => 'tamaulipas',
            '29' => 'tlaxcala',
            '30' => 'veracruz',
            '31' => 'yucatan',
            '32' => 'zacatecas'
        ];

        return $estados[$clave] ?? '';
    }

    private function respuestaError(string $mensaje): array
    {
        return [
            'ok' => false,
            'mensaje' => $mensaje,
            'fuente' => 'INEGI',
            'periodo' => '',
            'estado' => null,
            'municipios' => []
        ];
    }
}
