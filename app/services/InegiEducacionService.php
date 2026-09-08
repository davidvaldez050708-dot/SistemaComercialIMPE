<?php

class InegiEducacionService
{
    public const CODIGO_INDICADOR = 'SECUNDARIA_COMPLETA_15_MAS';
    private const ANIO_CENSO = 2020;
    private const MAX_DESCARGA_BYTES = 120000000;

    public function obtenerPoblacionObjetivoEstado(string $claveInegi): array
    {
        $claveInegi = str_pad(trim($claveInegi), 2, '0', STR_PAD_LEFT);

        if (!preg_match('/^(0[1-9]|[12][0-9]|3[0-2])$/', $claveInegi)) {
            return $this->error('La clave INEGI del Estado no es válida.');
        }

        if (!class_exists('ZipArchive')) {
            return $this->error('El servidor no tiene habilitado ZipArchive para consultar el archivo oficial de INEGI.');
        }

        if (!function_exists('curl_init')) {
            return $this->error('El servidor no tiene habilitado cURL para consultar la información oficial de INEGI.');
        }

        $urls = [
            'https://www.inegi.org.mx/contenidos/programas/ccpv/2020/datosabiertos/iter/iter_' .
                $claveInegi . '_cpv2020_csv.zip',
            'https://www.inegi.org.mx/contenidos/programas/ccpv/iter/zip/iter2020/iter_' .
                $claveInegi . 'csv20.zip'
        ];

        $descarga = $this->descargarArchivoOficial($urls);

        if (($descarga['ok'] ?? false) !== true) {
            return $descarga;
        }

        $rutaZip = (string)$descarga['ruta'];

        try {
            $lectura = $this->leerIndicadorDesdeZip($rutaZip, $claveInegi);
        } catch (Throwable $error) {
            error_log('INEGI educación: ' . $error->getMessage());
            $lectura = $this->error(
                'No fue posible reconocer el indicador educativo dentro del archivo oficial de INEGI.'
            );
        } finally {
            if (is_file($rutaZip)) {
                @unlink($rutaZip);
            }
        }

        if (($lectura['ok'] ?? false) !== true) {
            return $lectura;
        }

        $lectura['fuente'] = 'INEGI - Censo de Población y Vivienda 2020 (ITER/SCITEL)';
        $lectura['url_fuente'] = (string)($descarga['url'] ?? '');
        $lectura['archivo_origen'] = basename((string)($descarga['url'] ?? ''));
        $lectura['tipo_actualizacion'] = 'AUTOMATICA';

        return $lectura;
    }

    private function descargarArchivoOficial(array $urls): array
    {
        foreach ($urls as $url) {
            $rutaTemporal = tempnam(sys_get_temp_dir(), 'impe_educacion_');

            if ($rutaTemporal === false) {
                return $this->error('No fue posible preparar la descarga temporal de INEGI.');
            }

            $archivo = fopen($rutaTemporal, 'wb');

            if ($archivo === false) {
                @unlink($rutaTemporal);
                return $this->error('No fue posible preparar el archivo temporal de INEGI.');
            }

            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_FILE => $archivo,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 90,
                CURLOPT_FAILONERROR => false,
                CURLOPT_USERAGENT => 'SistemaComercialIMPE/1.0',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ]);

            $ejecutado = curl_exec($curl);
            $codigoHttp = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $errorCurl = curl_error($curl);
            curl_close($curl);
            fclose($archivo);

            $tamano = is_file($rutaTemporal) ? (int)filesize($rutaTemporal) : 0;

            if (
                $ejecutado !== false &&
                $codigoHttp >= 200 &&
                $codigoHttp < 300 &&
                $tamano > 0 &&
                $tamano <= self::MAX_DESCARGA_BYTES
            ) {
                return [
                    'ok' => true,
                    'ruta' => $rutaTemporal,
                    'url' => $url
                ];
            }

            @unlink($rutaTemporal);

