<?php if (!empty($metaPuedeConfigurar)): ?>
<div class="central-form-config meta-config-workspace" id="campanias-formularios">
<div class="meta-config-intro">
<span class="meta-config-intro-icon"><i class="bi bi-diagram-3" aria-hidden="true"></i></span>
<p>Relaciona el ID de cada formulario de Meta con un programa del catálogo. La asociación se reutiliza en futuras importaciones; no altera prospectos existentes.</p>
</div>
<form class="central-config-upload meta-config-upload" method="post" enctype="multipart/form-data" action="<?= $esc(BASE_URL.'index.php?controller=prospecto&action=metaFormularioDetectar') ?>">
<input type="hidden" name="csrf_meta" value="<?= $esc($metaCsrf) ?>">
<div class="meta-config-upload-field">
<label for="archivoFormularios"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i> Detectar formularios desde un archivo de Meta</label>
<input type="file" id="archivoFormularios" name="archivo_formularios" accept=".csv,.tsv,.txt,.xlsx" required>
</div>
<button class="central-link meta-config-upload-button" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Detectar formularios</button>
<small><i class="bi bi-info-circle" aria-hidden="true"></i> Solo identifica los IDs: no importa prospectos ni modifica las asociaciones guardadas.</small>
</form>
<?php if ($formularioAviso!==''): ?><p class="central-note" role="status"><?= $esc($formularioAviso) ?></p><?php endif; ?>
<?php if (!$formulariosDisponible): ?><p class="central-note">La configuración persistente requiere instalar su estructura. Puedes seguir revisando archivos y seleccionar sus programas antes de importar.</p>
<?php elseif (!$formulariosConfiguracion): ?>
<div class="meta-config-empty" role="status"><i class="bi bi-inbox" aria-hidden="true"></i>
<p>Aún no hay formularios detectados. Selecciona un archivo de Meta para identificar sus formularios y después asociarlos con sus programas.</p>
</div>
<?php else: ?>
<div class="central-form-config-grid">
<?php foreach ($formulariosConfiguracion as $f): $a=$f['asociacion']??[]; ?>
<form method="post" action="<?= $esc(BASE_URL.'index.php?controller=prospecto&action=metaFormularioGuardar') ?>" class="central-form-config-row">
<input type="hidden" name="csrf_meta" value="<?= $esc($metaCsrf) ?>"><input type="hidden" name="formulario_id" value="<?= $esc($f['id']) ?>"><input type="hidden" name="version" value="<?= (int)($a['version']??0) ?>">
<div><strong><?= $esc($f['nombre']) ?></strong><small>ID <?= $esc($f['id']) ?> · Versión <?= (int)($a['version']??0) ?></small></div>
<div><label for="programaFormulario<?= $esc(hash('sha256',$f['id'])) ?>">Programa educativo</label><select name="programa_codigo" id="programaFormulario<?= $esc(hash('sha256',$f['id'])) ?>">
<option value=""><?= $a?'Retirar asociación':'Selecciona un programa' ?></option>
<?php foreach ($metaProgramas as $p): ?><option value="<?= $esc($p['codigo']) ?>" <?= ($a['programa_codigo']??'')===$p['codigo']?'selected':'' ?>><?= $esc($p['nombre']) ?></option><?php endforeach; ?></select></div>
<button type="submit" class="central-link"><i class="bi bi-check2" aria-hidden="true"></i> Guardar asociación</button>
</form><?php endforeach; ?>
</div>
<?php if ($formulariosHistorial): ?>
<details class="central-form-history"><summary>Últimos cambios de asociaciones</summary><div class="central-table-wrap" tabindex="0" aria-label="Historial de asociaciones"><table class="central-table"><thead><tr><th>Formulario e ID</th><th>Programa</th><th>Versión</th><th>Usuario</th><th>Fecha · México</th></tr></thead><tbody>
<?php foreach ($formulariosHistorial as $h): ?><tr><td><?= $esc($h['formulario_nombre']) ?><small><?= $esc($h['formulario_id']) ?></small></td><td><?= $esc($h['programa_nombre']??'Asociación retirada') ?></td><td><?= (int)$h['version'] ?></td><td>#<?= (int)$h['usuario_id'] ?></td><td><?= $esc(ProspectoFechaHoraHelper::aMexico($h['created_at'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div></details><?php endif; endif; ?>
</div>
<?php endif; ?>
