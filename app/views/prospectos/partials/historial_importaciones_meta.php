<?php
/** Historial permanente de Meta. Sólo incluido para prospectos.importar_meta. */
$hist=$metaHistorialListado;
$hFiltros=[
    'buscar'=>is_string($_GET['buscar']??null)?(string)$_GET['buscar']:'',
    'desde'=>is_string($_GET['desde']??null)?(string)$_GET['desde']:'',
    'hasta'=>is_string($_GET['hasta']??null)?(string)$_GET['hasta']:''
];
$hUrl=static fn(int $numero): string => BASE_URL.'index.php?'.http_build_query([
    'controller'=>'prospecto','action'=>'index','seccion'=>'meta',
    'historial_importaciones'=>'1'
]+array_filter($hFiltros,static fn($v)=>$v!=='')+['pagina'=>$numero]);
?>
<div class="meta-history" id="campanias-historial">
<p class="meta-history-intro">Consulta cada archivo de Meta que terminó de importarse, con sus resultados y el usuario responsable. Los registros antiguos no se reconstruyen.</p>
<?php if (!$metaHistorialDisponible): ?>
<div class="meta-history-empty" role="status"><i class="bi bi-database-exclamation" aria-hidden="true"></i>
<div><strong>Historial aún no disponible</strong><p>Es necesario instalar la estructura del historial. Las campañas y los prospectos existentes no se modifican.</p></div></div>
<?php else: ?>
<div class="meta-history-kpis" aria-label="Totales de las importaciones consultadas">
<div><small>Archivos completados</small><strong><?= number_format((int)$hist['total']) ?></strong></div>
<div><small>Filas leídas</small><strong><?= number_format((int)$hist['indicadores']['filas']) ?></strong></div>
<div><small>Prospectos nuevos</small><strong><?= number_format((int)$hist['indicadores']['nuevos']) ?></strong></div>
<div><small>Repetidos</small><strong><?= number_format((int)$hist['indicadores']['repetidos']) ?></strong></div>
<div><small>Rechazados</small><strong><?= number_format((int)$hist['indicadores']['rechazados']) ?></strong></div>
</div>
<form class="meta-history-filters" method="get" action="<?= $esc(BASE_URL.'index.php') ?>">
<input type="hidden" name="controller" value="prospecto">
<input type="hidden" name="action" value="index">
<input type="hidden" name="seccion" value="meta">
<input type="hidden" name="historial_importaciones" value="1">
<div><label for="metaHistorialBuscar">Archivo o formulario</label>
<input id="metaHistorialBuscar" name="buscar" type="search" maxlength="100" placeholder="Buscar archivo o formulario" value="<?= $esc($hFiltros['buscar']) ?>"></div>
<div><label for="metaHistorialDesde">Desde</label><input id="metaHistorialDesde" name="desde" type="date" value="<?= $esc($hFiltros['desde']) ?>"></div>
<div><label for="metaHistorialHasta">Hasta</label><input id="metaHistorialHasta" name="hasta" type="date" value="<?= $esc($hFiltros['hasta']) ?>"></div>
<button class="central-link meta-history-search" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Consultar</button>
<a class="central-link meta-history-reset" href="<?= $esc(BASE_URL.'index.php?controller=prospecto&action=index&seccion=meta&historial_importaciones=1') ?>">Limpiar</a>
</form>
<?php if (empty($hist['registros'])): ?>
<div class="meta-history-empty" role="status"><i class="bi bi-clock-history" aria-hidden="true"></i>
<div><strong>No hay importaciones para mostrar</strong><p>Las nuevas cargas completadas aparecerán aquí, con su archivo, fecha y cantidades. Comprueba los filtros si esperabas resultados.</p></div></div>
<?php else: ?>
<p class="meta-history-count"><?= number_format((int)$hist['total']) ?> archivos encontrados · <?= (int)$hist['limite'] ?> por página · Horario de Ciudad de México.</p>
<div class="central-table-wrap meta-history-table-wrap" role="region" tabindex="0" aria-label="Historial de importaciones de Meta">
<table class="central-table meta-history-table">
<thead><tr><th>Fecha de importación</th><th>Archivo</th><th>Formulario y programa</th><th>Responsable</th><th>Leídas</th><th>Nuevos</th><th>Repetidos</th><th>Rechazados</th><th>Estado</th></tr></thead>
<tbody>
<?php foreach ($hist['registros'] as $r): ?>
<tr>
<td><?= $esc(ProspectoFechaHoraHelper::aMexico($r['terminado_at'])) ?></td>
<td class="meta-history-file"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i><span><?= $esc($r['nombre_archivo']) ?></span></td>
<td>
<?php if ($r['formularios_total']>0): ?>
<details class="meta-history-forms"><summary><?= (int)$r['formularios_total'] ?> <?= (int)$r['formularios_total']===1?'formulario':'formularios' ?> · <?= (int)$r['campanias_total'] ?> <?= (int)$r['campanias_total']===1?'campaña':'campañas' ?></summary>
<ul><?php foreach ($r['formularios_detalle'] as $form): ?>
<li><strong><?= $esc($form['nombre']?:'Formulario sin nombre') ?></strong><small><?= (int)$form['total'] ?> registros<?= $form['id']!==''?' · ID '.$esc($form['id']):'' ?></small>
<small>Programa: <?= $esc(!empty($form['programas'])?implode(' · ',$form['programas']):'Sin clasificar') ?></small></li>
<?php endforeach; ?></ul>
<?php if ((int)$r['formularios_total']>count($r['formularios_detalle'])): ?><small>Se muestran los primeros <?= count($r['formularios_detalle']) ?> formularios.</small><?php endif; ?>
</details>
<?php else: ?><span class="meta-history-muted">Sin formulario informado</span><?php endif; ?>
</td>
<td><?= $esc(trim((string)($r['responsable']??''))?:'Usuario #'.(int)$r['usuario_id']) ?></td>
<td class="meta-history-number"><?= number_format((int)$r['filas_leidas']) ?></td>
<td class="meta-history-number meta-history-new"><?= number_format((int)$r['nuevos']) ?></td>
<td class="meta-history-number"><strong><?= number_format((int)$r['repetidos_crm']+(int)$r['repetidos_archivo']) ?></strong><small><?= (int)$r['repetidos_crm'] ?> en CRM · <?= (int)$r['repetidos_archivo'] ?> en archivo</small></td>
<td class="meta-history-number"><?= number_format((int)$r['rechazados']) ?></td>
<td><span class="meta-history-completed"><i class="bi bi-check-circle" aria-hidden="true"></i> Completado</span></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php
$hPagina=(int)$hist['pagina'];$hPaginas=(int)$hist['paginas'];
$hInicio=max(1,min($hPagina-2,max(1,$hPaginas-6)));
$hFinal=min($hPaginas,$hInicio+6);
?>
<?php if ($hPaginas>1): ?>
<nav class="meta-history-pagination" aria-label="Páginas del historial de importaciones">
<?php if ($hPagina>1): ?><a href="<?= $esc($hUrl($hPagina-1)) ?>" rel="prev" aria-label="Página anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i> Anterior</a><?php endif; ?>
<?php if ($hInicio>1): ?><a href="<?= $esc($hUrl(1)) ?>" aria-label="Página 1">1</a><?php if ($hInicio>2): ?><span class="meta-history-dots" aria-hidden="true">…</span><?php endif; ?><?php endif; ?>
<?php for ($n=$hInicio;$n<=$hFinal;$n++): ?>
<a href="<?= $esc($hUrl($n)) ?>" <?= $n===$hPagina?'aria-current="page"':'' ?> aria-label="Página <?= $n ?>"><?= $n ?></a>
<?php endfor; ?>
<?php if ($hFinal<$hPaginas): ?><?php if ($hFinal<$hPaginas-1): ?><span class="meta-history-dots" aria-hidden="true">…</span><?php endif; ?><a href="<?= $esc($hUrl($hPaginas)) ?>" aria-label="Página <?= $hPaginas ?>"><?= $hPaginas ?></a><?php endif; ?>
<?php if ($hPagina<$hPaginas): ?><a href="<?= $esc($hUrl($hPagina+1)) ?>" rel="next" aria-label="Página siguiente">Siguiente <i class="bi bi-chevron-right" aria-hidden="true"></i></a><?php endif; ?>
</nav>
<p class="meta-history-page-count">Página <?= $hPagina ?> de <?= $hPaginas ?></p>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</div>
