<?php

require_once __DIR__ . '/../../config/api_keys.php';

class DenueService
{
    private const ACTIVIDAD_TODAS = '0';
    private const ESTRATO_TODOS = '0';

    public function obtenerSectoresEstado(string $claveInegi): array
    {
        $claveInegi = trim($claveInegi);

        if ($claveInegi === '' || !ctype_digit($claveInegi) || strlen($claveInegi) !== 2) {
            return [
                'ok' => false,
                'mensaje' => 'El área geográfica solicitada no es válida.'
            ];
        }

        if (
            !defined('DENUE_TOKEN') ||
            !defined('DENUE_BASE_URL') ||
            trim((string)DENUE_TOKEN) === '' ||
            trim((string)DENUE_BASE_URL) === ''
        ) {
            return [
                'ok' => false,
                'mensaje' => 'La configuración de DENUE no está disponible.'
            ];
        }

        $respuesta = $this->consultarCuantificar($claveInegi);

        if (!$respuesta['ok']) {
            return $respuesta;
        }

        return $this->procesarSectores($respuesta['datos'], $claveInegi);
    }

    public function buscarEstablecimientos(
        string $claveEstado,
        string $termino,
        string $claveMunicipio = '0',
        int $pagina = 1,
        int $limite = 10
    ): array {
        $claveEstado = trim($claveEstado);
        $claveMunicipio = $this->normalizarClaveMunicipal($claveMunicipio);
        $termino = trim($termino);
        $pagina = max(1, $pagina);
        $limite = min(20, max(1, $limite));

        if ($termino === '' || strlen($termino) < 3) {
            return [
                'ok' => true,
                'resultados' => [],
                'hay_mas' => false
            ];
        }

        if (
            $claveEstado === '' ||
            !ctype_digit($claveEstado) ||
            strlen($claveEstado) !== 2 ||
            ($claveMunicipio !== '0' && strlen($claveMunicipio) !== 3)
        ) {
            return [
                'ok' => false,
                'mensaje' => 'El área geográfica solicitada no es válida.'
            ];
        }

        if (!$this->configuracionDisponible()) {
            return [
                'ok' => false,
                'mensaje' => 'La configuración de DENUE no está disponible.'
            ];
        }

        $inicio = (($pagina - 1) * $limite) + 1;
        $fin = $inicio + $limite;
        $respuesta = $this->consultarBuscarAreaAct(
            $claveEstado,
            $claveMunicipio,
            $termino,
            $inicio,
            $fin
        );

        if (!$respuesta['ok']) {
            return $respuesta;
        }

        $resultados = [];

        foreach ($respuesta['datos'] as $registro) {
            if (!is_array($registro)) {
                continue;
            }

            $candidato = $this->normalizarEstablecimiento($registro);

            if ($candidato !== null) {
                $resultados[] = $candidato;
            }
        }

        return [
            'ok' => true,
            'resultados' => array_slice($resultados, 0, $limite),
            'hay_mas' => count($resultados) > $limite
        ];
    }

    public function buscarDenuePorNombre(
        string $claveEstado,
        string $claveMunicipio,
        string $termino,
        int $limite = 120
    ): array {
        $claveEstado = trim($claveEstado);
        $claveMunicipio = $this->normalizarClaveMunicipal($claveMunicipio);
        $termino = trim($termino);
        $limite = min(250, max(10, $limite));

        if ($termino === '' || strlen($termino) < 3) {
            return [
                'ok' => true,
                'resultados' => [],
                'hay_mas' => false
            ];
        }

        if (
            $claveEstado === '' ||
            !ctype_digit($claveEstado) ||
            strlen($claveEstado) !== 2 ||
            ($claveMunicipio !== '0' && strlen($claveMunicipio) !== 3)
        ) {
            return [
                'ok' => false,
                'mensaje' => 'El área geográfica solicitada no es válida.'
            ];
        }

        if (!$this->configuracionDisponible()) {
            return [
                'ok' => false,
                'mensaje' => 'La configuración de DENUE no está disponible.'
            ];
        }

        $cache = $this->leerCacheNombre($claveEstado, $claveMunicipio, $termino);

        if ($cache !== null) {
            return [
                'ok' => true,
                'resultados' => array_slice($cache, 0, $limite),
                'hay_mas' => count($cache) > $limite,
                'cache' => true
            ];
        }

        $solicitudes = [[
            'url' => $this->construirUrlBuscarAreaAct(
                $claveEstado,
                $claveMunicipio,
                $termino,
                1,
                120
            ),
            'perfil' => ['peso' => 100]
        ]];

        foreach ($this->obtenerSectoresComplementariosBusqueda($termino) as $sector) {
            $solicitudes[] = [
                'url' => $this->construirUrlBuscarAreaActEstr(
                    $claveEstado,
                    $claveMunicipio,
                    $termino,
                    1,
                    220,
                    $sector,
                    0
                ),
                'perfil' => ['peso' => 95]
            ];
        }

        $respuestas = $this->consultarUrlsConcurrentes($solicitudes, 4);
        $resultadosPorClave = [];
        $consultasCorrectas = 0;

        foreach ($respuestas as $respuesta) {
            if (!$respuesta['ok']) {
                continue;
            }

            $consultasCorrectas++;

            foreach ($respuesta['datos'] as $registro) {
                if (!is_array($registro)) {
                    continue;
                }

                $candidato = $this->normalizarEstablecimiento($registro);

                if ($candidato === null) {
                    continue;
                }

                $clave = $candidato['clave_origen'];
                $puntaje = (int)($respuesta['perfil']['peso'] ?? 0) +
                    ((int)$candidato['estrato_valor'] * 4);

                if (
                    !isset($resultadosPorClave[$clave]) ||
                    $puntaje > (int)($resultadosPorClave[$clave]['puntaje_denue'] ?? 0)
                ) {
                    $candidato['puntaje_denue'] = $puntaje;
                    $resultadosPorClave[$clave] = $candidato;
                }
            }
        }

        if ($consultasCorrectas === 0 && empty($resultadosPorClave)) {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible conectar con DENUE.'
            ];
        }

