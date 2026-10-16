<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../config/private_config.php';

class CuentaCorreoUsuarioService
{
    private $connection;
    private $clave;

    public function __construct()
    {
        $this->connection = (new Database())->connect();
        $this->clave = $this->cargarClave();
    }

    public function obtener($usuarioId, $incluirSecreto = false)
    {
        $usuarioId = (int)$usuarioId;
        if ($usuarioId <= 0 || !$this->tablaDisponible()) {
            return null;
        }

        $sql = "SELECT *
                FROM usuario_correo_config
                WHERE usuario_id = ?
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();

        if (!$fila) {
            return null;
        }

        $salida = [
            'id' => (int)$fila['id'],
            'usuario_id' => (int)$fila['usuario_id'],
            'proveedor' => (string)$fila['proveedor'],
            'correo' => (string)$fila['correo'],
            'smtp_host' => (string)$fila['smtp_host'],
            'smtp_port' => (int)$fila['smtp_port'],
            'smtp_encryption' => (string)$fila['smtp_encryption'],
            'smtp_username' => (string)$fila['smtp_username'],
            'activo' => (bool)((int)$fila['activo']),
            'tiene_password' => trim((string)$fila['smtp_password_cipher']) !== ''
        ];

        if ($incluirSecreto) {
            $salida['smtp_password'] = $this->descifrar(
                (string)$fila['smtp_password_cipher'],
                (string)$fila['smtp_password_iv'],
                (string)$fila['smtp_password_tag']
            );
        }

        return $salida;
    }

    public function guardar($usuarioId, array $datos)
    {
        $usuarioId = (int)$usuarioId;
        if ($usuarioId <= 0) {
            return $this->error('La sesión no está activa.', 401);
        }

        if (!$this->tablaDisponible()) {
            return $this->error(
                'Falta aplicar la migración de configuración de correo.',
                500
            );
        }

        if ($this->clave === '') {
            return $this->error(
                'Falta configurar la clave privada para proteger las credenciales de correo.',
                500
            );
        }

        $proveedor = strtoupper(trim((string)($datos['proveedor'] ?? 'OTRO')));
        $correo = strtolower(trim((string)($datos['correo'] ?? '')));
        $host = trim((string)($datos['smtp_host'] ?? ''));
        $port = (int)($datos['smtp_port'] ?? 587);
        $encryption = strtolower(trim((string)($datos['smtp_encryption'] ?? 'tls')));
        $username = trim((string)($datos['smtp_username'] ?? ''));
        $password = (string)($datos['smtp_password'] ?? '');

        if (!in_array($proveedor, ['GMAIL', 'MICROSOFT', 'HOSTINGER', 'OTRO'], true)) {
            return $this->error('El proveedor seleccionado no es válido.', 422);
        }
        if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Ingresa un correo válido.', 422);
        }
        if ($host === '' || $port <= 0 || $port > 65535) {
            return $this->error('La configuración del servidor SMTP no es válida.', 422);
        }
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            return $this->error('El tipo de seguridad SMTP no es válido.', 422);
        }
        if ($username === '') {
            $username = $correo;
        }

        $actual = $this->obtener($usuarioId, true);
        if ($password === '' && is_array($actual)) {
            $password = (string)($actual['smtp_password'] ?? '');
        }

        if ($password === '') {
            return $this->error(
                'Ingresa la contraseña o contraseña de aplicación de la cuenta.',
                422
            );
        }

        $cifrado = $this->cifrar($password);
        if (!($cifrado['ok'] ?? false)) {
            return $cifrado;
        }

        $activo = 1;

        $sql = "INSERT INTO usuario_correo_config (
                    usuario_id,
                    proveedor,
                    correo,
                    smtp_host,
                    smtp_port,
                    smtp_encryption,
                    smtp_username,
                    smtp_password_cipher,
                    smtp_password_iv,
                    smtp_password_tag,
                    activo,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    proveedor = VALUES(proveedor),
                    correo = VALUES(correo),
                    smtp_host = VALUES(smtp_host),
                    smtp_port = VALUES(smtp_port),
                    smtp_encryption = VALUES(smtp_encryption),
                    smtp_username = VALUES(smtp_username),
                    smtp_password_cipher = VALUES(smtp_password_cipher),
                    smtp_password_iv = VALUES(smtp_password_iv),
                    smtp_password_tag = VALUES(smtp_password_tag),
                    activo = VALUES(activo)";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            'isssisssssi',
            $usuarioId,
            $proveedor,
            $correo,
            $host,
            $port,
            $encryption,
            $username,
            $cifrado['cipher'],
            $cifrado['iv'],
            $cifrado['tag'],
            $activo
        );
        $stmt->execute();

        return [
            'ok' => true,
            'mensaje' => 'Cuenta de correo guardada correctamente.'
        ];
    }

    public function eliminar($usuarioId)
    {
        $usuarioId = (int)$usuarioId;
        if ($usuarioId <= 0 || !$this->tablaDisponible()) {
            return $this->error('No hay una cuenta de correo para eliminar.', 404);
        }

        $stmt = $this->connection->prepare(
            "DELETE FROM usuario_correo_config WHERE usuario_id = ?"
        );
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();

        return [
            'ok' => true,
            'mensaje' => 'Cuenta de correo eliminada.'
        ];
    }

    private function cargarClave()
    {
        $env = getenv('MAIL_ACCOUNT_ENCRYPTION_KEY');
        if ($env !== false && trim((string)$env) !== '') {
            return trim((string)$env);
        }

        $config = impeLeerConfigPrivada('mail_account.local.php');
        return trim((string)($config['encryption_key'] ?? ''));
    }

    private function cifrar($texto)
    {
        if (!function_exists('openssl_encrypt')) {
            return $this->error('OpenSSL no está disponible para proteger la credencial.', 500);
        }

        $key = hash('sha256', $this->clave, true);
        $iv = random_bytes(12);
        $tag = '';

        $cipher = openssl_encrypt(
            $texto,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($cipher === false) {
            return $this->error('No fue posible proteger la credencial.', 500);
        }

        return [
            'ok' => true,
            'cipher' => base64_encode($cipher),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag)
        ];
    }

    private function descifrar($cipher, $iv, $tag)
    {
        if ($this->clave === '' || $cipher === '' || $iv === '' || $tag === '') {
            return '';
        }

        $resultado = openssl_decrypt(
            base64_decode($cipher, true) ?: '',
            'aes-256-gcm',
            hash('sha256', $this->clave, true),
            OPENSSL_RAW_DATA,
            base64_decode($iv, true) ?: '',
            base64_decode($tag, true) ?: ''
        );

        return $resultado === false ? '' : (string)$resultado;
    }

    private function tablaDisponible()
    {
        $resultado = $this->connection->query(
            "SHOW TABLES LIKE 'usuario_correo_config'"
        );
        return $resultado && $resultado->num_rows > 0;
    }

    private function error($mensaje, $codigoHttp)
    {
        return [
            'ok' => false,
            'mensaje' => (string)$mensaje,
            'codigo_http' => (int)$codigoHttp
        ];
    }
}
