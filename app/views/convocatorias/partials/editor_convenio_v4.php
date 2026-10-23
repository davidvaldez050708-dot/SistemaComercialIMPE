<?php // Contenedor institucional; las tarjetas, Registro y QR los monta el componente existente. ?>
<div class="modal fade" id="modalCrearConvocatoria" tabindex="-1" aria-labelledby="editorConvenioV4Titulo" aria-hidden="true" data-borrador-v4="1" data-bs-backdrop="static">
 <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl system-form-dialog impe-aliado-multi-dialog">
  <div class="modal-content system-form-modal">
   <div class="modal-header system-form-modal-header"><div><h5 class="system-form-modal-title" id="editorConvenioV4Titulo">Nueva convocatoria</h5><p class="system-form-modal-subtitle">Los avances se guardan como borrador. Publica cuando estén completos.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
   <form class="convocatoria-create-form" action="<?= $texto(BASE_URL) ?>index.php?controller=convocatoria&amp;action=guardarProgramasAliado" method="POST" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $texto($_SESSION['csrf_token']??'') ?>">
    <div class="modal-body"><div class="system-form-grid"><div class="system-form-full" hidden><input name="enlace_registro"><input name="clasificacion"></div></div></div>
    <div class="modal-footer impe-v4-footer"><div data-v4-status role="status" aria-live="polite">Cargando borrador…</div><button type="button" class="btn btn-system-light" data-v4-retry hidden>Reintentar guardado</button><button type="button" class="btn btn-system-light" data-v4-reload hidden>Cargar versión del servidor</button><button type="button" class="btn btn-system-light" data-v4-discard>Descartar borrador</button><button type="button" class="btn btn-system-light" data-bs-dismiss="modal">Volver al detalle</button><button type="submit" class="btn btn-system-save" disabled>Publicar convocatorias</button></div>
   </form>
  </div>
 </div>
</div>
<?php require $component; ?>
