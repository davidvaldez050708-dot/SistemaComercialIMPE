<?php
require_once __DIR__ . '/PermissionHelper.php';

/** Autorización interna. Los enlaces públicos no dependen de una sesión del CRM. */
final class FormularioPermisos
{
    public const VER = 'formularios.ver';
    public const REGISTRO = 'formularios.registro.capturar';
    public const INSCRIPCION = 'formularios.inscripcion.capturar';
    public const COMPARTIR = 'formularios.compartir';
    public const COMPROBANTES = 'formularios.comprobantes.ver';

    public static function catalogo(): array
    {
        return [
            ['modulo'=>'Formularios','codigo'=>self::VER,'nombre'=>'Ver formularios','descripcion'=>'Consultar el módulo y revisar los formularios de Registro e Inscripción.'],
            ['modulo'=>'Formularios','codigo'=>self::REGISTRO,'nombre'=>'Capturar registros','descripcion'=>'Guardar personas desde el formulario interno de Registro. Requiere ver formularios.'],
            ['modulo'=>'Formularios','codigo'=>self::INSCRIPCION,'nombre'=>'Capturar inscripciones','descripcion'=>'Guardar inscripciones y recibir comprobantes desde la captura interna. Requiere ver formularios.'],
            ['modulo'=>'Formularios','codigo'=>self::COMPARTIR,'nombre'=>'Compartir enlaces de formularios','descripcion'=>'Generar, copiar y abrir los enlaces públicos de Registro e Inscripción desde el módulo. Requiere ver formularios.'],
            ['modulo'=>'Formularios','codigo'=>self::COMPROBANTES,'nombre'=>'Consultar comprobantes de inscripción','descripcion'=>'Descargar comprobantes de pago recibidos. Requiere ver formularios y no autoriza validar pagos.']
        ];
    }

    public static function puede(string $codigo): bool
    {
        return tienePermiso(self::VER) && ($codigo === self::VER || tienePermiso($codigo));
    }

    public static function exigir(string $codigo, bool $json = false): void
    {
        if (self::puede($codigo)) return;
        http_response_code(403);
        $mensaje = 'Tu perfil no tiene permiso para realizar esta operación en Formularios.';
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'mensaje'=>$mensaje],JSON_UNESCAPED_UNICODE); exit;
        }
        $tituloPagina = 'Acceso restringido';
        $subtituloPagina = 'Formularios';
        $opcionActiva = 'formularios';
        require __DIR__ . '/../views/layout/dashboard_head.php';
        require __DIR__ . '/../views/layout/sidebar.php';
        require __DIR__ . '/../views/layout/topbar.php';
        echo '<section class="dashboard-panel" role="alert" style="margin:24px;padding:24px">'
            .'<h2 style="font-size:18px">Acceso restringido</h2>'
            .'<p style="font-size:13px;color:#637189">'.$mensaje.' Solicita el acceso al Administrador.</p></section>';
        require __DIR__ . '/../views/layout/dashboard_footer.php';
        exit;
    }
}
