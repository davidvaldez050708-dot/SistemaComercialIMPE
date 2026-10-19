<?php
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$all=$programacionesVentas??[];
$q=mb_strtolower(trim((string)($_GET['q']??'')));$state=(string)($_GET['estado']??'TODAS');$now=date('Y-m-d H:i:s');
$filtered=array_values(array_filter($all,static fn($r)=>($q===''||mb_strpos(mb_strtolower($r['nombre'].' '.$r['telefono'].' '.$r['detalle']),$q)!==false)&&($state!=='VENCIDAS'||$r['fecha']<=$now)));
$total=count($filtered);$pages=max(1,(int)ceil($total/20));$page=min($pages,max(1,(int)($_GET['pagina']??1)));$rows=array_slice($filtered,($page-1)*20,20);
?>
<style><?php foreach(['seguimiento_llamadas_expediente.css','seguimiento_llamadas_player.css','seguimiento_entrantes_analista.css','telefonia_devoluciones.css'] as $css)echo file_get_contents(ROOT_PATH.'/public/css/'.$css); ?></style>
<section class="dashboard-panel linkage-incoming-page impe-callbacks" data-sales-history
 data-save-url="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=guardarResultadoVenta" data-detail-url="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=detalleVenta"
 data-program-url="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=completarProgramacionVenta" data-csrf="<?= $esc($_SESSION['csrf_token']??TelefoniaContactosService::tokenFormulario()) ?>">
 <header class="linkage-incoming-heading"><div class="linkage-incoming-heading-copy"><span class="linkage-incoming-eyebrow"><i class="bi bi-calendar-event"></i> VENTAS · TELEFONÍA</span><h2>Contactos programados</h2><p>Agenda tus próximos contactos. Cerrar un recordatorio no registra una llamada ni una conversación.</p></div><a class="btn btn-system-light linkage-incoming-back" href="<?= BASE_URL ?>index.php?controller=home&amp;action=index">Mi teléfono y agenda</a></header>
 <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Bandeja comercial"><a class="btn btn-system-light" href="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=misLlamadas">Historial</a><a class="btn btn-system-light" href="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=misLlamadas&amp;vista=devoluciones">Por devolver</a><a class="btn btn-system-save" href="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=misLlamadas&amp;vista=agenda">Programadas</a></nav>
 <?php if(!empty($errorMisLlamadas)): ?><p role="alert"><?= $esc($errorMisLlamadas) ?></p><?php endif; ?>
 <form class="impe-callback-filter-panel" method="get"><input type="hidden" name="controller" value="telefonia"><input type="hidden" name="action" value="misLlamadas"><input type="hidden" name="vista" value="agenda">
  <div class="impe-callback-filter-field"><label for="salesProgramSearch">Nombre o teléfono</label><input class="form-control" type="search" id="salesProgramSearch" name="q" maxlength="100" value="<?= $esc($_GET['q']??'') ?>"></div>
  <div class="impe-callback-filter-field"><label for="salesProgramState">Mostrar</label><select data-native-select class="form-select" id="salesProgramState" name="estado"><option value="TODAS">Todas las programadas</option><option value="VENCIDAS" <?= $state==='VENCIDAS'?'selected':'' ?>>Pendientes de atender</option></select></div><div class="impe-callback-filter-action"><button class="btn btn-system-light">Filtrar</button></div>
 </form>
 <div class="linkage-call-history-list linkage-incoming-history" role="list">
 <?php foreach($rows as $r): ?><article class="linkage-call-history-card linkage-incoming-card" role="listitem"><div class="linkage-call-recording-card-icon"><i class="bi bi-calendar-event"></i></div><div class="linkage-call-recording-body">
  <div class="linkage-call-recording-top"><div class="linkage-call-history-identity"><strong><?= $esc($r['nombre']?:$r['telefono']) ?></strong><p><?= $esc($r['telefono']) ?></p></div><time><?= $esc($r['fecha']) ?></time></div><div class="linkage-call-detail linkage-incoming-card-detail"><?= $esc($r['detalle']) ?></div>
  <div class="linkage-call-history-footer"><div class="linkage-call-result-group"><span class="linkage-call-result-badge"><?= $r['fecha']<=$now?'Por atender':'Programada' ?></span><button type="button" class="linkage-incoming-open" data-sales-dial="<?= $esc($r['telefono']) ?>"><i class="bi bi-telephone-outbound"></i> Llamar</button>
   <?php if($r['pbx_call_id']!==''): ?><button type="button" class="linkage-incoming-open" data-sales-detail="<?= $esc($r['pbx_call_id']) ?>" data-extension="<?= $esc($r['extension']) ?>">Consultar resultado</button><?php endif; ?>
   <button type="button" class="linkage-incoming-open" data-sales-program-complete="<?= (int)$r['id'] ?>" data-source="<?= $esc($r['origen']) ?>" data-date="<?= $esc($r['fecha']) ?>"><i class="bi bi-check-circle"></i> Cerrar recordatorio</button>
  </div></div></div></article><?php endforeach; ?>
 <?php if(!$rows): ?><p class="text-muted">No hay contactos programados con estos filtros.</p><?php endif; ?>
 </div>
 <nav class="linkage-incoming-pagination" aria-label="Páginas de contactos programados"><span><?= $total ?> registros</span><div class="linkage-incoming-pagination-pages"><?php foreach(['Anterior'=>$page-1,'Siguiente'=>$page+1] as $label=>$n): ?><a class="linkage-incoming-pagination-arrow <?= ($n<1||$n>$pages)?'disabled':'' ?>" href="<?= $esc(($n<1||$n>$pages)?'#':BASE_URL.'index.php?'.http_build_query(array_merge($_GET,['controller'=>'telefonia','action'=>'misLlamadas','vista'=>'agenda','pagina'=>$n]))) ?>"><?= $label ?></a><?php endforeach; ?><span><?= $page ?> / <?= $pages ?></span></div></nav>
</section>
<?php require __DIR__.'/resultado_ventas_form.php'; ?>
