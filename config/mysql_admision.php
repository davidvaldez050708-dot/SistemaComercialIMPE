<?php

final class AdmisionMysqlOcupada extends RuntimeException
{
}

/** Coordina aperturas de una cuenta entre workers del mismo host. */
final class AdmisionMysql
{
    private const VENTANA = 1.05;
    private const PENDIENTE = 30.0;
    private const MAXIMO = 15;
    private const CUPO_NORMAL = 13;

    private string $directorio;

    public function __construct(string $directorio)
    {
        $ruta = realpath($directorio);
        $publico = realpath(dirname(__DIR__));
        if ($ruta === false || !is_dir($ruta) || !is_writable($ruta) || $publico === false ||
            str_starts_with(strtolower($ruta . DIRECTORY_SEPARATOR), strtolower($publico . DIRECTORY_SEPARATOR)) ||
            (PHP_OS_FAMILY !== 'Windows' && (fileperms($ruta) & 0077) !== 0)) {
            throw new AdmisionMysqlOcupada('El directorio privado de admisión no está disponible.');
        }
        $this->directorio = $ruta . DIRECTORY_SEPARATOR;
    }

    public function reservar(bool $urgente): string
    {
        $marca = $this->directorio . 'mysql-cupo-' . bin2hex(random_bytes(16)) . '.reserva';
        $limite = self::ahora() + ($urgente ? 0.24 : 4.2);
        $cupo = $urgente ? self::MAXIMO : self::CUPO_NORMAL;

        do {
            if ($this->registrarSiHayCupo($marca, $cupo)) {
                return $marca;
            }
            $restante = $limite - self::ahora();
            if ($restante <= 0) {
                break;
            }
            usleep((int)min($restante * 1000000, random_int(12000, 30000)));
        } while (self::ahora() < $limite);

        throw new AdmisionMysqlOcupada('No hay cupo inmediato para abrir una conexión.');
    }

    public function completar(string $marca, bool $reutilizada): void
    {
        // El resultado se escribe por separado. Un lector puede estar
        // leyendo la reserva en un sistema que no admite borrar archivos abiertos.
        $this->guardar($marca . '.fin', $reutilizada ? 0.0 : self::ahora() + self::VENTANA);
    }

    private function registrarSiHayCupo(string $marca, int $cupo): bool
    {
        $candado = @fopen($this->directorio . 'mysql-aperturas.lock', 'c+b');
        if ($candado === false) {
            throw new AdmisionMysqlOcupada('No fue posible abrir el candado de admisión.');
        }
        try {
            if (!flock($candado, LOCK_EX | LOCK_NB)) {
                return false;
            }
            $marcas = glob($this->directorio . 'mysql-cupo-*.reserva', GLOB_NOSORT);
            if ($marcas === false) {
                throw new AdmisionMysqlOcupada('No fue posible consultar el estado de admisión.');
            }
            $ahora = self::ahora();
            $ocupadas = 0;
            foreach ($marcas as $archivo) {
                $fin = $archivo . '.fin';
                // Ausencia de confirmación se contabiliza conservadoramente.
                $cerrada = is_file($fin);
                $contenido = @file_get_contents($cerrada ? $fin : $archivo, false, null, 0, 64);
                if ($contenido === false || !preg_match('/^\d+\.\d{6}$/D', $contenido)) {
                    throw new AdmisionMysqlOcupada('El estado de admisión requiere revisión.');
                }
                $vence = (float)$contenido;
                // Tras un reinicio del host cambia el origen del reloj monotónico.
                if ($vence <= $ahora || $vence > $ahora + self::PENDIENTE + 0.001) {
                    if (@unlink($archivo) && $cerrada) {
                        @unlink($fin);
                    }
                    continue;
                }
                $ocupadas++;
            }
            if ($ocupadas >= $cupo) {
                return false;
            }
            $this->guardar($marca, $ahora + self::PENDIENTE);
            return true;
        } finally {
            fclose($candado);
        }
    }

    private function guardar(string $marca, float $vence): void
    {
        $temporal = @tempnam($this->directorio, '.mysql-aperturas-');
        if ($temporal === false) {
            throw new AdmisionMysqlOcupada('No fue posible guardar la reserva de admisión.');
        }
        try {
            $contenido = sprintf('%.6F', $vence);
            if (@file_put_contents($temporal, $contenido) !== strlen($contenido) ||
                !@rename($temporal, $marca)) {
                throw new AdmisionMysqlOcupada('No fue posible guardar la reserva de admisión.');
            }
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
