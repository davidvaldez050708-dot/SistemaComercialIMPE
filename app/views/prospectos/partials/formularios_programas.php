<?php if (!empty($metaPuedeConfigurar)): ?>
<details class="central-panel central-form-config" id="campanias-formularios" <?= ($_GET['configurar_formularios']??'')==='1'?'open':'' ?>>
<summary>Configurar formularios y programas educativos</summary>
<div class="central-section-head"><p>Asocia cada ID de formulario con un programa del catálogo. Los cambios se guardan explícitamente, conservan su historial y se aplican a futuras importaciones.</p></div>
<form class="central-config-upload" method="post" enctype="multipart/form-data" action="<?= $esc(BASE_URL.'index.php?controller=prospecto&action=metaFormularioDetectar') ?>">
<input type="hidden" name="csrf_meta" value="<?= $esc($metaCsrf) ?>">
<label for="archivoFormularios">Detectar formularios de un archivo de Meta</label><input type="file" id="archivoFormularios" name="archivo_formularios" required>
<button class="central-link" type="submit">Detectar formularios</button><small>Identifica sus IDs sin importar prospectos ni cambiar asociaciones guardadas.</small>
</form>
<?php if ($formularioAviso!==''): ?><p class="central-note" role="status"><?= $esc($formularioAviso) ?></p><?php endif; ?>
<?php if (!$formulariosDisponible): ?><p class="central-note">La configuración persistente requiere instalar su estructura. Puedes seguir revisando archivos y seleccionar sus programas antes de importar.</p>
<?php elseif (!$formulariosConfiguracion): ?><p class="central-note">Todavía no hay IDs de formulario detectados. Aparecerán al revisar un archivo autorizado o al consultar campañas ya incorporadas.</p>
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
</details>
<?php endif; ?>
