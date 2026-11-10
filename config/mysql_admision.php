<?php

final class AdmisionMysqlOcupada extends RuntimeException
{
    public const PLAZO = 1;
    public const ESTADO = 2;
}

/** Quince cupos independientes por cuenta y host. */
final class AdmisionMysql
{
    public const NORMAL = 'normal';
    public const SONDEO = 'sondeo';
    public const ARRANQUE_TELEFONO = 'arranque_telefono';
    public const CRITICA = 'critica';

    private const VENTANA = 1.05;
    private const PENDIENTE = 30.0;
    private const CUPOS_NORMALES = 12;
    private const CUPOS_TOTALES = 15;

    private string $directorio;
    private string $arranque;

    public function __construct(string $directorio)
    {
        $ruta = realpath($directorio);
        $publico = realpath(dirname(__DIR__));
        if ($ruta === false || !is_dir($ruta) || !is_writable($ruta) || $publico === false ||
            str_starts_with(strtolower($ruta . DIRECTORY_SEPARATOR), strtolower($publico . DIRECTORY_SEPARATOR)) ||
            (PHP_OS_FAMILY !== 'Windows' && (fileperms($ruta) & 0077) !== 0)) {
            throw new AdmisionMysqlOcupada('El directorio privado de admisión no está disponible.', AdmisionMysqlOcupada::ESTADO);
        }
        $this->directorio = $ruta . DIRECTORY_SEPARATOR;
        $bootId = PHP_OS_FAMILY === 'Linux' ?
            trim((string)@file_get_contents('/proc/sys/kernel/random/boot_id', false, null, 0, 40)) : '';
        $this->arranque = preg_match('/^[a-f0-9-]{36}$/D', $bootId) ? $bootId : '-';
    }

    /** @return array{candado: resource, estado: string} */
    public function reservar(string $prioridad): array
    {
        $presupuesto = match ($prioridad) {
            self::CRITICA => 0.24,
            self::ARRANQUE_TELEFONO => 1.25,
            self::SONDEO => 1.25,
            self::NORMAL => 4.2,
        };
        $limite = self::ahora() + $presupuesto;
        $turnos = range(1, self::CUPOS_NORMALES);
        shuffle($turnos);
        if ($prioridad === self::CRITICA) {
            $reservados = range(self::CUPOS_NORMALES + 1, self::CUPOS_TOTALES);
            shuffle($reservados);
            $turnos = array_merge($reservados, $turnos);
        } elseif ($prioridad === self::ARRANQUE_TELEFONO) {
            array_unshift($turnos, self::CUPOS_NORMALES + 1);
        }

        $fallo = null;
        do {
            foreach ($turnos as $clave => $turno) {
                try {
                    $reserva = $this->intentar($turno, $limite);
                } catch (AdmisionMysqlOcupada $error) {
                    $fallo = $error;
                    unset($turnos[$clave]);
                    continue;
                }
                if ($reserva !== null) {
                    return $reserva;
                }
                if (self::ahora() >= $limite) {
                    break 2;
                }
            }
            $restante = $limite - self::ahora();
            if ($restante > 0) {
                usleep((int)min($restante * 1000000, random_int(5000, 14000)));
            }
        } while ($turnos && self::ahora() < $limite);

        if ($fallo !== null) {
            throw $fallo;
        }
        throw new AdmisionMysqlOcupada('No hay cupo para abrir una conexión.', AdmisionMysqlOcupada::PLAZO);
    }

    public function completar(array $reserva, bool $reutilizada): void
    {
        try {
            $this->guardar($reserva['estado'], $reutilizada ? 0.0 : self::ahora() + self::VENTANA);
        } finally {
            fclose($reserva['candado']);
        }
    }

    private function intentar(int $turno, float $limite): ?array
    {
        $base = $this->directorio . 'mysql-cupo-v2-' . $turno;
        $archivo = $base . '.lock';
        if (is_link($archivo) || (file_exists($archivo) && !is_file($archivo))) {
            return null;
        }
        $candado = @fopen($archivo, 'c+b');
        if ($candado === false) {
            return null;
        }
        $concedida = false;
        try {
            if (!@flock($candado, LOCK_EX | LOCK_NB)) {
                return null;
            }
            $estado = $base . '.estado';
            $ahora = self::ahora();
            if ($ahora >= $limite || !$this->disponible($estado, $ahora)) {
                return null;
            }
            $this->guardar($estado, $ahora + self::PENDIENTE);
            $concedida = true;
            return ['candado' => $candado, 'estado' => $estado];
        } finally {
            if (!$concedida) {
                fclose($candado);
            }
        }
    }

    private function disponible(string $estado, float $ahora): bool
    {
        clearstatcache(true, $estado);
        if (is_link($estado)) {
            return false;
        }
        if (!file_exists($estado)) {
            return true;
        }
        if (!is_file($estado)) {
            return false;
        }
        $contenido = @file_get_contents($estado, false, null, 0, 100);
        if ($contenido === false || !preg_match('/^([a-f0-9-]{36}|-) (\d+\.\d{6})$/D', $contenido, $partes)) {
            return false;
        }
        if ($this->arranque !== '-' && $partes[1] !== '-' && $partes[1] !== $this->arranque) {
            return true; // Reinicio confirmado del mismo host.
        }
        return (float)$partes[2] <= $ahora;
    }

    private function guardar(string $estado, float $vence): void
    {
        $temporal = @tempnam($this->directorio, '.mysql-cupo-');
        if ($temporal === false) {
            throw new AdmisionMysqlOcupada('No fue posible guardar la reserva.', AdmisionMysqlOcupada::ESTADO);
        }
        try {
            $contenido = $this->arranque . ' ' . sprintf('%.6F', $vence);
            if (strcasecmp(dirname($temporal) . DIRECTORY_SEPARATOR, $this->directorio) !== 0 ||
                @file_put_contents($temporal, $contenido) !== strlen($contenido)) {
                throw new AdmisionMysqlOcupada('No fue posible escribir el estado temporal.', AdmisionMysqlOcupada::ESTADO);
            }
            for ($intento = 0; $intento < 3; $intento++) {
                if (@rename($temporal, $estado)) {
                    return;
                }
                if ($intento < 2) {
                    usleep(2000);
                }
            }
            throw new AdmisionMysqlOcupada('No fue posible reemplazar atómicamente el estado.', AdmisionMysqlOcupada::ESTADO);
        } finally {
            if (is_file($temporal)) {
                @unlink($temporal);
            }
        }
    }

    private static function ahora(): float
    {
        return hrtime(true) / 1000000000;
    }
}
