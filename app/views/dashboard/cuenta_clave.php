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
$analistas = is_array($tableroCuentaClave['analistas'] ?? null)
    ? $tableroCuentaClave['analistas']
    : [];
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

$totalAtencionVinculacion =
    (int)($resumen['requieren_atencion'] ?? count($atenciones));
$totalAtencionAliados =
    $puedeAliados
        ? (int)($aliados['pendientes'] ?? 0)
        : 0;
$totalAsuntos = $totalAtencionVinculacion + $totalAtencionAliados;

$seguimientoUrl =
    BASE_URL .
    'index.php?controller=seguimientoVinculacion&action=index';
$aliadosUrl =
    BASE_URL .
    'index.php?controller=aliado&action=index';
$reportesUrl =
    BASE_URL .
    'index.php?controller=reporte&action=index';
$territoriosUrl =
    BASE_URL .
    'index.php?controller=territorio&action=index';

$metricas = [
    [
        'valor' => (int)($resumen['territorios'] ?? 0),
        'etiqueta' => 'Territorios supervisados',
        'ayuda' => 'Alcance territorial activo',
        'icono' => 'bi-geo-alt',
        'clase' => ''
    ],
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
        'ayuda' => 'Seguimientos de vinculación activos',
        'icono' => 'bi-list-check',
        'clase' => ''
    ],
    [
        'valor' => $totalAtencionVinculacion,
        'etiqueta' => 'Requieren atención',
        'ayuda' => 'Pendientes prioritarios del equipo',
        'icono' => 'bi-exclamation-circle',
        'clase' => $totalAtencionVinculacion > 0
            ? 'is-attention'
            : 'is-clear'
    ]
];

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
?>

<section class="kam-dashboard" data-kam-dashboard>
    <section class="kam-welcome">
        <div class="kam-welcome-copy">
            <span class="kam-eyebrow">TU OPERACIÓN</span>
            <h2><?= $esc($saludo . ', ' . $nombreCuentaClave) ?></h2>
            <p><?= $esc($fechaLarga) ?></p>
            <p class="kam-welcome-tagline">
                Supervisa la vinculación de tu equipo y la relación con tu red de aliados.
            </p>
        </div>

        <div class="kam-welcome-status-zone">
            <div class="kam-welcome-status <?= $totalAsuntos > 0 ? 'is-attention' : 'is-clear' ?>">
                <span class="kam-welcome-status-icon" aria-hidden="true">
                    <i class="bi <?= $totalAsuntos > 0 ? 'bi-exclamation-circle' : 'bi-check-circle' ?>"></i>
                </span>
                <div>
                    <strong>
                        <?php if ($totalAsuntos > 0): ?>
                            <?= $totalAsuntos ?>
                            <?= $totalAsuntos === 1
                                ? ' asunto requiere revisión'
                                : ' asuntos requieren revisión' ?>
                        <?php else: ?>
                            Tu operación está al día
                        <?php endif; ?>
                    </strong>
                    <span>
                        <?= $totalAsuntos > 0
                            ? 'Prioriza los casos de vinculación y aliados que necesitan continuidad.'
                            : 'No hay pendientes prioritarios detectados en este momento.' ?>
                    </span>
                </div>
            </div>
        </div>
    </section>

    <section class="kam-kpi-panel" aria-label="Indicadores principales">
        <div class="kam-kpi-heading">
            <span class="kam-eyebrow">RESUMEN OPERATIVO</span>
            <h2>Tu alcance actual</h2>
            <p>Datos vigentes de supervisión y red institucional.</p>
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

    <?php if ($puedeSeguimiento): ?>
        <div class="kam-primary-grid">
            <section class="dashboard-panel kam-attention-panel">
                <div class="kam-panel-heading">
                    <div>
                        <span class="kam-section-kicker">PRIORIDAD OPERATIVA</span>
                        <h2 class="panel-title mb-1">
                            Seguimientos que conviene revisar
                        </h2>
                        <p class="page-subtitle mb-0">
                            Casos de tus Analistas con acciones vencidas, próximas o sin actividad.
                        </p>
                    </div>
                    <a class="kam-panel-link" href="<?= $esc($seguimientoUrl) ?>">
                        Ver seguimiento
                        <i class="bi bi-arrow-right"></i>
                    </a>
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
                <?php else: ?>
                    <div class="kam-empty-state">
                        <span><i class="bi bi-check2-circle"></i></span>
                        <div>
                            <strong>Sin seguimientos prioritarios.</strong>
                            <p>
                                La cartera de tus Analistas no presenta acciones que requieran revisión inmediata.
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="dashboard-panel kam-team-panel">
                <div class="kam-panel-heading">
                    <div>
                        <span class="kam-section-kicker">EQUIPO DE VINCULACIÓN</span>
                        <h2 class="panel-title mb-1">Carga operativa</h2>
                        <p class="page-subtitle mb-0">
                            La vista crece automáticamente conforme se vinculen más Analistas.
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
                            $territoriosAnalista = is_array($analista['territorios'] ?? null)
                                ? $analista['territorios']
                                : [];
                            $territorioTexto = '';
                            if (!empty($territoriosAnalista)) {
                                $nombres = array_map(
                                    static fn($territorio) =>
                                        (string)($territorio['nombre'] ?? ''),
                                    $territoriosAnalista
                                );
                                $territorioTexto = implode(' · ', array_filter($nombres));
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
                                        <span>
                                            <?= $territorioTexto !== ''
                                                ? $esc($territorioTexto)
                                                : 'Sin territorio activo' ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="kam-team-metrics">
                                    <div>
                                        <strong><?= (int)($analista['seguimientos'] ?? 0) ?></strong>
                                        <span>Seguimientos</span>
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
                                        Ver cartera
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
                            Relaciones formalizadas y continuidad de convocatorias.
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
                        <i class="bi bi-check-circle"></i>
                        <span>
                            No hay aliados con seguimiento pendiente en este momento.
                        </span>
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
                            ? 'Compara la carga bajo tu responsabilidad entre territorios.'
                            : 'Con un solo estado, el tablero profundiza automáticamente a municipios.' ?>
                    </p>
                </div>
                <a class="kam-panel-link" href="<?= $esc($territoriosUrl) ?>">
                    Ver territorios
                    <i class="bi bi-arrow-right"></i>
                </a>
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
                            <?php if (($cobertura['modo'] ?? '') === 'estados'): ?>
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

    <section class="kam-quick-actions" aria-label="Accesos rápidos">
        <?php if ($puedeSeguimiento): ?>
            <a href="<?= $esc($seguimientoUrl) ?>">
                <i class="bi bi-list-task"></i>
                <span>
                    <strong>Seguimiento</strong>
                    <small>Supervisa la cartera de vinculación</small>
                </span>
                <i class="bi bi-arrow-right"></i>
            </a>
        <?php endif; ?>

        <?php if ($puedeAliados): ?>
            <a href="<?= $esc($aliadosUrl) ?>">
                <i class="bi bi-building-check"></i>
                <span>
                    <strong>Aliados</strong>
                    <small>Gestiona tu red formalizada</small>
                </span>
                <i class="bi bi-arrow-right"></i>
            </a>
        <?php endif; ?>

        <a href="<?= $esc($reportesUrl) ?>">
            <i class="bi bi-bar-chart"></i>
            <span>
                <strong>Reportes</strong>
                <small>Analiza operación y resultados</small>
            </span>
            <i class="bi bi-arrow-right"></i>
        </a>
    </section>
</section>
