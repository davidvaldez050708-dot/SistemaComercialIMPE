<?php
$registroServicio = new AliadoRegistroService((new Database())->connect());
if ($registroServicio->disponible() && (tienePermiso('aliados.crear_manual') || tienePermiso('aliados.gestionar_programas'))):
$catalogoRegistro = $registroServicio->catalogo();
?>
<div class="modal fade" id="modalAliadoRegistro" tabindex="-1" aria-labelledby="tituloAliadoRegistro" aria-hidden="true" data-registro-admin data-estado="<?= (int)$estado['id'] ?>" data-base="<?= $texto(BASE_URL) ?>">
 <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
  <form class="modal-content aliados-modal" data-registro-form>
   <div class="modal-header"><div><h2 class="modal-title" id="tituloAliadoRegistro">Agregar aliado</h2><p data-registro-subtitulo>Relación registrada manualmente, sin formalización documental.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
   <div class="modal-body">
    <div class="alert alert-danger d-none" role="alert" data-registro-error></div>
    <input type="hidden" name="id"><input type="hidden" name="origen" value="manual">
    <input type="hidden" name="estado_id" value="<?= (int)$estado['id'] ?>">
    <div data-registro-manual class="row g-3">
     <div class="col-12"><label class="form-label" for="aliadoManualNombre">Institución *</label><input class="form-control" id="aliadoManualNombre" name="nombre_entidad" maxlength="180" minlength="2" required></div>
     <div class="col-md-6"><label class="form-label" for="aliadoManualTipo">Tipo de institución *</label><select class="form-select" id="aliadoManualTipo" name="tipo_entidad" required><?php foreach (AliadoRegistroService::TIPOS as $tipo): ?><option value="<?= $texto($tipo) ?>"><?= $texto(ucfirst(strtolower($tipo))) ?></option><?php endforeach; ?></select></div>
     <div class="col-md-6"><label class="form-label" for="aliadoManualEstado">Estado</label><input class="form-control" id="aliadoManualEstado" value="<?= $texto($estado['nombre']) ?>" disabled></div>
     <div class="col-md-6"><label class="form-label" for="aliadoManualMunicipio">Municipio *</label><select class="form-select" id="aliadoManualMunicipio" name="municipio_id" required><option value="">Selecciona municipio</option></select></div>
     <div class="col-md-6"><label class="form-label" for="aliadoManualSituacion">Relación</label><select class="form-select" id="aliadoManualSituacion" name="situacion"><option value="VIGENTE">Vigente</option><option value="HISTORICA">Histórica</option></select></div>
     <?php foreach (['contacto_nombre'=>['Persona de contacto',120,'text'],'contacto_cargo'=>['Cargo',120,'text'],'correo_contacto'=>['Correo',180,'email'],'telefono_fuente'=>['Teléfono',30,'tel'],'referencia'=>['Referencia',180,'text'],'relacion_fecha'=>['Fecha de relación (opcional)',10,'date']] as $campo=>$config): ?>
      <div class="col-md-6"><label class="form-label" for="manual_<?= $campo ?>"><?= $texto($config[0]) ?></label><input class="form-control" id="manual_<?= $campo ?>" name="<?= $campo ?>" type="<?= $config[2] ?>" maxlength="<?= $config[1] ?>"></div>
     <?php endforeach; ?>
     <div class="col-12"><label class="form-label" for="aliadoManualNotas">Notas</label><textarea class="form-control" id="aliadoManualNotas" name="notas" maxlength="1500" rows="3"></textarea></div>
    </div>
    <fieldset class="aliados-programas-fieldset mt-3">
     <legend class="aliados-programas-legend">Programas autorizados para Registro <span aria-hidden="true">*</span></legend>
     <p class="aliados-programas-ayuda">Selecciona los programas que ofertará este aliado. El enlace mostrará la selección vigente y los registros anteriores conservarán su programa.</p>
     <div class="aliados-programas-grid">
      <?php foreach ($catalogoRegistro as $programa): ?>
       <label class="aliados-programa-opcion" for="programa_<?= $texto($programa['codigo']) ?>">
        <input class="form-check-input aliados-programa-checkbox" type="checkbox" name="programas[]" id="programa_<?= $texto($programa['codigo']) ?>" value="<?= $texto($programa['codigo']) ?>">
        <span class="aliados-programa-nombre"><?= $texto($programa['nombre']) ?></span>
        <i class="bi bi-mortarboard aliados-programa-icono" aria-hidden="true"></i>
       </label>
      <?php endforeach; ?>
     </div>
    </fieldset>
   </div>
   <div class="modal-footer"><button type="button" class="btn btn-system-light d-none me-auto" data-registro-desactivar>Desactivar aliado</button><button type="button" class="btn btn-system-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-system-save" type="submit" data-registro-guardar>Guardar</button></div>
  </form>
 </div>
</div>

