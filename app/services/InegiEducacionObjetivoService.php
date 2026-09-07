<?php

class InegiEducacionObjetivoService
{
    private const PERIODO = 2020;
    private const BASE_URL = 'https://www.inegi.org.mx/contenidos/programas/ccpv/iter/zip/iter2020';

    public function obtenerPorEstado(string $claveEstado): array
    {
        $claveEstado = str_pad(trim($claveEstado), 2, '0', STR_PAD_LEFT);

        if (!preg_match('/^\d{2}$/', $claveEstado)) {
            return $this->respuestaError('La clave del Estado no es válida.');
        }

        $cache = $this->rutaCache($claveEstado);
        $datosCache = $this->leerCache($cache);

        if (is_array($datosCache)) {
            return $datosCache;
        }

        $resultado = $this->descargarYProcesar($claveEstado);

        if (($resultado['ok'] ?? false) === true) {
            $this->guardarCache($cache, $resultado);
        }

        return $resultado;
    }

    private function descargarYProcesar(string $claveEstado): array
    {
        if (!class_exists('ZipArchive')) {
            return $this->respuestaError(
                'La extensión ZIP de PHP no está disponible para leer los datos de INEGI.'
            );
        }

        $url = self::BASE_URL . '/iter_' . $claveEstado . 'csv20.zip';
        $temporal = tempnam(sys_get_temp_dir(), 'inegi_edu_');

        if ($temporal === false) {
            return $this->respuestaError('No fue posible crear el archivo temporal para INEGI.');
        }

        $archivo = fopen($temporal, 'wb');

        if ($archivo === false) {
            @unlink($temporal);
            return $this->respuestaError('No fue posible preparar la descarga de INEGI.');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $archivo,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 55,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'SistemaComercialIMPE/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: application/zip, application/octet-stream;q=0.9, */*;q=0.8'
            ]
        ]);

        $okCurl = curl_exec($ch);
        $codigoHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($ch);
        curl_close($ch);
        fclose($archivo);

        if ($okCurl === false || $errorCurl !== '' || $codigoHttp !== 200) {
            @unlink($temporal);
            return $this->respuestaError(
                'No fue posible descargar el Censo 2020 de INEGI en este momento.'
            );
        }

        $zip = new ZipArchive();

        if ($zip->open($temporal) !== true) {
            @unlink($temporal);
            return $this->respuestaError('INEGI devolvió un archivo que no pudo abrirse.');
        }

        $csvNombre = $this->buscarCsvDatos($zip, $claveEstado);

        if ($csvNombre === null) {
            $zip->close();
            @unlink($temporal);
            return $this->respuestaError('No se encontró el conjunto de datos del Censo 2020.');
        }

        $stream = $zip->getStream($csvNombre);

        if ($stream === false) {
            $zip->close();
            @unlink($temporal);
            return $this->respuestaError('No fue posible leer el conjunto de datos de INEGI.');
        }

        $resultado = $this->procesarCsv($stream, $claveEstado);

        fclose($stream);
        $zip->close();
        @unlink($temporal);

        return $resultado;
    }

    private function buscarCsvDatos(ZipArchive $zip, string $claveEstado): ?string
    {
        $preferido = 'conjunto_de_datos_iter_' . $claveEstado . 'CSV20.csv';

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = (string)$zip->getNameIndex($i);

            if (strcasecmp(basename($nombre), $preferido) === 0) {
                return $nombre;
            }
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = (string)$zip->getNameIndex($i);
            $base = strtolower(basename($nombre));

            if (
                str_ends_with($base, '.csv') &&
                str_contains($base, 'conjunto_de_datos') &&
                str_contains($base, 'iter_' . strtolower($claveEstado))
            ) {
                return $nombre;
            }
        }

        return null;
    }

    private function procesarCsv($stream, string $claveEstado): array
    {
        $encabezado = fgetcsv($stream);

        if (!is_array($encabezado) || empty($encabezado)) {
            return $this->respuestaError('El archivo de INEGI no contiene encabezados válidos.');
        }

        $encabezado = array_map(function ($campo) {
            $campo = preg_replace('/^\xEF\xBB\xBF/', '', (string)$campo);
            return strtoupper(trim($campo));
        }, $encabezado);

        $estado = null;
        $municipios = [];

        while (($fila = fgetcsv($stream)) !== false) {
            if (!is_array($fila) || count($fila) < count($encabezado)) {
                continue;
            }

            $fila = array_slice($fila, 0, count($encabezado));
            $registro = array_combine($encabezado, $fila);

            if (!is_array($registro)) {
                continue;
            }

            $entidad = $this->claveNumerica($registro['ENTIDAD'] ?? '', 2);
            $municipio = $this->claveNumerica($registro['MUN'] ?? '', 3);
            $localidad = $this->claveNumerica($registro['LOC'] ?? '', 4);

            if ($entidad !== $claveEstado || $localidad !== '0000') {
                continue;
            }

            $metricas = $this->construirMetricas($registro);

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
                'No se encontró el total estatal dentro de los datos de INEGI.'
            );
        }

        usort($municipios, function ($a, $b) {
            $aValor = (int)($a['metricas']['fuera_15_24'] ?? 0);
            $bValor = (int)($b['metricas']['fuera_15_24'] ?? 0);

            if ($aValor === $bValor) {
                return strcmp((string)$a['nombre'], (string)$b['nombre']);
            }

            return $bValor <=> $aValor;
        });

        return [
            'ok' => true,
            'fuente' => 'INEGI - Censo de Población y Vivienda 2020 (ITER)',
            'periodo' => (string)self::PERIODO,
            'metodologia' => [
                'secundaria_completa' =>
                    'Personas de 15 años y más cuya máxima escolaridad son 3 grados aprobados de secundaria (P15SEC_CO).',
                'fuera_15_17' =>
                    'Diferencia entre la población de 15 a 17 años y la población del mismo grupo que asiste a la escuela.',
                'fuera_18_24' =>
                    'Diferencia entre la población de 18 a 24 años y la población del mismo grupo que asiste a la escuela.',
                'advertencia' =>
                    'Los indicadores de escolaridad máxima y asistencia son grupos distintos; no deben sumarse ni interpretarse como las mismas personas.'
            ],
            'estado' => $estado,
            'municipios' => $municipios,
            'municipios_total' => count($municipios),
            'generado_at' => date('Y-m-d H:i:s')
        ];
    }

    private function construirMetricas(array $registro): array
    {
        $p15Mas = $this->numero($registro['P_15YMAS'] ?? null);
        $secundaria = $this->numero($registro['P15SEC_CO'] ?? null);
        $p15a17 = $this->numero($registro['P_15A17'] ?? null);
        $asiste15a17 = $this->numero($registro['P15A17A'] ?? null);
        $p18a24 = $this->numero($registro['P_18A24'] ?? null);
        $asiste18a24 = $this->numero($registro['P18A24A'] ?? null);
        $posbasica = $this->numero($registro['P18YM_PB'] ?? null);
        $gradoPromedio = $this->decimal($registro['GRAPROES'] ?? null);

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

    private function numero($valor): ?int
    {
        $valor = trim((string)$valor);

        if ($valor === '' || !preg_match('/^-?\d+$/', $valor)) {
            return null;
        }

        return max(0, (int)$valor);
    }

    private function decimal($valor): ?float
    {
        $valor = trim((string)$valor);

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

    private function claveNumerica($valor, int $longitud): string
    {
        $valor = trim((string)$valor);
        $valor = preg_replace('/\D+/', '', $valor) ?? '';

        if ($valor === '') {
            $valor = '0';
        }

        return str_pad($valor, $longitud, '0', STR_PAD_LEFT);
    }

    private function rutaCache(string $claveEstado): string
    {
        return ROOT_PATH . '/storage/cache/inegi/educacion_objetivo_' .
            self::PERIODO . '_' . $claveEstado . '.json';
    }

    private function leerCache(string $ruta): ?array
    {
        if (!is_file($ruta)) {
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
            'fuente' => 'INEGI - Censo de Población y Vivienda 2020 (ITER)',
            'periodo' => (string)self::PERIODO,
            'estado' => null,
            'municipios' => []
        ];
    }
}
