<?php
// La recepción es una función del perfil Marketing; no es el Marcador de Ventas.
require_once __DIR__ . '/../../helpers/TelefoniaMarketingAccessHelper.php';
$recepcionMarketingPermitida = marketingTieneRecepcionAsignada();
?>
<?php if ($recepcionMarketingPermitida): ?>
<section class="dashboard-panel marketing-reception-panel"
    data-marketing-reception
    data-history-url="<?= htmlspecialchars(BASE_URL . 'prueba_telefonia/api/historial_recepcion_marketing.php', ENT_QUOTES, 'UTF-8') ?>"
    data-recording-url="<?= htmlspecialchars(BASE_URL . 'prueba_telefonia/api/grabacion_recepcion_marketing.php', ENT_QUOTES, 'UTF-8') ?>">
    <div class="marketing-reception-heading">
        <div class="marketing-reception-heading-main">
            <span class="marketing-reception-heading-icon" aria-hidden="true">
                <i class="bi bi-headset"></i>
            </span>
            <div>
                <span class="marketing-reception-eyebrow">TU RECEPCIÓN</span>
                <h2>Recepción telefónica</h2>
                <p>Consulta las llamadas entrantes de tu extensión, sin mezclar expedientes ni prospectos.</p>
            </div>
        </div>
        <div class="marketing-reception-actions">
            <span class="marketing-reception-state" data-marketing-reception-state role="status" aria-live="polite">
                <i class="bi bi-circle-fill" aria-hidden="true"></i>
                <span data-marketing-reception-state-text>Verificando recepción…</span>
            </span>
            <button type="button" class="btn btn-system-primary marketing-reception-activate"
                data-marketing-reception-activate disabled>
                <i class="bi bi-headset" aria-hidden="true"></i>
                Activar recepción
            </button>
            <button type="button" class="btn btn-system-light marketing-reception-details-toggle"
                data-marketing-reception-details-toggle aria-expanded="false"
                aria-controls="marketingRecepcionHistorial">
                <span data-marketing-reception-details-label>Ver historial</span>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>
        </div>
    </div>
    <div class="marketing-reception-details" id="marketingRecepcionHistorial"
        data-marketing-reception-details hidden>
    <div class="marketing-reception-explainer" data-marketing-reception-hint>
        Estamos verificando la extensión telefónica asignada a tu usuario.
    </div>
    <div class="marketing-reception-metrics" aria-label="Resumen de recepción de los últimos 30 días">
        <article class="marketing-reception-metric">
            <span class="marketing-reception-metric-icon"><i class="bi bi-telephone-inbound" aria-hidden="true"></i></span>
            <div><strong data-marketing-reception-total>0</strong><span>Recibidas</span></div>
        </article>
        <article class="marketing-reception-metric">
            <span class="marketing-reception-metric-icon is-good"><i class="bi bi-telephone-check" aria-hidden="true"></i></span>
            <div><strong data-marketing-reception-answered>0</strong><span>Atendidas</span></div>
        </article>
        <article class="marketing-reception-metric">
            <span class="marketing-reception-metric-icon is-alert"><i class="bi bi-telephone-x" aria-hidden="true"></i></span>
            <div><strong data-marketing-reception-missed>0</strong><span>Perdidas</span></div>
        </article>
        <article class="marketing-reception-metric">
            <span class="marketing-reception-metric-icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span>
            <div><strong data-marketing-reception-transferred>0</strong><span>Transferidas</span></div>
        </article>
    </div>

    <div class="marketing-reception-history-head">
        <div>
            <h3>Historial de llamadas entrantes</h3>
            <p>Últimos 30 días, exclusivamente de tu extensión y desde su asignación.</p>
        </div>
        <div class="marketing-reception-filters">
            <label class="marketing-reception-search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" class="form-control" placeholder="Buscar número…"
                    aria-label="Buscar llamadas por número o extensión de transferencia"
                    data-marketing-reception-search autocomplete="off">
            </label>
            <select class="form-select marketing-reception-status-filter"
                data-marketing-reception-filter aria-label="Filtrar llamadas por estado">
                <option value="">Todos los estados</option>
                <option value="Atendida">Atendidas</option>
                <option value="Perdida">Perdidas</option>
                <option value="Transferida">Transferidas</option>
                <option value="En curso">En curso</option>
                <option value="Sonando">Sonando</option>
            </select>
            <button type="button" class="btn btn-system-light marketing-reception-refresh"
                data-marketing-reception-refresh title="Actualizar historial"
                aria-label="Actualizar historial de recepción">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                Actualizar
            </button>
        </div>
    </div>

    <div class="table-responsive marketing-reception-table-scroll">
        <table class="table align-middle marketing-reception-table telephony-history-table">
            <thead>
                <tr>
                    <th>Fecha y hora</th>
                    <th>Número entrante</th>
                    <th>Estado</th>
                    <th>Duración</th>
                    <th>Transferida a</th>
                    <th>Grabación</th>
                </tr>
            </thead>
            <tbody data-marketing-reception-rows>
                <tr><td colspan="6" class="marketing-reception-placeholder">
                    Consultando el historial de llamadas…
                </td></tr>
            </tbody>
        </table>
    </div>
    <div class="marketing-reception-footer">
        <span data-marketing-reception-count role="status" aria-live="polite"></span>
        <nav class="marketing-reception-pagination" data-marketing-reception-pages
            aria-label="Páginas del historial de recepción" hidden></nav>
    </div>
    </div>
</section>
<?php endif; ?>
