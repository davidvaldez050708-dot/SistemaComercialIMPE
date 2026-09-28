<?php

require_once __DIR__ . '/../../config/db_connection.php';

/**
 * Importa el tabulado oficial B2020_07_08_M del Censo 2020.
 *
 * El tabulado contiene 5 columnas de desglose y 28 columnas estadísticas.
 * Para evitar dobles conteos sólo se usan categorías de nivel total:
 *
 * Sin media superior:
 *   2 Sin escolaridad
 *   3 Preescolar
 *   4 Primaria total
 *   8 Secundaria total
 *   12 Técnicos/comerciales con primaria terminada
 *
 * Sin educación superior:
 *   categorías anteriores +
 *   13 Técnicos/comerciales con secundaria terminada total
 *   17 Preparatoria/bachillerato total
 *   21 Normal básica
 *
 * Las subcolumnas de grados NO se suman porque ya están contenidas en sus
 * respectivos totales.
 */
class InegiPerfilEducativoPrioritarioImportService
{
    private const FUENTE =
        'INEGI - Censo de Población y Vivienda 2020, tabulado predefinido';
    private const REFERENCIA = 'B2020_07_08_M';
    private const GRUPOS = [
        '25-29',
        '30-34',
        '35-39',
        '40-44',
        '45-49'
    ];

    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function importarXlsx(string $ruta, string $nombreOriginal = ''): array
    {
        if (
            $ruta === '' ||
            !is_file($ruta) ||
            !is_readable($ruta) ||
            !class_exists('ZipArchive') ||
            !class_exists('DOMDocument')
        ) {
            return $this->error(
                'No fue posible leer el XLSX o el servidor no tiene soporte ZIP/XML.'
            );
        }

        $zip = new ZipArchive();
        if ($zip->open($ruta) !== true) {
            return $this->error('El archivo no es un XLSX válido.');
        }

        try {
            $sharedStrings = $this->obtenerSharedStrings($zip);
            $registros = [];
            $estructuraEncontrada = false;

            for ($indice = 0; $indice < $zip->numFiles; $indice++) {
                $estadistica = $zip->statIndex($indice);
                $entrada = (string)($estadistica['name'] ?? '');

                if (!preg_match('#^xl/worksheets/sheet\d+\.xml$#i', $entrada)) {
                    continue;
                }

                $resultado = $this->leerHoja(
                    $zip,
                    $entrada,
                    $sharedStrings
                );

                if (($resultado['estructura'] ?? false) === true) {
                    $estructuraEncontrada = true;
                }

                foreach (($resultado['registros'] ?? []) as $registro) {
                    $clave = implode('|', [
                        $registro['clave_estado'],
                        $registro['clave_municipio'],
                        $registro['grupo_edad']
                    ]);
                    $registros[$clave] = $registro;
                }
            }

            if (!$estructuraEncontrada) {
                return $this->error(
                    'No se reconoció la estructura del tabulado B2020_07_08_M.'
                );
            }

            if (empty($registros)) {
                return $this->error(
                    'El tabulado fue reconocido, pero no se localizaron filas Total para los grupos de 25 a 49 años.'
                );
            }

            $validacion = $this->validarCobertura(array_values($registros));
            if (($validacion['ok'] ?? false) !== true) {
                return $validacion;
            }

            $guardados = $this->guardar(
                array_values($registros),
                basename($nombreOriginal !== '' ? $nombreOriginal : $ruta)
            );

            if (($guardados['ok'] ?? false) !== true) {
                return $guardados;
            }

            return [
                'ok' => true,
                'mensaje' =>
                    'Perfil educativo prioritario importado correctamente desde ' .
                    self::REFERENCIA . '.',
                'registros' => count($registros),
                'estados' => $validacion['estados'] ?? 0,
                'municipios' => $validacion['municipios'] ?? 0
            ];
        } catch (Throwable $error) {
            error_log(
                'Importación perfil educativo prioritario: ' .
                $error->getMessage()
            );

            return $this->error(
                'No fue posible procesar el tabulado oficial.'
            );
        } finally {
            $zip->close();
        }
    }

