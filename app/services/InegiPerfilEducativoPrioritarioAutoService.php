<?php

require_once __DIR__ . '/../models/PerfilEducativoPrioritarioModel.php';
require_once __DIR__ . '/InegiPerfilEducativoPrioritarioImportService.php';
require_once __DIR__ . '/InegiEscolaridadAdultaXlsxService.php';

/**
 * Busca automáticamente el tabulado educativo prioritario en fuentes oficiales
 * de INEGI. Si no localiza una descarga compatible, no estima cifras: conserva
 * el estado "pendiente" y vuelve a intentar después del TTL.
 */
class InegiPerfilEducativoPrioritarioAutoService
{
    private const TTL_FALLO = 21600; // 6 horas
    private const MAX_BYTES = 134217728; // 128 MB; algunos tabulados estatales son voluminosos
    private const CACHE_VERSION = 'v2_direct_tabulados';

    private const PAGINAS_DESCUBRIMIENTO = [
        'https://www.inegi.org.mx/programas/ccpv/2020/#Tabulados',
        'https://www.inegi.org.mx/sistemas/Olap/Proyectos/bd/censos/cpv2020/P3Mas.asp'
    ];

    public function obtenerOActualizar(
        int $estadoId,
        string $claveEstado,
        bool $permitirActualizacion = true,
        bool $forzarActualizacion = false
    ): array
    {
        $modelo = new PerfilEducativoPrioritarioModel();
        $actual = $modelo->obtenerPorEstado($estadoId, $claveEstado);

        if (
            ($actual['disponible'] ?? false) === true &&
            !$forzarActualizacion
        ) {
            $actual['actualizacion_automatica'] = [
                'intentada' => false,
                'estado' => 'DISPONIBLE'
            ];
            return $actual;
        }

        if (!$modelo->tablaDisponible()) {
            $actual['actualizacion_automatica'] = [
                'intentada' => false,
                'estado' => 'MIGRACION_PENDIENTE',
                'mensaje' => 'Falta aplicar la migración del perfil educativo prioritario.'
            ];
            return $actual;
        }

        if (!$permitirActualizacion) {
            $actual['actualizacion_automatica'] = [
                'intentada' => false,
                'estado' => 'PENDIENTE_SINCRONIZACION',
                'mensaje' =>
                    'El cruce oficial de 25 a 49 años todavía no está sincronizado para este territorio. Un administrador puede cargarlo desde “Actualizar información oficial”.'
            ];
            return $actual;
        }

        $estadoCache = $this->leerEstadoIntento($claveEstado);

        if (
            !$forzarActualizacion &&
            is_array($estadoCache) &&
            (int)($estadoCache['timestamp'] ?? 0) > time() - self::TTL_FALLO
        ) {
            $actual['actualizacion_automatica'] = [
                'intentada' => false,
                'estado' => (string)($estadoCache['estado'] ?? 'PENDIENTE'),
                'mensaje' => (string)($estadoCache['mensaje'] ?? '')
            ];
            return $actual;
        }

        $resultado = $this->actualizarDesdeInegi($claveEstado);

        if (($resultado['ok'] ?? false) === true) {
            $this->guardarEstadoIntento($claveEstado, [
                'estado' => 'ACTUALIZADO',
                'mensaje' => (string)($resultado['mensaje'] ?? '')
            ]);

            $actual = $modelo->obtenerPorEstado($estadoId, $claveEstado);
            $actual['actualizacion_automatica'] = [
                'intentada' => true,
                'estado' => ($actual['disponible'] ?? false)
                    ? 'ACTUALIZADO'
                    : 'SIN_DATOS_ESTADO',
                'mensaje' => (string)($resultado['mensaje'] ?? '')
            ];

            return $actual;
        }

        $mensaje = trim((string)($resultado['mensaje'] ?? ''));
        if ($mensaje === '') {
            $mensaje =
                'INEGI no expuso una descarga compatible del cruce edad × escolaridad en esta consulta.';
        }

        $this->guardarEstadoIntento($claveEstado, [
            'estado' => 'FUENTE_NO_LOCALIZADA',
            'mensaje' => $mensaje
        ]);

        $actual['actualizacion_automatica'] = [
            'intentada' => true,
            'estado' => 'FUENTE_NO_LOCALIZADA',
            'mensaje' => $mensaje
        ];

        return $actual;
    }

