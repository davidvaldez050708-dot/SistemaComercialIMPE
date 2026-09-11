<?php

$tableroAnalista = is_array($tableroAnalista ?? null) ? $tableroAnalista : [];
$resumen = is_array($tableroAnalista['resumen'] ?? null) ? $tableroAnalista['resumen'] : [];
$avanceMes = is_array($tableroAnalista['avance_mes'] ?? null) ? $tableroAnalista['avance_mes'] : [];
$atenciones = is_array($tableroAnalista['atenciones'] ?? null) ? $tableroAnalista['atenciones'] : [];
$proximos = is_array($tableroAnalista['proximos'] ?? null) ? $tableroAnalista['proximos'] : [];
$actividad = is_array($tableroAnalista['actividad'] ?? null) ? $tableroAnalista['actividad'] : [];
$territorios = is_array($tableroAnalista['territorios'] ?? null) ? $tableroAnalista['territorios'] : [];

$nombreAnalista = trim((string)($_SESSION['nombre'] ?? ''));
if ($nombreAnalista === '') {
    $nombreAnalista = trim((string)($_SESSION['usuario'] ?? 'Analista'));
}

$horaActual = (int)date('G');
$saludo = $horaActual < 12
    ? 'Buenos días'
    : ($horaActual < 19 ? 'Buenas tardes' : 'Buenas noches');

$meses = [
    1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
    5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
    9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
];
$diasSemana = [
    1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves',
    5 => 'viernes', 6 => 'sábado', 7 => 'domingo'
];
$ahora = new DateTimeImmutable();
$fechaLarga = ucfirst($diasSemana[(int)$ahora->format('N')]) . ', ' .
    $ahora->format('j') . ' de ' . $meses[(int)$ahora->format('n')] . ' de ' . $ahora->format('Y');

$esc = static function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$urlSeguimiento = static function ($estadoId, $seguimientoId = 0) {
    $url = BASE_URL . 'index.php?controller=seguimientoVinculacion&action=estado&estado_id=' .
        (int)$estadoId;

    if ((int)$seguimientoId > 0) {
        $url .= '&abrir_seguimiento=' . (int)$seguimientoId;
    }

    return $url;
};

$formatearFechaCorta = static function ($valor) use ($meses) {
    if (!$valor) {
        return 'Sin fecha';
    }

    try {
        $fecha = new DateTimeImmutable((string)$valor);
        $hoy = new DateTimeImmutable('today');
        $manana = $hoy->modify('+1 day');

        if ($fecha->format('Y-m-d') === $hoy->format('Y-m-d')) {
            return 'Hoy · ' . $fecha->format('H:i');
        }

        if ($fecha->format('Y-m-d') === $manana->format('Y-m-d')) {
            return 'Mañana · ' . $fecha->format('H:i');
        }

        return $fecha->format('j') . ' ' . substr($meses[(int)$fecha->format('n')], 0, 3) .
            ' · ' . $fecha->format('H:i');
    } catch (Throwable $error) {
        return (string)$valor;
    }
};

$etiquetaCanal = static function ($canal) {
    $etiquetas = [
        'LLAMADA_IP' => 'Llamada',
        'WHATSAPP' => 'WhatsApp',
        'CORREO' => 'Correo',
        'NOTA' => 'Nota',
        'SISTEMA' => 'Actualización'
    ];

    return $etiquetas[strtoupper((string)$canal)] ?? 'Actividad';
};

$iconoCanal = static function ($canal) {
    $iconos = [
        'LLAMADA_IP' => 'bi-telephone',
        'WHATSAPP' => 'bi-whatsapp',
        'CORREO' => 'bi-envelope',
        'NOTA' => 'bi-journal-text',
        'SISTEMA' => 'bi-arrow-repeat'
    ];

    return $iconos[strtoupper((string)$canal)] ?? 'bi-activity';
};

$textoResultado = static function ($resultado) {
    $etiquetas = [
        'CONTACTADO' => 'Contacto realizado',
        'NO_CONTESTO' => 'Sin respuesta',
        'OCUPADO' => 'Línea ocupada',
        'NUMERO_INCORRECTO' => 'Número incorrecto',
        'SOLICITO_LLAMAR_DESPUES' => 'Solicitó volver a llamar',
        'MENSAJE_ENVIADO' => 'Mensaje enviado',
        'CORREO_ENVIADO' => 'Correo enviado',
        'SIN_RESPUESTA' => 'Sin respuesta',
        'OTRO' => 'Resultado registrado'
    ];

    return $etiquetas[strtoupper((string)$resultado)] ?? '';
};

