<?php // Contenido inerte: se abre en un único modal compartido por acción. ?>
<template id="detalle-<?= $id ?>">
 <div class="aliados-section-heading"><div><span class="aliados-eyebrow">INSTITUCIÓN INTERNACIONAL</span><h3><?= $esc($aliado['nombre']) ?></h3><p><?= $esc($aliado['ubicacion']) ?></p></div></div>
 <dl class="international-detail-grid">
  <?php foreach (['País'=>$aliado['pais_nombre']??'','Estado / provincia'=>$aliado['region']??'','Ciudad'=>$aliado['ciudad']??'','Contacto'=>$contacto,'Teléfono'=>$telefono,'Programas autorizados'=>$aliado['programas']??'','Convenio'=>$aliado['convenio_referencia']??''] as $label=>$value): ?><div><dt><?= $esc($label) ?></dt><dd><?= $esc($value ?: 'Sin registrar') ?></dd></div><?php endforeach; ?>
 </dl>
 <section class="international-general-forms"><h3>Formularios generales de la institución</h3><p class="form-text">Estos enlaces conservan la procedencia del aliado y sus programas autorizados. No pertenecen a una convocatoria específica.</p>
 <?php if ((int)$aliado['activo']): foreach (['Registro'=>$enlaceRegistro,'Inscripción'=>$enlaceInscripcion] as $label=>$url): ?>
 <div class="international-link-row"><span><?= $label ?></span><div class="aliados-actions"><a class="btn btn-system-light" href="<?= $esc($url) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Abrir <?= mb_strtolower($label) ?></a><button type="button" class="btn btn-system-light" data-copy-ally-url="<?= $esc($url) ?>"><i class="bi bi-copy" aria-hidden="true"></i> Copiar <?= mb_strtolower($label) ?></button></div></div>
 <?php endforeach; else: ?><p class="text-muted">Enlaces desactivados: institución inactiva.</p><?php endif; ?>
 </section>
</template>
<?php if ($puedeConvenio): ?>
<template id="convenio-<?= $id ?>">
 <div class="aliados-section-heading"><div><h3><?= $esc($aliado['nombre']) ?></h3><p>El convenio conserva la institución original y sus enlaces.</p></div></div>
 <form method="post" action="<?= BASE_URL ?>index.php?controller=aliado&amp;action=convenioInternacional" class="system-form-grid">
  <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="csrf_internacional" value="<?= $esc($_SESSION['csrf_aliados_internacionales']) ?>">
  <div><label class="form-label" for="international-agreement-date">Fecha del convenio</label><input id="international-agreement-date" type="date" name="fecha" class="form-control system-form-control" value="<?= $esc($aliado['convenio_fecha']??'') ?>" required></div>
  <div><label class="form-label" for="international-agreement-reference">Referencia</label><input id="international-agreement-reference" name="referencia" class="form-control system-form-control" maxlength="180" value="<?= $esc($aliado['convenio_referencia']??'') ?>" required></div>
  <div class="system-form-full"><label class="form-label" for="international-agreement-notes">Notas</label><textarea id="international-agreement-notes" name="notas" class="form-control system-form-control" rows="3" maxlength="10000"><?= $esc($aliado['convenio_notas']??'') ?></textarea></div>
  <div class="system-form-full text-end"><button class="btn btn-system-save" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Guardar convenio</button></div>
 </form>
</template>
<?php endif; ?>
<?php if ($puedeVerConvocatorias): ?>
<template id="convocatorias-<?= $id ?>">
 <div class="aliados-section-heading"><div><h3><?= $esc($aliado['nombre']) ?></h3><p>Enlaces y códigos QR de cada convocatoria registrada.</p></div><span class="aliados-result-count"><?= count($propias) ?> convocatorias</span></div>
 <?php if (!$propias): ?><div class="data-empty-state linkage-empty-state international-empty"><i class="bi bi-megaphone" aria-hidden="true"></i><strong>Todavía no hay convocatorias.</strong><p>Las convocatorias conservarán aquí sus enlaces y su vigencia.</p></div><?php endif; ?>
 <?php foreach ($propias as $c):
  $hoy=date('Y-m-d');$estadoC=!(int)$c['estado']?'Inactiva':(!empty($c['fecha_termino'])&&$c['fecha_termino']<$hoy?'Finalizada':($c['fecha_inicio']>$hoy?'Programada':'Vigente'));
 ?>
 <section class="international-campaign" data-international-campaign>
  <div class="international-campaign-heading"><div><h3><?= $esc($c['titulo']) ?></h3><p><?= $esc($c['programa_nombre']) ?> · <?= $esc(ucfirst($c['formulario'])) ?></p></div><span class="aliados-result-count"><?= $estadoC ?></span></div>
  <p class="form-text"><i class="bi bi-calendar3" aria-hidden="true"></i> <?= $esc($fecha($c['fecha_inicio'])) ?> — <?= $esc($fecha($c['fecha_termino'])) ?></p>
  <label class="form-label" for="campaign-url-<?= (int)$c['convocatoria_id'] ?>">Enlace de <?= $esc($c['formulario']) ?></label><input id="campaign-url-<?= (int)$c['convocatoria_id'] ?>" class="form-control system-form-control" aria-label="Liga de convocatoria" readonly value="<?= $esc($c['enlace_registro']) ?>">
  <div class="aliados-actions international-campaign-actions"><a class="btn btn-system-light" href="<?= $esc($c['enlace_registro']) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Abrir formulario</a><button type="button" class="btn btn-system-light" data-copy-ally-url="<?= $esc($c['enlace_registro']) ?>"><i class="bi bi-copy" aria-hidden="true"></i> Copiar enlace</button><a class="btn btn-system-light" href="<?= BASE_URL ?>index.php?controller=aliado&amp;action=qrInternacional&amp;id=<?= (int)$c['convocatoria_id'] ?>" target="_blank" rel="noopener"><i class="bi bi-qr-code" aria-hidden="true"></i> Consultar QR</a><a class="btn btn-system-light" href="<?= BASE_URL ?>index.php?controller=aliado&amp;action=qrInternacional&amp;descargar=1&amp;id=<?= (int)$c['convocatoria_id'] ?>"><i class="bi bi-download" aria-hidden="true"></i> Descargar QR</a></div>
 </section><?php endforeach; ?>
</template>
<?php endif; ?>
