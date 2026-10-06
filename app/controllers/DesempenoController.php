<?php

require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../services/DesempenoService.php';
require_once __DIR__ . '/../services/DesempenoPdfService.php';

class DesempenoController
{
    public function index()
    {
        $this->validarAcceso();

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolNombre = (string)($_SESSION['rol'] ?? '');

        $puedeGlobal = tienePermiso('desempeno.ver_global');
        $puedeEquipo = tienePermiso('desempeno.ver_equipo');
        $puedePropio = tienePermiso('desempeno.ver_propio');

        $filtros = $this->obtenerFiltros();
        $servicio = new DesempenoService();

        $desempeno = $servicio->preparar(
            $usuarioId,
            $rolNombre,
            $puedeGlobal,
            $puedeEquipo,
            $puedePropio,
            $filtros
        );

        $urlExportarPdf = '';
        if (tienePermiso('desempeno.exportar')) {
            $parametros = [
                'controller' => 'desempeno',
                'action' => 'exportarPdf',
                'vista' => $desempeno['vista'] ?? '',
                'area' => $desempeno['area'] ?? '',
                'periodo' => $desempeno['periodo']['clave'] ?? 'semana',
                'fecha_desde' => $desempeno['periodo']['fecha_desde'] ?? '',
                'fecha_hasta' => $desempeno['periodo']['fecha_hasta'] ?? '',
                'estado_id' => (int)($desempeno['estado_id'] ?? 0),
                'persona_id' => (int)($desempeno['persona_id'] ?? 0)
            ];

            $urlExportarPdf =
                BASE_URL .
                'index.php?' .
                http_build_query(
                    $parametros,
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );
        }

        $errorDesempeno = (string)(
            $_SESSION['error_desempeno_pdf'] ?? ''
        );
        unset($_SESSION['error_desempeno_pdf']);

        $tituloPagina = 'Desempeño';
        $subtituloPagina =
            'Desempeño, historial operativo y reconocimientos por periodo.';
        $opcionActiva = 'desempeno';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/desempeno/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function exportarPdf()
    {
        $this->validarAcceso(true);

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolNombre = (string)($_SESSION['rol'] ?? '');

        $servicio = new DesempenoService();
        $desempeno = $servicio->preparar(
            $usuarioId,
            $rolNombre,
            tienePermiso('desempeno.ver_global'),
            tienePermiso('desempeno.ver_equipo'),
            tienePermiso('desempeno.ver_propio'),
            $this->obtenerFiltros(),
            true
        );

        $desempeno['generado_por'] = trim(
            (string)($_SESSION['nombre'] ?? '') . ' ' .
            (string)($_SESSION['apellidos'] ?? '')
        );
        $desempeno['generado_por_rol'] =
            (string)($_SESSION['rol'] ?? '');
        $desempeno['fecha_generacion'] = date('d/m/Y H:i');

        try {
            $resultado = (new DesempenoPdfService())
                ->generar($desempeno);

            if (!($resultado['ok'] ?? false)) {
                throw new RuntimeException(
                    (string)(
                        $resultado['mensaje_tecnico']
                            ?? $resultado['mensaje']
                            ?? 'No fue posible generar el PDF.'
                    )
                );
            }

            $contenido = (string)(
                $resultado['contenido_pdf'] ?? ''
            );
            $nombre = (string)(
                $resultado['nombre_archivo']
                    ?? 'Desempeno_Corte_' . date('Y-m-d') . '.pdf'
            );

            header('Content-Type: application/pdf');
            header(
                'Content-Disposition: attachment; filename="' .
                $nombre .
                '"'
            );
            header('Content-Length: ' . strlen($contenido));
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');

            echo $contenido;
            exit;
        } catch (Throwable $error) {
            error_log(
                '[desempeno_pdf] ' . $error->getMessage()
            );

            $_SESSION['error_desempeno_pdf'] =
                'No fue posible generar el PDF de desempeño.';

            $parametros = $this->obtenerFiltros();
            $parametros['controller'] = 'desempeno';
            $parametros['action'] = 'index';

            header(
                'Location: ' .
                BASE_URL .
                'index.php?' .
                http_build_query(
                    $parametros,
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                )
            );
            exit;
        }
    }

    private function obtenerFiltros()
    {
        return [
            'vista' => strtolower(trim(
                (string)($_GET['vista'] ?? '')
            )),
            'area' => strtolower(trim(
                (string)($_GET['area'] ?? '')
            )),
            'periodo' => strtolower(trim(
                (string)($_GET['periodo'] ?? 'semana')
            )),
            'fecha_desde' => trim(
                (string)($_GET['fecha_desde'] ?? '')
            ),
            'fecha_hasta' => trim(
                (string)($_GET['fecha_hasta'] ?? '')
            ),
            'estado_id' => max(
                0,
                (int)($_GET['estado_id'] ?? 0)
            ),
            'persona_id' => max(
                0,
                (int)($_GET['persona_id'] ?? 0)
            )
        ];
    }

    private function validarAcceso($requiereExportar = false)
    {
        if (!tienePermiso('desempeno.ver')) {
            http_response_code(403);
            die('No tienes permiso para consultar Desempeño.');
        }

        if (
            !tienePermiso('desempeno.ver_global') &&
            !tienePermiso('desempeno.ver_equipo') &&
            !tienePermiso('desempeno.ver_propio')
        ) {
            http_response_code(403);
            die('No tienes un alcance de Desempeño habilitado.');
        }

        if (
            $requiereExportar &&
            !tienePermiso('desempeno.exportar')
        ) {
            http_response_code(403);
            die('No tienes permiso para exportar Desempeño.');
        }
    }
}
