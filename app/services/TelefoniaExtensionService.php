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

    public function asegurarEstructura()
    {
        $sql = "CREATE TABLE IF NOT EXISTS telefonia_extensiones (
            id INT NOT NULL AUTO_INCREMENT,
            usuario_id INT NOT NULL,
            proveedor VARCHAR(20) NOT NULL DEFAULT 'ZADARMA',
            extension VARCHAR(12) NOT NULL,
            caller_id VARCHAR(40) NULL,
            permite_salientes TINYINT(1) NOT NULL DEFAULT 1,
            permite_entrantes TINYINT(1) NOT NULL DEFAULT 1,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            creado_por INT NULL,
            actualizado_por INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_telefonia_usuario_proveedor (
                usuario_id,
                proveedor
            ),
            UNIQUE KEY uk_telefonia_extension_proveedor (
                proveedor,
                extension
            ),
            KEY idx_telefonia_extension_activa (
                proveedor,
                activo,
                extension
            ),
            CONSTRAINT fk_telefonia_extension_usuario
                FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_telefonia_extension_creado_por
                FOREIGN KEY (creado_por) REFERENCES usuarios(id)
                ON UPDATE CASCADE ON DELETE SET NULL,
            CONSTRAINT fk_telefonia_extension_actualizado_por
                FOREIGN KEY (actualizado_por) REFERENCES usuarios(id)
                ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_general_ci";

        if (!$this->connection->query($sql)) {
            throw new RuntimeException(
                'No fue posible preparar la configuración de extensiones.'
            );
        }

        return true;
    }

    public function listarUsuariosConfigurables()
    {
        $this->asegurarEstructura();

        $sql = "SELECT
                    u.id,
                    u.nombre,
                    u.apellidos,
                    u.foto_perfil,
                    u.correo,
                    u.estado,
                    u.rol_id,
                    r.nombre AS rol,
                    te.id AS telefonia_id,
                    te.extension,
                    te.caller_id,
                    te.permite_salientes,
                    te.permite_entrantes,
                    te.activo AS telefonia_activa,
                    te.updated_at AS telefonia_actualizada_at
                FROM usuarios u
                INNER JOIN roles r
                    ON r.id = u.rol_id
                LEFT JOIN telefonia_extensiones te
                    ON te.usuario_id = u.id
                   AND te.proveedor = 'ZADARMA'
                WHERE r.nombre IN (
                    'Analista de Datos',
                    'Asesor de Ventas'
                )
                ORDER BY
                    CASE
                        WHEN r.nombre = 'Analista de Datos' THEN 1
                        WHEN r.nombre = 'Asesor de Ventas' THEN 2
                        ELSE 9
                    END,
                    u.estado DESC,
                    u.nombre,
                    u.apellidos";

        $resultado = $this->connection->query($sql);

        if (!$resultado) {
            throw new RuntimeException(
                'No fue posible consultar los usuarios de telefonía.'
            );
        }

        $usuarios = [];

        while ($fila = $resultado->fetch_assoc()) {
            $fila['id'] = (int)$fila['id'];
            $fila['estado'] = (int)$fila['estado'];
            $fila['rol_id'] = (int)$fila['rol_id'];
            $fila['telefonia_id'] = isset($fila['telefonia_id'])
                ? (int)$fila['telefonia_id']
                : 0;
            $fila['permite_salientes'] =
                (int)($fila['permite_salientes'] ?? 0);
            $fila['permite_entrantes'] =
                (int)($fila['permite_entrantes'] ?? 0);
            $fila['telefonia_activa'] =
                (int)($fila['telefonia_activa'] ?? 0);
            $usuarios[] = $fila;
        }

        return $usuarios;
    }

    public function resumenConfiguracion(array $usuarios)
    {
        $resumen = [
            'usuarios' => count($usuarios),
            'configurados' => 0,
            'activos' => 0,
            'pendientes' => 0,
        ];

        foreach ($usuarios as $usuario) {
            if ((int)($usuario['telefonia_id'] ?? 0) <= 0) {
                $resumen['pendientes']++;
                continue;
            }

            $resumen['configurados']++;

            if (
                (int)($usuario['telefonia_activa'] ?? 0) === 1 &&
                (int)($usuario['estado'] ?? 0) === 1
            ) {
                $resumen['activos']++;
            }
        }

        return $resumen;
    }

    public function guardarAsignacion(
        $usuarioId,
        $extension,
        $callerId,
        $permiteSalientes,
        $permiteEntrantes,
        $activo,
        $actorUsuarioId
    ) {
        $this->asegurarEstructura();

        $usuarioId = (int)$usuarioId;
        $actorUsuarioId = (int)$actorUsuarioId;
        $extension = trim((string)$extension);
        $callerId = $this->normalizarCallerId($callerId);
        $permiteSalientes = $permiteSalientes ? 1 : 0;
        $permiteEntrantes = $permiteEntrantes ? 1 : 0;
        $activo = $activo ? 1 : 0;

        if (!preg_match('/^\d{3,6}$/', $extension)) {
            throw new InvalidArgumentException(
                'La extensión debe contener entre 3 y 6 dígitos.'
            );
        }

        $usuario = $this->obtenerUsuarioConfigurable($usuarioId);

        if (!$usuario) {
            throw new InvalidArgumentException(
                'El usuario seleccionado no puede utilizar la configuración de telefonía.'
            );
        }

        if ($activo === 1 && (int)$usuario['estado'] !== 1) {
            throw new InvalidArgumentException(
                'No puedes activar telefonía para un usuario inactivo.'
            );
        }

        if (
            $activo === 1 &&
            $permiteSalientes === 0 &&
            $permiteEntrantes === 0
        ) {
            throw new InvalidArgumentException(
                'Una extensión activa debe permitir llamadas salientes, entrantes o ambas.'
            );
        }

        $this->connection->begin_transaction();

        try {
            $sqlDuplicada = "SELECT
                    te.usuario_id,
                    u.nombre,
                    u.apellidos
                FROM telefonia_extensiones te
                INNER JOIN usuarios u
                    ON u.id = te.usuario_id
                WHERE te.proveedor = 'ZADARMA'
                  AND te.extension = ?
                  AND te.usuario_id <> ?
                LIMIT 1";

            $stmtDuplicada = $this->connection->prepare($sqlDuplicada);
            $stmtDuplicada->bind_param(
                'si',
                $extension,
                $usuarioId
            );
            $stmtDuplicada->execute();
            $duplicada = $stmtDuplicada->get_result()->fetch_assoc();

            if ($duplicada) {
                $nombreDuplicado = trim(
                    (string)($duplicada['nombre'] ?? '') . ' ' .
                    (string)($duplicada['apellidos'] ?? '')
                );

                throw new InvalidArgumentException(
                    'La extensión ' . $extension .
                    ' ya está asignada a ' .
                    ($nombreDuplicado !== ''
                        ? $nombreDuplicado
                        : 'otro usuario') .
                    '. Libérala antes de reutilizarla.'
                );
            }

            $sqlActual = "SELECT id
                FROM telefonia_extensiones
                WHERE usuario_id = ?
                  AND proveedor = 'ZADARMA'
                LIMIT 1
                FOR UPDATE";

            $stmtActual = $this->connection->prepare($sqlActual);
            $stmtActual->bind_param('i', $usuarioId);
            $stmtActual->execute();
            $actual = $stmtActual->get_result()->fetch_assoc();

            if ($actual) {
                $sqlGuardar = "UPDATE telefonia_extensiones
                    SET extension = ?,
                        caller_id = NULLIF(?, ''),
                        permite_salientes = ?,
                        permite_entrantes = ?,
                        activo = ?,
                        actualizado_por = NULLIF(?, 0),
                        updated_at = NOW()
                    WHERE id = ?";

                $stmtGuardar = $this->connection->prepare(
                    $sqlGuardar
                );
                $configId = (int)$actual['id'];
                $stmtGuardar->bind_param(
                    'ssiiiii',
                    $extension,
                    $callerId,
                    $permiteSalientes,
                    $permiteEntrantes,
                    $activo,
                    $actorUsuarioId,
                    $configId
                );
            } else {
                $sqlGuardar = "INSERT INTO telefonia_extensiones (
                        usuario_id,
                        proveedor,
                        extension,
                        caller_id,
                        permite_salientes,
                        permite_entrantes,
                        activo,
                        creado_por,
                        actualizado_por
                    ) VALUES (
                        ?,
                        'ZADARMA',
                        ?,
                        NULLIF(?, ''),
                        ?,
                        ?,
                        ?,
                        NULLIF(?, 0),
                        NULLIF(?, 0)
                    )";

                $stmtGuardar = $this->connection->prepare(
                    $sqlGuardar
                );
                $stmtGuardar->bind_param(
                    'issiiiii',
                    $usuarioId,
                    $extension,
                    $callerId,
                    $permiteSalientes,
                    $permiteEntrantes,
                    $activo,
                    $actorUsuarioId,
                    $actorUsuarioId
                );
            }

            if (!$stmtGuardar->execute()) {
                throw new RuntimeException(
                    'No fue posible guardar la extensión.'
                );
            }

            $this->connection->commit();
            return true;
        } catch (Throwable $e) {
            $this->connection->rollback();
            throw $e;
        }
    }

    public function liberarAsignacion(
        $usuarioId,
        $actorUsuarioId = 0
    ) {
        $this->asegurarEstructura();

        $usuarioId = (int)$usuarioId;

        if (!$this->obtenerUsuarioConfigurable($usuarioId)) {
            throw new InvalidArgumentException(
                'El usuario indicado no es válido para telefonía.'
            );
        }

        $sql = "DELETE FROM telefonia_extensiones
                WHERE usuario_id = ?
                  AND proveedor = 'ZADARMA'";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $usuarioId);

        if (!$stmt->execute()) {
            throw new RuntimeException(
                'No fue posible liberar la extensión.'
            );
        }

        return true;
    }

    private function obtenerUsuarioConfigurable($usuarioId)
    {
        $sql = "SELECT
                    u.id,
                    u.estado,
                    r.nombre AS rol
                FROM usuarios u
                INNER JOIN roles r
                    ON r.id = u.rol_id
                WHERE u.id = ?
                  AND r.nombre IN (
                    'Analista de Datos',
                    'Asesor de Ventas'
                  )
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function normalizarCallerId($callerId)
    {
        $callerId = trim((string)$callerId);

        if ($callerId === '') {
            return '';
        }

        $digitos = preg_replace('/\D+/', '', $callerId) ?: '';

        if (
            strpos($callerId, '+') === 0 &&
            strlen($digitos) >= 8 &&
            strlen($digitos) <= 15
        ) {
            return '+' . $digitos;
        }

        if (strlen($digitos) === 10) {
            return '+52' . $digitos;
        }

        if (
            strlen($digitos) === 12 &&
            strpos($digitos, '52') === 0
        ) {
            return '+' . $digitos;
        }

        throw new InvalidArgumentException(
            'El Caller ID debe ser un número válido, por ejemplo +527771234567.'
        );
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
