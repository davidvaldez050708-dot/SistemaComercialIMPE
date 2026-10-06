<?php

$tableroCuentaClave = is_array($tableroCuentaClave ?? null)
    ? $tableroCuentaClave
    : [];

$resumen = is_array($tableroCuentaClave['resumen'] ?? null)
    ? $tableroCuentaClave['resumen']
    : [];
$atenciones = is_array($tableroCuentaClave['atenciones'] ?? null)
    ? $tableroCuentaClave['atenciones']
    : [];
$atencionesTotal = (int)(
    $tableroCuentaClave['atenciones_total'] ?? count($atenciones)
);
$analistas = is_array($tableroCuentaClave['analistas'] ?? null)
    ? $tableroCuentaClave['analistas']
    : [];
$agenda = is_array($tableroCuentaClave['agenda'] ?? null)
    ? $tableroCuentaClave['agenda']
    : [];
$agendaTotal = (int)(
    $tableroCuentaClave['agenda_total'] ?? count($agenda)
);
$aliados = is_array($tableroCuentaClave['aliados'] ?? null)
    ? $tableroCuentaClave['aliados']
    : [];
$cobertura = is_array($tableroCuentaClave['cobertura'] ?? null)
    ? $tableroCuentaClave['cobertura']
    : [];
$permisos = is_array($tableroCuentaClave['permisos'] ?? null)
    ? $tableroCuentaClave['permisos']
    : [];

$puedeSeguimiento = (bool)($permisos['seguimiento'] ?? false);
$puedeAliados = (bool)($permisos['aliados'] ?? false);
$puedeAgendaAliados = (bool)(
    $permisos['agenda_aliados'] ?? false
);
$puedeTerritorios = function_exists('tienePermiso')
    ? tienePermiso('territorios.ver')
    : false;