$totalAtencionVisible = count($atenciones);
$seguimientoIndexUrl = BASE_URL . 'index.php?controller=seguimientoVinculacion&action=index';
?>

<section class="analyst-dashboard" data-analyst-dashboard>
    <div class="analyst-welcome mb-4">
        <div>
            <span class="analyst-welcome-eyebrow">TU JORNADA</span>
            <h2><?= $esc($saludo . ', ' . $nombreAnalista) ?></h2>
            <p><?= $esc($fechaLarga) ?></p>
        </div>

        <div class="analyst-welcome-status">
            <span class="analyst-welcome-status-icon">
                <i class="bi bi-compass"></i>
            </span>
            <div>
                <strong>
                    <?= $totalAtencionVisible > 0
                        ? $totalAtencionVisible . ' ' . ($totalAtencionVisible === 1 ? 'seguimiento requiere' : 'seguimientos requieren') . ' atención'
                        : 'Tu operación está al día' ?>
                </strong>
                <span>
                    <?= $totalAtencionVisible > 0
                        ? 'Ordenados por urgencia para que sepas por dónde continuar.'
                        : 'No hay seguimientos prioritarios detectados en este momento.' ?>
                </span>
            </div>
        </div>
    </div>

    <div class="metric-grid analyst-metric-grid mb-4">
        <article class="metric-card analyst-metric-card">
            <div class="metric-icon">
                <i class="bi bi-kanban"></i>
            </div>
            <div>
                <p class="metric-value"><?= (int)($resumen['en_seguimiento'] ?? 0) ?></p>
                <p class="metric-label">En seguimiento</p>
                <span class="analyst-metric-help">Carga activa actual</span>
            </div>
        </article>

        <article class="metric-card analyst-metric-card">
            <div class="metric-icon analyst-icon-today">
                <i class="bi bi-calendar2-check"></i>
            </div>
            <div>
                <p class="metric-value"><?= (int)($resumen['para_hoy'] ?? 0) ?></p>
                <p class="metric-label">Para hoy</p>
                <span class="analyst-metric-help">Acciones y reuniones del día</span>
            </div>
        </article>

        <article class="metric-card analyst-metric-card">
            <div class="metric-icon analyst-icon-overdue">
                <i class="bi bi-exclamation-circle"></i>
            </div>
            <div>
                <p class="metric-value"><?= (int)($resumen['atrasados'] ?? 0) ?></p>
                <p class="metric-label">Atrasados</p>
                <span class="analyst-metric-help">Requieren atención prioritaria</span>
            </div>
        </article>

        <article class="metric-card analyst-metric-card">
            <div class="metric-icon metric-icon-success">
                <i class="bi bi-people"></i>
            </div>
            <div>
                <p class="metric-value"><?= (int)($resumen['reuniones_proximas'] ?? 0) ?></p>
                <p class="metric-label">Reuniones próximas</p>
                <span class="analyst-metric-help">Siguientes 7 días</span>
            </div>
        </article>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-12 col-xl-8">
            <section class="dashboard-panel analyst-attention-panel h-100">
                <div class="analyst-panel-heading">
                    <div>
                        <span class="analyst-section-kicker">PRIORIDAD OPERATIVA</span>
                        <h2 class="panel-title mb-0">Qué requiere mi atención</h2>
                    </div>
                    <a class="analyst-panel-link" href="<?= $esc($seguimientoIndexUrl) ?>">
                        Ver seguimientos
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <?php if (!empty($atenciones)): ?>
                    <div class="analyst-attention-list">
                        <?php foreach ($atenciones as $item): ?>
                            <?php
                            $tipoAtencion = (string)($item['tipo_atencion'] ?? 'seguimiento');
                            $claseTipo = in_array($tipoAtencion, ['atrasado', 'hoy', 'espera', 'reunion'], true)
                                ? $tipoAtencion
                                : 'seguimiento';
                            $ubicacion = trim((string)($item['municipio'] ?? ''));
                            if ($ubicacion === '') {
                                $ubicacion = trim((string)($item['estado_nombre'] ?? ''));
                            }
                            ?>
                            <article
                                class="analyst-attention-item"
                                data-analyst-attention-item
                                data-seguimiento-id="<?= (int)$item['id'] ?>">
                                <span class="analyst-attention-indicator is-<?= $esc($claseTipo) ?>"></span>

                                <div class="analyst-attention-main">
                                    <div class="analyst-attention-topline">
                                        <strong><?= $esc($item['nombre_entidad'] ?? 'Institución') ?></strong>
                                        <span class="analyst-priority-pill is-<?= $esc($claseTipo) ?>">
                                            <?= $esc($item['motivo_atencion'] ?? 'Revisar seguimiento') ?>
                                        </span>
                                    </div>
                                    <div class="analyst-attention-meta">
                                        <?php if ($ubicacion !== ''): ?>
                                            <span><i class="bi bi-geo-alt"></i><?= $esc($ubicacion) ?></span>
                                        <?php endif; ?>
                                        <span>
                                            <i class="bi bi-clock"></i>
                                            <?= $esc($formatearFechaCorta($item['fecha_referencia'] ?? null)) ?>
                                        </span>
                                    </div>
                                    <span class="analyst-attention-route" data-route-action>
                                        Consultando ruta de vinculación…
                                    </span>
                                </div>

                                <a
                                    class="btn btn-system-light analyst-work-button"
                                    href="<?= $esc($urlSeguimiento($item['estado_id'] ?? 0, $item['id'] ?? 0)) ?>">
                                    Trabajar
                                    <i class="bi bi-arrow-right"></i>
                                </a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="analyst-empty-state">
                        <span><i class="bi bi-check2-circle"></i></span>
                        <div>
                            <strong>No hay pendientes prioritarios</strong>
                            <p>Cuando una acción venza, una reunión esté próxima o un seguimiento lleve varios días sin actividad, aparecerá aquí.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div class="col-12 col-xl-4">
            <section class="dashboard-panel analyst-upcoming-panel h-100">
                <div class="analyst-panel-heading">
                    <div>
                        <span class="analyst-section-kicker">PRÓXIMOS 7 DÍAS</span>
                        <h2 class="panel-title mb-0">Próximamente</h2>
                    </div>
                </div>

                <?php if (!empty($proximos)): ?>
                    <div class="analyst-upcoming-list">
                        <?php foreach ($proximos as $evento): ?>
                            <?php $esReunion = ($evento['tipo_evento'] ?? '') === 'REUNION'; ?>
                            <a
                                class="analyst-upcoming-item"
                                href="<?= $esc($urlSeguimiento($evento['estado_id'] ?? 0, $evento['seguimiento_id'] ?? 0)) ?>">
                                <span class="analyst-upcoming-icon <?= $esReunion ? 'is-meeting' : '' ?>">
                                    <i class="bi <?= $esReunion ? 'bi-people' : 'bi-check2-square' ?>"></i>
                                </span>
                                <div>
                                    <strong><?= $esc($evento['nombre_entidad'] ?? 'Seguimiento') ?></strong>
                                    <span><?= $esReunion ? 'Reunión' : 'Próxima acción' ?> · <?= $esc($formatearFechaCorta($evento['fecha_evento'] ?? null)) ?></span>
                                </div>
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="analyst-empty-state analyst-empty-state-small">
                        <span><i class="bi bi-calendar2"></i></span>
                        <div>
                            <strong>Sin compromisos próximos</strong>
                            <p>No hay acciones ni reuniones programadas para los siguientes 7 días.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <section class="dashboard-panel analyst-progress-panel mb-4">
        <div class="analyst-panel-heading analyst-progress-heading">
            <div>
                <span class="analyst-section-kicker">ACTIVIDAD DEL MES</span>
                <h2 class="panel-title mb-0">Mi avance en vinculación</h2>
                <p>Lectura rápida de las acciones que has logrado durante <?= $esc($meses[(int)$ahora->format('n')]) ?>.</p>
            </div>
            <span class="analyst-interaction-total">
                <strong><?= (int)($avanceMes['interacciones'] ?? 0) ?></strong>
                interacciones registradas
            </span>
        </div>

        <div class="analyst-progress-grid">
            <div class="analyst-progress-step">
                <span class="analyst-progress-number">01</span>
                <div class="analyst-progress-icon"><i class="bi bi-chat-dots"></i></div>
                <strong><?= (int)($avanceMes['contactos_efectivos'] ?? 0) ?></strong>
                <span>Contactos efectivos</span>
            </div>
            <div class="analyst-progress-line"></div>
            <div class="analyst-progress-step">
                <span class="analyst-progress-number">02</span>
                <div class="analyst-progress-icon"><i class="bi bi-patch-check"></i></div>
                <strong><?= (int)($avanceMes['datos_verificados'] ?? 0) ?></strong>
                <span>Datos verificados</span>
            </div>
            <div class="analyst-progress-line"></div>
            <div class="analyst-progress-step">
                <span class="analyst-progress-number">03</span>
                <div class="analyst-progress-icon"><i class="bi bi-send-check"></i></div>
                <strong><?= (int)($avanceMes['oficios_enviados'] ?? 0) ?></strong>
                <span>Oficios enviados</span>
            </div>
            <div class="analyst-progress-line"></div>
            <div class="analyst-progress-step">
                <span class="analyst-progress-number">04</span>
                <div class="analyst-progress-icon"><i class="bi bi-people"></i></div>
                <strong><?= (int)($avanceMes['reuniones_realizadas'] ?? 0) ?></strong>
                <span>Reuniones realizadas</span>
            </div>
        </div>
    </section>

    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <section class="dashboard-panel analyst-activity-panel h-100">
                <div class="analyst-panel-heading">
                    <div>
                        <span class="analyst-section-kicker">TRAZABILIDAD</span>
                        <h2 class="panel-title mb-0">Actividad reciente</h2>
                    </div>
                </div>

                <?php if (!empty($actividad)): ?>
                    <div class="analyst-activity-list">
                        <?php foreach ($actividad as $registro): ?>
                            <?php
                            $canal = (string)($registro['canal'] ?? '');
                            $resultado = $textoResultado($registro['resultado'] ?? '');
                            ?>
                            <a
                                class="analyst-activity-item"
                                href="<?= $esc($urlSeguimiento($registro['estado_id'] ?? 0, $registro['seguimiento_id'] ?? 0)) ?>">
                                <span class="analyst-activity-icon">
                                    <i class="bi <?= $esc($iconoCanal($canal)) ?>"></i>
                                </span>
                                <div class="analyst-activity-copy">
                                    <strong><?= $esc($registro['nombre_entidad'] ?? 'Institución') ?></strong>
                                    <span>
                                        <?= $esc($etiquetaCanal($canal)) ?>
                                        <?= $resultado !== '' ? ' · ' . $esc($resultado) : '' ?>
                                    </span>
                                </div>
                                <time><?= $esc($formatearFechaCorta($registro['fecha_inicio'] ?? null)) ?></time>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="analyst-empty-state analyst-empty-state-small">
                        <span><i class="bi bi-clock-history"></i></span>
                        <div>
                            <strong>Aún no hay actividad reciente</strong>
                            <p>Las interacciones que registres en Vinculación se mostrarán aquí.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div class="col-12 col-xl-5">
            <section class="dashboard-panel analyst-territory-panel h-100">
                <div class="analyst-panel-heading">
                    <div>
                        <span class="analyst-section-kicker">COBERTURA</span>
                        <h2 class="panel-title mb-0">Mis territorios</h2>
                    </div>
                </div>

                <?php if (!empty($territorios)): ?>
                    <div class="analyst-territory-list">
                        <?php foreach ($territorios as $territorio): ?>
                            <a
                                class="analyst-territory-item"
                                href="<?= $esc($urlSeguimiento($territorio['id'] ?? 0)) ?>">
                                <span class="analyst-territory-icon"><i class="bi bi-geo-alt"></i></span>
                                <div>
                                    <strong><?= $esc($territorio['nombre'] ?? 'Territorio') ?></strong>
                                    <span>
                                        <?= (int)($territorio['seguimientos_activos'] ?? 0) ?>
                                        <?= (int)($territorio['seguimientos_activos'] ?? 0) === 1 ? 'seguimiento activo' : 'seguimientos activos' ?>
                                    </span>
                                </div>
                                <?php if ((int)($territorio['es_principal'] ?? 0) === 1): ?>
                                    <span class="analyst-territory-primary">Principal</span>
                                <?php endif; ?>
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="analyst-empty-state analyst-empty-state-small">
                        <span><i class="bi bi-geo"></i></span>
                        <div>
                            <strong>Sin territorios activos</strong>
                            <p>Cuando tengas un territorio asignado aparecerá aquí junto con su carga de seguimiento.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>
