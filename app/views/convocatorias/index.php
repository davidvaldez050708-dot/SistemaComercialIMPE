<?php

$convocatorias = $convocatorias ?? [];
$estados = $estados ?? [];
$mensajeExito = $mensajeExito ?? '';
$mensajeError = $mensajeError ?? '';
$erroresFormulario = $erroresFormulario ?? [];
$datosFormulario = $datosFormulario ?? [];
$modalAbierto = $modalAbierto ?? '';
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

$datosCrear = $modalAbierto === 'crear' ? $datosFormulario : [];
$datosEditar = $modalAbierto === 'editar' ? $datosFormulario : [];
?>

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

<?php if ($tipoConvocatoria === ''): ?>
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
                                        '&tipo=' . rawurlencode($tipoReciente) .
                                        '&subtipo=' . rawurlencode($subtipoReciente) .
                                        '&anio=' . $anioReciente .
                                        '&mes=' . $mesReciente;
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

<?php
if ($tipoConvocatoria === 'titulacion') {
    $etiquetaTipoConvocatoria = 'Titulación';
    $opcionesSubtipo = [
        [
            'slug' => 'ejecutivas',
            'titulo' => 'Ejecutivas',
            'descripcion' => 'Consulta las convocatorias correspondientes a ejecutivas.',
            'icono' => 'bi-briefcase'
        ],
        [
            'slug' => 'experiencia-laboral',
            'titulo' => 'Titulación por experiencia laboral',
            'descripcion' => 'Consulta las convocatorias de titulación por experiencia laboral.',
            'icono' => 'bi-person-workspace'
        ],
        [
            'slug' => 'inscripciones-abiertas',
            'titulo' => 'Inscripciones Abiertas',
            'descripcion' => 'Consulta las convocatorias con inscripciones abiertas.',
            'icono' => 'bi-door-open'
        ]
    ];
} elseif ($tipoConvocatoria === 'bachillerato') {
    $etiquetaTipoConvocatoria = 'Bachillerato';
    $opcionesSubtipo = [
        [
            'slug' => 'bachillerato-2-anos',
            'titulo' => 'Bachillerato en 2 años',
            'descripcion' => 'Consulta las convocatorias correspondientes a Bachillerato en 2 años.',
            'icono' => 'bi-calendar2-check'
        ],
        [
            'slug' => 'bachillerato-286',
            'titulo' => 'Bachillerato 286',
            'descripcion' => 'Consulta las convocatorias correspondientes a Bachillerato 286.',
            'icono' => 'bi-journal-text'
        ],
        [
            'slug' => 'ingles',
            'titulo' => 'Inglés',
            'descripcion' => 'Consulta las convocatorias correspondientes a Inglés.',
            'icono' => 'bi-translate'
        ],
        [
            'slug' => 'inscripciones-abiertas',
            'titulo' => 'Inscripciones Abiertas',
            'descripcion' => 'Consulta las convocatorias con inscripciones abiertas.',
            'icono' => 'bi-door-open'
        ]
    ];
} else {
    $etiquetaTipoConvocatoria = 'Sindicatos';
    $opcionesSubtipo = [];
}

$etiquetasSubtipo = [];
foreach ($opcionesSubtipo as $opcionSubtipo) {
    $etiquetasSubtipo[$opcionSubtipo['slug']] = $opcionSubtipo['titulo'];
}

if ($tipoConvocatoria === 'sindicatos') {
    $etiquetasSubtipo['sindicatos'] = 'Sindicatos';
}
?>

<?php if ($subtipoConvocatoria === ''): ?>
<div class="convocatoria-territory-context">
    <a
        class="data-back-link"
        href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index&territorio_id=<?= (int)$territorioSeleccionado['id'] ?>">
        <i class="bi bi-arrow-left"></i>
        Cambiar tipo
    </a>
    <span><?= $texto($territorioSeleccionado['nombre'] ?? '') ?> · <?= $texto($etiquetaTipoConvocatoria) ?></span>
</div>

