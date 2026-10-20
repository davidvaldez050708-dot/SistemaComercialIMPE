<?php $escVenta=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); ?>
<div class="modal fade" id="modalResultadoVenta" tabindex="-1" aria-labelledby="tituloResultadoVenta" aria-hidden="true">
 <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg system-form-dialog linkage-incoming-modal-dialog">
  <div class="modal-content system-form-modal">
   <div class="modal-header system-form-modal-header"><div><h5 id="tituloResultadoVenta" class="system-form-modal-title">Resultado de llamada comercial</h5><p class="system-form-modal-subtitle">Registra lo que ocurrió. El resultado guardado es definitivo.</p></div><button class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
   <form class="linkage-incoming-form" data-sales-form>
    <div class="modal-body system-form-modal-body">
     <input type="hidden" name="csrf_token" value="<?= $escVenta($_SESSION['csrf_token']??TelefoniaContactosService::tokenFormulario()) ?>"><input type="hidden" name="pbx_call_id"><input type="hidden" name="extension">
     <div class="linkage-incoming-summary"><strong data-sales-form-number></strong><span data-sales-form-state></span></div>
     <p class="system-form-modal-subtitle" data-sales-capture-summary hidden></p>
     <div class="row g-3" data-sales-fields>
      <div class="col-12"><label class="form-label" for="salesResult">Resultado</label><select data-native-select class="form-select" id="salesResult" name="resultado" required><option value="">Selecciona lo que ocurrió</option><?php foreach(TelefoniaResultadoVentasService::opciones() as $code=>$label): ?><option value="<?= $escVenta($code) ?>"><?= $escVenta($label) ?></option><?php endforeach; ?></select></div>
      <div class="col-12 col-md-6"><label class="form-label" for="salesProspect">Nombre del prospecto</label><input class="form-control" id="salesProspect" name="prospecto_nombre" maxlength="150"><small class="system-form-modal-subtitle d-block mt-2">Dato informado; no equivale a identidad verificada.</small></div>
      <div class="col-12 col-md-6"><label class="form-label" for="salesContact">Contacto de mi agenda</label><select data-native-select class="form-select" id="salesContact" name="contacto_id"><option value="">Sin vincular contacto</option><?php foreach(($contactosVentas??[]) as $contact): ?><option value="<?= (int)$contact['id'] ?>"><?= $escVenta($contact['nombre'].' · '.$contact['telefono']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><div class="form-check"><input type="checkbox" class="form-check-input" id="salesSaveContact" name="guardar_contacto" value="1"><label class="form-check-label" for="salesSaveContact">Guardar este prospecto en mi agenda</label></div><label class="form-label mt-2" for="salesEmail">Correo (opcional, para la agenda)</label><input type="email" class="form-control" id="salesEmail" name="correo" maxlength="180"></div>
      <div class="col-12"><label class="form-label" for="salesNext">Próxima llamada (opcional)</label><input type="datetime-local" id="salesNext" name="proxima_accion_at" class="form-control"><small class="system-form-modal-subtitle d-block mt-2">Obligatoria si seleccionas «Requiere seguimiento».</small></div>
      <div class="col-12"><label class="form-label" for="salesNote">Observaciones</label><textarea class="form-control" id="salesNote" name="observacion" rows="3" maxlength="1000"></textarea></div>
     </div>
     <p class="system-form-modal-subtitle mt-3" data-sales-feedback role="status" aria-live="polite"></p>
    </div>
    <div class="modal-footer system-form-modal-footer linkage-incoming-form-footer"><button type="button" class="btn btn-system-light" data-bs-dismiss="modal">Cerrar</button><button type="button" class="btn btn-system-light" data-sales-recheck hidden><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Comprobar cierre</button><button class="btn btn-system-save" type="submit" data-sales-submit><i class="bi bi-check-circle"></i> Guardar resultado</button></div>
   </form>
  </div>
 </div>
</div>
