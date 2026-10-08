<?php

require_once __DIR__ . '/../../config/db_connection.php';

/**
 * Agenda personal de teléfonos: no crea instituciones ni seguimientos.
 * Todas las operaciones se limitan al usuario autenticado.
 */
class TelefoniaContactosService
{
    private $connection;

    public function __construct()
    {
        $this->connection = (new Database())->connect();
        $this->asegurarEstructura();
    }

    public static function tokenFormulario(): string
    {
        if (
            !isset($_SESSION['telefonia_contactos_csrf']) ||
            !is_string($_SESSION['telefonia_contactos_csrf']) ||
            !preg_match('/^[0-9a-f]{64}$/', $_SESSION['telefonia_contactos_csrf'])
        ) {
            $_SESSION['telefonia_contactos_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['telefonia_contactos_csrf'];
    }

    public static function validarToken(string $token): bool
    {
        return $token !== '' &&
            hash_equals(self::tokenFormulario(), $token);
    }

    private function asegurarEstructura(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS telefonia_contactos_personales (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    usuario_id INT NOT NULL,
                    nombre VARCHAR(90) NOT NULL,
                    telefono VARCHAR(18) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_telefono_contacto_usuario (usuario_id, telefono),
                    KEY idx_telefono_contactos_usuario (usuario_id, nombre)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        if (!$this->connection->query($sql)) {
            throw new RuntimeException('No fue posible preparar los teléfonos guardados.');
        }
    }

    public static function normalizarTelefono(string $entrada): string
    {
        $entrada = trim($entrada);
        if (
            $entrada === '' ||
            !preg_match('/^\\+?[0-9 ()\\.\\-]+$/', $entrada)
        ) {
            throw new InvalidArgumentException('Escribe un teléfono con dígitos y, opcionalmente, prefijo internacional.');
        }

        $digitos = preg_replace('/\\D+/', '', $entrada) ?? '';
        if (strlen($digitos) === 10) {
            $digitos = '52' . $digitos;
        }

        if (!preg_match('/^[1-9][0-9]{10,14}$/', $digitos)) {
            throw new InvalidArgumentException('Usa diez dígitos de México o un teléfono internacional válido.');
        }

        return '+' . $digitos;
    }

    public function listar(int $usuarioId): array
    {
        if ($usuarioId <= 0) {
            return [];
        }

        $sql = "SELECT id, nombre, telefono
                FROM telefonia_contactos_personales
                WHERE usuario_id = ?
                ORDER BY nombre ASC, id DESC
                LIMIT 150";
        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No fue posible consultar los teléfonos guardados.');
        }
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $result = $stmt->get_result();
        $contactos = [];

        while ($fila = $result->fetch_assoc()) {
            $contactos[] = [
                'id' => (int)$fila['id'],
                'nombre' => (string)$fila['nombre'],
                'telefono' => (string)$fila['telefono']
            ];
        }
        $stmt->close();

        return $contactos;
    }

    public function guardar(int $usuarioId, string $nombre, string $telefono): array
    {
        if ($usuarioId <= 0) {
            throw new InvalidArgumentException('Sesión no válida.');
        }

        $nombre = trim(preg_replace('/\\s+/u', ' ', $nombre) ?? $nombre);
        if (
            $nombre === '' ||
            mb_strlen($nombre, 'UTF-8') > 90 ||
            mb_strlen($nombre, 'UTF-8') < 2
        ) {
            throw new InvalidArgumentException('Escribe un nombre de entre 2 y 90 caracteres.');
        }

        $telefono = self::normalizarTelefono($telefono);

        // Si ya existe, permite actualizar el nombre sin consumir otro cupo.
        $stmtExistente = $this->connection->prepare(
            'SELECT id FROM telefonia_contactos_personales WHERE usuario_id = ? AND telefono = ? LIMIT 1'
        );
        $stmtExistente->bind_param('is', $usuarioId, $telefono);
        $stmtExistente->execute();
        $existente = $stmtExistente->get_result()->fetch_assoc();
        $stmtExistente->close();

        if (!$existente) {
            $stmtConteo = $this->connection->prepare(
                'SELECT COUNT(*) AS total FROM telefonia_contactos_personales WHERE usuario_id = ?'
            );
            $stmtConteo->bind_param('i', $usuarioId);
            $stmtConteo->execute();
            $conteo = $stmtConteo->get_result()->fetch_assoc();
            $stmtConteo->close();

            if ((int)($conteo['total'] ?? 0) >= 150) {
                throw new InvalidArgumentException('Llegaste al máximo de 150 teléfonos personales.');
            }
        }

        $stmt = $this->connection->prepare(
            "INSERT INTO telefonia_contactos_personales (usuario_id, nombre, telefono)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->bind_param('iss', $usuarioId, $nombre, $telefono);
        $stmt->execute();
        $stmt->close();

        $consulta = $this->connection->prepare(
            'SELECT id, nombre, telefono FROM telefonia_contactos_personales WHERE usuario_id = ? AND telefono = ? LIMIT 1'
        );
        $consulta->bind_param('is', $usuarioId, $telefono);
        $consulta->execute();
        $contacto = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$contacto) {
            throw new RuntimeException('No fue posible recuperar el teléfono guardado.');
        }

        return [
            'id' => (int)$contacto['id'],
            'nombre' => (string)$contacto['nombre'],
            'telefono' => (string)$contacto['telefono']
        ];
    }

    public function eliminar(int $usuarioId, int $contactoId): bool
    {
        if ($usuarioId <= 0 || $contactoId <= 0) {
            throw new InvalidArgumentException('Teléfono no válido.');
        }

        $stmt = $this->connection->prepare(
            'DELETE FROM telefonia_contactos_personales WHERE id = ? AND usuario_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $contactoId, $usuarioId);
        $stmt->execute();
        $eliminado = $stmt->affected_rows > 0;
        $stmt->close();
        return $eliminado;
    }
}
