<?php
// La recepción es una función del perfil Marketing; no es el Marcador de Ventas.
require_once __DIR__ . '/../../helpers/TelefoniaMarketingAccessHelper.php';
$recepcionMarketingPermitida = marketingTieneRecepcionAsignada();
?>
<?php if ($recepcionMarketingPermitida): ?>
<section class="dashboard-panel marketing-reception-panel"
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
                <p>Consulta tus llamadas entrantes y salientes desde esta extensión, sin mezclar expedientes ni prospectos.</p>
            </div>
        </div>
        <div class="marketing-reception-actions">
            <label class="marketing-reception-auto-preference"
                data-marketing-reception-auto-wrap
                title="Conectar la extensión automáticamente al iniciar sesión">
                <input type="checkbox" data-marketing-reception-auto checked>
                <span>Conectar al iniciar sesión</span>
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
    <p class="marketing-reception-browser-note" data-marketing-reception-browser-note hidden>
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <span>La conexión WebRTC permanece en una ventana pequeña. Puedes minimizarla,
            pero no cerrarla mientras recibas llamadas.</span>
    </p>
    <div class="marketing-reception-explainer" data-marketing-reception-hint>
        Estamos verificando la extensión telefónica asignada a tu usuario.
    </div>
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
                <input type="search" class="form-control" placeholder="Buscar número…"
                    aria-label="Buscar llamadas por número o extensión de transferencia"
                    data-marketing-reception-search autocomplete="off">
            </label>
            <label class="marketing-reception-filter-field">
            <span class="marketing-reception-filter-label">Tipo de llamada</span>
            <select class="form-select marketing-reception-status-filter"
                data-marketing-reception-type aria-label="Filtrar por tipo de llamada">
                <option value="">Entrantes y salientes</option>
                <option value="Entrante">Entrantes</option>
                <option value="Saliente">Salientes</option>
            </select>
            </label>
            <label class="marketing-reception-filter-field">
            <span class="marketing-reception-filter-label">Estado o resultado</span>
            <select class="form-select marketing-reception-status-filter"
                data-marketing-reception-filter aria-label="Filtrar llamadas por estado">
                <option value="">Todos los estados</option>
                <option value="Atendida">Atendidas</option>
                <option value="Perdida">Perdidas</option>
                <option value="Transferida">Transferidas</option>
                <option value="En curso">En curso</option>
                <option value="Sonando">Sonando</option>
                <option value="Completada">Respondidas</option>
                <option value="Sin respuesta">Sin respuesta</option>
                <option value="Ocupada">Ocupadas</option>
                <option value="Cancelada">Canceladas</option>
                <option value="Fallida">Fallidas</option>
                <option value="Marcando">Marcando</option>
                <option value="Número inexistente">Número inexistente</option>
                <option value="No disponible">No disponible</option>
                <option value="Sin saldo">Sin saldo</option>
                <option value="Límite excedido">Límite excedido</option>
                <optgroup label="Resultado registrado por recepción">
                    <option value="resultado:CONTACTO">Persona atendió</option>
                    <option value="resultado:BUZON">Buzón de voz</option>
                    <option value="resultado:NO_DISPONIBLE">No disponible · registrado</option>
                    <option value="resultado:NUMERO_INCORRECTO">Número incorrecto</option>
                    <option value="resultado:OTRO">Otro resultado</option>
                </optgroup>
            </select>
            </label>
            <button type="button" class="btn btn-system-light marketing-reception-refresh"
                data-marketing-reception-refresh title="Actualizar historial"
                aria-label="Actualizar historial de recepción">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                Actualizar
            </button>
        </div>

    <p class="marketing-reception-history-note">El estado lo confirma la centralita. Registra el resultado para distinguir una persona de un buzón o una locución.</p>

    <p class="marketing-reception-callback-status" data-marketing-reception-callback-status
        role="status" aria-live="polite" hidden></p>
    <div class="table-responsive marketing-reception-table-scroll">
        <table class="table align-middle marketing-reception-table telephony-history-table">
            <thead>
                <tr>
                    <th>Fecha y hora</th>
                    <th>Tipo</th>
                    <th>Número</th>
                    <th>Llamar</th>
                    <th>Estado</th>
                    <th>Duración</th>
                    <th>Transferida a</th>
                    <th>Grabación</th>
                </tr>
            </thead>
            <tbody data-marketing-reception-rows>
                <tr><td colspan="8" class="marketing-reception-placeholder">
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
    <dialog class="marketing-reception-result-dialog" data-marketing-result-dialog
        aria-labelledby="marketingResultadoTitulo">
        <form data-marketing-result-form>
            <header><h3 id="marketingResultadoTitulo">Resultado de la llamada</h3>
                <p data-marketing-result-phone></p></header>
            <div class="marketing-reception-result-body">
                <p>Registra lo que escuchaste. El estado confirmado por la centralita se conserva.</p>
                <label for="marketingResultado">Resultado observado</label>
                <select id="marketingResultado" name="resultado" required data-marketing-result-select>
                    <option value="">Selecciona un resultado</option>
                    <option value="CONTACTO">Persona atendió</option>
                    <option value="BUZON">Buzón de voz</option>
                    <option value="NO_DISPONIBLE">No disponible</option>
                    <option value="NUMERO_INCORRECTO">Número incorrecto</option>
                    <option value="OTRO">Otro resultado</option>
                    <option value="SIN_CLASIFICAR">Sin clasificar</option>
                </select>
                <label for="marketingResultadoNota">Nota <span>(opcional)</span></label>
                <textarea id="marketingResultadoNota" name="nota" rows="3" maxlength="500"
                    placeholder="Ej. buzón de voz; volver a llamar por la tarde." data-marketing-result-note></textarea>
                <p class="marketing-reception-result-error" data-marketing-result-error role="alert" hidden></p>
            </div>
            <footer><button type="button" class="btn btn-system-light" data-marketing-result-cancel>Cancelar</button>
                <button type="submit" class="btn btn-system-primary" data-marketing-result-save>Guardar resultado</button></footer>
        </form>
    </dialog>
    </div>
</section>
<?php endif; ?>
