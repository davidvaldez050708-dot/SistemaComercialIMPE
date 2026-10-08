<?php

$territorios = $territorios ?? [];
$mensajeError = $mensajeError ?? '';
$estructuraAliadosDisponible = $estructuraAliadosDisponible ?? false;

$texto = static function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$slugEstado = static function ($nombreEstado) {
    $nombreEstado = trim((string)$nombreEstado);
    $slug = strtolower($nombreEstado);
    $slug = iconv('UTF-8', 'ASCII//TRANSLIT', $slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', (string)$slug);
    $slug = trim((string)$slug, '-');

    $especiales = [
        'Ciudad de México' => 'ciudad-de-mexico',
        'Estado de México' => 'estado-de-mexico',
        'Michoacán' => 'michoacán',
        'Nuevo León' => 'nuevo-leon',
        'Querétaro' => 'queretaro',
        'San Luis Potosí' => 'san-luis-potosi',
        'Yucatán' => 'yucatan'
    ];

    return $especiales[$nombreEstado] ?? $slug;
};

$imagenEstado = static function ($territorio) use ($slugEstado) {
    return BASE_URL .
        'public/img/estados/' .
        $slugEstado($territorio['nombre'] ?? '') .
        '.png';
};

$tarjetasPorPagina = 12;

?>

<?php if ($mensajeError !== ''): ?>
    <div class="alert alert-danger login-alert mb-3" role="alert">
        <i class="bi bi-exclamation-circle"></i>
        <span><?= $texto($mensajeError) ?></span>
    </div>
<?php endif; ?>

<?php if (!$estructuraAliadosDisponible): ?>
    <div class="alert alert-warning aliados-structure-alert" role="alert">
        <i class="bi bi-exclamation-triangle"></i>
        <div>
            <strong>Falta preparar la estructura de Aliados.</strong>
            <span>Aplica las migraciones del módulo antes de utilizarlo.</span>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($territorios)): ?>
    <section class="dashboard-panel data-territorial-selector aliados-territory-selector">
        <div class="data-selector-heading">
            <div class="data-territorial-selector-copy">
                <h2 class="panel-title mb-1">Seleccionar territorio</h2>
                <p>Busca o selecciona un Estado para consultar sus instituciones aliadas.</p>
            </div>
        </div>

        <form class="data-territorial-toolbar aliados-territory-toolbar" action="#" method="GET" data-ally-territory-filters>
            <div class="data-filter-field aliados-territory-search-field">
                <label for="buscar_territorio_aliados">Buscar territorio</label>
                <div class="module-search">
                    <i class="bi bi-search"></i>
                    <input
                        type="search"
                        class="form-control"
                        id="buscar_territorio_aliados"
                        placeholder="Buscar territorio..."
                        autocomplete="off"
                        data-ally-territory-search>
                </div>
            </div>

            <div class="data-filter-field">
                <label for="filtro_estado_aliados">Estado de aliados</label>
                <select
                    class="form-select"
                    id="filtro_estado_aliados"
                    data-ally-territory-status>
                    <option value="todos">Todos</option>
                    <option value="con">Con aliados</option>
                    <option value="sin">Sin aliados</option>
                </select>
            </div>

            <div class="data-filter-field">
                <label for="selector_territorio_aliados">Territorio</label>
                <select
                    class="form-select data-territorial-state-select"
                    id="selector_territorio_aliados"
                    data-ally-territory-select>
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

    <div class="linkage-results-text aliados-territory-results">
        <span data-ally-territory-counter>
            <?= count($territorios) ?> <?= count($territorios) === 1 ? 'resultado' : 'resultados' ?>
        </span>
    </div>

    <section
        class="data-territorial-cards linkage-territory-grid aliados-territory-grid"
        aria-label="Territorios con alcance de Aliados"
        data-ally-territory-grid>
        <?php foreach ($territorios as $territorio): ?>
            <?php
            $totalAliados = (int)($territorio['total_aliados'] ?? 0);
            $totalMunicipios = (int)($territorio['total_municipios_aliados'] ?? 0);
            $tieneAliados = $totalAliados > 0 ? 1 : 0;
            $urlEstado = BASE_URL .
                'index.php?controller=aliado&action=estado&estado_id=' .
                (int)$territorio['id'];
            ?>
            <article
                class="dashboard-panel data-territorial-card linkage-territory-card aliados-territory-card"
                role="link"
                tabindex="0"
                data-ally-state-card
                data-estado-id="<?= (int)$territorio['id'] ?>"
                data-estado-nombre="<?= $texto($territorio['nombre'] ?? '') ?>"
                data-estado-alias="<?= $texto($territorio['nombre_corto'] ?? '') ?>"
                data-tiene-aliados="<?= $tieneAliados ?>"
                data-card-url="<?= $texto($urlEstado) ?>">
                <div class="data-card-header">
                    <div class="data-state-image">
                        <img
                            src="<?= $texto($imagenEstado($territorio)) ?>"
                            alt="Mapa de <?= $texto($territorio['nombre'] ?? '') ?>">
                    </div>

                    <div class="data-card-header-info">
                        <div class="data-card-heading">
                            <h3><?= $texto($territorio['nombre'] ?? '') ?></h3>
                            <?php if (trim((string)($territorio['nombre_corto'] ?? '')) !== ''): ?>
                                <p><?= $texto($territorio['nombre_corto']) ?></p>
                            <?php endif; ?>
                        </div>

                        <?php if ($tieneAliados): ?>
                            <span class="data-info-badge data-info-badge-complete">Con aliados</span>
                        <?php else: ?>
                            <span class="data-info-badge data-info-badge-empty">Sin aliados</span>
                        <?php endif; ?>
                    </div>
                </div>

                <dl class="data-card-meta linkage-card-meta aliados-territory-meta">
                    <div>
                        <dt>Aliados</dt>
                        <dd>
                            <?= $totalAliados ?>
                            <?= $totalAliados === 1 ? 'formalizado' : 'formalizados' ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Municipios</dt>
                        <dd>
                            <?= $totalMunicipios ?>
                            <?= $totalMunicipios === 1 ? 'con aliado' : 'con aliados' ?>
                        </dd>
                    </div>
                </dl>

                <a class="btn btn-system-light" href="<?= $texto($urlEstado) ?>">
                    Ver aliados
                </a>
            </article>
        <?php endforeach; ?>
    </section>

    <div class="data-pagination data-territory-pagination linkage-pagination aliados-territory-pagination" data-ally-territory-pagination>
        <span data-ally-territory-pagination-label></span>
        <div data-ally-territory-pages></div>
    </div>

    <section class="dashboard-panel data-empty-state linkage-empty-state d-none" data-ally-territory-empty>
        <span><i class="bi bi-search"></i></span>
        <strong>No se encontraron territorios con los filtros seleccionados.</strong>
        <p>Prueba cambiando la búsqueda o el estado de aliados.</p>
    </section>
