<?php
declare(strict_types=1);

require_once __DIR__.'/../../config/db_connection.php';
require_once __DIR__.'/../models/UsuarioModel.php';
require_once __DIR__.'/../models/RolModel.php';
require_once __DIR__.'/PermissionHelper.php';

/** Contexto autenticado de una petición; no inicia catálogos ni crea tablas. */
final class TelefoniaPeticionContexto
{
    public static function actualizar(bool $persistirSesion=true): void
    {
        if (defined('IMPE_PERMISOS_REFRESCADOS')) {
            self::validarUsuarioCliente();
            if(!empty($_SESSION['requiere_cambio_password']))self::rechazar(403,'Cambia tu contraseña antes de utilizar telefonía.');
            return;
        }
        $uid=(int)($_SESSION['usuario_id']??0);
        if ($uid<1) self::rechazar(401,'Sesión no activa.');
        self::validarUsuarioCliente();
        // Las APIs usan una instantánea de la sesión. No necesitan escribir
        // nombres ni permisos en ella: la siguiente petición los relee.
        // Liberar antes de abrir MySQL evita también bloquear al usuario si
        // falla la conexión o la consulta de autorización tarda.
        if (!$persistirSesion) self::liberarSesion();
        try {
            $db=Database::compartida();
            $account=(new UsuarioModel($db))->buscarPorId($uid);
            if (!$account || (int)$account['estado']!==1 || (int)$account['rol_estado']!==1) {
                $_SESSION=[];
                if (session_status()===PHP_SESSION_ACTIVE) session_destroy();
                self::rechazar(403,'Tu cuenta o perfil de acceso ya no está activo.');
            }
            foreach (['nombre','apellidos','usuario','foto_perfil','rol_id','rol','requiere_cambio_password'] as $field) {
                $_SESSION[$field]=$account[$field]??'';
            }
            $_SESSION['permisos']=(new RolModel($db))->obtenerCodigosPermisosPorRol((int)$account['rol_id']);
            define('IMPE_PERMISOS_REFRESCADOS',true);
            if(!empty($_SESSION['requiere_cambio_password']))self::rechazar(403,'Cambia tu contraseña antes de utilizar telefonía.');
        } catch (Throwable $error) {
            error_log('[telefonia_contexto] '.get_class($error));
            self::rechazar(503,'No fue posible verificar tu acceso. Inténtalo nuevamente.');
        }
    }

    public static function liberarSesion(): void
    {
        // La copia de $_SESSION sigue disponible para permisos y CSRF de esta
        // petición. Ninguna espera de PBX, SQL o audio retiene su archivo.
        if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    }

    public static function escribirSesion(callable $write): void
    {
        $uid=(int)($_SESSION['usuario_id']??0);
        $csrf=(string)($_SESSION['csrf_token']??'');
        if(session_status()!==PHP_SESSION_ACTIVE && !session_start()) self::rechazar(503,'No fue posible actualizar la sesión.');
        if($uid<1 || $uid!==(int)($_SESSION['usuario_id']??0) ||
            $csrf==='' || !hash_equals($csrf,(string)($_SESSION['csrf_token']??''))) {
            self::rechazar(409,'La sesión cambió mientras se verificaba la operación.');
        }
        try {$write();}
        finally {self::liberarSesion();}
    }

    private static function validarUsuarioCliente(): void
    {
        $header=$_SERVER['HTTP_X_IMPE_PHONE_USER']??null;
        if ($header!==null && (!is_string($header) || !ctype_digit($header) ||
            (int)$header!==(int)($_SESSION['usuario_id']??0))) {
            self::rechazar(409,'La petición pertenece a otra sesión de usuario.');
        }
    }

    private static function rechazar(int $status,string $message): void
    {
        self::liberarSesion();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode(['ok'=>false,'mensaje'=>$message],JSON_UNESCAPED_UNICODE);
        exit;
    }
}
