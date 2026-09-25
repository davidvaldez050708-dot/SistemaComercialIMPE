<?php

/**
 * Consulta el perfil adulto/laboral desde ITER 2020 de INEGI.
 *
 * La fuente contiene totales estatales y municipales (LOC=0000), por lo que no
 * es necesario estimar grupos de edad. Los rangos del producto se agregan así:
 * 25-34 = P_25A29 + P_30A34
 * 35-44 = P_35A39 + P_40A44
 * 45-54 = P_45A49 + P_50A54
 *
 * PEA y población ocupada se conservan como contexto laboral general (12+);
 * no se presentan como si correspondieran exclusivamente a las personas 25-54.
 */
class InegiPerfilAdultoLaboralService
{
    private const PERIODO = 2020;
    private const FUENTE = 'INEGI - Censo de Población y Vivienda 2020 (ITER)';
    private const MAX_DESCARGA_BYTES = 180000000;

    public function obtenerPorEstado(string $claveEstado): array
    {
        $claveEstado = str_pad(trim($claveEstado), 2, '0', STR_PAD_LEFT);

        if (!preg_match('/^(0[1-9]|[12][0-9]|3[0-2])$/', $claveEstado)) {
            return $this->error('La clave del Estado no es válida.');
        }

        $urlBase = 'https://lcidsig.inegi.org.mx/server/rest/services/Hosted/' .
            'conjunto_de_datos_iter_' . $claveEstado . '_2020/FeatureServer/0/query';

        $url = $urlBase . '?' . http_build_query([
            'where' => 'entidad=' . (int)$claveEstado . ' AND loc=0',
            'outFields' => implode(',', [
                'entidad', 'nom_ent', 'mun', 'nom_mun', 'loc',
                'p_25a29', 'p_30a34', 'p_35a39', 'p_40a44',
                'p_45a49', 'p_50a54', 'pea', 'pocupada'
            ]),
            'returnGeometry' => 'false',
            'resultRecordCount' => 1000,
            'orderByFields' => 'mun ASC',
            'f' => 'json'
        ], '', '&', PHP_QUERY_RFC3986);

        $respuesta = $this->getJson($url);

        // El directorio Hosted de INEGI no expone necesariamente un FeatureServer
        // ITER por cada entidad. Cuando el servicio estatal no existe, usamos el
        // ZIP oficial de Datos Abiertos del mismo Censo 2020.
        if (!is_array($respuesta) || !empty($respuesta['error'])) {
            return $this->obtenerDesdeZipOficial($claveEstado);
        }

        $estado = null;
        $municipios = [];

        foreach (($respuesta['features'] ?? []) as $feature) {
            $raw = is_array($feature['attributes'] ?? null) ? $feature['attributes'] : null;
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

            $metricas = $this->metricas($registro);
            if (!$this->metricasValidas($metricas)) {
                continue;
            }

            $fila = [
                'clave_estado' => $entidad,
                'clave_municipio' => $municipio,
                'clave_geografica' => $entidad . ($municipio === '000' ? '' : $municipio),
                'nombre' => trim((string)(
                    $municipio === '000'
                        ? ($registro['NOM_ENT'] ?? '')
                        : ($registro['NOM_MUN'] ?? '')
                )),
                'metricas' => $metricas
            ];

            if ($municipio === '000') {
                $estado = $fila;
            } else {
                $municipios[] = $fila;
            }
        }

        if ($estado === null) {
            return $this->error(
                'INEGI respondió, pero no devolvió el total estatal con los grupos adultos requeridos.'
            );
        }

        usort($municipios, static function (array $a, array $b): int {
            $poblacionA = (int)($a['metricas']['poblacion_25_54'] ?? 0);
            $poblacionB = (int)($b['metricas']['poblacion_25_54'] ?? 0);

            return $poblacionA === $poblacionB
                ? strcmp((string)($a['nombre'] ?? ''), (string)($b['nombre'] ?? ''))
                : $poblacionB <=> $poblacionA;
        });

        return $this->respuestaExitosa($estado, $municipios, 'FeatureServer/0');
    }

    private function obtenerDesdeZipOficial(string $claveEstado): array
    {
        if (!function_exists('curl_init') || !class_exists('ZipArchive')) {
            return $this->error(
                'El servidor no cuenta con cURL/ZIP para leer el archivo oficial de INEGI.'
            );
        }

        $url = 'https://www.inegi.org.mx/contenidos/programas/ccpv/2020/datosabiertos/iter/' .
            'iter_' . $claveEstado . '_cpv2020_csv.zip';
        $temporal = tempnam(sys_get_temp_dir(), 'inegi_adulto_');

        if ($temporal === false) {
            return $this->error('No fue posible preparar la descarga oficial de INEGI.');
        }

        $archivo = fopen($temporal, 'wb');
        if ($archivo === false) {
            @unlink($temporal);
            return $this->error('No fue posible preparar el archivo temporal de INEGI.');
        }

        $bytes = 0;
        $exceso = false;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'SistemaComercialIMPE/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/zip,application/octet-stream;q=0.9,*/*;q=0.5'],
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

