<?php
$puedeUsarMarcadorVentas = $puedeUsarMarcadorVentas ?? false;
$panelTelefonoVentas = is_array($panelTelefono ?? null) ? $panelTelefono : [];
$extensionVentas = trim((string)($panelTelefonoVentas['extension'] ?? ''));
?>
<?php if ($puedeUsarMarcadorVentas): ?>
    <div class="telephony-sales-dashboard">
        <section class="telephony-sales-intro">
            <div class="telephony-sales-intro-copy">
                <span class="telephony-eyebrow">TU JORNADA</span>
                <h2>Mi teléfono</h2>
                <p>Tu espacio para llamar y organizar teléfonos de personas prospecto.</p>
            </div>
            <div class="telephony-sales-intro-status">
                <span class="telephony-sales-status-icon" aria-hidden="true">
                    <i class="bi bi-headset"></i>
                </span>
                <div>
                    <strong data-telephony-sales-extension-status><?= $extensionVentas !== ''
                        ? 'Extensión ' . htmlspecialchars($extensionVentas, ENT_QUOTES, 'UTF-8')
                        : 'Extensión pendiente' ?></strong>
                    <span data-telephony-sales-extension-caption><?= $extensionVentas !== ''
                        ? 'Teléfono asignado a tu usuario'
                        : 'Solicita la configuración al administrador' ?></span>
                </div>
            </div>
        </section>
        <?php require __DIR__ . '/../telefonia/marcador.php'; ?>
    </div>
<?php else: ?>
    <section class="dashboard-panel telephony-sales-permission">
        <span class="telephony-eyebrow">TELÉFONO · VENTAS</span>
        <h2 class="panel-title">Tu espacio telefónico</h2>
        <p>El administrador debe habilitar los permisos <strong>Usar telefonía</strong> y
            <strong>Realizar llamadas salientes</strong> para que aparezca tu marcador.</p>
    </section>
<?php endif; ?>
