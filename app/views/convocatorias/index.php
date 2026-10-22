<?php

$convocatorias = $convocatorias ?? [];
$estados = $estados ?? [];
$mensajeExito = $mensajeExito ?? '';
$mensajeError = $mensajeError ?? '';
$erroresFormulario = $erroresFormulario ?? [];
$datosFormulario = $datosFormulario ?? [];
$modalAbierto = $modalAbierto ?? '';
$vistaConvocatoriasInicial = (
    (string)($_GET['vista'] ?? '') === 'historial'
) ? 'historial' : 'activas';
$buscar = $buscar ?? '';
$estadoFiltro = $estadoFiltro ?? 0;
$estatusFiltro = $estatusFiltro ?? '';
$fechaFiltro = $fechaFiltro ?? '';
$categoriaFiltro = $categoriaFiltro ?? '';
$territorioSeleccionado = $territorioSeleccionado ?? null;
$tipoConvocatoria = $tipoConvocatoria ?? '';
$subtipoConvocatoria = $subtipoConvocatoria ?? '';
$convocatoriasRecientes = $convocatoriasRecientes ?? [];
$anioSeleccionado = (int)($anioSeleccionado ?? date('Y'));
$mesSeleccionado = (int)($mesSeleccionado ?? 0);
$aniosConvocatorias = is_array($aniosConvocatorias ?? null)
    ? $aniosConvocatorias
    : [(int)date('Y')];
$resumenMensualConvocatorias = is_array($resumenMensualConvocatorias ?? null)
    ? $resumenMensualConvocatorias
    : [];
$mesesVisiblesConvocatorias = is_array($mesesVisiblesConvocatorias ?? null)
    ? $mesesVisiblesConvocatorias
    : [];
$mostrarSelectorMes = (bool)($mostrarSelectorMes ?? false);
$nombresMesesConvocatoria = [
    1 => 'Enero',
    2 => 'Febrero',
    3 => 'Marzo',
    4 => 'Abril',
    5 => 'Mayo',
    6 => 'Junio',
    7 => 'Julio',
    8 => 'Agosto',
    9 => 'Septiembre',
    10 => 'Octubre',
    11 => 'Noviembre',
    12 => 'Diciembre'
];
$esChihuahua = $territorioSeleccionado &&
    strcasecmp(trim((string)($territorioSeleccionado['nombre'] ?? '')), 'Chihuahua') === 0;

$clasificacionesConvocatoria = [
    [
        'valor' => 'titulacion|ejecutivas',
        'tipo' => 'titulacion',
        'subtipo' => 'ejecutivas',
        'etiqueta' => 'Ejecutivas'
    ],
    [
        'valor' => 'titulacion|experiencia-laboral',
        'tipo' => 'titulacion',
        'subtipo' => 'experiencia-laboral',
        'etiqueta' => 'Titulación por experiencia laboral'
    ],
    [
        'valor' => 'titulacion|inscripciones-abiertas',
        'tipo' => 'titulacion',
        'subtipo' => 'inscripciones-abiertas',
        'etiqueta' => 'Inscripciones abiertas'
    ],
    [
        'valor' => 'bachillerato|bachillerato-2-anos',
        'tipo' => 'bachillerato',
        'subtipo' => 'bachillerato-2-anos',
        'etiqueta' => 'Bachillerato en 2 años'
    ],
    [
        'valor' => 'bachillerato|bachillerato-286',
        'tipo' => 'bachillerato',
        'subtipo' => 'bachillerato-286',
        'etiqueta' => 'Bachillerato 286'
    ],
    [
        'valor' => 'bachillerato|ingles',
        'tipo' => 'bachillerato',
        'subtipo' => 'ingles',
        'etiqueta' => 'Inglés'
    ],
    [
        'valor' => 'bachillerato|inscripciones-abiertas',
        'tipo' => 'bachillerato',
        'subtipo' => 'inscripciones-abiertas',
        'etiqueta' => 'Inscripciones abiertas'
    ]
];

if ($esChihuahua) {
    $clasificacionesConvocatoria[] = [
        'valor' => 'sindicatos|sindicatos',
        'tipo' => 'sindicatos',
        'subtipo' => 'sindicatos',
        'etiqueta' => 'Sindicatos'
    ];
}

$clasificacionesDisponibles = array_values(array_filter(
    $clasificacionesConvocatoria,
    static fn($item) =>
        $tipoConvocatoria === '' ||
        (string)($item['tipo'] ?? '') === $tipoConvocatoria
));

$aliadoContextoConvocatoria = $aliadoContextoConvocatoria ?? null;
$opcionesProgramaAliado = $aliadoContextoConvocatoria['opciones_convocatoria'] ?? [];
if ($aliadoContextoConvocatoria) {
    $opcionesValor = array_column($opcionesProgramaAliado, 'clasificacion');
    $clasificacionesDisponibles = array_values(array_filter(
        $clasificacionesDisponibles,
        static fn($item) => in_array($item['valor'], $opcionesValor, true)
    ));
}

$clasificacionFiltroActual = strtolower(trim((string)($_GET['clasificacion'] ?? '')));
if (
    $clasificacionFiltroActual === '' &&
    $tipoConvocatoria !== '' &&
    $subtipoConvocatoria !== ''
) {
    $clasificacionFiltroActual = $tipoConvocatoria . '|' . $subtipoConvocatoria;
}

$puedeCrear = tienePermiso('convocatorias.crear');
$puedeEditar = tienePermiso('convocatorias.editar');
$puedeDescargar = tienePermiso('convocatorias.descargar');
$puedeCambiarEstado = tienePermiso('convocatorias.cambiar_estado');

$texto = static function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$fechaLegible = static function ($fecha) {
    if (!$fecha) {
        return '—';
    }

    try {
        return (new DateTime($fecha))->format('d/m/Y');
    } catch (Exception $error) {
        return (string)$fecha;
    }
};

$periodoLegible = static function ($fechaInicio, $fechaTermino) use ($fechaLegible) {
    $fechaInicio = trim((string)$fechaInicio);
    $fechaTermino = trim((string)$fechaTermino);

    if ($fechaInicio === '' && $fechaTermino === '') {
        return 'Sin fecha';
    }

    if ($fechaInicio !== '' && $fechaTermino === '') {
        return $fechaLegible($fechaInicio) . ' — Sin vencimiento';
    }

    return $fechaLegible($fechaInicio) . ' — ' . $fechaLegible($fechaTermino);
};

$datosCrear = $modalAbierto === 'crear' ? $datosFormulario : [];
$datosEditar = $modalAbierto === 'editar' ? $datosFormulario : [];

$territorioActualId = (int)($territorioSeleccionado['id'] ?? 0);
$estadosCrearSeleccionados = array_map(
    'intval',
    $datosCrear['estados_ids'] ?? []
);
$hayEstadosAdicionalesCrear = count(
    array_filter(
        $estadosCrearSeleccionados,
        static fn($estadoId) => $estadoId !== $territorioActualId
    )
) > 0;

$fechasOpcionalesConvocatoria =
    $subtipoConvocatoria === 'inscripciones-abiertas';
?>
<!-- CSS Convocatorias con cache busting -->
<link
    rel="stylesheet"
    href="<?= BASE_URL ?>public/css/convocatorias.css?v=<?= urlencode((string)@filemtime(__DIR__ . '/../../../public/css/convocatorias.css')) ?>">


<?php if ($mensajeExito !== ''): ?>
    <div class="toast-container position-fixed top-0 end-0 p-3">
        <div
            class="toast system-toast"
            role="status"
            aria-live="polite"
            aria-atomic="true"
            data-bs-delay="3200"
            data-convocatoria-success-toast>
            <div class="toast-body">
                <i class="bi bi-check2-circle"></i>
                <span><?= $texto($mensajeExito) ?></span>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const toastElement = document.querySelector('[data-convocatoria-success-toast]');

        if (toastElement && window.bootstrap) {
            bootstrap.Toast.getOrCreateInstance(toastElement).show();
        }
    });
    </script>
<?php endif; ?>

<?php if ($mensajeError !== ''): ?>
    <div class="toast-container position-fixed top-0 end-0 p-3">
        <div
            class="toast system-toast"
            role="alert"
            aria-live="assertive"
            aria-atomic="true"
            data-bs-delay="4200"
            data-convocatoria-error-toast>
            <div class="toast-body">
                <i class="bi bi-exclamation-circle"></i>
                <span><?= $texto($mensajeError) ?></span>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const toastElement = document.querySelector('[data-convocatoria-error-toast]');

        if (toastElement && window.bootstrap) {
            bootstrap.Toast.getOrCreateInstance(toastElement).show();
        }
    });
    </script>
<?php endif; ?>

<div class="toast-container position-fixed top-0 end-0 p-3">
    <div
        class="toast system-toast"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        data-bs-delay="3200"
        data-convocatoria-download-toast>
        <div class="toast-body">
            <i class="bi bi-check2-circle" data-convocatoria-download-toast-icon></i>
            <span data-convocatoria-download-toast-text>
                La imagen se descargó correctamente.
            </span>
        </div>
    </div>
</div>

<?php if (!$territorioSeleccionado): ?>
<section class="data-territorial-module convocatoria-territory-selector">
    <section class="dashboard-panel data-territorial-selector">
        <div class="data-selector-heading">
            <div class="data-territorial-selector-copy">
                <h2 class="panel-title mb-1">Seleccionar territorio</h2>
                <p>Busca o selecciona un Estado para consultar sus convocatorias.</p>
            </div>
        </div>

        <div class="data-territorial-toolbar convocatoria-territory-toolbar">
            <div class="data-filter-field">
                <label for="buscar_territorio_convocatoria">Buscar territorio</label>
                <div class="module-search">
                    <i class="bi bi-search"></i>
                    <input
                        type="search"
                        class="form-control"
                        id="buscar_territorio_convocatoria"
                        placeholder="Buscar territorio..."
                        aria-label="Buscar territorio"
                        data-convocatoria-territory-search>
                </div>
            </div>
        </div>
    </section>

    <section class="data-territorial-cards" data-convocatoria-territory-cards>
        <?php foreach ($estados as $estado): ?>
            <?php
            $nombreEstado = trim((string)($estado['nombre'] ?? ''));
            $slugEstado = strtolower($nombreEstado);
            $slugEstado = iconv('UTF-8', 'ASCII//TRANSLIT', $slugEstado);
            $slugEstado = preg_replace('/[^a-z0-9]+/', '-', (string)$slugEstado);
            $slugEstado = trim((string)$slugEstado, '-');

            if ($nombreEstado === 'Ciudad de México') {
                $slugEstado = 'ciudad-de-mexico';
            } elseif ($nombreEstado === 'Estado de México') {
                $slugEstado = 'estado-de-mexico';
            } elseif ($nombreEstado === 'Michoacán') {
                $slugEstado = 'michoacán';
            } elseif ($nombreEstado === 'Nuevo León') {
                $slugEstado = 'nuevo-leon';
            } elseif ($nombreEstado === 'Querétaro') {
                $slugEstado = 'queretaro';
            } elseif ($nombreEstado === 'San Luis Potosí') {
                $slugEstado = 'san-luis-potosi';
            } elseif ($nombreEstado === 'Yucatán') {
                $slugEstado = 'yucatan';
            }

            $imagenEstado = BASE_URL . 'public/img/estados/' . $slugEstado . '.png';
            ?>
            <article
                class="dashboard-panel data-territorial-card convocatoria-territory-card"
                data-convocatoria-territory-card
                data-territory-name="<?= $texto(mb_strtolower($nombreEstado, 'UTF-8')) ?>">
                <div class="data-card-heading">
                    <h3><?= $texto($nombreEstado) ?></h3>
                </div>

                <div class="convocatoria-territory-map">
                    <img
                        src="<?= $texto($imagenEstado) ?>"
                        alt="Mapa de <?= $texto($nombreEstado) ?>">
                </div>

                <a
                    class="btn btn-system-light"
                    href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index&territorio_id=<?= (int)$estado['id'] ?>">
                    Ver convocatorias
                </a>
            </article>
        <?php endforeach; ?>
    </section>

    <div
        class="convocatoria-territory-count"
        data-convocatoria-territory-count
        data-total-territories="<?= count($estados) ?>">
        <?php if (!empty($estados)): ?>
            Mostrando 1 a <?= count($estados) ?> de <?= count($estados) ?> territorios
        <?php else: ?>
            Mostrando 0 de 0 territorios
        <?php endif; ?>
    </div>

    <section
        class="dashboard-panel data-empty-state d-none"
        data-convocatoria-territory-empty>
        <span><i class="bi bi-map"></i></span>
        <strong>No se encontraron territorios.</strong>
        <p>Prueba con otro nombre de estado.</p>
    </section>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const search = document.querySelector('[data-convocatoria-territory-search]');
    const cards = Array.from(document.querySelectorAll('[data-convocatoria-territory-card]'));
    const empty = document.querySelector('[data-convocatoria-territory-empty]');
    const counter = document.querySelector('[data-convocatoria-territory-count]');
    const totalTerritorios = cards.length;
    let timer = null;

    const normalizar = function (valor) {
        return String(valor || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    };

    const aplicarFiltro = function () {
        const term = normalizar(search?.value || '');
        let visibles = 0;

        cards.forEach(function (card) {
            const name = normalizar(card.dataset.territoryName || '');
            const mostrar = term === '' || name.includes(term);
            card.classList.toggle('d-none', !mostrar);

            if (mostrar) {
                visibles++;
            }
        });

        empty?.classList.toggle('d-none', visibles > 0);

        if (counter) {
            counter.textContent = visibles > 0
                ? 'Mostrando 1 a ' + visibles + ' de ' + totalTerritorios + ' territorios'
                : 'Mostrando 0 de ' + totalTerritorios + ' territorios';
        }
    };

    search?.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(aplicarFiltro, 300);
    });
});
</script>
<?php return; ?>
<?php endif; ?>

<?php if ($tipoConvocatoria === '' && empty($desdeConvenio)): ?>
<?php
$nombreTerritorioActual = trim((string)($territorioSeleccionado['nombre'] ?? ''));
$slugTerritorioActual = strtolower($nombreTerritorioActual);
$slugTerritorioActual = iconv('UTF-8', 'ASCII//TRANSLIT', $slugTerritorioActual);
$slugTerritorioActual = preg_replace('/[^a-z0-9]+/', '-', (string)$slugTerritorioActual);
$slugTerritorioActual = trim((string)$slugTerritorioActual, '-');

if ($nombreTerritorioActual === 'Ciudad de México') {
    $slugTerritorioActual = 'ciudad-de-mexico';
} elseif ($nombreTerritorioActual === 'Estado de México') {
    $slugTerritorioActual = 'estado-de-mexico';
} elseif ($nombreTerritorioActual === 'Michoacán') {
    $slugTerritorioActual = 'michoacán';
} elseif ($nombreTerritorioActual === 'Nuevo León') {
    $slugTerritorioActual = 'nuevo-leon';
} elseif ($nombreTerritorioActual === 'Querétaro') {
    $slugTerritorioActual = 'queretaro';
} elseif ($nombreTerritorioActual === 'San Luis Potosí') {
    $slugTerritorioActual = 'san-luis-potosi';
} elseif ($nombreTerritorioActual === 'Yucatán') {
    $slugTerritorioActual = 'yucatan';
}

$imagenTerritorioActual = BASE_URL . 'public/img/estados/' . $slugTerritorioActual . '.png';
$descripcionTiposTerritorio = $esChihuahua
    ? 'Consulta las convocatorias de titulación, bachillerato y sindicatos, revisa su vigencia y mantén actualizadas las publicaciones en ' . $nombreTerritorioActual . '.'
    : 'Consulta las convocatorias de titulación y bachillerato, revisa su vigencia y mantén actualizadas las publicaciones en ' . $nombreTerritorioActual . '.';
?>

