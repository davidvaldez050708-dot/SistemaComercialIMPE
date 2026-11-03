<?php
$esc=static fn($v)=>htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');
$texto=static fn($v)=>$v===null||$v===''?'Sin indicar':(string)$v;
$filtros=[];foreach (['buscar','origen','programa','limite','campania'] as $k) if (isset($_GET[$k])&&is_string($_GET[$k])) $filtros[$k]=$_GET[$k];
if ($seccionProspectos!=='meta') unset($filtros['campania']);
$urlPagina=static fn($p)=>BASE_URL.'index.php?'.http_build_query(['controller'=>'prospecto','action'=>'index','seccion'=>$seccionProspectos]+$filtros+['pagina'=>$p]);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/prospectos_central.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/prospectos_central.css'),0,16) ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/prospectos_ficha_panel.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/prospectos_ficha_panel.css'),0,16) ?>">
<?php if ($seccionProspectos==='meta'): ?><link rel="stylesheet" href="<?= BASE_URL ?>public/css/prospectos_importador_meta.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/prospectos_importador_meta.css'),0,16) ?>"><?php endif; ?>
<div class="prospectos-central">
<?php require __DIR__.'/partials/navegacion.php';require __DIR__.'/partials/resumen.php'; ?>
<?php if ($mensajeError!==''): ?><p class="central-alert" role="alert"><?= $esc($mensajeError) ?></p><?php endif; ?>
<?php if (!$centralDisponible): ?>
<section class="central-panel central-empty"><i class="bi bi-archive" aria-hidden="true"></i><h3>El almacén central aún no está disponible</h3><p>Los registros originales continúan disponibles en Aliados.</p><a class="central-link" href="<?= $esc(BASE_URL.'index.php?controller=prospecto&action=index&seccion=aliados') ?>">Consultar registros de formularios</a></section>
<?php elseif ($seccionProspectos==='asignaciones'): ?>
<section class="central-panel"><div class="central-section-head"><h3>Historial de asignaciones</h3><p>Se muestran únicamente asignaciones existentes. La distribución automática se habilitará en una fase posterior.</p></div>
<?php if (!$listado['registros']): ?><div class="central-empty"><i class="bi bi-person-check" aria-hidden="true"></i><h3>Aún no hay asignaciones registradas</h3><p>Los prospectos del almacén no se presentan como asignados.</p></div>
<?php else: ?><div class="central-table-wrap" tabindex="0" role="region" aria-label="Asignaciones"><table class="central-table"><thead><tr><th>Prospecto</th><th>Asesor</th><th>Fecha de asignación · México</th><th>Fecha de trabajo</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>
<?php foreach ($listado['registros'] as $r): ?><tr><td><?= $esc($texto($r['nombre_completo'])) ?></td><td><?= $esc($texto($r['asesor'])) ?></td><td><?= $esc(ProspectoFechaHoraHelper::aMexico($r['asignado_at'])) ?></td><td><?= $esc($texto($r['fecha_trabajo'])) ?></td><td><?= $esc($r['estado']) ?></td><td><a class="central-link" href="<?= $esc(BASE_URL.'index.php?controller=prospecto&action=detalle&id='.(int)$r['prospecto_id']) ?>" data-prospecto-ficha>Ver ficha</a></td></tr><?php endforeach; ?>
</tbody></table></div><?php require __DIR__.'/partials/paginacion_central.php';endif; ?></section>
<?php else: ?>
<?php if ($seccionProspectos==='meta') require __DIR__.'/partials/formularios_programas.php'; ?>
<?php if ($seccionProspectos==='meta' && !empty($metaResultado)): ?>
<div class="meta-import-result" role="status"><strong>Última importación terminada</strong>
<?= (int)$metaResultado['nuevos'] ?> prospectos nuevos · <?= (int)$metaResultado['repetidos'] ?> ya existentes · <?= (int)$metaResultado['rechazados'] ?> filas no válidas · <?= (int)$metaResultado['repetidos_archivo'] ?> repetidos dentro del archivo.
</div><?php endif; ?>
<?php if ($seccionProspectos==='meta' && !empty($metaAviso)): ?><p class="central-alert" role="alert"><?= $esc($metaAviso) ?></p><?php endif; ?>
<section class="central-panel">
<div class="central-section-head <?= $seccionProspectos==='meta'?'meta-import-toolbar':'' ?>">
<h3><?= $seccionProspectos==='meta'?'Prospectos de campañas':'Todos los prospectos' ?></h3>
<?php if ($seccionProspectos==='meta'&&!empty($metaPuedeConfigurar)): ?><a class="central-link" href="<?= $esc(BASE_URL.'index.php?controller=prospecto&action=index&seccion=meta&configurar_formularios=1#campanias-formularios') ?>"><i class="bi bi-sliders" aria-hidden="true"></i> Configurar formularios</a><?php endif; ?>
<?php if ($seccionProspectos==='meta' && !empty($metaPuedeImportar)): ?>
<button type="button" class="meta-import-trigger" data-meta-abrir><i class="bi bi-file-earmark-arrow-up" aria-hidden="true"></i> Importar archivo</button>
<?php endif; ?>
</div>
<form method="get" class="central-filters" data-central-filtros>
<input type="hidden" name="controller" value="prospecto"><input type="hidden" name="action" value="index"><input type="hidden" name="seccion" value="<?= $esc($seccionProspectos) ?>">
<div><label for="centralBuscar">Buscar prospecto</label><div class="central-search"><i class="bi bi-search" aria-hidden="true"></i><input id="centralBuscar" name="buscar" type="search" maxlength="180" placeholder="Nombre, teléfono o correo…" value="<?= $esc($filtros['buscar']??'') ?>"></div></div>
<?php if ($seccionProspectos==='meta'): ?>
<div><label for="centralCampania">Campaña</label><select id="centralCampania" name="campania"><option value="">Todas las campañas</option><?php foreach ($listado['campanias']??[] as $c): ?><option value="<?= $esc($c['id']) ?>" <?= ($filtros['campania']??'')===$c['id']?'selected':'' ?>><?= $esc(($c['nombre']?:'Campaña sin nombre').' · '.$c['id']) ?></option><?php endforeach; ?></select></div>
<?php else: ?>
<div><label for="centralOrigen">Procedencia</label><select id="centralOrigen" name="origen"><?php foreach ([''=>'Todos los orígenes','ALIADO'=>'Aliados','FORMULARIO_GENERAL'=>'Formularios generales','META_ADS'=>'Campañas'] as $v=>$n): ?><option value="<?= $v ?>" <?= ($filtros['origen']??'')===$v?'selected':'' ?>><?= $n ?></option><?php endforeach; ?></select></div>
<?php endif; ?>
<div><label for="centralPrograma">Programa educativo</label><select id="centralPrograma" name="programa"><option value="">Todos los programas</option><?php foreach ($listado['programas'] as $p): ?><option value="<?= $esc($p['programa_clave']) ?>" <?= ($filtros['programa']??'')===$p['programa_clave']?'selected':'' ?>><?= $esc($p['nombre']) ?></option><?php endforeach; ?></select></div>
<div><label for="centralLimite">Por página</label><select id="centralLimite" name="limite"><?php foreach ([25,50,100] as $n): ?><option <?= $listado['limite']===$n?'selected':'' ?>><?= $n ?></option><?php endforeach; ?></select></div>
<noscript><button type="submit" class="central-link">Buscar</button></noscript></form>
<p class="central-alert" role="alert" data-central-error hidden></p><span class="central-loading" role="status" aria-live="polite" data-central-status></span>
<div data-central-results><p class="central-count"><?= (int)$listado['total'] ?> prospectos · Fechas en hora de México.</p>
<div class="central-table-wrap" tabindex="0" role="region" aria-label="Prospectos comerciales"><table class="central-table"><thead><tr><th>Fecha de registro</th><th>Nombre</th><th>Teléfono</th><th>Programa</th><th><?= $seccionProspectos==='meta'?'Campaña':'Procedencia' ?></th><th>Horario de contacto</th><th>Gestión</th><th>Detalle</th></tr></thead><tbody>
<?php foreach ($listado['registros'] as $r): ?><tr><td><?= $esc($r['fecha_origen']?ProspectoFechaHoraHelper::aMexico($r['fecha_origen']):'Fecha de origen sin confirmar') ?></td><td class="central-name"><?= $esc($texto($r['nombre_completo'])) ?><small>ID comercial #<?= (int)$r['id'] ?></small></td><td><?= $esc($texto($r['telefono_original'])) ?></td><td><?= $esc($texto($r['programas'])) ?></td><td class="central-campaign-cell"><?php if ($seccionProspectos!=='meta'): ?><?= $esc($texto($r['procedencias'])) ?><?php endif; require __DIR__.'/partials/campanias_celda.php'; ?></td><td><?= $esc($texto($r['horario'])) ?></td><td><span class="central-badge"><?= $esc($r['gestion']??'Sin asignación') ?></span></td><td><a class="central-link" href="<?= $esc(BASE_URL.'index.php?controller=prospecto&action=detalle&id='.(int)$r['id']) ?>" data-prospecto-ficha><i class="bi bi-eye" aria-hidden="true"></i> Ver ficha</a></td></tr><?php endforeach; ?>
<?php if (!$listado['registros']): ?><tr><td colspan="8"><div class="central-empty"><i class="bi bi-search" aria-hidden="true"></i><h3>No hay prospectos con estos filtros</h3><p>Los registros originales se conservan en Aliados.</p></div></td></tr><?php endif; ?>
</tbody></table></div><?php require __DIR__.'/partials/paginacion_central.php'; ?></div></section>
<?php endif; ?>
</div>
<?php require __DIR__.'/partials/ficha_panel.php'; ?>
<?php if ($seccionProspectos==='meta' && !empty($metaPuedeImportar)): require __DIR__.'/partials/importar_meta.php'; endif; ?>
<script defer src="<?= BASE_URL ?>public/javascript/prospectos_ficha_panel.js?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/javascript/prospectos_ficha_panel.js'),0,16) ?>"></script>
<script defer src="<?= BASE_URL ?>public/javascript/prospectos_central.js?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/javascript/prospectos_central.js'),0,16) ?>"></script>
<?php if ($seccionProspectos==='meta' && !empty($metaPuedeImportar)): ?><script defer src="<?= BASE_URL ?>public/javascript/prospectos_importador_meta.js?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/javascript/prospectos_importador_meta.js'),0,16) ?>"></script><?php endif; ?>
