<?php

require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../services/CorreoConexionOAuthService.php';

final class CorreoConexionController
{
    public function __construct()
    {
        header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
        if (!in_array($_GET['action'] ?? 'index', ['index', 'iniciar', 'callback', 'desconectar'], true)) { http_response_code(404); exit; }
        if (!self::puedeAcceder()) { http_response_code(403); exit('No tienes permiso para conectar cuentas de envío.'); }
    }

    public static function puedeAcceder(): bool
    {
        return (int)($_SESSION['usuario_id'] ?? 0) > 0 && (int)($_SESSION['rol_id'] ?? 0) !== 1 &&
            (strcasecmp((string)($_SESSION['rol'] ?? ''), 'Marketing') === 0 || tieneAlgunPermiso([
                'seguimientos_vinculacion.operar_propios', 'oficios.enviar', 'reuniones.solicitar', 'reuniones.gestionar',
                'aliados.compartir_correo', 'difusion.enviar']));
    }

    public function index(): void
    {
        $email = strtolower(trim((string)($_GET['correo'] ?? '')));
        $service = new CorreoConexionOAuthService();
        $providers = $service->disponibles(); $connected = false;
        $notice = (string)($_SESSION['mail_connection_flash'] ?? ''); unset($_SESSION['mail_connection_flash']);
        try { if (filter_var($email, FILTER_VALIDATE_EMAIL)) { $connected = $service->obtener((int)$_SESSION['usuario_id'], $email) !== null; } }
        catch (Throwable $e) { $notice = 'No se pudo consultar esta conexión. Revisa la configuración privada con el administrador.'; }
        $tituloPagina = 'Conectar cuenta de correo'; $subtituloPagina = 'Autoriza tus cuentas de envío'; $opcionActiva = '';
        require __DIR__ . '/../views/layout/dashboard_head.php';
        require __DIR__ . '/../views/layout/sidebar.php';
        require __DIR__ . '/../views/layout/topbar.php';
        require __DIR__ . '/../views/correos/conexion.php';
        require __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function iniciar(): void
    {
        $this->post();
        $email = strtolower(trim((string)($_POST['correo'] ?? '')));
        try {
            $url = (new CorreoConexionOAuthService())->iniciar((int)$_SESSION['usuario_id'], $email, (string)($_POST['proveedor'] ?? ''), $_SESSION);
            header('Location: ' . $url, true, 303); exit;
        } catch (Throwable $e) { $this->fallo($e); $this->volver($email); }
    }

    public function callback(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(405); exit('Método no permitido.'); }
        $email = '';
        try {
            $email = (new CorreoConexionOAuthService())->completar((int)$_SESSION['usuario_id'], $_GET, $_SESSION);
            $_SESSION['mail_connection_flash'] = 'Cuenta autorizada y autenticada con el proveedor. Vuelve a la redacción para enviar; todavía no se ha transmitido ningún mensaje.';
        } catch (Throwable $e) { $this->fallo($e); }
        $this->volver($email);
    }

    public function desconectar(): void
    {
        $this->post(); $email = strtolower(trim((string)($_POST['correo'] ?? '')));
        try {
            (new CorreoConexionOAuthService())->desconectar((int)$_SESSION['usuario_id'], $email, $_SESSION);
            $_SESSION['mail_connection_flash'] = 'Conexión OAuth retirada de tu usuario en el CRM. Puedes revocar también el permiso de la aplicación desde tu cuenta del proveedor. Las cuentas configuradas por el administrador se gestionan por separado.';
        } catch (Throwable $e) { $this->fallo($e); }
        $this->volver($email);
    }

    private function post(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Método no permitido.'); }
        $a = (string)($_SESSION['csrf_token'] ?? ''); $b = (string)($_POST['csrf_token'] ?? '');
        if ($a === '' || $b === '' || !hash_equals($a, $b)) { http_response_code(419); exit('La sesión de seguridad cambió. Recarga la página.'); }
    }

    private function fallo(Throwable $e): void
    {
        $code = preg_match('/^MAIL_OAUTH_[A-Z_]+$/D', $e->getMessage()) ? $e->getMessage() : 'MAIL_OAUTH_FAILURE';
        error_log('MAIL_OAUTH code=' . $code . ' exception=' . get_class($e));
        $messages = [
            'MAIL_OAUTH_CONFIG' => 'El administrador debe configurar la aplicación OAuth y su URL de retorno antes de conectar esta cuenta.',
            'MAIL_OAUTH_CONFIG_CHANGED' => 'La configuración de la aplicación cambió. Inicia de nuevo la autorización.',
            'MAIL_OAUTH_STATE' => 'La autorización venció o no pertenece a tu sesión. Iníciala de nuevo desde el CRM.',
            'MAIL_OAUTH_FORBIDDEN' => 'Esta cuenta tiene una restricción administrativa. Solicita su autorización para tu usuario.',
            'MAIL_OAUTH_MANAGED' => 'Esta cuenta ya tiene un transporte administrado. Su API, SMTP o credenciales se actualizan con el administrador.',
            'MAIL_OAUTH_DENIED' => 'No se concedió el permiso de envío. La cuenta no se ha conectado.',
            'MAIL_OAUTH_SCOPE' => 'Falta conceder el permiso de envío en el proveedor. La cuenta no se ha conectado.',
            'MAIL_OAUTH_RATE' => 'Se alcanzó el límite de intentos de conexión. Espera unos minutos antes de intentarlo otra vez.'
        ];
        $_SESSION['mail_connection_flash'] = $messages[$code] ?? 'No se pudo autenticar el buzón solicitado. Revisa los permisos del proveedor, la conexión SMTP y TLS de Hostinger con el administrador. La cuenta no se ha conectado.';
    }

    private function volver(string $email): void
    {
        $q = filter_var($email, FILTER_VALIDATE_EMAIL) ? '&correo=' . rawurlencode($email) : '';
        header('Location: index.php?controller=correoConexion&action=index' . $q, true, 303); exit;
    }
}
