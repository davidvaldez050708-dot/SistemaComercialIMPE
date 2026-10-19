<?php
$esc = static function ($valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};
$panelTelefono = is_array($panelTelefono ?? null) ? $panelTelefono : [];
$extension = (string)($panelTelefono['extension'] ?? '');
$callerId = (string)($panelTelefono['caller_id'] ?? '');
$historial = is_array($panelTelefono['historial'] ?? null) ? $panelTelefono['historial'] : [];
$contactos = is_array($panelTelefono['contactos'] ?? null) ? $panelTelefono['contactos'] : [];
$urlActualizarMarcador = BASE_URL . (
    strtolower((string)($_GET['controller'] ?? '')) === 'home'
        ? 'index.php?controller=home&action=index'
        : 'index.php?controller=telefonia&action=marcador'
);
?>
<div class="telephony-dialer-page"
    data-telephony-dialer
    data-can-call="<?= $extension !== '' ? '1' : '0' ?>"
    data-contact-add-url="<?= $esc(BASE_URL . 'index.php?controller=telefonia&action=guardarContacto') ?>"
    data-contact-delete-url="<?= $esc(BASE_URL . 'index.php?controller=telefonia&action=eliminarContacto') ?>"
    data-result-save-url="<?= $esc(BASE_URL . 'index.php?controller=telefonia&action=guardarResultadoVenta') ?>"
    data-call-start-url="<?= $esc(BASE_URL . 'index.php?controller=telefonia&action=iniciarMarcacionVenta') ?>"
    data-call-bind-url="<?= $esc(BASE_URL . 'index.php?controller=telefonia&action=vincularMarcacionVenta') ?>"
    data-csrf-token="<?= $esc($panelTelefono['csrf'] ?? '') ?>">

    <section class="telephony-summary-strip" aria-label="Resumen telefónico de los últimos 30 días">
        <article class="telephony-summary-item">
            <span class="telephony-summary-icon" aria-hidden="true"><i class="bi bi-telephone"></i></span>
            <div>
                <strong><?= (int)($historial['atenciones'] ?? 0) ?></strong>
                <span>Atenciones · 30 días</span>
            </div>
        </article>
        <article class="telephony-summary-item">
            <span class="telephony-summary-icon" aria-hidden="true"><i class="bi bi-telephone-inbound"></i></span>
            <div>
                <strong><?= (int)($historial['contestadas'] ?? 0) ?></strong>
                <span>Conectadas (incluye buzón)</span>
            </div>
        </article>
        <article class="telephony-summary-item">
            <span class="telephony-summary-icon" aria-hidden="true"><i class="bi bi-person-check"></i></span>
            <div>
                <strong><?= (int)($historial['conversaciones_reales'] ?? 0) ?></strong>
                <span>Conversaciones reales</span>
            </div>
        </article>
        <article class="telephony-summary-item">
            <span class="telephony-summary-icon" aria-hidden="true"><i class="bi bi-clock-history"></i></span>
            <div>
                <strong><?= number_format((int)($historial['segundos'] ?? 0) / 60, 1) ?></strong>
                <span>Minutos observados</span>
            </div>
        </article>
    </section>

    <div class="telephony-workspace-grid">
    <section class="dashboard-panel telephony-dialer-card">
        <div class="telephony-dialer-heading">
            <div>
                <span class="telephony-eyebrow">MARCACIÓN</span>
                <h2>Marcar número</h2>
                <p>Escribe o selecciona un teléfono para llamar.</p>
            </div>
            <div class="telephony-identity">
                <span class="telephony-extension-pill">
                    <i class="bi bi-headset" aria-hidden="true"></i>
                    <span data-telephony-extension-identity><?= $esc($extension !== '' ? 'Extensión ' . $extension : 'Sin extensión asignada') ?></span>
                </span>
                <?php if ($callerId !== ''): ?>
                    <small>Identificador configurado: <?= $esc($callerId) ?></small>
                <?php endif; ?>
            </div>
        </div>

        <?php if (($panelTelefono['mensaje'] ?? '') !== ''): ?>
            <div class="alert alert-warning" role="alert"
                <?= ($extension === '' && ($panelTelefono['mensaje'] ?? '') ===
                    'El administrador debe asignarte una extensión activa con llamadas salientes.')
                    ? 'data-telephony-assignment-warning' : '' ?>>
                <?= $esc($panelTelefono['mensaje']) ?>
            </div>
        <?php endif; ?>

        <div class="telephony-dialer-body">
            <form class="telephony-keypad" data-telephony-dialer-form autocomplete="off">
                <label for="numero-dialer">Número a marcar</label>
                <input id="numero-dialer" class="form-control" type="tel"
                    inputmode="tel" maxlength="22" placeholder="Ej. 477 123 4567"
                    data-telephony-dial-number>
                <small>México: 10 dígitos. Otros países: agrega el prefijo.</small>

                <div class="telephony-key-grid" role="group" aria-label="Teclado telefónico">
                    <?php foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '+', '0', '⌫'] as $tecla): ?>
                        <button type="button" data-dial-key="<?= $esc($tecla) ?>"
                            aria-label="<?= $tecla === '⌫' ? 'Borrar dígito' : $esc($tecla) ?>">
                            <?= $esc($tecla) ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <button type="submit" class="btn btn-system-save telephony-dial-submit"
                    data-telephony-dial-call disabled>
                    <i class="bi bi-telephone-fill" aria-hidden="true"></i>
                    Llamar
                </button>
                <div class="telephony-dial-status" role="status"
                    aria-live="polite" data-telephony-dial-status>
                    <?= $extension !== ''
                        ? 'Comprobando teléfono…'
                        : 'Esperando la asignación de una extensión.' ?>
                </div>
                <div class="telephony-dial-secondary">
                    <button type="button" class="btn btn-system-light"
                        data-telephony-dial-mute aria-pressed="false" disabled>
                        <i class="bi bi-mic-mute" data-telephony-dial-mute-icon aria-hidden="true"></i>
                        <span data-telephony-dial-mute-label>Silenciar</span>
                    </button>
                    <button type="button" class="btn btn-system-light"
                        data-telephony-dial-hangup disabled>Colgar llamada</button>
                    <button type="button" class="btn btn-system-light telephony-dial-new"
                        data-telephony-dial-new hidden>Nueva llamada</button>
                </div>
                <div class="telephony-sales-result" data-sales-result-block hidden>
                    <div class="telephony-sales-result-heading">
                        <strong><i class="bi bi-journal-check" aria-hidden="true"></i> Registrar resultado</strong>
                        <small>Indica qué ocurrió; buzones y mensajes automáticos no habilitan grabaciones.</small>
                    </div>
                    <button type="button" class="btn btn-system-save" data-sales-open-hub>Registrar en Mis llamadas</button>
                    <p class="telephony-sales-result-feedback" role="status" aria-live="polite"
                        data-sales-result-feedback></p>
                </div>
                <div class="telephony-aftercall" data-telephony-aftercall hidden>
                    <span>¿Necesitas llamar de nuevo a este número?</span>
                    <button type="button" class="btn btn-system-light"
                        data-telephony-aftercall-save>
                        <i class="bi bi-bookmark-plus" aria-hidden="true"></i>
                        Guardar en mi agenda
                    </button>
                </div>
            </form>

            <p class="telephony-dial-footnote">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                Los minutos se calculan con eventos de Zadarma; no equivalen al saldo del plan.
            </p>

        </div>
    </section>

    <section class="dashboard-panel telephony-contacts-card">
        <div class="telephony-dialer-heading">
            <div>
                <span class="telephony-eyebrow">AGENDA PERSONAL</span>
                <h2>Contactos telefónicos</h2>
                <p>Guarda los nombres y teléfonos de las personas prospecto con las que hablas.</p>
            </div>
        </div>
        <?php if (($panelTelefono['mensaje_contactos'] ?? '') !== ''): ?>
            <div class="alert alert-warning" role="alert"><?= $esc($panelTelefono['mensaje_contactos']) ?></div>
        <?php endif; ?>
        <div class="telephony-contacts-search">
            <label for="telefono-buscar-prospecto">Buscar prospecto</label>
            <div class="telephony-contacts-search-field">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input id="telefono-buscar-prospecto" type="search" class="form-control"
                    placeholder="Escribe un nombre o teléfono…"
                    autocomplete="off" spellcheck="false"
                    aria-controls="telefonia-lista-contactos"
                    data-telephony-contact-search>
                <button type="button" class="telephony-contacts-search-clear"
                    data-telephony-contact-search-clear
                    aria-label="Limpiar búsqueda de prospectos" hidden>
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <form class="telephony-contact-form" data-telephony-contact-form autocomplete="off">
            <input type="hidden" data-telephony-contact-id value="0">
            <div>
                <label for="telefono-contacto-nombre">Nombre del prospecto</label>
                <input id="telefono-contacto-nombre" type="text" class="form-control"
                    maxlength="90" placeholder="Ej. Mariana López" required
                    data-telephony-contact-name>
            </div>
            <div>
                <label for="telefono-contacto-numero">Teléfono</label>
                <input id="telefono-contacto-numero" type="tel" inputmode="tel"
                    maxlength="22" class="form-control" placeholder="Ej. 477 123 4567"
                    required data-telephony-contact-number>
            </div>
            <div><label for="agendaCorreo">Correo (opcional)</label><input class="form-control" type="email" id="agendaCorreo" maxlength="180" data-telephony-contact-email></div>
            <div><label for="agendaProxima">Próximo contacto (opcional)</label><input class="form-control" type="datetime-local" id="agendaProxima" data-telephony-contact-next></div>
            <div><label for="agendaNota">Observaciones</label><textarea class="form-control" id="agendaNota" rows="2" maxlength="500" data-telephony-contact-note></textarea></div>
            <div class="telephony-contact-form-actions"><button type="button" class="btn btn-system-light" data-telephony-contact-cancel hidden>Cancelar edición</button>
                <button type="button" class="btn btn-system-light"
                    data-telephony-contact-from-dialer>Tomar del marcador</button>
                <button type="submit" class="btn btn-system-save"
                    data-telephony-contact-save>
                    <i class="bi bi-bookmark-plus" aria-hidden="true"></i>
                    Guardar número
                </button>
            </div>
        </form>
        <p class="telephony-contacts-feedback" role="status" aria-live="polite"
            data-telephony-contact-status></p>
        <div id="telefonia-lista-contactos" class="telephony-contacts-list"
            data-telephony-contacts-list>
            <?php foreach ($contactos as $contacto): ?>
                <article class="telephony-contact-row" data-telephony-contact-row
                    data-contact-id="<?= (int)$contacto['id'] ?>"
                    data-contact-number="<?= $esc($contacto['telefono']) ?>" data-contact-email="<?= $esc($contacto['correo']??'') ?>" data-contact-note="<?= $esc($contacto['observacion']??'') ?>" data-contact-next="<?= $esc($contacto['proxima_accion_at']??'') ?>">
                    <div class="telephony-contact-name">
                        <strong data-contact-name><?= $esc($contacto['nombre']) ?></strong>
                        <small data-contact-display-number><?= $esc($contacto['telefono']) ?></small><small data-contact-extra><?= $esc(implode(' · ',array_filter([$contacto['correo']??'', $contacto['proxima_accion_at']??'', $contacto['observacion']??'']))) ?></small>
                    </div>
                    <div class="telephony-contact-actions"><button type="button" class="btn btn-system-light" data-telephony-contact-edit aria-label="Editar contacto"><i class="bi bi-pencil"></i></button>
                        <button type="button" class="btn btn-system-light"
                            data-telephony-contact-use>Usar número</button>
                        <button type="button" class="telephony-contact-remove"
                            data-telephony-contact-remove aria-label="Eliminar teléfono guardado">
                            <i class="bi bi-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="telephony-contacts-empty" data-telephony-contacts-empty
            <?= !empty($contactos) ? 'hidden' : '' ?>>
            Aún no has guardado prospectos en tu agenda. Agrega el primero arriba.
        </p>
        <p class="telephony-contacts-empty" data-telephony-contacts-no-results hidden>
            No se encontraron prospectos con ese nombre o teléfono.
        </p>
        <div class="telephony-contacts-footer">
            <span class="telephony-contacts-count" data-telephony-contacts-count
                role="status" aria-live="polite" aria-atomic="true"></span>
            <nav class="telephony-contacts-pagination" data-telephony-contact-pagination
                aria-label="Páginas de contactos guardados" hidden>
                <button type="button" class="telephony-contacts-page-btn"
                    data-telephony-contact-prev aria-label="Página anterior">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                </button>
                <div class="telephony-contacts-page-numbers" data-telephony-contact-pages></div>
                <button type="button" class="telephony-contacts-page-btn"
                    data-telephony-contact-next aria-label="Página siguiente">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </button>
            </nav>
        </div>
    </section>
    </div>

    <section class="dashboard-panel telephony-dial-history">
      <div class="telephony-dialer-heading"><div><span class="telephony-eyebrow">ACTIVIDAD</span><h2>Resumen de llamadas</h2><p>Consulta resultados, grabaciones y pendientes en tu bandeja personal.</p></div>
       <a class="btn btn-system-light" href="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=misLlamadas"><i class="bi bi-clock-history"></i> Abrir Mis llamadas</a></div>
    </section>
</div>
