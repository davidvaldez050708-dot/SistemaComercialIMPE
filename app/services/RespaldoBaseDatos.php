<?php

require_once __DIR__ . '/../../config/config.php';

final class RespaldoBaseDatos
{
    private ?string $directorio;
    private array $ajustes = [];

    public function __construct()
    {
        $privada = dirname(ROOT_PATH) . '/impe-private/respaldo.local.php';
        if (basename(ROOT_PATH) === 'public_html' && realpath($privada) === $privada
            && is_file($privada) && is_readable($privada)
            && (fileperms($privada) & 0077) === 0) {
            $ajustes = require $privada;
            $this->ajustes = is_array($ajustes) ? $ajustes : [];
        }
        $config = $this->ajuste('IMPE_BACKUP_DIR', 'directorio');
        $real = $config ? realpath($config) : false;
        $this->directorio = $real && is_dir($real) ? $real : null;
        if ($this->directorio !== null) {
            $ruta = strtolower(str_replace('\\', '/', $real)) . '/';
            foreach ([ROOT_PATH, $_SERVER['DOCUMENT_ROOT'] ?? ''] as $publica) {
                $raiz = $publica !== '' ? realpath($publica) : false;
                if ($raiz && str_starts_with($ruta, strtolower(str_replace('\\', '/', $raiz)) . '/')) {
                    $this->directorio = null;
                }
            }
        }
    }

    private function ajuste(string $variable, string $campo): string
    {
        $valor = getenv($variable);
        if (is_string($valor) && $valor !== '') {
            return $valor;
        }

        return is_string($this->ajustes[$campo] ?? null) ? $this->ajustes[$campo] : '';
    }

    private function clave(): string
    {
        $clave = $this->ajuste('IMPE_BACKUP_HMAC_KEY', 'clave_hmac');
        if (!preg_match('/\A[a-f0-9]{64}\z/i', $clave)) {
            throw new DomainException('Falta configurar la clave privada de integridad de respaldos.');
        }
        return hex2bin($clave);
    }

    private function verificar(string $ruta, string $nombre): array
    {
        $meta = $ruta . '.json';
        if (!is_file($meta) || is_link($meta) || filesize($meta) > 4096) {
            throw new DomainException('El respaldo carece de metadatos verificables.');
        }
        $sobre = json_decode((string)file_get_contents($meta), true);
        $datos = $sobre['datos'] ?? null;
        $firma = $sobre['firma'] ?? null;
        if (!is_string($datos) || !is_string($firma) ||
            !hash_equals(hash_hmac('sha256', $datos, $this->clave()), $firma)) {
            throw new DomainException('Los metadatos del respaldo son inválidos.');
        }
        $m = json_decode($datos, true);
        $hash = hash_file('sha256', $ruta);
        if (($m['version'] ?? null) !== 1 || ($m['base'] ?? null) !== DB_NAME ||
            ($m['nombre'] ?? null) !== $nombre || ($m['bytes'] ?? null) !== filesize($ruta) ||
            !is_string($hash) || !hash_equals((string)($m['sha256'] ?? ''), $hash)) {
            throw new DomainException('El respaldo está alterado o es incompatible.');
        }
        return $m;
    }

    public function historial(): array
    {
        $respaldos = [];
        $rutas = glob(ROOT_PATH . '/storage/backups/db-backup-*.sql') ?: [];
        if ($this->directorio !== null) {
            $rutas = array_merge($rutas, glob($this->directorio . '/db-backup-*.sql') ?: []);
        }
        foreach ($rutas as $ruta) {
            $nombre = basename($ruta);
            if (!$this->nombreValido($nombre) || !is_file($ruta) || is_link($ruta)) {
                continue;
            }
            $estado = 'Histórico sin verificar';
            $fecha = filemtime($ruta);
            if ($this->directorio !== null && dirname($ruta) === $this->directorio) {
                if (!is_file($ruta . '.json')) continue;
                try {
                    $meta = $this->verificar($ruta, $nombre);
                    $fecha = $meta['fecha'];
                    $estado = 'Integridad verificada';
                } catch (DomainException $e) {
                    $estado = 'Integridad no verificada';
                }
            }
            $respaldos[$nombre] = ['nombre' => $nombre, 'fecha' => $fecha,
                'bytes' => filesize($ruta), 'estado' => $estado];
        }
        usort($respaldos, static function (array $a, array $b): int {
            return [$b['fecha'], $b['nombre']] <=> [$a['fecha'], $a['nombre']];
        });
        return $respaldos;
    }

