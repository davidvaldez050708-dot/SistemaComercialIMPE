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
    <fieldset class="mt-3"><legend class="form-label">Programas autorizados para Registro *</legend><p class="text-muted small">El enlace mostrará la selección vigente. Los registros anteriores conservan su programa.</p>
     <?php foreach ($catalogoRegistro as $programa): ?><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="programas[]" id="programa_<?= $texto($programa['codigo']) ?>" value="<?= $texto($programa['codigo']) ?>"><label class="form-check-label" for="programa_<?= $texto($programa['codigo']) ?>"><?= $texto($programa['nombre']) ?></label></div><?php endforeach; ?>
    </fieldset>
   </div>
   <div class="modal-footer"><button type="button" class="btn btn-system-light d-none me-auto" data-registro-desactivar>Desactivar aliado</button><button type="button" class="btn btn-system-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-system-save" type="submit" data-registro-guardar>Guardar</button></div>
  </form>
 </div>
</div>
<?php endif; ?>