$esc = static function ($valor) {
    return htmlspecialchars(
        (string)$valor,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
};

$nombreCuentaClave = trim((string)($_SESSION['nombre'] ?? ''));
if ($nombreCuentaClave === '') {
    $nombreCuentaClave = trim(
        (string)($_SESSION['usuario'] ?? 'Cuenta Clave')
    );
}

$horaActual = (int)date('G');
$saludo = $horaActual < 12
    ? 'Buenos días'
    : ($horaActual < 19 ? 'Buenas tardes' : 'Buenas noches');

$meses = [
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

$diasSemana = [
    1 => 'lunes',
    2 => 'martes',
    3 => 'miércoles',
    4 => 'jueves',
    5 => 'viernes',
    6 => 'sábado',
    7 => 'domingo'
];

$ahora = new DateTimeImmutable();
$fechaLarga =
    ucfirst($diasSemana[(int)$ahora->format('N')]) .
    ', ' .
    $ahora->format('j') .
    ' de ' .
    $meses[(int)$ahora->format('n')] .
    ' de ' .
    $ahora->format('Y');

$formatearFecha = static function ($valor) use ($meses) {
    $valor = trim((string)$valor);

    if ($valor === '') {
        return 'Sin fecha';
    }

    try {
        $fecha = new DateTimeImmutable($valor);
        $hoy = new DateTimeImmutable('today');
        $manana = $hoy->modify('+1 day');

        if ($fecha->format('Y-m-d') === $hoy->format('Y-m-d')) {
            return 'Hoy · ' . $fecha->format('H:i');
        }

        if ($fecha->format('Y-m-d') === $manana->format('Y-m-d')) {
            return 'Mañana · ' . $fecha->format('H:i');
        }

        return
            $fecha->format('j') .
            ' ' .
            substr($meses[(int)$fecha->format('n')], 0, 3) .
            ' · ' .
            $fecha->format('H:i');
    } catch (Throwable $error) {
        return $valor;
    }
};

$formatearActividad = static function ($valor) {
    $valor = trim((string)$valor);

    if ($valor === '') {
        return 'Sin actividad registrada';
    }

    try {
        $fecha = new DateTimeImmutable($valor);
        $hoy = new DateTimeImmutable('today');
        $ayer = $hoy->modify('-1 day');

        if ($fecha->format('Y-m-d') === $hoy->format('Y-m-d')) {
            return 'Actividad hoy · ' . $fecha->format('H:i');
        }

        if ($fecha->format('Y-m-d') === $ayer->format('Y-m-d')) {
            return 'Última actividad ayer';
        }

        return 'Última actividad · ' . $fecha->format('d/m/Y');
    } catch (Throwable $error) {
        return 'Actividad registrada';
    }
};

$iniciales = static function ($nombre) {
    $partes = preg_split(
        '/\s+/u',
        trim((string)$nombre),
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    if (empty($partes)) {
        return 'A';
    }

    $resultado = '';
    foreach (array_slice($partes, 0, 2) as $parte) {
        $resultado .= function_exists('mb_substr')
            ? mb_substr($parte, 0, 1, 'UTF-8')
            : substr($parte, 0, 1);
    }

    return strtoupper($resultado);
};

$totalAtencionEquipo =
    (int)($resumen['requieren_atencion'] ?? $atencionesTotal);
$totalAccionesPropias =
    $puedeAgendaAliados
        ? (int)($resumen['acciones_propias'] ?? $agendaTotal)
        : 0;

$estadoGeneralAtencion =
    $totalAtencionEquipo > 0 || $totalAccionesPropias > 0;

if ($totalAtencionEquipo > 0 && $totalAccionesPropias > 0) {
    $estadoGeneralTitulo =
        $totalAtencionEquipo .
        ($totalAtencionEquipo === 1
            ? ' caso del equipo'
            : ' casos del equipo') .
        ' · ' .
        $totalAccionesPropias .
        ($totalAccionesPropias === 1
            ? ' acción tuya'
            : ' acciones tuyas');
    $estadoGeneralDetalle =
        'Separa lo que debes supervisar de las acciones que te corresponden directamente.';
} elseif ($totalAtencionEquipo > 0) {
    $estadoGeneralTitulo =
        $totalAtencionEquipo .
        ($totalAtencionEquipo === 1
            ? ' caso del equipo requiere supervisión'
            : ' casos del equipo requieren supervisión');
    $estadoGeneralDetalle = $puedeAgendaAliados
        ? 'Tus acciones programadas con aliados están al día.'
        : 'Prioriza la cartera de vinculación que requiere seguimiento.';
} elseif ($totalAccionesPropias > 0) {
    $estadoGeneralTitulo =
        $totalAccionesPropias .
        ($totalAccionesPropias === 1
            ? ' acción tuya próxima'
            : ' acciones tuyas próximas');
    $estadoGeneralDetalle =
        'Tu equipo no presenta casos prioritarios; revisa tu agenda con aliados.';
} else {
    $estadoGeneralTitulo = 'Tu operación está al día';
    $estadoGeneralDetalle =
        'No hay casos prioritarios ni acciones programadas que requieran atención inmediata.';
}

$seguimientoUrl =
    BASE_URL .
    'index.php?controller=seguimientoVinculacion&action=index';
$aliadosUrl =
    BASE_URL .
    'index.php?controller=aliado&action=index';
$territoriosUrl =
    BASE_URL .
    'index.php?controller=territorio&action=index';

$metricas = [
    [
        'valor' => (int)($resumen['analistas'] ?? 0),
        'etiqueta' => 'Analistas vinculados',
        'ayuda' => 'Equipo dentro de tu alcance',
        'icono' => 'bi-people',
        'clase' => ''
    ],
    [
        'valor' => (int)($resumen['seguimientos_activos'] ?? 0),
        'etiqueta' => 'Cartera supervisada',
        'ayuda' => 'Vinculaciones activas del equipo',
        'icono' => 'bi-list-check',
        'clase' => ''
    ],
    [
        'valor' => $totalAtencionEquipo,
        'etiqueta' => 'Atención del equipo',
        'ayuda' => 'Casos prioritarios para supervisar',
        'icono' => 'bi-exclamation-circle',
        'clase' => $totalAtencionEquipo > 0
            ? 'is-attention'
            : 'is-clear'
    ]
];

if ($puedeAgendaAliados) {
    $metricas[] = [
        'valor' => $totalAccionesPropias,
        'etiqueta' => 'Tus próximas acciones',
        'ayuda' => 'Agenda con aliados a 7 días',
        'icono' => 'bi-calendar-check',
        'clase' => $totalAccionesPropias > 0
            ? 'is-personal'
            : 'is-clear'
    ];
}

if ($puedeAliados) {
    $metricas[] = [
        'valor' => (int)($resumen['aliados'] ?? 0),
        'etiqueta' => 'Aliados institucionales',
        'ayuda' => 'Convenios formalizados en tu red',
        'icono' => 'bi-building-check',
        'clase' => 'is-allies'
    ];
}

$itemsCobertura = is_array($cobertura['items'] ?? null)
    ? $cobertura['items']
    : [];
$territoriosTotal = (int)(
    $cobertura['territorios_total']
        ?? $resumen['territorios']
        ?? 0
);
$territoriosSinActividad = (int)(
    $cobertura['sin_actividad'] ?? 0
);
$mostrarAnalistasCobertura = count($analistas) > 1;
$mostrarZonaPrincipal =
    $puedeSeguimiento || $puedeAgendaAliados;
?>

<section class="kam-dashboard" data-kam-dashboard>
    <section class="kam-welcome">
        <div class="kam-welcome-copy">
            <span class="kam-eyebrow">TU OPERACIÓN</span>
            <h2><?= $esc($saludo . ', ' . $nombreCuentaClave) ?></h2>
            <p><?= $esc($fechaLarga) ?></p>
            <p class="kam-welcome-tagline">
                Supervisa la vinculación de tu equipo y atiende la relación con tu red de aliados.
            </p>
        </div>

        <div class="kam-welcome-status-zone">
            <div class="kam-welcome-status <?= $estadoGeneralAtencion ? 'is-attention' : 'is-clear' ?>">
                <span class="kam-welcome-status-icon" aria-hidden="true">
                    <i class="bi <?= $estadoGeneralAtencion ? 'bi-exclamation-circle' : 'bi-check-circle' ?>"></i>
                </span>
                <div>
                    <strong><?= $esc($estadoGeneralTitulo) ?></strong>
                    <span><?= $esc($estadoGeneralDetalle) ?></span>
                </div>
            </div>
        </div>
    </section>

    <section class="kam-kpi-section" aria-label="Resumen operativo">
        <div class="kam-kpi-section-heading">
            <span class="kam-eyebrow">RESUMEN OPERATIVO</span>
            <div>
                <h2>Lo que necesitas vigilar hoy</h2>
                <p>
                    Distingue la supervisión de tu equipo de las acciones que te corresponden directamente.
                </p>
            </div>
        </div>

        <div class="kam-kpi-grid">
            <?php foreach ($metricas as $metrica): ?>
                <article class="kam-metric-card <?= $esc($metrica['clase']) ?>">
                    <span class="kam-metric-icon" aria-hidden="true">
                        <i class="bi <?= $esc($metrica['icono']) ?>"></i>
                    </span>
                    <div>
                        <strong><?= (int)$metrica['valor'] ?></strong>
                        <span><?= $esc($metrica['etiqueta']) ?></span>
                        <small><?= $esc($metrica['ayuda']) ?></small>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($mostrarZonaPrincipal): ?>
        <div class="kam-primary-grid <?= !$puedeSeguimiento ? 'is-single' : '' ?>">
            <?php if ($puedeSeguimiento): ?>
                <section class="dashboard-panel kam-attention-panel">
                    <div class="kam-panel-heading">
                        <div>
                            <span class="kam-section-kicker">CARTERA DEL EQUIPO</span>
                            <h2 class="panel-title mb-1">
                                Casos que requieren supervisión
                            </h2>
                            <p class="page-subtitle mb-0">
                                Prioridades de todos los Analistas vinculados dentro de tu alcance.
                            </p>
                        </div>
                        <span class="kam-panel-count <?= $atencionesTotal > 0 ? 'is-attention' : '' ?>">
                            <?= $atencionesTotal ?>
                            <?= $atencionesTotal === 1 ? 'caso' : 'casos' ?>
                        </span>
                    </div>

                    <?php if (!empty($atenciones)): ?>
                        <div class="kam-attention-list">
                            <?php foreach ($atenciones as $item): ?>
                                <?php
                                $tipo = (string)($item['tipo_atencion'] ?? 'seguimiento');
                                $tipoClase = in_array(
                                    $tipo,
                                    ['atrasado', 'hoy', 'espera', 'reunion'],
                                    true
                                )
                                    ? $tipo
                                    : 'seguimiento';
                                ?>
                                <a
                                    class="kam-attention-item"
                                    href="<?= $esc($item['url'] ?? $seguimientoUrl) ?>">
                                    <span class="kam-attention-indicator is-<?= $esc($tipoClase) ?>"></span>
                                    <div class="kam-attention-main">
                                        <div class="kam-attention-title">
                                            <strong>
                                                <?= $esc($item['nombre_entidad'] ?? 'Institución') ?>
                                            </strong>
                                            <span class="kam-priority-pill is-<?= $esc($tipoClase) ?>">
                                                <?= $esc($item['motivo_atencion'] ?? 'Revisar seguimiento') ?>
                                            </span>
                                        </div>
                                        <div class="kam-attention-meta">
                                            <span>
                                                <i class="bi bi-person"></i>
                                                <?= $esc($item['analista_nombre'] ?? 'Analista') ?>
                                            </span>
                                            <span>
                                                <i class="bi bi-geo-alt"></i>
                                                <?= $esc(
                                                    trim((string)($item['municipio'] ?? '')) !== ''
                                                        ? $item['municipio']
                                                        : ($item['estado_nombre'] ?? 'Territorio')
                                                ) ?>
                                            </span>
                                            <?php if (!empty($item['fecha_referencia'])): ?>
                                                <span>
                                                    <i class="bi bi-clock"></i>
                                                    <?= $esc($formatearFecha($item['fecha_referencia'])) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <i class="bi bi-chevron-right kam-row-arrow"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <div class="kam-panel-footer-action">
                            <a href="<?= $esc($seguimientoUrl) ?>">
                                <?= $atencionesTotal > count($atenciones)
                                    ? 'Ver los ' . $atencionesTotal . ' casos que requieren atención'
                                    : 'Abrir seguimiento del equipo' ?>
                                <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="kam-empty-state">
                            <span><i class="bi bi-check2-circle"></i></span>
                            <div>
                                <strong>Sin casos prioritarios del equipo.</strong>
                                <p>
                                    La cartera de tus Analistas no presenta acciones que requieran supervisión inmediata.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <div class="kam-right-stack">
                <?php if ($puedeSeguimiento): ?>
                    <section class="dashboard-panel kam-team-panel">
                        <div class="kam-panel-heading">
                            <div>
                                <span class="kam-section-kicker">EQUIPO DE VINCULACIÓN</span>
                                <h2 class="panel-title mb-1">Carga operativa</h2>
                                <p class="page-subtitle mb-0">
                                    Preparado para uno o varios Analistas sin cambiar la estructura del tablero.
                                </p>
                            </div>
                            <span class="kam-panel-count">
                                <?= count($analistas) ?>
                                <?= count($analistas) === 1 ? 'Analista' : 'Analistas' ?>
                            </span>
                        </div>

                        <?php if (!empty($analistas)): ?>
                            <div class="kam-team-list">
                                <?php foreach ($analistas as $analista): ?>
                                    <?php
                                    $foto = trim((string)($analista['foto_perfil'] ?? ''));
                                    $fotoUrl = $foto !== ''
                                        ? BASE_URL . ltrim($foto, '/')
                                        : '';
                                    $territoriosAnalista = is_array(
                                        $analista['territorios'] ?? null
                                    )
                                        ? $analista['territorios']
                                        : [];
                                    $territoriosAnalistaTotal = (int)(
                                        $analista['territorios_total']
                                            ?? count($territoriosAnalista)
                                    );

                                    if ($territoriosAnalistaTotal === 0) {
                                        $territorioTexto = 'Sin territorio activo';
                                    } elseif ($territoriosAnalistaTotal <= 2) {
                                        $nombres = array_map(
                                            static fn($territorio) =>
                                                (string)($territorio['nombre'] ?? ''),
                                            $territoriosAnalista
                                        );
                                        $territorioTexto = implode(
                                            ' · ',
                                            array_filter($nombres)
                                        );
                                    } else {
                                        $territorioTexto =
                                            $territoriosAnalistaTotal .
                                            ' territorios asignados';
                                    }
                                    ?>
                                    <article class="kam-team-item">
                                        <div class="kam-team-person">
                                            <span class="kam-team-avatar">
                                                <?php if ($fotoUrl !== ''): ?>
                                                    <img
                                                        src="<?= $esc($fotoUrl) ?>"
                                                        alt="<?= $esc($analista['nombre'] ?? 'Analista') ?>">
                                                <?php else: ?>
                                                    <?= $esc($iniciales($analista['nombre'] ?? 'Analista')) ?>
                                                <?php endif; ?>
                                            </span>
                                            <div>
                                                <strong><?= $esc($analista['nombre'] ?? 'Analista') ?></strong>
                                                <span><?= $esc($territorioTexto) ?></span>
                                            </div>
                                        </div>

                                        <div class="kam-team-metrics">
                                            <div>
                                                <strong><?= (int)($analista['seguimientos'] ?? 0) ?></strong>
                                                <span>Cartera</span>
                                            </div>
                                            <div class="<?= (int)($analista['requieren_atencion'] ?? 0) > 0 ? 'is-attention' : '' ?>">
                                                <strong><?= (int)($analista['requieren_atencion'] ?? 0) ?></strong>
                                                <span>Atención</span>
                                            </div>
                                            <div>
                                                <strong><?= (int)($analista['para_hoy'] ?? 0) ?></strong>
                                                <span>Para hoy</span>
                                            </div>
                                        </div>

                                        <div class="kam-team-footer">
                                            <span>
                                                <?= $esc($formatearActividad($analista['ultima_actividad_at'] ?? '')) ?>
                                            </span>
                                            <a href="<?= $esc($analista['cartera_url'] ?? $seguimientoUrl) ?>">
                                                <?= $esc($analista['cartera_url_label'] ?? 'Ver cartera') ?>
                                                <i class="bi bi-arrow-right"></i>
                                            </a>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="kam-empty-state is-compact">
                                <span><i class="bi bi-person-plus"></i></span>
                                <div>
                                    <strong>Aún no hay Analistas vinculados.</strong>
                                    <p>
                                        Cuando se asigne uno o más Analistas a tus territorios, su carga aparecerá aquí.
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if ($puedeAgendaAliados): ?>
                    <section class="dashboard-panel kam-agenda-panel">
                        <div class="kam-panel-heading">
                            <div>
                                <span class="kam-section-kicker">TU AGENDA OPERATIVA</span>
                                <h2 class="panel-title mb-1">Próximas acciones con aliados</h2>
                                <p class="page-subtitle mb-0">
                                    Acciones asignadas directamente a ti, separadas de la cartera de tus Analistas.
                                </p>
                            </div>
                            <span class="kam-panel-count <?= $agendaTotal > 0 ? 'is-personal' : '' ?>">
                                <?= $agendaTotal ?>
                                <?= $agendaTotal === 1 ? 'acción' : 'acciones' ?>
                            </span>
                        </div>

                        <?php if (!empty($agenda)): ?>
                            <div class="kam-agenda-list">
                                <?php foreach ($agenda as $accion): ?>
                                    <a
                                        class="kam-agenda-item"
                                        href="<?= $esc($accion['url'] ?? $aliadosUrl) ?>">
                                        <span class="kam-agenda-time is-<?= $esc($accion['estado_tiempo'] ?? 'proxima') ?>">
                                            <strong><?= $esc($accion['etiqueta_tiempo'] ?? 'Próxima') ?></strong>
                                            <small><?= $esc($formatearFecha($accion['fecha'] ?? '')) ?></small>
                                        </span>
                                        <div class="kam-agenda-main">
                                            <strong><?= $esc($accion['institucion'] ?? 'Aliado') ?></strong>
                                            <span><?= $esc($accion['accion'] ?? 'Dar seguimiento') ?></span>
                                            <?php if (trim((string)($accion['convocatoria'] ?? '')) !== ''): ?>
                                                <small>
                                                    Convocatoria: <?= $esc($accion['convocatoria']) ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($agendaTotal > count($agenda)): ?>
                                <div class="kam-panel-footer-action">
                                    <a href="<?= $esc($aliadosUrl) ?>">
                                        Ver agenda completa en Aliados
                                        <i class="bi bi-arrow-right"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="kam-inline-clear is-agenda">
                                <span class="kam-inline-clear-icon">
                                    <i class="bi bi-check-circle"></i>
                                </span>
                                <div>
                                    <strong>Tu agenda está al día.</strong>
                                    <span>
                                        No tienes contactos programados con aliados para los próximos 7 días.
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="kam-secondary-grid <?= !$puedeAliados ? 'is-single' : '' ?>">
        <?php if ($puedeAliados): ?>
            <section class="dashboard-panel kam-allies-panel">
                <div class="kam-panel-heading">
                    <div>
                        <span class="kam-section-kicker">RED INSTITUCIONAL</span>
                        <h2 class="panel-title mb-1">Estado de tus aliados</h2>
                        <p class="page-subtitle mb-0">
                            Salud actual de las relaciones formalizadas y sus convocatorias.
                        </p>
                    </div>
                    <a class="kam-panel-link" href="<?= $esc($aliadosUrl) ?>">
                        Ver aliados
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <div class="kam-allies-summary">
                    <div>
                        <strong><?= (int)($aliados['total'] ?? 0) ?></strong>
                        <span>Aliados</span>
                    </div>
                    <div>
                        <strong><?= (int)($aliados['con_difusion'] ?? 0) ?></strong>
                        <span>Con difusión</span>
                    </div>
                    <div>
                        <strong><?= (int)($aliados['pendientes'] ?? 0) ?></strong>
                        <span>Pendientes</span>
                    </div>
                    <div class="<?= (int)($aliados['vencidos'] ?? 0) > 0 ? 'is-attention' : '' ?>">
                        <strong><?= (int)($aliados['vencidos'] ?? 0) ?></strong>
                        <span>Vencidos</span>
                    </div>
                </div>

                <?php if (!empty($aliados['atencion'])): ?>
                    <div class="kam-allies-list">
                        <?php foreach ($aliados['atencion'] as $aliado): ?>
                            <a
                                class="kam-ally-item"
                                href="<?= $esc($aliado['url'] ?? $aliadosUrl) ?>">
                                <div>
                                    <strong><?= $esc($aliado['institucion'] ?? 'Aliado') ?></strong>
                                    <span>
                                        <?= $esc($aliado['estado_label'] ?? 'Seguimiento pendiente') ?>
                                        <?php if (trim((string)($aliado['municipio'] ?? '')) !== ''): ?>
                                            · <?= $esc($aliado['municipio']) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php if (!empty($aliado['proximo_seguimiento_at'])): ?>
                                    <small>
                                        <?= $esc($formatearFecha($aliado['proximo_seguimiento_at'])) ?>
                                    </small>
                                <?php endif; ?>
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="kam-inline-clear">
                        <span class="kam-inline-clear-icon">
                            <i class="bi bi-check-circle"></i>
                        </span>
                        <div>
                            <strong>Red sin seguimientos pendientes.</strong>
                            <span>
                                No hay aliados que requieran continuidad en este momento.
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="dashboard-panel kam-coverage-panel">
            <div class="kam-panel-heading">
                <div>
                    <span class="kam-section-kicker">COBERTURA</span>
                    <h2 class="panel-title mb-1">
                        <?= $esc($cobertura['titulo'] ?? 'Cobertura territorial') ?>
                    </h2>
                    <p class="page-subtitle mb-0">
                        <?= ($cobertura['modo'] ?? '') === 'estados'
                            ? 'Muestra primero los territorios donde existe actividad real de vinculación o aliados.'
                            : 'Con un solo estado, el tablero profundiza automáticamente a municipios.' ?>
                    </p>
                </div>
                <div class="kam-coverage-heading-actions">
                    <?php if (($cobertura['modo'] ?? '') === 'estados'): ?>
                        <span class="kam-panel-count">
                            <?= $territoriosTotal ?>
                            <?= $territoriosTotal === 1 ? 'territorio' : 'territorios' ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($puedeTerritorios): ?>
                        <a class="kam-panel-link" href="<?= $esc($territoriosUrl) ?>">
                            Ver territorios
                            <i class="bi bi-arrow-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($itemsCobertura)): ?>
                <div class="kam-coverage-list">
                    <?php foreach ($itemsCobertura as $item): ?>
                        <article class="kam-coverage-item">
                            <div class="kam-coverage-name">
                                <span class="kam-coverage-icon">
                                    <i class="bi <?= ($cobertura['modo'] ?? '') === 'estados' ? 'bi-map' : 'bi-geo-alt' ?>"></i>
                                </span>
                                <div>
                                    <strong><?= $esc($item['nombre'] ?? 'Territorio') ?></strong>
                                    <span>
                                        <?= (int)($item['seguimientos'] ?? 0) ?> seguimientos
                                        ·
                                        <?= (int)($item['aliados'] ?? 0) ?> aliados
                                    </span>
                                </div>
                            </div>
                            <?php if (
                                ($cobertura['modo'] ?? '') === 'estados' &&
                                $mostrarAnalistasCobertura
                            ): ?>
                                <span class="kam-coverage-team">
                                    <?= (int)($item['analistas'] ?? 0) ?>
                                    <?= (int)($item['analistas'] ?? 0) === 1
                                        ? 'Analista'
                                        : 'Analistas' ?>
                                </span>
                            <?php endif; ?>
                            <span class="kam-coverage-attention <?= (int)($item['requieren_atencion'] ?? 0) > 0 ? 'is-attention' : '' ?>">
                                <?= (int)($item['requieren_atencion'] ?? 0) ?>
                                atención
                            </span>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if (
                    ($cobertura['modo'] ?? '') === 'estados' &&
                    $territoriosSinActividad > 0
                ): ?>
                    <div class="kam-coverage-inactive">
                        <i class="bi bi-info-circle"></i>
                        <span>
                            <?= $territoriosSinActividad ?>
                            <?= $territoriosSinActividad === 1
                                ? ' territorio sin actividad registrada.'
                                : ' territorios sin actividad registrada.' ?>
                        </span>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="kam-empty-state is-compact">
                    <span><i class="bi bi-geo"></i></span>
                    <div>
                        <strong>Sin actividad territorial para mostrar.</strong>
                        <p>
                            La cobertura aparecerá conforme existan seguimientos o aliados en tus territorios.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>
