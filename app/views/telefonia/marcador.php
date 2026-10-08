<?php
$esc = static function ($valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};
$panelTelefono = is_array($panelTelefono ?? null) ? $panelTelefono : [];
$extension = (string)($panelTelefono['extension'] ?? '');
$callerId = (string)($panelTelefono['caller_id'] ?? '');
$historial = is_array($panelTelefono['historial'] ?? null) ? $panelTelefono['historial'] : [];
$contactos = is_array($panelTelefono['contactos'] ?? null) ? $panelTelefono['contactos'] : [];
?>
<div class="telephony-dialer-page"
    data-telephony-dialer
    data-can-call="<?= $extension !== '' ? '1' : '0' ?>"
    data-contact-add-url="<?= $esc(BASE_URL . 'index.php?controller=telefonia&action=guardarContacto') ?>"
    data-contact-delete-url="<?= $esc(BASE_URL . 'index.php?controller=telefonia&action=eliminarContacto') ?>"
    data-csrf-token="<?= $esc($panelTelefono['csrf'] ?? '') ?>">

    <section class="dashboard-panel telephony-dialer-card">
        <div class="telephony-dialer-heading">
            <div>
                <span class="telephony-eyebrow">TELÉFONO · EXTENSIÓN PERSONAL</span>
                <h2>Marcador telefónico</h2>
                <p>Marca directamente. No necesitas crear instituciones ni registrar una etapa comercial.</p>
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
                <small>Para México escribe 10 dígitos. Para otros países incluye el prefijo internacional.</small>

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

            <aside class="telephony-dial-info">
                <h3>Resumen de mi actividad</h3>
                <div class="telephony-dial-kpis">
                    <div>
                        <strong><?= (int)($historial['atenciones'] ?? 0) ?></strong>
                        <span>Atenciones (30 días)</span>
                    </div>
                    <div>
                        <strong><?= (int)($historial['contestadas'] ?? 0) ?></strong>
                        <span>Contestadas</span>
                    </div>
                    <div>
                        <strong><?= number_format((int)($historial['segundos'] ?? 0) / 60, 1) ?></strong>
                        <span>Minutos observados</span>
                    </div>
                </div>
                <div class="telephony-dial-tip">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <p>Las llamadas del historial provienen de las notificaciones de Zadarma. La duración observada no representa el saldo ni la facturación del plan.</p>
                </div>
                <p>Si tu extensión tiene permiso para recibir transferencias, activa los auriculares de la barra superior al empezar tu jornada.</p>
            </aside>
        </div>
    </section>

    <section class="dashboard-panel telephony-contacts-card">
        <div class="telephony-dialer-heading">
            <div>
                <span class="telephony-eyebrow">MI AGENDA PERSONAL</span>
                <h2>Teléfonos guardados</h2>
                <p>Guarda números frecuentes para volver a marcarlos. Solo tú puedes consultarlos.</p>
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
                    data-telephony-contact-from-dialer>Usar número del marcador</button>
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
            Todavía no tienes números guardados. Agrega uno para comenzar.
        </p>
    </section>

    <section class="dashboard-panel telephony-dial-history">
        <div class="telephony-dialer-heading">
            <div>
                <span class="telephony-eyebrow">MI HISTORIAL</span>
                <h2>Llamadas recientes</h2>
                <p>Últimos 30 días · hasta 30 atenciones registradas de tu extensión</p>
            </div>
            <a class="btn btn-system-light" href="<?= $esc(BASE_URL . 'index.php?controller=telefonia&action=marcador') ?>">
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
                                Aún no hay llamadas registradas para tu extensión.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
