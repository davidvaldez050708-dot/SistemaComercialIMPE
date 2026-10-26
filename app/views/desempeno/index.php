<?php

require_once __DIR__ . '/../../helpers/AvatarHelper.php';
require_once __DIR__ . '/../../helpers/VentasActividadAccess.php';

$desempeno = is_array($desempeno ?? null)
    ? $desempeno
    : [];

$vista = (string)($desempeno['vista'] ?? 'propio');
$area = (string)($desempeno['area'] ?? 'analistas');
$esAsesores = $area === 'asesores';
$areaLabel = (string)($desempeno['area_label'] ?? 'Analistas');
$periodo = is_array($desempeno['periodo'] ?? null)
    ? $desempeno['periodo']
    : [];
$ranking = is_array($desempeno['ranking'] ?? null)
    ? $desempeno['ranking']
    : [];
$resumen = is_array($desempeno['resumen'] ?? null)
    ? $desempeno['resumen']
    : [];
$reconocimientos = is_array(
    $desempeno['reconocimientos'] ?? null
)
    ? $desempeno['reconocimientos']
    : [];
$tendencia = is_array($desempeno['tendencia'] ?? null)
    ? $desempeno['tendencia']
    : [];
$territorios = is_array($desempeno['territorios'] ?? null)
    ? $desempeno['territorios']
    : [];
$personas = is_array($desempeno['personas'] ?? null)
    ? $desempeno['personas']
    : [];
$criterios = is_array($desempeno['criterios'] ?? null)
    ? $desempeno['criterios']
    : [];

$puedeGlobal = tienePermiso('desempeno.ver_global');
$puedeEquipo = tienePermiso('desempeno.ver_equipo');
$puedePropio = tienePermiso('desempeno.ver_propio');
$rolActualDesempeno = trim((string)($_SESSION['rol'] ?? ''));
$esCuentaClaveDesempeno =
    strcasecmp($rolActualDesempeno, 'Cuenta Clave') === 0;
$esAnalistaDesempeno =
    strcasecmp($rolActualDesempeno, 'Analista de Datos') === 0;
$esMarketingDesempeno =
    strcasecmp($rolActualDesempeno, 'Marketing') === 0;

$mostrarTabGlobal = $puedeGlobal && (!$esAsesores || $vista === 'global');
$mostrarTabEquipo =
    $puedeEquipo &&
    $esCuentaClaveDesempeno;
$mostrarTabPropio =
    $puedePropio &&
    (
        $esCuentaClaveDesempeno ||
        $esAnalistaDesempeno ||
        $esMarketingDesempeno || ($esAsesores && $vista === 'propio')
    );