    public function archivo(string $nombre): string
    {
        $ruta = $this->directorio !== null ? $this->directorio . '/' . $nombre : '';
        if (!is_file($ruta)) $ruta = ROOT_PATH . '/storage/backups/' . $nombre;
        if (!$this->nombreValido($nombre) || !is_file($ruta) || is_link($ruta)) {
            throw new DomainException('El respaldo solicitado no está disponible.');
        }
        return $ruta;
    }

    public function generar(): string
    {
        return $this->conBloqueo(fn () => $this->crearRespaldo());
    }

    public function restaurar(string $nombre): void
    {
        $this->conBloqueo(function () use ($nombre): void {
            $ruta = $this->archivo($nombre);
            if (dirname($ruta) !== $this->directorio) {
                throw new DomainException('El respaldo histórico requiere validación antes de restaurar.');
            }
            $this->verificar($ruta, $nombre);
            if (getenv('IMPE_BACKUP_LAB') !== 'YES' || DB_NAME !== 'impe_r16_fuente'
                || !preg_match('/\A127\.0\.0\.1:([0-9]+)\z/', DB_HOST, $host)) {
                throw new DomainException('Integridad verificada. La restauración solo está habilitada en una instalación aislada y autorizada.');
            }

            $db = new mysqli('127.0.0.1', DB_USER, DB_PASSWORD, DB_NAME, (int)$host[1]);
            try {
                $this->restaurarVersionAislada($db, $ruta);
            } finally {
                $db->close();
            }
        });
    }

    private function restaurarVersionAislada(mysqli $db, string $ruta): void
    {
        $eventos = $db->query('SELECT @@GLOBAL.event_scheduler')->fetch_row()[0];
        if (!in_array($eventos, ['OFF', 'DISABLED'], true)) {
            throw new DomainException('Desactiva el planificador de eventos en la instancia aislada antes de restaurar.');
        }

        $puntero = $this->directorio . '/restauracion-activa';
        $anterior = is_file($puntero) ? trim((string)file_get_contents($puntero)) : DB_NAME;
        if ($anterior !== DB_NAME && !preg_match('/\Aimpe_r16_lab_version_[a-f0-9]{16}\z/', $anterior)) {
            throw new DomainException('No se pudo identificar la versión aislada actualmente activa.');
        }

        $id = bin2hex(random_bytes(8));
        $previo = $this->directorio . '/copia-previa-' . $id . '.sql';
        $verificacion = 'impe_r16_lab_verificacion_' . $id;
        $nueva = 'impe_r16_lab_version_' . $id;
        $temporal = $puntero . '.' . $id;
        $creados = [];
        try {
            $this->exportar($anterior, $previo);
            $huella = hash_file('sha256', $previo);
            if (!$huella) {
                throw new RuntimeException('No se pudo calcular la huella de la copia previa.');
            }
            $meta = json_encode(['esquema' => $anterior, 'sha256' => $huella,
                'firma' => hash_hmac('sha256', "$anterior:$huella", $this->clave())], JSON_THROW_ON_ERROR);
            if (file_put_contents($previo . '.json', $meta) !== strlen($meta)
                || !chmod($previo . '.json', 0600)) {
                throw new RuntimeException('No se pudo conservar la copia previa.');
            }
            if (!hash_equals($huella, (string)hash_file('sha256', $previo))) {
                throw new RuntimeException('No se pudo verificar la copia previa.');
            }
            foreach ([$verificacion => $previo, $nueva => $ruta] as $esquema => $archivo) {
                $db->query("CREATE DATABASE `$esquema` CHARACTER SET utf8mb4");
                $creados[] = $esquema;
                $this->importar($archivo, $esquema);
                $db->select_db($esquema);
                if (!$db->query('SHOW FULL TABLES')->num_rows) {
                    throw new RuntimeException('La restauración no contiene tablas.');
                }
            }
            $db->query("DROP DATABASE `$verificacion`");
            array_shift($creados);

            // Publicar solo después de completar y comprobar todas las importaciones.
            if (file_put_contents($temporal, $nueva . "\n") !== strlen($nueva) + 1
                || !chmod($temporal, 0600) || !rename($temporal, $puntero)) {
                throw new RuntimeException('No se pudo seleccionar la versión restaurada.');
            }
        } catch (Throwable $error) {
            foreach ($creados as $esquema) {
                try {
                    $db->query("DROP DATABASE `$esquema`");
                } catch (Throwable $limpieza) {
                    error_log('[impe_respaldo] Quedó un esquema aislado para revisión: ' . $esquema);
                }
            }
            throw new DomainException('La restauración aislada no pudo completarse. Se conservó la versión anterior.', 0, $error);
        } finally {
            if (is_file($temporal)) unlink($temporal);
        }
    }

