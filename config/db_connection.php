<?php

require_once __DIR__ . '/config.php';

class Database
{
    private static ?mysqli $compartida = null;
    private static ?RuntimeException $falloConexion = null;

    /**
     * Sesión independiente para consumidores con transacciones, bloqueos
     * o estado de sesión propio. La persistencia entre peticiones es optativa.
     */
    public function connect(?bool $persistente = null): mysqli
    {
        if (self::$falloConexion !== null) {
            throw self::$falloConexion;
        }
        $persistente = $persistente ?? false;
        $host = preg_replace('/^p:/', '', DB_HOST);
        try {
            $conexion = mysqli_init();
            if (!$conexion || !$conexion->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3) ||
                !@$conexion->real_connect(($persistente ? 'p:' : '') . $host, DB_USER, DB_PASSWORD, DB_NAME)) {
                throw new mysqli_sql_exception('Conexión MySQL no disponible.', mysqli_connect_errno());
            }
            if (!$conexion->set_charset('utf8mb4')) {
                throw new RuntimeException('No fue posible configurar la conexión.');
            }
            return $conexion;
        } catch (Throwable $e) {
            error_log('[impe_mysql] fallo de conexión; código=' . (int)$e->getCode());
            $error = new RuntimeException('No fue posible conectar con la base de datos.', 0, $e);
            if (self::esFalloTemporal($error)) {
                self::$falloConexion = $error;
            }
            throw $error;
        }
    }

    /**
     * Para consumidores auditados sin transacciones propias ni cierre manual.
     * PHP libera esta referencia al finalizar la petición.
     */
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
