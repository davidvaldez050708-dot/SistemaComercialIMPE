<?php

class InegiPerfilEducativoMasivoService
{
    public const CODIGO_INDICADOR = 'SECUNDARIA_COMPLETA_15_MAS';
    private const ANIO_REFERENCIA = 2020;
    private const MAX_XLSX_BYTES = 16777216;
    private const MAX_XLSX_EN_ZIP = 40;

    public function leerCarga(string $rutaArchivo, string $nombreOriginal): array
    {
        if ($rutaArchivo === '' || !is_file($rutaArchivo) || !is_readable($rutaArchivo)) {
            return $this->error('No fue posible leer el archivo seleccionado.');
        }

        $extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));

        if ($extension === 'xlsx') {
            return $this->leerXlsx($rutaArchivo, $nombreOriginal);
        }

        if ($extension === 'zip') {
            return $this->leerZip($rutaArchivo, $nombreOriginal);
        }

        return $this->error('El archivo debe estar en formato XLSX o ZIP.');
    }

    private function leerZip(string $rutaArchivo, string $nombreOriginal): array
    {
        if (!class_exists('ZipArchive')) {
            return $this->error('El servidor no tiene habilitado ZipArchive para procesar archivos ZIP/XLSX.');
        }

        $zip = new ZipArchive();

        if ($zip->open($rutaArchivo) !== true) {
            return $this->error('El archivo ZIP no es válido o está dañado.');
        }

        $registros = [];
        $archivosProcesados = 0;
        $temporales = [];

        try {
            for ($indice = 0; $indice < $zip->numFiles; $indice++) {
                $estadistica = $zip->statIndex($indice);
                $entrada = (string)($estadistica['name'] ?? '');

                if ($entrada === '' || strtolower(pathinfo($entrada, PATHINFO_EXTENSION)) !== 'xlsx') {
                    continue;
                }

                $archivosProcesados++;

                if ($archivosProcesados > self::MAX_XLSX_EN_ZIP) {
                    return $this->error('El ZIP contiene más archivos XLSX de los permitidos.');
                }

                $tamano = (int)($estadistica['size'] ?? 0);

                if ($tamano <= 0 || $tamano > self::MAX_XLSX_BYTES) {
                    return $this->error('Uno de los XLSX del ZIP está vacío o supera 16 MB.');
                }

                $contenido = $zip->getFromIndex($indice);

                if ($contenido === false || $contenido === '') {
                    return $this->error('No fue posible leer uno de los XLSX incluidos en el ZIP.');
                }

                $temporal = tempnam(sys_get_temp_dir(), 'impe_edu_');

                if ($temporal === false || file_put_contents($temporal, $contenido) === false) {
                    return $this->error('No fue posible preparar temporalmente uno de los XLSX.');
                }

                $temporales[] = $temporal;
                $resultado = $this->leerXlsx($temporal, basename($entrada));

                if (($resultado['ok'] ?? false) !== true) {
                    return $this->error(
                        'No se pudo procesar “' . basename($entrada) . '”: ' .
                        ($resultado['mensaje'] ?? 'estructura no reconocida.')
                    );
                }

                foreach (($resultado['registros'] ?? []) as $registro) {
                    $clave = (string)($registro['clave_geografica'] ?? '');

                    if ($clave !== '') {
                        $registros[$clave] = $registro;
                    }
                }
            }
        } finally {
            $zip->close();

            foreach ($temporales as $temporal) {
                if (is_file($temporal)) {
                    @unlink($temporal);
                }
            }
        }

        if ($archivosProcesados === 0) {
            return $this->error('El ZIP no contiene archivos XLSX.');
        }

        if (empty($registros)) {
            return $this->error('No se localizaron registros estatales de ITER/SCITEL dentro del ZIP.');
        }

        ksort($registros, SORT_STRING);

        return [
            'ok' => true,
            'registros' => array_values($registros),
            'total_estados' => count($registros),
            'archivos_procesados' => $archivosProcesados,
            'archivo_origen' => basename($nombreOriginal)
        ];
    }

    private function leerXlsx(string $rutaArchivo, string $nombreOriginal): array
    {
        $tamano = filesize($rutaArchivo);

        if ($tamano === false || $tamano <= 0 || $tamano > self::MAX_XLSX_BYTES) {
            return $this->error('El XLSX supera 16 MB o está vacío.');
        }

        if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
            return $this->error('El servidor no tiene habilitado el soporte necesario para leer archivos XLSX.');
        }

        $zip = new ZipArchive();

        if ($zip->open($rutaArchivo) !== true) {
            return $this->error('El archivo seleccionado no es un XLSX válido o está dañado.');
        }

        try {
            $sharedStrings = $this->obtenerSharedStrings($zip);
            $registros = [];
            $estructuraEncontrada = false;
            $hojasEncontradas = 0;
            $anio = $this->detectarAnio($nombreOriginal);

            for ($indice = 0; $indice < $zip->numFiles; $indice++) {
                $estadistica = $zip->statIndex($indice);
                $nombreEntrada = (string)($estadistica['name'] ?? '');

                if (!preg_match('#^xl/worksheets/sheet\d+\.xml$#i', $nombreEntrada)) {
                    continue;
                }

                $hojasEncontradas++;
                $resultadoHoja = $this->leerHojaMasiva($zip, $nombreEntrada, $sharedStrings);

                if (($resultadoHoja['estructura_encontrada'] ?? false) === true) {
                    $estructuraEncontrada = true;
                }

                foreach (($resultadoHoja['registros'] ?? []) as $registro) {
                    $clave = (string)($registro['clave_geografica'] ?? '');

                    if ($clave === '') {
                        continue;
                    }

                    $registro['anio'] = $anio;
                    $registro['codigo_indicador'] = self::CODIGO_INDICADOR;
                    $registro['nombre_indicador'] =
                        'Población de 15 años y más cuya máxima escolaridad es secundaria completa';
                    $registro['grupo_edad'] = '15 años y más';
                    $registro['fuente'] =
                        'INEGI - Censo de Población y Vivienda ' . $anio . ' (ITER/SCITEL)';
                    $registro['archivo_origen'] = basename($nombreOriginal);
                    $registro['tipo_actualizacion'] = 'IMPORTACION';
                    $registros[$clave] = $registro;
                }
            }

            if ($hojasEncontradas === 0) {
                return $this->error('El XLSX no contiene hojas de cálculo reconocibles.');
            }

            if (empty($registros)) {
                return $this->error(
                    $estructuraEncontrada
                        ? 'Se reconoció la estructura ITER/SCITEL, pero no se localizaron totales estatales.'
                        : 'No se localizaron las columnas ENTIDAD, MUN, LOC, P_15YMAS y P15SEC_CO.'
                );
            }

            ksort($registros, SORT_STRING);

            return [
                'ok' => true,
                'registros' => array_values($registros),
                'total_estados' => count($registros),
                'archivos_procesados' => 1,
                'archivo_origen' => basename($nombreOriginal)
            ];
        } catch (Throwable $error) {
            error_log('INEGI perfil educativo masivo: ' . $error->getMessage());
            return $this->error('No fue posible reconocer la estructura del archivo oficial de INEGI.');
        } finally {
            $zip->close();
        }
    }

    private function leerHojaMasiva(
        ZipArchive $zip,
        string $nombreEntrada,
        array $sharedStrings
    ): array {
        $xml = $zip->getFromName($nombreEntrada);

        if ($xml === false || trim($xml) === '') {
            return ['estructura_encontrada' => false, 'registros' => []];
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
            return ['estructura_encontrada' => false, 'registros' => []];
        }

        $xpath = new DOMXPath($documento);
        $filas = $xpath->query('//*[local-name()="row"]');

        if ($filas === false || $filas->length === 0) {
            return ['estructura_encontrada' => false, 'registros' => []];
        }

        $indices = null;
        $registros = [];

        foreach ($filas as $filaNodo) {
            $celdas = $this->leerFila($xpath, $filaNodo, $sharedStrings);

            if (empty($celdas)) {
                continue;
            }

            if ($indices === null) {
                $indices = $this->detectarIndicesCabeceras($celdas);
                continue;
            }

            $claveEntidad = $this->claveGeografica($celdas[$indices['entidad']] ?? '', 2);
            $claveMunicipio = $this->claveGeografica($celdas[$indices['municipio']] ?? '', 3);
            $claveLocalidad = $this->claveGeografica($celdas[$indices['localidad']] ?? '', 4);

            if (
                $claveEntidad === null ||
                !preg_match('/^(0[1-9]|[12][0-9]|3[0-2])$/', $claveEntidad) ||
                $claveMunicipio !== '000' ||
                $claveLocalidad !== '0000'
            ) {
                continue;
            }

            $poblacionBase = $this->enteroOficial($celdas[$indices['poblacion_base']] ?? null);
            $secundariaCompleta = $this->enteroOficial($celdas[$indices['secundaria']] ?? null);

            if (
                $poblacionBase === null ||
                $poblacionBase <= 0 ||
                $secundariaCompleta === null ||
                $secundariaCompleta < 0 ||
                $secundariaCompleta > $poblacionBase
            ) {
                throw new RuntimeException(
                    'Uno de los registros estatales contiene valores educativos no válidos.'
                );
            }

            $registros[$claveEntidad] = [
                'clave_geografica' => $claveEntidad,
                'cantidad_personas' => $secundariaCompleta,
                'poblacion_base' => $poblacionBase,
                'porcentaje' => round(($secundariaCompleta / $poblacionBase) * 100, 2)
            ];
        }

        return [
            'estructura_encontrada' => $indices !== null,
            'registros' => array_values($registros)
        ];
    }

    private function leerFila(DOMXPath $xpath, DOMNode $filaNodo, array $sharedStrings): array
    {
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

        return str_pad((string)(int)$valor, $longitud, '0', STR_PAD_LEFT);
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
        return ['ok' => false, 'mensaje' => $mensaje];
    }
}