<div class="modal fade" id="modalManualContactos" tabindex="-1" aria-labelledby="tituloManualContactos" aria-hidden="true">
 <div class="modal-dialog modal-dialog-centered modal-lg">
  <div class="modal-content aliados-modal">
   <div class="modal-header"><div><span class="aliados-eyebrow">CONTACTOS DE DIFUSIÓN</span><h2 class="modal-title" id="tituloManualContactos">Canales del aliado</h2><p data-manual-contactos-context>Consulta y prepara los números autorizados del aliado.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
   <div class="modal-body">
    <div class="alert alert-danger d-none" data-manual-contactos-error role="alert"></div>
    <div class="aliados-contact-source-note"><i class="bi bi-shield-check" aria-hidden="true"></i><span>Los teléfonos obtenidos durante el registro se muestran como referencia. Al elegir uno para difusión se guarda una relación independiente y el dato original permanece intacto.</span></div>
    <div class="aliados-contact-list">
     <section class="aliados-contact-item" data-manual-source-card hidden>
      <span class="aliados-contact-item-icon"><i class="bi bi-telephone" aria-hidden="true"></i></span>
      <div class="aliados-contact-item-copy"><strong data-manual-source-phone>—</strong><span>Teléfono de registro · Aliado manual</span><div class="aliados-contact-badges"><span class="is-unconfirmed" data-manual-source-whatsapp>WhatsApp no confirmado</span></div></div>
      <div class="aliados-contact-item-actions"><button type="button" class="btn aliados-btn-secondary" data-manual-use-source><i class="bi bi-plus-circle" aria-hidden="true"></i> Usar para difusión</button></div>
     </section>
     <div class="aliados-contact-list" data-manual-contactos-list aria-live="polite"></div>
    </div>
    <div class="aliados-contact-editor">
     <div class="aliados-contact-editor-heading"><div><strong data-manual-editor-title>Agregar número de difusión</strong><span>Úsalo cuando la institución comparta un número específico para convocatorias.</span></div><button type="button" class="btn aliados-btn-secondary d-none" data-manual-contacto-limpiar>Cancelar edición</button></div>
     <form data-manual-contactos-form>
      <input type="hidden" name="id"><input type="hidden" name="origen" value="manual"><input type="hidden" name="contacto_id">
      <input type="hidden" name="nombre" value="Difusión"><input type="hidden" name="cargo" value=""><input type="hidden" name="correo" value="">
      <div class="aliados-contact-form-grid">
       <div><label class="form-label" for="manualContactoTelefono">Número</label><input class="form-control" id="manualContactoTelefono" name="telefono" maxlength="30" type="tel" autocomplete="tel" placeholder="Ej. 477 123 4567" required></div>
       <div><label class="form-label" for="manualContactoEtiqueta">Etiqueta</label><input class="form-control" id="manualContactoEtiqueta" name="etiqueta" maxlength="80" value="Difusión" placeholder="Ej. Difusión, Dirección, Admisiones" required></div>
      </div>
      <div class="aliados-contact-options">
       <label><input type="checkbox" name="whatsapp_confirmado" value="1"><span><strong>Confirmado para WhatsApp</strong><small>Marca sólo si la institución confirmó que este número usa WhatsApp.</small></span></label>
       <label><input type="checkbox" name="whatsapp_autorizado" value="1"><span><strong>Autorizó comunicaciones por WhatsApp</strong><small>Marca sólo cuando la institución haya aceptado recibir mensajes en este número.</small></span></label>
       <label><input type="checkbox" name="preferido_difusion" value="1"><span><strong>Preferido para difusión</strong><small>Será la primera opción sugerida al abrir la conversación.</small></span></label>
      </div>
      <div class="aliados-contact-form-actions"><button type="submit" class="btn aliados-btn-primary"><i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar contacto</button></div>
     </form>
    </div>
   </div>
  </div>
 </div>
</div>
<div class="modal fade" id="modalManualHistorial" tabindex="-1" aria-labelledby="tituloManualHistorial" aria-hidden="true">
 <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
  <div class="modal-content aliados-modal">
   <div class="modal-header"><div><h2 class="modal-title" id="tituloManualHistorial">Historial de difusión</h2><p>Actuaciones registradas por Cuenta Clave; no implica envío automático del CRM.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
   <div class="modal-body">
    <div class="alert alert-danger d-none" data-manual-historial-error role="alert"></div>
    <div data-manual-historial-list aria-live="polite"></div>
    <form data-manual-difusion-form class="row g-3 mt-3">
     <input type="hidden" name="id"><input type="hidden" name="origen" value="manual">
     <div class="col-md-4"><label for="manualDifusionCanal" class="form-label">Canal utilizado *</label><select class="form-select" name="canal" id="manualDifusionCanal" required><option value="CORREO">Correo</option><option value="WHATSAPP">WhatsApp</option><option value="OTRO">Otro</option></select></div>
     <div class="col-md-8"><label for="manualDifusionNota" class="form-label">Nota de difusión</label><input class="form-control" id="manualDifusionNota" name="nota" maxlength="500" placeholder="Ej. Convocatoria compartida con la institución"></div>
     <div class="col-12 d-flex justify-content-end"><button class="btn btn-system-save" type="submit">Registrar difusión realizada</button></div>
    </form>
   </div>
   <div class="modal-footer"><button type="button" class="btn btn-system-light" data-bs-dismiss="modal">Cerrar</button></div>
  </div>
 </div>
</div>
<?php endif; ?>
