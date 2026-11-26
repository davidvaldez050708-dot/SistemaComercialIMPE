<?php
// La recepción es una función del perfil Marketing; no es el Marcador de Ventas.
require_once __DIR__ . '/../../helpers/TelefoniaMarketingAccessHelper.php';
$recepcionMarketingPermitida = marketingTieneRecepcionAsignada();
?>
<?php if ($recepcionMarketingPermitida): ?>
<section class="dashboard-panel marketing-reception-panel" id="recepcion-telefonica"
    data-marketing-reception
    data-result-url="<?= htmlspecialchars(BASE_URL . 'prueba_telefonia/api/resultado_recepcion_marketing.php', ENT_QUOTES, 'UTF-8') ?>"
    data-result-csrf="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
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
            </div>
        </div>
        <div class="marketing-reception-actions">
            <label class="marketing-reception-tab-preference" data-marketing-reception-auto-wrap hidden
                title="Permite conectar la extensión automáticamente al iniciar sesión">
                <input type="checkbox" data-marketing-reception-auto checked>
                <span>Recepción automática</span>
            </label>
            <label class="marketing-reception-tab-preference"
                title="La pestaña telefónica debe permanecer abierta para recibir llamadas">
                <input type="checkbox" data-marketing-reception-tab-mode>
                <span>Usar pestaña, no ventanita</span>
            </label>
            <span class="marketing-reception-state" data-marketing-reception-state role="status" aria-live="polite">
                <i class="bi bi-circle-fill" aria-hidden="true"></i>
                <span data-marketing-reception-state-text>Verificando recepción…</span>
            </span>
            <button type="button" class="btn btn-system-primary marketing-reception-activate"
                data-marketing-reception-activate disabled>
                <i class="bi bi-headset" aria-hidden="true"></i>
                Activar recepción
            </button>
            
        </div>
    </div>
    <div class="marketing-reception-details" id="marketingRecepcionHistorial"
        data-marketing-reception-details>
    <div class="marketing-reception-metrics" aria-label="Resumen telefónico de los últimos 30 días">
        <article class="marketing-reception-metric">
            <span class="marketing-reception-metric-icon"><i class="bi bi-telephone-inbound" aria-hidden="true"></i></span>
            <div><strong data-marketing-reception-total>0</strong><span>Recibidas</span></div>
        </article>
        <article class="marketing-reception-metric">
            <span class="marketing-reception-metric-icon is-good"><i class="bi bi-telephone-fill" aria-hidden="true"></i><i class="bi bi-check-lg marketing-reception-answered-check" aria-hidden="true"></i></span>
            <div><strong data-marketing-reception-answered>0</strong><span>Atendidas</span></div>
        </article>
        <article class="marketing-reception-metric">
            <span class="marketing-reception-metric-icon is-alert"><i class="bi bi-telephone-x" aria-hidden="true"></i></span>
            <div><strong data-marketing-reception-missed>0</strong><span>Perdidas</span></div>
        </article>
        <article class="marketing-reception-metric">
            <span class="marketing-reception-metric-icon is-outgoing"><i class="bi bi-telephone-outbound" aria-hidden="true"></i></span>
            <div><strong data-marketing-reception-outgoing>0</strong><span>Salientes</span></div>
        </article>
        <article class="marketing-reception-metric">
            <span class="marketing-reception-metric-icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span>
            <div><strong data-marketing-reception-transferred>0</strong><span>Transferidas</span></div>
        </article>
        <article class="marketing-reception-metric">
            <span class="marketing-reception-metric-icon is-alert"><i class="bi bi-person-dash" aria-hidden="true"></i></span>
            <div><strong data-marketing-reception-no-contact>0</strong><span>Salientes sin contacto</span></div>
        </article>
    </div>

    <div class="marketing-reception-history-head">
        <div>
            <h3>Historial de llamadas</h3>
            <p>Últimos 30 días, exclusivamente de tu extensión y desde su asignación.</p>
        </div>
    </div>
        <div class="marketing-reception-filters" role="search" aria-label="Filtrar historial telefónico">
            <label class="marketing-reception-search">
                <span class="marketing-reception-filter-label">Buscar llamada</span>
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" class="form-control" placeholder="Número, extensión o resultado…"
                    aria-label="Buscar llamadas por número, extensión o resultado"
                    data-marketing-reception-search autocomplete="off">
            </label>
            <label class="marketing-reception-filter-field">
            <span class="marketing-reception-filter-label">Tipo de llamada</span>
            <select class="form-select marketing-reception-status-filter"
                data-native-select data-marketing-reception-type aria-label="Filtrar por tipo de llamada">
                <option value="">Entrantes y salientes</option>
                <option value="Entrante">Entrantes</option>
                <option value="Saliente">Salientes</option>
            </select>
            </label>
            <label class="marketing-reception-filter-field">
            <span class="marketing-reception-filter-label">Estado de llamada</span>
            <select class="form-select marketing-reception-status-filter"
                data-native-select data-marketing-reception-filter aria-label="Filtrar llamadas por estado">
                <option value="">Todos los estados</option>
                <option value="respondidas">Respondidas</option>
                <option value="sin-respuesta">Sin respuesta</option>
                <option value="Transferida">Transferidas</option>
                <option value="activas">En curso</option>
                <option value="no-completadas">No completadas</option>
                <option value="pendientes">Pendientes de resultado</option>
            </select>
            </label>
            <button type="button" class="btn btn-system-light marketing-reception-refresh"
                data-marketing-reception-refresh title="Actualizar historial"
                aria-label="Actualizar historial de recepción">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                Actualizar
            </button>
        </div>

    <div class="table-responsive marketing-reception-table-scroll">
        <table class="table align-middle marketing-reception-table telephony-history-table">
            <thead>
                <tr>
                    <th>Fecha y hora</th>
                    <th>Tipo</th>
                    <th>Número</th>
                    <th>Llamar</th>
                    <th>Estado</th>
                    <th>Resultado</th>
                    <th>Duración</th>
                    <th>Transferida a</th>
                    <th>Grabación</th>
                </tr>
            </thead>
            <tbody data-marketing-reception-rows>
                <tr><td colspan="9" class="marketing-reception-placeholder">
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
    <div class="modal fade marketing-reception-result-modal" id="modalResultadoRecepcionMarketing"
        data-marketing-result-dialog tabindex="-1" aria-labelledby="marketingResultadoTitulo" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable system-form-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div><h5 class="system-form-modal-title" id="marketingResultadoTitulo">Registrar resultado de llamada</h5>
                    <p class="system-form-modal-subtitle" data-marketing-result-phone></p></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
        <form class="marketing-reception-result-form" data-marketing-result-form>
            <div class="modal-body system-form-modal-body">
                <p class="marketing-reception-result-description">Selecciona el resultado observado y añade una nota si necesitas dar seguimiento. Una vez guardado, no se podrá modificar.</p>
                <div class="mb-3">
                <label class="form-label" for="marketingResultado">Resultado observado</label>
                <select class="form-select" id="marketingResultado" name="resultado" required
                    data-native-select data-marketing-result-select>
                    <option value="">Selecciona un resultado</option>
                    <option value="CONTACTO">Persona atendió</option>
                    <option value="BUZON">Buzón de voz</option>
                    <option value="NO_DISPONIBLE">No disponible</option>
                    <option value="NUMERO_INCORRECTO">Número incorrecto</option>
                    <option value="OTRO">Otro resultado</option>
                    <option value="SIN_CLASIFICAR">Sin clasificar</option>
                </select>
                </div>
                <div class="mb-0">
                <label class="form-label" for="marketingResultadoNota">Nota <span class="text-muted">(opcional)</span></label>
                <textarea class="form-control" id="marketingResultadoNota" name="nota" rows="3" maxlength="500"
                    placeholder="Ej. buzón de voz; volver a llamar por la tarde." data-marketing-result-note></textarea>
                </div>
            </div>
            <div class="modal-footer system-form-modal-footer">
                <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal" data-marketing-result-cancel>
                    <i class="bi bi-x-lg" aria-hidden="true"></i> Cancelar</button>
                <button type="submit" class="btn btn-system-save" data-marketing-result-save>
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Guardar resultado</button>
            </div>
        </form>
        </div>
      </div>
    </div>
    </div>
</section>
<?php endif; ?>
