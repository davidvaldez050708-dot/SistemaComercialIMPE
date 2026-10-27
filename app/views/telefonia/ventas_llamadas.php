<?php
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$rows=$ventasBandeja['filas']??[];$stats=$ventasBandeja['resumen']??[];
?>
<style><?php foreach(['seguimiento_llamadas_expediente.css','seguimiento_llamadas_player.css','seguimiento_entrantes_analista.css','telefonia_devoluciones.css','telefonia_ventas_ui.css'] as $css)echo file_get_contents(ROOT_PATH.'/public/css/'.$css); ?></style>
<section class="dashboard-panel linkage-incoming-page impe-callbacks sales-calls-page" data-sales-history data-auto-call="<?= $esc($_GET['pbx_call_id']??'') ?>" data-auto-extension="<?= $esc($_GET['extension']??'') ?>"
 data-save-url="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=guardarResultadoVenta" data-detail-url="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=detalleVenta">
 <header class="linkage-incoming-heading"><div class="linkage-incoming-heading-copy"><span class="linkage-incoming-eyebrow"><i class="bi bi-headset"></i> VENTAS · TELEFONÍA</span><h2>Mis llamadas comerciales</h2><p>Historial y resultados de tus llamadas. La conexión la confirma la centralita; el resultado lo registras tú.</p></div><a class="btn btn-system-light linkage-incoming-back" href="<?= BASE_URL ?>index.php?controller=home&amp;action=index"><i class="bi bi-arrow-left"></i> Mi teléfono y agenda</a></header>
 <nav class="sales-history-tabs" aria-label="Bandeja comercial"><a class="btn btn-system-save" href="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=misLlamadas">Historial</a><a class="btn btn-system-light" href="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=misLlamadas&amp;vista=devoluciones">Por devolver</a><a class="btn btn-system-light" href="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=misLlamadas&amp;vista=agenda">Programadas</a></nav>
 <div class="linkage-call-summary linkage-incoming-stats sales-history-stats"><?php foreach(['pbx'=>'Llamadas PBX','intentos'=>'Marcaciones sin conciliar','pendientes'=>'Por registrar','perdidas'=>'Perdidas','conversaciones'=>'Conversaciones registradas'] as $key=>$label): ?><div><span><?= $label ?></span><strong><?= (int)($stats[$key]??0) ?></strong></div><?php endforeach; ?></div>
 <?php if(!empty($errorMisLlamadas)): ?><p role="alert"><?= $esc($errorMisLlamadas) ?></p><?php endif; ?>
 <form method="get" class="impe-callback-filter-panel"><input type="hidden" name="controller" value="telefonia"><input type="hidden" name="action" value="misLlamadas">
  <div class="impe-callback-filter-field"><label for="salesSearch">Buscar nombre o teléfono</label><input class="form-control" id="salesSearch" type="search" name="q" value="<?= $esc($_GET['q']??'') ?>" maxlength="100"></div>
  <div class="impe-callback-filter-field"><label for="salesFilter">Mostrar</label><select data-native-select class="form-select" id="salesFilter" name="estado"><?php foreach([''=>'Todas','pendientes'=>'Pendientes de resultado','Entrante'=>'Entrantes','Saliente'=>'Salientes','transferidas'=>'Transferidas desde Recepción','perdidas'=>'Perdidas','sin_conexion'=>'Sin conexión confirmada'] as $key=>$label): ?><option value="<?= $esc($key) ?>" <?= ($key===($_GET['estado']??''))?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
  <div class="impe-callback-filter-field"><label for="salesFrom">Desde</label><input class="form-control" id="salesFrom" type="date" name="desde" value="<?= $esc($_GET['desde']??date('Y-m-d',strtotime('-29 days'))) ?>" required></div>
  <div class="impe-callback-filter-field"><label for="salesUntil">Hasta</label><input class="form-control" id="salesUntil" type="date" name="hasta" value="<?= $esc($_GET['hasta']??date('Y-m-d')) ?>" required></div>
  <div class="impe-callback-filter-action"><button class="btn btn-system-light"><i class="bi bi-search"></i> Filtrar</button></div>
 </form>
 <div class="linkage-call-history-list linkage-incoming-history" role="list">
 <?php foreach($rows as $call): $result=$call['registro']??null;$code=(string)($result['resultado']??'');$ref=(string)$call['pbx_call_id'];$index=$call['id']; ?>
  <article class="linkage-call-history-card linkage-incoming-card <?= $ref===''?'is-unconfirmed':'' ?>" role="listitem">
   <div class="linkage-call-recording-card-icon"><i class="bi <?= $call['direccion']==='Entrante'?'bi-telephone-inbound':'bi-telephone-outbound' ?>"></i></div>
   <div class="linkage-call-recording-body">
    <div class="linkage-call-recording-top"><div class="linkage-call-history-identity"><strong><?= $esc($ref==='' ? (str_starts_with((string)$index,'intento-')?'Intento de marcación · Sin referencia PBX':'Transferencia pendiente de confirmar') : (($result['prospecto_nombre']??'')?:($call['nombre']?:'Llamada '.$call['direccion']))) ?></strong><p><?= $esc($call['numero']) ?> · Ext. <?= $esc($call['extension']) ?></p><?php if($call['origen_extension']!==''): ?><small>Transferida desde extensión <?= $esc($call['origen_extension']) ?></small><?php endif; ?></div><time><?= $esc($call['fecha']) ?></time></div>
    <div class="linkage-call-detail linkage-incoming-card-detail"><span class="sales-pbx-state <?= $ref===''?'is-pending':($call['conectada']?'is-connected':'is-unanswered') ?>"><?= $esc($ref==='' ? 'Aún no se ha vinculado una llamada confirmada a este intento' : $call['direccion'].' · '.$call['estado_pbx']) ?></span><?php if($result): ?><p><?= $esc(TelefoniaResultadoVentasService::opciones()[$code]??$code) ?></p><?php endif; ?></div>
    <div class="linkage-call-history-footer"><div class="linkage-call-result-group">
     <?php if($ref!==''&&($result || TelefoniaVentasLlamadasService::resultadoCapturable($call))): ?><button type="button" class="linkage-incoming-open" data-sales-detail="<?= $esc($ref) ?>" data-extension="<?= $esc($call['extension']) ?>"><i class="bi bi-journal-check"></i> <?= $result?'Consultar resultado':'Registrar resultado' ?></button><?php endif; ?>
     <button type="button" class="linkage-incoming-open" data-sales-dial="<?= $esc($call['numero']) ?>"><i class="bi bi-telephone-outbound"></i> Llamar</button>
     <?php if($call['finalizada']&&$call['conectada']&&TelefoniaResultadoVentasService::esConversacion($code)&&count($call['segmentos'])>0): ?>
      <button type="button" class="linkage-call-audio-state is-available linkage-call-recording-toggle" data-sales-audio="<?= $esc($ref) ?>" data-extension="<?= $esc($call['extension']) ?>" data-segments="<?= count($call['segmentos']) ?>" aria-expanded="false"><i class="bi bi-record-circle"></i> Consultar grabación</button>
     <?php else: ?><small class="text-muted"><?= $code!==''&&!TelefoniaResultadoVentasService::esConversacion($code)?'Sin reproducción de audio':($ref===''?'Sin referencia PBX: no hay audio verificable':($call['segmentos']?'Audio sujeto al resultado':'Audio sin confirmar para tu extensión')) ?></small><?php endif; ?>
    </div><span class="linkage-call-recording-duration"><i class="bi bi-clock"></i> <?= $call['segundos']===null?($ref===''?'Sin duración PBX':'Duración sin confirmar'):sprintf('%02d:%02d',intdiv($call['segundos'],60),$call['segundos']%60) ?></span></div>
    <div data-sales-audio-slot hidden></div>
   </div>
  </article>
 <?php endforeach; ?>
 <?php if(!$rows): ?><p class="text-muted">No hay llamadas con estos filtros.</p><?php endif; ?>
 </div>
 <nav class="linkage-incoming-pagination" aria-label="Páginas de llamadas"><span><?= (int)($ventasBandeja['total']??0) ?> registros</span><div class="linkage-incoming-pagination-pages"><?php $page=(int)($ventasBandeja['pagina']??1);$pages=(int)($ventasBandeja['paginas']??1);foreach(['Anterior'=>$page-1,'Siguiente'=>$page+1] as $label=>$n): $url=BASE_URL.'index.php?'.http_build_query(array_merge($_GET,['controller'=>'telefonia','action'=>'misLlamadas','pagina'=>$n])); ?><a class="linkage-incoming-pagination-arrow <?= ($n<1||$n>$pages)?'disabled':'' ?>" href="<?= $esc(($n<1||$n>$pages)?'#':$url) ?>" aria-disabled="<?= ($n<1||$n>$pages)?'true':'false' ?>"><?= $label ?></a><?php endforeach; ?><span><?= $page ?> / <?= $pages ?></span></div></nav>
</section>
<?php require __DIR__.'/resultado_ventas_form.php'; ?>
