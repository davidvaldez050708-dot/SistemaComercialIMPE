<?php

$territorios = $territorios ?? [];
$modoSeguimiento = $modoSeguimiento ?? 'analista';
$mensajeExito = $mensajeExito ?? '';

$texto = function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$pluralAnalistas = function ($total) {
    return (int)$total === 1 ? 'asignado' : 'asignados';
};

$slugEstado = function ($nombreEstado) {
    $slug = strtolower((string)$nombreEstado);
    $slug = iconv('UTF-8', 'ASCII//TRANSLIT', $slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    $nombreEstado = trim((string)$nombreEstado);

    if ($nombreEstado === 'Ciudad de México') {
        $slug = 'ciudad-de-mexico';
    }

    if ($nombreEstado === 'Estado de México') {
        $slug = 'estado-de-mexico';
    }

    if ($nombreEstado === 'Michoacán') {
        $slug = 'michoacán';
    }

    if ($nombreEstado === 'Nuevo León') {
        $slug = 'nuevo-leon';
    }

    if ($nombreEstado === 'Querétaro') {
        $slug = 'queretaro';
    }

    if ($nombreEstado === 'San Luis Potosí') {
        $slug = 'san-luis-potosi';
    }

    if ($nombreEstado === 'Yucatán') {
        $slug = 'yucatan';
    }

    return $slug;
};

$imagenEstado = function ($territorio) use ($slugEstado) {
    return BASE_URL .
        'public/img/estados/' .
        $slugEstado($territorio['nombre'] ?? '') .
        '.png';
};

$badgeSeguimiento = function ($totalSeguimientos) {
    return (int)$totalSeguimientos > 0
        ? '<span class="data-info-badge data-info-badge-complete">Con seguimiento</span>'
        : '<span class="data-info-badge data-info-badge-empty">Sin seguimiento</span>';
};

$textoEmpty = [
    'administrador' => 'Cuando existan Estados activos aparecerán disponibles para su consulta.',
    'supervisor' => 'Cuando tengas una Cuenta Clave activa podrás revisar los seguimientos de tus Analistas.',
    'analista' => 'Cuando se te asigne un Estado podrás iniciar seguimientos de vinculación desde esta sección.'
];

$tarjetasPorPaginaSeguimiento = 12;

?>

<?php if (!empty($mensajeError)): ?>
    <div class="alert alert-danger login-alert mb-3" role="alert">
        <i class="bi bi-exclamation-circle"></i>
        <span><?= $texto($mensajeError) ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($mensajeExito)): ?>
    <div class="alert alert-success login-alert mb-3" role="status">
        <i class="bi bi-check2-circle"></i>
        <span><?= $texto($mensajeExito) ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($territorios)): ?>
    <section class="dashboard-panel data-territorial-selector linkage-selector">
        <div class="data-selector-heading">
            <div class="data-territorial-selector-copy">
                <h2 class="panel-title">Seleccionar territorio</h2>
                <p>Busca o selecciona un Estado para consultar su seguimiento de vinculación.</p>
            </div>
        </div>

        <form class="data-territorial-toolbar linkage-territory-toolbar" action="#" method="GET" data-linkage-filters>
            <div class="data-filter-field">
                <label for="buscar_territorio_seguimiento">Buscar territorio</label>
                <div class="module-search">
                    <i class="bi bi-search"></i>
                    <input
                        type="search"
                        class="form-control"
                        id="buscar_territorio_seguimiento"
                        name="buscar_territorio"
                        placeholder="Buscar territorio..."
                        autocomplete="off"
                        aria-label="Buscar territorio"
                        data-linkage-search>
                </div>
            </div>

            <div class="data-filter-field">
                <label for="filtro_estado_seguimiento">Estado de seguimiento</label>
                <select
                    class="form-select"
                    id="filtro_estado_seguimiento"
                    name="estado_seguimiento"
                    aria-label="Filtrar por estado de seguimiento"
                    data-linkage-status-filter>
                    <option value="todos">Todos</option>
                    <option value="con">Con seguimiento</option>
                    <option value="sin">Sin seguimiento</option>
                </select>
            </div>

            <div class="data-filter-field">
                <label for="filtro_analistas_seguimiento">Analistas</label>
                <select
                    class="form-select"
                    id="filtro_analistas_seguimiento"
                    name="analistas"
                    aria-label="Filtrar por analistas"
                    data-linkage-analysts-filter>
                    <option value="todos">Todos</option>
                    <option value="con">Con analista</option>
                    <option value="sin">Sin analista</option>
                </select>
            </div>

            <div class="data-filter-field">
                <label for="selector_territorio_seguimiento">Territorio</label>
                <select
                    class="form-select data-territorial-state-select"
                    id="selector_territorio_seguimiento"
                    name="estado_id"
                    aria-label="Seleccionar Estado"
                    data-linkage-territory-filter>
                    <option value="">Seleccionar Estado</option>
                    <?php foreach ($territorios as $territorio): ?>
                        <option value="<?= (int)$territorio['id'] ?>">
                            <?= $texto($territorio['nombre'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </section>

    <div class="linkage-results-text" data-linkage-results-summary>
        <span data-linkage-counter>
            <?= count($territorios) ?> <?= count($territorios) === 1 ? 'resultado' : 'resultados' ?>
        </span>
    </div>

    <section class="data-territorial-cards linkage-territory-grid" aria-label="Territorios asignados" data-linkage-territory-grid>
        <?php foreach ($territorios as $territorio): ?>
            <?php
                $totalSeguimientos = (int)($territorio['total_seguimientos'] ?? 0);
                $totalAnalistas = $modoSeguimiento === 'analista'
                    ? 1
                    : (int)($territorio['total_analistas'] ?? 0);
                $tieneSeguimiento = $totalSeguimientos > 0 ? 1 : 0;
                $tieneAnalista = $totalAnalistas > 0 ? 1 : 0;
                $urlSeguimiento = BASE_URL .
                    'index.php?controller=seguimientoVinculacion&action=estado&estado_id=' .
                    (int)$territorio['id'];
                $mapaUrl = $imagenEstado($territorio);
            ?>
            <article
                class="dashboard-panel data-territorial-card linkage-territory-card"
                role="link"
                tabindex="0"
                data-estado-id="<?= (int)$territorio['id'] ?>"
                data-estado-nombre="<?= $texto($territorio['nombre'] ?? '') ?>"
                data-estado-alias="<?= $texto($territorio['nombre_corto'] ?? '') ?>"
                data-seguimientos="<?= $totalSeguimientos ?>"
                data-tiene-seguimiento="<?= $tieneSeguimiento ?>"
                data-analistas="<?= $totalAnalistas ?>"
                data-tiene-analista="<?= $tieneAnalista ?>"
                data-card-url="<?= $texto($urlSeguimiento) ?>">
                <div class="data-card-header">
                    <div class="data-state-image">
                        <img
                            src="<?= $texto($mapaUrl) ?>"
                            alt="Mapa de <?= $texto($territorio['nombre'] ?? '') ?>">
                    </div>

                    <div class="data-card-header-info">
                        <div class="data-card-heading">
                            <h3><?= $texto($territorio['nombre'] ?? '') ?></h3>
                            <?php if (trim((string)($territorio['nombre_corto'] ?? '')) !== ''): ?>
                                <p><?= $texto($territorio['nombre_corto']) ?></p>
                            <?php endif; ?>
                        </div>

                        <?= $badgeSeguimiento($totalSeguimientos) ?>
                    </div>
                </div>

                <dl class="data-card-meta linkage-card-meta">
                    <div>
                        <dt>Seguimientos</dt>
                        <dd>
                            <?= $totalSeguimientos ?>
                            <?= (int)$totalSeguimientos === 1 ? 'activo' : 'activos' ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Analistas</dt>
                        <dd>
                            <?= $totalAnalistas ?>
                            <?= $pluralAnalistas($totalAnalistas) ?>
                        </dd>
                    </div>
                </dl>

                <a
                    class="btn btn-system-light"
                    href="<?= $texto($urlSeguimiento) ?>">
                    Ver seguimiento
                </a>
            </article>
        <?php endforeach; ?>
    </section>

    <div class="data-pagination data-territory-pagination linkage-pagination" data-linkage-pagination>
        <span data-linkage-pagination-label></span>
        <div data-linkage-pages></div>
    </div>

    <section class="dashboard-panel data-empty-state linkage-empty-state d-none" data-linkage-empty>
        <span>
            <i class="bi bi-search"></i>
        </span>
        <strong>No se encontraron territorios con los filtros seleccionados.</strong>
        <p>Prueba cambiando la búsqueda o los filtros.</p>
    </section>
<?php else: ?>
    <section class="dashboard-panel data-empty-state linkage-empty-state">
        <span>
            <i class="bi bi-map"></i>
        </span>
        <strong>No tienes territorios asignados actualmente.</strong>
        <p>
            <?= $texto($textoEmpty[$modoSeguimiento] ?? $textoEmpty['analista']) ?>
        </p>
    </section>
<?php endif; ?>

<script>
const elementosPorPaginaSeguimiento = <?= (int)$tarjetasPorPaginaSeguimiento ?>;
let paginaActualSeguimiento = 1;

const normalizarTextoSeguimiento = function (valor) {
    return String(valor || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim();
};

const aplicarFiltrosSeguimiento = function (paginaSolicitada) {
    const formulario = document.querySelector('[data-linkage-filters]');
    const tarjetas = Array.from(document.querySelectorAll('[data-estado-id]'));
    const contador = document.querySelector('[data-linkage-counter]');
    const grid = document.querySelector('[data-linkage-territory-grid]');
    const estadoVacio = document.querySelector('[data-linkage-empty]');
    const paginacion = document.querySelector('[data-linkage-pagination]');
    const etiquetaPaginacion = document.querySelector('[data-linkage-pagination-label]');
    const paginas = document.querySelector('[data-linkage-pages]');

    if (!formulario || tarjetas.length === 0) {
        return;
    }

    const busqueda = normalizarTextoSeguimiento(
        formulario.querySelector('[data-linkage-search]')?.value || ''
    );
    const filtroSeguimiento =
        formulario.querySelector('[data-linkage-status-filter]')?.value || 'todos';
    const filtroAnalistas =
        formulario.querySelector('[data-linkage-analysts-filter]')?.value || 'todos';
    const filtroTerritorio =
        formulario.querySelector('[data-linkage-territory-filter]')?.value || '';

    paginaActualSeguimiento = Math.max(
        1,
        parseInt(paginaSolicitada || paginaActualSeguimiento, 10) || 1
    );

    const tarjetasFiltradas = tarjetas.filter(function (tarjeta) {
        const textoTerritorio = normalizarTextoSeguimiento(
            (tarjeta.dataset.estadoNombre || '') + ' ' +
            (tarjeta.dataset.estadoAlias || '')
        );
        const coincideBusqueda =
            busqueda === '' || textoTerritorio.includes(busqueda);
        const coincideSeguimiento =
            filtroSeguimiento === 'todos' ||
            (filtroSeguimiento === 'con' && tarjeta.dataset.tieneSeguimiento === '1') ||
            (filtroSeguimiento === 'sin' && tarjeta.dataset.tieneSeguimiento === '0');
        const coincideAnalistas =
            filtroAnalistas === 'todos' ||
            (filtroAnalistas === 'con' && tarjeta.dataset.tieneAnalista === '1') ||
            (filtroAnalistas === 'sin' && tarjeta.dataset.tieneAnalista === '0');
        const coincideTerritorio =
            filtroTerritorio === '' || tarjeta.dataset.estadoId === filtroTerritorio;
        return (
            coincideBusqueda &&
            coincideSeguimiento &&
            coincideAnalistas &&
            coincideTerritorio
        );
    });
    const total = tarjetasFiltradas.length;
    const totalPaginas = Math.max(
        1,
        Math.ceil(total / elementosPorPaginaSeguimiento)
    );

    if (paginaActualSeguimiento > totalPaginas) {
        paginaActualSeguimiento = 1;
    }

    const inicio = (paginaActualSeguimiento - 1) * elementosPorPaginaSeguimiento;
    const fin = inicio + elementosPorPaginaSeguimiento;
    const tarjetasVisibles = new Set(tarjetasFiltradas.slice(inicio, fin));

    tarjetas.forEach(function (tarjeta) {
        tarjeta.classList.toggle('d-none', !tarjetasVisibles.has(tarjeta));
    });

    if (contador) {
        contador.textContent =
            total + ' ' + (total === 1 ? 'resultado' : 'resultados');
    }

    if (grid) {
        grid.classList.toggle('d-none', total === 0);
    }

    if (estadoVacio) {
        estadoVacio.classList.toggle('d-none', total !== 0);
    }

    if (paginacion) {
        paginacion.classList.toggle(
            'd-none',
            total === 0 || total <= elementosPorPaginaSeguimiento
        );
    }

    if (etiquetaPaginacion && total > elementosPorPaginaSeguimiento) {
        etiquetaPaginacion.textContent =
            'Mostrando ' +
            (inicio + 1) +
            ' a ' +
            Math.min(fin, total) +
            ' de ' +
            total +
            ' territorios';
    }

    if (paginas) {
        paginas.innerHTML = '';

        if (totalPaginas > 1) {
            const paginaAnterior = Math.max(1, paginaActualSeguimiento - 1);
            const paginaSiguiente = Math.min(totalPaginas, paginaActualSeguimiento + 1);
            const crearEnlace = function (pagina, texto, activo, deshabilitado) {
                const enlace = document.createElement('a');
                enlace.href = '#';
                enlace.dataset.linkagePage = String(pagina);
                enlace.textContent = texto;
                enlace.classList.toggle('active', activo);
                enlace.classList.toggle('disabled', deshabilitado);
                enlace.setAttribute('aria-disabled', deshabilitado ? 'true' : 'false');
                enlace.setAttribute('aria-label', texto);
                return enlace;
            };

            paginas.appendChild(
                crearEnlace(
                    paginaAnterior,
                    '‹',
                    false,
                    paginaActualSeguimiento === 1
                )
            );

            for (let pagina = 1; pagina <= totalPaginas; pagina += 1) {
                paginas.appendChild(
                    crearEnlace(
                        pagina,
                        String(pagina),
                        pagina === paginaActualSeguimiento,
                        false
                    )
                );
            }

            paginas.appendChild(
                crearEnlace(
                    paginaSiguiente,
                    '›',
                    false,
                    paginaActualSeguimiento === totalPaginas
                )
            );
        }
    }
};

const formularioSeguimiento = document.querySelector('[data-linkage-filters]');

if (formularioSeguimiento) {
    formularioSeguimiento.addEventListener('submit', function (event) {
        event.preventDefault();
        paginaActualSeguimiento = 1;
        aplicarFiltrosSeguimiento(1);
    });

    formularioSeguimiento
        .querySelector('[data-linkage-search]')
        ?.addEventListener('input', function () {
            paginaActualSeguimiento = 1;
            aplicarFiltrosSeguimiento(1);
        });

    formularioSeguimiento
        .querySelectorAll('select')
        .forEach(function (selector) {
            selector.addEventListener('change', function () {
                paginaActualSeguimiento = 1;
                aplicarFiltrosSeguimiento(1);
            });
        });

    document
        .querySelector('[data-linkage-pages]')
        ?.addEventListener('click', function (event) {
            const enlace = event.target.closest('[data-linkage-page]');

            if (!enlace) {
                return;
            }

            event.preventDefault();

            if (enlace.classList.contains('disabled')) {
                return;
            }

            aplicarFiltrosSeguimiento(
                parseInt(enlace.dataset.linkagePage || '1', 10)
            );
        });

    aplicarFiltrosSeguimiento(1);
}

document.querySelectorAll('.linkage-territory-card[data-card-url]').forEach(function (tarjeta) {
    tarjeta.addEventListener('click', function (event) {
        if (event.target.closest('a, button')) {
            return;
        }

        window.location.href = tarjeta.dataset.cardUrl;
    });

    tarjeta.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        event.preventDefault();
        window.location.href = tarjeta.dataset.cardUrl;
    });
});
</script>