    /** Reutiliza el descubrimiento/descarga oficial para los dos indicadores adultos. */
    public function actualizarEscolaridadAdulta(string $claveEstado): array
    {
        return $this->actualizarDesdeInegi($claveEstado, true);
    }

    private function actualizarDesdeInegi(string $claveEstado, bool $soloAdultos = false): array
    {
        if (!function_exists('curl_init')) {
            return $this->error('El servidor no tiene cURL habilitado.');
        }

        $urls = [];

        /*
         * Los tabulados del Censo 2020 sí tienen una ruta oficial estable:
         *   /contenidos/programas/ccpv/2020/tabulados/
         *   cpv2020_b_<abreviatura>_07_educacion.xlsx
         *
         * Ejemplos documentados públicamente:
         *   cpv2020_b_eum_07_educacion.xlsx
         *   cpv2020_b_mex_07_educacion.xlsx
         *
         * El archivo estatal contiene el bloque municipal que necesitamos,
         * por lo que se intenta antes que cualquier mecanismo de descubrimiento.
         */
        foreach ($this->urlsTabuladoEstado($claveEstado) as $urlDirecta) {
            $urls[] = $urlDirecta;
        }

        /*
         * INEGI está devolviendo HTML al pedir la publicación municipal de
         * Oaxaca. Su libro nacional sí incluye el desglose por Estado, sexo,
         * edad y las 28 categorías del cuadro de escolaridad. Usar solo como
         * respaldo para la sincronización por edades de Oaxaca; no sirve para
         * el perfil municipal de 25–49 ni para otros Estados sin comprobarlos.
         */
        if ($soloAdultos && $claveEstado === '20') {
            $urls[] = 'https://www.inegi.org.mx/contenidos/programas/ccpv/2020/tabulados/' .
                'cpv2020_b_eum_07_educacion.xlsx';
        }

        $urlConfigurada = trim((string)getenv('INEGI_EDU_PRIORITARIO_URL'));
        if ($urlConfigurada !== '' && $this->esUrlInegi($urlConfigurada)) {
            $urls[] = $urlConfigurada;
        }

        foreach (self::PAGINAS_DESCUBRIMIENTO as $pagina) {
            $html = $this->descargarTexto($pagina);

            if ($html === null) {
                continue;
            }

            foreach ($this->extraerEnlacesCompatibles($html, $pagina) as $url) {
                $urls[] = $url;
            }
        }

        $urls = array_values(array_unique($urls));

        if (empty($urls)) {
            return $this->error(
                'No se pudo construir una ruta oficial de tabulados educativos para el Estado.'
            );
        }

        $erroresIntentos = [];

        foreach ($urls as $url) {
            $resultado = $this->procesarDescarga(
                $url,
                $claveEstado,
                $soloAdultos
            );

            if (($resultado['ok'] ?? false) === true) {
                return $resultado;
            }

            $mensajeIntento =
                trim((string)($resultado['mensaje'] ?? ''));

            if ($mensajeIntento !== '') {
                $rutaIntento =
                    basename(
                        (string)parse_url(
                            $url,
                            PHP_URL_PATH
                        )
                    );

                $erroresIntentos[] =
                    ($rutaIntento !== ''
                        ? $rutaIntento . ': '
                        : '') .
                    $mensajeIntento;
            }
        }

        $erroresIntentos =
            array_values(
                array_unique($erroresIntentos)
            );

        return $this->error(
            !empty($erroresIntentos)
                ? implode(
                    ' | ',
                    array_slice($erroresIntentos, 0, 4)
                )
                : 'Las descargas oficiales localizadas no tuvieron una estructura compatible.'
        );
    }

