<?php

$tituloPagina = $tituloPagina ?? 'Panel administrativo';
$subtituloPagina = $subtituloPagina ?? 'Resumen del sistema';

$nombreCompleto = trim(
    ($_SESSION['nombre'] ?? '') . ' ' .
    ($_SESSION['apellidos'] ?? '')
);

if ($nombreCompleto === '') {
    $nombreCompleto = $_SESSION['usuario'] ?? 'Usuario';
}

$iniciales = strtoupper(
    substr($_SESSION['nombre'] ?? 'U', 0, 1) .
    substr($_SESSION['apellidos'] ?? '', 0, 1)
);

if (strlen($iniciales) < 2) {
    $iniciales = strtoupper(substr($nombreCompleto, 0, 2));
}

$fotoPerfil = trim($_SESSION['foto_perfil'] ?? '');
$fotoPerfilUrl = $fotoPerfil !== ''
    ? BASE_URL . ltrim($fotoPerfil, '/')
    : '';

$controllerDashboard = strtolower(
    trim((string)($_GET['controller'] ?? 'home'))
);
$actionDashboard = strtolower(
    trim((string)($_GET['action'] ?? 'index'))
);

$esHomeDashboard = $controllerDashboard === 'home';
$esSeguimientoEstado =
    $controllerDashboard === 'seguimientovinculacion' &&
    $actionDashboard === 'estado';
$esSeguimientoDetalle =
    $controllerDashboard === 'seguimientovinculacion' &&
    $actionDashboard === 'detalle';
$esAgendaDashboard = $controllerDashboard === 'agendareunion';
$esReportesDashboard =
    in_array(
        $controllerDashboard,
        [
            'reporte',
            'reporteadministrador',
            'seguimientovinculacionreporte',
            'seguimientoreporteanalitica'
        ],
        true
    ) ||
    (
        $controllerDashboard === 'seguimientovinculacion' &&
        $actionDashboard === 'reportes'
    );
$esTerritorialDashboard = in_array(
    $controllerDashboard,
    [
        'territorio',
        'dataterritorial',
        'fuenteoficial',
        'poblacionobjetivoeducativa'
    ],
    true
);
$esConvocatoriaDashboard = $controllerDashboard === 'convocatoria';

$cssOpcionalDashboard = [];

if ($esHomeDashboard) {
    $cssOpcionalDashboard = array_merge(
        $cssOpcionalDashboard,
        [
            'dashboard_analista.css',
            'dashboard_analista_refinamientos.css',
            'dashboard_analista_reuniones.css',
            'dashboard_analista_boceto.css',
            'dashboard_analista_boceto_ajustes.css',
            'territorios_resumen_refinamientos.css'
        ]
    );
}

if ($esSeguimientoEstado) {
    $cssOpcionalDashboard = array_merge(
        $cssOpcionalDashboard,
        [
            'seguimiento_filtros_layout.css',
            'seguimiento_panel_ruta.css',
            'seguimiento_proxima_accion_fecha.css',
            'oficios_vista_previa.css',
            'oficios_vista_previa_documento.css',
            'oficios_correo_ajustes.css',
            'oficios_selector_compacto.css',
            'seguimiento_interacciones_refinamientos.css',
            'seguimiento_llamadas_refinamientos.css',
            'seguimiento_flujo.css',
            'seguimiento_llamada_twilio.css',
            'seguimiento_caller_id_usuario.css',
            'seguimiento_llamada_flotante.css',
            'seguimiento_llamada_registro_obligatorio.css',
            'seguimiento_llamada_registro_compacto.css',
            'seguimiento_llamada_contacto_efectivo.css',
            'seguimiento_post_envio.css',
            'seguimiento_estado_compacto.css'
        ]
    );
}

if ($esSeguimientoDetalle) {
    $cssOpcionalDashboard = array_merge(
        $cssOpcionalDashboard,
        [
            'seguimiento_expediente.css',
            'seguimiento_expediente_v2.css',
            'seguimiento_expediente_proxima_accion.css',
            'seguimiento_expediente_actividad.css',
            'seguimiento_expediente_oficios_refinamiento.css',
            'seguimiento_expediente_correos.css',
            'seguimiento_llamadas_expediente.css',
            'seguimiento_llamadas_player.css'
        ]
    );
}

if ($esAgendaDashboard) {
    $cssOpcionalDashboard = array_merge(
        $cssOpcionalDashboard,
        [
            'agenda_reunion.css',
            'agenda_reunion_refinamientos.css'
        ]
    );
}

if ($esSeguimientoEstado || $esSeguimientoDetalle || $esAgendaDashboard) {
    $cssOpcionalDashboard[] = 'seguimiento_estabilizacion_visual.css';
}