$esc = static function ($valor) {
    return htmlspecialchars(
        (string)$valor,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
};

$iniciales = static function ($nombre) {
    $partes = preg_split(
        '/\s+/u',
        trim((string)$nombre),
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    if (empty($partes)) {
        return 'U';
    }

    $resultado = '';
    foreach (array_slice($partes, 0, 2) as $parte) {
        $resultado .= function_exists('mb_substr')
            ? mb_substr($parte, 0, 1, 'UTF-8')
            : substr($parte, 0, 1);
    }

    return strtoupper($resultado);
};

$fotoUrl = static function ($ruta) {
    $ruta = trim((string)$ruta);
    return $ruta !== ''
        ? BASE_URL . ltrim($ruta, '/')
        : '';
};

$urlVista = static function ($vistaDestino) use ($desempeno) {
    $params = [
        'controller' => 'desempeno',
        'action' => 'index',
        'vista' => $vistaDestino,
        'periodo' => $desempeno['periodo']['clave'] ?? 'semana'
    ];

    if ($vistaDestino === 'global') {
        $params['area'] = $desempeno['area'] ?? 'analistas';
    }

    return
        BASE_URL .
        'index.php?' .
        http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
};

$periodos = [
    'hoy' => 'Hoy',
    'ayer' => 'Ayer',
    'semana' => 'Esta semana',
    'ultimos_7' => 'Últimos 7 días',
    'mes' => 'Este mes',
    'mes_anterior' => 'Mes anterior',
    'personalizado' => 'Personalizado'
];

$estadoId = (int)($desempeno['estado_id'] ?? 0);
$personaId = (int)($desempeno['persona_id'] ?? 0);
$esPersonalizado =
    (string)($periodo['clave'] ?? '') === 'personalizado';

$maxTendencia = 0;
$actividadTendenciaTotal = 0;

foreach ($tendencia as $filaTendencia) {
    $principalTendencia =
        (int)($filaTendencia['principal'] ?? 0);
    $secundarioTendencia =
        (int)($filaTendencia['secundario'] ?? 0);
    $terciarioTendencia =
        (int)($filaTendencia['terciario'] ?? 0);

    $actividadTendenciaTotal +=
        $principalTendencia +
        $secundarioTendencia +
        $terciarioTendencia;

    $maxTendencia = max(
        $maxTendencia,
        $principalTendencia,
        $secundarioTendencia,
        $terciarioTendencia
    );
}

$totalDiasTendencia = count($tendencia);
$saltoEtiquetaTendencia =
    $totalDiasTendencia <= 10
        ? 1
        : ($totalDiasTendencia <= 20
            ? 2
            : ($totalDiasTendencia <= 31 ? 3 : 5));

$maxTendencia = max(1, $maxTendencia);

$vistaLabel = [
    'global' => 'Vista global',
    'equipo' => 'Mi equipo',
    'propio' => 'Mi desempeño'
][$vista] ?? 'Desempeño';

$esComparativo =
    count($ranking) > 1 &&
    in_array($vista, ['global', 'equipo'], true);

$rankingTitle = $vista === 'propio'
    ? 'Mi desempeño en el periodo'
    : (
        $vista === 'equipo'
            ? (
                $esComparativo
                    ? 'Ranking de mi equipo'
                    : 'Desempeño de mi equipo'
            )
            : (
                $esComparativo
                    ? 'Ranking del área'
                    : 'Desempeño del área'
            )
    );
?>

<section class="performance-module" data-performance-module>
    <?php if (!empty($errorDesempeno)): ?>
        <div class="alert alert-danger login-alert mb-3" role="alert">
            <i class="bi bi-exclamation-circle"></i>
            <span><?= $esc($errorDesempeno) ?></span>
        </div>
    <?php endif; ?>

    <section class="performance-hero">
        <div>
            <span class="performance-eyebrow">DESEMPEÑO Y RECONOCIMIENTOS</span>
            <h1><?= $esc($vistaLabel) ?></h1>
            <p>
                <?= $vista === 'equipo'
                    ? 'Revisa el desempeño de tu equipo, identifica quién destaca y en qué métricas dentro de tu alcance autorizado.'
                    : (
                        $vista === 'global'
                            ? 'Consulta el desempeño del área seleccionada con métricas comparables y criterios visibles.'
                            : 'Consulta tu actividad registrada y evolución dentro del periodo seleccionado.'
                    ) ?>
            </p>
        </div>

        <div class="performance-live-state">
            <span class="performance-live-dot"></span>
            <div>
                <strong>Datos en tiempo real</strong>
                <small>
                    Actualizado <?= $esc(
                        substr(
                            (string)($desempeno['actualizado_at'] ?? ''),
                            11,
                            5
                        )
                    ) ?>
                    · refresco cada 60 s
                </small>
            </div>
        </div>
    </section>

    <?php if (
        ($mostrarTabGlobal ? 1 : 0) +
        ($mostrarTabEquipo ? 1 : 0) +
        ($mostrarTabPropio ? 1 : 0) > 1
    ): ?>
        <nav class="performance-view-tabs" aria-label="Vista de desempeño">
            <?php if ($mostrarTabGlobal): ?>
                <a
                    href="<?= $esc($urlVista('global')) ?>"
                    class="<?= $vista === 'global' ? 'is-active' : '' ?>">
                    <i class="bi bi-globe2"></i>
                    Vista global
                </a>
            <?php endif; ?>

            <?php if ($mostrarTabEquipo): ?>
                <a
                    href="<?= $esc($urlVista('equipo')) ?>"
                    class="<?= $vista === 'equipo' ? 'is-active' : '' ?>">
                    <i class="bi bi-people"></i>
                    Mi equipo
                </a>
            <?php endif; ?>

            <?php if ($mostrarTabPropio): ?>
                <a
                    href="<?= $esc($urlVista('propio')) ?>"
                    class="<?= $vista === 'propio' ? 'is-active' : '' ?>">
                    <i class="bi bi-person"></i>
                    Mi desempeño
                </a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

    <section class="dashboard-panel performance-filter-panel">
        <form
            method="GET"
            class="performance-filter-form"
            data-performance-filter-form>
            <input type="hidden" name="controller" value="desempeno">
            <input type="hidden" name="action" value="index">
            <input type="hidden" name="vista" value="<?= $esc($vista) ?>">

            <?php if (!empty($desempeno['puede_cambiar_area']) || ($vista === 'equipo' && VentasActividadAccess::alcance(true) !== '')): ?>
                <div class="performance-filter-field">
                    <label for="performance_area">Área</label>
                    <select
                        id="performance_area"
                        name="area"
                        class="form-select"
                        data-performance-auto-submit>
                        <?php foreach (['analistas'=>'Analistas'] + ($vista === 'global' ? ['cuenta_clave'=>'Cuenta Clave'] : []) + (VentasActividadAccess::alcance(true) !== '' ? ['asesores'=>'Asesores'] : []) as $codigoArea=>$nombreArea): ?>
                            <option value="<?= $esc($codigoArea) ?>" <?= $area === $codigoArea ? 'selected' : '' ?>><?= $esc($nombreArea) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" name="area" value="<?= $esc($area) ?>">
            <?php endif; ?>

            <div class="performance-filter-field">
                <label for="performance_periodo">Periodo</label>
                <select
                    id="performance_periodo"
                    name="periodo"
                    class="form-select"
                    data-performance-period>
                    <?php foreach ($periodos as $valor => $label): ?>
                        <option
                            value="<?= $esc($valor) ?>"
                            <?= (string)($periodo['clave'] ?? '') === $valor ? 'selected' : '' ?>>
                            <?= $esc($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (!$esAsesores): ?>
            <div class="performance-filter-field">
                <label for="performance_estado">Territorio</label>
                <select
                    id="performance_estado"
                    name="estado_id"
                    class="form-select"
                    data-performance-auto-submit>
                    <option value="0">Todos los territorios</option>
                    <?php foreach ($territorios as $territorio): ?>
                        <option
                            value="<?= (int)($territorio['id'] ?? 0) ?>"
                            <?= $estadoId === (int)($territorio['id'] ?? 0) ? 'selected' : '' ?>>
                            <?= $esc(
                                $territorio['nombre_corto']
                                    ?? $territorio['nombre']
                                    ?? ''
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php endif; ?>

            <?php if (!empty($desempeno['puede_cambiar_persona'])): ?>
                <div class="performance-filter-field">
                    <label for="performance_persona">
                        <?= $area === 'cuenta_clave'
                            ? 'Cuenta Clave'
                            : ($esAsesores ? 'Asesor' : 'Analista') ?>
                    </label>
                    <select
                        id="performance_persona"
                        name="persona_id"
                        class="form-select"
                        data-performance-auto-submit>
                        <option value="0">Todas las personas</option>
                        <?php foreach ($personas as $persona): ?>
                            <option
                                value="<?= (int)($persona['id'] ?? 0) ?>"
                                <?= $personaId === (int)($persona['id'] ?? 0) ? 'selected' : '' ?>>
                                <?= $esc($persona['nombre_completo'] ?? trim(
                                    (string)($persona['nombre'] ?? '') . ' ' .
                                    (string)($persona['apellidos'] ?? '')
                                )) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" name="persona_id" value="<?= $personaId ?>">
            <?php endif; ?>

            <div class="performance-filter-actions">
                <?php if ($urlExportarPdf !== ''): ?>
                    <a
                        href="<?= $esc($urlExportarPdf) ?>"
                        class="btn btn-system-light" data-performance-pdf target="_blank" rel="noopener">
                        <i class="bi bi-file-earmark-pdf"></i>
                        Exportar PDF
                    </a>
                <?php endif; ?>

                <button type="submit" class="btn btn-system-save">
                    <i class="bi bi-arrow-repeat"></i>
                    Actualizar
                </button>
            </div>

            <div
                class="performance-custom-period <?= $esPersonalizado ? '' : 'd-none' ?>"
                data-performance-custom-period>
                <div class="performance-filter-field">
                    <label for="performance_desde">Desde</label>
                    <input
                        type="date"
                        class="form-control"
                        id="performance_desde"
                        name="fecha_desde"
                        max="<?= $esc(date('Y-m-d')) ?>"
                        value="<?= $esc($periodo['fecha_desde'] ?? '') ?>">
                </div>
                <div class="performance-filter-field">
                    <label for="performance_hasta">Hasta</label>
                    <input
                        type="date"
                        class="form-control"
                        id="performance_hasta"
                        name="fecha_hasta"
                        max="<?= $esc(date('Y-m-d')) ?>"
                        value="<?= $esc($periodo['fecha_hasta'] ?? '') ?>">
                </div>
                <p>
                    El rango personalizado se aplica a todas las métricas, el ranking y el PDF.
                </p>
            </div>
        </form>
    </section>

    <div class="performance-context-row">
        <div>
            <span>ÁREA</span>
            <strong><?= $esc($areaLabel) ?></strong>
        </div>
        <div>
            <span>PERIODO</span>
            <strong><?= $esc($periodo['label'] ?? '') ?></strong>
        </div>
        <div>
            <span>PARTICIPANTES</span>
            <strong><?= count($ranking) ?></strong>
        </div>
        <div>
            <span>ALCANCE</span>
            <strong>
                <?= $esAsesores
                    ? $esc(['global'=>'Todos los asesores autorizados','equipo'=>'Asesores de mi equipo','propio'=>'Mi actividad'][$vista] ?? 'Asesores autorizados')
                    : ($estadoId > 0 ? 'Territorio filtrado' : 'Todos los territorios autorizados') ?>
            </strong>
        </div>
    </div>

    <?php if ($esAsesores): ?>
        <section class="performance-kpi-grid is-five">
            <?php foreach ([
                ['telephone','Llamadas registradas',(int)($resumen['llamadas']??0),'Entrantes y salientes confirmadas'],
                ['person-check','Con contacto confirmado',(int)($resumen['conversaciones_confirmadas']??0),'Conexión y conversación registrada'],
                ['bullseye','Tasa de contacto',isset($resumen['tasa_contacto'])?number_format($resumen['tasa_contacto'],1).'%':'—','Contacto confirmado / llamadas'],
                ['clipboard-check','Cumplimiento del registro',isset($resumen['cumplimiento_registro'])?number_format($resumen['cumplimiento_registro'],1).'%':'—','Resultados / llamadas capturables'],
                ['clock','Tiempo confirmado',VentasActividadMetricas::tiempoConfirmado($resumen),(int)($resumen['duraciones_pendientes']??0).' duraciones pendientes']
            ] as [$icono,$etiqueta,$valor,$ayuda]): ?>
                <article><span class="performance-kpi-icon"><i class="bi bi-<?= $esc($icono) ?>"></i></span><div><strong><?= $esc($valor) ?></strong><span><?= $esc($etiqueta) ?></span><small><?= $esc($ayuda) ?></small></div></article>
            <?php endforeach; ?>
        </section>
        <div class="performance-decision-note" role="status"><i class="bi bi-info-circle" aria-hidden="true"></i><span><?= (int)($resumen['intentos']??0) ?> intentos y <?= (int)($resumen['transferencias_pendientes']??0) ?> transferencias sin confirmar; se excluyen del ranking de llamadas. <?= (int)($resumen['por_registrar']??0) ?> llamadas por registrar.</span></div>
    <?php elseif ($area === 'cuenta_clave'): ?>
        <section class="performance-kpi-grid">
            <article>
                <span class="performance-kpi-icon"><i class="bi bi-building-check"></i></span>
                <div>
                    <strong><?= (int)($resumen['aliados_trabajados'] ?? 0) ?></strong>
                    <span>Aliados trabajados</span>
                    <small>Con actividad en el periodo</small>
                </div>
            </article>
            <article>
                <span class="performance-kpi-icon"><i class="bi bi-megaphone"></i></span>
                <div>
                    <strong><?= (int)($resumen['difusiones'] ?? 0) ?></strong>
                    <span>Difusiones</span>
                    <small>Convocatorias compartidas</small>
                </div>
            </article>
            <article>
                <span class="performance-kpi-icon"><i class="bi bi-arrow-repeat"></i></span>
                <div>
                    <strong><?= (int)($resumen['seguimientos'] ?? 0) ?></strong>
                    <span>Actualizaciones</span>
                    <small>Seguimientos registrados</small>
                </div>
            </article>
            <article>
                <span class="performance-kpi-icon is-success"><i class="bi bi-patch-check"></i></span>
                <div>
                    <strong><?= (int)($resumen['confirmaciones'] ?? 0) ?></strong>
                    <span>Confirmaciones</span>
                    <small>Difusiones confirmadas</small>
                </div>
            </article>
        </section>
    <?php elseif ($area === 'marketing'): ?>
        <section class="performance-kpi-grid">
            <article>
                <span class="performance-kpi-icon"><i class="bi bi-megaphone"></i></span>
                <div>
                    <strong><?= (int)($resumen['publicaciones'] ?? 0) ?></strong>
                    <span>Convocatorias publicadas</span>
                    <small>Creadas en el periodo</small>
                </div>
            </article>
            <article>
                <span class="performance-kpi-icon"><i class="bi bi-map"></i></span>
                <div>
                    <strong><?= (int)($resumen['territorios_cubiertos'] ?? 0) ?></strong>
                    <span>Cobertura acumulada</span>
                    <small>Suma de territorios cubiertos por persona</small>
                </div>
            </article>
            <article>
                <span class="performance-kpi-icon"><i class="bi bi-pencil-square"></i></span>
                <div>
                    <strong><?= (int)($resumen['actualizaciones'] ?? 0) ?></strong>
                    <span>Actualizaciones</span>
                    <small>Convocatorias modificadas</small>
                </div>
            </article>
            <article>
                <span class="performance-kpi-icon is-success"><i class="bi bi-calendar-check"></i></span>
                <div>
                    <strong><?= (int)($resumen['vigentes'] ?? 0) ?></strong>
                    <span>Vigentes creadas</span>
                    <small>Continúan activas</small>
                </div>
            </article>
        </section>
    <?php else: ?>
        <section class="performance-kpi-grid is-five">
            <article>
                <span class="performance-kpi-icon"><i class="bi bi-telephone"></i></span>
                <div>
                    <strong><?= (int)($resumen['llamadas_realizadas'] ?? 0) ?></strong>
                    <span>Llamadas válidas</span>
                    <small>Vinculadas a telefonía</small>
                </div>
            </article>
            <article>
                <span class="performance-kpi-icon is-success"><i class="bi bi-person-check"></i></span>
                <div>
                    <strong><?= (int)($resumen['llamadas_con_contacto'] ?? 0) ?></strong>
                    <span>Llamadas con contacto</span>
                    <small>Se habló con una persona</small>
                </div>
            </article>
            <article>
                <span class="performance-kpi-icon"><i class="bi bi-bullseye"></i></span>
                <div>
                    <strong><?= $esc(number_format((float)($resumen['tasa_contacto'] ?? 0), 1)) ?>%</strong>
                    <span>Tasa de contacto</span>
                    <small>Con contacto / válidas</small>
                </div>
            </article>
            <article>
                <span class="performance-kpi-icon"><i class="bi bi-activity"></i></span>
                <div>
                    <strong><?= (int)($resumen['interacciones'] ?? 0) ?></strong>
                    <span>Interacciones útiles</span>
                    <small>Actividad registrada</small>
                </div>
            </article>
            <article>
                <span class="performance-kpi-icon"><i class="bi bi-shield-check"></i></span>
                <div>
                    <strong><?= (int)($resumen['llamadas_efectivas'] ?? 0) ?></strong>
                    <span>Llamadas efectivas</span>
                    <small>Verificación con evidencia</small>
                </div>
            </article>
        </section>
    <?php endif; ?>

    <div class="performance-main-grid <?= empty($reconocimientos) ? 'is-single' : '' ?>">
        <section class="dashboard-panel performance-ranking-panel">
            <div class="performance-panel-heading">
                <div>
                    <span class="performance-eyebrow">
                        <?= $vista === 'propio'
                            ? 'RESUMEN PERSONAL'
                            : ($esComparativo
                                ? 'RANKING DEL PERIODO'
                                : 'DESEMPEÑO DEL PERIODO') ?>
                    </span>
                    <h2><?= $esc($rankingTitle) ?></h2>
                    <p>
                        <?= $vista === 'propio'
                            ? 'Consulta tus métricas e historial del periodo sin convertir tu desempeño individual en un ranking.'
                            : (
                                $esComparativo
                                    ? 'Compara resultados e identifica fortalezas para reconocimientos. El índice operativo resume la actividad con criterios visibles y no asigna incentivos automáticamente.'
                                    : 'Consulta las métricas del alcance seleccionado. El ranking se habilita automáticamente cuando existen al menos dos participantes.'
                            ) ?>
                    </p>
                </div>
                <?php if (count($ranking) > 1): ?>
                    <span class="performance-ranking-count">
                        <?= count($ranking) ?> participantes
                    </span>
                <?php endif; ?>
            </div>

            <?php if (empty($ranking)): ?>
                <div class="performance-empty">
                    <i class="bi bi-bar-chart"></i>
                    <strong>Sin datos para el periodo seleccionado.</strong>
                    <span>
                        Ajusta el periodo o el alcance para consultar actividad registrada.
                    </span>
                </div>
            <?php else: ?>
                <div class="performance-ranking-list">
                    <?php foreach ($ranking as $fila): ?>
                        <?php
                        $nombre = (string)($fila['nombre_completo'] ?? '');
                        $foto = $fotoUrl($fila['foto_perfil'] ?? '');
                        $posicion = (int)($fila['posicion'] ?? 0);
                        ?>
                        <article class="performance-person-row <?= $posicion <= 3 && $esComparativo ? 'is-top' : '' ?> <?= !$esComparativo ? 'is-summary' : '' ?>">
                            <?php if ($esComparativo): ?>
                                <div class="performance-position">
                                    <?php if ($posicion <= 3): ?>
                                        <i class="bi bi-award"></i>
                                    <?php endif; ?>
                                    <strong><?= $posicion ?></strong>
                                </div>
                            <?php endif; ?>

                            <div class="performance-person">
                                <?php if ($vista === 'propio'): ?>
                                    <?= renderAvatarUsuario(
                                        $nombre,
                                        '',
                                        $rolActualDesempeno,
                                        $fila['foto_perfil'] ?? '',
                                        'md',
                                        'general',
                                        'performance-personal-avatar',
                                        false
                                    ) ?>
                                <?php else: ?>
                                <span class="performance-avatar">
                                    <?php if ($foto !== ''): ?>
                                        <img src="<?= $esc($foto) ?>" alt="<?= $esc($nombre) ?>">
                                    <?php else: ?>
                                        <?= $esc($iniciales($nombre)) ?>
                                    <?php endif; ?>
                                </span>
                                <?php endif; ?>
                                <div>
                                    <strong><?= $esc($nombre) ?></strong>
                                    <span>
                                        <?= $area === 'cuenta_clave'
                                            ? 'Cuenta Clave'
                                            : ($area === 'marketing'
                                                ? 'Marketing'
                                                : ($esAsesores ? 'Asesor de Ventas' : 'Analista de Datos')) ?>
                                    </span>
                                </div>
                            </div>

                            <?php if ($esAsesores): ?>
                                <div class="performance-row-metrics">
                                    <div><strong><?= (int)$fila['llamadas'] ?></strong><span>Llamadas</span></div>
                                    <div><strong><?= (int)$fila['conversaciones_confirmadas'] ?></strong><span>Con contacto</span></div>
                                    <div><strong><?= $fila['tasa_contacto']===null?'—':$esc(number_format($fila['tasa_contacto'],1)).'%' ?></strong><span>Tasa contacto</span></div>
                                    <div><strong><?= $fila['cumplimiento_registro']===null?'—':$esc(number_format($fila['cumplimiento_registro'],1)).'%' ?></strong><span>Registro</span></div>
                                </div>
                            <?php elseif ($area === 'cuenta_clave'): ?>
                                <div class="performance-row-metrics is-account">
                                    <div>
                                        <strong><?= (int)($fila['aliados_trabajados'] ?? 0) ?></strong>
                                        <span>Aliados</span>
                                    </div>
                                    <div>
                                        <strong><?= (int)($fila['difusiones'] ?? 0) ?></strong>
                                        <span>Difusiones</span>
                                    </div>
                                    <div>
                                        <strong><?= (int)($fila['seguimientos'] ?? 0) ?></strong>
                                        <span>Actualizaciones</span>
                                    </div>
                                    <div>
                                        <strong><?= (int)($fila['confirmaciones'] ?? 0) ?></strong>
                                        <span>Confirmaciones</span>
                                    </div>
                                </div>
                            <?php elseif ($area === 'marketing'): ?>
                                <div class="performance-row-metrics">
                                    <div>
                                        <strong><?= (int)($fila['publicaciones'] ?? 0) ?></strong>
                                        <span>Publicadas</span>
                                    </div>
                                    <div>
                                        <strong><?= (int)($fila['territorios_cubiertos'] ?? 0) ?></strong>
                                        <span>Territorios</span>
                                    </div>
                                    <div>
                                        <strong><?= (int)($fila['actualizaciones'] ?? 0) ?></strong>
                                        <span>Actualizadas</span>
                                    </div>
                                    <div>
                                        <strong><?= (int)($fila['vigentes'] ?? 0) ?></strong>
                                        <span>Vigentes</span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="performance-row-metrics">
                                    <div>
                                        <strong><?= (int)($fila['llamadas_con_contacto'] ?? 0) ?></strong>
                                        <span>Con contacto</span>
                                    </div>
                                    <div>
                                        <strong><?= $esc(number_format((float)($fila['tasa_contacto'] ?? 0), 1)) ?>%</strong>
                                        <span>Tasa contacto</span>
                                    </div>
                                    <div>
                                        <strong><?= (int)($fila['interacciones'] ?? 0) ?></strong>
                                        <span>Interacciones</span>
                                    </div>
                                    <div>
                                        <strong><?= (int)($fila['llamadas_efectivas'] ?? 0) ?></strong>
                                        <span>Efectivas</span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($esComparativo): ?>
                                <div class="performance-index">
                                    <span>Índice operativo</span>
                                    <strong>
                                        <?= $fila['indice'] === null
                                            ? '—'
                                            : $esc(number_format((float)$fila['indice'], 1)) ?>
                                    </strong>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!empty($reconocimientos)): ?>
            <section class="dashboard-panel performance-recognition-panel">
                <div class="performance-panel-heading">
                    <div>
                        <span class="performance-eyebrow">RECONOCIMIENTOS</span>
                        <h2>Destacados del periodo</h2>
                        <p>
                            Reconocimientos por métrica objetiva dentro del mismo periodo y área.
                        </p>
                    </div>
                </div>

                <div class="performance-recognition-list">
                    <?php foreach ($reconocimientos as $reconocimiento): ?>
                        <?php
                        $foto = $fotoUrl($reconocimiento['foto_perfil'] ?? '');
                        $nombre = (string)($reconocimiento['nombre'] ?? '');
                        ?>
                        <article>
                            <span class="performance-recognition-icon">
                                <i class="bi <?= $esc($reconocimiento['icono'] ?? 'bi-award') ?>"></i>
                            </span>
                            <div class="performance-recognition-person">
                                <span class="performance-avatar is-small">
                                    <?php if ($foto !== ''): ?>
                                        <img src="<?= $esc($foto) ?>" alt="<?= $esc($nombre) ?>">
                                    <?php else: ?>
                                        <?= $esc($iniciales($nombre)) ?>
                                    <?php endif; ?>
                                </span>
                                <div>
                                    <span><?= $esc($reconocimiento['titulo'] ?? '') ?></span>
                                    <strong><?= $esc($nombre) ?></strong>
                                </div>
                            </div>
                            <div class="performance-recognition-value">
                                <strong><?= $esc($reconocimiento['valor'] ?? '') ?></strong>
                                <span><?= $esc($reconocimiento['unidad'] ?? '') ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <section class="dashboard-panel performance-history-panel">
        <div class="performance-panel-heading">
            <div>
                <span class="performance-eyebrow">EVOLUCIÓN</span>
                <h2>Historial diario</h2>
                <p>
                    <?= $area === 'cuenta_clave'
                        ? 'Difusiones, actualizaciones y confirmaciones registradas por día.'
                        : ($area === 'marketing'
                            ? 'Convocatorias publicadas y actualizadas por día.'
                            : ($esAsesores ? 'Llamadas, contactos confirmados y resultados registrados por día.' : 'Interacciones útiles, llamadas con contacto y llamadas efectivas registradas por día.')) ?>
                </p>
            </div>
            <span class="performance-period-badge">
                <?= $esc($periodo['label'] ?? '') ?>
            </span>
        </div>

        <?php if ($actividadTendenciaTotal <= 0): ?>
            <div class="performance-chart-empty">
                <span class="performance-chart-empty-icon">
                    <i class="bi bi-bar-chart"></i>
                </span>
                <div>
                    <strong>Sin actividad registrada en este periodo</strong>
                    <span>
                        La gráfica aparecerá cuando existan movimientos dentro del rango seleccionado.
                    </span>
                </div>
            </div>
        <?php else: ?>
            <div class="performance-chart-scroll" role="region" tabindex="0" aria-label="Historial diario: desplázate horizontalmente para consultar todas las fechas">
                <div
                    class="performance-bar-chart"
                    style="--performance-days: <?= max(1, $totalDiasTendencia) ?>">
                    <?php foreach ($tendencia as $indiceDia => $dia): ?>
                        <?php
                        $principal = (int)($dia['principal'] ?? 0);
                        $secundario = (int)($dia['secundario'] ?? 0);
                        $terciario = (int)($dia['terciario'] ?? 0);
                        $altoPrincipal = $principal > 0
                            ? max(6, ($principal / $maxTendencia) * 100)
                            : 0;
                        $altoSecundario = $secundario > 0
                            ? max(6, ($secundario / $maxTendencia) * 100)
                            : 0;
                        $altoTerciario = $terciario > 0
                            ? max(6, ($terciario / $maxTendencia) * 100)
                            : 0;
                        $mostrarEtiqueta =
                            ($indiceDia % $saltoEtiquetaTendencia) === 0 ||
                            $indiceDia === ($totalDiasTendencia - 1);
                        ?>
                        <div class="performance-day">
                            <div class="performance-bars">
                                <span
                                    class="is-primary"
                                    style="height: <?= $esc(number_format($altoPrincipal, 2, '.', '')) ?>%"
                                    title="<?= $principal ?>"></span>
                                <span
                                    class="is-secondary"
                                    style="height: <?= $esc(number_format($altoSecundario, 2, '.', '')) ?>%"
                                    title="<?= $secundario ?>"></span>
                                <?php if ($area === 'cuenta_clave' || $area === 'analistas' || $esAsesores): ?>
                                    <span
                                        class="is-tertiary"
                                        style="height: <?= $esc(number_format($altoTerciario, 2, '.', '')) ?>%"
                                        title="<?= $terciario ?>"></span>
                                <?php endif; ?>
                            </div>
                            <strong
                                class="<?= $mostrarEtiqueta ? '' : 'is-hidden-label' ?>"
                                title="<?= $esc($dia['fecha'] ?? '') ?>">
                                <?= $esc($dia['label'] ?? '') ?>
                            </strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="performance-chart-legend">
                <?php if ($esAsesores): ?>
                    <span><i class="is-primary"></i> Llamadas</span>
                    <span><i class="is-secondary"></i> Con contacto confirmado</span>
                    <span><i class="is-tertiary"></i> Resultados registrados</span>
                <?php elseif ($area === 'cuenta_clave'): ?>
                    <span><i class="is-primary"></i> Difusiones</span>
                    <span><i class="is-secondary"></i> Actualizaciones</span>
                    <span><i class="is-tertiary"></i> Confirmaciones</span>
                <?php elseif ($area === 'marketing'): ?>
                    <span><i class="is-primary"></i> Publicaciones</span>
                    <span><i class="is-secondary"></i> Actualizaciones</span>
                <?php else: ?>
                    <span><i class="is-primary"></i> Interacciones</span>
                    <span><i class="is-secondary"></i> Con contacto</span>
                    <span><i class="is-tertiary"></i> Llamadas efectivas</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="dashboard-panel performance-criteria-panel">
        <div class="performance-panel-heading">
            <div>
                <span class="performance-eyebrow">CRITERIOS DE MEDICIÓN</span>
                <h2>Cómo se construyen estas métricas</h2>
                <p>
                    Los criterios son visibles para que el ranking sea auditable y no dependa de una puntuación oculta.
                </p>
            </div>
        </div>

        <div class="performance-criteria-list">
            <?php foreach ($criterios as $criterio): ?>
                <div>
                    <i class="bi bi-check2-circle"></i>
                    <span><?= $esc($criterio) ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="performance-decision-note">
            <i class="bi bi-info-circle"></i>
            <span>
                El módulo sirve como apoyo para supervisión y reconocimientos. El índice operativo no asigna automáticamente bonos, sanciones o decisiones laborales.
            </span>
        </div>
    </section>
</section>

<?php if ($vista === 'propio'): ?>
<script>
// El footer registra el fallback compartido al terminar DOMContentLoaded.
// Reentregar sólo los errores de estas fotos que ocurrieron antes de ese registro.
document.addEventListener('DOMContentLoaded', function () {
    window.setTimeout(function () {
        document.querySelectorAll('.performance-module .performance-personal-avatar > .system-avatar-image').forEach(function (imagen) {
            if (imagen.complete && imagen.naturalWidth === 0) {
                imagen.dispatchEvent(new Event('error'));
            }
        });
    }, 0);
}, { once: true });
</script>
<?php endif; ?>