<div class="convocatoria-overview-page">
    <div class="convocatoria-overview-locationbar">
        <div class="convocatoria-overview-location">
            <i class="bi bi-geo-alt-fill"></i>
            <strong><?= $texto($nombreTerritorioActual) ?></strong>
        </div>

        <a
            class="convocatoria-overview-change"
            href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index">
            <i class="bi bi-arrow-repeat"></i>
            Cambiar territorio
        </a>
    </div>

    <section class="dashboard-panel convocatoria-overview-hero">
        <span class="convocatoria-overview-kicker">CONVOCATORIAS</span>
        <h2>Administra las convocatorias de tu territorio</h2>
        <p><?= $texto($descripcionTiposTerritorio) ?></p>
    </section>

    <div class="convocatoria-overview-layout">
        <main class="convocatoria-overview-main">
            <section class="convocatoria-overview-types<?= $esChihuahua ? ' is-chihuahua' : '' ?>">
                <a
                    class="dashboard-panel convocatoria-overview-type-card"
                    href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index&territorio_id=<?= (int)$territorioSeleccionado['id'] ?>&tipo=titulacion">
                    <span class="convocatoria-overview-type-icon">
                        <i class="bi bi-mortarboard"></i>
                    </span>
                    <span class="convocatoria-overview-type-copy">
                        <strong>Titulación</strong>
                        <small>Consulta las convocatorias correspondientes a titulación.</small>
                    </span>
                    <span class="convocatoria-overview-type-arrow">
                        <i class="bi bi-arrow-right"></i>
                    </span>
                </a>

                <a
                    class="dashboard-panel convocatoria-overview-type-card"
                    href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index&territorio_id=<?= (int)$territorioSeleccionado['id'] ?>&tipo=bachillerato">
                    <span class="convocatoria-overview-type-icon">
                        <i class="bi bi-book"></i>
                    </span>
                    <span class="convocatoria-overview-type-copy">
                        <strong>Bachillerato</strong>
                        <small>Consulta las convocatorias correspondientes a bachillerato.</small>
                    </span>
                    <span class="convocatoria-overview-type-arrow">
                        <i class="bi bi-arrow-right"></i>
                    </span>
                </a>

                <?php if ($esChihuahua): ?>
                    <a
                        class="dashboard-panel convocatoria-overview-type-card"
                        href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index&territorio_id=<?= (int)$territorioSeleccionado['id'] ?>&tipo=sindicatos&subtipo=sindicatos">
                        <span class="convocatoria-overview-type-icon">
                            <i class="bi bi-people"></i>
                        </span>
                        <span class="convocatoria-overview-type-copy">
                            <strong>Sindicatos</strong>
                            <small>Consulta las convocatorias correspondientes a sindicatos.</small>
                        </span>
                        <span class="convocatoria-overview-type-arrow">
                            <i class="bi bi-arrow-right"></i>
                        </span>
                    </a>
                <?php endif; ?>
            </section>

            <section
                class="dashboard-panel convocatoria-overview-recent"
                id="convocatorias-recientes">
                <div class="convocatoria-overview-recent-heading">
                    <div class="convocatoria-overview-recent-title">
                        <span><i class="bi bi-file-earmark-text"></i></span>
                        <div>
                            <h3>Convocatorias recientes</h3>
                            <p>Consulta rápidamente el estado de las publicaciones de este territorio.</p>
                        </div>
                    </div>

                    <?php if (count($convocatoriasRecientes) > 4): ?>
                        <button
                            type="button"
                            class="convocatoria-overview-link"
                            data-convocatoria-overview-all
                            aria-expanded="false">
                            Ver todas
                            <i class="bi bi-arrow-right"></i>
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (!empty($convocatoriasRecientes)): ?>
                    <div class="convocatoria-overview-table-wrap">
                        <table class="convocatoria-overview-table">
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
                                <?php foreach ($convocatoriasRecientes as $indiceReciente => $convocatoriaReciente): ?>
                                    <?php
                                    $tipoReciente = (string)($convocatoriaReciente['tipo_convocatoria'] ?? '');
                                    $subtipoReciente = (string)($convocatoriaReciente['subtipo_convocatoria'] ?? '');
                                    $estadoProceso = (string)($convocatoriaReciente['estado_proceso'] ?? 'inactiva');

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

                                    $estadoEtiquetas = [
                                        'activa' => 'Activa',
                                        'proxima' => 'Próxima a vencer',
                                        'finalizada' => 'Finalizada',
                                        'inactiva' => 'Inactiva'
                                    ];

                                    $fechaInicioReciente = trim(
                                        (string)($convocatoriaReciente['fecha_inicio'] ?? '')
                                    );
                                    $timestampInicioReciente = $fechaInicioReciente !== ''
                                        ? strtotime($fechaInicioReciente)
                                        : false;
                                    $anioReciente = $timestampInicioReciente !== false
                                        ? (int)date('Y', $timestampInicioReciente)
                                        : (int)date('Y');
                                    $mesReciente = $timestampInicioReciente !== false
                                        ? (int)date('n', $timestampInicioReciente)
                                        : (int)date('n');

                                    $urlReciente = BASE_URL .
                                        'index.php?controller=convocatoria&action=index&territorio_id=' .
                                        (int)$territorioSeleccionado['id'] .
                                        '&tipo=' . rawurlencode($tipoReciente);
                                    ?>
                                    <tr
                                        data-convocatoria-overview-row
                                        data-process-status="<?= $texto($estadoProceso) ?>"
                                        class="<?= $indiceReciente >= 4 ? 'd-none' : '' ?>"
                                        <?= $indiceReciente >= 4 ? 'data-convocatoria-overview-extra' : '' ?>>
                                        <td>
                                            <a
                                                class="convocatoria-overview-name"
                                                href="<?= $texto($urlReciente) ?>">
                                                <span class="convocatoria-overview-row-icon">
                                                    <i class="bi <?= $texto($tipoIcono) ?>"></i>
                                                </span>
                                                <strong><?= $texto($convocatoriaReciente['titulo'] ?? '') ?></strong>
                                            </a>
                                        </td>
                                        <td><?= $texto($tipoEtiqueta) ?></td>
                                        <td><?= $texto($fechaLegible($convocatoriaReciente['fecha_inicio'] ?? '')) ?></td>
                                        <td>
                                            <?= $texto($fechaLegible($convocatoriaReciente['fecha_inicio'] ?? '')) ?>
                                            -
                                            <?= $texto($fechaLegible($convocatoriaReciente['fecha_termino'] ?? '')) ?>
                                        </td>
                                        <td>
                                            <span class="convocatoria-process-badge convocatoria-process-<?= $texto($estadoProceso) ?>">
                                                <?= $texto($estadoEtiquetas[$estadoProceso] ?? 'Inactiva') ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <a
                                                class="convocatoria-overview-row-action"
                                                href="<?= $texto($urlReciente) ?>"
                                                aria-label="Abrir <?= $texto($convocatoriaReciente['titulo'] ?? '') ?>">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="convocatoria-recent-empty">
                        <i class="bi bi-inbox"></i>
                        <span>No hay convocatorias recientes para mostrar.</span>
                    </div>
                <?php endif; ?>
            </section>
        </main>

        <aside class="convocatoria-overview-aside">
            <section class="dashboard-panel convocatoria-overview-territory-card">
                <div class="convocatoria-overview-territory-copy">
                    <span class="convocatoria-overview-aside-icon">
                        <i class="bi bi-geo-alt-fill"></i>
                    </span>
                    <div>
                        <small>Territorio actual</small>
                        <strong><?= $texto($nombreTerritorioActual) ?></strong>
                        <a
                            href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index">
                            <i class="bi bi-arrow-repeat"></i>
                            Cambiar territorio
                        </a>
                    </div>
                </div>

                <img
                    src="<?= $texto($imagenTerritorioActual) ?>"
                    alt="Mapa de <?= $texto($nombreTerritorioActual) ?>"
                    class="convocatoria-overview-territory-map">
            </section>

            <section class="dashboard-panel convocatoria-overview-help">
                <div class="convocatoria-overview-help-heading">
                    <span class="convocatoria-overview-aside-icon">
                        <i class="bi bi-clipboard2"></i>
                    </span>
                    <div>
                        <h3>¿Qué puedes hacer aquí?</h3>
                        <p>Consulta las convocatorias vigentes, revisa próximas publicaciones y da seguimiento a su vigencia en tu territorio.</p>
                    </div>
                </div>

                <div class="convocatoria-overview-help-links">
                    <a href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index&territorio_id=<?= (int)$territorioSeleccionado['id'] ?>&tipo=titulacion">
                        <span><i class="bi bi-mortarboard"></i></span>
                        <strong>Ver convocatorias de titulación</strong>
                        <i class="bi bi-chevron-right"></i>
                    </a>

                    <a href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index&territorio_id=<?= (int)$territorioSeleccionado['id'] ?>&tipo=bachillerato">
                        <span><i class="bi bi-book"></i></span>
                        <strong>Ver convocatorias de bachillerato</strong>
                        <i class="bi bi-chevron-right"></i>
                    </a>

                    <button
                        type="button"
                        data-convocatoria-overview-filter="proxima">
                        <span><i class="bi bi-clock"></i></span>
                        <strong>Revisar próximas a vencer</strong>
                        <i class="bi bi-chevron-right"></i>
                    </button>

                    <button
                        type="button"
                        data-convocatoria-overview-filter="finalizada">
                        <span><i class="bi bi-file-earmark-text"></i></span>
                        <strong>Consultar convocatorias finalizadas</strong>
                        <i class="bi bi-chevron-right"></i>
                    </button>

                    <?php if ($esChihuahua): ?>
                        <a href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index&territorio_id=<?= (int)$territorioSeleccionado['id'] ?>&tipo=sindicatos&subtipo=sindicatos">
                            <span><i class="bi bi-people"></i></span>
                            <strong>Ver convocatorias de sindicatos</strong>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </section>
        </aside>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const filas = Array.from(document.querySelectorAll('[data-convocatoria-overview-row]'));
    const extras = Array.from(document.querySelectorAll('[data-convocatoria-overview-extra]'));
    const botonTodas = document.querySelector('[data-convocatoria-overview-all]');
    const filtrosRapidos = Array.from(document.querySelectorAll('[data-convocatoria-overview-filter]'));
    const panelRecientes = document.getElementById('convocatorias-recientes');

    const mostrarTodas = function () {
        filas.forEach(function (fila) {
            fila.classList.remove('d-none');
        });

        if (botonTodas) {
            botonTodas.setAttribute('aria-expanded', 'true');
            botonTodas.innerHTML = 'Ver menos <i class="bi bi-arrow-up"></i>';
        }
    };

    botonTodas?.addEventListener('click', function () {
        const expandido = botonTodas.getAttribute('aria-expanded') === 'true';

        if (expandido) {
            extras.forEach(function (fila) {
                fila.classList.add('d-none');
            });
            botonTodas.setAttribute('aria-expanded', 'false');
            botonTodas.innerHTML = 'Ver todas <i class="bi bi-arrow-right"></i>';
            return;
        }

        mostrarTodas();
    });

    filtrosRapidos.forEach(function (boton) {
        boton.addEventListener('click', function () {
            const estado = boton.dataset.convocatoriaOverviewFilter || '';

            filas.forEach(function (fila) {
                fila.classList.toggle(
                    'd-none',
                    fila.dataset.processStatus !== estado
                );
            });

            if (botonTodas) {
                botonTodas.setAttribute('aria-expanded', 'true');
                botonTodas.innerHTML = 'Ver todas <i class="bi bi-arrow-right"></i>';
            }

            panelRecientes?.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        });
    });
});
</script>

<?php return; ?>
<?php endif; ?>







<div class="convocatoria-territory-context">
    <a
        class="data-back-link"
        href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index&territorio_id=<?= (int)$territorioSeleccionado['id'] ?>">
        <i class="bi bi-arrow-left"></i>
        Cambiar tipo
    </a>

    <span>
        <?= $texto($territorioSeleccionado['nombre'] ?? '') ?>
        <?php if ($tipoConvocatoria === 'titulacion'): ?>
            · Titulación
        <?php elseif ($tipoConvocatoria === 'bachillerato'): ?>
            · Bachillerato
        <?php elseif ($tipoConvocatoria === 'sindicatos'): ?>
            · Sindicatos
        <?php endif; ?>
        <?php if ($clasificacionFiltroActual !== ''): ?>
            · <?= $texto(
                array_values(array_filter(
                    $clasificacionesConvocatoria,
                    static fn($item) => $item['valor'] === $clasificacionFiltroActual
                ))[0]['etiqueta'] ?? ''
            ) ?>
        <?php endif; ?>
    </span>
</div>

<?php
$convocatoriasActivasIniciales = array_values(array_filter(
    $convocatorias,
    static fn($convocatoria) => (int)($convocatoria['estado'] ?? 0) === 1
));

$convocatoriasHistorialIniciales = array_values(array_filter(
    $convocatorias,
    static fn($convocatoria) => (int)($convocatoria['estado'] ?? 0) !== 1
));
?>

<div class="convocatoria-status-tabs" role="tablist" aria-label="Estado de convocatorias">
    <button
        type="button"
        class="convocatoria-status-tab<?= $vistaConvocatoriasInicial === 'activas' ? ' is-active' : '' ?>"
        data-convocatoria-view="activas"
        role="tab"
        aria-selected="<?= $vistaConvocatoriasInicial === 'activas' ? 'true' : 'false' ?>">
        <span class="convocatoria-status-tab-icon">
            <i class="bi bi-megaphone"></i>
        </span>
        <span>Convocatorias activas</span>
        <strong data-convocatoria-count="activas"><?= count($convocatoriasActivasIniciales) ?></strong>
    </button>

    <button
        type="button"
        class="convocatoria-status-tab<?= $vistaConvocatoriasInicial === 'historial' ? ' is-active' : '' ?>"
        data-convocatoria-view="historial"
        role="tab"
        aria-selected="<?= $vistaConvocatoriasInicial === 'historial' ? 'true' : 'false' ?>">
        <span class="convocatoria-status-tab-icon">
            <i class="bi bi-archive"></i>
        </span>
        <span>Historial / Desactivadas</span>
        <strong data-convocatoria-count="historial"><?= count($convocatoriasHistorialIniciales) ?></strong>
    </button>
</div>

