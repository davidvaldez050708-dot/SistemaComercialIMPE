<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../config/private_config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

class CuentaCorreoUsuarioService
{
    private $connection;
    private $clave;

    public function __construct()
    {
        $this->connection = (new Database())->connect();
        $this->clave = $this->cargarOGenerarClave();
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
                'No fue posible inicializar la protección de credenciales de correo.',
                500
            );
        }

        $config = $this->normalizarConfiguracion($usuarioId, $datos);
        if (!($config['ok'] ?? false)) {
            return $config;
        }

        $prueba = $this->probarConfiguracion($config['cuenta']);
        if (!($prueba['ok'] ?? false)) {
            return $prueba;
        }

        $cuenta = $config['cuenta'];
        $cifrado = $this->cifrar($cuenta['smtp_password']);
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
            $cuenta['proveedor'],
            $cuenta['correo'],
            $cuenta['smtp_host'],
            $cuenta['smtp_port'],
            $cuenta['smtp_encryption'],
            $cuenta['smtp_username'],
            $cifrado['cipher'],
            $cifrado['iv'],
            $cifrado['tag'],
            $activo
        );
        $stmt->execute();

        return [
            'ok' => true,
            'mensaje' => 'Cuenta verificada y guardada correctamente.',
            'proveedor' => $cuenta['proveedor']
        ];
    }

    public function probar($usuarioId, array $datos)
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

        $config = $this->normalizarConfiguracion($usuarioId, $datos);
        if (!($config['ok'] ?? false)) {
            return $config;
        }

        return $this->probarConfiguracion($config['cuenta']);
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

    private function normalizarConfiguracion($usuarioId, array $datos)
    {
        $proveedor = strtoupper(trim((string)($datos['proveedor'] ?? 'OTRO')));
        $correo = strtolower(trim((string)($datos['correo'] ?? '')));
        $host = trim((string)($datos['smtp_host'] ?? ''));
        $port = (int)($datos['smtp_port'] ?? 587);
        $encryption = strtolower(trim((string)($datos['smtp_encryption'] ?? 'tls')));
        $username = trim((string)($datos['smtp_username'] ?? ''));
        $password = (string)($datos['smtp_password'] ?? '');

        if (!in_array($proveedor, ['GMAIL', 'MICROSOFT', 'OUTLOOK', 'HOSTINGER', 'OTRO'], true)) {
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

        return [
            'ok' => true,
            'cuenta' => [
                'proveedor' => $proveedor,
                'correo' => $correo,
                'smtp_host' => $host,
                'smtp_port' => $port,
                'smtp_encryption' => $encryption,
                'smtp_username' => $username,
                'smtp_password' => $password
            ]
        ];
    }

    private function probarConfiguracion(array $cuenta)
    {
        $mail = new PHPMailer(true);

        try {
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = (string)$cuenta['smtp_host'];
            $mail->Port = (int)$cuenta['smtp_port'];
            $mail->SMTPAuth = true;
            $mail->Username = (string)$cuenta['smtp_username'];
            $mail->Password = (string)$cuenta['smtp_password'];
            $mail->Timeout = 15;
            $mail->Timelimit = 15;
            $mail->SMTPDebug = 0;

            $encryption = strtolower((string)$cuenta['smtp_encryption']);
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPAutoTLS = false;
                $mail->SMTPSecure = false;
            }

            if (!$mail->smtpConnect()) {
                return $this->error(
                    'No fue posible conectar con el servidor SMTP.',
                    422
                );
            }

            $mail->smtpClose();

            return [
                'ok' => true,
                'mensaje' => 'Conexión SMTP validada correctamente.'
            ];
        } catch (Throwable $error) {
            error_log(
                'Prueba SMTP usuario: ' .
                $error->getMessage()
            );

            return $this->error(
                $this->mensajeErrorSmtp(
                    (string)$cuenta['proveedor'],
                    $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $error->getMessage()
                ),
                422
            );
        }
    }

    private function mensajeErrorSmtp($proveedor, $detalle)
    {
        $texto = strtolower((string)$detalle);

        if (
            strpos($texto, 'authenticate') !== false ||
            strpos($texto, 'authentication') !== false ||
            strpos($texto, 'username and password') !== false ||
            strpos($texto, '535') !== false
        ) {
            if ($proveedor === 'GMAIL') {
                return 'Gmail rechazó las credenciales. Usa una contraseña de aplicación si tu cuenta tiene verificación en dos pasos.';
            }

            if ($proveedor === 'MICROSOFT' || $proveedor === 'OUTLOOK') {
                return 'Microsoft rechazó las credenciales SMTP. Verifica la contraseña y que SMTP AUTH esté habilitado para la cuenta.';
            }

            return 'El servidor rechazó el usuario o la contraseña SMTP.';
        }

        if (
            strpos($texto, 'connect') !== false ||
            strpos($texto, 'connection') !== false ||
            strpos($texto, 'timed out') !== false ||
            strpos($texto, 'getaddrinfo') !== false
        ) {
            return 'No fue posible establecer conexión con el servidor SMTP. Revisa servidor, puerto y seguridad.';
        }

        return 'El servidor SMTP rechazó la conexión. Revisa los datos de la cuenta y las políticas de seguridad del proveedor.';
    }

    private function cargarOGenerarClave()
    {
        $env = getenv('MAIL_ACCOUNT_ENCRYPTION_KEY');
        if ($env !== false && trim((string)$env) !== '') {
            return trim((string)$env);
        }

        $config = impeLeerConfigPrivada('mail_account.local.php');
        $existente = trim((string)($config['encryption_key'] ?? ''));
        if ($existente !== '') {
            return $existente;
        }

        try {
            $clave = bin2hex(random_bytes(32));
        } catch (Throwable $error) {
            error_log('Clave de correo: ' . $error->getMessage());
            return '';
        }

        $contenido = "<?php\n\nreturn [\n    'encryption_key' => '" .
            addslashes($clave) .
            "',\n];\n";

        foreach ($this->directoriosPrivadosEscritura() as $directorio) {
            if (!is_dir($directorio)) {
                if (!@mkdir($directorio, 0700, true) && !is_dir($directorio)) {
                    continue;
                }
            }

            $ruta = rtrim($directorio, '/\\') .
                DIRECTORY_SEPARATOR .
                'mail_account.local.php';

            if (@file_put_contents($ruta, $contenido, LOCK_EX) === false) {
                continue;
            }

            @chmod($ruta, 0600);
            return $clave;
        }

        return '';
    }

    private function directoriosPrivadosEscritura()
    {
        $directorios = [];
        $explicito = trim((string)(getenv('IMPE_PRIVATE_CONFIG_DIR') ?: ''));
        if ($explicito !== '') {
            $directorios[] = $explicito;
        }

        $root = dirname(__DIR__, 2);
        if (basename($root) === 'public_html') {
            $directorios[] = dirname($root) . DIRECTORY_SEPARATOR . 'impe-private';
        }

        $directorios[] = $root . DIRECTORY_SEPARATOR . 'config';

        return array_values(array_unique($directorios));
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
