<?php $d=$aliadoEditar ?? []; ?>
<div class="modal fade" id="modalAliadoInternacional" tabindex="-1" aria-hidden="true" <?= $aliadoEditar?'data-aliado-edit-open':'' ?>>
 <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg system-form-dialog"><div class="modal-content system-form-modal">
 <div class="modal-header system-form-modal-header"><div><h5 class="system-form-modal-title"><?= $aliadoEditar?'Editar institución':'Registrar institución' ?></h5><p class="system-form-modal-subtitle">Conserva la ubicación del aliado sin asignar territorios mexicanos.</p></div><button class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
 <form method="post" action="<?= BASE_URL ?>index.php?controller=aliado&amp;action=guardarInternacional">
 <input type="hidden" name="id" value="<?= (int)($d['id']??0) ?>"><input type="hidden" name="csrf_internacional" value="<?= $esc($_SESSION['csrf_aliados_internacionales']) ?>">
 <div class="modal-body"><div class="system-form-grid">
 <div class="system-form-full"><label class="form-label" for="aliado_nombre">Institución</label><input class="form-control system-form-control" id="aliado_nombre" name="nombre" value="<?= $esc($d['nombre']??'') ?>" maxlength="180" required></div>
 <div><label class="form-label" for="aliado_pais">País</label><select class="form-select system-form-control" name="pais_codigo" id="aliado_pais" required><option value="">Selecciona un país</option><?php foreach (AliadoInternacionalService::paises() as $codigo=>$nombre): ?><option value="<?= $codigo ?>" <?= ($d['pais_codigo']??'')===$codigo?'selected':'' ?>><?= $esc($nombre) ?></option><?php endforeach; ?></select></div>
 <div data-aliado-otro-pais><label class="form-label" for="aliado_pais_nombre">Nombre del país</label><input class="form-control system-form-control" id="aliado_pais_nombre" name="pais_nombre" value="<?= $esc($d['pais_nombre']??'') ?>" maxlength="100"></div>
 <p class="system-form-full small text-muted d-none" data-aliado-mexico>Las instituciones mexicanas conservan el <a href="<?= BASE_URL ?>index.php?controller=aliado&amp;action=index">flujo territorial existente de Aliados</a>.</p>
 <div><label class="form-label" for="aliado_region">Estado, provincia o región</label><input class="form-control system-form-control" id="aliado_region" name="region" value="<?= $esc($d['region']??'') ?>" maxlength="120" required></div>
 <div><label class="form-label" for="aliado_ciudad">Ciudad</label><input class="form-control system-form-control" id="aliado_ciudad" name="ciudad" value="<?= $esc($d['ciudad']??'') ?>" maxlength="120" required></div>
 <div><label class="form-label" for="aliado_contacto">Contacto</label><input class="form-control system-form-control" id="aliado_contacto" name="contacto_nombre" value="<?= $esc($d['contacto_nombre']??'') ?>" maxlength="180"></div>
 <div><label class="form-label" for="aliado_telefono">Teléfono</label><input class="form-control system-form-control" id="aliado_telefono" name="contacto_telefono" value="<?= $esc($d['contacto_telefono']??'') ?>" maxlength="80"></div>
 <?php if (!$aliadoEditar): ?><fieldset class="system-form-full"><legend class="h6">Programas autorizados</legend><?php foreach ($programasCatalogo as $p): ?><label class="form-check"><input class="form-check-input" type="checkbox" name="programas[]" value="<?= $esc($p['codigo']) ?>"><span class="form-check-label"><?= $esc($p['nombre']) ?></span></label><?php endforeach; ?></fieldset><?php endif; ?>
 </div></div><div class="modal-footer"><button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-system-save">Guardar institución</button></div>
 </form></div></div>
</div>
