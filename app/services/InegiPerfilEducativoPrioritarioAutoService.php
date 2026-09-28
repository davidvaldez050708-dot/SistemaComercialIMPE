<?php

require_once __DIR__ . '/../models/PerfilEducativoPrioritarioModel.php';
require_once __DIR__ . '/InegiPerfilEducativoPrioritarioImportService.php';

/**
 * Busca automáticamente el tabulado educativo prioritario en fuentes oficiales
 * de INEGI. Si no localiza una descarga compatible, no estima cifras: conserva
 * el estado "pendiente" y vuelve a intentar después del TTL.
 */
class InegiPerfilEducativoPrioritarioAutoService
{
    private const TTL_FALLO = 21600; // 6 horas
    private const MAX_BYTES = 33554432; // 32 MB

    private const PAGINAS_DESCUBRIMIENTO = [
        'https://www.inegi.org.mx/programas/ccpv/2020/#Tabulados',
        'https://www.inegi.org.mx/sistemas/Olap/Proyectos/bd/censos/cpv2020/P3Mas.asp'
    ];

    public function obtenerOActualizar(int $estadoId, string $claveEstado): array
    {
        $modelo = new PerfilEducativoPrioritarioModel();
        $actual = $modelo->obtenerPorEstado($estadoId, $claveEstado);

        if (($actual['disponible'] ?? false) === true) {
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

        $estadoCache = $this->leerEstadoIntento($claveEstado);

        if (
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

        $resultado = $this->actualizarDesdeInegi();

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

    private function actualizarDesdeInegi(): array
    {
        if (!function_exists('curl_init')) {
            return $this->error('El servidor no tiene cURL habilitado.');
        }

        $urls = [];

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
                'No se localizó automáticamente un XLSX/ZIP oficial identificado como B2020_07_08_M.'
            );
        }

        $primerError = '';

        foreach ($urls as $url) {
            $resultado = $this->procesarDescarga($url);

            if (($resultado['ok'] ?? false) === true) {
                return $resultado;
            }

            if ($primerError === '') {
                $primerError = trim((string)($resultado['mensaje'] ?? ''));
            }
        }

        return $this->error(
            $primerError !== ''
                ? $primerError
                : 'Las descargas oficiales localizadas no tuvieron una estructura compatible.'
        );
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
                strpos($texto, 'b2020_07_08_m') === false
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

    private function procesarDescarga(string $url): array
    {
        $temporal = tempnam(sys_get_temp_dir(), 'inegi_edu_prior_');

        if ($temporal === false) {
            return $this->error('No fue posible preparar la descarga temporal.');
        }

        $descarga = $this->descargarArchivo($url, $temporal);

        if (($descarga['ok'] ?? false) !== true) {
            @unlink($temporal);
            return $descarga;
        }

        $extension = strtolower((string)($descarga['extension'] ?? ''));

        try {
            if ($extension === 'xlsx') {
                return (new InegiPerfilEducativoPrioritarioImportService())
                    ->importarXlsx($temporal, basename((string)parse_url($url, PHP_URL_PATH)));
            }

            if ($extension === 'zip') {
                return $this->procesarZip($temporal);
            }

            return $this->error('INEGI devolvió un formato no compatible.');
        } finally {
            @unlink($temporal);
        }
    }

    private function procesarZip(string $ruta): array
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
                    strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'xlsx' ||
                    (
                        strpos($normalizado, '07_08') === false &&
                        strpos($normalizado, 'b2020_07_08_m') === false
                    )
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

                    $resultado =
                        (new InegiPerfilEducativoPrioritarioImportService())
                            ->importarXlsx($tmp, basename($nombre));

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

    private function descargarArchivo(string $url, string $destino): array
    {
        if (!$this->esUrlInegi($url)) {
            return $this->error('La URL localizada no pertenece a INEGI.');
        }

        $archivo = fopen($destino, 'wb');
        if ($archivo === false) {
            return $this->error('No fue posible crear el archivo temporal.');
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
            CURLOPT_WRITEFUNCTION => static function ($curl, $bloque) use (
                $archivo,
                &$bytes,
                &$exceso
            ) {
                $longitud = strlen((string)$bloque);
                $bytes += $longitud;

                if ($bytes > self::MAX_BYTES) {
                    $exceso = true;
                    return 0;
                }

                $escritos = fwrite($archivo, (string)$bloque);
                return $escritos === false ? 0 : $escritos;
            }
        ]);

        $ok = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tipo = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        $error = curl_error($ch);
        unset($ch);
        fflush($archivo);
        fclose($archivo);

        if ($exceso || $ok === false || $error !== '' || $http < 200 || $http >= 300) {
            return $this->error(
                $exceso
                    ? 'La descarga oficial supera el tamaño máximo permitido.'
                    : 'No fue posible descargar la fuente oficial localizada.'
            );
        }

        $firma = @file_get_contents($destino, false, null, 0, 4);
        $ruta = strtolower((string)parse_url($url, PHP_URL_PATH));

        $extension =
            substr($ruta, -5) === '.xlsx'
                ? 'xlsx'
                : (
                    substr($ruta, -4) === '.zip' ||
                    strpos($tipo, 'zip') !== false ||
                    $firma === "PK\x03\x04"
                        ? 'zip'
                        : ''
                );

        return [
            'ok' => $extension !== '',
            'extension' => $extension,
            'mensaje' => $extension === ''
                ? 'La descarga oficial no tiene formato XLSX/ZIP reconocible.'
                : ''
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
