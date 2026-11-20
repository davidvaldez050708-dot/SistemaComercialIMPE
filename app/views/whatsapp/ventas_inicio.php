<?php if(!empty($plantillaVentas['plantilla_nombre'])): ?>
    <details class="wa-template-preview">
        <summary>Ver presentación institucional</summary>
        <p><?= nl2br($texto($plantillaVentas['plantilla_cuerpo'])) ?></p>
        <small><?= $texto($plantillaVentas['plantilla_categoria'].' · '.$plantillaVentas['plantilla_idioma']) ?> · Se enviará al prospecto de esta conversación.</small>
    </details>
    <button type="button" class="btn whatsapp-template-button" data-wa-ventas-inicio data-id="<?= (int)$conversacionSeleccionada['id'] ?>" data-url="<?= BASE_URL ?>index.php?controller=whatsapp&amp;action=iniciarVentas" data-csrf="<?= $texto($_SESSION['csrf_token']??'') ?>">Enviar presentación institucional</button>
    <span role="status" aria-live="polite" data-wa-ventas-status></span>
<?php else: ?>
    <small class="wa-template-pending">Presentación pendiente de configurar por el Administrador en Inicio comercial.</small>
<?php endif; ?>
<script src="<?= BASE_URL ?>public/javascript/whatsapp_ventas.js?v=<?= (int)filemtime(__DIR__.'/../../../public/javascript/whatsapp_ventas.js') ?>"></script>
