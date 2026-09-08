<?php

class InegiEducacionService
{
    public const CODIGO_INDICADOR = 'SECUNDARIA_COMPLETA_15_MAS';
    private const ANIO_REFERENCIA = 2020;
    private const MAX_ARCHIVO_BYTES = 16777216;

    public function leerArchivo(
        string $rutaArchivo,
        string $claveEstadoEsperada,
        string $nombreOriginal = ''
    ): array {
        $claveEstadoEsperada = str_pad(
            trim($claveEstadoEsperada),
            2,
            '0',
            STR_PAD_LEFT
        );

        if (!preg_match('/^(0[1-9]|[12][0-9]|3[0-2])$/', $claveEstadoEsperada)) {
            return $this->error('La clave INEGI del Estado no es válida.');
        }

        if ($rutaArchivo === '' || !is_file($rutaArchivo) || !is_readable($rutaArchivo)) {
            return $this->error('No fue posible leer el archivo XLSX seleccionado.');
        }

        $tamano = filesize($rutaArchivo);

        if (
            $tamano === false ||
            $tamano <= 0 ||
            $tamano > self::MAX_ARCHIVO_BYTES
        ) {
            return $this->error('El archivo XLSX supera el tamaño permitido o está vacío.');
        }

        if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
            return $this->error(
                'El servidor no tiene habilitado el soporte necesario para leer archivos XLSX.'
            );
        }

        $zip = new ZipArchive();

        if ($zip->open($rutaArchivo) !== true) {
            return $this->error('El archivo seleccionado no es un XLSX válido o está dañado.');
        }