        $ok = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($ch);
        unset($ch);
        fflush($archivo);
        fclose($archivo);

        if ($exceso || $ok === false || $errorCurl !== '' || $http !== 200) {
            @unlink($temporal);
            return $this->error(
                $exceso
                    ? 'El archivo oficial de INEGI supera el tamaño permitido para la actualización.'
                    : 'No fue posible descargar el ITER 2020 oficial para el Estado seleccionado.'
            );
        }

        $zip = new ZipArchive();
        if ($zip->open($temporal) !== true) {
            @unlink($temporal);
            return $this->error('INEGI devolvió un archivo que no pudo abrirse como ZIP.');
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
            $entradas[] = ['nombre' => $nombre, 'peso' => $peso];
        }

        usort($entradas, static function (array $a, array $b): int {
            return (int)$b['peso'] <=> (int)$a['peso'];
        });

        foreach ($entradas as $entrada) {
            $stream = $zip->getStream((string)$entrada['nombre']);
            if ($stream === false) {
                continue;
            }

            $resultado = $this->procesarCsvIter(
                $stream,
                $claveEstado,
                basename((string)$entrada['nombre'])
            );
            fclose($stream);

            if (($resultado['ok'] ?? false) === true) {
                $zip->close();
                @unlink($temporal);
                return $resultado;
            }
        }

        $zip->close();
        @unlink($temporal);