    private function urlsTabuladoEstado(string $claveEstado): array
    {
        /*
         * INEGI no usa una convención única de abreviaturas en todos sus
         * productos históricos. Para algunos tabulados 2020 el nombre físico
         * difiere de la abreviatura habitual (p. ej. Campeche=cam,
         * Chiapas=chs). Conservamos variantes para que una respuesta 404/HTML
         * no bloquee la sincronización del Estado.
         */
        $abreviaturas = [
            '01' => ['ags'],
            '02' => ['bc'],
            '03' => ['bcs'],
            '04' => ['cam', 'camp'],
            '05' => ['coa', 'coah'],
            '06' => ['col'],
            '07' => ['chs', 'chis'],
            '08' => ['chh', 'chih'],
            '09' => ['cdmx', 'df'],
            '10' => ['dgo'],
            '11' => ['gto'],
            '12' => ['gro'],
            '13' => ['hgo'],
            '14' => ['jal'],
            '15' => ['mex'],
            '16' => ['mich', 'mic'],
            '17' => ['mor'],
            '18' => ['nay'],
            '19' => ['nl'],
            '20' => ['oax'],
            '21' => ['pue'],
            '22' => ['qro'],
            '23' => ['qroo'],
            '24' => ['slp'],
            '25' => ['sin'],
            '26' => ['son'],
            '27' => ['tab'],
            '28' => ['tam', 'tamps'],
            '29' => ['tla', 'tlax'],
            '30' => ['ver'],
            '31' => ['yuc'],
            '32' => ['zac']
        ];

        $claveEstado = str_pad(
            preg_replace('/\D+/', '', $claveEstado) ?? '',
            2,
            '0',
            STR_PAD_LEFT
        );
        $variantes = $abreviaturas[$claveEstado] ?? [];

        if (empty($variantes)) {
            return [];
        }

        $base = 'https://www.inegi.org.mx/contenidos/programas/ccpv/2020/tabulados/';
        $urls = [];

        foreach ($variantes as $abreviatura) {
            $prefijo =
                $base .
                'cpv2020_b_' .
                $abreviatura .
                '_07_educacion';

            /*
             * La mayoría de las entidades responde el XLSX directamente,
             * pero algunas publicaciones históricas de INEGI pueden estar
             * empaquetadas. Probar ambos formatos es seguro porque la descarga
             * valida firma y estructura antes de importar.
             */
            $urls[] = $prefijo . '.xlsx';
            $urls[] = $prefijo . '.zip';
        }

        return array_values(array_unique($urls));
    }

    private function extraerEnlacesCompatibles(string $html, string $base): array
    {
        $salida = [];

        preg_match_all(
            '/(?:href|src)\s*=\s*["\']([^"\']+)["\']/iu',
            $html,
            $m
        );

        foreach (($m[1] ?? []) as $enlace) {
            $enlace = html_entity_decode((string)$enlace, ENT_QUOTES, 'UTF-8');
            $texto = mb_strtolower($enlace, 'UTF-8');

            if (
                strpos($texto, '07_08') === false &&
                strpos($texto, 'b2020_07_08_m') === false &&
                strpos($texto, '_07_educacion') === false
            ) {
                continue;
            }

            if (!preg_match('/\.(xlsx|zip)(?:\?|$)/i', $enlace)) {
                continue;
            }

            $absoluta = $this->urlAbsoluta($enlace, $base);

            if ($absoluta !== '' && $this->esUrlInegi($absoluta)) {
                $salida[] = $absoluta;
            }
        }

        return array_values(array_unique($salida));
    }

