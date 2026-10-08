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
                <span>Contestadas</span>
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
                    <?= $esc($extension !== '' ? 'Extensión ' . $extension : 'Sin extensión asignada') ?>
                </span>
                <?php if ($callerId !== ''): ?>
                    <small>Identificador configurado: <?= $esc($callerId) ?></small>
                <?php endif; ?>
            </div>
        </div>

        <?php if (($panelTelefono['mensaje'] ?? '') !== ''): ?>
            <div class="alert alert-warning" role="alert">
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
                        data-telephony-dial-hangup disabled>Colgar llamada</button>
                    <button type="button" class="btn btn-system-light"
                        data-telephony-dial-new hidden>Nueva llamada</button>
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
                <h2>Teléfonos guardados</h2>
                <p>Tus números frecuentes, privados y listos para marcar.</p>
            </div>
        </div>
        <?php if (($panelTelefono['mensaje_contactos'] ?? '') !== ''): ?>
            <div class="alert alert-warning" role="alert"><?= $esc($panelTelefono['mensaje_contactos']) ?></div>
        <?php endif; ?>
        <form class="telephony-contact-form" data-telephony-contact-form autocomplete="off">
            <div>
                <label for="telefono-contacto-nombre">Nombre o referencia</label>
                <input id="telefono-contacto-nombre" type="text" class="form-control"
                    maxlength="90" placeholder="Ej. Oficina Guanajuato" required
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
        <div class="telephony-contacts-list" data-telephony-contacts-list>
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
            No hay teléfonos guardados. Agrega el primero arriba.
        </p>
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
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($historial['recientes'] ?? []) as $llamada): ?>
                        <?php $duracion = max(0, (int)($llamada['segundos'] ?? 0)); ?>
                        <tr>
                            <td><?= $esc($llamada['fecha'] ?? '') ?></td>
                            <td><?= $esc($llamada['tipo'] ?? '') ?></td>
                            <td><?= $esc(($llamada['numero'] ?? '') ?: 'No disponible') ?></td>
                            <td><?= !empty($llamada['contestada'])
                                ? 'Contestada' : 'Sin respuesta confirmada' ?></td>
                            <td><?= sprintf('%02d:%02d', intdiv($duracion, 60), $duracion % 60) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($historial['recientes'])): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                Todavía no hay llamadas registradas en tu extensión.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
