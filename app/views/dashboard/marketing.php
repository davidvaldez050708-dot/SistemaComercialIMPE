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

<?php require __DIR__ . '/marketing_indicadores.php'; ?>




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

<?php require __DIR__ . '/marketing_widgets.php'; ?>

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

<div class="mt-4">


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