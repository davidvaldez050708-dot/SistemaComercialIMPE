<?php
$esc = static function ($valor) { return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8'); };
?>
<div class="telephony-dialer-page" data-telephony-dialer>
    <section class="dashboard-panel telephony-dialer-card">
        <div class="telephony-dialer-heading">
            <div>
                <span class="telephony-eyebrow">ZADARMA · TELÉFONO</span>
                <h2>Marcador telefónico</h2>
                <p>Marca números directamente sin registrar instituciones ni etapas comerciales.</p>
            </div>
            <span class="telephony-extension-pill">Extensión <?= $esc($extensionAsignada ?: 'Sin asignar') ?></span>
        </div>
        <?php if ($mensajeMarcador !== ''): ?><div class="alert alert-warning"><?= $esc($mensajeMarcador) ?></div><?php endif; ?>
        <div class="telephony-dialer-body">
            <form class="telephony-keypad" data-telephony-dialer-form autocomplete="off">
                <label for="numero-dialer">Número a marcar</label>
                <input id="numero-dialer" class="form-control" type="tel" inputmode="tel" maxlength="22"
                    placeholder="Ej. 477 123 4567" data-telephony-dial-number>
                <small>México: escribe 10 dígitos. Se añade 52 automáticamente. Otros países: escribe el prefijo internacional.</small>
                <div class="telephony-key-grid" role="group" aria-label="Teclado telefónico">
                    <?php foreach (['1','2','3','4','5','6','7','8','9','+','0','⌫'] as $tecla): ?>
                        <button type="button" data-dial-key="<?= $esc($tecla) ?>"
                            aria-label="<?= $tecla === '⌫' ? 'Borrar dígito' : $esc($tecla) ?>"><?= $esc($tecla) ?></button>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-system-save telephony-dial-submit"
                    <?= $extensionAsignada === '' ? 'disabled' : '' ?> data-telephony-dial-call>
                    <i class="bi bi-telephone-fill"></i> Llamar
                </button>
                <div role="status" aria-live="polite" class="telephony-dial-status" data-telephony-dial-status>
                    <?= $extensionAsignada === '' ? 'Esperando asignación de extensión.' : 'Comprobando teléfono…' ?>
                </div>
                <button type="button" class="btn btn-system-light" data-telephony-dial-hangup disabled>Colgar llamada</button>
            </form>
            <aside class="telephony-dial-info">
                <h3>Actividad de mi extensión</h3>
                <div class="telephony-dial-kpis">
                    <div><strong><?= (int)$actividadTelefonica['atenciones'] ?></strong><span>Atenciones (30 días)</span></div>
                    <div><strong><?= (int)$actividadTelefonica['contestadas'] ?></strong><span>Contestadas</span></div>
                    <div><strong><?= number_format($actividadTelefonica['segundos']/60, 1) ?></strong><span>Minutos observados</span></div>
                </div>
                <p>El historial se obtiene de los eventos firmados por Zadarma. No representa facturación ni un proceso comercial.</p>
                <p>Si tu extensión recibe transferencias, activa el botón de auriculares en la barra superior.</p>
            </aside>
        </div>
    </section>
    <section class="dashboard-panel telephony-dial-history">
        <div class="telephony-dialer-heading">
            <div><h2>Historial de mi extensión</h2><p>Últimos 30 días, 30 atenciones más recientes</p></div>
            <a href="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=marcador" class="btn btn-system-light">Actualizar</a>
        </div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Fecha</th><th>Tipo</th><th>Número</th><th>Estado</th><th>Duración</th></tr></thead>
                <tbody>
                    <?php foreach ($actividadTelefonica['recientes'] as $llamada): ?>
                        <tr><td><?= $esc($llamada['fecha']) ?></td><td><?= $esc($llamada['tipo']) ?></td>
                            <td><?= $esc($llamada['numero'] ?: 'No disponible') ?></td>
                            <td><?= $llamada['contestada'] ? 'Contestada' : 'Sin respuesta confirmada' ?></td>
                            <td><?= sprintf('%02d:%02d', intdiv((int)$llamada['segundos'],60), (int)$llamada['segundos']%60) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($actividadTelefonica['recientes'])): ?>
                        <tr><td colspan="5" class="text-muted text-center py-4">Aún no hay llamadas registradas.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>