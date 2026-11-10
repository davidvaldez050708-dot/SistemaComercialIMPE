<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mysql_admision.php';

class Database
{
    private static ?mysqli $compartida = null;
    private static ?RuntimeException $falloConexion = null;

    /**
     * Sesión independiente para consumidores con transacciones, bloqueos
     * o estado de sesión propio. En web se conserva el pool de Hostinger
     * mientras se mide la tasa de aperturas de las sesiones independientes.
     */
    public function connect(?bool $persistente = null): mysqli
    {
        return $this->abrir($persistente, false);
    }

    private function abrir(?bool $persistente, bool $compartida): mysqli
    {
        if (self::$falloConexion !== null) {
            throw self::$falloConexion;
        }
        $persistente = $persistente ?? (
            PHP_SAPI !== 'cli' && getenv('IMPE_DB_INDEPENDENT_PERSISTENT') !== '0'
        );
        $host = preg_replace('/^p:/', '', DB_HOST);
        $directorio = (string)getenv('IMPE_DB_ADMISION_DIR');
        $trazar = getenv('IMPE_DB_TRAZA') === '1';
        $ruta = ($directorio !== '' || $trazar) ? self::ruta() : '';
        $admision = null;
        $reserva = null;
        $prioridad = self::prioridad($ruta);
        $inicio = hrtime(true);
        $espera = 0;
        $resultado = 'sin_intento';
        $codigo = 0;
        try {
            if ($directorio !== '') {
                $admision = new AdmisionMysql($directorio);
                $reserva = $admision->reservar($prioridad);
                $espera = (int)((hrtime(true) - $inicio) / 1000000);
            }
            $antes = ($admision !== null || $trazar) ? self::reutilizaciones() : null;
            $resultado = 'intento_fallido';
            $conexion = mysqli_init();
            if (!$conexion || !$conexion->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3) ||
                !@$conexion->real_connect(($persistente ? 'p:' : '') . $host, DB_USER, DB_PASSWORD, DB_NAME)) {
                throw new mysqli_sql_exception('Conexión MySQL no disponible.', mysqli_connect_errno());
            }
            $despues = $antes !== null ? self::reutilizaciones() : null;
            $resultado = $despues === null ? 'sin_medicion' :
                ($despues > $antes ? 'reutilizada' : 'nueva');
            if (!$conexion->set_charset('utf8mb4')) {
                throw new RuntimeException('No fue posible configurar la conexión.');
            }
            return $conexion;
        } catch (Throwable $e) {
            $codigo = (int)$e->getCode();
            if ($e instanceof AdmisionMysqlOcupada) {
                $resultado = $codigo === AdmisionMysqlOcupada::PLAZO ? 'sin_cupo' : 'estado_no_disponible';
                $espera = (int)((hrtime(true) - $inicio) / 1000000);
                error_log('[impe_mysql_admision] ' . $e->getMessage());
            } else {
                error_log('[impe_mysql] fallo de conexión; código=' . $codigo);
            }
            $error = new RuntimeException('No fue posible conectar con la base de datos.', 0, $e);
            if (self::esFalloTemporal($error)) {
                self::$falloConexion = $error;
            }
            throw $error;
        } finally {
            if ($admision !== null && $reserva !== null) {
                try {
                    $admision->completar($reserva, $resultado === 'reutilizada');
                } catch (AdmisionMysqlOcupada $error) {
                    error_log('[impe_mysql_admision] No fue posible confirmar la reserva.');
                }
            }
            if ($trazar) {
                error_log('[impe_mysql_traza] ' . json_encode([
                    'ruta' => $ruta, 'pid' => getmypid(), 'compartida' => $compartida,
                    'persistente' => $persistente, 'resultado' => $resultado,
                    'codigo' => $codigo, 'espera_ms' => $espera, 'prioridad' => $prioridad,
                ]));
            }
        }
    }

    private static function reutilizaciones(): ?int
    {
        if (!function_exists('mysqli_get_client_stats') || !filter_var(ini_get('mysqlnd.collect_statistics'), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }
        $valor = mysqli_get_client_stats()['connection_reused'] ?? null;
        return filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0], 'flags' => FILTER_NULL_ON_FAILURE]);
    }

    private static function prioridad(string $ruta): string
    {
        $metodo = $_SERVER['REQUEST_METHOD'] ?? '';
        return match ($ruta) {
            'api.zadarma_webhook', 'api.zadarma_entrada_activa',
            'telefonia.autorizarTransferencia', 'telefonia.validarOrdenTransferencia',
            'telefonia.iniciarMarcacionVenta', 'telefonia.vincularMarcacionVenta' => AdmisionMysql::CRITICA,
            'api.zadarma_webrtc' => AdmisionMysql::ARRANQUE_TELEFONO,
            'api.zadarma_estado_llamada' => (int)($_GET['checkpoint'] ?? 0) === 1 ?
                AdmisionMysql::ARRANQUE_TELEFONO : AdmisionMysql::CRITICA,
            'api.transferencia_operacion' => $metodo === 'POST' ? AdmisionMysql::CRITICA : AdmisionMysql::SONDEO,
            'telefonia.disponibilidad' => $metodo === 'POST' &&
                !in_array($_POST['accion'] ?? '', ['POLL', 'PRESENCIA'], true) ?
                AdmisionMysql::CRITICA : AdmisionMysql::SONDEO,
            'api.avisos_llamadas', 'api.borrador_llamada',
            'telefonia.contextoTransferencia' => AdmisionMysql::SONDEO,
            default => AdmisionMysql::NORMAL,
        };
    }

    private static function ruta(): string
    {
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('/^(zadarma_[a-z_]+|avisos_llamadas|transferencia_operacion|borrador_llamada)\.php$/D', $script, $coincidencia)) {
            return 'api.' . $coincidencia[1];
        }
        $controlador = $_GET['controller'] ?? '';
        $accion = $_GET['action'] ?? '';
        if (is_string($controlador) && is_string($accion) &&
            in_array($controlador, ['login', 'home', 'reminder', 'telefonia'], true) &&
            in_array($accion, [
                'iniciarSesion', 'mostrarLogin', 'index', 'pendientes', 'shell', 'disponibilidad',
                'autorizarTransferencia', 'contextoTransferencia', 'validarOrdenTransferencia',
                'iniciarMarcacionVenta', 'vincularMarcacionVenta',
            ], true)) {
            return $controlador . '.' . $accion;
        }
        return 'otras';
    }

    /**
     * Para consumidores auditados sin transacciones propias ni cierre manual.
     * PHP libera esta referencia al finalizar la petición.
     */
    public static function compartida(): mysqli
    {
        $persistente = PHP_SAPI !== 'cli' && getenv('IMPE_DB_PERSISTENT') !== '0';
        return self::$compartida ??= (new self())->abrir($persistente, true);
    }

    public static function esFalloTemporal(Throwable $error): bool
    {
        do {
            if ($error instanceof AdmisionMysqlOcupada) {
                return true;
            }
            if ($error instanceof mysqli_sql_exception &&
                in_array((int)$error->getCode(), [1040, 1203, 1226, 2002, 2003, 2006, 2013], true)) {
                return true;
            }
            $error = $error->getPrevious();
        } while ($error !== null);
        return false;
    }

    public static function responderFalloTemporal(Throwable $error, bool $json = true): bool
    {
        if (!self::esFalloTemporal($error)) {
            return false;
        }
        http_response_code(503);
        header('Retry-After: 3');
        header('Cache-Control: no-store');
        $mensaje = 'El servicio está temporalmente ocupado. Intenta nuevamente en unos segundos.';
        header('Content-Type: ' . ($json ? 'application/json' : 'text/plain') . '; charset=utf-8');
        echo $json ? json_encode(['ok' => false, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE) : $mensaje;
        return true;
    }
}
