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
                    <div data-sales-result-form>
                        <label for="ventas-resultado-fin">Resultado de la llamada</label>
                        <select id="ventas-resultado-fin" class="form-select" data-sales-result-select>
                            <option value="">Selecciona el resultado</option>
                            <?php foreach (TelefoniaResultadoVentasService::opciones() as $codigo => $etiqueta): ?>
                                <option value="<?= $esc($codigo) ?>"><?= $esc($etiqueta) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-system-save" data-sales-result-submit>
                            Guardar resultado
                        </button>
                    </div>
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
            <div class="telephony-contact-form-actions">
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
                    data-contact-number="<?= $esc($contacto['telefono']) ?>">
                    <div class="telephony-contact-name">
                        <strong data-contact-name><?= $esc($contacto['nombre']) ?></strong>
                        <small data-contact-display-number><?= $esc($contacto['telefono']) ?></small>
                    </div>
                    <div class="telephony-contact-actions">
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
        <div class="telephony-dialer-heading">
            <div>
                <span class="telephony-eyebrow">ACTIVIDAD RECIENTE</span>
                <h2>Llamadas recientes</h2>
                <p>Hasta 30 llamadas de tu extensión registradas en los últimos 30 días.</p>
            </div>
            <a class="btn btn-system-light" href="<?= $esc($urlActualizarMarcador) ?>">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                Actualizar
            </a>
        </div>
        <div class="table-responsive">
            <table class="table align-middle telephony-history-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>Número</th>
                        <th>Estado</th>
                        <th>Duración</th>
                        <th>Grabación</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($historial['recientes'] ?? []) as $indiceLlamada => $llamada): ?>
                        <?php
                        $duracion = max(0, (int)($llamada['segundos'] ?? 0));
                        $pbxId = (string)($llamada['pbx_call_id'] ?? '');
                        $hayGrabacion = !empty($llamada['tiene_grabacion']) &&
                            (bool)preg_match('/^out_[a-fA-F0-9]{32,64}$/', $pbxId);
                        $grabacionEnProceso = !$hayGrabacion &&
                            !empty($llamada['grabacion_procesando']);
                        $identificadorFila = 'venta-grabacion-' . (int)$indiceLlamada;
                        $urlGrabacion = BASE_URL .
                            'prueba_telefonia/api/grabacion_ventas.php?pbx_call_id=' .
                            rawurlencode($pbxId);
                        ?>
                        <tr>
                            <td><?= $esc($llamada['fecha'] ?? '') ?></td>
                            <td><?= $esc($llamada['tipo'] ?? '') ?></td>
                            <td><?= $esc(($llamada['numero'] ?? '') ?: 'No disponible') ?></td>
                            <td class="telephony-sales-result-cell">
                                <?php $resultadoActual = (string)($llamada['resultado_ventas'] ?? ''); ?>
                                <?php if (($llamada['tipo'] ?? '') === 'Saliente' &&
                                    preg_match('/^out_[a-fA-F0-9]{32,64}$/', $pbxId)): ?>
                                    <span class="telephony-sales-result-badge <?= $resultadoActual !== '' ? 'is-classified' : '' ?>"
                                        data-sales-result-badge>
                                        <?= $esc($llamada['resultado_etiqueta'] ?? 'Por clasificar') ?>
                                    </span>
                                    <form class="telephony-sales-history-form" data-sales-history-result-form
                                        data-pbx-call-id="<?= $esc($pbxId) ?>">
                                        <select class="form-select" aria-label="Resultado de la llamada"
                                            data-sales-history-result-select required>
                                            <option value="">Clasificar…</option>
                                            <?php foreach (TelefoniaResultadoVentasService::opciones() as $codigo => $etiqueta): ?>
                                                <option value="<?= $esc($codigo) ?>"
                                                    <?= $resultadoActual === $codigo ? 'selected' : '' ?>>
                                                    <?= $esc($etiqueta) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-system-light"
                                            data-sales-history-result-submit>Guardar</button>
                                    </form>
                                    <span class="telephony-sales-history-feedback"
                                        data-sales-history-feedback role="status" aria-live="polite"></span>
                                <?php else: ?>
                                    <?= !empty($llamada['contestada']) ? 'Conectada' : 'Sin respuesta' ?>
                                <?php endif; ?>
                            </td>
                            <td><?= sprintf('%02d:%02d', intdiv($duracion, 60), $duracion % 60) ?></td>
                            <td class="telephony-recording-cell">
                                <?php if ($hayGrabacion): ?>
                                    <button type="button"
                                        class="linkage-call-audio-state is-available linkage-call-recording-toggle"
                                        data-sales-recording-toggle
                                        data-recording-url="<?= $esc($urlGrabacion) ?>"
                                        data-recording-seconds="<?= $duracion ?>"
                                        aria-controls="<?= $identificadorFila ?>"
                                        aria-expanded="false">
                                        <i class="bi bi-record-circle" aria-hidden="true"></i>
                                        <span>Grabación</span>
                                        <i class="bi bi-chevron-down linkage-call-recording-chevron" aria-hidden="true"></i>
                                    </button>
                                <?php elseif (!empty($llamada['grabacion_excluida'])): ?>
                                    <span class="telephony-recording-empty"><i class="bi bi-slash-circle" aria-hidden="true"></i> No aplica</span>
                                <?php elseif (!empty($llamada['resultado_pendiente'])): ?>
                                    <span class="telephony-recording-empty">Pendiente de clasificar</span>
                                <?php elseif ($grabacionEnProceso): ?>
                                    <span class="linkage-call-audio-state is-processing">
                                        <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                                        Procesando
                                    </span>
                                <?php else: ?>
                                    <span class="telephony-recording-empty">Sin grabación</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($hayGrabacion): ?>
                            <tr id="<?= $identificadorFila ?>" class="telephony-history-recording-row"
                                data-sales-recording-row hidden>
                                <td colspan="6">
                                    <div class="telephony-history-recording-slot"
                                         data-sales-recording-slot></div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (empty($historial['recientes'])): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                Todavía no hay llamadas registradas en tu extensión.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