    private function procesarDescarga(
        string $url,
        string $claveEstado,
        bool $soloAdultos = false
    ): array
    {
        $temporal = tempnam(sys_get_temp_dir(), 'inegi_edu_prior_');

        if ($temporal === false) {
            return $this->error('No fue posible preparar la descarga temporal.');
        }

        $descarga = [
            'ok' => false,
            'mensaje' => 'No fue posible descargar la fuente oficial localizada.'
        ];

        for ($intento = 1; $intento <= 3; $intento++) {
            $descarga =
                $this->descargarArchivo(
                    $url,
                    $temporal
                );

            if (($descarga['ok'] ?? false) === true) {
                break;
            }

            $mensajeDescarga =
                strtolower(
                    trim(
                        (string)($descarga['mensaje'] ?? '')
                    )
                );
            $errorDeterminista =
                strpos($mensajeDescarga, 'text/html') !== false ||
                strpos($mensajeDescarga, 'no es zip/xlsx') !== false ||
                strpos($mensajeDescarga, 'no contiene la estructura') !== false;

            if ($errorDeterminista || $intento >= 3) {
                break;
            }

            usleep(
                $intento === 1
                    ? 900000
                    : 1800000
            );
        }

        if (($descarga['ok'] ?? false) !== true) {
            @unlink($temporal);
            return $descarga;
        }

        $extension = strtolower((string)($descarga['extension'] ?? ''));

        try {
            if ($extension === 'xlsx' && $soloAdultos) {
                return (new InegiEscolaridadAdultaXlsxService())->importarXlsx(
                    $temporal,
                    basename((string)parse_url($url, PHP_URL_PATH)),
                    $claveEstado,
                    $url
                );
            }

            if ($extension === 'xlsx') {
                return (new InegiPerfilEducativoPrioritarioImportService())
                    ->importarXlsx(
                        $temporal,
                        basename(
                            (string)parse_url(
                                $url,
                                PHP_URL_PATH
                            )
                        ),
                        $claveEstado
                    );
            }

            if ($extension === 'zip') {
                return $this->procesarZip(
                    $temporal,
                    $claveEstado,
                    $soloAdultos,
                    $url
                );
            }

            return $this->error('INEGI devolvió un formato no compatible.');
        } finally {
            @unlink($temporal);
        }
    }

    private function procesarZip(
        string $ruta,
        string $claveEstado,
        bool $soloAdultos = false,
        string $urlFuente = ''
    ): array
    {
        if (!class_exists('ZipArchive')) {
            return $this->error('El servidor no tiene ZipArchive habilitado.');
        }

        $zip = new ZipArchive();

        if ($zip->open($ruta) !== true) {
            return $this->error('El ZIP oficial no pudo abrirse.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nombre = (string)$zip->getNameIndex($i);
                $normalizado = mb_strtolower($nombre, 'UTF-8');

                if (
                    strtolower(
                        pathinfo(
                            $nombre,
                            PATHINFO_EXTENSION
                        )
                    ) !== 'xlsx'
                ) {
                    continue;
                }

                $contenido = $zip->getFromIndex($i);
                if ($contenido === false || $contenido === '') {
                    continue;
                }

                $tmp = tempnam(sys_get_temp_dir(), 'inegi_edu_xlsx_');
                if ($tmp === false) {
                    continue;
                }

                try {
                    if (file_put_contents($tmp, $contenido) === false) {
                        continue;
                    }

                    $resultado = $soloAdultos
                        ? (new InegiEscolaridadAdultaXlsxService())->importarXlsx(
                            $tmp, basename($nombre), $claveEstado, $urlFuente
                        )
                        : (new InegiPerfilEducativoPrioritarioImportService())
                            ->importarXlsx($tmp, basename($nombre), $claveEstado);

                    if (($resultado['ok'] ?? false) === true) {
                        return $resultado;
                    }
                } finally {
                    @unlink($tmp);
                }
            }
        } finally {
            $zip->close();
        }

        return $this->error(
            'El ZIP oficial no contiene el tabulado B2020_07_08_M en XLSX.'
        );
    }

