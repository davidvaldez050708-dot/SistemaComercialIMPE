<?php if (tienePermiso('formularios_aliados.gestionar_enlaces')): ?>
<section class="crear-convocatoria-modal-notes aliado-registro-enlace" data-registro-enlace data-base="<?= $texto(BASE_URL) ?>">
 <h3 class="panel-title">Formulario de Registro exclusivo</h3>
 <p class="text-muted small">Independiente de Crear convocatoria e Inscripción.</p>
 <p data-enlace-programas></p><p role="status" data-enlace-estado></p>
 <div class="alert alert-danger d-none" role="alert" data-enlace-error></div>
 <div class="d-flex flex-wrap gap-2 mb-3"><button type="button" class="btn btn-system-save" data-enlace-accion="generar">Generar enlace</button><button type="button" class="btn btn-system-light" data-enlace-accion="habilitar">Habilitar</button><button type="button" class="btn btn-system-light" data-enlace-accion="deshabilitar">Deshabilitar</button></div>
 <div data-enlace-publicado hidden>
  <label class="form-label" for="enlaceRegistroAliado">Enlace estable</label><input readonly class="form-control" id="enlaceRegistroAliado" data-enlace-url>
  <div class="d-flex flex-wrap gap-2 my-3"><button type="button" class="btn btn-system-light" data-enlace-copiar>Copiar enlace</button><a class="btn btn-system-light" target="_blank" rel="noopener noreferrer" data-enlace-preview>Vista previa</a><button type="button" class="btn btn-system-light" data-enlace-qr>Generar QR</button></div>
  <div data-enlace-qr-panel hidden>
   <div class="row g-2 mb-3"><div class="col-6"><label class="form-label" for="qrAliadoColor">Color QR</label><input class="form-control form-control-color" id="qrAliadoColor" type="color" value="#223a84" data-qr-color></div><div class="col-6"><label class="form-label" for="qrAliadoSize">Tamaño</label><select class="form-select" id="qrAliadoSize" data-qr-size><option value="600">600 px</option><option value="900">900 px</option></select></div></div>
   <canvas data-enlace-canvas role="img" aria-label="QR del enlace de Registro"></canvas>
   <button type="button" class="btn btn-system-light mt-2" data-enlace-descargar>Descargar QR PNG</button>
  </div>
 </div>
</section>
<?php endif; ?>