    private function leerHoja(
        ZipArchive $zip,
        string $entrada,
        array $sharedStrings
    ): array {
        $xml = $zip->getFromName($entrada);

        if ($xml === false || trim($xml) === '') {
            return ['estructura' => false, 'registros' => []];
        }

        $documento = new DOMDocument();
        $estadoAnterior = libxml_use_internal_errors(true);
        $ok = $documento->loadXML(
            $xml,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($estadoAnterior);

        if (!$ok) {
            return ['estructura' => false, 'registros' => []];
        }

        $xpath = new DOMXPath($documento);
        $filas = $xpath->query('//*[local-name()="row"]');

        if ($filas === false) {
            return ['estructura' => false, 'registros' => []];
        }

        $estructura = false;
        $registros = [];
        $estadoActual = '';
        $estadoNombre = '';
        $municipioActual = '000';
        $municipioNombre = '';
        $sexoActual = '';

        foreach ($filas as $filaNodo) {
            $celdas = $this->leerFila(
                $xpath,
                $filaNodo,
                $sharedStrings
            );

            if (empty($celdas)) {
                continue;
            }

            $textoFila = mb_strtolower(
                implode(' ', array_filter($celdas, static function ($v) {
                    return trim((string)$v) !== '';
                })),
                'UTF-8'
            );

            if (
                strpos($textoFila, 'población') !== false &&
                strpos($textoFila, 'escolaridad') !== false
            ) {
                $estructura = true;
            }

            $geoEstado = trim((string)($celdas[1] ?? ''));
            $geoMunicipio = trim((string)($celdas[2] ?? ''));
            $sexo = trim((string)($celdas[3] ?? ''));
            $grupo = $this->normalizarGrupoEdad(
                (string)($celdas[4] ?? '')
            );

            if (preg_match('/^([0-9]{2})\s+(.+)$/u', $geoEstado, $m)) {
                $estadoActual = $m[1];
                $estadoNombre = trim($m[2]);
            }

            if ($geoMunicipio !== '') {
                if ($this->esTotalGeografico($geoMunicipio)) {
                    $municipioActual = '000';
                    $municipioNombre = $estadoNombre;
                } elseif (preg_match('/^([0-9]{3})\s+(.+)$/u', $geoMunicipio, $m)) {
                    $municipioActual = $m[1];
                    $municipioNombre = trim($m[2]);
                }
            }

            if ($sexo !== '') {
                $sexoActual = $this->normalizarTexto($sexo);
            }

            if (
                $estadoActual === '' ||
                !in_array($grupo, self::GRUPOS, true) ||
                $sexoActual !== 'TOTAL'
            ) {
                continue;
            }

            /*
             * En el tabulado B2020_07_08_M:
             * A-E = dimensiones.
             * F-AG = columnas estadísticas 1-28.
             */
            $medidas = [];
            for ($columna = 1; $columna <= 28; $columna++) {
                $indiceCelda = 5 + $columna;
                $medidas[$columna] = $this->entero(
                    $celdas[$indiceCelda] ?? null
                );
            }

            if ($medidas[1] === null || $medidas[1] <= 0) {
                continue;
            }

            $sinMedia = $this->sumaRequerida([
                $medidas[2],
                $medidas[3],
                $medidas[4],
                $medidas[8],
                $medidas[12]
            ]);

            $sinSuperior = $this->sumaRequerida([
                $medidas[2],
                $medidas[3],
                $medidas[4],
                $medidas[8],
                $medidas[12],
                $medidas[13],
                $medidas[17],
                $medidas[21]
            ]);

            if (
                $sinMedia === null ||
                $sinSuperior === null ||
                $sinMedia > $medidas[1] ||
                $sinSuperior > $medidas[1]
            ) {
                continue;
            }

            $clave = implode('|', [
                $estadoActual,
                $municipioActual,
                $grupo
            ]);

            $registros[$clave] = [
                'clave_estado' => $estadoActual,
                'clave_municipio' => $municipioActual,
                'nombre_geografia' =>
                    $municipioActual === '000'
                        ? $estadoNombre
                        : $municipioNombre,
                'grupo_edad' => $grupo,
                'poblacion_total' => $medidas[1],
                'sin_media_superior_concluida' => $sinMedia,
                'sin_superior' => $sinSuperior
            ];
        }

        return [
            'estructura' => $estructura || !empty($registros),
            'registros' => array_values($registros)
        ];
    }

    private function validarCobertura(array $registros): array
    {
        $gruposPorGeo = [];
        $estados = [];
        $municipios = [];

        foreach ($registros as $registro) {
            $estado = (string)($registro['clave_estado'] ?? '');
            $municipio = (string)($registro['clave_municipio'] ?? '');
            $grupo = (string)($registro['grupo_edad'] ?? '');

            if (
                !preg_match('/^\d{2}$/', $estado) ||
                !preg_match('/^\d{3}$/', $municipio) ||
                !in_array($grupo, self::GRUPOS, true)
            ) {
                return $this->error(
                    'El tabulado contiene una clave geográfica o grupo de edad no válido.'
                );
            }

            $geo = $estado . $municipio;
            $gruposPorGeo[$geo][$grupo] = true;
            $estados[$estado] = true;

            if ($municipio !== '000') {
                $municipios[$geo] = true;
            }
        }

        foreach ($gruposPorGeo as $geo => $grupos) {
            if (count($grupos) !== count(self::GRUPOS)) {
                return $this->error(
                    'La geografía ' . $geo .
                    ' no contiene los cinco grupos de edad requeridos.'
                );
            }
        }

        return [
            'ok' => true,
            'estados' => count($estados),
            'municipios' => count($municipios)
        ];
    }

    private function guardar(array $registros, string $archivoOrigen): array
    {
        $this->connection->begin_transaction();

        try {
            $sql = "INSERT INTO perfil_educativo_prioritario (
                        clave_estado,
                        clave_municipio,
                        nombre_geografia,
                        grupo_edad,
                        anio,
                        poblacion_total,
                        sin_media_superior_concluida,
                        sin_superior,
                        fuente,
                        referencia_fuente,
                        archivo_origen,
                        fecha_consulta
                    ) VALUES (?, ?, ?, ?, 2020, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        nombre_geografia = VALUES(nombre_geografia),
                        poblacion_total = VALUES(poblacion_total),
                        sin_media_superior_concluida =
                            VALUES(sin_media_superior_concluida),
                        sin_superior = VALUES(sin_superior),
                        fuente = VALUES(fuente),
                        referencia_fuente = VALUES(referencia_fuente),
                        archivo_origen = VALUES(archivo_origen),
                        fecha_consulta = NOW()";

            $stmt = $this->connection->prepare($sql);

            foreach ($registros as $registro) {
                $estado = (string)$registro['clave_estado'];
                $municipio = (string)$registro['clave_municipio'];
                $nombre = (string)$registro['nombre_geografia'];
                $grupo = (string)$registro['grupo_edad'];
                $poblacion = (int)$registro['poblacion_total'];
                $sinMedia = (int)$registro['sin_media_superior_concluida'];
                $sinSuperior = (int)$registro['sin_superior'];
                $fuente = self::FUENTE;
                $referencia = self::REFERENCIA;

                $stmt->bind_param(
                    'ssssiiisss',
                    $estado,
                    $municipio,
                    $nombre,
                    $grupo,
                    $poblacion,
                    $sinMedia,
                    $sinSuperior,
                    $fuente,
                    $referencia,
                    $archivoOrigen
                );
                $stmt->execute();
            }

            $this->connection->commit();
            return ['ok' => true];
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log(
                'Guardado perfil educativo prioritario: ' .
                $error->getMessage()
            );

            return $this->error(
                'No fue posible guardar el perfil educativo prioritario.'
            );
        }
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

            if (!preg_match('/^([A-Z]+)/', $referencia, $m)) {
                continue;
            }

            $indice = $this->columnaAIndice($m[1]);
            $tipo = strtolower(trim($celda->getAttribute('t')));
            $valor = '';

            if ($tipo === 'inlineStr') {
                $nodos = $xpath->query('.//*[local-name()="t"]', $celda);
                if ($nodos !== false) {
                    foreach ($nodos as $nodo) {
                        $valor .= $nodo->textContent;
                    }
                }
            } else {
                $nodoValor = $xpath->query(
                    './*[local-name()="v"]',
                    $celda
                )?->item(0);
                $crudo = $nodoValor
                    ? (string)$nodoValor->textContent
                    : '';

                if ($tipo === 's' && ctype_digit(trim($crudo))) {
                    $valor = $sharedStrings[(int)trim($crudo)] ?? '';
                } else {
                    $valor = $crudo;
                }
            }

            $resultado[$indice] = trim((string)$valor);
        }

        return $resultado;
    }