<?php if ($tipoConvocatoria === 'titulacion' || $tipoConvocatoria === 'bachillerato'): ?>
    <section class="dashboard-panel convocatoria-titulacion-hero">
        <div class="convocatoria-titulacion-hero-copy">
            <span><?= $tipoConvocatoria === 'bachillerato' ? 'BACHILLERATO' : 'TITULACIÓN' ?></span>
            <h2>Consulta las convocatorias disponibles</h2>
            <p>
                Selecciona la opción que deseas consultar para el territorio de
                <?= $texto($territorioSeleccionado['nombre'] ?? '') ?>.
            </p>
        </div>

        <div class="convocatoria-titulacion-hero-icon" aria-hidden="true">
            <i class="bi <?= $tipoConvocatoria === 'bachillerato' ? 'bi-journal-bookmark' : 'bi-file-earmark-text' ?>"></i>
        </div>
    </section>

    <section class="convocatoria-titulacion-cards">
        <?php foreach ($opcionesSubtipo as $opcionSubtipo): ?>
            <article class="dashboard-panel convocatoria-titulacion-card">
                <div class="convocatoria-titulacion-card-icon">
                    <i class="bi <?= $texto($opcionSubtipo['icono']) ?>"></i>
                </div>

                <div class="convocatoria-titulacion-card-copy">
                    <h3><?= $texto($opcionSubtipo['titulo']) ?></h3>
                    <p><?= $texto($opcionSubtipo['descripcion']) ?></p>

                    <ul class="convocatoria-titulacion-benefits">
                        <li>
                            <i class="bi bi-check-circle"></i>
                            <span>Revisa requisitos</span>
                        </li>
                        <li>
                            <i class="bi bi-check-circle"></i>
                            <span>Consulta vigencia</span>
                        </li>
                        <li>
                            <i class="bi bi-check-circle"></i>
                            <span>Descarga información</span>
                        </li>
                    </ul>
                </div>

                <a
                    class="btn btn-system-light convocatoria-titulacion-card-button"
                    href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index&territorio_id=<?= (int)$territorioSeleccionado['id'] ?>&tipo=<?= $texto($tipoConvocatoria) ?>&subtipo=<?= $texto($opcionSubtipo['slug']) ?>">
                    Ver convocatorias
                    <i class="bi bi-arrow-right"></i>
                </a>
            </article>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <section class="dashboard-panel convocatoria-type-heading">
        <h2 class="panel-title mb-1">
            Opciones de <?= $texto($etiquetaTipoConvocatoria) ?>
        </h2>
        <p class="panel-subtitle mb-0">Selecciona la opción que deseas consultar para este territorio.</p>
    </section>

    <section class="convocatoria-type-cards convocatoria-subtype-cards">
        <?php foreach ($opcionesSubtipo as $opcionSubtipo): ?>
            <article class="dashboard-panel convocatoria-type-card">
                <div class="convocatoria-type-card-icon">
                    <i class="bi <?= $texto($opcionSubtipo['icono']) ?>"></i>
                </div>
                <div>
                    <h3><?= $texto($opcionSubtipo['titulo']) ?></h3>
                    <p><?= $texto($opcionSubtipo['descripcion']) ?></p>
                </div>
                <a
                    class="btn btn-system-light"
                    href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index&territorio_id=<?= (int)$territorioSeleccionado['id'] ?>&tipo=<?= $texto($tipoConvocatoria) ?>&subtipo=<?= $texto($opcionSubtipo['slug']) ?>">
                    Ver
                    <i class="bi bi-arrow-right"></i>
                </a>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php return; ?>
<?php endif; ?>

<?php if ($mostrarSelectorMes): ?>
<?php
$estadoMesLabels = [
    'activa' => 'Activa',
    'proxima' => 'Próxima',
    'finalizada' => 'Finalizada',
    'inactiva' => 'Inactiva'
];

$urlCambiarOpcionMes = $tipoConvocatoria === 'sindicatos'
    ? BASE_URL . 'index.php?controller=convocatoria&action=index&territorio_id=' .
        (int)$territorioSeleccionado['id']
    : BASE_URL . 'index.php?controller=convocatoria&action=index&territorio_id=' .
        (int)$territorioSeleccionado['id'] .
        '&tipo=' . rawurlencode($tipoConvocatoria);
