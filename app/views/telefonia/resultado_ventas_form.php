<?php $ventasResultadoFormularioIncluido=true; $escVenta=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); ?>
<style>
#modalResultadoVenta .sales-result-trigger{height:auto;min-height:54px;text-align:left;white-space:normal;padding-right:2.5rem;line-height:1.5}
#modalResultadoVenta .sales-result-menu{max-height:310px;overflow:auto;border:1px solid #d5e0f3;border-radius:12px;box-shadow:0 12px 30px rgba(25,45,85,.12)}
#modalResultadoVenta .sales-result-choice{display:flex;align-items:center;gap:12px;white-space:normal;min-height:42px;margin:0;border-radius:8px;font-size:inherit;cursor:pointer}
#modalResultadoVenta .sales-result-choice .form-check-input{margin:0;flex-shrink:0}
#modalResultadoVenta .sales-result-choice:has(input:disabled){opacity:.6;cursor:default}
</style>
<div class="modal fade" id="modalResultadoVenta" tabindex="-1" aria-labelledby="tituloResultadoVenta" aria-hidden="true">
 <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg system-form-dialog linkage-incoming-modal-dialog">
  <div class="modal-content system-form-modal">
   <div class="modal-header system-form-modal-header"><div><h5 id="tituloResultadoVenta" class="system-form-modal-title">Resultado de llamada comercial</h5><p class="system-form-modal-subtitle">Registra lo que ocurrió. El resultado guardado es definitivo.</p></div><button class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
   <form class="linkage-incoming-form" data-sales-form>
    <div class="modal-body system-form-modal-body">
     <input type="hidden" name="csrf_token" value="<?= $escVenta($_SESSION['csrf_token']??TelefoniaContactosService::tokenFormulario()) ?>"><input type="hidden" name="pbx_call_id"><input type="hidden" name="extension">
     <input type="hidden" name="catalogo_version" value="<?= $escVenta(TelefoniaResultadoVentasCatalogo::VERSION) ?>">
     <input type="hidden" name="resultados_seleccionados" value="[]">
     <div class="linkage-incoming-summary"><strong data-sales-form-number></strong><span data-sales-form-state></span></div>
     <p class="system-form-modal-subtitle" data-sales-capture-summary hidden></p>
     <template data-sales-historical-options><?php foreach(TelefoniaResultadoVentasService::opciones() as $code=>$label): ?><option value="<?= $escVenta($code) ?>"><?= $escVenta($label) ?></option><?php endforeach; ?></template>
     <div class="row g-3" data-sales-fields>
      <div class="col-12"><label class="form-label" id="salesResultLabel" for="salesResultTrigger">Resultado de la llamada</label>
       <input type="hidden" id="salesResult" name="resultado">
       <div class="dropdown" data-sales-result-picker><button type="button" class="form-select sales-result-trigger" id="salesResultTrigger" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-labelledby="salesResultLabel salesResultText"><span id="salesResultText" data-sales-result-text>Selecciona uno o dos resultados</span></button>
        <div class="dropdown-menu w-100 p-2 sales-result-menu" aria-labelledby="salesResultLabel" data-sales-result-options><?php foreach(TelefoniaResultadoVentasCatalogo::opcionesSeleccion() as $code=>$label): ?><label class="dropdown-item sales-result-choice"><input type="checkbox" class="form-check-input" value="<?= $escVenta($code) ?>" data-sales-result-choice data-requires-connection="<?= TelefoniaResultadoVentasCatalogo::conPersona($code)?'1':'0' ?>"><span><?= $escVenta($label) ?></span></label><?php endforeach; ?></div>
       </div><small class="system-form-modal-subtitle d-block mt-2" data-sales-result-help>Puedes seleccionar uno o hasta dos resultados.</small>
      </div>
      <div class="col-12" data-sales-interrupted hidden><label class="form-label" for="salesInterrupted">¿Hablaste con una persona antes del corte?</label><select data-native-select class="form-select" id="salesInterrupted" name="contacto_antes_corte"><option value="">Selecciona una opción</option><option value="SI">Sí, hablé con una persona</option><option value="NO">No, no hubo conversación con una persona</option></select></div>
      <div class="col-12" data-sales-legacy-result hidden><p class="system-form-modal-subtitle mb-0">Este resultado se guardó con un formulario anterior y se conserva sin cambios.</p></div>
      <div class="col-12" data-sales-previous-reason hidden><p class="system-form-modal-subtitle mb-0" data-sales-previous-reason-text></p></div>
      <div class="col-12 col-md-6"><label class="form-label" for="salesProspect">Nombre del prospecto</label><input class="form-control" id="salesProspect" name="prospecto_nombre" maxlength="150"><small class="system-form-modal-subtitle d-block mt-2">Dato informado; no equivale a identidad verificada.</small></div>
      <div class="col-12 col-md-6"><label class="form-label" for="salesContact">Contacto de mi agenda</label><select data-native-select class="form-select" id="salesContact" name="contacto_id"><option value="">Sin vincular contacto</option><?php foreach(($contactosVentas??[]) as $contact): ?><option value="<?= (int)$contact['id'] ?>"><?= $escVenta($contact['nombre'].' · '.$contact['telefono']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><div class="form-check"><input type="checkbox" class="form-check-input" id="salesSaveContact" name="guardar_contacto" value="1"><label class="form-check-label" for="salesSaveContact">Guardar este prospecto en mi agenda</label></div><label class="form-label mt-2" for="salesEmail">Correo (opcional, para la agenda)</label><input type="email" class="form-control" id="salesEmail" name="correo" maxlength="180"></div>
      <div class="col-12"><label class="form-label" for="salesNext">Próxima llamada (opcional)</label><input type="datetime-local" id="salesNext" name="proxima_accion_at" class="form-control"><small class="system-form-modal-subtitle d-block mt-2">Si acordaron volver a hablar, selecciona la fecha y hora del recordatorio.</small></div>
      <div class="col-12"><label class="form-label" for="salesNote">Observaciones</label><textarea class="form-control" id="salesNote" name="observacion" rows="3" maxlength="1000"></textarea></div>
     </div>
     <section class="mt-4 border-top pt-3" data-sales-observaciones hidden
       data-list-url="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=observacionesVenta"
       data-add-url="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=agregarObservacionVenta">
      <h6 class="form-label mb-2">Observaciones adicionales</h6>
      <p class="system-form-modal-subtitle">Cada nota conserva su autor y fecha. Una vez guardada, no se puede editar ni eliminar. El resultado y la observación original permanecen iguales.</p>
      <div data-sales-notes-list aria-live="polite"></div>
      <button type="button" class="btn btn-system-light mt-2" data-sales-note-open hidden><i class="bi bi-plus-circle" aria-hidden="true"></i> Agregar observación</button>
      <div class="mt-3" data-sales-note-editor hidden>
       <label class="form-label" for="salesAdditionalNote">Nueva observación</label>
       <textarea class="form-control" id="salesAdditionalNote" rows="3" maxlength="1000" data-sales-note-text aria-describedby="salesAdditionalNoteHelp"></textarea>
       <small id="salesAdditionalNoteHelp" class="system-form-modal-subtitle d-block mt-2">Hasta 1000 caracteres. Esta nota no programa llamadas ni cambia el resultado.</small>
       <div class="d-flex flex-wrap gap-2 mt-3"><button type="button" class="btn btn-system-save" data-sales-note-save><i class="bi bi-check-circle" aria-hidden="true"></i> Guardar observación</button><button type="button" class="btn btn-system-light" data-sales-note-cancel>Cancelar</button></div>
      </div>
      <p class="system-form-modal-subtitle mt-2" data-sales-notes-feedback role="status" aria-live="polite"></p>
     </section>
     <p class="system-form-modal-subtitle mt-3" data-sales-feedback role="status" aria-live="polite"></p>
    </div>
    <div class="modal-footer system-form-modal-footer linkage-incoming-form-footer"><button type="button" class="btn btn-system-light" data-bs-dismiss="modal">Cerrar</button><button class="btn btn-system-save" type="submit" data-sales-submit><i class="bi bi-check-circle"></i> Guardar resultado</button></div>
   </form>
  </div>
 </div>
</div>
<script src="<?= BASE_URL ?>public/javascript/telefonia_ventas_observaciones.js?v=<?= filemtime(__DIR__.'/../../../public/javascript/telefonia_ventas_observaciones.js') ?>"></script>