        return $this->error(
            'El ZIP oficial fue descargado, pero no contiene el perfil adulto/laboral esperado.'
        );
    }

    private function procesarCsvIter($stream, string $claveEstado, string $archivoOrigen): array
    {
        $cabecera = $this->buscarCabeceraIter($stream);
        if ($cabecera === null) {
            return $this->error('No se localizaron las variables adultas requeridas en el CSV.');
        }

        $indices = $cabecera['indices'];
        $delimitador = $cabecera['delimitador'];
        $estado = null;
        $municipios = [];

        while (($fila = fgetcsv($stream, 0, $delimitador)) !== false) {
            if (!is_array($fila)) {
                continue;
            }

            $entidad = $this->claveNumerica($fila[$indices['entidad']] ?? '', 2);
            $municipio = $this->claveNumerica($fila[$indices['municipio']] ?? '', 3);
            $localidad = $this->claveNumerica($fila[$indices['localidad']] ?? '', 4);

            if ($entidad !== $claveEstado || $localidad !== '0000') {
                continue;
            }

            $registro = [
                'P_25A29' => $fila[$indices['p25a29']] ?? null,
                'P_30A34' => $fila[$indices['p30a34']] ?? null,
                'P_35A39' => $fila[$indices['p35a39']] ?? null,
                'P_40A44' => $fila[$indices['p40a44']] ?? null,
                'P_45A49' => $fila[$indices['p45a49']] ?? null,
                'P_50A54' => $fila[$indices['p50a54']] ?? null,
                'PEA' => $indices['pea'] === null ? null : ($fila[$indices['pea']] ?? null),
                'POCUPADA' => $indices['pocupada'] === null ? null : ($fila[$indices['pocupada']] ?? null)
            ];
            $metricas = $this->metricas($registro);

            if (!$this->metricasValidas($metricas)) {
                continue;
            }

            $nombre = $municipio === '000'
                ? trim((string)($fila[$indices['nombre_entidad']] ?? ''))
                : trim((string)($fila[$indices['nombre_municipio']] ?? ''));
            $registroSalida = [
                'clave_estado' => $entidad,
                'clave_municipio' => $municipio,
                'clave_geografica' => $entidad . ($municipio === '000' ? '' : $municipio),
                'nombre' => $nombre,
                'metricas' => $metricas
            ];

            if ($municipio === '000') {
                $estado = $registroSalida;
            } else {
                $municipios[] = $registroSalida;
            }
        }

        if ($estado === null || empty($municipios)) {
            return $this->error('No se identificaron totales estatales y municipales completos.');
        }

        usort($municipios, static function (array $a, array $b): int {
            return (int)($b['metricas']['poblacion_25_54'] ?? 0)
                <=> (int)($a['metricas']['poblacion_25_54'] ?? 0);
        });

        return $this->respuestaExitosa($estado, $municipios, $archivoOrigen);
    }

    private function buscarCabeceraIter($stream): ?array
    {
        // ZipArchive::getStream() no garantiza soporte para seek/rewind.
        // El CSV ITER inicia con su cabecera, así que la leemos una sola vez y
        // probamos los delimitadores sobre el texto ya cargado en memoria.
        $texto = fgets($stream);
        if ($texto === false) {
            return null;
        }

        $texto = preg_replace('/^\\xEF\\xBB\\xBF/', '', (string)$texto);

        foreach ([',', ';', "\t", '|'] as $delimitador) {
            $campos = str_getcsv($texto, $delimitador);
            $mapa = [];

            foreach ($campos as $indice => $campo) {
                $normalizado = strtoupper(trim((string)$campo));
                if ($normalizado !== '') {
                    $mapa[$normalizado] = (int)$indice;
                }
            }

            $requeridos = [
                'ENTIDAD', 'MUN', 'LOC',
                'P_25A29', 'P_30A34', 'P_35A39',
                'P_40A44', 'P_45A49', 'P_50A54'
            ];

            if (count(array_intersect($requeridos, array_keys($mapa))) !== count($requeridos)) {
                continue;
            }

            return [
                'delimitador' => $delimitador,
                'indices' => [
                    'entidad' => $mapa['ENTIDAD'],
                    'municipio' => $mapa['MUN'],
                    'localidad' => $mapa['LOC'],
                    'nombre_entidad' => $mapa['NOM_ENT'] ?? null,
                    'nombre_municipio' => $mapa['NOM_MUN'] ?? null,
                    'p25a29' => $mapa['P_25A29'],
                    'p30a34' => $mapa['P_30A34'],
                    'p35a39' => $mapa['P_35A39'],
                    'p40a44' => $mapa['P_40A44'],
                    'p45a49' => $mapa['P_45A49'],
                    'p50a54' => $mapa['P_50A54'],
                    'pea' => $mapa['PEA'] ?? null,
                    'pocupada' => $mapa['POCUPADA'] ?? null
                ]
            ];
        }

        return null;
    }

    private function respuestaExitosa(array $estado, array $municipios, string $archivoOrigen): array
    {
        return [
            'ok' => true,
            'fuente' => self::FUENTE,
            'periodo' => self::PERIODO,
            'archivo_origen' => $archivoOrigen,
            'compatibilidad' => 'PERFIL_ADULTO_LABORAL_VALIDADO',
            'metodologia' => [
                'poblacion_25_34' => 'Suma de P_25A29 y P_30A34.',
                'poblacion_35_44' => 'Suma de P_35A39 y P_40A44.',
                'poblacion_45_54' => 'Suma de P_45A49 y P_50A54.',
                'poblacion_25_54' => 'Suma de los seis grupos quinquenales entre 25 y 54 años.',
                'laboral' => 'PEA y POCUPADA son contexto laboral general de 12 años y más; no representan exclusivamente a la población de 25 a 54 años.'
            ],
            'estado' => $estado,
            'municipios' => $municipios,
            'municipios_total' => count($municipios),
            'generado_at' => date('Y-m-d H:i:s')
        ];
    }


    private function metricas(array $registro): array
    {
        $p25a29 = $this->numero($registro['P_25A29'] ?? null);
        $p30a34 = $this->numero($registro['P_30A34'] ?? null);
        $p35a39 = $this->numero($registro['P_35A39'] ?? null);
        $p40a44 = $this->numero($registro['P_40A44'] ?? null);
        $p45a49 = $this->numero($registro['P_45A49'] ?? null);
        $p50a54 = $this->numero($registro['P_50A54'] ?? null);

        $p25a34 = $this->suma([$p25a29, $p30a34]);
        $p35a44 = $this->suma([$p35a39, $p40a44]);
        $p45a54 = $this->suma([$p45a49, $p50a54]);

        return [
            'poblacion_25_34' => $p25a34,
            'poblacion_35_44' => $p35a44,
            'poblacion_45_54' => $p45a54,
            'poblacion_25_54' => $this->suma([$p25a34, $p35a44, $p45a54]),
            'poblacion_economicamente_activa' => $this->numero($registro['PEA'] ?? null),
            'poblacion_ocupada' => $this->numero($registro['POCUPADA'] ?? null)
        ];
    }

    private function metricasValidas(array $metricas): bool
    {
        foreach (['poblacion_25_34', 'poblacion_35_44', 'poblacion_45_54', 'poblacion_25_54'] as $clave) {
            if (!is_int($metricas[$clave] ?? null) || $metricas[$clave] < 0) {
                return false;
            }
        }

        return (int)$metricas['poblacion_25_54'] > 0;
    }

    private function suma(array $valores): ?int
    {
        foreach ($valores as $valor) {
            if ($valor === null) {
                return null;
            }
        }

        return array_sum($valores);
    }

    private function numero($valor): ?int
    {
        $valor = str_replace([',', ' '], '', trim((string)$valor));

        if ($valor === '' || !preg_match('/^-?\d+(?:\.0+)?$/', $valor)) {
            return null;
        }

        return max(0, (int)$valor);
    }

    private function normalizarRegistro(array $registro): array
    {
        $salida = [];

        foreach ($registro as $clave => $valor) {
            $salida[strtoupper(trim((string)$clave))] = $valor;
        }

        return $salida;
    }

    private function claveNumerica($valor, int $longitud): string
    {
        $valor = preg_replace('/\D+/', '', trim((string)$valor)) ?? '';
        return str_pad($valor === '' ? '0' : $valor, $longitud, '0', STR_PAD_LEFT);
    }

    private function getJson(string $url): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host !== 'lcidsig.inegi.org.mx') {
            return null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
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

    private function error(string $mensaje): array
    {
        return ['ok' => false, 'mensaje' => $mensaje];
    }
}
