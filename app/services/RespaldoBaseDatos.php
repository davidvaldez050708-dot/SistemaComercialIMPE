<?php

require_once __DIR__ . '/../../config/config.php';

final class RespaldoBaseDatos
{
    public const LIMITE_HISTORIAL = 20;

    private string $directorio = ROOT_PATH . '/storage/backups';

    public function historial(): array
    {
        $respaldos = [];
        foreach (glob($this->directorio . '/db-backup-*.sql') ?: [] as $ruta) {
            $nombre = basename($ruta);
            if ($this->nombreValido($nombre) && is_file($ruta) && !is_link($ruta)) {
                $respaldos[] = [
                    'nombre' => $nombre,
                    'fecha' => filemtime($ruta),
                    'bytes' => filesize($ruta),
                ];
            }
        }
        usort($respaldos, static function (array $a, array $b): int {
            return [$b['fecha'], $b['nombre']] <=> [$a['fecha'], $a['nombre']];
        });
        return $respaldos;
    }

    public function archivo(string $nombre): string
    {
        $ruta = $this->directorio . '/' . $nombre;
        if (!$this->nombreValido($nombre) || !is_file($ruta) || is_link($ruta)) {
            throw new DomainException('El respaldo solicitado no está disponible.');
        }
        return $ruta;
    }

    public function generar(): string
    {
        return $this->conBloqueo(fn () => $this->crearRespaldo());
    }

    public function eliminar(string $nombre): void
    {
        $this->conBloqueo(function () use ($nombre): void {
            if (!@unlink($this->archivo($nombre))) {
                throw new DomainException('No se pudo eliminar el respaldo.');
            }
        });
    }

    public function restaurar(string $origen, bool $subido = false): void
    {
        $this->conBloqueo(function () use ($origen, $subido): void {
            $ruta = $subido ? $origen : $this->archivo($origen);
            $entrada = @fopen($ruta, 'rb');
            if (!$entrada) {
                throw new DomainException('No se pudo abrir el archivo SQL.');
            }

            try {
                $cliente = $this->ejecutable(['mariadb', 'mysql']);
                $copia = $this->crearRespaldo(false);
                try {
                    $this->ejecutar($cliente, [
                        '--binary-mode', '--local-infile=0', '--skip-reconnect', '--database=' . DB_NAME,
                    ], $entrada);
                } catch (RuntimeException $e) {
                    throw new DomainException(
                        'La restauración falló y pudo aplicar cambios parciales. La copia previa está disponible: ' . $copia,
                        0,
                        $e
                    );
                }
            } finally {
                fclose($entrada);
                if (isset($copia)) {
                    $this->rotarHistorial($copia);
                }
            }
        });
    }

    private function nombreValido(string $nombre): bool
    {
        return preg_match('/\Adb-backup-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}-[a-f0-9]{12}\.sql\z/', $nombre) === 1;
    }

    private function conBloqueo(callable $operacion): mixed
    {
        if (!is_dir($this->directorio) && !@mkdir($this->directorio, 0700, true) && !is_dir($this->directorio)) {
            throw new DomainException('No se pudo preparar el directorio de respaldos.');
        }
        $bloqueo = @fopen($this->directorio . '/.lock', 'c');
        if (!$bloqueo) {
            throw new DomainException('No se pudo acceder al directorio de respaldos.');
        }

        try {
            if (!flock($bloqueo, LOCK_EX | LOCK_NB)) {
                throw new DomainException('Ya hay una operación de respaldo en curso. Inténtalo de nuevo en unos momentos.');
            }
            set_time_limit(0);
            return $operacion();
        } finally {
            fclose($bloqueo);
        }
    }

