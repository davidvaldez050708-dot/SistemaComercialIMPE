<?php /* Contenedor único para la captura de Analista y Ventas. */ ?>
<div class="impe-routing-panel impe-call-capture" id="<?= $esAsesorShell?'impeSalesCapturePanel':'impeCapturePanel' ?>"
 <?= $esAsesorShell?'data-sales-prospect-dialog':'data-shell-capture-panel' ?> hidden>
 <div class="impe-routing-heading">
  <strong><?= $esAsesorShell?'Datos del prospecto':'Datos durante la llamada' ?></strong>
  <span><?= $esAsesorShell?'Captura durante la conversación. Los datos y el resultado de la llamada se guardan por separado.':'Captura durante la conversación. El borrador se guarda automáticamente; revisa el resultado al finalizar.' ?></span>
  <?php if($esAsesorShell): ?><button type="button" class="impe-routing-close" data-sales-prospect-close aria-label="Cerrar datos del prospecto"><i class="bi bi-x-lg" aria-hidden="true"></i></button><?php endif; ?>
 </div>
 <form <?= $esAsesorShell?'':'data-routing-capture-form' ?> autocomplete="off">
  <?php require __DIR__.($esAsesorShell?'/prospecto_venta.php':'/captura_institucional_campos.php'); ?>
  <button type="submit" class="impe-routing-save"><?= $esAsesorShell?'Guardar datos':'Guardar borrador' ?></button>
  <p data-routing-capture-message <?= $esAsesorShell?'data-sales-prospect-feedback':'' ?> role="status" aria-live="polite"></p>
 </form>
</div>