        $resultados = array_values($resultadosPorClave);

        usort($resultados, function (array $a, array $b): int {
            $comparacionTamano =
                (int)($b['estrato_valor'] ?? 0) <=>
                (int)($a['estrato_valor'] ?? 0);

            if ($comparacionTamano !== 0) {
                return $comparacionTamano;
            }

            return strcasecmp((string)$a['nombre'], (string)$b['nombre']);
        });

        $this->guardarCacheNombre($claveEstado, $claveMunicipio, $termino, $resultados);

        return [
            'ok' => true,
            'resultados' => array_slice($resultados, 0, $limite),
            'hay_mas' => count($resultados) > $limite,
            'cache' => false
        ];
    }

    public function buscarCandidatosRecomendados(
        string $claveEstado,
        string $claveMunicipio = '0',
        int $limite = 80
    ): array {
        $claveEstado = trim($claveEstado);
        $claveMunicipio = $this->normalizarClaveMunicipal($claveMunicipio);
        $limite = min(300, max(10, $limite));

        if (
            $claveEstado === '' ||
            !ctype_digit($claveEstado) ||
            strlen($claveEstado) !== 2 ||
            ($claveMunicipio !== '0' && strlen($claveMunicipio) !== 3)
        ) {
            return [
                'ok' => false,
                'mensaje' => 'El área geográfica solicitada no es válida.'
            ];
        }

        if (!$this->configuracionDisponible()) {
            return [
                'ok' => false,
                'mensaje' => 'La configuración de DENUE no está disponible.'
            ];
        }

        $perfiles = [
            ['termino' => 'administración pública', 'sector' => '93', 'peso' => 100],
            ['termino' => 'educación', 'sector' => '61', 'peso' => 96],
            ['termino' => 'salud', 'sector' => '62', 'peso' => 94],
            ['termino' => 'asociación', 'sector' => '81', 'peso' => 88],
            ['termino' => 'manufactura', 'sector' => '31', 'peso' => 86],
            ['termino' => 'servicios profesionales', 'sector' => '54', 'peso' => 84],
            ['termino' => 'corporativo', 'sector' => '55', 'peso' => 82],
            ['termino' => 'financiera', 'sector' => '52', 'peso' => 80],
            ['termino' => 'transporte', 'sector' => '48', 'peso' => 78]
        ];
        $sectoresCache = array_map(function (array $perfil): string {
            return $perfil['sector'];
        }, $perfiles);
        $cache = $this->leerCacheRecomendados($claveEstado, $claveMunicipio, $sectoresCache);

        if ($cache !== null) {
            return [
                'ok' => true,
                'resultados' => array_slice($cache, 0, $limite),
                'hay_mas' => count($cache) > $limite,
                'cache' => true
            ];
        }

        $solicitudes = [];

        foreach ($perfiles as $perfil) {
            $this->registrarDiagnosticoDenue(
                $claveEstado,
                $claveMunicipio,
                $perfil['sector'],
                0,
                $perfil['termino']
            );
            $solicitudes[] = [
                'url' => $this->construirUrlBuscarAreaActEstr(
                    $claveEstado,
                    $claveMunicipio,
                    $perfil['termino'],
                    1,
                    100,
                    $perfil['sector'],
                    0
                ),
                'perfil' => $perfil
            ];
        }

        foreach ($perfiles as $perfil) {
            $this->registrarDiagnosticoDenue(
                $claveEstado,
                $claveMunicipio,
                $perfil['sector'],
                7,
                $perfil['termino']
            );
            $solicitudes[] = [
                'url' => $this->construirUrlBuscarAreaActEstr(
                    $claveEstado,
                    $claveMunicipio,
                    $perfil['termino'],
                    1,
                    35,
                    $perfil['sector'],
                    7
                ),
                'perfil' => $perfil
            ];
        }

        $respuestas = $this->consultarUrlsConcurrentes($solicitudes, 4);
        $resultadosPorClave = [];
        $consultasCorrectas = 0;

        foreach ($respuestas as $respuesta) {
            if (!$respuesta['ok']) {
                continue;
            }

            $consultasCorrectas++;
            $perfil = $respuesta['perfil'];

            foreach ($respuesta['datos'] as $registro) {
                if (!is_array($registro)) {
                    continue;
                }

                $candidato = $this->normalizarEstablecimiento($registro);

                if ($candidato === null || (int)$candidato['estrato_valor'] < 3) {
                    continue;
                }

                $clave = $candidato['clave_origen'];
                $puntajePerfil = (int)$perfil['peso'] + ((int)$candidato['estrato_valor'] * 8);

                if (
                    !isset($resultadosPorClave[$clave]) ||
                    $puntajePerfil > (int)($resultadosPorClave[$clave]['puntaje_denue'] ?? 0)
                ) {
                    $candidato['puntaje_denue'] = $puntajePerfil;
                    $resultadosPorClave[$clave] = $candidato;
                }
            }
        }

        if ($consultasCorrectas === 0 && empty($resultadosPorClave)) {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible conectar con DENUE.'
            ];
        }

        $resultados = array_values($resultadosPorClave);

        usort($resultados, function (array $a, array $b): int {
            $comparacionPerfil =
                (int)($b['puntaje_denue'] ?? 0) <=>
                (int)($a['puntaje_denue'] ?? 0);

            if ($comparacionPerfil !== 0) {
                return $comparacionPerfil;
            }

            return strcasecmp((string)$a['nombre'], (string)$b['nombre']);
        });

        $this->guardarCacheRecomendados($claveEstado, $claveMunicipio, $sectoresCache, $resultados);

        return [
            'ok' => true,
            'resultados' => array_slice($resultados, 0, $limite),
            'hay_mas' => count($resultados) > $limite,
            'cache' => false
        ];
    }

    public function obtenerEstablecimientoPorId(string $idEstablecimiento): array
    {
        $idEstablecimiento = trim($idEstablecimiento);

        if ($idEstablecimiento === '' || !ctype_digit($idEstablecimiento)) {
            return [
                'ok' => false,
                'mensaje' => 'El establecimiento DENUE no es válido.'
            ];
        }

        if (!$this->configuracionDisponible()) {
            return [
                'ok' => false,
                'mensaje' => 'La configuración de DENUE no está disponible.'
            ];
        }

        $url = rtrim(DENUE_BASE_URL, '/') .
            '/Ficha/' .
            rawurlencode($idEstablecimiento) .
            '/' .
            rawurlencode(DENUE_TOKEN);

        $respuesta = $this->consultarUrl($url);

        if (!$respuesta['ok']) {
            return $respuesta;
        }

        $registro = $respuesta['datos'][0] ?? $respuesta['datos'];

        if (!is_array($registro)) {
            return [
                'ok' => false,
                'mensaje' => 'DENUE no devolvió información del establecimiento.'
            ];
        }

        $candidato = $this->normalizarEstablecimiento($registro);

        if ($candidato === null) {
            return [
                'ok' => false,
                'mensaje' => 'La información del establecimiento DENUE está incompleta.'
            ];
        }

        return [
            'ok' => true,
            'candidato' => $candidato
        ];
    }

    private function consultarCuantificar(string $claveInegi): array
    {
        $url = rtrim(DENUE_BASE_URL, '/') .
            '/Cuantificar/' .
            self::ACTIVIDAD_TODAS .
            '/' .
            rawurlencode($claveInegi) .
            '/' .
            self::ESTRATO_TODOS .
            '/' .
            rawurlencode(DENUE_TOKEN);

        return $this->consultarUrl($url);
    }

    private function consultarBuscarAreaAct(
        string $claveEstado,
        string $claveMunicipio,
        string $termino,
        int $inicio,
        int $fin,
        string $sector = '0'
    ): array {
        return $this->consultarUrl(
            $this->construirUrlBuscarAreaAct(
                $claveEstado,
                $claveMunicipio,
                $termino,
                $inicio,
                $fin,
                $sector
            )
        );
    }

    private function construirUrlBuscarAreaAct(
        string $claveEstado,
        string $claveMunicipio,
        string $termino,
        int $inicio,
        int $fin,
        string $sector = '0'
    ): string {
        $sector = trim($sector) === '' ? '0' : trim($sector);

        if ($sector !== '0' && !ctype_digit($sector)) {
            $sector = '0';
        }

        return rtrim(DENUE_BASE_URL, '/') .
            '/BuscarAreaAct/' .
            rawurlencode($claveEstado) .
            '/' .
            rawurlencode($claveMunicipio) .
            '/0/0/0/' .
            rawurlencode($sector) .
            '/0/0/0/' .
            rawurlencode($termino) .
            '/' .
            $inicio .
            '/' .
            $fin .
            '/0/' .
            rawurlencode(DENUE_TOKEN);
    }

    private function consultarBuscarAreaActEstr(
        string $claveEstado,
        string $claveMunicipio,
        string $termino,
        int $inicio,
        int $fin,
        string $sector = '0',
        int $estrato = 0
    ): array {
        $this->registrarDiagnosticoDenue(
            $claveEstado,
            $claveMunicipio,
            $sector,
            $estrato,
            $termino
        );

        return $this->consultarUrl(
            $this->construirUrlBuscarAreaActEstr(
                $claveEstado,
                $claveMunicipio,
                $termino,
                $inicio,
                $fin,
                $sector,
                $estrato
            )
        );
    }

    private function construirUrlBuscarAreaActEstr(
        string $claveEstado,
        string $claveMunicipio,
        string $termino,
        int $inicio,
        int $fin,
        string $sector = '0',
        int $estrato = 0
    ): string {
        $sector = trim($sector) === '' ? '0' : trim($sector);
        $estrato = $estrato >= 1 && $estrato <= 7 ? $estrato : 0;

        if ($sector !== '0' && !ctype_digit($sector)) {
            $sector = '0';
        }

        return rtrim(DENUE_BASE_URL, '/') .
            '/BuscarAreaActEstr/' .
            rawurlencode($claveEstado) .
            '/' .
            rawurlencode($claveMunicipio) .
            '/0/0/0/' .
            rawurlencode($sector) .
            '/0/0/0/' .
            rawurlencode($termino) .
            '/' .
            $inicio .
            '/' .
            $fin .
            '/0/' .
            $estrato .
            '/' .
            rawurlencode(DENUE_TOKEN);
    }

    private function consultarUrlsConcurrentes(array $solicitudes, int $maximoConcurrente = 4): array
    {
        if (empty($solicitudes)) {
            return [];
        }

        if (!function_exists('curl_multi_init')) {
            $respuestas = [];

            foreach ($solicitudes as $solicitud) {
                $respuesta = $this->consultarUrl($solicitud['url']);
                $respuesta['perfil'] = $solicitud['perfil'] ?? [];
                $respuestas[] = $respuesta;
            }

            return $respuestas;
        }

        $multi = curl_multi_init();

        if ($multi === false) {
            return [];
        }

        $pendientes = array_values($solicitudes);
        $activos = [];
        $respuestas = [];
        $maximoConcurrente = max(1, min(4, $maximoConcurrente));
        $agregarSolicitud = function () use (&$pendientes, &$activos, $multi) {
            if (empty($pendientes)) {
                return;
            }

            $solicitud = array_shift($pendientes);
            $handle = curl_init();

            if ($handle === false) {
                return;
            }

            curl_setopt_array($handle, [
                CURLOPT_URL => $solicitud['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT => 16,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json'
                ]
            ]);

            curl_multi_add_handle($multi, $handle);
            $activos[spl_object_id($handle)] = [
                'handle' => $handle,
                'solicitud' => $solicitud
            ];
        };

        while (count($activos) < $maximoConcurrente && !empty($pendientes)) {
            $agregarSolicitud();
        }

        do {
            do {
                $estado = curl_multi_exec($multi, $ejecutando);
            } while ($estado === CURLM_CALL_MULTI_PERFORM);

            while ($info = curl_multi_info_read($multi)) {
                $handle = $info['handle'];
                $id = spl_object_id($handle);
                $activo = $activos[$id] ?? null;
                $contenido = curl_multi_getcontent($handle);
                $codigoHttp = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
                $errorCurl = curl_error($handle);
                $respuesta = $this->procesarRespuestaDenue($contenido, $codigoHttp, $errorCurl);
                $respuesta['perfil'] = $activo['solicitud']['perfil'] ?? [];
                $respuestas[] = $respuesta;

                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                unset($activos[$id]);

                while (count($activos) < $maximoConcurrente && !empty($pendientes)) {
                    $agregarSolicitud();
                }
            }

            if ($ejecutando > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($ejecutando > 0 || !empty($activos));

        curl_multi_close($multi);

        return $respuestas;
    }

    private function normalizarClaveMunicipal(string $claveMunicipio): string
    {
        $claveMunicipio = preg_replace('/\D+/', '', $claveMunicipio);

        if ($claveMunicipio === '' || $claveMunicipio === '0') {
            return '0';
        }

        return str_pad(substr($claveMunicipio, -3), 3, '0', STR_PAD_LEFT);
    }

    private function registrarDiagnosticoDenue(
        string $claveEstado,
        string $claveMunicipio,
        string $sector,
        int $estrato,
        string $termino
    ): void {
        error_log(
            '[DENUE] entidad=' .
            $claveEstado .
            ' municipio=' .
            $claveMunicipio .
            ' sector=' .
            (trim($sector) === '' ? '0' : trim($sector)) .
            ' estrato=' .
            $estrato .
            ' termino=' .
            $termino
        );
    }

    private function obtenerSectoresComplementariosBusqueda(string $termino): array
    {
        $termino = $this->normalizarTextoBusqueda($termino);
        $sectores = [];

        if (
            strpos($termino, 'institut') !== false ||
            strpos($termino, 'coleg') !== false ||
            strpos($termino, 'escuel') !== false ||
            strpos($termino, 'univers') !== false ||
            strpos($termino, 'educ') !== false
        ) {
            $sectores[] = '61';
        }

        if (
            strpos($termino, 'secretaria') !== false ||
            strpos($termino, 'gobierno') !== false ||
            strpos($termino, 'municip') !== false ||
            strpos($termino, 'delegacion') !== false
        ) {
            $sectores[] = '93';
        }

        if (
            strpos($termino, 'salud') !== false ||
            strpos($termino, 'hospital') !== false ||
            strpos($termino, 'clinica') !== false
        ) {
            $sectores[] = '62';
        }

        if (
            strpos($termino, 'asociacion') !== false ||
            strpos($termino, 'camara') !== false ||
            strpos($termino, 'fundacion') !== false
        ) {
            $sectores[] = '81';
        }

        return array_values(array_unique($sectores));
    }

    private function normalizarTextoBusqueda(string $texto): string
    {
        $texto = strtolower(trim($texto));
        $normalizado = iconv('UTF-8', 'ASCII//TRANSLIT', $texto);

        return $normalizado === false ? $texto : $normalizado;
    }

    private function leerCacheRecomendados(
        string $claveEstado,
        string $claveMunicipio,
        array $sectores
    ): ?array {
        $archivo = $this->obtenerArchivoCacheRecomendados($claveEstado, $claveMunicipio, $sectores);

        if (!is_file($archivo) || (time() - filemtime($archivo)) > 1800) {
            return null;
        }

        $contenido = file_get_contents($archivo);

        if ($contenido === false) {
            return null;
        }

        $datos = json_decode($contenido, true);

        return is_array($datos) ? $datos : null;
    }

    private function guardarCacheRecomendados(
        string $claveEstado,
        string $claveMunicipio,
        array $sectores,
        array $resultados
    ): void {
        $directorio = dirname(
            $this->obtenerArchivoCacheRecomendados($claveEstado, $claveMunicipio, $sectores)
        );

        if (!is_dir($directorio)) {
            mkdir($directorio, 0775, true);
        }

        if (!is_dir($directorio) || !is_writable($directorio)) {
            return;
        }

        file_put_contents(
            $this->obtenerArchivoCacheRecomendados($claveEstado, $claveMunicipio, $sectores),
            json_encode($resultados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
        );
    }

    private function obtenerArchivoCacheRecomendados(
        string $claveEstado,
        string $claveMunicipio,
        array $sectores
    ): string {
        sort($sectores);
        $clave = md5('v5|' . $claveEstado . '|' . $claveMunicipio . '|' . implode(',', $sectores));

        return __DIR__ . '/../../storage/cache/denue/recomendados_' . $clave . '.json';
    }

    private function leerCacheNombre(
        string $claveEstado,
        string $claveMunicipio,
        string $termino
    ): ?array {
        $archivo = $this->obtenerArchivoCacheNombre($claveEstado, $claveMunicipio, $termino);

        if (!is_file($archivo) || (time() - filemtime($archivo)) > 900) {
            return null;
        }

        $contenido = file_get_contents($archivo);

        if ($contenido === false) {
            return null;
        }

        $datos = json_decode($contenido, true);

        return is_array($datos) ? $datos : null;
    }

    private function guardarCacheNombre(
        string $claveEstado,
        string $claveMunicipio,
        string $termino,
        array $resultados
    ): void {
        $archivo = $this->obtenerArchivoCacheNombre($claveEstado, $claveMunicipio, $termino);
        $directorio = dirname($archivo);

        if (!is_dir($directorio)) {
            mkdir($directorio, 0775, true);
        }

        if (!is_dir($directorio) || !is_writable($directorio)) {
            return;
        }

        file_put_contents(
            $archivo,
            json_encode($resultados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
        );
    }

    private function obtenerArchivoCacheNombre(
        string $claveEstado,
        string $claveMunicipio,
        string $termino
    ): string {
        $clave = md5(
            'nombre-v1|' .
            $claveEstado .
            '|' .
            $claveMunicipio .
            '|' .
            $this->normalizarTextoBusqueda($termino)
        );

        return __DIR__ . '/../../storage/cache/denue/busqueda_' . $clave . '.json';
    }

    private function consultarUrl(string $url): array
    {
        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible conectar con DENUE.'
            ];
        }

        $ch = curl_init();

        if ($ch === false) {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible conectar con DENUE.'
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json'
            ]
        ]);

        $contenido = curl_exec($ch);
        $codigoHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($ch);

        curl_close($ch);

        return $this->procesarRespuestaDenue($contenido, $codigoHttp, $errorCurl);
    }

    private function procesarRespuestaDenue($contenido, int $codigoHttp, string $errorCurl): array
    {
        if ($contenido === false || $errorCurl !== '') {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible conectar con DENUE.'
            ];
        }

        if ($codigoHttp !== 200) {
            return [
                'ok' => false,
                'mensaje' => 'DENUE respondió con un código HTTP no válido.'
            ];
        }

        $datos = json_decode($contenido, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($datos)) {
            return [
                'ok' => false,
                'mensaje' => 'La respuesta de DENUE no tiene un formato válido.'
            ];
        }

        return [
            'ok' => true,
            'datos' => $datos
        ];
    }

    private function configuracionDisponible(): bool
    {
        return defined('DENUE_TOKEN') &&
            defined('DENUE_BASE_URL') &&
            trim((string)DENUE_TOKEN) !== '' &&
            trim((string)DENUE_BASE_URL) !== '';
    }

    private function normalizarEstablecimiento(array $registro): ?array
    {
        $id = $this->obtenerValor($registro, ['Id', 'id', 'ID']);
        $nombre = $this->obtenerValor($registro, ['Nombre', 'nombre']);

        if ($id === '' || $nombre === '') {
            return null;
        }

        $actividad = $this->obtenerValor(
            $registro,
            ['Clase_actividad', 'Clase Actividad', 'Actividad', 'actividad']
        );
        $telefono = $this->obtenerValor($registro, ['Telefono', 'Teléfono', 'telefono']);
        $correo = $this->obtenerValor($registro, ['Correo_e', 'Correo', 'correo']);
        $sitioWeb = $this->obtenerValor(
            $registro,
            ['Sitio_internet', 'Sitio Web', 'sitio_web', 'www']
        );
        $ubicacion = $this->obtenerValor($registro, ['Ubicacion', 'Ubicación', 'ubicacion']);
        $claveArea = $this->obtenerValor(
            $registro,
            ['AreaGeo', 'Area_geo', 'Área geoestadística', 'Clave_geoestadistica', 'AG', 'Cve_geo']
        );
        $claveEstado = $this->obtenerValor($registro, ['Cve_Ent', 'Cve_Entidad', 'EntidadFederativaId']);
        $claveMunicipio = $this->obtenerValor($registro, ['Cve_Mun', 'Cve_Municipio', 'MunicipioId']);
        $estrato = $this->obtenerValor(
            $registro,
            ['Estrato', 'Estrato_personal', 'Personal_ocupado', 'Per_ocu', 'personal_ocupado']
        );
        $estratoValor = $this->normalizarEstratoPersonal($estrato);
        $codigoActividad = $this->obtenerValor(
            $registro,
            ['Codigo_act', 'Código actividad', 'Codigo_actividad', 'codigo_actividad', 'AE']
        );

        if (
            $claveArea === '' &&
            ctype_digit($claveEstado) &&
            ctype_digit($claveMunicipio)
        ) {
            $claveArea = str_pad($claveEstado, 2, '0', STR_PAD_LEFT) .
                str_pad($claveMunicipio, 3, '0', STR_PAD_LEFT);
        }

        return [
            'origen' => 'DENUE',
            'clave_origen' => 'DENUE:' . $id,
            'id_origen' => $id,
            'tipo_entidad' => $this->clasificarTipoEntidadDenue($codigoActividad, $actividad),
            'tipo_entidad_etiqueta' => $this->etiquetarTipoEntidadDenue($codigoActividad, $actividad),
            'nombre' => $nombre,
            'actividad' => $actividad !== '' ? $actividad : null,
            'sector' => $this->obtenerSectorActividad($codigoActividad, $actividad),
            'estrato_valor' => $estratoValor,
            'estrato_etiqueta' => $this->obtenerEtiquetaEstratoPersonal($estratoValor),
            'direccion' => $this->construirDireccion($registro),
            'telefono' => $telefono !== '' ? $telefono : null,
            'correo' => $correo !== '' ? $correo : null,
            'sitio_web' => $sitioWeb !== '' ? $sitioWeb : null,
            'municipio_nombre' => $this->obtenerValor(
                $registro,
                ['Municipio', 'municipio', 'Nombre_municipio']
            ) ?: $this->obtenerMunicipioDesdeUbicacion($ubicacion),
            'municipio_id' => null,
            'clave_area' => $claveArea,
            'ubicacion' => $ubicacion,
            'entidad_nombre' => $this->obtenerValor(
                $registro,
                ['Entidad', 'Estado', 'entidad', 'Nombre_entidad']
            ),
            'fuente' => 'DENUE',
            'contexto' => $ubicacion
        ];
    }

    private function clasificarTipoEntidadDenue(string $codigoActividad, string $actividad): string
    {
        $sector = $this->obtenerSectorActividad($codigoActividad, $actividad);

        if (in_array($sector, ['61', '62', '93'], true)) {
            return 'INSTITUCION';
        }

        $actividad = strtolower($actividad);

        if (
            strpos($actividad, 'asociaci') !== false ||
            strpos($actividad, 'camara') !== false ||
            strpos($actividad, 'organizacion') !== false ||
            strpos($actividad, 'organización') !== false
        ) {
            return 'ORGANIZACION';
        }

        return 'EMPRESA';
    }

    private function etiquetarTipoEntidadDenue(string $codigoActividad, string $actividad): string
    {
        $sector = $this->obtenerSectorActividad($codigoActividad, $actividad);

        if ($sector === '93') {
            return 'Institución pública';
        }

        if ($sector === '61') {
            return 'Institución educativa';
        }

        if ($sector === '62') {
            return 'Institución de salud';
        }

        if ($this->clasificarTipoEntidadDenue($codigoActividad, $actividad) === 'ORGANIZACION') {
            return 'Organización';
        }

        return 'Empresa';
    }

    private function construirDireccion(array $registro): ?string
    {
        $partes = [];

        foreach ([
            'Tipo_vialidad',
            'Calle',
            'Num_Exterior',
            'Num_Interior',
            'Colonia',
            'CP'
        ] as $campo) {
            $valor = $this->obtenerValor($registro, [$campo]);

            if ($valor !== '') {
                $partes[] = $valor;
            }
        }

        return empty($partes) ? null : implode(' ', $partes);
    }

    private function obtenerMunicipioDesdeUbicacion(string $ubicacion): ?string
    {
        $partes = array_values(array_filter(array_map('trim', explode(',', $ubicacion))));

        if (count($partes) >= 2) {
            return $partes[count($partes) - 2];
        }

        return null;
    }

    private function normalizarEstratoPersonal(string $estrato): int
    {
        $estrato = trim($estrato);

        if ($estrato === '') {
            return 0;
        }

        if (ctype_digit($estrato)) {
            $valor = (int)$estrato;
            return $valor >= 1 && $valor <= 7 ? $valor : 0;
        }

        $normalizado = strtolower($estrato);

        if (
            strpos($normalizado, '251') !== false ||
            strpos($normalizado, 'mas') !== false ||
            strpos($normalizado, 'más') !== false
        ) {
            return 7;
        }

        if (strpos($normalizado, '101') !== false) {
            return 6;
        }

        if (strpos($normalizado, '51') !== false) {
            return 5;
        }

        if (strpos($normalizado, '31') !== false) {
            return 4;
        }

        if (strpos($normalizado, '11') !== false) {
            return 3;
        }

        if (strpos($normalizado, '6') !== false) {
            return 2;
        }

        if (strpos($normalizado, '0') !== false || strpos($normalizado, '5') !== false) {
            return 1;
        }

        return 0;
    }

    private function obtenerEtiquetaEstratoPersonal(int $estrato): ?string
    {
        $etiquetas = [
            1 => '0-5 personas',
            2 => '6-10 personas',
            3 => '11-30 personas',
            4 => '31-50 personas',
            5 => '51-100 personas',
            6 => '101-250 personas',
            7 => '251+ personas'
        ];

        return $etiquetas[$estrato] ?? null;
    }

    private function obtenerSectorActividad(string $codigoActividad, string $actividad): ?string
    {
        $codigoActividad = trim($codigoActividad);

        if (strlen($codigoActividad) >= 2 && ctype_digit(substr($codigoActividad, 0, 2))) {
            return substr($codigoActividad, 0, 2);
        }

        $actividad = strtolower($actividad);

        if (strpos($actividad, 'educ') !== false) {
            return '61';
        }

        if (strpos($actividad, 'salud') !== false || strpos($actividad, 'asistencia social') !== false) {
            return '62';
        }

        if (strpos($actividad, 'gobierno') !== false || strpos($actividad, 'administraci') !== false) {
            return '93';
        }

        if (strpos($actividad, 'manufact') !== false || strpos($actividad, 'fabricaci') !== false) {
            return '31';
        }

        if (strpos($actividad, 'transporte') !== false || strpos($actividad, 'almacenamiento') !== false) {
            return '48';
        }

        if (strpos($actividad, 'financier') !== false || strpos($actividad, 'seguros') !== false) {
            return '52';
        }

        if (strpos($actividad, 'profesional') !== false || strpos($actividad, 'cient') !== false) {
            return '54';
        }

        if (strpos($actividad, 'corporativ') !== false) {
            return '55';
        }

        return null;
    }

    private function obtenerValor(array $registro, array $llaves): string
    {
        foreach ($llaves as $llave) {
            if (isset($registro[$llave])) {
                return trim((string)$registro[$llave]);
            }
        }

        return '';
    }

    private function procesarSectores(array $datos, string $claveInegi): array
    {
        if (empty($datos)) {
            return [
                'ok' => false,
                'mensaje' => 'DENUE no devolvió información económica para el territorio.'
            ];
        }

        $valoresPorCodigo = [];

        foreach ($datos as $registro) {
            if (!is_array($registro)) {
                continue;
            }

            $claveActividad = trim((string)($registro['AE'] ?? ''));
            $claveArea = trim((string)($registro['AG'] ?? ''));

            if (strlen($claveActividad) !== 2 || $claveArea !== $claveInegi) {
                continue;
            }

            $valoresPorCodigo[$claveActividad] = $this->normalizarEntero(
                $registro['Total'] ?? 0
            );
        }

        if (empty($valoresPorCodigo)) {
            return [
                'ok' => false,
                'mensaje' => 'DENUE no devolvió información económica para el territorio.'
            ];
        }

        $establecimientosPorSector = $this->construirSectoresAgrupados($valoresPorCodigo);
        $totalEstablecimientos = array_sum($establecimientosPorSector);

        if ($totalEstablecimientos <= 0) {
            return [
                'ok' => false,
                'mensaje' => 'DENUE no devolvió información económica para el territorio.'
            ];
        }

        $sectores = [];
        $catalogo = $this->obtenerCatalogoSectores();

        foreach ($establecimientosPorSector as $clave => $establecimientos) {
            $sectores[] = [
                'clave_sector' => $clave,
                'nombre_sector' => $catalogo[$clave],
                'establecimientos' => $establecimientos,
                'porcentaje' => round(($establecimientos / $totalEstablecimientos) * 100, 2)
            ];
        }

        usort($sectores, function (array $a, array $b): int {
            $comparacionEstablecimientos = $b['establecimientos'] <=> $a['establecimientos'];

            if ($comparacionEstablecimientos !== 0) {
                return $comparacionEstablecimientos;
            }

            return strcmp($a['nombre_sector'], $b['nombre_sector']);
        });

        return [
            'ok' => true,
            'area_geografica' => $claveInegi,
            'total_establecimientos' => $totalEstablecimientos,
            'sectores' => $sectores
        ];
    }

    private function construirSectoresAgrupados(array $valoresPorCodigo): array
    {
        return [
            '11' => $valoresPorCodigo['11'] ?? 0,
            '21' => $valoresPorCodigo['21'] ?? 0,
            '22' => $valoresPorCodigo['22'] ?? 0,
            '23' => $valoresPorCodigo['23'] ?? 0,
            '31-33' => ($valoresPorCodigo['31'] ?? 0) +
                ($valoresPorCodigo['32'] ?? 0) +
                ($valoresPorCodigo['33'] ?? 0),
            '43' => $valoresPorCodigo['43'] ?? 0,
            '46' => $valoresPorCodigo['46'] ?? 0,
            '48-49' => ($valoresPorCodigo['48'] ?? 0) +
                ($valoresPorCodigo['49'] ?? 0),
            '51' => $valoresPorCodigo['51'] ?? 0,
            '52' => $valoresPorCodigo['52'] ?? 0,
            '53' => $valoresPorCodigo['53'] ?? 0,
            '54' => $valoresPorCodigo['54'] ?? 0,
            '55' => $valoresPorCodigo['55'] ?? 0,
            '56' => $valoresPorCodigo['56'] ?? 0,
            '61' => $valoresPorCodigo['61'] ?? 0,
            '62' => $valoresPorCodigo['62'] ?? 0,
            '71' => $valoresPorCodigo['71'] ?? 0,
            '72' => $valoresPorCodigo['72'] ?? 0,
            '81' => $valoresPorCodigo['81'] ?? 0,
            '93' => $valoresPorCodigo['93'] ?? 0
        ];
    }

    private function obtenerCatalogoSectores(): array
    {
        return [
            '11' => 'Agricultura, cría y explotación de animales, aprovechamiento forestal, pesca y caza',
            '21' => 'Minería',
            '22' => 'Generación, transmisión, distribución y comercialización de energía eléctrica, suministro de agua y gas natural',
            '23' => 'Construcción',
            '31-33' => 'Industrias manufactureras',
            '43' => 'Comercio al por mayor',
            '46' => 'Comercio al por menor',
            '48-49' => 'Transportes, correos y almacenamiento',
            '51' => 'Información en medios masivos',
            '52' => 'Servicios financieros y de seguros',
            '53' => 'Servicios inmobiliarios y de alquiler de bienes muebles e intangibles',
            '54' => 'Servicios profesionales, científicos y técnicos',
            '55' => 'Dirección y administración de grupos empresariales o corporativos',
            '56' => 'Servicios de apoyo a los negocios y manejo de residuos, y servicios de remediación',
            '61' => 'Servicios educativos',
            '62' => 'Servicios de salud y de asistencia social',
            '71' => 'Servicios de esparcimiento, culturales y deportivos, y otros servicios recreativos',
            '72' => 'Servicios de alojamiento temporal y de preparación de alimentos y bebidas',
            '81' => 'Otros servicios excepto actividades gubernamentales',
            '93' => 'Actividades legislativas, gubernamentales, de impartición de justicia y de organismos internacionales y extraterritoriales'
        ];
    }

    private function normalizarEntero($valor): int
    {
        if (is_int($valor)) {
            return max(0, $valor);
        }

        if (is_float($valor)) {
            return max(0, (int)$valor);
        }

        $valor = trim((string)$valor);

        if ($valor === '' || !is_numeric($valor)) {
            return 0;
        }

        return max(0, (int)$valor);
    }
}
