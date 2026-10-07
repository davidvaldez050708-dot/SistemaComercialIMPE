<?php

require_once __DIR__ . '/../../config/db_connection.php';

class TelefoniaExtensionService
{
    private $connection;
    private $rootPath;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
        $this->rootPath = dirname(__DIR__, 2);
    }

    public function resolverParaUsuario($usuarioId)
    {
        $usuarioId = (int)$usuarioId;

        if ($usuarioId <= 0) {
            return null;
        }

        $asignacion = $this->buscarAsignacionActiva($usuarioId);

        if ($asignacion !== null) {
            return $asignacion;
        }

        /*
         * Compatibilidad temporal con la prueba técnica existente:
         * mientras no haya ninguna asignación activa en la tabla nueva,
         * se permite usar pbx_extension de zadarma_config.php.
         *
         * En cuanto exista la primera asignación real, todo usuario deberá
         * contar con su propia extensión y este fallback deja de aplicar.
         */
        if ($this->hayAsignacionesActivas()) {
            return null;
        }

        $config = $this->cargarConfig();
        $extension = trim((string)($config['pbx_extension'] ?? ''));

        if (!preg_match('/^\d{3,6}$/', $extension)) {
            return null;
        }

        return [
            'usuario_id' => $usuarioId,
            'proveedor' => 'ZADARMA',
            'extension' => $extension,
            'caller_id' => trim((string)($config['caller_id'] ?? '')),
            'permite_salientes' => true,
            'permite_entrantes' => true,
            'origen' => 'LEGACY_CONFIG',
        ];
    }

    public function obtenerPorExtension($extension)
    {
        $extension = trim((string)$extension);

        if (
            $extension === '' ||
            !$this->estructuraDisponible()
        ) {
            return null;
        }

        $sql = "SELECT
                    te.usuario_id,
                    te.proveedor,
                    te.extension,
                    te.caller_id,
                    te.permite_salientes,
                    te.permite_entrantes,
                    te.activo,
                    u.nombre,
                    u.apellidos,
                    u.rol_id,
                    r.nombre AS rol
                FROM telefonia_extensiones te
                INNER JOIN usuarios u
                    ON u.id = te.usuario_id
                INNER JOIN roles r
                    ON r.id = u.rol_id
                WHERE te.proveedor = 'ZADARMA'
                  AND te.extension = ?
                  AND te.activo = 1
                  AND u.estado = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('s', $extension);
        $stmt->execute();

        $fila = $stmt->get_result()->fetch_assoc();

        return $fila ?: null;
    }

    private function buscarAsignacionActiva($usuarioId)
    {
        if (!$this->estructuraDisponible()) {
            return null;
        }

        $sql = "SELECT
                    te.usuario_id,
                    te.proveedor,
                    te.extension,
                    te.caller_id,
                    te.permite_salientes,
                    te.permite_entrantes
                FROM telefonia_extensiones te
                INNER JOIN usuarios u
                    ON u.id = te.usuario_id
                WHERE te.usuario_id = ?
                  AND te.proveedor = 'ZADARMA'
                  AND te.activo = 1
                  AND u.estado = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();

        $fila = $stmt->get_result()->fetch_assoc();

        if (!$fila) {
            return null;
        }

        $extension = trim((string)($fila['extension'] ?? ''));

        if (!preg_match('/^\d{3,6}$/', $extension)) {
            return null;
        }

        return [
            'usuario_id' => (int)$fila['usuario_id'],
            'proveedor' => 'ZADARMA',
            'extension' => $extension,
            'caller_id' => trim((string)($fila['caller_id'] ?? '')),
            'permite_salientes' =>
                (int)($fila['permite_salientes'] ?? 0) === 1,
            'permite_entrantes' =>
                (int)($fila['permite_entrantes'] ?? 0) === 1,
            'origen' => 'USUARIO',
        ];
    }

    private function hayAsignacionesActivas()
    {
        if (!$this->estructuraDisponible()) {
            return false;
        }

        $resultado = $this->connection->query(
            "SELECT COUNT(*) AS total
             FROM telefonia_extensiones
             WHERE proveedor = 'ZADARMA'
               AND activo = 1"
        );

        $fila = $resultado ? $resultado->fetch_assoc() : null;

        return (int)($fila['total'] ?? 0) > 0;
    }

    private function estructuraDisponible()
    {
        try {
            $resultado = $this->connection->query(
                "SHOW TABLES LIKE 'telefonia_extensiones'"
            );

            return $resultado && $resultado->num_rows > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function cargarConfig()
    {
        $ruta = $this->rootPath . '/config/zadarma_config.php';

        if (!is_file($ruta)) {
            return [];
        }

        $config = require $ruta;

        return is_array($config) ? $config : [];
    }
}
