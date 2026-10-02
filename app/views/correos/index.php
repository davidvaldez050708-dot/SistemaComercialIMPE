<?php
$resumenCorreos = is_array($resumenCorreos ?? null)
    ? $resumenCorreos
    : [
        'total' => 0,
        'enviados' => 0,
        'pendientes' => 0,
        'borradores' => 0
    ];

$correos = is_array($correos ?? null)
    ? $correos
    : [];

$texto = static fn($valor) => htmlspecialchars(
    (string)$valor,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>

<section class="correo-marketing-module">
    <section class="dashboard-panel correo-marketing-selector">
        <div class="correo-marketing-selector-heading">
            <div>
                <h2 class="panel-title">Centro de correos</h2>
                <p>
                    Consulta y organiza los correos relacionados con las actividades de Marketing.
                </p>
            </div>

            <span class="correo-marketing-selector-icon" aria-hidden="true">
                <i class="bi bi-envelope-paper"></i>
            </span>
        </div>

        <div class="correo-marketing-summary-grid">
            <article class="correo-marketing-summary-card">
                <span class="correo-marketing-summary-icon">
                    <i class="bi bi-envelope"></i>
                </span>
                <div>
                    <strong><?= (int)($resumenCorreos['total'] ?? 0) ?></strong>
                    <span>Total de correos</span>
                </div>
            </article>

            <article class="correo-marketing-summary-card">
                <span class="correo-marketing-summary-icon">
                    <i class="bi bi-send-check"></i>
                </span>
                <div>
                    <strong><?= (int)($resumenCorreos['enviados'] ?? 0) ?></strong>
                    <span>Enviados</span>
                </div>
            </article>

            <article class="correo-marketing-summary-card">
                <span class="correo-marketing-summary-icon">
                    <i class="bi bi-clock-history"></i>
                </span>
                <div>
                    <strong><?= (int)($resumenCorreos['pendientes'] ?? 0) ?></strong>
                    <span>Pendientes</span>
                </div>
            </article>

            <article class="correo-marketing-summary-card">
                <span class="correo-marketing-summary-icon">
                    <i class="bi bi-file-earmark-text"></i>
                </span>
                <div>
                    <strong><?= (int)($resumenCorreos['borradores'] ?? 0) ?></strong>
                    <span>Borradores</span>
                </div>
            </article>
        </div>
    </section>

    <section class="dashboard-panel correo-marketing-toolbar-panel">
        <div class="correo-marketing-toolbar-heading">
            <div>
                <h2 class="panel-title">Bandeja de correos</h2>
                <p>Busca y filtra los correos registrados en este módulo.</p>
            </div>
        </div>

        <form class="correo-marketing-toolbar" action="#" method="GET">
            <div class="data-filter-field correo-marketing-search">
                <label for="correo_marketing_buscar">Buscar correo</label>
                <div class="module-search">
                    <i class="bi bi-search"></i>
                    <input
                        type="search"
                        class="form-control"
                        id="correo_marketing_buscar"
                        name="buscar"
                        placeholder="Buscar por asunto, destinatario o correo..."
                        autocomplete="off">
                </div>
            </div>

            <div class="data-filter-field">
                <label for="correo_marketing_estado">Estado</label>
                <select
                    class="form-select"
                    id="correo_marketing_estado"
                    name="estado">
                    <option value="">Todos</option>
                    <option value="enviado">Enviados</option>
                    <option value="pendiente">Pendientes</option>
                    <option value="borrador">Borradores</option>
                </select>
            </div>

            <div class="data-filter-field">
                <label for="correo_marketing_tipo">Tipo</label>
                <select
                    class="form-select"
                    id="correo_marketing_tipo"
                    name="tipo">
                    <option value="">Todos</option>
                    <option value="convocatoria">Convocatorias</option>
                    <option value="general">General</option>
                </select>
            </div>
        </form>
    </section>

    <div class="correo-marketing-results">
        <span>
            <?= count($correos) ?>
            <?= count($correos) === 1 ? 'resultado' : 'resultados' ?>
        </span>
    </div>

    <section class="dashboard-panel correo-marketing-list-panel">
        <div class="correo-marketing-tabs" role="tablist" aria-label="Bandejas de correo">
            <button
                type="button"
                class="correo-marketing-tab is-active"
                data-correo-tab="todos"
                aria-selected="true">
                <i class="bi bi-inbox"></i>
                Todos
            </button>

            <button
                type="button"
                class="correo-marketing-tab"
                data-correo-tab="enviados"
                aria-selected="false">
                <i class="bi bi-send-check"></i>
                Enviados
            </button>

            <button
                type="button"
                class="correo-marketing-tab"
                data-correo-tab="pendientes"
                aria-selected="false">
                <i class="bi bi-clock-history"></i>
                Pendientes
            </button>

            <button
                type="button"
                class="correo-marketing-tab"
                data-correo-tab="borradores"
                aria-selected="false">
                <i class="bi bi-file-earmark-text"></i>
                Borradores
            </button>
        </div>

        <div class="table-responsive">
            <table class="table users-table correo-marketing-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Destinatario</th>
                        <th>Asunto</th>
                        <th>Tipo</th>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($correos)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="correo-marketing-empty">
                                    <span class="correo-marketing-empty-icon">
                                        <i class="bi bi-envelope"></i>
                                    </span>

                                    <div>
                                        <h3>Aún no hay correos registrados</h3>
                                        <p>
                                            Cuando el módulo tenga correos asociados,
                                            aparecerán aquí para consulta y seguimiento.
                                        </p>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($correos as $correo): ?>
                            <tr>
                                <td>
                                    <strong><?= $texto($correo['destinatario'] ?? '—') ?></strong>
                                    <small><?= $texto($correo['correo'] ?? '') ?></small>
                                </td>
                                <td><?= $texto($correo['asunto'] ?? '—') ?></td>
                                <td><?= $texto($correo['tipo'] ?? 'General') ?></td>
                                <td><?= $texto($correo['fecha'] ?? '—') ?></td>
                                <td>
                                    <span class="correo-marketing-status">
                                        <?= $texto($correo['estado'] ?? 'Pendiente') ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button
                                        type="button"
                                        class="table-action-button"
                                        aria-label="Ver correo">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
