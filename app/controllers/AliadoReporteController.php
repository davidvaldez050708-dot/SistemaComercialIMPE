<?php

require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../models/AliadoModel.php';
require_once __DIR__ . '/../models/SeguimientoVinculacionModel.php';
require_once __DIR__ . '/../services/ReporteAliadosPanoramaService.php';

class AliadoReporteController
{
    public function index()
    {
        $this->validarAcceso();

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;

        $modeloAliado = new AliadoModel();
        $modeloSeguimiento = new SeguimientoVinculacionModel();

        $territorios = $esAdministrador
            ? $modeloSeguimiento->obtenerEstadosAdministrador()
            : $modeloSeguimiento->obtenerEstadosSupervisadosCuentaClave(
                $usuarioId
            );

        $idsTerritorios = [];
        foreach ($territorios as $territorio) {
            $id = (int)($territorio['id'] ?? 0);
            if ($id > 0) {
                $idsTerritorios[$id] = true;
            }
        }

        $estadoId = max(0, (int)($_GET['estado_id'] ?? 0));
        if ($estadoId > 0 && !isset($idsTerritorios[$estadoId])) {
            http_response_code(403);
            die('No tienes acceso al territorio seleccionado.');
        }

        $aliadosParaMunicipios = $modeloAliado->obtenerListado(
            $usuarioId,
            $esAdministrador,
            $estadoId > 0 ? ['estado_id' => $estadoId] : []
        );

        $municipios = [];
        foreach ($aliadosParaMunicipios as $aliado) {
            $municipioId = (int)($aliado['municipio_id'] ?? 0);
            $municipioNombre = trim(
                (string)($aliado['municipio_nombre'] ?? '')
            );

            if ($municipioId <= 0 || $municipioNombre === '') {
                continue;
            }

            $municipios[$municipioId] = [
                'id' => $municipioId,
                'nombre' => $municipioNombre,
                'estado_id' => (int)($aliado['estado_id'] ?? 0),
                'estado_nombre' => (string)($aliado['estado_nombre'] ?? '')
            ];
        }

        uasort($municipios, static function ($a, $b) {
            $comparacion = strcasecmp(
                (string)($a['estado_nombre'] ?? ''),
                (string)($b['estado_nombre'] ?? '')
            );

            return $comparacion !== 0
                ? $comparacion
                : strnatcasecmp(
                    (string)($a['nombre'] ?? ''),
                    (string)($b['nombre'] ?? '')
                );
        });

        $municipioId = max(0, (int)($_GET['municipio_id'] ?? 0));
        if ($municipioId > 0 && !isset($municipios[$municipioId])) {
            $municipioId = 0;
        }

        $situacionesPermitidas = [
            'todos',
            'con_difusion',
            'sin_difusion',
            'pendientes',
            'vencidos',
            'esperando_respuesta',
            'sin_respuesta',
            'solicita_informacion',
            'difusion_confirmada',
            'no_participara'
        ];
        $situacion = strtolower(trim(
            (string)($_GET['situacion'] ?? 'todos')
        ));
        if (!in_array($situacion, $situacionesPermitidas, true)) {
            $situacion = 'todos';
        }

        $filtrosReporte = [
            'estado_id' => $estadoId,
            'municipio_id' => $municipioId,
            'situacion' => $situacion
        ];

        $generarReporte = (string)($_GET['generar'] ?? '') === '1';
        $reporteAliados = null;

        if ($generarReporte) {
            $service = new ReporteAliadosPanoramaService();
            $reporteAliados = $service->prepararDatos(
                $usuarioId,
                $esAdministrador,
                $filtrosReporte
            );
        }

        $tituloPagina = 'Reporte de Aliados';
        $subtituloPagina =
            'Panorama ejecutivo de la red institucional y su seguimiento.';
        $opcionActiva = 'reportes';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/reportes/aliados.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    private function validarAcceso()
    {
        if (!isset($_SESSION['usuario_id'])) {
            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );
            exit;
        }

        if (
            !tienePermiso('reportes.ver') ||
            !tienePermiso('reportes.aliados.panorama') ||
            !tienePermiso('aliados.ver')
        ) {
            http_response_code(403);
            die('No tienes permiso para consultar este reporte.');
        }
    }
}