    private function importar(string $ruta, string $destino): void
    {
        $cliente = $this->ejecutable(['mariadb', 'mysql']);
        $entrada = fopen($ruta, 'rb');
        if ($entrada === false) {
            throw new RuntimeException('No se pudo leer el respaldo.');
        }
        try {
            $this->ejecutar($cliente, ['--binary-mode', '--local-infile=0',
                '--skip-reconnect', '--database=' . $destino], $entrada);
        } finally {
            fclose($entrada);
        }
    }

    /** Prueba de importación en una instancia local desechable, con un esquema vacío. */
    public function validarEnLaboratorio(string $nombre, string $destino): void
    {
        if (PHP_SAPI !== 'cli' || getenv('IMPE_BACKUP_LAB') !== 'YES' ||
            !preg_match('/\A127\.0\.0\.1:[0-9]+\z/', DB_HOST) ||
            !preg_match('/\Aimpe_r16_lab_[a-z0-9]+\z/', $destino) || $destino === DB_NAME) {
            throw new DomainException('Se requiere una instancia local desechable y un esquema de laboratorio.');
        }

        $this->conBloqueo(function () use ($nombre, $destino): void {
            $ruta = $this->archivo($nombre);
            if (dirname($ruta) !== $this->directorio) {
                throw new DomainException('El respaldo histórico carece de autenticación.');
            }
            $this->verificar($ruta, $nombre);
            $puerto = (int)substr(DB_HOST, strlen('127.0.0.1:'));
            $lab = new mysqli('127.0.0.1', DB_USER, DB_PASSWORD, $destino, $puerto);
            try {
                foreach (['TABLES'=>'TABLE_SCHEMA','ROUTINES'=>'ROUTINE_SCHEMA','EVENTS'=>'EVENT_SCHEMA'] as $tabla=>$campo) {
                    $consulta = $lab->prepare("SELECT 1 FROM information_schema.$tabla WHERE $campo=? LIMIT 1");
                    $consulta->bind_param('s', $destino);
                    $consulta->execute();
                    $ocupado = $consulta->get_result()->num_rows > 0;
                    $consulta->close();
                    if ($ocupado) throw new DomainException('El esquema de laboratorio debe estar vacío.');
                }
            } finally {
                $lab->close();
            }
            $this->importar($ruta, $destino);
        });
    }

    private function nombreValido(string $nombre): bool
    {
        return preg_match('/\Adb-backup-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}-[a-f0-9]{12}\.sql\z/', $nombre) === 1;
    }