    private function obtenerSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false || trim($xml) === '') {
            return [];
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML(
            $xml,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok) {
            return [];
        }

        $xpath = new DOMXPath($doc);
        $elementos = $xpath->query('//*[local-name()="si"]');
        $salida = [];

        if ($elementos === false) {
            return $salida;
        }

        foreach ($elementos as $elemento) {
            $texto = '';
            $nodos = $xpath->query(
                './/*[local-name()="t"]',
                $elemento
            );

            if ($nodos !== false) {
                foreach ($nodos as $nodo) {
                    $texto .= $nodo->textContent;
                }
            }

            $salida[] = trim($texto);
        }

        return $salida;
    }

    private function columnaAIndice(string $letras): int
    {
        $indice = 0;
        foreach (str_split(strtoupper($letras)) as $letra) {
            $indice = ($indice * 26) + (ord($letra) - 64);
        }

        return $indice;
    }

    private function normalizarGrupoEdad(string $valor): string
    {
        $valor = $this->normalizarTexto($valor);
        $valor = str_replace([' AÑOS', ' '], ['', ''], $valor);
        $valor = str_replace(['–', '—'], '-', $valor);

        foreach (self::GRUPOS as $grupo) {
            if ($valor === str_replace('-', '-', $grupo)) {
                return $grupo;
            }
        }

        return '';
    }

    private function normalizarTexto(string $valor): string
    {
        $valor = mb_strtoupper(trim($valor), 'UTF-8');
        $mapa = [
            'Á' => 'A',
            'É' => 'E',
            'Í' => 'I',
            'Ó' => 'O',
            'Ú' => 'U',
            'Ü' => 'U'
        ];

        return strtr($valor, $mapa);
    }

    private function esTotalGeografico(string $valor): bool
    {
        $normalizado = $this->normalizarTexto($valor);

        return in_array(
            $normalizado,
            ['ENTIDAD FEDERATIVA', 'TOTAL', 'ESTADO'],
            true
        );
    }

    private function entero($valor): ?int
    {
        $valor = str_replace([',', ' '], '', trim((string)$valor));

        if ($valor === '' || !preg_match('/^\d+(?:\.0+)?$/', $valor)) {
            return null;
        }

        return (int)$valor;
    }

    private function sumaRequerida(array $valores): ?int
    {
        foreach ($valores as $valor) {
            if ($valor === null) {
                return null;
            }
        }

        return array_sum($valores);
    }

    private function error(string $mensaje): array
    {
        return ['ok' => false, 'mensaje' => $mensaje];
    }
}
