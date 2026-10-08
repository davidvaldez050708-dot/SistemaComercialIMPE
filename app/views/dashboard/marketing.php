<div class="marketing-module-typography">
<?php
$resumenMarketing = $resumenMarketing ?? [];
$coberturaMarketing = $coberturaMarketing ?? [
    'total_estados' => 0,
    'estados_cubiertos' => 0,
    'territorios' => []
];
$convocatoriasUrl = BASE_URL . 'index.php?controller=convocatoria&action=index';
$proximasFinalizarMarketing = is_array($proximasFinalizarMarketing ?? null)
    ? $proximasFinalizarMarketing
    : [];
$totalEstadosCobertura = (int)($coberturaMarketing['total_estados'] ?? 0);
$estadosCubiertos = (int)($coberturaMarketing['estados_cubiertos'] ?? 0);
$territoriosCobertura = is_array($coberturaMarketing['territorios'] ?? null)
    ? $coberturaMarketing['territorios']
    : [];
$estadosSinConvocatoria = is_array($estadosSinConvocatoria ?? null)
    ? $estadosSinConvocatoria
    : [];

$nombreMarketing = trim((string)($_SESSION['nombre'] ?? ''));
if ($nombreMarketing === '') {
    $nombreMarketing = trim((string)($_SESSION['usuario'] ?? 'Marketing'));
}

$horaMarketing = (int)date('G');
$saludoMarketing = $horaMarketing < 12
    ? 'Buenos días'
    : ($horaMarketing < 19 ? 'Buenas tardes' : 'Buenas noches');

$mesesMarketing = [
    1 => 'enero',
    2 => 'febrero',
    3 => 'marzo',
    4 => 'abril',
    5 => 'mayo',
    6 => 'junio',
    7 => 'julio',
    8 => 'agosto',
    9 => 'septiembre',
    10 => 'octubre',
    11 => 'noviembre',
    12 => 'diciembre'
];
$diasMarketing = [
    1 => 'lunes',
    2 => 'martes',
    3 => 'miércoles',
    4 => 'jueves',
    5 => 'viernes',
    6 => 'sábado',
    7 => 'domingo'
];

$fechaMarketingActual = new DateTimeImmutable();
$fechaLargaMarketing =
    ucfirst($diasMarketing[(int)$fechaMarketingActual->format('N')]) . ', ' .
    $fechaMarketingActual->format('j') . ' de ' .
    $mesesMarketing[(int)$fechaMarketingActual->format('n')] . ' de ' .
    $fechaMarketingActual->format('Y');

$totalCoberturaPendienteMarketing = count($estadosSinConvocatoria);
?>

