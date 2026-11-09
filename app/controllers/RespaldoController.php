<?php

require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../services/RespaldoBaseDatos.php';

final class RespaldoController
{
    private const RUTA = 'index.php?controller=respaldo&action=index';

    public function index(): void
    {
        if (empty($_SESSION['usuario_id']) || !esAdministradorSistema()) {
            http_response_code(403);
            echo 'Solo el administrador puede gestionar los respaldos.';
            return;
        }

        $servicio = new RespaldoBaseDatos();
        $urlRespaldo = BASE_URL . self::RUTA;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->procesar($servicio);
            header('Location: ' . $urlRespaldo, true, 303);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            header('Allow: GET, POST');
            return;
        }
        if (isset($_GET['descargar'])) {
            $this->descargar($servicio, is_string($_GET['descargar']) ? $_GET['descargar'] : '');
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

    private function procesar(RespaldoBaseDatos $servicio): void
    {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            $_SESSION['aviso_respaldo'] = [
                'error' => true,
                'mensaje' => 'No se pudo validar la solicitud. Si el archivo es grande, verifica el límite de carga del servidor.',
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
                case 'eliminar':
                    $servicio->eliminar($nombre);
                    $mensaje = 'El respaldo se eliminó del historial.';
                    break;
                case 'restaurar':
                    $servicio->restaurar($nombre);
                    $mensaje = 'Se restauró la base de datos. La copia previa quedó en el historial.';
                    break;
                case 'subir':
                    $servicio->restaurar($this->archivoSubido(), true);
                    $mensaje = 'Se restauró la base de datos desde el archivo cargado. La copia previa quedó en el historial.';
                    break;
                default:
                    throw new DomainException('Selecciona una operación válida.');
            }
            $_SESSION['aviso_respaldo'] = ['mensaje' => $mensaje, 'descarga' => $descarga ?? null];
        } catch (DomainException $e) {
            $_SESSION['aviso_respaldo'] = ['error' => true, 'mensaje' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[impe_respaldo] ' . $e->getMessage());
            $_SESSION['aviso_respaldo'] = [
                'error' => true,
                'mensaje' => 'No se pudo completar la operación. Revisa la configuración y los permisos de la base de datos en el servidor.',
            ];
        }
    }

    private function archivoSubido(): string
    {
        $archivo = $_FILES['archivo_sql'] ?? [];
        if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new DomainException('Selecciona un archivo SQL dentro del límite de carga del servidor.');
        }
        if (!is_string($archivo['name']) || !is_string($archivo['tmp_name'])
            || strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)) !== 'sql'
            || !is_uploaded_file($archivo['tmp_name']) || $archivo['size'] <= 0) {
            throw new DomainException('El archivo debe tener extensión .sql y contener un respaldo válido.');
        }
        return $archivo['tmp_name'];
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
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . fstat($archivo)['size']);
        header('X-Content-Type-Options: nosniff');
        fpassthru($archivo);
        fclose($archivo);
    }
}