    private function crearRespaldo(bool $rotar = true): string
    {
        $dump = $this->ejecutable(['mariadb-dump', 'mysqldump']);
        $nombre = 'db-backup-' . date('Y-m-d_H-i-s') . '-' . bin2hex(random_bytes(6)) . '.sql';
        $destino = $this->directorio . '/' . $nombre;
        $temporal = $destino . '.part';
        try {
            $this->ejecutar($dump, [
                '--single-transaction', '--quick', '--hex-blob', '--no-tablespaces',
                '--routines', '--events', '--triggers', '--add-drop-table', '--', DB_NAME,
            ], null, $temporal);
            if (!is_file($temporal) || filesize($temporal) === 0 || !@rename($temporal, $destino)) {
                throw new RuntimeException('No se pudo guardar el respaldo.');
            }
        } finally {
            if (is_file($temporal)) {
                @unlink($temporal);
            }
        }
        if ($rotar) {
            $this->rotarHistorial($nombre);
        }
        return $nombre;
    }

    private function rotarHistorial(string $conservar): void
    {
        $restantes = self::LIMITE_HISTORIAL - 1;
        foreach ($this->historial() as $respaldo) {
            if ($respaldo['nombre'] !== $conservar && $restantes-- <= 0) {
                if (!@unlink($this->directorio . '/' . $respaldo['nombre'])) {
                    error_log('[impe_respaldo] No se pudo depurar un respaldo antiguo.');
                }
            }
        }
    }

    private function ejecutable(array $nombres): string
    {
        $configurado = getenv('IMPE_MYSQL_BIN_DIR');
        $directorios = $configurado !== false && $configurado !== ''
            ? [$configurado]
            : explode(PATH_SEPARATOR, (string)getenv('PATH'));
        if (!$configurado && PHP_OS_FAMILY === 'Windows') {
            array_unshift($directorios, dirname(PHP_BINARY, 2) . '/mysql/bin', 'C:/xampp/mysql/bin');
        }
        foreach ($directorios as $directorio) {
            if (trim($directorio) === '') {
                continue;
            }
            foreach ($nombres as $nombre) {
                $ruta = realpath(rtrim(trim($directorio, '"'), '/\\') . '/' . $nombre
                    . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''));
                if ($ruta !== false && is_file($ruta) && is_executable($ruta)) {
                    return $ruta;
                }
            }
        }
        throw new DomainException('El servidor necesita las herramientas de MySQL o MariaDB para realizar esta operación.');
    }

    private function ejecutar(string $programa, array $opciones, $entrada = null, ?string $salida = null): void
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('La ejecución de herramientas de base de datos está deshabilitada.');
        }
        $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $host = preg_replace('/^p:/', '', DB_HOST);
        $conexion = ['--user=' . DB_USER, '--default-character-set=utf8mb4'];
        if (preg_match('/^([^:]+):(\d+)$/D', $host, $partes)) {
            $host = $partes[1];
            $conexion[] = '--port=' . $partes[2];
        } elseif (preg_match('#^([^:]+):(/.+)$#D', $host, $partes)) {
            $host = $partes[1];
            $conexion[] = '--socket=' . $partes[2];
        }
        $conexion[] = '--host=' . $host;

        $entorno = getenv();
        $entorno['MYSQL_PWD'] = DB_PASSWORD;
        $proceso = @proc_open(
            array_merge([$programa, '--no-defaults'], $conexion, $opciones),
            [$entrada ?? ['file', $nulo, 'r'], ['file', $salida ?? $nulo, 'wb'], ['pipe', 'w']],
            $tuberias,
            ROOT_PATH,
            $entorno,
            ['bypass_shell' => true]
        );
        if (!is_resource($proceso)) {
            throw new RuntimeException('No se pudo iniciar la herramienta de base de datos.');
        }
        // Drenar stderr evita bloquear el proceso y evita registrar datos SQL sensibles.
        while (!feof($tuberias[2])) {
            fread($tuberias[2], 8192);
        }
        fclose($tuberias[2]);
        $codigo = proc_close($proceso);
        if ($codigo !== 0) {
            error_log('[impe_respaldo] ' . basename($programa) . ' terminó con código ' . $codigo);
            throw new RuntimeException('La herramienta de base de datos no pudo completar la operación.');
        }
    }
}