            if ($errorCurl !== '') {
                error_log('INEGI educación descarga: ' . $errorCurl);
            }
        }

        return $this->error(
            'INEGI no devolvió el archivo de datos educativos para el Estado seleccionado.'
        );
    }

    private function leerIndicadorDesdeZip(string $rutaZip, string $claveInegi): array
    {
        $zip = new ZipArchive();

        if ($zip->open($rutaZip) !== true) {
            return $this->error('El archivo descargado de INEGI no es un ZIP válido.');
        }

        try {
            $entradaCsv = $this->buscarEntradaCsv($zip);

            if ($entradaCsv === null) {
                return $this->error('El archivo oficial de INEGI no contiene el CSV esperado.');
            }

            $flujo = $zip->getStream($entradaCsv);

            if ($flujo === false) {
                return $this->error('No fue posible leer el CSV oficial de INEGI.');
            }

            try {
                $primeraLinea = fgets($flujo);

                if ($primeraLinea === false) {
                    return $this->error('El CSV oficial de INEGI está vacío.');
                }

                $delimitador = $this->detectarDelimitador($primeraLinea);
                $cabeceras = str_getcsv($primeraLinea, $delimitador);
                $cabeceras = array_map([$this, 'normalizarCabecera'], $cabeceras);
                $indices = $this->obtenerIndices($cabeceras);

                foreach (['entidad', 'municipio', 'localidad', 'poblacion_base', 'secundaria'] as $campo) {
                    if ($indices[$campo] === null) {
                        throw new RuntimeException(
                            'No se encontró la columna requerida: ' . $campo
                        );
                    }
                }

                while (($fila = fgetcsv($flujo, 0, $delimitador)) !== false) {
                    if (count($fila) < count($cabeceras)) {
                        continue;
                    }

                    $entidad = str_pad(
                        trim((string)($fila[$indices['entidad']] ?? '')),
                        2,
                        '0',
                        STR_PAD_LEFT
                    );
                    $municipio = str_pad(
                        trim((string)($fila[$indices['municipio']] ?? '')),
                        3,
                        '0',
                        STR_PAD_LEFT
                    );
                    $localidad = str_pad(
                        trim((string)($fila[$indices['localidad']] ?? '')),
                        4,
                        '0',
                        STR_PAD_LEFT
                    );

                    if (
                        $entidad !== $claveInegi ||
                        $municipio !== '000' ||
                        $localidad !== '0000'
                    ) {
                        continue;
                    }

                    $poblacionBase = $this->enteroOficial(
                        $fila[$indices['poblacion_base']] ?? null
                    );
                    $secundariaCompleta = $this->enteroOficial(
                        $fila[$indices['secundaria']] ?? null
                    );

                    if (
                        $poblacionBase === null ||
                        $poblacionBase <= 0 ||
                        $secundariaCompleta === null ||
                        $secundariaCompleta < 0 ||
                        $secundariaCompleta > $poblacionBase
                    ) {
                        return $this->error(
                            'El total estatal de INEGI contiene valores educativos no válidos.'
                        );
                    }

                    return [
                        'ok' => true,
                        'clave_geografica' => $claveInegi,
                        'anio' => self::ANIO_CENSO,
                        'codigo_indicador' => self::CODIGO_INDICADOR,
                        'nombre_indicador' =>
                            'Población de 15 años y más con secundaria completa',
                        'grupo_edad' => '15 años y más',
                        'cantidad_personas' => $secundariaCompleta,
                        'poblacion_base' => $poblacionBase,
                        'porcentaje' => round(
                            ($secundariaCompleta / $poblacionBase) * 100,
                            2
                        )
                    ];
                }

                return $this->error(
                    'No se encontró el total estatal dentro del archivo oficial de INEGI.'
                );
            } finally {
                fclose($flujo);
            }
        } finally {
            $zip->close();
        }
    }

    private function buscarEntradaCsv(ZipArchive $zip): ?string
    {
        $candidatos = [];

        for ($indice = 0; $indice < $zip->numFiles; $indice++) {
            $estadistica = $zip->statIndex($indice);
            $nombre = (string)($estadistica['name'] ?? '');

            if (strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'csv') {
                continue;
            }

            $prioridad = stripos($nombre, 'conjunto_de_datos') !== false ? 0 : 1;
            $candidatos[] = [
                'nombre' => $nombre,
                'prioridad' => $prioridad,
                'tamano' => (int)($estadistica['size'] ?? 0)
            ];
        }

        if (empty($candidatos)) {
            return null;
        }

        usort($candidatos, function ($a, $b) {
            if ($a['prioridad'] !== $b['prioridad']) {
                return $a['prioridad'] <=> $b['prioridad'];
            }

            return $b['tamano'] <=> $a['tamano'];
        });

        return $candidatos[0]['nombre'];
    }

    private function detectarDelimitador(string $linea): string
    {
        $candidatos = [',', "\t", ';', '|'];
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

    private function normalizarCabecera(string $cabecera): string
    {
        $cabecera = preg_replace('/^\xEF\xBB\xBF/', '', $cabecera);
        return strtoupper(trim((string)$cabecera));
    }

    private function obtenerIndices(array $cabeceras): array
    {
        return [
            'entidad' => $this->indiceCabecera($cabeceras, ['ENTIDAD', 'ENT']),
            'municipio' => $this->indiceCabecera($cabeceras, ['MUN']),
            'localidad' => $this->indiceCabecera($cabeceras, ['LOC']),
            'poblacion_base' => $this->indiceCabecera($cabeceras, ['P_15YMAS']),
            'secundaria' => $this->indiceCabecera($cabeceras, ['P15SEC_CO'])
        ];
    }

    private function indiceCabecera(array $cabeceras, array $nombres): ?int
    {
        foreach ($nombres as $nombre) {
            $indice = array_search($nombre, $cabeceras, true);

            if ($indice !== false) {
                return (int)$indice;
            }
        }

        return null;
    }

    private function enteroOficial($valor): ?int
    {
        $valor = trim((string)$valor);

        if ($valor === '' || !preg_match('/^\d+$/', $valor)) {
            return null;
        }

        return (int)$valor;
    }

    private function error(string $mensaje): array
    {
        return [
            'ok' => false,
            'mensaje' => $mensaje
        ];
    }
}
