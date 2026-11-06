<?php
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$baseInscritos = BASE_URL . 'index.php?controller=inscritos&action=';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/inscritos.css?v=<?= filemtime(ROOT_PATH.'/public/css/inscritos.css') ?>">
<div class="inscritos-page" data-inscritos
     data-list-url="<?= $e($baseInscritos.'listar') ?>"
     data-detail-url="<?= $e($baseInscritos.'detalle') ?>"
     data-export-url="<?= $e($baseInscritos.'exportar') ?>"
     data-comprobante-url="<?= $e(BASE_URL.'index.php?controller=formulario&action=comprobanteInscripcion') ?>">
    <section class="inscritos-panel" aria-labelledby="inscritos-listado-titulo">
        <div class="inscritos-heading">
            <h2 id="inscritos-listado-titulo">Todos los inscritos</h2>
        </div>

        <form class="inscritos-filters" data-inscritos-filtros autocomplete="off" role="search">
            <div class="inscritos-field inscritos-search">
                <label for="inscritos-buscar">Buscar inscrito</label>
                <div class="inscritos-search-control">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input id="inscritos-buscar" type="search" name="buscar" maxlength="100"
                           placeholder="Nombre, apellidos, correo, teléfono o CURP"
                           aria-controls="inscritos-tabla">
                </div>
            </div>
            <div class="inscritos-field inscritos-programa">
                <label for="inscritos-programa">Programa</label>
                <select id="inscritos-programa" name="programa">
                    <option value="">Todos los programas</option>
                    <?php foreach ($programasInscritos as $programa): ?>
                        <option value="<?= $e($programa['slug']) ?>"><?= $e($programa['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="inscritos-field inscritos-fecha">
                <label for="inscritos-desde">Desde</label>
                <input id="inscritos-desde" type="date" name="desde">
            </div>
            <div class="inscritos-field inscritos-fecha">
                <label for="inscritos-hasta">Hasta</label>
                <input id="inscritos-hasta" type="date" name="hasta">
            </div>
            <button type="button" class="inscritos-clear" data-inscritos-limpiar title="Limpiar filtros">
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Limpiar
            </button>
        </form>

        <div class="inscritos-results-toolbar">
            <p data-inscritos-resumen aria-live="polite">Cargando inscripciones…</p>
            <?php if ($puedeExportarInscritos): ?>
                <button type="button" class="inscritos-excel" data-inscritos-excel disabled>
                    <i class="bi bi-file-earmark-excel" aria-hidden="true"></i>
                    Exportar Excel
                </button>
            <?php endif; ?>
        </div>

        <div class="inscritos-table-wrap">
            <table id="inscritos-tabla">
                <thead>
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col">Nombre completo</th>
                        <th scope="col">Programa</th>
                        <th scope="col">Correo electrónico</th>
                        <th scope="col">Teléfono</th>
                        <th scope="col">Origen</th>
                        <th scope="col" class="inscritos-actions-col">Ficha</th>
                    </tr>
                </thead>
                <tbody data-inscritos-body>
                    <tr><td colspan="7" class="inscritos-message">Consultando inscripciones…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="inscritos-footer">
            <small>Inscripciones de los formularios actuales · Hora de México.</small>
            <nav class="inscritos-pagination" data-inscritos-paginacion aria-label="Páginas de inscritos" hidden></nav>
        </div>
        <?php if ($puedeExportarInscritos): ?>
            <p class="inscritos-export-note">El Excel incluye todos los resultados del filtro, hasta 10,000 registros por descarga.</p>
        <?php endif; ?>
    </section>
</div>

<div class="inscritos-overlay" data-inscritos-overlay hidden></div>
<aside class="inscritos-drawer" data-inscritos-drawer
      role="dialog" aria-modal="true" aria-label="Ficha de inscripción"
      aria-hidden="true" hidden tabindex="-1">
    <div class="inscritos-drawer-header">
        <div><span class="inscritos-eyebrow">EXPEDIENTE DE INSCRIPCIÓN</span>
            <h3 data-inscritos-ficha-titulo>Detalle del inscrito</h3>
            <p data-inscritos-ficha-programa>Información registrada</p>
        </div>
        <button type="button" data-inscritos-cerrar aria-label="Cerrar ficha" class="inscritos-close">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>
    <div class="inscritos-drawer-scroll" data-inscritos-ficha role="region" aria-live="polite">
        <p class="inscritos-drawer-loading">Selecciona una inscripción para consultar su ficha.</p>
    </div>
    <div class="inscritos-drawer-actions" data-inscritos-comprobante-actions hidden>
        <a class="inscritos-download" data-inscritos-comprobante href="#">
            <i class="bi bi-download" aria-hidden="true"></i> Descargar comprobante
        </a>
        <small>El archivo adjunto no confirma que el pago haya sido acreditado.</small>
    </div>
</aside>
<script src="<?= BASE_URL ?>public/javascript/inscritos.js?v=<?= filemtime(ROOT_PATH.'/public/javascript/inscritos.js') ?>" defer></script>
