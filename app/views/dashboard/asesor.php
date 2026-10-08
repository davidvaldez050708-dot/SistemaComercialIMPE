<?php
$puedeUsarMarcadorVentas = $puedeUsarMarcadorVentas ?? false;
?>
<?php if ($puedeUsarMarcadorVentas): ?>
    <div class="telephony-sales-intro">
        <div>
            <span class="telephony-eyebrow">MI ESPACIO DE TRABAJO</span>
            <h2>Mi teléfono</h2>
            <p>Marca, guarda números frecuentes y consulta tus llamadas. No se requiere registrar oportunidades ni seguimientos.</p>
        </div>
    </div>
    <?php require __DIR__ . '/../telefonia/marcador.php'; ?>
<?php else: ?>
    <section class="dashboard-panel telephony-dialer-card">
        <h2 class="panel-title">Teléfono de Ventas</h2>
        <p>Tu perfil todavía no tiene habilitados los permisos de telefonía. Solicita al administrador <strong>Usar telefonía</strong> y <strong>Realizar llamadas salientes</strong> desde Roles y permisos.</p>
    </section>
<?php endif; ?>
