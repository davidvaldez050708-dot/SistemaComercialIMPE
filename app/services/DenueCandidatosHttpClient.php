<?php
require_once __DIR__ . '/DenueTlsPolicy.php';

/** Transporte acotado de las búsquedas de candidatos; nunca registra URLs ni tokens. */
class DenueCandidatosHttpClient
{
    private int $timeoutMs;
    private int $presupuestoMs;

    public function __construct(int $timeoutMs = 6500, int $presupuestoMs = 25000)
    {
        $this->timeoutMs = max(100, min(6500, $timeoutMs));
        $this->presupuestoMs = max(100, min(25000, $presupuestoMs));
    }

    public function consultar(array $solicitudes, int $concurrencia = 4): array
    {
        if (!$solicitudes) return [];
        $resultados = [];
        foreach ($solicitudes as $id => $solicitud) {
            $resultados[$id] = self::fallo('PLAZO', 'DENUE está tardando en responder. Intenta nuevamente.', true);
            $resultados[$id]['perfil'] = $solicitud['perfil'] ?? [];
        }
        if (!function_exists('curl_multi_init')) {
            foreach ($resultados as &$resultado) {
                $resultado = self::fallo('CONFIGURACION', 'El servidor no tiene disponible la conexión con DENUE.', false)
                    + ['perfil' => $resultado['perfil']];
            }
            return array_values($resultados);
        }

        $multi = curl_multi_init();
        $pendientes = [];
        $activos = [];
        $fin = microtime(true) + $this->presupuestoMs / 1000;
        $concurrencia = max(1, min(4, $concurrencia));
        foreach ($solicitudes as $id => $solicitud) {
            $pendientes[] = ['id' => $id, 'solicitud' => $solicitud, 'intento' => 1, 'desde' => 0];
        }
        try {
            while (($pendientes || $activos) && microtime(true) < $fin) {
                foreach ($pendientes as $pos => $trabajo) {
                    if (count($activos) >= $concurrencia) break;
                    if ($trabajo['desde'] > microtime(true)) continue;
                    $restante = (int)(($fin - microtime(true)) * 1000);
                    if ($restante < 100) break;
                    unset($pendientes[$pos]);
                    $handle = curl_init();
                    if ($handle === false) continue;
                    $datos = (object)['cuerpo' => '', 'retry_after' => 0, 'exceso' => false];
                    curl_setopt_array($handle, [
                        CURLOPT_URL => $trabajo['solicitud']['url'],
                        CURLOPT_CONNECTTIMEOUT_MS => min(2000, $restante),
                        CURLOPT_TIMEOUT_MS => min($this->timeoutMs, $restante),
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_USERAGENT => 'SistemaComercialIMPE/1.0 DENUE-candidatos',
                        CURLOPT_HTTPHEADER => ['Accept: application/json'],
                        CURLOPT_WRITEFUNCTION => static function ($ch, string $parte) use ($datos): int {
                            if (strlen($datos->cuerpo) + strlen($parte) > 8 * 1024 * 1024) {
                                $datos->exceso = true;
                                return 0;
                            }
                            $datos->cuerpo .= $parte;
                            return strlen($parte);
                        },
                        CURLOPT_HEADERFUNCTION => static function ($ch, string $linea) use ($datos): int {
                            if (stripos($linea, 'Retry-After:') === 0) {
                                $valor = trim(substr($linea, 12));
                                $datos->retry_after = ctype_digit($valor) ? (int)$valor : max(0, (int)strtotime($valor) - time());
                            }
                            return strlen($linea);
                        }
                    ] + DenueTlsPolicy::opciones($trabajo['solicitud']['url']));
                    curl_multi_add_handle($multi, $handle);
                    $activos[spl_object_id($handle)] = $trabajo + ['handle' => $handle, 'datos' => $datos];
                }
                do { $estado = curl_multi_exec($multi, $ejecutando); }
                while ($estado === CURLM_CALL_MULTI_PERFORM);
                if ($estado !== CURLM_OK) break;
                while ($info = curl_multi_info_read($multi)) {
                    $handle = $info['handle'];
                    $clave = spl_object_id($handle);
                    $trabajo = $activos[$clave];
                    $http = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
                    $errno = (int)$info['result'];
                    $respuesta = $trabajo['datos']->exceso
                        ? self::fallo('FORMATO', 'La respuesta de DENUE supera el tamaño permitido.', false)
                        : self::interpretar($trabajo['datos']->cuerpo, $http, $errno);
                    $respuesta['perfil'] = $trabajo['solicitud']['perfil'] ?? [];
                    $respuesta['intentos'] = $trabajo['intento'];
                    $resultados[$trabajo['id']] = $respuesta;
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                    unset($activos[$clave]);
                    if (!$respuesta['ok']) {
                        error_log('[DENUE candidatos] http=' . $http . ' curl_errno=' . $errno
                            . ' codigo=' . $respuesta['codigo'] . ' intento=' . $trabajo['intento']);
                    }
                    if (!empty($respuesta['reintentable']) && $trabajo['intento'] < 2) {
                        $espera = max(0.3, $trabajo['datos']->retry_after);
                        if (microtime(true) + $espera + 0.1 < $fin) {
                            $pendientes[] = [
                                'id' => $trabajo['id'], 'solicitud' => $trabajo['solicitud'],
                                'intento' => $trabajo['intento'] + 1, 'desde' => microtime(true) + $espera
                            ];
                        }
                    }
                }
                if ($activos) {
                    if (curl_multi_select($multi, 0.05) === -1) usleep(10000);
                } elseif ($pendientes) {
                    usleep(10000);
                }
            }
        } finally {
            foreach ($activos as $trabajo) {
                curl_multi_remove_handle($multi, $trabajo['handle']);
                curl_close($trabajo['handle']);
            }
            curl_multi_close($multi);
        }
        return array_values($resultados);
    }