if ($esConvocatoriaDashboard || $esHomeDashboard) {
    $cssOpcionalDashboard[] = 'convocatorias.css';
}

if ($esTerritorialDashboard) {
    $cssOpcionalDashboard[] = 'territorios_resumen_refinamientos.css';
}

if ($esReportesDashboard) {
    $cssOpcionalDashboard = array_merge(
        $cssOpcionalDashboard,
        [
            'reportes.css',
            'seguimiento_reportes_refinamiento.css',
            'seguimiento_reportes_decisiones_v2.css',
            'seguimiento_reportes_analitica.css',
            'seguimiento_reportes_presentacion_v3.css',
            'seguimiento_reportes_institucion_operativa.css'
        ]
    );
}

$cssOpcionalDashboard = array_values(array_unique($cssOpcionalDashboard));

$jsOpcionalHead = [
    'firma_correo_perfil.js'
];

if ($esHomeDashboard) {
    $jsOpcionalHead = array_merge(
        $jsOpcionalHead,
        [
            'seguimiento_abrir_desde_dashboard.js',
            'dashboard_analista.js',
            'dashboard_analista_integridad.js',
            'dashboard_analista_refinamientos.js',
            'dashboard_analista_navegacion_rapida.js',
            'dashboard_analista_reuniones.js'
        ]
    );
}

if ($esSeguimientoEstado) {
    $jsOpcionalHead = array_merge(
        $jsOpcionalHead,
        [
            'seguimiento_interaccion_id_bridge.js',
            'seguimiento_llamada_zadarma.js',
            'seguimiento_zadarma_marcado_e164.js',
            'seguimiento_zadarma_widget_oculto.js',
            'seguimiento_caller_id_usuario.js',
            'seguimiento_llamada_twilio.js',
            'seguimiento_llamada_flotante.js',
            'seguimiento_llamada_registro_obligatorio.js',
            'seguimiento_llamada_registro_compacto.js',
            'seguimiento_llamada_contacto_efectivo.js',
            'seguimiento_llamadas_desplegable.js',
            'reunion_fecha_guard.js',
            'reunion_resultado.js',
            'reprogramacion_reunion.js',
            'seguimiento_bandeja_sync.js',
            'seguimiento_resumen_ruta.js',
            'seguimiento_panel_ruta.js',
            'seguimiento_proxima_accion_fecha.js',
            'seguimiento_resultados_humanizados.js',
            'oficios_correo_formato.js',
            'correo_firma_envio.js'
        ]
    );
}

if ($esSeguimientoDetalle) {
    $jsOpcionalHead = array_merge(
        $jsOpcionalHead,
        [
            'seguimiento_llamadas_expediente.js',
            'seguimiento_llamadas_desplegable.js',
            'seguimiento_expediente_proxima_accion.js',
            'seguimiento_resultados_humanizados.js',
            'seguimiento_expediente_oficios_refinamiento.js',
            'seguimiento_expediente_correos.js'
        ]
    );
}

if ($esAgendaDashboard) {
    $jsOpcionalHead = array_merge(
        $jsOpcionalHead,
        [
            'agenda_reunion.js',
            'agenda_kam_cambio.js',
            'reprogramacion_reunion.js',
            'agenda_correo_natural.js',
            'agenda_historial_navegacion.js',
            'correo_firma_envio.js'
        ]
    );
}

if ($esReportesDashboard) {
    $jsOpcionalHead = array_merge(
        $jsOpcionalHead,
        [
            'reportes_accesos.js',
            'seguimiento_reportes_refinamiento.js',
            'seguimiento_reportes_decisiones_v2.js',
            'seguimiento_reportes_analitica.js',
            'seguimiento_reportes_presentacion_v3.js',
            'seguimiento_reportes_atencion_unificada.js',
            'seguimiento_reportes_institucion_operativa.js'
        ]
    );
}

