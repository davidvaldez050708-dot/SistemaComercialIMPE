<?php
require_once __DIR__ . '/PermissionHelper.php';

/**
 * Acceso independiente de Prospectos y Formularios.
 * Inscripciones contiene CURP, datos personales y comprobantes: no equivale
 * a poder abrir el formulario o a consultar prospectos.
 */
final class InscritosPermisos
{
    public const VER = 'inscritos.ver';
    public const EXPORTAR = 'inscritos.exportar';

    public static function catalogo(): array
    {
        return [
            [
                'modulo' => 'Inscripciones',
                'codigo' => self::VER,
                'nombre' => 'Consultar inscripciones recibidas',
                'descripcion' => 'Ver la tabla de inscripciones y fichas individuales de los programas.'
            ],
            [
                'modulo' => 'Inscripciones',
                'codigo' => self::EXPORTAR,
                'nombre' => 'Exportar inscripciones a Excel',
                'descripcion' => 'Descargar los datos de las inscripciones recibidas según los filtros. Requiere consultar inscripciones.'
            ]
        ];
    }

    public static function puede(string $codigo): bool
    {
        return tienePermiso(self::VER)
            && ($codigo === self::VER || tienePermiso($codigo));
    }

    public static function exigir(string $codigo, bool $json = false): void
    {
        if (self::puede($codigo)) return;

        http_response_code(403);
        header('Cache-Control: no-store, private');
        $mensaje = 'Tu perfil no tiene permiso para consultar esta información de Inscripciones.';
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $tituloPagina = 'Acceso restringido';
        $subtituloPagina = 'Inscripciones';
        $opcionActiva = 'inscritos';
        require __DIR__ . '/../views/layout/dashboard_head.php';
        require __DIR__ . '/../views/layout/sidebar.php';
        require __DIR__ . '/../views/layout/topbar.php';
        echo '<section class="dashboard-panel" role="alert" style="margin:24px;padding:24px">'
            . '<h2 style="font-size:18px">Acceso restringido</h2>'
            . '<p style="font-size:13px;color:#637189">'
            . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8')
            . ' Solicita el permiso correspondiente en Roles y permisos.</p></section>';
        require __DIR__ . '/../views/layout/dashboard_footer.php';
        exit;
    }
}