<section class="analyst-dashboard-v2 marketing-welcome-shell">
    <section class="analyst-welcome marketing-welcome">
        <div class="analyst-welcome-copy">
            <span class="analyst-welcome-eyebrow">TU JORNADA</span>
            <h2>
                <?= htmlspecialchars(
                    $saludoMarketing . ', ' . $nombreMarketing,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </h2>
            <p>
                <?= htmlspecialchars(
                    $fechaLargaMarketing,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </p>
            <p class="analyst-welcome-tagline">
                Mantengamos tus convocatorias vigentes, visibles y actualizadas.
            </p>
        </div>

        <div class="analyst-welcome-status-zone">
            <a
                class="analyst-welcome-status <?= $totalCoberturaPendienteMarketing > 0 ? 'is-attention' : 'is-clear' ?> is-dashboard-action marketing-welcome-status-link"
                href="#marketingPendingCoverage">
                <span class="analyst-welcome-status-icon" aria-hidden="true">
                    <i class="bi"></i>
                </span>
                <strong>
                    <?php if ($totalCoberturaPendienteMarketing > 0): ?>
                        <?= $totalCoberturaPendienteMarketing ?>
                        <?= $totalCoberturaPendienteMarketing === 1
                            ? 'territorio requiere cobertura'
                            : 'territorios requieren cobertura' ?>
                    <?php else: ?>
                        Cobertura territorial completa
                    <?php endif; ?>
                </strong>
                <i
                    class="bi bi-chevron-down analyst-welcome-status-arrow"
                    aria-hidden="true"></i>
            </a>

            <span class="analyst-welcome-status-copy">
                <?= $totalCoberturaPendienteMarketing > 0
                    ? 'Consulta los estados que aún no cuentan con una convocatoria activa.'
                    : 'Todos los estados cuentan con al menos una convocatoria activa.' ?>
            </span>
        </div>
    </section>
</section>

<?php require __DIR__ . '/../telefonia/recepcion_marketing.php'; ?>

<div class="metric-grid mb-4">
    <article class="metric-card">
        <div class="metric-icon"><i class="bi bi-megaphone"></i></div>
        <div>
            <p class="metric-value"><?= (int)($resumenMarketing['total'] ?? 0) ?></p>
            <p class="metric-label">Total de convocatorias</p>
        </div>
    </article>

    <article class="metric-card">
        <div class="metric-icon metric-icon-success"><i class="bi bi-check-circle"></i></div>
        <div>
            <p class="metric-value"><?= (int)($resumenMarketing['activas'] ?? 0) ?></p>
            <p class="metric-label">Convocatorias activas</p>
        </div>
    </article>

    <article class="metric-card">
        <div class="metric-icon metric-icon-muted"><i class="bi bi-slash-circle"></i></div>
        <div>
            <p class="metric-value"><?= (int)($resumenMarketing['inactivas'] ?? 0) ?></p>
            <p class="metric-label">Convocatorias inactivas</p>
        </div>
    </article>

    <article class="metric-card">
        <div class="metric-icon"><i class="bi bi-calendar-check"></i></div>
        <div>
            <p class="metric-value"><?= (int)($resumenMarketing['vigentes'] ?? 0) ?></p>
            <p class="metric-label">Convocatorias vigentes</p>
        </div>
    </article>
</div>

<section class="dashboard-panel marketing-expiring-panel">
    <div class="table-panel-header">
        <div>
            <h2 class="panel-title mb-0 marketing-expiring-title">Gestión de Convocatorias</h2>
            <p class="panel-subtitle mb-0">
                <?= (int)($resumenMarketing['proximas_finalizar'] ?? 0) ?>
                convocatorias activas finalizan en los próximos 7 días.
            </p>
        </div>

        <?php if (tienePermiso('convocatorias.ver')): ?>
            <button
                type="button"
                class="btn btn-system-primary marketing-expiring-trigger"
                data-bs-toggle="modal"
                data-bs-target="#modalConvocatoriasPorVencer">
                <i class="bi bi-arrow-right-circle me-2"></i>
                Ir a convocatorias
            </button>
        <?php endif; ?>
    </div>
</section>

<?php
$publicacionesRecientesMarketing = is_array($publicacionesRecientesMarketing ?? null)
    ? $publicacionesRecientesMarketing
    : [];

$formatearFechaMarketing = static function ($fecha) {
    $fecha = trim((string)$fecha);

    if ($fecha === '') {
        return '—';
    }

    $timestamp = strtotime($fecha);

    return $timestamp !== false ? date('d/m/Y', $timestamp) : $fecha;
};

$estadoEtiquetasMarketing = [
    'activa' => 'Activa',
    'proxima' => 'Próxima a vencer',
    'finalizada' => 'Finalizada',
    'inactiva' => 'Inactiva'
];
?>

<?php if (tienePermiso('convocatorias.ver')): ?>
<div
    class="modal fade"
    id="modalConvocatoriasPorVencer"
    tabindex="-1"
    aria-labelledby="modalConvocatoriasPorVencerTitulo"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered marketing-expiring-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header">
                <div>
                    <h2
                        class="modal-title"
                        id="modalConvocatoriasPorVencerTitulo">
                        Convocatorias próximas a vencer
                    </h2>
                    <p class="modal-subtitle">
                        Selecciona una convocatoria para ir directamente a su ubicación.
                    </p>
                </div>
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Cerrar"></button>
            </div>

            <div class="modal-body marketing-expiring-modal-body">
                <?php if (!empty($proximasFinalizarMarketing)): ?>
                    <div class="marketing-expiring-list">
                        <?php foreach ($proximasFinalizarMarketing as $convocatoriaPorVencer): ?>
                            <?php
                            $territorioVencimientoId =
                                (int)($convocatoriaPorVencer['territorio_id'] ?? 0);
                            $tipoVencimiento =
                                trim((string)($convocatoriaPorVencer['tipo_convocatoria'] ?? ''));
                            $subtipoVencimiento =
                                trim((string)($convocatoriaPorVencer['subtipo_convocatoria'] ?? ''));
                            $tituloVencimiento =
                                trim((string)($convocatoriaPorVencer['titulo'] ?? 'Convocatoria'));
                            $urlVencimiento = $convocatoriasUrl;

                            if ($territorioVencimientoId > 0) {
                                $urlVencimiento .=
                                    '&territorio_id=' . $territorioVencimientoId;
                            }

                            if ($tipoVencimiento !== '') {
                                $urlVencimiento .=
                                    '&tipo=' . rawurlencode($tipoVencimiento);
                            }

                            if ($subtipoVencimiento !== '') {
                                $urlVencimiento .=
                                    '&subtipo=' . rawurlencode($subtipoVencimiento);
                            }

                            if ($tituloVencimiento !== '') {
                                $urlVencimiento .=
                                    '&buscar=' . rawurlencode($tituloVencimiento);
                            }

                            $diasRestantesVencimiento =
                                max(0, (int)($convocatoriaPorVencer['dias_restantes'] ?? 0));

                            if ($diasRestantesVencimiento === 0) {
                                $textoVencimiento = 'Vence hoy';
                            } elseif ($diasRestantesVencimiento === 1) {
                                $textoVencimiento = 'Vence mañana';
                            } else {
                                $textoVencimiento =
                                    'Vence en ' . $diasRestantesVencimiento . ' días';
                            }
                            ?>
                            <a
                                class="marketing-expiring-item"
                                href="<?= htmlspecialchars($urlVencimiento, ENT_QUOTES, 'UTF-8') ?>">
                                <span class="marketing-expiring-item-icon">
                                    <i class="bi bi-calendar-event"></i>
                                </span>

                                <span class="marketing-expiring-item-copy">
                                    <strong>
                                        <?= htmlspecialchars($tituloVencimiento, ENT_QUOTES, 'UTF-8') ?>
                                    </strong>
                                    <small>
                                        <?= htmlspecialchars(
                                            (string)($convocatoriaPorVencer['estados'] ?? 'Sin territorio'),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                        ·
                                        <?= htmlspecialchars(
                                            $formatearFechaMarketing(
                                                $convocatoriaPorVencer['fecha_termino'] ?? ''
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </small>
                                </span>

                                <span class="marketing-expiring-item-status">
                                    <?= htmlspecialchars($textoVencimiento, ENT_QUOTES, 'UTF-8') ?>
                                </span>

                                <i class="bi bi-chevron-right marketing-expiring-item-arrow"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="marketing-expiring-empty">
                        <i class="bi bi-check-circle"></i>
                        <strong>No hay convocatorias próximas a vencer.</strong>
                        <span>
                            No existen convocatorias activas con vencimiento en los próximos 7 días.
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<section class="dashboard-panel marketing-recent-publications mt-4">
    <div class="marketing-recent-heading">
        <div class="marketing-recent-title">
            <span class="marketing-recent-title-icon">
                <i class="bi bi-file-earmark-text"></i>
            </span>
            <div>
                <h2>Publicaciones recientes</h2>
                <p>Últimas convocatorias publicadas en el sistema.</p>
            </div>
        </div>

        <?php if (tienePermiso('convocatorias.ver')): ?>
            <a
                class="marketing-recent-link"
                href="<?= BASE_URL ?>index.php?controller=convocatoria&action=publicacionesDelDia&fecha=<?= date('Y-m-d') ?>">
                Ver todas
                <i class="bi bi-arrow-right"></i>
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($publicacionesRecientesMarketing)): ?>
        <div class="marketing-recent-table-wrap">
            <table class="marketing-recent-table">
                <thead>
                    <tr>
                        <th>Nombre de la convocatoria</th>
                        <th>Tipo</th>
                        <th>Fecha de publicación</th>
                        <th>Vigencia</th>
                        <th>Estado</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($publicacionesRecientesMarketing as $publicacionReciente): ?>
                        <?php
                        $tipoReciente = (string)($publicacionReciente['tipo_convocatoria'] ?? '');
                        $estadoProceso = (string)($publicacionReciente['estado_proceso'] ?? 'inactiva');

                        if ($tipoReciente === 'titulacion') {
                            $tipoEtiqueta = 'Titulación';
                            $tipoIcono = 'bi-mortarboard';
                        } elseif ($tipoReciente === 'bachillerato') {
                            $tipoEtiqueta = 'Bachillerato';
                            $tipoIcono = 'bi-book';
                        } else {
                            $tipoEtiqueta = 'Sindicatos';
                            $tipoIcono = 'bi-people';
                        }
                        $territorioRecienteId = (int)($publicacionReciente['territorio_id'] ?? 0);
                        $urlPublicacionReciente = $convocatoriasUrl;

                        if ($territorioRecienteId > 0) {
                            $urlPublicacionReciente .= '&territorio_id=' . $territorioRecienteId;
                        }

                        if ($tipoReciente !== '') {
                            $urlPublicacionReciente .= '&tipo=' . rawurlencode($tipoReciente);
                        }

                        $subtipoReciente = (string)($publicacionReciente['subtipo_convocatoria'] ?? '');

                        if ($subtipoReciente !== '') {
                            $urlPublicacionReciente .= '&subtipo=' . rawurlencode($subtipoReciente);
                        }

                        $tituloReciente = trim((string)($publicacionReciente['titulo'] ?? ''));

                        if ($tituloReciente !== '') {
                            $urlPublicacionReciente .= '&buscar=' . rawurlencode($tituloReciente);
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="marketing-recent-name">
                                    <span class="marketing-recent-row-icon">
                                        <i class="bi <?= htmlspecialchars($tipoIcono, ENT_QUOTES, 'UTF-8') ?>"></i>
                                    </span>
                                    <strong>
                                        <?= htmlspecialchars((string)($publicacionReciente['titulo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </strong>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($tipoEtiqueta, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($formatearFechaMarketing($publicacionReciente['fecha_inicio'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?= htmlspecialchars($formatearFechaMarketing($publicacionReciente['fecha_inicio'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                -
                                <?= htmlspecialchars($formatearFechaMarketing($publicacionReciente['fecha_termino'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <span class="convocatoria-process-badge convocatoria-process-<?= htmlspecialchars($estadoProceso, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($estadoEtiquetasMarketing[$estadoProceso] ?? 'Inactiva', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if (tienePermiso('convocatorias.ver')): ?>
                                    <a
                                        class="marketing-recent-row-action"
                                        href="<?= htmlspecialchars($urlPublicacionReciente, ENT_QUOTES, 'UTF-8') ?>"
                                        aria-label="Abrir <?= htmlspecialchars($tituloReciente !== '' ? $tituloReciente : 'convocatoria', ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="marketing-recent-row-action is-disabled" aria-hidden="true">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="marketing-recent-empty">
            <i class="bi bi-inbox"></i>
            <span>No hay publicaciones recientes para mostrar.</span>
        </div>
    <?php endif; ?>
</section>

<?php
$porcentajeCobertura = $totalEstadosCobertura > 0
    ? (int)round(($estadosCubiertos / $totalEstadosCobertura) * 100)
    : 0;
$maxConvocatoriasTerritorio = 0;
foreach ($territoriosCobertura as $territorioCobertura) {
    $maxConvocatoriasTerritorio = max(
        $maxConvocatoriasTerritorio,
        (int)($territorioCobertura['convocatorias_activas'] ?? 0)
    );
}
?>

<section class="dashboard-panel marketing-territory-coverage mt-4">
    <div class="marketing-coverage-heading">
        <div>
            <span class="analyst-section-kicker">COBERTURA TERRITORIAL</span>
            <h2 class="panel-title mb-0">Cobertura territorial de convocatorias activas</h2>
        </div>

        <a class="analyst-panel-link" href="<?= $convocatoriasUrl ?>">
            Ver territorios
            <i class="bi bi-arrow-right"></i>
        </a>
    </div>

    <div class="marketing-coverage-grid">
        <div class="marketing-coverage-overview">
            <div
                class="marketing-coverage-donut"
                style="--coverage-percent: <?= $porcentajeCobertura ?>%;">
                <div class="marketing-coverage-donut-center">
                    <strong><?= $estadosCubiertos ?> de <?= $totalEstadosCobertura ?></strong>
                    <span>estados</span>
                </div>
            </div>

            <div class="marketing-coverage-copy">
                <strong>con convocatorias activas</strong>
                <p>
                    Actualmente existen convocatorias activas en el
                    <?= $porcentajeCobertura ?>% del territorio nacional.
                </p>
            </div>
        </div>

        <div class="marketing-coverage-ranking">
            <h3>Estados con más convocatorias activas</h3>

            <?php if (!empty($territoriosCobertura)): ?>
                <div class="marketing-coverage-list">
                    <?php foreach ($territoriosCobertura as $indice => $territorio): ?>
                        <?php
                        $cantidadActivas = (int)($territorio['convocatorias_activas'] ?? 0);
                        $anchoBarra = $maxConvocatoriasTerritorio > 0
                            ? ($cantidadActivas / $maxConvocatoriasTerritorio) * 100
                            : 0;
                        ?>
                        <div class="marketing-coverage-item">
                            <span class="marketing-coverage-position"><?= $indice + 1 ?></span>
                            <span class="marketing-coverage-state">
                                <?= htmlspecialchars((string)($territorio['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span class="marketing-coverage-track" aria-hidden="true">
                                <span
                                    class="marketing-coverage-bar"
                                    style="width: <?= number_format($anchoBarra, 2, '.', '') ?>%;">
                                </span>
                            </span>
                            <strong><?= $cantidadActivas ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="marketing-coverage-empty">
                    Aún no hay cobertura territorial activa.
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php
$periodoPublicaciones = (int)($periodoPublicaciones ?? 30);
$totalPublicacionesPeriodo = (int)($totalPublicacionesPeriodo ?? 0);
?>

<?php
$estadosSinConvocatoria = is_array($estadosSinConvocatoria ?? null)
    ? $estadosSinConvocatoria
    : [];
$totalSinConvocatoria = count($estadosSinConvocatoria);
$porcentajeSinConvocatoria = $totalEstadosCobertura > 0
    ? (int)round(($totalSinConvocatoria / $totalEstadosCobertura) * 100)
    : 0;
?>

<div class="marketing-bottom-grid mt-4">
<section class="dashboard-panel marketing-publications-by-type">
    <?php
    $publicacionesPorTipoMarketing = is_array($publicacionesPorTipoMarketing ?? null)
        ? $publicacionesPorTipoMarketing
        : [];

    $tiposPublicacionesMarketing = [
        'bachillerato' => [
            'titulo' => 'Bachillerato',
            'icono' => 'bi-book'
        ],
        'titulacion' => [
            'titulo' => 'Titulación',
            'icono' => 'bi-mortarboard'
        ]
    ];
    ?>

    <div class="marketing-publications-types-heading">
        <div>
            <span class="analyst-section-kicker">PUBLICACIONES POR TIPO</span>
            <h2>Total de publicaciones según el tipo de convocatoria.</h2>
        </div>

        <form method="GET" action="<?= BASE_URL ?>index.php" class="marketing-publications-filter">
            <input type="hidden" name="controller" value="home">
            <input type="hidden" name="action" value="index">
            <select
                class="form-select system-form-control"
                name="periodo_publicaciones"
                aria-label="Periodo de publicaciones"
                onchange="this.form.submit()">
                <option value="7" <?= $periodoPublicaciones === 7 ? 'selected' : '' ?>>Últimos 7 días</option>
                <option value="30" <?= $periodoPublicaciones === 30 ? 'selected' : '' ?>>Últimos 30 días</option>
                <option value="90" <?= $periodoPublicaciones === 90 ? 'selected' : '' ?>>Últimos 90 días</option>
            </select>
        </form>
    </div>

    <div class="marketing-publications-type-grid">
        <?php foreach ($tiposPublicacionesMarketing as $tipoClave => $tipoConfig): ?>
            <?php
            $datosTipo = is_array($publicacionesPorTipoMarketing[$tipoClave] ?? null)
                ? $publicacionesPorTipoMarketing[$tipoClave]
                : [];
            $mesesTipo = is_array($datosTipo['meses'] ?? null)
                ? $datosTipo['meses']
                : [];
            $valoresMes = array_map(
                static fn($mes) => (int)($mes['total'] ?? 0),
                $mesesTipo
            );
            $maxValorMes = !empty($valoresMes) ? max($valoresMes) : 0;
            $escalaMes = max(1, $maxValorMes);
            ?>

            <article class="marketing-publication-type-card">
                <div class="marketing-publication-type-header">
                    <span class="marketing-publication-type-icon">
                        <i class="bi <?= htmlspecialchars($tipoConfig['icono'], ENT_QUOTES, 'UTF-8') ?>"></i>
                    </span>
                    <strong><?= htmlspecialchars($tipoConfig['titulo'], ENT_QUOTES, 'UTF-8') ?></strong>
                </div>

                <div class="marketing-publication-type-body">
                    <div class="marketing-publication-type-total">
                        <strong><?= (int)($datosTipo['total'] ?? 0) ?></strong>
                        <span>publicaciones</span>
                    </div>

                    <div class="marketing-publication-type-chart">
                        <?php foreach ($mesesTipo as $mes): ?>
                            <?php
                            $cantidadMes = (int)($mes['total'] ?? 0);
                            $alturaBarra = $cantidadMes > 0
                                ? max(18, (int)round(($cantidadMes / $escalaMes) * 100))
                                : 10;
                            $esDestacado = $maxValorMes > 0 && $cantidadMes === $maxValorMes;
                            ?>
                            <div class="marketing-publication-type-month">
                                <strong><?= $cantidadMes ?></strong>
                                <div class="marketing-publication-type-bar-space" aria-hidden="true">
                                    <span
                                        class="<?= $esDestacado ? 'is-highlighted' : '' ?>"
                                        style="height: <?= $alturaBarra ?>%;">
                                    </span>
                                </div>
                                <small>
                                    <?= htmlspecialchars((string)($mes['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

    <section
        class="dashboard-panel marketing-pending-coverage"
        id="marketingPendingCoverage">
        <div class="marketing-pending-heading">
            <div>
                <span class="analyst-section-kicker">COBERTURA PENDIENTE</span>
                <h2>Estados sin convocatoria activa</h2>
                <p>Territorios que actualmente no cuentan con convocatorias activas.</p>
            </div>

            <a class="analyst-panel-link" href="<?= $convocatoriasUrl ?>">
                Ver territorios
                <i class="bi bi-arrow-right"></i>
            </a>
        </div>

        <div class="marketing-pending-content">
            <div class="marketing-pending-summary">
                <div class="marketing-pending-count">
                    <span class="marketing-pending-icon">
                        <i class="bi bi-dash-circle"></i>
                    </span>
                    <div>
                        <strong><?= $totalSinConvocatoria ?> de <?= $totalEstadosCobertura ?></strong>
                        <span>estados sin convocatoria activa</span>
                    </div>
                </div>

                <div class="marketing-pending-progress" aria-hidden="true">
                    <span style="width: <?= $porcentajeSinConvocatoria ?>%;"></span>
                </div>

                <p>
                    <strong><?= $porcentajeSinConvocatoria ?>%</strong>
                    del territorio sin convocatoria activa.
                </p>
            </div>

            <div class="marketing-pending-list">
                <?php if (!empty($estadosSinConvocatoria)): ?>
                    <?php foreach ($estadosSinConvocatoria as $indice => $estadoPendiente): ?>
                        <div class="marketing-pending-item">
                            <span><?= $indice + 1 ?></span>
                            <strong>
                                <?= htmlspecialchars((string)($estadoPendiente['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </strong>
                            <small>Sin convocatoria</small>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="marketing-pending-empty">
                        Todos los estados cuentan con al menos una convocatoria activa.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

</div>