        try {
            $sharedStrings = $this->obtenerSharedStrings($zip);
            $hojasEncontradas = 0;
            $estructuraEncontrada = false;

            for ($indice = 0; $indice < $zip->numFiles; $indice++) {
                $estadistica = $zip->statIndex($indice);
                $nombreEntrada = (string)($estadistica['name'] ?? '');

                if (!preg_match('#^xl/worksheets/sheet\d+\.xml$#i', $nombreEntrada)) {
                    continue;
                }

                $hojasEncontradas++;
                $resultadoHoja = $this->leerHoja(
                    $zip,
                    $nombreEntrada,
                    $sharedStrings,
                    $claveEstadoEsperada
                );

                if (($resultadoHoja['estructura_encontrada'] ?? false) === true) {
                    $estructuraEncontrada = true;
                }

                if (($resultadoHoja['ok'] ?? false) !== true) {
                    continue;
                }

                $anio = $this->detectarAnio($nombreOriginal);
                $resultadoHoja['anio'] = $anio;
                $resultadoHoja['codigo_indicador'] = self::CODIGO_INDICADOR;
                $resultadoHoja['nombre_indicador'] =
                    'Población de 15 años y más cuya máxima escolaridad es secundaria completa';
                $resultadoHoja['grupo_edad'] = '15 años y más';
                $resultadoHoja['fuente'] =
                    'INEGI - Censo de Población y Vivienda ' . $anio . ' (ITER/SCITEL)';
                $resultadoHoja['archivo_origen'] = basename($nombreOriginal);
                $resultadoHoja['tipo_actualizacion'] = 'IMPORTACION';
                unset($resultadoHoja['estructura_encontrada']);

                return $resultadoHoja;
            }

            if ($hojasEncontradas === 0) {
                return $this->error('El XLSX no contiene hojas de cálculo reconocibles.');
            }

            if ($estructuraEncontrada) {
                return $this->error(
                    'El XLSX contiene la estructura de ITER, pero no corresponde al Estado seleccionado.'
                );
            }

            return $this->error(
                'No se localizaron las columnas ENTIDAD, MUN, LOC, P_15YMAS y P15SEC_CO en el XLSX oficial.'
            );
        } catch (Throwable $error) {
            error_log('INEGI educación XLSX: ' . $error->getMessage());
            return $this->error(
                'No fue posible reconocer la estructura del archivo oficial de INEGI.'
            );
        } finally {
            $zip->close();
        }
    }

    private function leerHoja(
        ZipArchive $zip,
        string $nombreEntrada,
        array $sharedStrings,
        string $claveEstadoEsperada
    ): array {
        $xml = $zip->getFromName($nombreEntrada);

        if ($xml === false || trim($xml) === '') {
            return ['ok' => false, 'estructura_encontrada' => false];
        }

        $documento = new DOMDocument();
        $estadoAnterior = libxml_use_internal_errors(true);
        $cargado = $documento->loadXML(
            $xml,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($estadoAnterior);

        if (!$cargado) {
            return ['ok' => false, 'estructura_encontrada' => false];
        }

        $xpath = new DOMXPath($documento);
        $filas = $xpath->query('//*[local-name()="row"]');

        if ($filas === false || $filas->length === 0) {
            return ['ok' => false, 'estructura_encontrada' => false];
        }

        $indices = null;
        $estructuraEncontrada = false;

        foreach ($filas as $filaNodo) {
            $celdas = $this->leerFila($xpath, $filaNodo, $sharedStrings);

            if (empty($celdas)) {
                continue;
            }

            if ($indices === null) {
                $indicesDetectados = $this->detectarIndicesCabeceras($celdas);

                if ($indicesDetectados === null) {
                    continue;
                }

                $indices = $indicesDetectados;
                $estructuraEncontrada = true;
                continue;
            }

            $claveEntidad = $this->claveGeografica(
                $celdas[$indices['entidad']] ?? '',
                2
            );
            $claveMunicipio = $this->claveGeografica(
                $celdas[$indices['municipio']] ?? '',
                3
            );
            $claveLocalidad = $this->claveGeografica(
                $celdas[$indices['localidad']] ?? '',
                4
            );

            if (
                $claveEntidad !== $claveEstadoEsperada ||
                $claveMunicipio !== '000' ||
                $claveLocalidad !== '0000'
            ) {
                continue;
            }

            $poblacionBase = $this->enteroOficial(
                $celdas[$indices['poblacion_base']] ?? null
            );
            $secundariaCompleta = $this->enteroOficial(
                $celdas[$indices['secundaria']] ?? null
            );

            if (
                $poblacionBase === null ||
                $poblacionBase <= 0 ||
                $secundariaCompleta === null ||
                $secundariaCompleta < 0 ||
                $secundariaCompleta > $poblacionBase
            ) {
                throw new RuntimeException(
                    'El registro estatal contiene valores educativos no válidos.'
                );
            }

            return [
                'ok' => true,
                'estructura_encontrada' => true,
                'clave_geografica' => $claveEstadoEsperada,
                'cantidad_personas' => $secundariaCompleta,
                'poblacion_base' => $poblacionBase,
                'porcentaje' => round(
                    ($secundariaCompleta / $poblacionBase) * 100,
                    2
                )
            ];
        }

        return [
            'ok' => false,
            'estructura_encontrada' => $estructuraEncontrada
        ];
    }

    private function leerFila(
        DOMXPath $xpath,
        DOMNode $filaNodo,
        array $sharedStrings
    ): array {
        $resultado = [];
        $celdas = $xpath->query('./*[local-name()="c"]', $filaNodo);

        if ($celdas === false) {
            return $resultado;
        }

        foreach ($celdas as $celda) {
            if (!$celda instanceof DOMElement) {
                continue;
            }

            $referencia = strtoupper(trim($celda->getAttribute('r')));

            if (!preg_match('/^([A-Z]+)/', $referencia, $coincidencia)) {
                continue;
            }

            $columna = $this->columnaAIndice($coincidencia[1]);
            $tipo = strtolower(trim($celda->getAttribute('t')));
            $valor = '';

            if ($tipo === 'inlineStr') {
                $textos = $xpath->query('.//*[local-name()="t"]', $celda);

                if ($textos !== false) {
                    foreach ($textos as $textoNodo) {
                        $valor .= $textoNodo->textContent;
                    }
                }
            } else {
                $nodoValor = $xpath->query('./*[local-name()="v"]', $celda)?->item(0);
                $valorCrudo = $nodoValor ? (string)$nodoValor->textContent : '';

                if ($tipo === 's' && preg_match('/^\d+$/', trim($valorCrudo))) {
                    $indiceShared = (int)trim($valorCrudo);
                    $valor = $sharedStrings[$indiceShared] ?? '';
                } else {
                    $valor = $valorCrudo;
                }
            }

            $resultado[$columna] = trim((string)$valor);
        }

        return $resultado;
    }

    private function detectarIndicesCabeceras(array $celdas): ?array
    {
        $mapa = [];

        foreach ($celdas as $indice => $valor) {
            $cabecera = $this->normalizarCabecera((string)$valor);

            if ($cabecera !== '') {
                $mapa[$cabecera] = (int)$indice;
            }
        }

        $entidad = $mapa['ENTIDAD'] ?? $mapa['ENT'] ?? null;
        $municipio = $mapa['MUN'] ?? null;
        $localidad = $mapa['LOC'] ?? null;
        $poblacionBase = $mapa['P_15YMAS'] ?? null;
        $secundaria = $mapa['P15SEC_CO'] ?? null;

        if (
            $entidad === null ||
            $municipio === null ||
            $localidad === null ||
            $poblacionBase === null ||
            $secundaria === null
        ) {
            return null;
        }

        return [
            'entidad' => $entidad,
            'municipio' => $municipio,
            'localidad' => $localidad,
            'poblacion_base' => $poblacionBase,
            'secundaria' => $secundaria
        ];
    }

    private function obtenerSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false || trim($xml) === '') {
            return [];
        }

        $documento = new DOMDocument();
        $estadoAnterior = libxml_use_internal_errors(true);
        $cargado = $documento->loadXML(
            $xml,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($estadoAnterior);

        if (!$cargado) {
            return [];
        }

        $xpath = new DOMXPath($documento);
        $elementos = $xpath->query('//*[local-name()="si"]');
        $sharedStrings = [];

        if ($elementos === false) {
            return $sharedStrings;
        }

        foreach ($elementos as $elemento) {
            $texto = '';
            $nodosTexto = $xpath->query('.//*[local-name()="t"]', $elemento);

            if ($nodosTexto !== false) {
                foreach ($nodosTexto as $nodoTexto) {
                    $texto .= $nodoTexto->textContent;
                }
            }

            $sharedStrings[] = trim($texto);
        }

        return $sharedStrings;
    }

    private function columnaAIndice(string $letras): int
    {
        $indice = 0;
        $letras = strtoupper($letras);

        for ($i = 0, $longitud = strlen($letras); $i < $longitud; $i++) {
            $indice = ($indice * 26) + (ord($letras[$i]) - 64);
        }

        return $indice;
    }

    private function normalizarCabecera(string $valor): string
    {
        $valor = preg_replace('/^\xEF\xBB\xBF/', '', $valor);
        $valor = strtoupper(trim((string)$valor));
        return preg_replace('/\s+/', ' ', $valor) ?? $valor;
    }

    private function claveGeografica($valor, int $longitud): ?string
    {
        $valor = trim((string)$valor);

        if ($valor === '' || !preg_match('/^\d+(?:\.0+)?$/', $valor)) {
            return null;
        }

        return str_pad(
            (string)(int)$valor,
            $longitud,
            '0',
            STR_PAD_LEFT
        );
    }

    private function enteroOficial($valor): ?int
    {
        $valor = trim((string)$valor);

        if ($valor === '' || !preg_match('/^\d+(?:\.0+)?$/', $valor)) {
            return null;
        }

        return (int)$valor;
    }

    private function detectarAnio(string $nombreOriginal): int
    {
        $nombreOriginal = basename(trim($nombreOriginal));

        if (
            preg_match('/(?:^|[^0-9])(20\d{2})(?:[^0-9]|$)/', $nombreOriginal, $coincidencia) &&
            (int)$coincidencia[1] >= 2000 &&
            (int)$coincidencia[1] <= 2100
        ) {
            return (int)$coincidencia[1];
        }

        return self::ANIO_REFERENCIA;
    }

    private function error(string $mensaje): array
    {
        return [
            'ok' => false,
            'mensaje' => $mensaje
        ];
    }
}