    private function descargarTexto(string $url): ?string
    {
        if (!$this->esUrlInegi($url)) {
            return null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'SistemaComercialIMPE/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5'
            ]
        ]);

        $texto = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);

        return $texto !== false && $error === '' && $http >= 200 && $http < 300
            ? (string)$texto
            : null;
    }

    private function descargarArchivo(
        string $url,
        string $destino
    ): array {
        if (!$this->esUrlInegi($url)) {
            return $this->error(
                'La URL localizada no pertenece a INEGI.'
            );
        }

        $archivo = fopen($destino, 'wb');

        if ($archivo === false) {
            return $this->error(
                'No fue posible crear el archivo temporal.'
            );
        }

        $bytes = 0;
        $exceso = false;
        $ch = curl_init();

        if ($ch === false) {
            fclose($archivo);
            return $this->error(
                'No fue posible inicializar la descarga oficial.'
            );
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT =>
                'Mozilla/5.0 SistemaComercialIMPE/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream;q=0.9,*/*;q=0.5',
                'Cache-Control: no-cache',
                'Pragma: no-cache'
            ],
            CURLOPT_WRITEFUNCTION =>
                static function (
                    $curl,
                    $bloque
                ) use (
                    $archivo,
                    &$bytes,
                    &$exceso
                ) {
                    $longitud =
                        strlen((string)$bloque);
                    $bytes += $longitud;

                    if ($bytes > self::MAX_BYTES) {
                        $exceso = true;
                        return 0;
                    }

                    $escritos =
                        fwrite(
                            $archivo,
                            (string)$bloque
                        );

                    return $escritos === false
                        ? 0
                        : $escritos;
                }
        ]);

        $ok = curl_exec($ch);
        $http =
            (int)curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );
        $tipo =
            strtolower(
                (string)curl_getinfo(
                    $ch,
                    CURLINFO_CONTENT_TYPE
                )
            );
        $urlFinal =
            (string)curl_getinfo(
                $ch,
                CURLINFO_EFFECTIVE_URL
            );
        $error = curl_error($ch);

        $contentLength = -1.0;

        if (
            defined('CURLINFO_CONTENT_LENGTH_DOWNLOAD_T')
        ) {
            $contentLength =
                (float)curl_getinfo(
                    $ch,
                    CURLINFO_CONTENT_LENGTH_DOWNLOAD_T
                );
        } elseif (
            defined('CURLINFO_CONTENT_LENGTH_DOWNLOAD')
        ) {
            $contentLength =
                (float)curl_getinfo(
                    $ch,
                    CURLINFO_CONTENT_LENGTH_DOWNLOAD
                );
        }

        curl_close($ch);
        fflush($archivo);
        fclose($archivo);

        $tamanoReal =
            is_file($destino)
                ? (int)(filesize($destino) ?: 0)
                : 0;

        if (
            $exceso ||
            $ok === false ||
            $error !== '' ||
            $http < 200 ||
            $http >= 300
        ) {
            return $this->error(
                $exceso
                    ? 'La descarga oficial supera 128 MB.'
                    : (
                        'No fue posible descargar la fuente oficial localizada' .
                        (
                            $http > 0
                                ? ' (HTTP ' . $http . ')'
                                : ''
                        ) .
                        (
                            $error !== ''
                                ? ': ' . $error
                                : '.'
                        )
                    )
            );
        }

        if ($urlFinal !== '' && !$this->esUrlInegi($urlFinal)) {
            return $this->error('La fuente oficial redirigió a un dominio no autorizado.');
        }

        /*
         * Si el servidor publicó Content-Length, una descarga más corta es un
         * archivo truncado aunque cURL haya terminado sin error.
         */
        if (
            $contentLength > 0 &&
            $tamanoReal > 0 &&
            $tamanoReal + 16 <
                (int)$contentLength
        ) {
            return $this->error(
                'La descarga oficial llegó incompleta: ' .
                $tamanoReal .
                ' de ' .
                (int)$contentLength .
                ' bytes.'
            );
        }

        if ($tamanoReal < 4) {
            return $this->error(
                'INEGI devolvió un archivo vacío o incompleto.'
            );
        }

        $firma =
            @file_get_contents(
                $destino,
                false,
                null,
                0,
                4
            );
        $ruta =
            strtolower(
                (string)parse_url(
                    $urlFinal !== ''
                        ? $urlFinal
                        : $url,
                    PHP_URL_PATH
                )
            );

        if ($firma !== "PK\x03\x04") {
            return $this->error(
                'INEGI respondió con un contenido que no es ZIP/XLSX' .
                ($tipo !== '' ? ' (' . $tipo . ')' : '') .
                '.'
            );
        }

        /*
         * La firma PK no basta: un ZIP truncado también conserva esos cuatro
         * bytes. Abrimos el contenedor antes de entregarlo al importador.
         */
        if (!class_exists('ZipArchive')) {
            return $this->error(
                'El servidor no tiene ZipArchive habilitado.'
            );
        }

        $zip = new ZipArchive();
        $codigoZip = $zip->open(
            $destino,
            ZipArchive::CHECKCONS
        );

        if ($codigoZip !== true) {
            return $this->error(
                'El archivo recibido está incompleto o no es un ZIP válido ' .
                '(ZipArchive ' .
                (string)$codigoZip .
                ', ' .
                $tamanoReal .
                ' bytes' .
                (
                    $contentLength > 0
                        ? ' de ' .
                            (int)$contentLength
                        : ''
                ) .
                ').'
            );
        }

        /*
         * No usar FL_NODIR aquí: workbook.xml vive dentro de /xl y esa
         * bandera hace que ZipArchive compare sólo el basename. Eso provocaba
         * falsos negativos en libros XLSX perfectamente válidos.
         */
        $tieneContentTypes =
            $zip->locateName(
                '[Content_Types].xml'
            ) !== false;
        $tieneWorkbook =
            $zip->locateName(
                'xl/workbook.xml'
            ) !== false;
        $tieneRels =
            $zip->locateName(
                '_rels/.rels'
            ) !== false;

        $esXlsx =
            $tieneContentTypes &&
            $tieneWorkbook &&
            $tieneRels;

        $zip->close();

        $esRutaXlsx =
            substr($ruta, -5) === '.xlsx';
        $esRutaZip =
            substr($ruta, -4) === '.zip';

        if ($esXlsx) {
            return [
                'ok' => true,
                'extension' => 'xlsx',
                'bytes' => $tamanoReal,
                'url_final' => $urlFinal,
                'mensaje' => ''
            ];
        }

        /*
         * Si el contenedor es un ZIP válido pero no es el libro directamente,
         * lo tratamos como paquete oficial. procesarZip() buscará dentro el
         * XLSX compatible. No dependemos del Content-Type del servidor.
         */
        return [
            'ok' => true,
            'extension' => 'zip',
            'bytes' => $tamanoReal,
            'url_final' => $urlFinal,
            'mensaje' => ''
        ];
    }

    private function urlAbsoluta(string $url, string $base): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }

        $partes = parse_url($base);
        $host = (string)($partes['host'] ?? '');

        if ($host === '') {
            return '';
        }

        if (strpos($url, '/') === 0) {
            return 'https://' . $host . $url;
        }

        $rutaBase = (string)($partes['path'] ?? '/');
        $directorio = rtrim(str_replace('\\', '/', dirname($rutaBase)), '/');

        return 'https://' . $host . $directorio . '/' . ltrim($url, '/');
    }

    private function esUrlInegi(string $url): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));

        return $host === 'inegi.org.mx' ||
            $host === 'www.inegi.org.mx' ||
            substr($host, -13) === '.inegi.org.mx';
    }

    private function rutaEstadoIntento(string $claveEstado): string
    {
        $directorio = dirname(__DIR__, 2) . '/storage/cache';

        if (!is_dir($directorio)) {
            @mkdir($directorio, 0775, true);
        }

        return $directorio .
            '/inegi_perfil_educativo_prioritario_' .
            self::CACHE_VERSION . '_' .
            preg_replace('/\D+/', '', $claveEstado) .
            '.json';
    }

    private function leerEstadoIntento(string $claveEstado): ?array
    {
        $ruta = $this->rutaEstadoIntento($claveEstado);

        if (!is_file($ruta)) {
            return null;
        }

        $datos = json_decode((string)@file_get_contents($ruta), true);
        return is_array($datos) ? $datos : null;
    }

    private function guardarEstadoIntento(string $claveEstado, array $datos): void
    {
        $datos['timestamp'] = time();

        @file_put_contents(
            $this->rutaEstadoIntento($claveEstado),
            json_encode(
                $datos,
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            ),
            LOCK_EX
        );
    }

    private function error(string $mensaje): array
    {
        return ['ok' => false, 'mensaje' => $mensaje];
    }
}