    private function conBloqueo(callable $operacion): mixed
    {
        if ($this->directorio === null ||
            (PHP_OS_FAMILY !== 'Windows' && (fileperms($this->directorio) & 0077) !== 0)) {
            throw new DomainException('Configura un directorio privado de respaldos fuera del sitio web.');
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

    private function crearRespaldo(): string
    {
        $clave = $this->clave();
        require_once __DIR__ . '/../../config/db_connection.php';
        $db = (new Database())->connect(false);
        try {
            $resultado = $db->query("SELECT 1 FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'
                AND (ENGINE IS NULL OR ENGINE<>'InnoDB') LIMIT 1");
            if (!$resultado || $resultado->num_rows > 0) {
                throw new DomainException('Hay tablas sin una instantánea transaccional compatible. Se requiere una ventana de mantenimiento.');
            }
        } finally {
            $db->close();
        }
        $nombre = 'db-backup-' . date('Y-m-d_H-i-s') . '-' . bin2hex(random_bytes(6)) . '.sql';
        $destino = $this->directorio . '/' . $nombre;
        $temporal = $destino . '.part';
        try {
            $this->exportar(DB_NAME, $temporal);
            $bytes = filesize($temporal);
            $hash = hash_file('sha256', $temporal);
            if ($hash === false) throw new RuntimeException('No se pudo calcular la integridad.');
            $datos = json_encode(['version'=>1, 'base'=>DB_NAME, 'nombre'=>$nombre,
                'fecha'=>time(), 'bytes'=>$bytes, 'sha256'=>$hash], JSON_THROW_ON_ERROR);
            $sobre = json_encode(['datos'=>$datos, 'firma'=>hash_hmac('sha256', $datos, $clave)], JSON_THROW_ON_ERROR);
            if (file_put_contents($temporal.'.json', $sobre, LOCK_EX) !== strlen($sobre) ||
                !chmod($temporal.'.json', 0600) || !rename($temporal, $destino) ||
                !rename($temporal.'.json', $destino.'.json')) {
                throw new RuntimeException('No se pudo publicar el respaldo verificado.');
            }
            return $nombre;
        } finally {
            foreach ([$temporal, $temporal.'.json'] as $parcial) {
                if (is_file($parcial)) @unlink($parcial);
            }
        }
    }

    private function exportar(string $esquema, string $ruta): void
    {
        // Crear el archivo privado antes de que el proceso escriba datos.
        $archivo = fopen($ruta, 'xb');
        if ($archivo === false) {
            throw new RuntimeException('No se pudo preparar el archivo privado.');
        }
        fclose($archivo);
        if (!chmod($ruta, 0600)) {
            throw new RuntimeException('No se pudo proteger el archivo privado.');
        }
        $dump = $this->ejecutable(['mariadb-dump', 'mysqldump']);
        $this->ejecutar($dump, [
            '--single-transaction', '--quick', '--hex-blob', '--no-tablespaces',
            '--routines', '--events', '--triggers', '--add-drop-table', '--', $esquema,
        ], null, $ruta);
        clearstatcache(true, $ruta);
        $bytes = filesize($ruta);
        $cola = $bytes ? file_get_contents($ruta, false, null, max(0, $bytes - 256)) : false;
        if (!$cola || !preg_match('/^-- Dump completed on .+$/m', $cola)) {
            throw new DomainException('La exportación está incompleta.');
        }
    }

    private function ejecutable(array $nombres): string
    {
        $configurado = $this->ajuste('IMPE_MYSQL_BIN_DIR', 'directorio_herramientas');
        $directorios = $configurado !== ''
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
        $avisos = false;
        while (!feof($tuberias[2])) {
            if (trim((string)fread($tuberias[2], 8192)) !== '') $avisos = true;
        }
        fclose($tuberias[2]);
        $codigo = proc_close($proceso);
        if ($codigo !== 0 || $avisos) {
            error_log('[impe_respaldo] ' . basename($programa) . ' terminó con código ' . $codigo);
            throw new RuntimeException('La herramienta de base de datos no pudo completar la operación.');
        }
    }
}