<section class="dashboard-panel convocatoria-list-workspace mt-3">
    <div class="convocatoria-list-workspace-header">
        <div class="convocatoria-list-workspace-title">
            <span class="convocatoria-list-workspace-icon is-active" data-convocatoria-heading-icon>
                <i class="bi bi-megaphone"></i>
            </span>

            <div>
                <h2 data-convocatoria-heading>Convocatorias activas</h2>
                <p data-convocatoria-heading-copy>
                    Convocatorias vigentes o nuevas disponibles para consulta y gestión.
                </p>
            </div>
        </div>

        <div class="module-toolbar-actions" data-convocatoria-create-action>
            <?php if ($puedeCrear): ?>
                <button
                    type="button"
                    class="btn btn-system-save"
                    data-bs-toggle="modal"
                    data-bs-target="#modalCrearConvocatoria">
                    <i class="bi bi-plus-circle me-2"></i>
                    Nueva convocatoria
                </button>
            <?php endif; ?>
        </div>
    </div>

    <form
        method="GET"
        action="<?= BASE_URL ?>index.php"
        class="convocatoria-filter-bar convocatoria-list-workspace-filters">
        <input type="hidden" name="controller" value="convocatoria">
        <input type="hidden" name="action" value="index">
        <input type="hidden" name="territorio_id" value="<?= (int)$territorioSeleccionado['id'] ?>">
        <input type="hidden" name="tipo" value="<?= $texto($tipoConvocatoria) ?>">
        <input
            type="hidden"
            name="vista"
            value="<?= $texto($vistaConvocatoriasInicial) ?>"
            data-convocatoria-vista>

        <div class="convocatoria-filter-field convocatoria-filter-search">
            <label class="form-label login-label" for="filtro_convocatoria_buscar">Buscar convocatoria</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input
                    type="search"
                    class="form-control system-form-control"
                    id="filtro_convocatoria_buscar"
                    name="buscar"
                    placeholder="Buscar convocatoria..."
                    value="<?= $texto($buscar) ?>">
            </div>
        </div>

        <div class="convocatoria-filter-field">
            <label
                class="form-label login-label"
                for="filtro_convocatoria_clasificacion_boton">
                Convocatoria
            </label>

            <?php
            $clasificacionFiltroEtiqueta = 'Todas las convocatorias';

            foreach ($clasificacionesDisponibles as $clasificacion) {
                if ($clasificacionFiltroActual === $clasificacion['valor']) {
                    $clasificacionFiltroEtiqueta =
                        (string)$clasificacion['etiqueta'];
                    break;
                }
            }
            ?>

            <div
                class="convocatoria-filter-dropdown"
                data-convocatoria-filter-dropdown>

                <input
                    type="hidden"
                    id="filtro_convocatoria_clasificacion"
                    name="clasificacion"
                    value="<?= $texto($clasificacionFiltroActual) ?>"
                    data-convocatoria-clasificacion>

                <button
                    type="button"
                    class="convocatoria-filter-dropdown-trigger"
                    id="filtro_convocatoria_clasificacion_boton"
                    aria-haspopup="listbox"
                    aria-expanded="false"
                    data-convocatoria-filter-trigger>
                    <span data-convocatoria-filter-label>
                        <?= $texto($clasificacionFiltroEtiqueta) ?>
                    </span>
                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                </button>

                <div
                    class="convocatoria-filter-dropdown-menu"
                    role="listbox"
                    aria-label="Seleccionar convocatoria"
                    data-convocatoria-filter-menu>

                    <button
                        type="button"
                        class="convocatoria-filter-dropdown-option <?= $clasificacionFiltroActual === '' ? 'is-selected' : '' ?>"
                        role="option"
                        aria-selected="<?= $clasificacionFiltroActual === '' ? 'true' : 'false' ?>"
                        data-convocatoria-filter-value="">
                        Todas las convocatorias
                    </button>

                    <?php foreach ($clasificacionesDisponibles as $clasificacion): ?>
                        <button
                            type="button"
                            class="convocatoria-filter-dropdown-option <?= $clasificacionFiltroActual === $clasificacion['valor'] ? 'is-selected' : '' ?>"
                            role="option"
                            aria-selected="<?= $clasificacionFiltroActual === $clasificacion['valor'] ? 'true' : 'false' ?>"
                            data-convocatoria-filter-value="<?= $texto($clasificacion['valor']) ?>">
                            <?= $texto($clasificacion['etiqueta']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="convocatoria-filter-field">
            <label class="form-label login-label" for="filtro_convocatoria_fecha">Fecha</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                <input
                    type="date"
                    class="form-control system-form-control"
                    id="filtro_convocatoria_fecha"
                    name="fecha"
                    value="<?= $texto($fechaFiltro) ?>"
>
            </div>
        </div>

        <input
            type="hidden"
            id="filtro_convocatoria_categoria"
            name="categoria"
            value="<?= $texto($categoriaFiltro) ?>">

        <div class="convocatoria-filter-actions">
            <a
                class="filter-clear-link <?= ($buscar === '' && $fechaFiltro === '' && $categoriaFiltro === '' && $clasificacionFiltroActual === '') ? 'd-none' : '' ?>"
                href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index&territorio_id=<?= (int)$territorioSeleccionado['id'] ?>&tipo=<?= $texto($tipoConvocatoria) ?>"
                data-convocatoria-clear-filters>
                Limpiar filtros
            </a>
        </div>
    </form>

    <div
        class="convocatoria-month-groups"
        data-convocatorias-listado
        aria-live="polite">
    </div>
</section>

<div
    class="modal fade"
    id="modalImagenConvocatoria"
    tabindex="-1"
    aria-labelledby="modalImagenConvocatoriaTitulo"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered convocatoria-image-dialog">
        <div class="modal-content convocatoria-image-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="modalImagenConvocatoriaTitulo">Imagen de la convocatoria</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <img
                    src=""
                    alt=""
                    class="convocatoria-image-preview"
                    data-convocatoria-image-preview>
            </div>
        </div>
    </div>
</div>

<div
    class="offcanvas offcanvas-end user-detail-panel"
    tabindex="-1"
    id="offcanvasDetalleConvocatoria"
    aria-labelledby="offcanvasDetalleConvocatoriaTitulo">

    <div class="offcanvas-header user-detail-header">
        <div>
            <h5 class="user-detail-title" id="offcanvasDetalleConvocatoriaTitulo">Detalle de la convocatoria</h5>
            <p class="user-detail-subtitle">Información de la publicación</p>
        </div>

        <button
            type="button"
            class="btn-close user-detail-close"
            data-bs-dismiss="offcanvas"
            aria-label="Cerrar">
        </button>
    </div>

    <div class="offcanvas-body user-detail-body" id="convocatoriaDetalleContenido">
        <div class="empty-table-message">Selecciona una convocatoria para consultar su detalle.</div>
    </div>
</div>

<div
    class="modal fade"
    id="modalCrearConvocatoria"
    tabindex="-1"
    aria-labelledby="modalCrearConvocatoriaTitulo"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg system-form-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5 class="system-form-modal-title" id="modalCrearConvocatoriaTitulo">Nueva convocatoria</h5>
                    <p class="system-form-modal-subtitle">Registra una publicación y su cobertura territorial</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <form
                class="convocatoria-create-form"
                action="<?= BASE_URL ?>index.php?controller=convocatoria&action=guardar"
                method="POST"
                enctype="multipart/form-data"
                novalidate>
                <input type="hidden" name="territorio_id" value="<?= (int)$territorioSeleccionado['id'] ?>">
                <?php if ($aliadoContextoConvocatoria): ?>
                    <input type="hidden" name="aliado_tipo" value="<?= $texto($_GET['aliado_tipo'] ?? '') ?>">
                    <input type="hidden" name="aliado_id" value="<?= (int)($_GET['aliado_id'] ?? 0) ?>">
                    <input type="hidden" name="programa_codigo" data-programa-aliado-codigo value="<?= $texto($datosCrear['programa_codigo'] ?? '') ?>">
                <?php endif; ?>
                <input
                    type="hidden"
                    name="seguimiento_origen_id"
                    value="<?= !empty($desdeConvenio) ? (int)$seguimientoOrigenId : 0 ?>">
        <input type="hidden" name="tipo" value="<?= $texto($tipoConvocatoria) ?>">
                <input
                    type="hidden"
                    name="retorno_query"
                    value="<?= $texto(http_build_query($_GET)) ?>">
                <input
                    type="hidden"
                    name="vista"
                    value="<?= $texto($vistaConvocatoriasInicial) ?>"
                    data-convocatoria-vista>

                <div class="modal-body">
                    <div class="system-form-grid">
                        <?php if ($aliadoContextoConvocatoria): ?>
                            <section class="system-form-full convocatoria-aliado-programas" data-convocatoria-programas-aliado>
                                <span class="aliados-eyebrow">PROGRAMAS DEL ALIADO</span>
                                <h3><?= $texto($aliadoContextoConvocatoria['origen']['nombre_entidad'] ?? 'Aliado') ?></h3>
                                <p>Elige un programa para esta publicación. Cada programa lleva su propia imagen y clasificación, pero todos conservan el mismo enlace y QR.</p>
                                <?php if (!$opcionesProgramaAliado): ?>
                                    <div class="alert alert-warning">Este aliado no tiene programas compatibles con los tipos de convocatoria actuales.</div>
                                <?php else: ?>
                                    <div class="convocatoria-aliado-programas-grid">
                                        <?php foreach ($opcionesProgramaAliado as $opcionAliado): ?>
                                            <button type="button" class="convocatoria-aliado-programa-option" data-aliado-programa-crear
                                                data-programa-codigo="<?= $texto($opcionAliado['codigo']) ?>"
                                                data-programa-clasificacion="<?= $texto($opcionAliado['clasificacion']) ?>">
                                                <i class="bi bi-mortarboard" aria-hidden="true"></i>
                                                <span><?= $texto($opcionAliado['nombre']) ?></span>
                                                <i class="bi bi-check-circle" aria-hidden="true"></i>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>
                        <div class="system-form-full">
                            <?php
                            $tipoCrearActual = strtolower(
                                trim((string)($datosCrear['tipo'] ?? ''))
                            );
                            $subtipoCrearActual = strtolower(
                                trim((string)($datosCrear['subtipo'] ?? ''))
                            );
                            $clasificacionCrear = (
                                $tipoCrearActual !== '' &&
                                $subtipoCrearActual !== ''
                            )
                                ? $tipoCrearActual . '|' . $subtipoCrearActual
                                : '';
                            $clasificacionCrearLabel = 'Selecciona una opción';

                            foreach ($clasificacionesDisponibles as $clasificacion) {
                                if ($clasificacionCrear === $clasificacion['valor']) {
                                    $clasificacionCrearLabel = (string)$clasificacion['etiqueta'];
                                    break;
                                }
                            }
                            ?>

                            <label class="form-label login-label" for="crear_convocatoria_clasificacion_boton">
                                <?= $aliadoContextoConvocatoria ? 'Programa de esta convocatoria' : 'Tipo de convocatoria' ?>
                            </label>

                            <div
                                class="convocatoria-form-type-dropdown"
                                data-convocatoria-classification-dropdown>
                                <input
                                    type="hidden"
                                    id="crear_convocatoria_clasificacion"
                                    name="clasificacion"
                                    value="<?= $texto($clasificacionCrear) ?>"
                                    data-convocatoria-context-classification
                                    data-convocatoria-classification-value
                                    required>

                                <button
                                    type="button"
                                    class="convocatoria-form-type-trigger"
                                    id="crear_convocatoria_clasificacion_boton"
                                    aria-haspopup="listbox"
                                    aria-expanded="false"
                                    <?= $aliadoContextoConvocatoria ? 'disabled title="El programa se selecciona en las tarjetas superiores"' : '' ?>
                                    data-convocatoria-classification-trigger>
                                    <span data-convocatoria-classification-label>
                                        <?= $texto($clasificacionCrearLabel) ?>
                                    </span>
                                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                </button>

                                <div
                                    class="convocatoria-form-type-menu"
                                    role="listbox"
                                    aria-label="Seleccionar tipo de convocatoria"
                                    data-convocatoria-classification-menu>
                                    <button
                                        type="button"
                                        class="convocatoria-form-type-option <?= $clasificacionCrear === '' ? 'is-selected' : '' ?>"
                                        role="option"
                                        aria-selected="<?= $clasificacionCrear === '' ? 'true' : 'false' ?>"
                                        data-convocatoria-classification-option="">
                                        Selecciona una opción
                                    </button>

                                    <?php foreach ($clasificacionesDisponibles as $clasificacion): ?>
                                        <button
                                            type="button"
                                            class="convocatoria-form-type-option <?= $clasificacionCrear === $clasificacion['valor'] ? 'is-selected' : '' ?>"
                                            role="option"
                                            aria-selected="<?= $clasificacionCrear === $clasificacion['valor'] ? 'true' : 'false' ?>"
                                            data-convocatoria-classification-option="<?= $texto($clasificacion['valor']) ?>">
                                            <?= $texto($clasificacion['etiqueta']) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <small class="form-text">
                                Selecciona la opción a la que pertenece esta convocatoria.
                            </small>
                        </div>

<div class="system-form-full convocatoria-public-form-share"
                            data-convocatoria-form-share
                            data-aliado-registro-contexto="<?= $aliadoContextoConvocatoria ? '1' : '0' ?>"
                            data-aliado-registro-url="<?= $texto($aliadoContextoConvocatoria['url'] ?? '') ?>"
                            data-aliado-registro-habilitado="<?= !empty($aliadoContextoConvocatoria['habilitado']) ? '1' : '0' ?>"
                            data-aliado-registro-tipo="<?= $texto($_GET['aliado_tipo'] ?? '') ?>"
                            data-aliado-registro-id="<?= (int)($_GET['aliado_id'] ?? 0) ?>"
                            data-registro-url="<?= BASE_URL ?>index.php?controller=formularioPublico&action=registro"
                            data-inscripcion-url="<?= BASE_URL ?>index.php?controller=formularioPublico&action=inscripcion"
                            data-aliado-id="<?= (int)($seguimientoOrigenId ?? 0) ?>"
                            data-convenio-nombre="<?= $texto($convenioNombreOrigen ?? '') ?>"
                            data-estado-id="<?= (int)($territorioSeleccionado['id'] ?? 0) ?>"
                            data-estado-nombre="<?= $texto($territorioSeleccionado['nombre'] ?? '') ?>"
                            data-quickchart-url="https://quickchart.io/qr">

                            <div class="convocatoria-public-form-heading">
                                <div>
                                    <span class="convocatoria-public-form-kicker">FORMULARIO PÚBLICO</span>
                                    <strong><?= $aliadoContextoConvocatoria ? 'Enlace y QR compartidos del aliado' : 'Enlace y código QR' ?></strong>
                                    <small>
                                        <?= $aliadoContextoConvocatoria ? 'Todos los programas de este aliado comparten la misma URL y QR de Registro.' : 'Selecciona el formulario que se utilizará para esta convocatoria.' ?>
                                    </small>
                                </div>
                            </div>

                            <div class="convocatoria-public-form-grid">
                                <div>
                                    <label
                                        class="form-label login-label"
                                        for="crear_convocatoria_formulario_publico">
                                        Formulario
                                    </label>
                                    <div
                                        class="convocatoria-form-type-dropdown <?= $aliadoContextoConvocatoria ? 'd-none' : '' ?>"
                                        data-convocatoria-form-type-dropdown>
                                        <input
                                            type="hidden"
                                            id="crear_convocatoria_formulario_publico"
                                            value="<?= $aliadoContextoConvocatoria ? 'registro' : '' ?>"
                                            data-convocatoria-form-type>

                                        <button
                                            type="button"
                                            class="convocatoria-form-type-trigger"
                                            aria-haspopup="listbox"
                                            aria-expanded="false"
                                            data-convocatoria-form-type-trigger>
                                            <span data-convocatoria-form-type-label>
                                                Selecciona una opción
                                            </span>
                                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                        </button>

                                        <div
                                            class="convocatoria-form-type-menu"
                                            role="listbox"
                                            aria-label="Seleccionar formulario"
                                            data-convocatoria-form-type-menu>
                                            <button
                                                type="button"
                                                class="convocatoria-form-type-option is-selected"
                                                role="option"
                                                aria-selected="true"
                                                data-convocatoria-form-type-value="">
                                                Selecciona una opción
                                            </button>
                                            <button
                                                type="button"
                                                class="convocatoria-form-type-option"
                                                role="option"
                                                aria-selected="false"
                                                data-convocatoria-form-type-value="registro">
                                                Registro
                                            </button>
                                            <button
                                                type="button"
                                                class="convocatoria-form-type-option"
                                                role="option"
                                                aria-selected="false"
                                                data-convocatoria-form-type-value="inscripcion">
                                                Inscripción
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="convocatoria-public-form-actions">
                                    <button
                                        type="button"
                                        class="btn btn-system-save"
                                        data-convocatoria-generate-link>
                                        <i class="bi bi-link-45deg"></i>
                                        Generar link
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-system-save"
                                        data-convocatoria-generate-qr
                                        disabled>
                                        <i class="bi bi-qr-code"></i>
                                        Generar QR
                                    </button>
                                </div>
                            </div>

                            <?php if ($aliadoContextoConvocatoria): ?>
                                <div class="convocatoria-aliado-enlace-estado">
                                    <span data-aliado-habilitacion-estado></span>
                                    <button type="button" class="btn btn-system-light" data-aliado-habilitar-registro hidden>Habilitar Registro</button>
                                </div>
                                <div class="convocatoria-aliado-qr-panel d-none" data-aliado-qr-panel>
                                    <canvas data-aliado-qr-canvas role="img" aria-label="Código QR del formulario de Registro del aliado"></canvas>
                                    <div class="d-flex flex-wrap gap-2 justify-content-center mt-2">
                                        <button type="button" class="btn btn-system-light" data-aliado-qr-copy><i class="bi bi-copy"></i> Copiar QR</button>
                                        <button type="button" class="btn btn-system-light" data-aliado-qr-download><i class="bi bi-download"></i> Descargar QR</button>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div
                                class="convocatoria-public-form-result d-none"
                                data-convocatoria-form-result>
                                <div class="convocatoria-public-form-url">
                                    <i class="bi bi-link-45deg"></i>
                                    <input
                                        type="text"
                                        readonly
                                        data-convocatoria-form-url>
                                    <button
                                        type="button"
                                        class="btn"
                                        title="Copiar enlace"
                                        aria-label="Copiar enlace"
                                        data-convocatoria-copy-link>
                                        <i class="bi bi-copy"></i>
                                    </button>
                                </div>

                            </div>
                        </div>

                        <div class="system-form-full">
                            <label class="form-label login-label" for="crear_convocatoria_enlace_registro">
                                Enlace de registro
                            </label>
                            <input
                                type="url"
                                class="form-control system-form-control"
                                id="crear_convocatoria_enlace_registro"
                                name="enlace_registro"
                                data-convocatoria-link-input
                                maxlength="1000"
                                placeholder="https://..."
                                value="<?= $texto($datosCrear['enlace_registro'] ?? '') ?>">
                            <small class="form-text">
                                <?= $aliadoContextoConvocatoria ? 'El enlace pertenece al aliado y es idéntico para todas sus convocatorias.' : 'Opcional. Cuenta Clave podrá copiar este enlace al preparar la convocatoria para WhatsApp.' ?>
                            </small>
                        </div>

<div class="system-form-full">
                            <label class="form-label login-label" for="crear_convocatoria_titulo">Título</label>
                            <input
                                type="text"
                                class="form-control system-form-control"
                                id="crear_convocatoria_titulo"
                                name="titulo"
                                data-convocatoria-context-title
                                value="<?= $texto($datosCrear['titulo'] ?? '') ?>"
                                required>
                        </div>

                        

                        <div>
                            <label class="form-label login-label" for="crear_convocatoria_fecha_inicio">Fecha de inicio</label>
                            <input
                                type="date"
                                class="form-control system-form-control"
                                id="crear_convocatoria_fecha_inicio"
                                name="fecha_inicio"
                                value="<?= $texto($datosCrear['fecha_inicio'] ?? '') ?>"
                                >
                        </div>

                        <div>
                            <label class="form-label login-label" for="crear_convocatoria_fecha_termino">Fecha de término</label>
                            <input
                                type="date"
                                class="form-control system-form-control"
                                id="crear_convocatoria_fecha_termino"
                                name="fecha_termino"
                                value="<?= $texto($datosCrear['fecha_termino'] ?? '') ?>"
                                <?= $fechasOpcionalesConvocatoria ? '' : 'required' ?>>
                        </div>

                        <div class="system-form-full">
                            <small class="form-text">
                                Define la vigencia de la convocatoria. En Inscripciones Abiertas las fechas pueden ser opcionales.
                            </small>
                        </div>

                        <div>
                            <label class="form-label login-label" for="crear_convocatoria_estado_boton">
                                Disponibilidad
                            </label>

                            <div
                                class="convocatoria-form-type-dropdown"
                                data-convocatoria-availability-dropdown>
                                <input
                                    type="hidden"
                                    id="crear_convocatoria_estado"
                                    name="estado"
                                    value="<?= $texto($datosCrear['estado'] ?? '1') ?>"
                                    data-convocatoria-availability-value>

                                <button
                                    type="button"
                                    class="convocatoria-form-type-trigger"
                                    id="crear_convocatoria_estado_boton"
                                    aria-haspopup="listbox"
                                    aria-expanded="false"
                                    data-convocatoria-availability-trigger>
                                    <span data-convocatoria-availability-label>
                                        <?= (string)($datosCrear['estado'] ?? '1') === '0' ? 'Inactiva' : 'Activa' ?>
                                    </span>
                                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                </button>

                                <div
                                    class="convocatoria-form-type-menu"
                                    role="listbox"
                                    aria-label="Seleccionar disponibilidad"
                                    data-convocatoria-availability-menu>
                                    <button
                                        type="button"
                                        class="convocatoria-form-type-option <?= (string)($datosCrear['estado'] ?? '1') !== '0' ? 'is-selected' : '' ?>"
                                        role="option"
                                        aria-selected="<?= (string)($datosCrear['estado'] ?? '1') !== '0' ? 'true' : 'false' ?>"
                                        data-convocatoria-availability-option="1">
                                        Activa
                                    </button>

                                    <button
                                        type="button"
                                        class="convocatoria-form-type-option <?= (string)($datosCrear['estado'] ?? '1') === '0' ? 'is-selected' : '' ?>"
                                        role="option"
                                        aria-selected="<?= (string)($datosCrear['estado'] ?? '1') === '0' ? 'true' : 'false' ?>"
                                        data-convocatoria-availability-option="0">
                                        Inactiva
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="form-label login-label" for="crear_convocatoria_imagen">Imagen</label>
                            <input
                                type="file"
                                class="form-control system-form-control"
                                id="crear_convocatoria_imagen"
                                name="imagen"
                                accept="image/jpeg,image/png,image/webp"
                                required>
                        </div>

                        <input
                            type="hidden"
                            name="estados[]"
                            value="<?= $territorioActualId ?>">

                        
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-system-save">
                        <i class="bi bi-check2-circle me-2"></i>
                        Guardar convocatoria
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div
    class="modal fade"
    id="modalConvocatoriaQr"
    tabindex="-1"
    aria-labelledby="modalConvocatoriaQrTitulo"
    aria-hidden="true"
    data-convocatoria-qr-modal>
    <div class="modal-dialog modal-dialog-centered modal-xl convocatoria-qr-dialog">
        <div class="modal-content system-form-modal convocatoria-qr-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <span class="convocatoria-public-form-kicker">CÓDIGO QR</span>
                    <h5 class="system-form-modal-title" id="modalConvocatoriaQrTitulo">
                        Personalizar código QR
                    </h5>
                    <p class="system-form-modal-subtitle">
                        Los cambios se reflejan automáticamente en la vista previa.
                    </p>
                </div>
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Cerrar"></button>
            </div>

            <div class="modal-body">
                <div class="convocatoria-qr-workspace">
                    <div class="convocatoria-qr-preview-column">
                        <div class="convocatoria-qr-preview">
                            <div
                                class="spinner-border text-primary d-none"
                                role="status"
                                data-convocatoria-qr-loader>
                                <span class="visually-hidden">Cargando...</span>
                            </div>
                            <img
                                src=""
                                alt="Código QR del formulario seleccionado"
                                data-convocatoria-qr-preview>
                        </div>

                        <div class="convocatoria-qr-link" data-convocatoria-qr-link></div>

                        <div class="convocatoria-qr-output-actions">
                            <button
                                type="button"
                                class="btn btn-system-cancel"
                                data-convocatoria-copy-qr>
                                <i class="bi bi-copy"></i>
                                Copiar QR
                            </button>
                            <button
                                type="button"
                                class="btn btn-system-save"
                                data-convocatoria-download-qr>
                                <i class="bi bi-download"></i>
                                Descargar PNG
                            </button>
                        </div>
                    </div>

                    <div class="convocatoria-qr-customizer">
                        <div class="convocatoria-qr-controls">
                            <label class="convocatoria-qr-control">
                                <span>Color del QR</span>
                                <input type="color" value="#223a84" data-convocatoria-qr-dark>
                            </label>

                            <label class="convocatoria-qr-control">
                                <span>Color de fondo</span>
                                <input type="color" value="#ffffff" data-convocatoria-qr-light>
                            </label>

                            <label class="convocatoria-qr-control">
                                <span>Color de esquinas</span>
                                <input type="color" value="#223a84" data-convocatoria-qr-finder-color>
                            </label>

                            <label class="convocatoria-qr-control">
                                <span>Estilo de puntos</span>
                                <select data-convocatoria-qr-dot-style>
                                    <option value="square">Cuadrado</option>
                                    <option value="dots">Circular</option>
                                    <option value="rounded">Redondeado</option>
                                    <option value="extra-rounded">Muy redondeado</option>
                                    <option value="classy">Clásico</option>
                                    <option value="classy-rounded">Clásico redondeado</option>
                                </select>
                            </label>

                            <label class="convocatoria-qr-control">
                                <span>Estilo de esquinas</span>
                                <select data-convocatoria-qr-finder-style>
                                    <option value="square">Cuadrado</option>
                                    <option value="dot">Circular</option>
                                    <option value="extra-rounded">Redondeado</option>
                                </select>
                            </label>

                            <label class="convocatoria-qr-control">
                                <span>Centro de esquinas</span>
                                <select data-convocatoria-qr-finder-dot-style>
                                    <option value="square">Cuadrado</option>
                                    <option value="dot">Circular</option>
                                </select>
                            </label>

                            <label class="convocatoria-qr-control">
                                <span>Tamaño de imagen</span>
                                <select data-convocatoria-qr-size>
                                    <option value="400">400 px</option>
                                    <option value="600" selected>600 px</option>
                                    <option value="800">800 px</option>
                                    <option value="1000">1000 px</option>
                                </select>
                            </label>

                            <label class="convocatoria-qr-control">
                                <span>Margen</span>
                                <select data-convocatoria-qr-margin>
                                    <option value="0">Sin margen</option>
                                    <option value="1">1 módulo</option>
                                    <option value="2" selected>2 módulos</option>
                                    <option value="4">4 módulos</option>
                                </select>
                            </label>

                            <label class="convocatoria-qr-control">
                                <span>Corrección de error</span>
                                <select data-convocatoria-qr-ec-level>
                                    <option value="L">Baja (L)</option>
                                    <option value="M" selected>Media (M)</option>
                                    <option value="Q">Alta (Q)</option>
                                    <option value="H">Máxima (H)</option>
                                </select>
                            </label>
                        </div>

                        <label class="convocatoria-qr-transparent">
                            <input type="checkbox" data-convocatoria-qr-transparent>
                            <span>Fondo transparente</span>
                        </label>

                        <div class="convocatoria-qr-image-options">
                            <div class="convocatoria-qr-image-heading">
                                <div>
                                    <strong>Imagen en el QR</strong>
                                    <small>
                                        Agrega un logotipo o imagen al centro del código QR.
                                    </small>
                                </div>
                                <label class="convocatoria-qr-image-switch">
                                    <input
                                        type="checkbox"
                                        class="form-check-input"
                                        data-convocatoria-qr-image-enabled>
                                    <span>Usar imagen</span>
                                </label>
                            </div>

                            <div class="convocatoria-qr-image-config d-none" data-convocatoria-qr-image-config>
                                <div class="convocatoria-qr-image-source">
                                    <label>
                                        <input
                                            type="radio"
                                            name="convocatoria_qr_image_source"
                                            value="upload"
                                            checked
                                            data-convocatoria-qr-image-source>
                                        Desde mi equipo
                                    </label>
                                    <label>
                                        <input
                                            type="radio"
                                            name="convocatoria_qr_image_source"
                                            value="url"
                                            data-convocatoria-qr-image-source>
                                        URL pública
                                    </label>
                                </div>

                                <div data-convocatoria-qr-upload-container>
                                    <label class="convocatoria-qr-control convocatoria-qr-control-full">
                                        <span>Seleccionar imagen</span>
                                        <input
                                            type="file"
                                            accept="image/png,image/jpeg,image/webp"
                                            data-convocatoria-qr-upload>
                                    </label>
                                    <div class="convocatoria-qr-upload-preview d-none" data-convocatoria-qr-upload-preview>
                                        <img src="" alt="Vista previa de imagen" data-convocatoria-qr-upload-preview-image>
                                        <div>
                                            <strong data-convocatoria-qr-upload-name></strong>
                                            <small>Imagen seleccionada para el centro del QR.</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-none" data-convocatoria-qr-url-container>
                                    <label class="convocatoria-qr-control convocatoria-qr-control-full">
                                        <span>URL pública de la imagen</span>
                                        <input
                                            type="url"
                                            placeholder="https://..."
                                            data-convocatoria-qr-image-url>
                                    </label>
                                </div>

                                <div class="convocatoria-qr-image-size">
                                    <div>
                                        <span>Tamaño de la imagen central</span>
                                        <strong data-convocatoria-qr-image-size-value>20%</strong>
                                    </div>
                                    <input
                                        type="range"
                                        min="10"
                                        max="30"
                                        step="1"
                                        value="20"
                                        data-convocatoria-qr-image-size>
                                </div>
                            </div>
                        </div>

                        <div class="convocatoria-qr-customizer-actions">
                            <button
                                type="button"
                                class="btn btn-system-cancel"
                                data-convocatoria-reset-qr>
                                <i class="bi bi-arrow-counterclockwise"></i>
                                Restablecer
                            </button>
                        </div>
                    </div>
                </div>

                <p class="convocatoria-qr-help">
                    Verifica que el código continúe escaneándose correctamente antes de compartirlo.
                </p>
            </div>
        </div>
    </div>
</div>

<div
    class="modal fade"
    id="modalEditarConvocatoria"
    tabindex="-1"
    aria-labelledby="modalEditarConvocatoriaTitulo"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg system-form-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5 class="system-form-modal-title" id="modalEditarConvocatoriaTitulo">Editar convocatoria</h5>
                    <p class="system-form-modal-subtitle">Actualiza la publicación sin perder su imagen actual</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <form
                id="formEditarConvocatoria"
                action="<?= BASE_URL ?>index.php?controller=convocatoria&action=actualizar"
                method="POST"
                enctype="multipart/form-data"
                novalidate>
                <input type="hidden" name="territorio_id" value="<?= (int)$territorioSeleccionado['id'] ?>">
                <input type="hidden" name="tipo" value="<?= $texto($tipoConvocatoria) ?>">
                <input type="hidden" name="subtipo" value="<?= $texto($subtipoConvocatoria) ?>">
                <input type="hidden" name="anio" value="<?= (int)$anioSeleccionado ?>">
                <input type="hidden" name="mes" value="<?= (int)$mesSeleccionado ?>">
                <input
                    type="hidden"
                    name="retorno_query"
                    value="<?= $texto(http_build_query($_GET)) ?>">
                <input
                    type="hidden"
                    name="vista"
                    value="<?= $texto($vistaConvocatoriasInicial) ?>"
                    data-convocatoria-vista>

                <input type="hidden" name="id" id="editar_convocatoria_id">

                <div class="modal-body">
                    <div class="system-form-grid">
                        <div class="system-form-full">
                            <label class="form-label login-label" for="editar_convocatoria_titulo">Título</label>
                            <input type="text" class="form-control system-form-control" id="editar_convocatoria_titulo" name="titulo" required>
                        </div>

                        <div class="system-form-full">
                            <label class="form-label login-label" for="editar_convocatoria_enlace_registro">
                                Enlace de registro
                            </label>
                            <input
                                type="url"
                                class="form-control system-form-control"
                                id="editar_convocatoria_enlace_registro"
                                name="enlace_registro"
                                maxlength="1000"
                                placeholder="https://...">
                            <small class="form-text">
                                Opcional. Se incluirá en el material preparado para difusión.
                            </small>
                        </div>

                        <div>
                            <label class="form-label login-label" for="editar_convocatoria_fecha_inicio">Fecha de inicio</label>
                            <input
                                type="date"
                                class="form-control system-form-control"
                                id="editar_convocatoria_fecha_inicio"
                                name="fecha_inicio"
                                <?= $fechasOpcionalesConvocatoria ? '' : 'required' ?>>
                        </div>

                        <div>
                            <label class="form-label login-label" for="editar_convocatoria_fecha_termino">Fecha de término</label>
                            <input
                                type="date"
                                class="form-control system-form-control"
                                id="editar_convocatoria_fecha_termino"
                                name="fecha_termino"
                                <?= $fechasOpcionalesConvocatoria ? '' : 'required' ?>>
                        </div>

                        <?php if ($fechasOpcionalesConvocatoria): ?>
                            <div class="system-form-full">
                                <small class="form-text">
                                    En Inscripciones Abiertas las fechas son opcionales.
                                    Puedes conservar únicamente una fecha de inicio o dejar ambas vacías.
                                </small>
                            </div>
                        <?php endif; ?>

                        <div>
                            <label class="form-label login-label" for="editar_convocatoria_estado">Disponibilidad</label>
                            <select class="form-select system-form-control" id="editar_convocatoria_estado" name="estado">
                                <option value="1">Activa</option>
                                <option value="0">Inactiva</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label login-label" for="editar_convocatoria_imagen">Reemplazar imagen</label>
                            <input
                                type="file"
                                class="form-control system-form-control"
                                id="editar_convocatoria_imagen"
                                name="imagen"
                                accept="image/jpeg,image/png,image/webp">
                            <small class="form-text">Si no eliges un archivo, se conservará la imagen actual.</small>
                        </div>

                        <div class="system-form-full">
                            <label class="form-label login-label">Estados adicionales</label>

                            <input
                                type="hidden"
                                name="estados[]"
                                value="<?= $territorioActualId ?>">

                            <button
                                type="button"
                                class="convocatoria-state-toggle"
                                data-bs-toggle="collapse"
                                data-bs-target="#editarEstadosAdicionales"
                                aria-expanded="false"
                                aria-controls="editarEstadosAdicionales">
                                <span>
                                    <i class="bi bi-geo-alt"></i>
                                    Seleccionar otros estados
                                </span>
                                <i class="bi bi-chevron-down"></i>
                            </button>

                            <small class="form-text convocatoria-state-help">
                                Solo utiliza esta opción si deseas publicar también en otros estados.
                                Si no necesitas agregar más, puedes omitirla.
                            </small>

                            <div
                                class="collapse convocatoria-state-extra-panel"
                                id="editarEstadosAdicionales">
                                <div class="convocatoria-state-picker" data-state-picker>
                                    <input
                                        type="search"
                                        class="form-control system-form-control convocatoria-state-search"
                                        placeholder="Buscar estado..."
                                        data-state-search>

                                    <div class="convocatoria-state-list">
                                        <label class="convocatoria-state-option convocatoria-state-option-all">
                                            <input
                                                type="checkbox"
                                                class="form-check-input"
                                                data-state-select-all>
                                            <span>Seleccionar todos</span>
                                        </label>

                                        <?php foreach ($estados as $estado): ?>
                                            <?php $estadoIdActual = (int)$estado['id']; ?>
                                            <?php if ($estadoIdActual === $territorioActualId) { continue; } ?>
                                            <label
                                                class="convocatoria-state-option"
                                                data-state-option
                                                data-state-name="<?= $texto(mb_strtolower((string)$estado['nombre'], 'UTF-8')) ?>">
                                                <input
                                                    type="checkbox"
                                                    class="form-check-input"
                                                    name="estados[]"
                                                    value="<?= $estadoIdActual ?>">
                                                <span><?= $texto($estado['nombre']) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-system-save">
                        <i class="bi bi-check2-circle me-2"></i>
                        Guardar cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div
    class="modal fade"
    id="modalEstadoConvocatoria"
    tabindex="-1"
    aria-labelledby="modalEstadoConvocatoriaTitulo"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered system-confirm-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5 class="system-form-modal-title" id="modalEstadoConvocatoriaTitulo">Cambiar estado</h5>
                    <p class="system-form-modal-subtitle">La convocatoria conservará su información y relaciones</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <form action="<?= BASE_URL ?>index.php?controller=convocatoria&action=cambiarEstado" method="POST">
                <input type="hidden" name="territorio_id" value="<?= (int)$territorioSeleccionado['id'] ?>">
                <input type="hidden" name="tipo" value="<?= $texto($tipoConvocatoria) ?>">
                <input type="hidden" name="subtipo" value="<?= $texto($subtipoConvocatoria) ?>">
                <input type="hidden" name="anio" value="<?= (int)$anioSeleccionado ?>">
                <input type="hidden" name="mes" value="<?= (int)$mesSeleccionado ?>">
                <input
                    type="hidden"
                    name="retorno_query"
                    value="<?= $texto(http_build_query($_GET)) ?>">
                <input
                    type="hidden"
                    name="vista"
                    value="<?= $texto($vistaConvocatoriasInicial) ?>"
                    data-convocatoria-vista>
                <input type="hidden" name="id" id="estado_convocatoria_id">
                <input type="hidden" name="estado" id="estado_convocatoria_nuevo">

                <div class="modal-body">
                    <p class="confirm-text" id="estado_convocatoria_mensaje">
                        ¿Deseas cambiar el estado de esta convocatoria?
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-system-save">Confirmar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const classificationDropdown = document.querySelector(
        '[data-convocatoria-classification-dropdown]'
    );
    const classificationValue = classificationDropdown?.querySelector(
        '[data-convocatoria-classification-value]'
    );
    const classificationTrigger = classificationDropdown?.querySelector(
        '[data-convocatoria-classification-trigger]'
    );
    const classificationLabel = classificationDropdown?.querySelector(
        '[data-convocatoria-classification-label]'
    );
    const classificationOptions = Array.from(
        classificationDropdown?.querySelectorAll(
            '[data-convocatoria-classification-option]'
        ) || []
    );

    const closeClassificationDropdown = function () {
        classificationDropdown?.classList.remove('is-open');
        classificationTrigger?.setAttribute('aria-expanded', 'false');
    };

    classificationTrigger?.addEventListener('click', function () {
        const opening = !classificationDropdown?.classList.contains('is-open');

        if (opening) {
            classificationDropdown?.classList.add('is-open');
            classificationTrigger.setAttribute('aria-expanded', 'true');
        } else {
            closeClassificationDropdown();
        }
    });

    classificationOptions.forEach(function (option) {
        option.addEventListener('click', function () {
            const value = String(
                option.dataset.convocatoriaClassificationOption || ''
            );

            if (classificationValue) {
                classificationValue.value = value;
                classificationValue.dispatchEvent(
                    new Event('change', { bubbles: true })
                );
                classificationValue.dispatchEvent(
                    new Event('input', { bubbles: true })
                );
            }

            if (classificationLabel) {
                classificationLabel.textContent = option.textContent.trim();
            }

            classificationOptions.forEach(function (item) {
                const selected = item === option;
                item.classList.toggle('is-selected', selected);
                item.setAttribute(
                    'aria-selected',
                    selected ? 'true' : 'false'
                );
            });

            closeClassificationDropdown();
        });
    });

    document.addEventListener('click', function (event) {
        if (
            classificationDropdown &&
            !classificationDropdown.contains(event.target)
        ) {
            closeClassificationDropdown();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeClassificationDropdown();
        }
    });

    const availabilityDropdown = document.querySelector(
        '[data-convocatoria-availability-dropdown]'
    );
    const availabilityValue = availabilityDropdown?.querySelector(
        '[data-convocatoria-availability-value]'
    );
    const availabilityTrigger = availabilityDropdown?.querySelector(
        '[data-convocatoria-availability-trigger]'
    );
    const availabilityLabel = availabilityDropdown?.querySelector(
        '[data-convocatoria-availability-label]'
    );
    const availabilityOptions = Array.from(
        availabilityDropdown?.querySelectorAll(
            '[data-convocatoria-availability-option]'
        ) || []
    );

    const closeAvailabilityDropdown = function () {
        availabilityDropdown?.classList.remove('is-open');
        availabilityTrigger?.setAttribute('aria-expanded', 'false');
    };

    availabilityTrigger?.addEventListener('click', function () {
        const opening = !availabilityDropdown?.classList.contains('is-open');

        if (opening) {
            availabilityDropdown?.classList.add('is-open');
            availabilityTrigger.setAttribute('aria-expanded', 'true');
        } else {
            closeAvailabilityDropdown();
        }
    });

    availabilityOptions.forEach(function (option) {
        option.addEventListener('click', function () {
            const value = String(
                option.dataset.convocatoriaAvailabilityOption || '1'
            );

            if (availabilityValue) {
                availabilityValue.value = value;
            }

            if (availabilityLabel) {
                availabilityLabel.textContent = option.textContent.trim();
            }

            availabilityOptions.forEach(function (item) {
                const selected = item === option;
                item.classList.toggle('is-selected', selected);
                item.setAttribute(
                    'aria-selected',
                    selected ? 'true' : 'false'
                );
            });

            closeAvailabilityDropdown();
        });
    });

    document.addEventListener('click', function (event) {
        if (
            availabilityDropdown &&
            !availabilityDropdown.contains(event.target)
        ) {
            closeAvailabilityDropdown();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAvailabilityDropdown();
        }
    });

    const share = document.querySelector('[data-convocatoria-form-share]');

    if (share) {
        const select = share.querySelector('[data-convocatoria-form-type]');
        const formTypeDropdown = share.querySelector('[data-convocatoria-form-type-dropdown]');
        const formTypeTrigger = share.querySelector('[data-convocatoria-form-type-trigger]');
        const formTypeLabel = share.querySelector('[data-convocatoria-form-type-label]');
        const formTypeOptions = Array.from(
            share.querySelectorAll('[data-convocatoria-form-type-value]')
        );
        const buttonLink = share.querySelector('[data-convocatoria-generate-link]');
        const buttonQr = share.querySelector('[data-convocatoria-generate-qr]');
        const result = share.querySelector('[data-convocatoria-form-result]');
        const inputUrl = share.querySelector('[data-convocatoria-form-url]');
        const copyButton = share.querySelector('[data-convocatoria-copy-link]');
        const qrModalElement = document.querySelector('[data-convocatoria-qr-modal]');
        const qrPreview = qrModalElement?.querySelector('[data-convocatoria-qr-preview]');
        const qrLoader = qrModalElement?.querySelector('[data-convocatoria-qr-loader]');
        const qrLink = qrModalElement?.querySelector('[data-convocatoria-qr-link]');
        const qrDark = qrModalElement?.querySelector('[data-convocatoria-qr-dark]');
        const qrLight = qrModalElement?.querySelector('[data-convocatoria-qr-light]');
        const qrFinderColor = qrModalElement?.querySelector('[data-convocatoria-qr-finder-color]');
        const qrDotStyle = qrModalElement?.querySelector('[data-convocatoria-qr-dot-style]');
        const qrFinderStyle = qrModalElement?.querySelector('[data-convocatoria-qr-finder-style]');
        const qrFinderDotStyle = qrModalElement?.querySelector('[data-convocatoria-qr-finder-dot-style]');
        const qrSize = qrModalElement?.querySelector('[data-convocatoria-qr-size]');
        const qrMargin = qrModalElement?.querySelector('[data-convocatoria-qr-margin]');
        const qrEcLevel = qrModalElement?.querySelector('[data-convocatoria-qr-ec-level]');
        const qrTransparent = qrModalElement?.querySelector('[data-convocatoria-qr-transparent]');
        const qrImageEnabled = qrModalElement?.querySelector('[data-convocatoria-qr-image-enabled]');
        const qrImageConfig = qrModalElement?.querySelector('[data-convocatoria-qr-image-config]');
        const qrImageSources = Array.from(
            qrModalElement?.querySelectorAll('[data-convocatoria-qr-image-source]') || []
        );
        const qrUpload = qrModalElement?.querySelector('[data-convocatoria-qr-upload]');
        const qrUploadContainer = qrModalElement?.querySelector('[data-convocatoria-qr-upload-container]');
        const qrUploadPreview = qrModalElement?.querySelector('[data-convocatoria-qr-upload-preview]');
        const qrUploadPreviewImage = qrModalElement?.querySelector('[data-convocatoria-qr-upload-preview-image]');
        const qrUploadName = qrModalElement?.querySelector('[data-convocatoria-qr-upload-name]');
        const qrUrlContainer = qrModalElement?.querySelector('[data-convocatoria-qr-url-container]');
        const qrImageUrl = qrModalElement?.querySelector('[data-convocatoria-qr-image-url]');
        const qrImageSize = qrModalElement?.querySelector('[data-convocatoria-qr-image-size]');
        const qrImageSizeValue = qrModalElement?.querySelector('[data-convocatoria-qr-image-size-value]');
        const qrReset = qrModalElement?.querySelector('[data-convocatoria-reset-qr]');
        const qrCopy = qrModalElement?.querySelector('[data-convocatoria-copy-qr]');
        const qrDownload = qrModalElement?.querySelector('[data-convocatoria-download-qr]');
        const convocatoriaLinkInput = document.querySelector('[data-convocatoria-link-input]');

        let qrCurrentUrl = '';
        let qrObjectUrl = '';
        let qrUploadedImageData = '';
        let qrRenderToken = 0;
        let qrTimer = null;

        let convocatoriaPublicRef = '';

        const buildPublicReference = function () {
            if (convocatoriaPublicRef !== '') {
                return convocatoriaPublicRef;
            }

            if (
                window.crypto &&
                typeof window.crypto.randomUUID === 'function'
            ) {
                convocatoriaPublicRef = window.crypto.randomUUID();
                return convocatoriaPublicRef;
            }

            convocatoriaPublicRef =
                'conv-' +
                Date.now().toString(36) +
                '-' +
                Math.random().toString(36).slice(2, 10);

            return convocatoriaPublicRef;
        };

        const getSelectedUrl = function () {
            if (share.dataset.aliadoRegistroContexto === '1') return String(share.dataset.aliadoRegistroUrl || '');
            const type = String(select?.value || '');
            let baseUrl = '';

            if (type === 'registro') {
                baseUrl = String(share.dataset.registroUrl || '');
            } else if (type === 'inscripcion') {
                baseUrl = String(share.dataset.inscripcionUrl || '');
            }

            if (baseUrl === '') {
                return '';
            }

            const classification = String(
                document.querySelector(
                    '[data-convocatoria-context-classification]'
                )?.value || ''
            ).trim();

            const title = String(
                document.querySelector(
                    '[data-convocatoria-context-title]'
                )?.value || ''
            ).trim();

            const aliadoId = String(
                share.dataset.aliadoId || ''
            ).trim();

            const estadoId = String(
                share.dataset.estadoId || ''
            ).trim();

            const estadoNombre = String(
                share.dataset.estadoNombre || ''
            ).trim();

            const convenioNombre = String(
                share.dataset.convenioNombre || ''
            ).trim();

            const url = new URL(baseUrl, window.location.origin);

            url.searchParams.set('formulario', type);
            url.searchParams.set('origen', 'convenio_aliado');

            if (convenioNombre !== '') {
                url.searchParams.set('convenio', convenioNombre);
            }

            if (estadoId !== '' && estadoId !== '0') {
                url.searchParams.set('estado_id', estadoId);
            }

            if (estadoNombre !== '') {
                url.searchParams.set('estado', estadoNombre);
            }

            if (classification !== '') {
                url.searchParams.set('convocatoria', classification);
            }

            if (title !== '') {
                url.searchParams.set('titulo', title);
            }

            url.searchParams.set(
                'convocatoria_ref',
                buildPublicReference()
            );

            return url.toString();
        };

        const canGeneratePublicLink = function () {
            const type = String(select?.value || '').trim();
            const classification = String(
                document.querySelector(
                    '[data-convocatoria-context-classification]'
                )?.value || ''
            ).trim();
            const title = String(
                document.querySelector(
                    '[data-convocatoria-context-title]'
                )?.value || ''
            ).trim();
            const aliadoId = String(
                share.dataset.aliadoId || ''
            ).trim();
            const estadoId = String(
                share.dataset.estadoId || ''
            ).trim();

            return (
                (type === 'registro' || type === 'inscripcion') &&
                classification !== '' &&
                estadoId !== '' &&
                estadoId !== '0'
            );
        };

        const updatePublicLinkAvailability = function () {
            if (buttonLink) {
                buttonLink.disabled = false;
                buttonLink.setAttribute(
                    'aria-disabled',
                    canGeneratePublicLink() ? 'false' : 'true'
                );
            }
        };

        const resetShare = function () {
            result?.classList.add('d-none');
            if (inputUrl) {
                inputUrl.value = '';
            }

            if (buttonQr) {
                buttonQr.disabled = true;
            }
        };

        const closeFormTypeDropdown = function () {
            formTypeDropdown?.classList.remove('is-open');
            formTypeTrigger?.setAttribute('aria-expanded', 'false');
        };

        formTypeTrigger?.addEventListener('click', function () {
            const opening = !formTypeDropdown?.classList.contains('is-open');

            if (opening) {
                formTypeDropdown?.classList.add('is-open');
                formTypeTrigger?.setAttribute('aria-expanded', 'true');
            } else {
                closeFormTypeDropdown();
            }
        });

        formTypeOptions.forEach(function (option) {
            option.addEventListener('click', function () {
                const value = String(
                    option.dataset.convocatoriaFormTypeValue || ''
                );

                if (select) {
                    select.value = value;
                }

                if (formTypeLabel) {
                    formTypeLabel.textContent = option.textContent.trim();
                }

                formTypeOptions.forEach(function (item) {
                    const selected = item === option;
                    item.classList.toggle('is-selected', selected);
                    item.setAttribute(
                        'aria-selected',
                        selected ? 'true' : 'false'
                    );
                });

                closeFormTypeDropdown();

                resetShare();
                updatePublicLinkAvailability();
            });
        });

        document.addEventListener('click', function (event) {
            if (
                formTypeDropdown &&
                !formTypeDropdown.contains(event.target)
            ) {
                closeFormTypeDropdown();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeFormTypeDropdown();
            }
        });

        const contextFields = [
            document.querySelector(
                '[data-convocatoria-context-classification]'
            )
        ];

        contextFields.forEach(function (field) {
            const invalidateGeneratedLink = function () {
                if (share.dataset.aliadoRegistroContexto === '1') return;
                if (!result?.classList.contains('d-none')) {
                    resetShare();
                }

                updatePublicLinkAvailability();
            };

            field?.addEventListener('input', invalidateGeneratedLink);
            field?.addEventListener('change', invalidateGeneratedLink);
        });

        buttonLink?.addEventListener('click', function () {
            if (share.dataset.aliadoRegistroContexto === '1') return;
            if (!canGeneratePublicLink()) {
                showQrToast(
                    'Selecciona el formulario y el tipo de convocatoria antes de generar el enlace.',
                    true
                );
                return;
            }

            const url = getSelectedUrl();

            if (url === '') {
                return;
            }

            if (inputUrl) {
                inputUrl.value = url;
            }

            if (convocatoriaLinkInput) {
                convocatoriaLinkInput.value = url;
                convocatoriaLinkInput.dispatchEvent(
                    new Event('input', { bubbles: true })
                );
            }

            result?.classList.remove('d-none');

            if (buttonQr) {
                buttonQr.disabled = false;
            }
        });

        const showQrToast = function (message, error) {
            let container = document.querySelector('[data-convocatoria-copy-toast-container]');

            if (!container) {
                container = document.createElement('div');
                container.className = 'toast-container position-fixed top-0 end-0 p-3';
                container.setAttribute('data-convocatoria-copy-toast-container', '');
                container.style.zIndex = '1200';
                document.body.appendChild(container);
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast' + (error ? ' system-toast-error' : '');
            toast.setAttribute('role', error ? 'alert' : 'status');
            toast.innerHTML =
                '<div class="toast-body"><i class="bi ' +
                (error ? 'bi-exclamation-circle' : 'bi-check2-circle') +
                '"></i><span></span></div>';
            toast.querySelector('span').textContent = message;
            container.appendChild(toast);
            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });

            bootstrap.Toast.getOrCreateInstance(toast, {
                autohide: true,
                delay: error ? 4200 : 2600
            }).show();
        };

        const normalizeQrColor = function (value, fallback) {
            const clean = String(value || '').replace('#', '').trim();
            return /^[0-9a-fA-F]{6}$/.test(clean)
                ? clean.toLowerCase()
                : fallback;
        };

        const getQrImageSource = function () {
            return qrImageSources.find(function (radio) {
                return radio.checked;
            })?.value || 'upload';
        };

        const getQrCenterImage = function () {
            if (!qrImageEnabled?.checked) {
                return '';
            }

            if (getQrImageSource() === 'upload') {
                if (qrUploadedImageData === '') {
                    throw new Error('Selecciona una imagen desde tu equipo.');
                }
                return qrUploadedImageData;
            }

            const value = String(qrImageUrl?.value || '').trim();

            if (!/^https?:\/\//i.test(value)) {
                throw new Error('Ingresa una URL pública válida para la imagen.');
            }

            return value;
        };

        const getQrParams = function () {
            const url = String(inputUrl?.value || getSelectedUrl()).trim();

            if (url === '') {
                return null;
            }

            const dark = normalizeQrColor(qrDark?.value, '223a84');
            const light = qrTransparent?.checked
                ? '0000'
                : normalizeQrColor(qrLight?.value, 'ffffff');
            const finderColor = normalizeQrColor(qrFinderColor?.value, dark);

            if (!qrTransparent?.checked && dark === light) {
                throw new Error('El color del QR y el fondo deben ser diferentes.');
            }

            const centerImage = getQrCenterImage();

            const params = {
                text: url,
                size: String(qrSize?.value || '600'),
                margin: String(qrMargin?.value || '2'),
                dark: dark,
                light: light,
                finderColor: finderColor,
                dotStyle: String(qrDotStyle?.value || 'square'),
                finderStyle: String(qrFinderStyle?.value || 'square'),
                finderDotStyle: String(qrFinderDotStyle?.value || 'square'),
                ecLevel: centerImage !== '' ? 'H' : String(qrEcLevel?.value || 'M'),
                format: 'png'
            };

            if (centerImage !== '') {
                const imagePercent = Math.min(
                    30,
                    Math.max(10, Number(qrImageSize?.value || 20))
                );
                params.centerImageUrl = centerImage;
                params.centerImageSizeRatio = Number((imagePercent / 100).toFixed(2));
            }

            return params;
        };

        const revokeQrObjectUrl = function () {
            if (qrObjectUrl !== '') {
                URL.revokeObjectURL(qrObjectUrl);
                qrObjectUrl = '';
            }
        };

        const updateQrPreview = async function () {
            if (!qrPreview) {
                return;
            }

            const token = ++qrRenderToken;

            try {
                const params = getQrParams();

                if (!params) {
                    return;
                }

                const quickchartBase = String(
                    share.dataset.quickchartUrl || 'https://quickchart.io/qr'
                ).trim();

                qrLoader?.classList.remove('d-none');
                qrPreview.classList.add('is-loading');

                const usingLocalImage =
                    qrImageEnabled?.checked &&
                    getQrImageSource() === 'upload';

                if (usingLocalImage) {
                    const response = await fetch(quickchartBase, {
                        method: 'POST',
                        mode: 'cors',
                        cache: 'no-store',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(params)
                    });

                    if (!response.ok) {
                        throw new Error('No fue posible generar el QR con la imagen seleccionada.');
                    }

                    const blob = await response.blob();

                    if (token !== qrRenderToken) {
                        return;
                    }

                    revokeQrObjectUrl();
                    qrObjectUrl = URL.createObjectURL(blob);
                    qrCurrentUrl = qrObjectUrl;
                    qrPreview.src = qrCurrentUrl;
                } else {
                    revokeQrObjectUrl();

                    const query = new URLSearchParams();
                    Object.entries(params).forEach(function ([key, value]) {
                        query.set(key, String(value));
                    });

                    qrCurrentUrl = quickchartBase + '?' + query.toString();
                    qrPreview.src = qrCurrentUrl;
                }

                if (qrLink) {
                    qrLink.textContent = String(inputUrl?.value || '');
                }
            } catch (error) {
                qrLoader?.classList.add('d-none');
                qrPreview.classList.remove('is-loading');
                showQrToast(
                    error.message || 'No fue posible actualizar el código QR.',
                    true
                );
            }
        };

        const scheduleQrUpdate = function (delay) {
            window.clearTimeout(qrTimer);
            qrTimer = window.setTimeout(updateQrPreview, delay ?? 120);
        };

        const getQrBlob = async function () {
            if (qrCurrentUrl === '') {
                await updateQrPreview();
            }

            if (qrCurrentUrl === '') {
                throw new Error('No hay un código QR disponible.');
            }

            const response = await fetch(qrCurrentUrl, {
                mode: 'cors',
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error('No fue posible obtener la imagen del QR.');
            }

            return await response.blob();
        };

        const resetQr = function () {
            if (qrDark) qrDark.value = '#223a84';
            if (qrLight) {
                qrLight.value = '#ffffff';
                qrLight.disabled = false;
            }
            if (qrFinderColor) qrFinderColor.value = '#223a84';
            if (qrDotStyle) qrDotStyle.value = 'square';
            if (qrFinderStyle) qrFinderStyle.value = 'square';
            if (qrFinderDotStyle) qrFinderDotStyle.value = 'square';
            if (qrSize) qrSize.value = '600';
            if (qrMargin) qrMargin.value = '2';
            if (qrEcLevel) qrEcLevel.value = 'M';
            if (qrTransparent) qrTransparent.checked = false;
            if (qrImageEnabled) qrImageEnabled.checked = false;

            qrImageConfig?.classList.add('d-none');
            qrUploadContainer?.classList.remove('d-none');
            qrUrlContainer?.classList.add('d-none');

            qrImageSources.forEach(function (radio) {
                radio.checked = radio.value === 'upload';
            });

            qrUploadedImageData = '';

            if (qrUpload) qrUpload.value = '';
            if (qrUploadPreviewImage) qrUploadPreviewImage.src = '';
            if (qrUploadName) qrUploadName.textContent = '';
            qrUploadPreview?.classList.add('d-none');
            if (qrImageUrl) qrImageUrl.value = '';
            if (qrImageSize) qrImageSize.value = '20';
            if (qrImageSizeValue) qrImageSizeValue.textContent = '20%';

            updateQrPreview();
        };

        buttonQr?.addEventListener('click', function () {
            if (share.dataset.aliadoRegistroContexto === '1') return;
            const url = String(inputUrl?.value || getSelectedUrl()).trim();

            if (url === '' || !qrModalElement) {
                return;
            }

            updateQrPreview();
            bootstrap.Modal.getOrCreateInstance(qrModalElement).show();
        });

        [
            qrDark,
            qrLight,
            qrFinderColor,
            qrDotStyle,
            qrFinderStyle,
            qrFinderDotStyle,
            qrSize,
            qrMargin,
            qrEcLevel
        ].forEach(function (control) {
            control?.addEventListener('input', function () {
                scheduleQrUpdate(80);
            });
            control?.addEventListener('change', function () {
                scheduleQrUpdate(0);
            });
        });

        qrTransparent?.addEventListener('change', function () {
            if (qrLight) {
                qrLight.disabled = qrTransparent.checked;
            }
            scheduleQrUpdate(0);
        });

        qrImageEnabled?.addEventListener('change', function () {
            qrImageConfig?.classList.toggle('d-none', !qrImageEnabled.checked);

            if (!qrImageEnabled.checked) {
                scheduleQrUpdate(0);
                return;
            }

            const hasImage =
                (getQrImageSource() === 'upload' && qrUploadedImageData !== '') ||
                (getQrImageSource() === 'url' && String(qrImageUrl?.value || '').trim() !== '');

            if (hasImage) {
                scheduleQrUpdate(0);
            }
        });

        qrImageSources.forEach(function (radio) {
            radio.addEventListener('change', function () {
                const upload = getQrImageSource() === 'upload';
                qrUploadContainer?.classList.toggle('d-none', !upload);
                qrUrlContainer?.classList.toggle('d-none', upload);

                if (qrImageEnabled?.checked) {
                    scheduleQrUpdate(0);
                }
            });
        });

        qrUpload?.addEventListener('change', function () {
            const file = qrUpload.files?.[0];

            if (!file) {
                qrUploadedImageData = '';
                qrUploadPreview?.classList.add('d-none');
                return;
            }

            if (!/^image\/(png|jpeg|webp)$/i.test(file.type)) {
                qrUpload.value = '';
                showQrToast('Selecciona una imagen PNG, JPG o WEBP.', true);
                return;
            }

            const reader = new FileReader();

            reader.onload = function () {
                qrUploadedImageData = String(reader.result || '');
                if (qrUploadPreviewImage) qrUploadPreviewImage.src = qrUploadedImageData;
                if (qrUploadName) qrUploadName.textContent = file.name;
                qrUploadPreview?.classList.remove('d-none');

                if (qrImageEnabled?.checked) {
                    scheduleQrUpdate(0);
                }
            };

            reader.readAsDataURL(file);
        });

        qrImageUrl?.addEventListener('input', function () {
            if (qrImageEnabled?.checked && getQrImageSource() === 'url') {
                scheduleQrUpdate(300);
            }
        });

        qrImageSize?.addEventListener('input', function () {
            const value = Math.min(30, Math.max(10, Number(qrImageSize.value || 20)));

            if (qrImageSizeValue) {
                qrImageSizeValue.textContent = value + '%';
            }

            if (qrImageEnabled?.checked) {
                scheduleQrUpdate(80);
            }
        });

        qrReset?.addEventListener('click', resetQr);

        qrDownload?.addEventListener('click', async function () {
            try {
                const blob = await getQrBlob();
                const objectUrl = URL.createObjectURL(blob);
                const a = document.createElement('a');
                const type = String(select?.value || 'formulario');
                a.href = objectUrl;
                a.download = 'qr-' + type + '.png';
                document.body.appendChild(a);
                a.click();
                a.remove();
                window.setTimeout(function () {
                    URL.revokeObjectURL(objectUrl);
                }, 1000);
                showQrToast('QR descargado correctamente.', false);
            } catch (error) {
                showQrToast(error.message || 'No fue posible descargar el QR.', true);
            }
        });

        qrCopy?.addEventListener('click', async function () {
            try {
                const blob = await getQrBlob();

                if (!navigator.clipboard || typeof ClipboardItem === 'undefined') {
                    throw new Error('Tu navegador no permite copiar imágenes directamente.');
                }

                await navigator.clipboard.write([
                    new ClipboardItem({ 'image/png': blob })
                ]);

                showQrToast('QR copiado correctamente.', false);
            } catch (error) {
                showQrToast(
                    error.message || 'No fue posible copiar el QR.',
                    true
                );
            }
        });

        qrPreview?.addEventListener('load', function () {
            qrLoader?.classList.add('d-none');
            qrPreview.classList.remove('is-loading');
        });

        qrPreview?.addEventListener('error', function () {
            qrLoader?.classList.add('d-none');
            qrPreview.classList.remove('is-loading');
        });

        qrModalElement?.addEventListener('hidden.bs.modal', function () {
            window.clearTimeout(qrTimer);
        });

        const mostrarToastCopia = function () {
            let container = document.querySelector(
                '[data-convocatoria-copy-toast-container]'
            );

            if (!container) {
                container = document.createElement('div');
                container.className =
                    'toast-container position-fixed top-0 end-0 p-3';
                container.setAttribute(
                    'data-convocatoria-copy-toast-container',
                    ''
                );
                container.style.zIndex = '1100';
                document.body.appendChild(container);
            }

            const toast = document.createElement('div');
            toast.className = 'toast system-toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.setAttribute('aria-atomic', 'true');

            toast.innerHTML =
                '<div class="toast-body">' +
                    '<i class="bi bi-check2-circle"></i>' +
                    '<span>Enlace copiado correctamente.</span>' +
                '</div>';

            container.appendChild(toast);

            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });

            if (window.bootstrap) {
                bootstrap.Toast.getOrCreateInstance(toast, {
                    autohide: true,
                    delay: 2600
                }).show();
            }
        };

        copyButton?.addEventListener('click', async function () {
            const url = String(inputUrl?.value || '').trim();

            if (url === '') {
                return;
            }

            try {
                await navigator.clipboard.writeText(url);
                mostrarToastCopia();
            } catch (error) {
                inputUrl?.focus();
                inputUrl?.select();
                document.execCommand('copy');
                mostrarToastCopia();
            }
        });
    }

    const modalImagenElemento = document.getElementById('modalImagenConvocatoria');
    const modalImagen = modalImagenElemento ? new bootstrap.Modal(modalImagenElemento) : null;
    const imagenPreview = modalImagenElemento?.querySelector('[data-convocatoria-image-preview]');
    const imagenTitulo = document.getElementById('modalImagenConvocatoriaTitulo');

    document.addEventListener('click', async function (event) {
        const enlaceDescarga = event.target.closest('[data-download-convocatoria]');

        if (enlaceDescarga) {
            event.preventDefault();

            const urlDescarga = enlaceDescarga.getAttribute('href') || '';
            const toastDescarga = document.querySelector('[data-convocatoria-download-toast]');
            const toastTexto = toastDescarga?.querySelector('[data-convocatoria-download-toast-text]');
            const toastIcono = toastDescarga?.querySelector('[data-convocatoria-download-toast-icon]');

            if (urlDescarga === '') {
                return;
            }

            enlaceDescarga.setAttribute('aria-busy', 'true');

            try {
                const respuesta = await fetch(urlDescarga, {
                    method: 'GET',
                    credentials: 'same-origin'
                });

                if (!respuesta.ok) {
                    throw new Error('No fue posible descargar la imagen.');
                }

                const blob = await respuesta.blob();
                const contentDisposition = respuesta.headers.get('Content-Disposition') || '';
                let nombreArchivo = 'convocatoria';

                const coincidenciaUtf8 = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i);
                const coincidenciaSimple = contentDisposition.match(/filename="?([^";]+)"?/i);

                if (coincidenciaUtf8 && coincidenciaUtf8[1]) {
                    nombreArchivo = decodeURIComponent(coincidenciaUtf8[1]);
                } else if (coincidenciaSimple && coincidenciaSimple[1]) {
                    nombreArchivo = coincidenciaSimple[1].trim();
                }

                const urlTemporal = URL.createObjectURL(blob);
                const descargaTemporal = document.createElement('a');
                descargaTemporal.href = urlTemporal;
                descargaTemporal.download = nombreArchivo;
                descargaTemporal.style.display = 'none';
                document.body.appendChild(descargaTemporal);
                descargaTemporal.click();
                descargaTemporal.remove();

                window.setTimeout(function () {
                    URL.revokeObjectURL(urlTemporal);
                }, 1000);

                if (toastDescarga && window.bootstrap) {
                    toastDescarga.classList.remove('system-toast-error');

                    if (toastTexto) {
                        toastTexto.textContent = 'La imagen se descargó correctamente.';
                    }

                    if (toastIcono) {
                        toastIcono.className = 'bi bi-check2-circle';
                    }

                    bootstrap.Toast.getOrCreateInstance(toastDescarga).show();
                }
            } catch (error) {
                if (toastDescarga && window.bootstrap) {
                    toastDescarga.classList.add('system-toast-error');

                    if (toastTexto) {
                        toastTexto.textContent = 'No fue posible descargar la imagen.';
                    }

                    if (toastIcono) {
                        toastIcono.className = 'bi bi-exclamation-circle';
                    }

                    bootstrap.Toast.getOrCreateInstance(toastDescarga).show();
                }
            } finally {
                enlaceDescarga.removeAttribute('aria-busy');
            }

            return;
        }

        const botonCopiar = event.target.closest('[data-copy-convocatoria-link]');

        if (botonCopiar) {
            const enlace = botonCopiar.dataset.copyConvocatoriaLink || '';

            if (enlace === '') {
                return;
            }

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(enlace);
                } else {
                    const temporal = document.createElement('textarea');
                    temporal.value = enlace;
                    temporal.setAttribute('readonly', '');
                    temporal.style.position = 'fixed';
                    temporal.style.opacity = '0';
                    document.body.appendChild(temporal);
                    temporal.select();
                    document.execCommand('copy');
                    temporal.remove();
                }

                const textoBoton = botonCopiar.querySelector('span');
                const iconoBoton = botonCopiar.querySelector('i');

                if (textoBoton) {
                    textoBoton.textContent = 'Copiado';
                }

                if (iconoBoton) {
                    iconoBoton.className = 'bi bi-check2';
                }

                window.setTimeout(function () {
                    if (textoBoton) {
                        textoBoton.textContent = 'Copiar enlace';
                    }

                    if (iconoBoton) {
                        iconoBoton.className = 'bi bi-copy';
                    }
                }, 1800);
            } catch (error) {
                window.alert('No fue posible copiar el enlace.');
            }

            return;
        }

        const botonImagen = event.target.closest('[data-convocatoria-image]');

        if (!botonImagen || !modalImagen || !imagenPreview) {
            return;
        }

        const titulo = botonImagen.dataset.convocatoriaImageTitle || 'Convocatoria';
        imagenPreview.src = botonImagen.dataset.convocatoriaImage || '';
        imagenPreview.alt = titulo;

        if (imagenTitulo) {
            imagenTitulo.textContent = titulo;
        }

        modalImagen.show();
    });

    modalImagenElemento?.addEventListener('hidden.bs.modal', function () {
        if (imagenPreview) {
            imagenPreview.src = '';
            imagenPreview.alt = '';
        }
    });

    const detallePanel = document.getElementById('offcanvasDetalleConvocatoria');
    const detalleContenido = document.getElementById('convocatoriaDetalleContenido');
    const detalleOffcanvas = detallePanel ? new bootstrap.Offcanvas(detallePanel) : null;

    const cargarConvocatoria = async function (id) {
        const respuesta = await fetch(
            <?= json_encode(BASE_URL . 'index.php?controller=convocatoria&action=detalle&id=') ?> + encodeURIComponent(id)
        );

        if (!respuesta.ok) {
            throw new Error('No fue posible consultar la convocatoria.');
        }

        const datos = await respuesta.json();

        if (!datos.ok || !datos.convocatoria) {
            throw new Error('No fue posible consultar la convocatoria.');
        }

        return datos.convocatoria;
    };

    const manejarVerConvocatoria = async function () {
            try {
                const convocatoria = await cargarConvocatoria(this.dataset.id);
                const imagen = convocatoria.imagen
                    ? '<img class="convocatoria-detail-image" src="' +
                        <?= json_encode(BASE_URL) ?> +
                        convocatoria.imagen.replace(/^\/+/, '') +
                        '" alt="">'
                    : '';

                detalleContenido.innerHTML =
                    imagen +
                    '<div class="user-detail-tab-content active">' +
                    '<div class="user-detail-info-row">' +
                    '<div class="user-detail-info-icon"><i class="bi bi-card-heading"></i></div>' +
                    '<div class="user-detail-info-label">Título</div>' +
                    '<div class="user-detail-info-value">' +
                    escapeHtml(convocatoria.titulo || '—') +
                    '</div></div>' +
                    '<div class="user-detail-info-row">' +
                    '<div class="user-detail-info-icon"><i class="bi bi-calendar3"></i></div>' +
                    '<div class="user-detail-info-label">Periodo</div>' +
                    '<div class="user-detail-info-value">' +
                    escapeHtml(formatearPeriodo(convocatoria.fecha_inicio, convocatoria.fecha_termino)) +
                    '</div></div>' +
                    '<div class="user-detail-info-row">' +
                    '<div class="user-detail-info-icon"><i class="bi bi-link-45deg"></i></div>' +
                    '<div class="user-detail-info-label">Enlace de registro</div>' +
                    '<div class="user-detail-info-value">' +
                    (convocatoria.enlace_registro
                        ? '<a href="' + escapeHtml(convocatoria.enlace_registro) + '" target="_blank" rel="noopener noreferrer">' +
                            escapeHtml(convocatoria.enlace_registro) +
                          '</a>'
                        : '—') +
                    '</div></div>' +
                    '<div class="user-detail-info-row">' +
                    '<div class="user-detail-info-icon"><i class="bi bi-geo-alt"></i></div>' +
                    '<div class="user-detail-info-label">Estados</div>' +
                    '<div class="user-detail-info-value">' +
                    escapeHtml((convocatoria.estados || []).join(', ') || '—') +
                    '</div></div>' +
                    '<div class="user-detail-info-row">' +
                    '<div class="user-detail-info-icon"><i class="bi bi-toggle-on"></i></div>' +
                    '<div class="user-detail-info-label">Estatus</div>' +
                    '<div class="user-detail-info-value">' +
                    ((Number(convocatoria.estado) === 1) ? 'Activa' : 'Inactiva') +
                    '</div></div></div>';

                detalleOffcanvas.show();
            } catch (error) {
                detalleContenido.innerHTML = '<div class="alert alert-danger">' + escapeHtml(error.message) + '</div>';
                detalleOffcanvas.show();
            }
    };

    const manejarEditarConvocatoria = async function () {
            try {
                const convocatoria = await cargarConvocatoria(this.dataset.id);
                document.getElementById('editar_convocatoria_id').value = convocatoria.id || '';
                document.getElementById('editar_convocatoria_titulo').value = convocatoria.titulo || '';
                document.getElementById('editar_convocatoria_enlace_registro').value = convocatoria.enlace_registro || '';
                document.getElementById('editar_convocatoria_fecha_inicio').value = convocatoria.fecha_inicio || '';
                document.getElementById('editar_convocatoria_fecha_termino').value = convocatoria.fecha_termino || '';
                document.getElementById('editar_convocatoria_estado').value = String(convocatoria.estado ?? 1);

                const seleccion = new Set((convocatoria.estados_ids || []).map(String));
                const modalEditar = document.getElementById('modalEditarConvocatoria');
                const territorioActualId = <?= json_encode((string)$territorioActualId) ?>;

                modalEditar.querySelectorAll('[data-state-option] input[type="checkbox"]').forEach(function (checkbox) {
                    checkbox.checked = seleccion.has(String(checkbox.value));
                });

                const pickerEditar = modalEditar.querySelector('[data-state-picker]');
                actualizarSeleccionTodos(pickerEditar);

                const panelEstadosAdicionales = document.getElementById('editarEstadosAdicionales');
                const tieneEstadosAdicionales = Array.from(seleccion).some(function (estadoId) {
                    return String(estadoId) !== String(territorioActualId);
                });

                if (panelEstadosAdicionales) {
                    const collapseEstados = bootstrap.Collapse.getOrCreateInstance(
                        panelEstadosAdicionales,
                        { toggle: false }
                    );

                    if (tieneEstadosAdicionales) {
                        collapseEstados.show();
                    } else {
                        collapseEstados.hide();
                    }
                }

                new bootstrap.Modal(document.getElementById('modalEditarConvocatoria')).show();
            } catch (error) {
                window.alert(error.message);
            }
    };

    vincularAccionesConvocatorias();

    document.querySelectorAll('[data-state-picker]').forEach(function (picker) {
        const search = picker.querySelector('[data-state-search]');
        const selectAll = picker.querySelector('[data-state-select-all]');
        const options = Array.from(picker.querySelectorAll('[data-state-option]'));

        const filterStates = function () {
            const term = normalizarTexto(search.value);

            options.forEach(function (option) {
                const name = normalizarTexto(option.dataset.stateName || '');
                option.hidden = term !== '' && !name.includes(term);
            });

            actualizarSeleccionTodos(picker);
        };

        search.addEventListener('input', filterStates);

        selectAll.addEventListener('change', function () {
            options.forEach(function (option) {
                if (!option.hidden) {
                    option.querySelector('input[type="checkbox"]').checked = selectAll.checked;
                }
            });

            actualizarSeleccionTodos(picker);
        });

        options.forEach(function (option) {
            option.querySelector('input[type="checkbox"]').addEventListener('change', function () {
                actualizarSeleccionTodos(picker);
            });
        });

        actualizarSeleccionTodos(picker);
    });

    function actualizarSeleccionTodos(picker) {
        if (!picker) {
            return;
        }

        const visibles = Array.from(picker.querySelectorAll('[data-state-option]'))
            .filter(function (option) {
                return !option.hidden;
            })
            .map(function (option) {
                return option.querySelector('input[type="checkbox"]');
            });

        const selectAll = picker.querySelector('[data-state-select-all]');
        const marcados = visibles.filter(function (checkbox) {
            return checkbox.checked;
        }).length;

        selectAll.checked = visibles.length > 0 && marcados === visibles.length;
        selectAll.indeterminate = marcados > 0 && marcados < visibles.length;
    }

    function normalizarTexto(valor) {
        return String(valor || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    const filtroBuscar = document.getElementById('filtro_convocatoria_buscar');
    const filtroFecha = document.getElementById('filtro_convocatoria_fecha');
    const filtroCategoria = document.getElementById('filtro_convocatoria_categoria');
    const filtroClasificacion = document.querySelector('[data-convocatoria-clasificacion]');
    const filtroClasificacionDropdown = document.querySelector(
        '[data-convocatoria-filter-dropdown]'
    );
    const filtroClasificacionTrigger = document.querySelector(
        '[data-convocatoria-filter-trigger]'
    );
    const filtroClasificacionLabel = document.querySelector(
        '[data-convocatoria-filter-label]'
    );
    const filtroClasificacionOpciones = Array.from(
        document.querySelectorAll('[data-convocatoria-filter-value]')
    );
    const limpiarFiltros = document.querySelector('[data-convocatoria-clear-filters]');
    const listadoConvocatorias = document.querySelector('[data-convocatorias-listado]');
    const tabsConvocatorias = Array.from(
        document.querySelectorAll('[data-convocatoria-view]')
    );
    const headingConvocatorias = document.querySelector('[data-convocatoria-heading]');
    const headingCopyConvocatorias = document.querySelector('[data-convocatoria-heading-copy]');
    const headingIconConvocatorias = document.querySelector('[data-convocatoria-heading-icon]');
    const crearConvocatoriaAction = document.querySelector('[data-convocatoria-create-action]');
    const contadorActivas = document.querySelector('[data-convocatoria-count="activas"]');
    const contadorHistorial = document.querySelector('[data-convocatoria-count="historial"]');

    let vistaConvocatorias = <?= json_encode($vistaConvocatoriasInicial) ?>;
    let convocatoriasActuales = <?= json_encode(
        $convocatorias,
        JSON_UNESCAPED_UNICODE |
        JSON_INVALID_UTF8_SUBSTITUTE |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;
    let temporizadorFiltro = null;
    let controladorFiltro = null;
    let secuenciaFiltro = 0;

    const actualizarVisibilidadLimpiar = function () {
        const hayFiltros =
            String(filtroBuscar?.value || '').trim() !== '' ||
            String(filtroFecha?.value || '') !== '' ||
            String(filtroCategoria?.value || '') !== '' ||
            String(filtroClasificacion?.value || '') !== '';

        limpiarFiltros?.classList.toggle('d-none', !hayFiltros);
    };

    const actualizarEncabezadoVista = function () {
        const esActivas = vistaConvocatorias === 'activas';

        tabsConvocatorias.forEach(function (tab) {
            const activa = tab.dataset.convocatoriaView === vistaConvocatorias;
            tab.classList.toggle('is-active', activa);
            tab.setAttribute('aria-selected', activa ? 'true' : 'false');
        });

        if (headingConvocatorias) {
            headingConvocatorias.textContent = esActivas
                ? 'Convocatorias activas'
                : 'Historial / Desactivadas';
        }

        if (headingCopyConvocatorias) {
            headingCopyConvocatorias.textContent = esActivas
                ? 'Convocatorias vigentes o nuevas disponibles para consulta y gestión.'
                : 'Consulta convocatorias desactivadas o que ya no se encuentran disponibles.';
        }

        if (headingIconConvocatorias) {
            headingIconConvocatorias.classList.toggle('is-active', esActivas);
            headingIconConvocatorias.classList.toggle('is-history', !esActivas);
            headingIconConvocatorias.innerHTML = esActivas
                ? '<i class="bi bi-megaphone"></i>'
                : '<i class="bi bi-archive"></i>';
        }

        crearConvocatoriaAction?.classList.toggle('d-none', !esActivas);
    };

    const actualizarContadoresVista = function () {
        const activas = convocatoriasActuales.filter(function (convocatoria) {
            return Number(convocatoria.estado) === 1;
        }).length;
        const historial = convocatoriasActuales.length - activas;

        if (contadorActivas) {
            contadorActivas.textContent = String(activas);
        }

        if (contadorHistorial) {
            contadorHistorial.textContent = String(historial);
        }
    };

    const obtenerMesConvocatoria = function (convocatoria) {
        const fechaInicio = String(convocatoria.fecha_inicio || '').trim();

        if (fechaInicio === '') {
            return {
                key: 'sin-fecha',
                orden: 0,
                titulo: 'Sin fecha definida',
                icono: 'bi-calendar-x'
            };
        }

        const partes = fechaInicio.split('-');
        const anio = Number(partes[0] || 0);
        const mes = Number(partes[1] || 0);

        const nombresMeses = [
            '',
            'Enero',
            'Febrero',
            'Marzo',
            'Abril',
            'Mayo',
            'Junio',
            'Julio',
            'Agosto',
            'Septiembre',
            'Octubre',
            'Noviembre',
            'Diciembre'
        ];

        if (anio <= 0 || mes < 1 || mes > 12) {
            return {
                key: 'sin-fecha',
                orden: 0,
                titulo: 'Sin fecha definida',
                icono: 'bi-calendar-x'
            };
        }

        return {
            key: String(anio) + '-' + String(mes).padStart(2, '0'),
            orden: (anio * 100) + mes,
            titulo: nombresMeses[mes] + ' ' + anio,
            icono: 'bi-calendar3'
        };
    };

    const construirFilaConvocatoria = function (convocatoria) {
        const estadoActivo = Number(convocatoria.estado) === 1;
        const imagenUrl = convocatoria.imagen
            ? <?= json_encode(BASE_URL) ?> +
                String(convocatoria.imagen).replace(/^\/+/, '')
            : '';

        const imagen = imagenUrl
            ? '<button type="button" class="convocatoria-thumb-button" ' +
                'data-convocatoria-image="' + escapeHtml(imagenUrl) + '" ' +
                'data-convocatoria-image-title="' + escapeHtml(convocatoria.titulo || '') + '" ' +
                'aria-label="Ver imagen de ' + escapeHtml(convocatoria.titulo || '') + '">' +
                '<img src="' + escapeHtml(imagenUrl) +
                '" alt="' + escapeHtml(convocatoria.titulo || '') +
                '" class="convocatoria-thumb"></button>'
            : '—';

        const acciones = [
            '<button type="button" class="table-action-button btn-ver-convocatoria" ' +
                'data-id="' + Number(convocatoria.id) + '" aria-label="Ver convocatoria">' +
                '<i class="bi bi-eye"></i></button>'
        ];

        <?php if ($puedeEditar): ?>
        acciones.push(
            '<button type="button" class="table-action-button btn-editar-convocatoria" ' +
            'data-id="' + Number(convocatoria.id) + '" aria-label="Editar convocatoria">' +
            '<i class="bi bi-pencil"></i></button>'
        );
        <?php endif; ?>

        <?php if ($puedeDescargar): ?>
        acciones.push(
            '<a class="table-action-button" href="' +
            <?= json_encode(BASE_URL . 'index.php?controller=convocatoria&action=descargarImagen&id=') ?> +
            Number(convocatoria.id) +
            '" data-download-convocatoria aria-label="Descargar imagen">' +
            '<i class="bi bi-download"></i></a>'
        );
        <?php endif; ?>

        <?php if ($puedeCambiarEstado): ?>
        acciones.push(
            '<button type="button" class="table-action-button ' +
            (estadoActivo ? 'table-action-warning' : 'table-action-success') +
            '" data-bs-toggle="modal" data-bs-target="#modalEstadoConvocatoria" ' +
            'data-id="' + Number(convocatoria.id) + '" ' +
            'data-titulo="' + escapeHtml(convocatoria.titulo || '') + '" ' +
            'data-estado-nuevo="' + (estadoActivo ? '0' : '1') + '" ' +
            'aria-label="' + (estadoActivo ? 'Desactivar convocatoria' : 'Activar convocatoria') + '">' +
            '<i class="bi ' + (estadoActivo ? 'bi-toggle-on' : 'bi-toggle-off') + '"></i></button>'
        );
        <?php endif; ?>

        return '<tr>' +
            '<td>' + imagen + '</td>' +
            '<td><strong class="convocatoria-month-row-title">' +
                escapeHtml(convocatoria.titulo || '') +
            '</strong></td>' +
            '<td>' + escapeHtml(formatearPeriodo(
                convocatoria.fecha_inicio,
                convocatoria.fecha_termino
            )) + '</td>' +
            '<td>' + escapeHtml(convocatoria.estados || 'Sin estados') + '</td>' +
            '<td>' +
                (convocatoria.enlace_registro
                    ? '<button type="button" class="convocatoria-external-link" ' +
                        'data-copy-convocatoria-link="' +
                        escapeHtml(convocatoria.enlace_registro) + '">' +
                        '<i class="bi bi-copy"></i><span>Copiar enlace</span></button>'
                    : '<span class="convocatoria-link-empty">—</span>') +
            '</td>' +
            '<td><span class="status-pill ' +
                (estadoActivo ? 'status-pill-active' : 'status-pill-inactive') + '">' +
                (estadoActivo ? 'Activa' : 'Inactiva') +
            '</span></td>' +
            '<td class="text-end"><div class="table-actions">' +
                acciones.join('') +
            '</div></td>' +
        '</tr>';
    };

    const renderConvocatorias = function (convocatorias, actualizarBase = true) {
        if (!listadoConvocatorias) {
            return;
        }

        if (actualizarBase) {
            convocatoriasActuales = Array.isArray(convocatorias)
                ? convocatorias
                : [];
        }

        actualizarContadoresVista();
        actualizarEncabezadoVista();

        const convocatoriasVista = convocatoriasActuales.filter(function (convocatoria) {
            const activa = Number(convocatoria.estado) === 1;

            return vistaConvocatorias === 'activas'
                ? activa
                : !activa;
        });

        if (convocatoriasVista.length === 0) {
            const mensajeVacio = vistaConvocatorias === 'activas'
                ? 'No hay convocatorias activas con los filtros seleccionados.'
                : 'No hay convocatorias en el historial con los filtros seleccionados.';

            listadoConvocatorias.innerHTML =
                '<div class="convocatoria-month-empty-state">' +
                    '<i class="bi bi-calendar2-x"></i>' +
                    '<strong>' + mensajeVacio + '</strong>' +
                '</div>';
            return;
        }

        const grupos = new Map();

        convocatoriasVista.forEach(function (convocatoria) {
            const mes = obtenerMesConvocatoria(convocatoria);

            if (!grupos.has(mes.key)) {
                grupos.set(mes.key, {
                    key: mes.key,
                    orden: mes.orden,
                    titulo: mes.titulo,
                    icono: mes.icono,
                    convocatorias: []
                });
            }

            grupos.get(mes.key).convocatorias.push(convocatoria);
        });

        const gruposOrdenados = Array.from(grupos.values()).sort(function (a, b) {
            if (a.key === 'sin-fecha') {
                return 1;
            }

            if (b.key === 'sin-fecha') {
                return -1;
            }

            return b.orden - a.orden;
        });

        listadoConvocatorias.innerHTML = gruposOrdenados.map(function (grupo) {
            const cantidad = grupo.convocatorias.length;
            const etiquetaCantidad = cantidad === 1
                ? '1 convocatoria'
                : cantidad + ' convocatorias';

            return '<section class="convocatoria-month-group">' +
                '<div class="convocatoria-month-group-header">' +
                    '<div class="convocatoria-month-group-title">' +
                        '<span class="convocatoria-month-group-icon">' +
                            '<i class="bi ' + escapeHtml(grupo.icono) + '"></i>' +
                        '</span>' +
                        '<strong>' + escapeHtml(grupo.titulo) + '</strong>' +
                    '</div>' +
                    '<span class="convocatoria-month-group-count">' +
                        escapeHtml(etiquetaCantidad) +
                    '</span>' +
                '</div>' +
                '<div class="table-responsive">' +
                    '<table class="table users-table align-middle mb-0 convocatoria-month-table">' +
                        '<thead><tr>' +
                            '<th>Imagen</th>' +
                            '<th>Título</th>' +
                            '<th>Periodo</th>' +
                            '<th>Estados</th>' +
                            '<th>Enlace</th>' +
                            '<th>Estatus</th>' +
                            '<th class="text-end">Acciones</th>' +
                        '</tr></thead>' +
                        '<tbody>' +
                            grupo.convocatorias.map(construirFilaConvocatoria).join('') +
                        '</tbody>' +
                    '</table>' +
                '</div>' +
            '</section>';
        }).join('');

        vincularAccionesConvocatorias();
    };

    const formatearFecha = function (fecha) {
        if (!fecha) {
            return '—';
        }

        const partes = String(fecha).split('-');
        return partes.length === 3
            ? partes[2] + '/' + partes[1] + '/' + partes[0]
            : String(fecha);
    };

    const formatearPeriodo = function (fechaInicio, fechaTermino) {
        const inicio = String(fechaInicio || '').trim();
        const termino = String(fechaTermino || '').trim();

        if (inicio === '' && termino === '') {
            return 'Sin fecha';
        }

        if (inicio !== '' && termino === '') {
            return formatearFecha(inicio) + ' — Sin vencimiento';
        }

        return formatearFecha(inicio) + ' — ' + formatearFecha(termino);
    };

    const cargarListadoFiltrado = async function () {
        if (!listadoConvocatorias) {
            return;
        }

        if (controladorFiltro) {
            controladorFiltro.abort();
        }

        const secuenciaActual = ++secuenciaFiltro;
        controladorFiltro = new AbortController();
        listadoConvocatorias.classList.add('opacity-50');

        const params = new URLSearchParams({
            controller: 'convocatoria',
            action: 'listadoFiltrado',
            buscar: String(filtroBuscar?.value || '').trim(),
            territorio_id: <?= json_encode((string)(int)$territorioSeleccionado['id']) ?>,
            tipo: <?= json_encode($tipoConvocatoria) ?>,
            subtipo: '',
            clasificacion: String(filtroClasificacion?.value || ''),
            fecha: String(filtroFecha?.value || ''),
            categoria: String(filtroCategoria?.value || ''),
            anio: <?= json_encode((string)(int)$anioSeleccionado) ?>,
            mes: <?= json_encode((string)(int)$mesSeleccionado) ?>
        });

        try {
            const respuesta = await fetch(
                <?= json_encode(BASE_URL . 'index.php?') ?> + params.toString(),
                {
                    headers: {
                        'X-Requested-With': 'fetch'
                    },
                    cache: 'no-store',
                    signal: controladorFiltro.signal
                }
            );

            if (!respuesta.ok) {
                throw new Error('No fue posible actualizar las convocatorias.');
            }

            const datos = await respuesta.json();

            if (secuenciaActual !== secuenciaFiltro) {
                return;
            }

            renderConvocatorias(datos.convocatorias || []);
        } catch (error) {
            if (error.name !== 'AbortError') {
                listadoConvocatorias.innerHTML =
                    '<div class="alert alert-danger mb-0">' +
                    escapeHtml(error.message) +
                    '</div>';
            }
        } finally {
            if (secuenciaActual === secuenciaFiltro) {
                listadoConvocatorias.classList.remove('opacity-50');
            }
        }
    };

    const programarBusqueda = function () {
        window.clearTimeout(temporizadorFiltro);
        actualizarVisibilidadLimpiar();

        temporizadorFiltro = window.setTimeout(function () {
            cargarListadoFiltrado();
        }, 300);
    };

    const persistirVistaConvocatorias = function () {
        document.querySelectorAll('[data-convocatoria-vista]').forEach(function (campo) {
            campo.value = vistaConvocatorias;
        });

        const urlActual = new URL(window.location.href);
        urlActual.searchParams.set('vista', vistaConvocatorias);
        window.history.replaceState({}, '', urlActual.toString());
    };

    tabsConvocatorias.forEach(function (tab) {
        tab.addEventListener('click', function () {
            vistaConvocatorias = tab.dataset.convocatoriaView === 'historial'
                ? 'historial'
                : 'activas';

            persistirVistaConvocatorias();
            renderConvocatorias(convocatoriasActuales, false);
        });
    });

    renderConvocatorias(convocatoriasActuales, false);

    filtroBuscar?.addEventListener('input', programarBusqueda);

    const cerrarFiltroClasificacion = function () {
        filtroClasificacionDropdown?.classList.remove('is-open');
        filtroClasificacionTrigger?.setAttribute('aria-expanded', 'false');
    };

    filtroClasificacionTrigger?.addEventListener('click', function () {
        const abrir = !filtroClasificacionDropdown?.classList.contains('is-open');

        if (abrir) {
            filtroClasificacionDropdown?.classList.add('is-open');
            filtroClasificacionTrigger.setAttribute('aria-expanded', 'true');
        } else {
            cerrarFiltroClasificacion();
        }
    });

    filtroClasificacionOpciones.forEach(function (opcion) {
        opcion.addEventListener('click', function () {
            const valor = String(
                opcion.dataset.convocatoriaFilterValue || ''
            );

            if (filtroClasificacion) {
                filtroClasificacion.value = valor;
            }

            if (filtroClasificacionLabel) {
                filtroClasificacionLabel.textContent =
                    opcion.textContent.trim();
            }

            filtroClasificacionOpciones.forEach(function (item) {
                const seleccionada = item === opcion;
                item.classList.toggle('is-selected', seleccionada);
                item.setAttribute(
                    'aria-selected',
                    seleccionada ? 'true' : 'false'
                );
            });

            cerrarFiltroClasificacion();
            window.clearTimeout(temporizadorFiltro);
            actualizarVisibilidadLimpiar();
            cargarListadoFiltrado();
        });
    });

    document.addEventListener('click', function (event) {
        if (
            filtroClasificacionDropdown &&
            !filtroClasificacionDropdown.contains(event.target)
        ) {
            cerrarFiltroClasificacion();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            cerrarFiltroClasificacion();
        }
    });

    filtroClasificacion?.addEventListener('change', function () {
        window.clearTimeout(temporizadorFiltro);
        actualizarVisibilidadLimpiar();
        cargarListadoFiltrado();
    });

    filtroFecha?.addEventListener('change', function () {
        window.clearTimeout(temporizadorFiltro);
        actualizarVisibilidadLimpiar();
        cargarListadoFiltrado();
    });

    filtroCategoria?.addEventListener('change', function () {
        window.clearTimeout(temporizadorFiltro);
        actualizarVisibilidadLimpiar();
        cargarListadoFiltrado();
    });

    limpiarFiltros?.addEventListener('click', function (event) {
        event.preventDefault();
        window.clearTimeout(temporizadorFiltro);

        if (filtroBuscar) {
            filtroBuscar.value = '';
        }

        if (filtroFecha) {
            filtroFecha.value = '';
        }

        if (filtroCategoria) {
            filtroCategoria.value = '';
        }

        if (filtroClasificacion) {
            filtroClasificacion.value = '';
        }

        if (filtroClasificacionLabel) {
            filtroClasificacionLabel.textContent =
                'Todas las convocatorias';
        }

        filtroClasificacionOpciones.forEach(function (item) {
            const seleccionada =
                String(item.dataset.convocatoriaFilterValue || '') === '';
            item.classList.toggle('is-selected', seleccionada);
            item.setAttribute(
                'aria-selected',
                seleccionada ? 'true' : 'false'
            );
        });

        actualizarVisibilidadLimpiar();
        cargarListadoFiltrado();
    });

    function vincularAccionesConvocatorias() {
        document.querySelectorAll('.btn-ver-convocatoria').forEach(function (boton) {
            if (boton.dataset.listenerVinculado === '1') {
                return;
            }

            boton.dataset.listenerVinculado = '1';
            boton.addEventListener('click', manejarVerConvocatoria);
        });

        document.querySelectorAll('.btn-editar-convocatoria').forEach(function (boton) {
            if (boton.dataset.listenerVinculado === '1') {
                return;
            }

            boton.dataset.listenerVinculado = '1';
            boton.addEventListener('click', manejarEditarConvocatoria);
        });
    }

    const modalEstado = document.getElementById('modalEstadoConvocatoria');
    if (modalEstado) {
        modalEstado.addEventListener('show.bs.modal', function (event) {
            const boton = event.relatedTarget;
            const nuevoEstado = Number(boton.dataset.estadoNuevo);
            document.getElementById('estado_convocatoria_id').value = boton.dataset.id || '';
            document.getElementById('estado_convocatoria_nuevo').value = String(nuevoEstado);
            document.getElementById('estado_convocatoria_mensaje').textContent =
                (nuevoEstado === 1 ? '¿Deseas activar "' : '¿Deseas desactivar "') +
                (boton.dataset.titulo || 'esta convocatoria') +
                '"?';
        });
    }

    function escapeHtml(valor) {
        return String(valor)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    <?php if ($modalAbierto === 'crear'): ?>
        const modalCrearAutomatico = document.getElementById('modalCrearConvocatoria');

        if (modalCrearAutomatico && window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(modalCrearAutomatico).show();
        }
    <?php endif; ?>
});
</script>

<?php if ($aliadoContextoConvocatoria): ?>
<script src="<?= BASE_URL ?>public/vendor/qrcode-generator/qrcode.js" defer></script>
<?php endif; ?>