    public static function interpretar(string $cuerpo, int $http, int $errno = 0): array
    {
        if ($errno !== 0) {
            if (in_array($errno, [51, 58, 60, 77, 83, 90], true)) {
                return self::fallo('CERTIFICADO', 'No se pudo verificar la conexión segura con DENUE. Solicita al administrador revisar los certificados.', false);
            }
            $temporal = in_array($errno, [5, 6, 7, 18, 28, 52, 55, 56], true);
            return self::fallo('CONEXION', 'No fue posible conectar con DENUE. Intenta nuevamente.', $temporal);
        }
        if ($http === 401 || $http === 403) {
            return self::fallo('AUTORIZACION', 'DENUE rechazó la autorización. Solicita al administrador revisar la configuración.', false);
        }
        if ($http !== 200) {
            return self::fallo('HTTP_' . $http,
                $http === 429 ? 'DENUE limitó temporalmente las consultas. Intenta nuevamente en unos momentos.'
                    : 'DENUE no está disponible en este momento. Intenta nuevamente.',
                in_array($http, [0, 408, 425, 429], true) || $http >= 500);
        }
        $datos = json_decode(ltrim($cuerpo, "\xEF\xBB\xBF"), true);
        if (!is_array($datos) || json_last_error() !== JSON_ERROR_NONE) {
            return self::fallo('FORMATO', 'DENUE devolvió una respuesta no válida. Intenta nuevamente.', true);
        }
        if ($datos === []) {
            return str_starts_with(ltrim($cuerpo, "\xEF\xBB\xBF \r\n\t"), '[')
                ? ['ok' => true, 'datos' => []]
                : self::fallo('FORMATO', 'DENUE devolvió una respuesta no válida. Intenta nuevamente.', true);
        }
        $filas = array_is_list($datos) ? $datos : [$datos];
        $registros = [];
        foreach ($filas as $fila) {
            if (is_array($fila) && ctype_digit((string)($fila['Id'] ?? $fila['id'] ?? $fila['ID'] ?? ''))) {
                $registros[] = $fila;
                continue;
            }
            $texto = is_string($fila) ? $fila : (is_array($fila) ? implode(' ', array_filter($fila, 'is_scalar')) : '');
            if (preg_match('/(?:autoriz|token|credencial|acceso denegado)/iu', $texto)) {
                return self::fallo('AUTORIZACION', 'DENUE rechazó la autorización. Solicita al administrador revisar la configuración.', false);
            }
            if (preg_match('/(?:no (?:hay|existen|se encontraron) (?:datos|resultados|registros)|sin resultados)/iu', $texto)) {
                continue;
            }
            return self::fallo('FORMATO', 'DENUE devolvió una respuesta no válida. Intenta nuevamente.', true);
        }
        return ['ok' => true, 'datos' => $registros];
    }

    private static function fallo(string $codigo, string $mensaje, bool $reintentable): array
    {
        return ['ok' => false, 'codigo' => $codigo, 'mensaje' => $mensaje, 'reintentable' => $reintentable];
    }
}
