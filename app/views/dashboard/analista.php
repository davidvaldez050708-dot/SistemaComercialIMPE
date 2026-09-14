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
$mesesCortos = [
    1 => 'ENE', 2 => 'FEB', 3 => 'MAR', 4 => 'ABR', 5 => 'MAY', 6 => 'JUN',
    7 => 'JUL', 8 => 'AGO', 9 => 'SEP', 10 => 'OCT', 11 => 'NOV', 12 => 'DIC'
];
$diasSemana = [
    1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves',
    5 => 'viernes', 6 => 'sábado', 7 => 'domingo'
];
$diasSemanaCortos = [
    1 => 'LUN', 2 => 'MAR', 3 => 'MIÉ', 4 => 'JUE', 5 => 'VIE', 6 => 'SÁB', 7 => 'DOM'
];

$ahora = new DateTimeImmutable();
$fechaLarga = ucfirst($diasSemana[(int)$ahora->format('N')]) . ', ' .
    $ahora->format('j') . ' de ' . $meses[(int)$ahora->format('n')] . ' de ' . $ahora->format('Y');

$esc = static function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$urlSeguimiento = static function ($estadoId, $seguimientoId = 0) {
    $url = BASE_URL . 'index.php?controller=seguimientoVinculacion&action=estado&estado_id=' . (int)$estadoId;

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

$formatearFechaAgenda = static function ($valor) use ($diasSemanaCortos, $mesesCortos) {
    try {
        $fecha = new DateTimeImmutable((string)$valor);
        return [
            'dia' => $diasSemanaCortos[(int)$fecha->format('N')] ?? '',
            'fecha' => $fecha->format('j') . ' ' . ($mesesCortos[(int)$fecha->format('n')] ?? ''),
            'hora' => $fecha->format('H:i')
        ];
    } catch (Throwable $error) {
        return ['dia' => '', 'fecha' => '', 'hora' => ''];
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

$totalAtencion = (int)($resumen['requieren_atencion'] ?? count($atenciones));
$seguimientoIndexUrl = BASE_URL . 'index.php?controller=seguimientoVinculacion&action=index';
$agendaUrl = BASE_URL . 'index.php?controller=agendaReunion&action=index';
$territoriosUrl = BASE_URL . 'index.php?controller=territorio&action=index';

$metricasClave = [
    [
        'valor' => (int)($resumen['en_seguimiento'] ?? 0),
        'etiqueta' => 'En seguimiento',
        'ayuda' => 'Carga activa actual',
        'clase' => ''
    ],
    [
        'valor' => (int)($resumen['para_hoy'] ?? 0),
        'etiqueta' => 'Para hoy',
        'ayuda' => 'Acciones y reuniones del día',
        'clase' => ''
    ],
    [
        'valor' => (int)($resumen['atrasados'] ?? 0),
        'etiqueta' => 'Atrasados',
        'ayuda' => 'Requieren atención prioritaria',
        'clase' => 'is-overdue'
    ],
    [
        'valor' => (int)($resumen['reuniones_proximas'] ?? 0),
        'etiqueta' => 'Reuniones próximas',
        'ayuda' => 'Siguientes 7 días',
        'clase' => ''
    ]
];
$baseMetrica = max(1, (int)($resumen['en_seguimiento'] ?? 0));

$metricasMes = [
    ['valor' => (int)($avanceMes['contactos_efectivos'] ?? 0), 'etiqueta' => 'Contactos efectivos'],
    ['valor' => (int)($avanceMes['datos_verificados'] ?? 0), 'etiqueta' => 'Datos verificados'],
    ['valor' => (int)($avanceMes['oficios_enviados'] ?? 0), 'etiqueta' => 'Oficios enviados'],
    ['valor' => (int)($avanceMes['reuniones_realizadas'] ?? 0), 'etiqueta' => 'Reuniones realizadas']
];
$maxMetricaMes = max(1, ...array_map(static function ($metrica) {
    return (int)$metrica['valor'];
}, $metricasMes));
?>

<section class="analyst-dashboard analyst-dashboard-v2" data-analyst-dashboard>
    <section class="analyst-welcome">
        <div class="analyst-welcome-copy">
            <span class="analyst-welcome-eyebrow">TU JORNADA</span>
            <h2><?= $esc($saludo . ', ' . $nombreAnalista) ?></h2>
            <p><?= $esc($fechaLarga) ?></p>
            <p class="analyst-welcome-tagline">Sigamos impulsando la vinculación educativa.</p>
        </div>

        <div class="analyst-welcome-status-zone">
            <div class="analyst-welcome-status <?= $totalAtencion > 0 ? 'is-attention' : 'is-clear' ?>">
                <span class="analyst-welcome-status-icon" aria-hidden="true"><i class="bi"></i></span>
                <strong>
                    <?= $totalAtencion > 0
                        ? $totalAtencion . ' ' . ($totalAtencion === 1 ? 'seguimiento requiere' : 'seguimientos requieren') . ' atención'
                        : 'Tu operación está al día' ?>
                </strong>
                <i class="bi bi-chevron-right analyst-welcome-status-arrow" aria-hidden="true"></i>
            </div>
            <span class="analyst-welcome-status-copy">
                <?= $totalAtencion > 0
                    ? 'Revisa tus principales pendientes para mantener el avance.'
                    : 'No hay seguimientos prioritarios detectados en este momento.' ?>
            </span>
        </div>
    </section>

    <section class="analyst-kpi-panel">
        <div class="analyst-kpi-heading">
            <h2>INDICADORES CLAVE</h2>
            <p>Estado actual de tus seguimientos</p>
        </div>

        <?php foreach ($metricasClave as $metrica): ?>
            <?php $porcentaje = min(100, max(0, (int)round(((int)$metrica['valor'] / $baseMetrica) * 100))); ?>
            <article class="analyst-metric-card <?= $esc($metrica['clase']) ?>">
                <p class="metric-value"><?= (int)$metrica['valor'] ?></p>
                <p class="metric-label"><?= $esc($metrica['etiqueta']) ?></p>
                <div class="analyst-metric-track" aria-hidden="true">
                    <span style="width: <?= $porcentaje ?>%;"></span>
                </div>
                <span class="analyst-metric-help"><?= $esc($metrica['ayuda']) ?></span>
            </article>
        <?php endforeach; ?>

        <div class="analyst-kpi-action">
            <a href="<?= $esc($seguimientoIndexUrl) ?>">
                <span class="analyst-kpi-action-icon"><i class="bi bi-list-task"></i></span>
                <span>Ver todos los<br>seguimientos</span>
                <i class="bi bi-arrow-right"></i>
            </a>
        </div>
    </section>

    <div class="analyst-main-grid">
        <section class="dashboard-panel analyst-attention-panel">
            <div class="analyst-panel-heading">
                <div>
                    <span class="analyst-section-kicker">PRIORIDAD OPERATIVA</span>
                    <h2 class="panel-title mb-0">Seguimientos que requieren tu atención</h2>
                </div>
                <a class="analyst-panel-link" href="<?= $esc($seguimientoIndexUrl) ?>">Ver todos <i class="bi bi-arrow-right"></i></a>
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
                                    <span><i class="bi bi-clock"></i><?= $esc($formatearFechaCorta($item['fecha_referencia'] ?? null)) ?></span>
                                </div>
                                <span class="analyst-attention-route" data-route-action>Consultando ruta de vinculación…</span>
                            </div>

                            <a
                                class="btn btn-system-light analyst-work-button"
                                href="<?= $esc($urlSeguimiento($item['estado_id'] ?? 0, $item['id'] ?? 0)) ?>">
                                Trabajar <i class="bi bi-arrow-right"></i>
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

        <section class="dashboard-panel analyst-upcoming-panel">
            <div class="analyst-panel-heading">
                <div>
                    <span class="analyst-section-kicker">PRÓXIMAMENTE</span>
                    <h2 class="panel-title mb-0">Tu agenda de los próximos 7 días</h2>
                </div>
                <a class="analyst-panel-link" href="<?= $esc($agendaUrl) ?>">Ver agenda <i class="bi bi-arrow-right"></i></a>
            </div>

            <?php if (!empty($proximos)): ?>
                <div class="analyst-upcoming-list">
                    <?php foreach ($proximos as $evento): ?>
                        <?php
                        $esReunion = ($evento['tipo_evento'] ?? '') === 'REUNION';
                        $fechaAgenda = $formatearFechaAgenda($evento['fecha_evento'] ?? null);
                        $tituloEvento = $esReunion
                            ? 'Reunión con ' . (string)($evento['nombre_entidad'] ?? 'institución')
                            : (string)($evento['nombre_entidad'] ?? 'Próxima acción');
                        ?>
                        <a
                            class="analyst-upcoming-item"
                            href="<?= $esc($urlSeguimiento($evento['estado_id'] ?? 0, $evento['seguimiento_id'] ?? 0)) ?>">
                            <span class="analyst-upcoming-icon analyst-upcoming-date <?= $esReunion ? 'is-meeting' : '' ?>">
                                <i class="bi <?= $esReunion ? 'bi-people' : 'bi-check2-square' ?>"></i>
                                <span>
                                    <strong><?= $esc($fechaAgenda['dia']) ?></strong>
                                    <small><?= $esc($fechaAgenda['fecha']) ?></small>
                                </span>
                            </span>
                            <div>
                                <strong><?= $esc($tituloEvento) ?></strong>
                                <span><?= $esReunion ? 'Reunión' : 'Próxima acción' ?> · <?= $esc($formatearFechaCorta($evento['fecha_evento'] ?? null)) ?></span>
                            </div>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="analyst-upcoming-footer">
                    <a href="<?= $esc($agendaUrl) ?>">Ver todos los eventos <i class="bi bi-arrow-right"></i></a>
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

    <div class="analyst-bottom-grid">
        <section class="dashboard-panel analyst-progress-panel">
            <div class="analyst-panel-heading analyst-progress-heading">
                <div>
                    <span class="analyst-section-kicker">ACTIVIDAD DEL MES</span>
                    <h2 class="panel-title mb-0">Avance de tus acciones de vinculación</h2>
                </div>
                <span class="analyst-interaction-total">
                    <strong><?= (int)($avanceMes['interacciones'] ?? 0) ?></strong> interacciones · <?= $esc(ucfirst($meses[(int)$ahora->format('n')]) . ' ' . $ahora->format('Y')) ?>
                </span>
            </div>

            <div class="analyst-progress-grid">
                <?php foreach ($metricasMes as $indice => $metrica): ?>
                    <?php $porcentajeMes = min(100, max(0, (int)round(((int)$metrica['valor'] / $maxMetricaMes) * 100))); ?>
                    <div class="analyst-progress-step">
                        <span class="analyst-progress-number"><?= str_pad((string)($indice + 1), 2, '0', STR_PAD_LEFT) ?></span>
                        <div class="analyst-progress-icon"><i class="bi bi-circle"></i></div>
                        <strong><?= (int)$metrica['valor'] ?></strong>
                        <span><?= $esc($metrica['etiqueta']) ?></span>
                        <div class="analyst-month-track" aria-hidden="true"><span style="width: <?= $porcentajeMes ?>%;"></span></div>
                        <span class="analyst-month-caption">Avance registrado</span>
                    </div>
                    <?php if ($indice < count($metricasMes) - 1): ?><div class="analyst-progress-line"></div><?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="dashboard-panel analyst-activity-panel">
            <div class="analyst-panel-heading">
                <div>
                    <span class="analyst-section-kicker">ACTIVIDAD RECIENTE</span>
                    <h2 class="panel-title mb-0">Últimas acciones registradas</h2>
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
                            <span class="analyst-activity-icon"><i class="bi <?= $esc($iconoCanal($canal)) ?>"></i></span>
                            <div class="analyst-activity-copy">
                                <strong><?= $esc($registro['nombre_entidad'] ?? 'Institución') ?></strong>
                                <span><?= $esc($etiquetaCanal($canal)) ?><?= $resultado !== '' ? ' · ' . $esc($resultado) : '' ?></span>
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

        <section class="dashboard-panel analyst-territory-panel">
            <div class="analyst-panel-heading">
                <div>
                    <span class="analyst-section-kicker">MIS TERRITORIOS</span>
                    <h2 class="panel-title mb-0">Seguimientos activos por territorio</h2>
                </div>
                <a class="analyst-panel-link" href="<?= $esc($territoriosUrl) ?>">Ver territorios <i class="bi bi-arrow-right"></i></a>
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
</section>