$jsOpcionalHead = array_values(array_unique($jsOpcionalHead));

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        <?= htmlspecialchars($tituloPagina) ?> | Grupo Porcayo
    </title>

    <link
        href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/global.css?v=<?= filemtime(ROOT_PATH . '/public/css/global.css') ?>">

    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/dashboard.css?v=<?= time() ?>">

    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/recordatorios.css?v=<?= filemtime(ROOT_PATH . '/public/css/recordatorios.css') ?>">

    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/poblacion_objetivo_educativa.css?v=<?= filemtime(ROOT_PATH . '/public/css/poblacion_objetivo_educativa.css') ?>">

    <?php foreach ($cssOpcionalDashboard as $archivoCss): ?>
        <?php $rutaCss = ROOT_PATH . '/public/css/' . $archivoCss; ?>
        <?php if (is_file($rutaCss)): ?>
            <link
                rel="stylesheet"
                href="<?= BASE_URL ?>public/css/<?= htmlspecialchars($archivoCss, ENT_QUOTES, 'UTF-8') ?>?v=<?= filemtime($rutaCss) ?>">
        <?php endif; ?>
    <?php endforeach; ?>

    <script>
        window.IMPE_CURRENT_ROLE_ID = <?= (int)($_SESSION['rol_id'] ?? 0) ?>;
        window.IMPE_CURRENT_USER_ID = <?= (int)($_SESSION['usuario_id'] ?? 0) ?>;
        window.IMPE_CAN_OPERATE_LINKAGE = <?= tienePermiso('seguimientos_vinculacion.operar_propios') ? 'true' : 'false' ?>;
        window.IMPE_CAN_SUPERVISE_LINKAGE = <?= tienePermiso('seguimientos_vinculacion.supervisar') ? 'true' : 'false' ?>;
        window.IMPE_CSRF_TOKEN = <?= json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        (function () {
            'use strict';

            const token = String(window.IMPE_CSRF_TOKEN || '');
            if (token === '') {
                return;
            }

            const mismaProcedencia = function (valor) {
                try {
                    const url = new URL(
                        valor instanceof Request ? valor.url : String(valor || ''),
                        window.location.href
                    );
                    return url.origin === window.location.origin;
                } catch (error) {
                    return true;
                }
            };

            const fetchOriginal = window.fetch.bind(window);
            window.fetch = function (recurso, opciones) {
                const configuracion = Object.assign({}, opciones || {});
                const metodo = String(
                    configuracion.method ||
                    (recurso instanceof Request ? recurso.method : 'GET')
                ).toUpperCase();

                if (metodo === 'POST' && mismaProcedencia(recurso)) {
                    const headers = new Headers(
                        configuracion.headers ||
                        (recurso instanceof Request ? recurso.headers : undefined)
                    );
                    headers.set('X-CSRF-Token', token);
                    configuracion.headers = headers;
                }

                return fetchOriginal(recurso, configuracion);
            };

            document.addEventListener('submit', function (event) {
                const form = event.target;
                if (!(form instanceof HTMLFormElement)) {
                    return;
                }

                if (String(form.method || 'GET').toUpperCase() !== 'POST') {
                    return;
                }

                try {
                    const action = new URL(form.action || window.location.href, window.location.href);
                    if (action.origin !== window.location.origin) {
                        return;
                    }
                } catch (error) {
                    return;
                }

                let input = form.querySelector('input[name="csrf_token"]');
                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'csrf_token';
                    form.appendChild(input);
                }
                input.value = token;
            }, true);
        })();
        window.IMPE_ANALISTA_REUNIONES = <?= json_encode(
            is_array($tableroAnalista['reuniones_dashboard'] ?? null)
                ? $tableroAnalista['reuniones_dashboard']
                : [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?>;
        window.IMPE_ANALISTA_DASHBOARD_META = <?= json_encode([
            'requieren_atencion_total' => (int)($tableroAnalista['resumen']['requieren_atencion'] ?? 0)
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.IMPE_ANALISTA_ACTIVIDAD_RECIENTE = <?= json_encode(
            is_array($tableroAnalista['integridad_dashboard']['actividad_actores'] ?? null)
                ? $tableroAnalista['integridad_dashboard']['actividad_actores']
                : [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?>;
        window.IMPE_REPORTE_SEGUIMIENTO_IDS = <?= json_encode(
            array_values(array_map(
                static function ($seguimiento) {
                    return (int)($seguimiento['id'] ?? 0);
                },
                is_array($seguimientosReporte ?? null) ? $seguimientosReporte : []
            )),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?>;
        window.IMPE_SEGUIMIENTO_RUTAS_INICIALES = <?= json_encode(
            is_array($seguimientosRutaInicial ?? null)
                ? $seguimientosRutaInicial
                : [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?>;
    </script>

    <?php foreach ($jsOpcionalHead as $archivoJs): ?>
        <?php $rutaJs = ROOT_PATH . '/public/javascript/' . $archivoJs; ?>
        <?php if (is_file($rutaJs)): ?>
            <script
                src="<?= BASE_URL ?>public/javascript/<?= htmlspecialchars($archivoJs, ENT_QUOTES, 'UTF-8') ?>?v=<?= filemtime($rutaJs) ?>">
            </script>
        <?php endif; ?>
    <?php endforeach; ?>
</head>

<body>

<div class="admin-shell">