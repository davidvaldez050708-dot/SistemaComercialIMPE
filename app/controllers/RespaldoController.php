<?php

require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../services/RespaldoBaseDatos.php';

final class RespaldoController
{
    private const RUTA = 'index.php?controller=respaldo&action=index';

    public function index(): void
    {
        if (!puedeGestionarRespaldos()) {
            http_response_code(403);
            echo 'Solo el Administrador y el Director de Operaciones pueden gestionar los respaldos.';
            return;
        }

        $servicio = new RespaldoBaseDatos();
        $urlRespaldo = BASE_URL . self::RUTA;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (($_POST['operacion'] ?? '') === 'descargar') {
                if (!$this->csrfValido()) {
                    http_response_code(403);
                    echo 'No se pudo validar la solicitud de descarga.';
                    return;
                }
                $this->descargar($servicio, is_string($_POST['nombre'] ?? null) ? $_POST['nombre'] : '');
                return;
            }
            $this->procesar($servicio);
            header('Location: ' . $urlRespaldo, true, 303);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            header('Allow: GET, POST');
            return;
        }
        $historial = $servicio->historial();
        $aviso = $_SESSION['aviso_respaldo'] ?? [];
        unset($_SESSION['aviso_respaldo']);

        $tituloPagina = 'Gestión de respaldo de base de datos';
        $subtituloPagina = 'Genera y descarga respaldos, consulta el historial y restaura la información del sistema';
        $opcionActiva = 'respaldo';
        require __DIR__ . '/../views/layout/dashboard_head.php';
        require __DIR__ . '/../views/layout/sidebar.php';
        require __DIR__ . '/../views/layout/topbar.php';
        require __DIR__ . '/../views/respaldo/index.php';
        require __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    private function csrfValido(): bool
    {
        $token = $_POST['csrf_token'] ?? null;
        $sesion = $_SESSION['csrf_token'] ?? null;
        return is_string($token) && is_string($sesion) && $sesion !== '' && hash_equals($sesion, $token);
    }

    private function procesar(RespaldoBaseDatos $servicio): void
    {
        if (!$this->csrfValido()) {
            $_SESSION['aviso_respaldo'] = [
                'error' => true,
                'mensaje' => 'No se pudo validar la solicitud. Recarga la página e inténtalo de nuevo.',
            ];
            return;
        }

        $nombre = is_string($_POST['nombre'] ?? null) ? $_POST['nombre'] : '';
        try {
            switch ($_POST['operacion'] ?? '') {
                case 'generar':
                    $descarga = $servicio->generar();
                    $mensaje = 'El respaldo se generó correctamente.';
                    break;
                case 'restaurar':
                    if (!esAdministradorSistema() || ($_POST['confirmacion'] ?? '') !== 'RESTAURAR') {
                        throw new DomainException('La restauración requiere autorización del Administrador y confirmación.');
                    }
                    $servicio->restaurar($nombre);
                    $mensaje = 'El respaldo se restauró y verificó en el entorno aislado.';
                    break;
                default:
                    throw new DomainException('Selecciona una operación válida.');
            }
            $_SESSION['aviso_respaldo'] = ['mensaje' => $mensaje, 'descarga' => $descarga ?? null];
        } catch (DomainException $e) {
            $_SESSION['aviso_respaldo'] = ['error' => true, 'mensaje' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[impe_respaldo] ' . get_class($e));
            $_SESSION['aviso_respaldo'] = [
                'error' => true,
                'mensaje' => 'No se pudo completar la operación. Revisa la configuración y los permisos de la base de datos en el servidor.',
            ];
        }
    }

    private function descargar(RespaldoBaseDatos $servicio, string $nombre): void
    {
        try {
            $archivo = @fopen($servicio->archivo($nombre), 'rb');
            if (!$archivo) {
                throw new DomainException('El respaldo solicitado no está disponible.');
            }
        } catch (DomainException $e) {
            http_response_code(404);
            echo $e->getMessage();
            return;
        }

        session_write_close();
        header('Cache-Control: private, no-store');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . fstat($archivo)['size']);
        header('X-Content-Type-Options: nosniff');
        fpassthru($archivo);
        fclose($archivo);
    }
}