<?php else: ?>
    <section class="dashboard-panel data-empty-state linkage-empty-state">
        <span><i class="bi bi-map"></i></span>
        <strong>No tienes territorios disponibles para Aliados.</strong>
        <p>Los Estados aparecerán cuando exista una asignación territorial vigente para tu Cuenta Clave.</p>
    </section>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const form = document.querySelector('[data-ally-territory-filters]');
    if (!form) {
        return;
    }

    const cards = Array.from(document.querySelectorAll('[data-ally-state-card]'));
    const search = form.querySelector('[data-ally-territory-search]');
    const status = form.querySelector('[data-ally-territory-status]');
    const territory = form.querySelector('[data-ally-territory-select]');
    const counter = document.querySelector('[data-ally-territory-counter]');
    const grid = document.querySelector('[data-ally-territory-grid]');
    const empty = document.querySelector('[data-ally-territory-empty]');
    const pagination = document.querySelector('[data-ally-territory-pagination]');
    const paginationLabel = document.querySelector('[data-ally-territory-pagination-label]');
    const pages = document.querySelector('[data-ally-territory-pages]');
    const perPage = <?= (int)$tarjetasPorPagina ?>;
    let currentPage = 1;

    const normalize = function (value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    };

    const render = function (requestedPage) {
        const query = normalize(search?.value || '');
        const statusValue = status?.value || 'todos';
        const territoryValue = territory?.value || '';

        const filtered = cards.filter(function (card) {
            const text = normalize(
                (card.dataset.estadoNombre || '') + ' ' +
                (card.dataset.estadoAlias || '')
            );
            const hasAllies = card.dataset.tieneAliados === '1';

            return (
                (query === '' || text.includes(query)) &&
                (
                    statusValue === 'todos' ||
                    (statusValue === 'con' && hasAllies) ||
                    (statusValue === 'sin' && !hasAllies)
                ) &&
                (
                    territoryValue === '' ||
                    card.dataset.estadoId === territoryValue
                )
            );
        });

        const totalPages = Math.max(1, Math.ceil(filtered.length / perPage));
        currentPage = Math.max(
            1,
            Math.min(
                totalPages,
                parseInt(requestedPage || currentPage, 10) || 1
            )
        );

        const start = (currentPage - 1) * perPage;
        const visible = new Set(filtered.slice(start, start + perPage));

        cards.forEach(function (card) {
            card.classList.toggle('d-none', !visible.has(card));
        });

        if (counter) {
            counter.textContent =
                filtered.length +
                (filtered.length === 1 ? ' resultado' : ' resultados');
        }

        grid?.classList.toggle('d-none', filtered.length === 0);
        empty?.classList.toggle('d-none', filtered.length !== 0);

        if (pagination) {
            pagination.classList.toggle('d-none', totalPages <= 1 || filtered.length === 0);
        }

        if (paginationLabel) {
            paginationLabel.textContent = filtered.length === 0
                ? ''
                : 'Página ' + currentPage + ' de ' + totalPages;
        }

        if (pages) {
            pages.innerHTML = '';

            for (let page = 1; page <= totalPages; page++) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className =
                    'data-pagination-button' +
                    (page === currentPage ? ' active' : '');
                button.textContent = String(page);
                button.addEventListener('click', function () {
                    render(page);
                });
                pages.appendChild(button);
            }
        }
    };

    form.addEventListener('submit', function (event) {
        event.preventDefault();
    });

    search?.addEventListener('input', function () {
        currentPage = 1;
        render(1);
    });
    status?.addEventListener('change', function () {
        currentPage = 1;
        render(1);
    });
    territory?.addEventListener('change', function () {
        currentPage = 1;
        render(1);
    });

    cards.forEach(function (card) {
        card.addEventListener('click', function (event) {
            if (event.target.closest('a, button')) {
                return;
            }

            const url = card.dataset.cardUrl || '';
            if (url) {
                window.location.href = url;
            }
        });

        card.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }

            event.preventDefault();
            const url = card.dataset.cardUrl || '';
            if (url) {
                window.location.href = url;
            }
        });
    });

    render(1);
});
</script>
