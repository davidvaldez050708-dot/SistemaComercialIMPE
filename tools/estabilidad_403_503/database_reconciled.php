<?php

require_once __DIR__ . '/config.php';

class Database
{
    private static ?mysqli $compartida = null;
    private static ?RuntimeException $falloConexion = null;
    private static ?WeakMap $diagnosticos = null;

    /**
     * Conserva la política publicada: sesiones independientes para transacciones;
     * persistencia web configurable, sin reintentos de conexiones ni operaciones.
     */
    public function connect(?bool $persistente = null): mysqli
    {
        if (self::$falloConexion !== null) {
            throw self::$falloConexion;
        }
        $persistente = $persistente ?? (
            PHP_SAPI !== 'cli' && getenv('IMPE_DB_INDEPENDENT_PERSISTENT') !== '0'
        );
        $host = preg_replace('/^(?:p:)+/', '', DB_HOST);
        $conexion = null;
        $motivo = null;
        $etapa = 'init';
        try {
            $conexion = mysqli_init();
            if (!$conexion) {
                throw new RuntimeException('No fue posible inicializar MySQL.');
            }
            $etapa = 'options';
            if (!$conexion->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3)) {
                throw new RuntimeException('No fue posible configurar MySQL.');
            }
            $etapa = 'connect';
            if (!@$conexion->real_connect(($persistente ? 'p:' : '') . $host, DB_USER, DB_PASSWORD, DB_NAME)) {
                // Con reportes desactivados también conservar el errno nativo.
                $motivo = self::motivoTecnico((string)mysqli_connect_error());
                throw new mysqli_sql_exception('Conexión MySQL no disponible.', mysqli_connect_errno());
            }
            $etapa = 'charset';
            if (!@$conexion->set_charset('utf8mb4')) {
                throw new mysqli_sql_exception('No fue posible configurar la conexión.', $conexion->errno);
            }
            return $conexion;
        } catch (Throwable $e) {
            self::registrarFallo($e, $etapa, $persistente, $motivo);
            if ($conexion) {
                try { $conexion->close(); } catch (Throwable $ignorado) {}
            }
            // Se conserva el contrato y la cadena original de excepciones.
            $error = new RuntimeException('No fue posible conectar con la base de datos.', 0, $e);
            if (self::esFalloTemporal($error)) {
                self::$falloConexion = $error;
            }
            throw $error;
        }
    }

    /** Solo para consumidores auditados sin transacción propia ni close(). */
    public static function compartida(): mysqli
    {
        $persistente = PHP_SAPI !== 'cli' && getenv('IMPE_DB_PERSISTENT') !== '0';
        return self::$compartida ??= (new self())->connect($persistente);
    }

    public static function esFalloTemporal(Throwable $error): bool
    {
        do {
            if ($error instanceof mysqli_sql_exception &&
                in_array((int)$error->getCode(), [1040, 1203, 1226, 2002, 2003, 2006, 2013], true)) {
                return true;
            }
            $error = $error->getPrevious();
        } while ($error !== null);
        return false;
    }

    private static function motivoTecnico(string $mensaje): ?string
    {
        // Solo categorías cerradas; nunca guardar el mensaje original ni valores.
        $mensaje = strtolower($mensaje);
        foreach ([
            'max_user_connections'=>'max_user_connections',
            'max_connections_per_hour'=>'max_connections_per_hour',
            'max_queries_per_hour'=>'max_queries_per_hour',
            'max_updates_per_hour'=>'max_updates_per_hour',
            'operation not permitted'=>'os_operation_not_permitted',
            'permission denied'=>'os_permission_denied',
            'connection refused'=>'connection_refused',
            'actively refused'=>'connection_refused',
            'too many connections'=>'too_many_connections',
            'server has gone away'=>'server_gone_away',
            'lost connection'=>'connection_lost',
            'timed out'=>'timeout',
        ] as $texto => $categoria) {
            if (str_contains($mensaje, $texto)) return $categoria;
        }
        return null;
    }

    private static function registrarFallo(Throwable $error, string $etapa, ?bool $persistente = null, ?string $motivo = null): string
    {
        self::$diagnosticos ??= new WeakMap();
        $causa = $error;
        $mysql = null;
        do {
            if (isset(self::$diagnosticos[$causa])) {
                return self::$diagnosticos[$causa];
            }
            if ($causa instanceof mysqli_sql_exception) $mysql = $causa;
            $ultima = $causa;
            $causa = $causa->getPrevious();
        } while ($causa !== null);
        $id = substr(hash('sha256', getmypid() . ':' . microtime(true) . ':' . spl_object_id($error)), 0, 20);
        $sqlstate = $mysql ? $mysql->getSqlState() : null;
        // Una excepción creada para MYSQLI_REPORT_OFF no dispone de SQLSTATE nativo.
        if ($sqlstate === '00000') $sqlstate = null;
        $ruta = static function ($valor): ?string {
            return is_string($valor) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $valor) ? $valor : null;
        };
        error_log('[impe_mysql] ' . json_encode([
            'time_utc' => gmdate('c'), 'error_id' => $id,
            'origin' => 'application.database', 'stage' => $etapa,
            'errno' => (int)($mysql ?? $ultima)->getCode(), 'sqlstate' => $sqlstate,
            'reason' => $motivo ?? self::motivoTecnico(($mysql ?? $ultima)->getMessage()),
            'exception' => get_class($mysql ?? $ultima),
            'temporary' => self::esFalloTemporal($error), 'persistent' => $persistente,
            'controller' => $ruta($_GET['controller'] ?? null),
            'action' => $ruta($_GET['action'] ?? null), 'pid' => getmypid(),
        ], JSON_UNESCAPED_SLASHES));
        self::$diagnosticos[$error] = $id;
        self::$diagnosticos[$ultima] = $id;
        return $id;
    }

    public static function responderFalloTemporal(Throwable $error, bool $json = true): bool
    {
        if (!self::esFalloTemporal($error)) {
            $causa = $error;
            do {
                if ($causa instanceof mysqli_sql_exception) {
                    self::registrarFallo($error, 'response');
                    break;
                }
                $causa = $causa->getPrevious();
            } while ($causa !== null);
            return false;
        }
        $id = self::registrarFallo($error, 'response');
        http_response_code(503);
        header('X-IMPE-DB-Error-ID: ' . $id);
        header('Retry-After: 3');
        header('Cache-Control: no-store');
        $mensaje = 'El servicio está temporalmente ocupado. Intenta nuevamente en unos segundos.';
        header('Content-Type: ' . ($json ? 'application/json' : 'text/plain') . '; charset=utf-8');
        echo $json ? json_encode(['ok' => false, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE) : $mensaje;
        return true;
    }
}