?>

<div class="convocatoria-month-page">
    <div class="convocatoria-territory-context convocatoria-month-context">
        <a
            class="data-back-link"
            href="<?= $texto($urlCambiarOpcionMes) ?>">
            <i class="bi bi-arrow-left"></i>
            <?= $tipoConvocatoria === 'sindicatos' ? 'Cambiar tipo' : 'Cambiar opción' ?>
        </a>

        <span>
            <?= $texto($territorioSeleccionado['nombre'] ?? '') ?>
            · <?= $texto($etiquetaTipoConvocatoria) ?>
            <?php if ($tipoConvocatoria !== 'sindicatos'): ?>
                · <?= $texto($etiquetasSubtipo[$subtipoConvocatoria] ?? '') ?>
            <?php endif; ?>
        </span>
    </div>

    <section class="dashboard-panel convocatoria-month-shell">
        <div class="convocatoria-month-heading">
            <div class="convocatoria-month-heading-main">
                <span class="convocatoria-month-heading-icon" aria-hidden="true">
                    <i class="bi bi-calendar3"></i>
                </span>

                <div class="convocatoria-month-heading-copy">
                    <h2>Convocatorias por mes</h2>
                    <p>Selecciona un mes para consultar las convocatorias disponibles.</p>
                </div>
            </div>

            <form
                method="GET"
                action="<?= BASE_URL ?>index.php"
                class="convocatoria-month-year-form">
                <input type="hidden" name="controller" value="convocatoria">
                <input type="hidden" name="action" value="index">
                <input
                    type="hidden"
                    name="territorio_id"
                    value="<?= (int)$territorioSeleccionado['id'] ?>">
                <input
                    type="hidden"
                    name="tipo"
                    value="<?= $texto($tipoConvocatoria) ?>">
                <input
                    type="hidden"
                    name="subtipo"
                    value="<?= $texto($subtipoConvocatoria) ?>">

                <span aria-hidden="true">
                    <i class="bi bi-calendar3"></i>
                </span>

                <select
                    name="anio"
                    aria-label="Seleccionar año"
                    onchange="this.form.submit()">
                    <?php foreach ($aniosConvocatorias as $anioDisponible): ?>
                        <option
                            value="<?= (int)$anioDisponible ?>"
                            <?= (int)$anioDisponible === $anioSeleccionado ? 'selected' : '' ?>>
                            <?= (int)$anioDisponible ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </form>
        </div>

        <?php
        $mesActualSistema = (int)date('n');
        $anioActualSistema = (int)date('Y');
        ?>

        <div class="convocatoria-month-grid">
            <?php foreach ($mesesVisiblesConvocatorias as $mesVisible): ?>
                <?php
                $numeroMes = (int)($mesVisible['mes'] ?? 0);
                $anioMes = (int)($mesVisible['anio'] ?? $anioSeleccionado);
                $nombreMes = $nombresMesesConvocatoria[$numeroMes] ?? '';
                $esMesActual = (
                    $numeroMes === $mesActualSistema &&
                    $anioMes === $anioActualSistema
                );
                $datosMes = is_array($mesVisible['datos'] ?? null)
                    ? $mesVisible['datos']
                    : ['total' => 0, 'convocatorias' => []];

                $totalMes = (int)($datosMes['total'] ?? 0);
                $muestrasMes = is_array($datosMes['convocatorias'] ?? null)
                    ? $datosMes['convocatorias']
                    : [];

                $urlMes = BASE_URL .
                    'index.php?controller=convocatoria&action=index&territorio_id=' .
                    (int)$territorioSeleccionado['id'] .
                    '&tipo=' . rawurlencode($tipoConvocatoria) .
                    '&subtipo=' . rawurlencode($subtipoConvocatoria) .
                    '&anio=' . $anioMes .
                    '&mes=' . $numeroMes;
                ?>

                <article
                    class="dashboard-panel convocatoria-month-card<?= $esMesActual ? ' is-current-month' : '' ?>"
                    <?= $esMesActual ? 'aria-current="date"' : '' ?>>
                    <?php if ($esMesActual): ?>
                        <span class="convocatoria-month-current-badge">
                            Mes actual
                        </span>
                    <?php endif; ?>

                    <i
                        class="bi bi-calendar3 convocatoria-month-watermark"
                        aria-hidden="true"></i>

                    <div class="convocatoria-month-card-header">
                        <span class="convocatoria-month-icon">
                            <i class="bi bi-calendar3"></i>
                        </span>

                        <div class="convocatoria-month-card-title">
                            <strong>
                                <?= $texto($nombreMes) ?>
                                <?php if ($anioMes !== $anioSeleccionado): ?>
                                    <?= (int)$anioMes ?>
                                <?php endif; ?>
                            </strong>
                            <small>Consulta las convocatorias de <?= $texto(mb_strtolower($nombreMes, 'UTF-8')) ?>.</small>
                        </div>

                        <span class="convocatoria-month-count"><?= $totalMes ?></span>
                    </div>

                    <div class="convocatoria-month-preview">
                        <?php if (!empty($muestrasMes)): ?>
                            <?php foreach ($muestrasMes as $muestraMes): ?>
                                <?php
                                $estadoMes = (string)($muestraMes['estado_proceso'] ?? 'inactiva');
                                ?>
                                <div class="convocatoria-month-preview-row">
                                    <span class="convocatoria-month-dot is-<?= $texto($estadoMes) ?>"></span>
                                    <span class="convocatoria-month-preview-name">
                                        <?= $texto($muestraMes['titulo'] ?? '') ?>
                                    </span>
                                    <span class="convocatoria-month-status is-<?= $texto($estadoMes) ?>">
                                        <?= $texto($estadoMesLabels[$estadoMes] ?? 'Inactiva') ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>

                            <?php if ($totalMes > count($muestrasMes)): ?>
                                <small class="convocatoria-month-more">
                                    +<?= $totalMes - count($muestrasMes) ?> más
                                </small>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="convocatoria-month-empty">
                                <span class="convocatoria-month-dot is-empty"></span>
                                <span>Sin convocatorias registradas</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <a
                        class="btn btn-system-light convocatoria-month-button"
                        href="<?= $texto($urlMes) ?>">
                        Ver convocatorias
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<?php return; ?>
<?php endif; ?>

<div class="convocatoria-territory-context">
    <a
        class="data-back-link"
        href="<?= BASE_URL .
            'index.php?controller=convocatoria&action=index&territorio_id=' .
            (int)$territorioSeleccionado['id'] .
            '&tipo=' . rawurlencode($tipoConvocatoria) .
            '&subtipo=' . rawurlencode($subtipoConvocatoria) .
            '&anio=' . (int)$anioSeleccionado ?>">
        <i class="bi bi-arrow-left"></i>
        Cambiar mes
    </a>
    <span>
        <?= $texto($territorioSeleccionado['nombre'] ?? '') ?>
        · <?= $texto($etiquetaTipoConvocatoria) ?>
        <?php if ($tipoConvocatoria !== 'sindicatos'): ?>
            · <?= $texto($etiquetasSubtipo[$subtipoConvocatoria] ?? '') ?>
        <?php endif; ?>
        <?php if ($mesSeleccionado >= 1 && $mesSeleccionado <= 12): ?>
            · <?= $texto($nombresMesesConvocatoria[$mesSeleccionado] ?? '') ?>
            <?= (int)$anioSeleccionado ?>
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
        class="convocatoria-status-tab is-active"
        data-convocatoria-view="activas"
        role="tab"
        aria-selected="true">
        <span class="convocatoria-status-tab-icon">
            <i class="bi bi-megaphone"></i>
        </span>
        <span>Convocatorias activas</span>
        <strong data-convocatoria-count="activas"><?= count($convocatoriasActivasIniciales) ?></strong>
    </button>

    <button
        type="button"
        class="convocatoria-status-tab"
        data-convocatoria-view="historial"
        role="tab"
        aria-selected="false">
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
        <input type="hidden" name="subtipo" value="<?= $texto($subtipoConvocatoria) ?>">
        <input type="hidden" name="anio" value="<?= (int)$anioSeleccionado ?>">
        <input type="hidden" name="mes" value="<?= (int)$mesSeleccionado ?>">

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
            <label class="form-label login-label" for="filtro_convocatoria_fecha">Fecha</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                <input
                    type="date"
                    class="form-control system-form-control"
                    id="filtro_convocatoria_fecha"
                    name="fecha"
                    value="<?= $texto($fechaFiltro) ?>"
                    <?php if ($mesSeleccionado >= 1 && $mesSeleccionado <= 12): ?>
                        min="<?= sprintf('%04d-%02d-01', $anioSeleccionado, $mesSeleccionado) ?>"
                        max="<?= date(
                            'Y-m-t',
                            strtotime(sprintf('%04d-%02d-01', $anioSeleccionado, $mesSeleccionado))
                        ) ?>"
                    <?php endif; ?>>
            </div>
        </div>

        <input
            type="hidden"
            id="filtro_convocatoria_categoria"
            name="categoria"
            value="<?= $texto($categoriaFiltro) ?>">

        <div class="convocatoria-filter-actions">
            <a
                class="filter-clear-link <?= ($buscar === '' && $fechaFiltro === '' && $categoriaFiltro === '') ? 'd-none' : '' ?>"
                href="<?= BASE_URL ?>index.php?controller=convocatoria&action=index&territorio_id=<?= (int)$territorioSeleccionado['id'] ?>&tipo=<?= $texto($tipoConvocatoria) ?>&subtipo=<?= $texto($subtipoConvocatoria) ?>&anio=<?= (int)$anioSeleccionado ?>&mes=<?= (int)$mesSeleccionado ?>"
                data-convocatoria-clear-filters>
                Limpiar filtros
            </a>
        </div>
    </form>

    <div class="convocatoria-list-table">
        <div class="table-responsive">
            <table class="table users-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Imagen</th>
                        <th>Título</th>
                        <th>Periodo</th>
                        <th>Estados</th>
                        <th>Estatus</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>

                <tbody data-convocatorias-listado>
                    <?php if (!empty($convocatoriasActivasIniciales)): ?>
                        <?php foreach ($convocatoriasActivasIniciales as $convocatoria): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($convocatoria['imagen'])): ?>
                                        <button
                                            type="button"
                                            class="convocatoria-thumb-button"
                                            data-convocatoria-image="<?= BASE_URL . $texto($convocatoria['imagen']) ?>"
                                            data-convocatoria-image-title="<?= $texto($convocatoria['titulo']) ?>"
                                            aria-label="Ver imagen de <?= $texto($convocatoria['titulo']) ?>">
                                            <img
                                                src="<?= BASE_URL . $texto($convocatoria['imagen']) ?>"
                                                alt="<?= $texto($convocatoria['titulo']) ?>"
                                                class="convocatoria-thumb">
                                        </button>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>

                                <td><?= $texto($convocatoria['titulo']) ?></td>

                                <td>
                                    <?= $texto($fechaLegible($convocatoria['fecha_inicio'])) ?>
                                    —
                                    <?= $texto($fechaLegible($convocatoria['fecha_termino'])) ?>
                                </td>

                                <td><?= $texto($convocatoria['estados'] ?: 'Sin estados') ?></td>

                                <td>
                                    <span class="status-pill status-pill-active">
                                        Activa
                                    </span>
                                </td>

                                <td class="text-end">
                                    <div class="table-actions">
                                        <button
                                            type="button"
                                            class="table-action-button btn-ver-convocatoria"
                                            data-id="<?= (int)$convocatoria['id'] ?>"
                                            aria-label="Ver convocatoria">
                                            <i class="bi bi-eye"></i>
                                        </button>

                                        <?php if ($puedeEditar): ?>
                                            <button
                                                type="button"
                                                class="table-action-button btn-editar-convocatoria"
                                                data-id="<?= (int)$convocatoria['id'] ?>"
                                                aria-label="Editar convocatoria">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($puedeDescargar): ?>
                                            <a
                                                class="table-action-button"
                                                href="<?= BASE_URL ?>index.php?controller=convocatoria&action=descargarImagen&id=<?= (int)$convocatoria['id'] ?>"
                                                aria-label="Descargar imagen">
                                                <i class="bi bi-download"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($puedeCambiarEstado): ?>
                                            <button
                                                type="button"
                                                class="table-action-button table-action-warning"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalEstadoConvocatoria"
                                                data-id="<?= (int)$convocatoria['id'] ?>"
                                                data-titulo="<?= $texto($convocatoria['titulo']) ?>"
                                                data-estado-nuevo="0"
                                                aria-label="Desactivar convocatoria">
                                                <i class="bi bi-toggle-on"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-table-message">
                                    No hay convocatorias activas con los filtros seleccionados.
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
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

    <div class="modal-dialog modal-dialog-centered modal-lg system-form-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5 class="system-form-modal-title" id="modalCrearConvocatoriaTitulo">Nueva convocatoria</h5>
                    <p class="system-form-modal-subtitle">Registra una publicación y su cobertura territorial</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <form
                action="<?= BASE_URL ?>index.php?controller=convocatoria&action=guardar"
                method="POST"
                enctype="multipart/form-data"
                novalidate>
                <input type="hidden" name="territorio_id" value="<?= (int)$territorioSeleccionado['id'] ?>">
                <input type="hidden" name="tipo" value="<?= $texto($tipoConvocatoria) ?>">
                <input type="hidden" name="subtipo" value="<?= $texto($subtipoConvocatoria) ?>">
                <input type="hidden" name="anio" value="<?= (int)$anioSeleccionado ?>">
                <input type="hidden" name="mes" value="<?= (int)$mesSeleccionado ?>">

                <div class="modal-body">
                    <div class="system-form-grid">
                        <div class="system-form-full">
                            <label class="form-label login-label" for="crear_convocatoria_titulo">Título</label>
                            <input
                                type="text"
                                class="form-control system-form-control"
                                id="crear_convocatoria_titulo"
                                name="titulo"
                                value="<?= $texto($datosCrear['titulo'] ?? '') ?>"
                                required>
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
                                maxlength="1000"
                                placeholder="https://..."
                                value="<?= $texto($datosCrear['enlace_registro'] ?? '') ?>">
                            <small class="form-text">
                                Opcional. Cuenta Clave podrá copiar este enlace al preparar la convocatoria para WhatsApp.
                            </small>
                        </div>

                        <div>
                            <label class="form-label login-label" for="crear_convocatoria_fecha_inicio">Fecha de inicio</label>
                            <input
                                type="date"
                                class="form-control system-form-control"
                                id="crear_convocatoria_fecha_inicio"
                                name="fecha_inicio"
                                value="<?= $texto($datosCrear['fecha_inicio'] ?? '') ?>"
                                required>
                        </div>

                        <div>
                            <label class="form-label login-label" for="crear_convocatoria_fecha_termino">Fecha de término</label>
                            <input
                                type="date"
                                class="form-control system-form-control"
                                id="crear_convocatoria_fecha_termino"
                                name="fecha_termino"
                                value="<?= $texto($datosCrear['fecha_termino'] ?? '') ?>"
                                required>
                        </div>

                        <div>
                            <label class="form-label login-label" for="crear_convocatoria_estado">Disponibilidad</label>
                            <select class="form-select system-form-control" id="crear_convocatoria_estado" name="estado">
                                <option value="1">Activa</option>
                                <option value="0">Inactiva</option>
                            </select>
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

                        <div class="system-form-full">
                            <label class="form-label login-label" for="crear_convocatoria_estados">Estados</label>
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
                                        <label
                                            class="convocatoria-state-option"
                                            data-state-option
                                            data-state-name="<?= $texto(mb_strtolower((string)$estado['nombre'], 'UTF-8')) ?>">
                                            <input
                                                type="checkbox"
                                                class="form-check-input"
                                                name="estados[]"
                                                value="<?= $estadoIdActual ?>"
                                                <?= in_array($estadoIdActual, array_map('intval', $datosCrear['estados_ids'] ?? []), true) ? 'checked' : '' ?>>
                                            <span><?= $texto($estado['nombre']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <small class="form-text">Puedes seleccionar uno o varios estados.</small>
                        </div>
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
                            <input type="date" class="form-control system-form-control" id="editar_convocatoria_fecha_inicio" name="fecha_inicio" required>
                        </div>

                        <div>
                            <label class="form-label login-label" for="editar_convocatoria_fecha_termino">Fecha de término</label>
                            <input type="date" class="form-control system-form-control" id="editar_convocatoria_fecha_termino" name="fecha_termino" required>
                        </div>

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
                            <label class="form-label login-label" for="editar_convocatoria_estados">Estados</label>
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
                                        <label
                                            class="convocatoria-state-option"
                                            data-state-option
                                            data-state-name="<?= $texto(mb_strtolower((string)$estado['nombre'], 'UTF-8')) ?>">
                                            <input
                                                type="checkbox"
                                                class="form-check-input"
                                                name="estados[]"
                                                value="<?= $estadoIdActual ?>"
                                                >
                                            <span><?= $texto($estado['nombre']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
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
    const modalImagenElemento = document.getElementById('modalImagenConvocatoria');
    const modalImagen = modalImagenElemento ? new bootstrap.Modal(modalImagenElemento) : null;
    const imagenPreview = modalImagenElemento?.querySelector('[data-convocatoria-image-preview]');
    const imagenTitulo = document.getElementById('modalImagenConvocatoriaTitulo');

    document.addEventListener('click', function (event) {
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
                    escapeHtml((convocatoria.fecha_inicio || '—') + ' — ' + (convocatoria.fecha_termino || '—')) +
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

                modalEditar.querySelectorAll('[data-state-option] input[type="checkbox"]').forEach(function (checkbox) {
                    checkbox.checked = seleccion.has(String(checkbox.value));
                });

                actualizarSeleccionTodos(modalEditar.querySelector('[data-state-picker]'));

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

    let vistaConvocatorias = 'activas';
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
            String(filtroCategoria?.value || '') !== '';

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
                '<tr><td colspan="6"><div class="empty-table-message">' +
                mensajeVacio +
                '</div></td></tr>';
            return;
        }

        listadoConvocatorias.innerHTML = convocatoriasVista.map(function (convocatoria) {
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
                '" aria-label="Descargar imagen"><i class="bi bi-download"></i></a>'
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
                '<td>' + escapeHtml(convocatoria.titulo || '') + '</td>' +
                '<td>' + escapeHtml(formatearFecha(convocatoria.fecha_inicio)) +
                    ' — ' + escapeHtml(formatearFecha(convocatoria.fecha_termino)) + '</td>' +
                '<td>' + escapeHtml(convocatoria.estados || 'Sin estados') + '</td>' +
                '<td><span class="status-pill ' +
                    (estadoActivo ? 'status-pill-active' : 'status-pill-inactive') + '">' +
                    (estadoActivo ? 'Activa' : 'Inactiva') +
                '</span></td>' +
                '<td class="text-end"><div class="table-actions">' +
                    acciones.join('') +
                '</div></td>' +
            '</tr>';
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
            subtipo: <?= json_encode($subtipoConvocatoria) ?>,
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
                    '<tr><td colspan="6"><div class="alert alert-danger mb-0">' +
                    escapeHtml(error.message) +
                    '</div></td></tr>';
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

    tabsConvocatorias.forEach(function (tab) {
        tab.addEventListener('click', function () {
            vistaConvocatorias = tab.dataset.convocatoriaView === 'historial'
                ? 'historial'
                : 'activas';

            renderConvocatorias(convocatoriasActuales, false);
        });
    });

    renderConvocatorias(convocatoriasActuales, false);

    filtroBuscar?.addEventListener('input', programarBusqueda);

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
        new bootstrap.Modal(document.getElementById('modalCrearConvocatoria')).show();
    <?php endif; ?>
});
</script>
