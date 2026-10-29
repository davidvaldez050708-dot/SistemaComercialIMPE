<?php
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$rows=$ventasBandeja['filas']??[];$stats=$ventasBandeja['resumen']??[];
$fechaVenta=static function(string $value): string {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value);
    return $date&&$date->format('Y-m-d H:i:s')===$value?$date->format('d/m/Y · H:i'):$value;
};
?>
<style><?php foreach(['seguimiento_llamadas_expediente.css','seguimiento_llamadas_player.css','seguimiento_entrantes_analista.css','telefonia_ventas_ui.css'] as $css)echo file_get_contents(ROOT_PATH.'/public/css/'.$css); ?></style>
<section class="dashboard-panel linkage-incoming-page sales-calls-page" data-sales-history data-auto-call="<?= $esc($_GET['pbx_call_id']??'') ?>" data-auto-extension="<?= $esc($_GET['extension']??'') ?>"
 data-save-url="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=guardarResultadoVenta" data-evidence-url="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=actualizarEvidenciaVenta" data-csrf="<?= $esc($_SESSION['csrf_token']??'') ?>" data-detail-url="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=detalleVenta">
 <header class="linkage-incoming-heading">
  <div class="linkage-incoming-heading-copy"><span class="linkage-incoming-eyebrow"><i class="bi bi-headset" aria-hidden="true"></i> VENTAS · TELEFONÍA</span><h2>Mis llamadas</h2><p>Consulta tus llamadas, registra resultados y revisa las grabaciones disponibles.</p></div>
  <a class="btn btn-system-light linkage-incoming-back" href="<?= BASE_URL ?>index.php?controller=home&amp;action=index"><i class="bi bi-arrow-left" aria-hidden="true"></i> Mi teléfono y agenda</a>
 </header>
 <nav class="sales-history-tabs" aria-label="Bandeja comercial"><a class="btn btn-system-save" aria-current="page" href="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=misLlamadas">Historial</a><a class="btn btn-system-light" href="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=misLlamadas&amp;vista=devoluciones">Por devolver</a><a class="btn btn-system-light" href="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=misLlamadas&amp;vista=agenda">Programadas</a></nav>
 <div class="linkage-call-summary linkage-incoming-stats sales-history-stats"><?php foreach(['pbx'=>'Llamadas confirmadas','intentos'=>'Intentos sin confirmar','pendientes'=>'Por registrar','perdidas'=>'Perdidas','conversaciones'=>'Conversaciones registradas'] as $key=>$label): ?><div><span><?= $label ?></span><strong><?= (int)($stats[$key]??0) ?></strong></div><?php endforeach; ?></div>
 <?php if(!empty($errorMisLlamadas)): ?><p role="alert"><?= $esc($errorMisLlamadas) ?></p><?php endif; ?>
 <form method="get" data-sales-auto-filter class="data-territorial-toolbar linkage-tools-form sales-history-filters" aria-label="Filtrar llamadas">
  <input type="hidden" name="controller" value="telefonia"><input type="hidden" name="action" value="misLlamadas">
  <div class="data-filter-field linkage-search-field"><label for="salesSearch">Buscar llamada</label><div class="module-search"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" id="salesSearch" type="search" name="q" placeholder="Nombre o teléfono…" value="<?= $esc($_GET['q']??'') ?>" maxlength="100"></div></div>
  <div class="data-filter-field"><label for="salesFilter">Estado o tipo</label><select data-native-select class="form-select" id="salesFilter" name="estado"><?php foreach([''=>'Todas las llamadas','pendientes'=>'Pendientes de resultado','Entrante'=>'Entrantes','Saliente'=>'Salientes','transferidas'=>'Transferidas desde Recepción','perdidas'=>'Perdidas','sin_conexion'=>'Sin conexión confirmada'] as $key=>$label): ?><option value="<?= $esc($key) ?>" <?= ($key===($_GET['estado']??''))?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
  <div class="data-filter-field"><label for="salesFrom">Desde</label><input class="form-control" id="salesFrom" type="date" name="desde" value="<?= $esc($_GET['desde']??date('Y-m-d',strtotime('-29 days'))) ?>" required></div>
  <div class="data-filter-field"><label for="salesUntil">Hasta</label><input class="form-control" id="salesUntil" type="date" name="hasta" value="<?= $esc($_GET['hasta']??date('Y-m-d')) ?>" required></div>
 </form>
 <div class="linkage-call-section-heading linkage-incoming-list-head"><div><strong>Historial de llamadas</strong><p>Llamadas confirmadas e intentos pendientes de conciliación.</p></div><span class="linkage-call-recordings-count"><?= (int)($ventasBandeja['total']??0) ?></span></div>
 <div class="linkage-call-history-list linkage-incoming-history" role="list">
 <?php foreach($rows as $call):
  $result=$call['registro']??null;$code=(string)($result['resultado']??'');$ref=(string)$call['pbx_call_id'];$index=$call['id'];
  $nombre=($result['prospecto_nombre']??'')?:($call['nombre']?:'Llamada '.mb_strtolower($call['direccion']));
  if($ref==='')$nombre=str_starts_with((string)$index,'intento-')?'Intento de marcación':'Transferencia por confirmar';
  $audio=TelefoniaVentasLlamadasService::presentacionAudio($call);
  $transferida=$call['direccion']==='Entrante'&&!empty($call['solicitud_id'])&&$call['origen_extension']!=='';
  $audioTexto=$transferida?'Consultar grabaciones':'Consultar grabación';
 ?>
  <article class="linkage-call-history-card linkage-incoming-card" role="listitem" data-sales-call-kind="<?= $ref===''?'intento':'confirmada' ?>">
   <div class="linkage-call-recording-card-icon"><i class="bi <?= $call['direccion']==='Entrante'?'bi-telephone-inbound':'bi-telephone-outbound' ?>" aria-hidden="true"></i></div>
   <div class="linkage-call-recording-body">
    <div class="linkage-call-recording-top">
     <div class="linkage-call-history-identity"><strong><?= $esc($nombre) ?></strong><span><?= $esc($call['numero']) ?> <span aria-hidden="true">·</span> Ext. <?= $esc($call['extension']) ?></span><?php if($call['origen_extension']!==''): ?><span>Desde Recepción · Ext. <?= $esc($call['origen_extension']) ?></span><?php endif; ?></div>
     <time class="linkage-call-history-date" datetime="<?= $esc(str_replace(' ','T',$call['fecha'])) ?>"><i class="bi bi-calendar3" aria-hidden="true"></i> <?= $esc($fechaVenta($call['fecha'])) ?></time>
    </div>
    <div class="linkage-call-result-group" aria-label="Estado y resultado">
     <span class="linkage-call-result-badge <?= $ref!==''&&$call['conectada']?'is-contact':'' ?>"><?= $esc($ref===''?'Sin llamada confirmada':($result&&!$call['finalizada']?'Resultado registrado · Confirmación de la llamada pendiente':(!$call['finalizada']&&TelefoniaVentasLlamadasService::resultadoCapturable($call)?'Atención realizada · Resultado pendiente · Confirmación de la llamada pendiente':$call['direccion'].' · '.$call['estado_pbx']))) ?></span>
     <?php foreach(TelefoniaResultadoVentasCatalogo::etiquetasRegistro($result) as $etiquetaResultado): ?><span class="linkage-call-result-badge is-result"><?= $esc($etiquetaResultado) ?></span><?php endforeach; ?>
    </div>
    <div class="linkage-call-history-footer">
     <div class="linkage-call-result-group">
      <?php if($ref!==''&&(TelefoniaVentasLlamadasService::resultadoCapturable($call)||$result)): ?><?php /* IMPE_VENTAS_GUARD_RESULTADO_V3 */ if (!empty($call['registro']) || TelefoniaVentasLlamadasService::resultadoCapturable($call)): ?><button type="button" class="linkage-incoming-open" data-sales-detail="<?= $esc($ref) ?>" data-extension="<?= $esc($call['extension']) ?>"><i class="bi bi-journal-check" aria-hidden="true"></i> <?= $result?'Consultar resultado':'Registrar resultado' ?></button><?php endif; ?><?php endif; ?>
      <button type="button" class="linkage-incoming-open" data-sales-dial="<?= $esc($call['numero']) ?>"><i class="bi bi-telephone-outbound" aria-hidden="true"></i> Llamar</button>
      <?php if(TelefoniaVentasLlamadasService::audioConsultable($call)): ?>
       <button type="button" class="linkage-call-audio-state is-available linkage-call-recording-toggle" data-sales-audio="<?= $esc($ref) ?>" data-extension="<?= $esc($call['extension']) ?>" data-sales-ended="<?= $call['finalizada']?'1':'0' ?>" data-sales-transferida="<?= $transferida?'1':'0' ?>" data-segments="<?= $audio['disponible']?count($call['segmentos']):0 ?>" data-segment-info="<?= $esc(json_encode($call['segmentos_info']??[])) ?>" aria-expanded="false"><i class="bi bi-record-circle" aria-hidden="true"></i> <?= $audioTexto ?> <i class="bi bi-chevron-down linkage-call-recording-chevron" aria-hidden="true"></i></button>
       <?php if(!$audio['disponible']&&$call['finalizada']): ?><span class="linkage-call-audio-state" data-sales-audio-state="<?= $esc($audio['estado']) ?>"><?= $esc($audio['texto']) ?></span><?php endif; ?>
      <?php elseif($ref!==''): ?><span class="linkage-call-audio-state" title="<?= $esc($audio['detalle']) ?>" data-sales-audio-state="<?= $esc($audio['estado']) ?>"><i class="bi bi-info-circle" aria-hidden="true"></i> <?= $esc($audio['texto']) ?></span><?php endif; ?>
     </div>
     <span class="linkage-call-recording-duration" data-sales-duration-pending="<?= $call['segundos']===null?'1':'0' ?>"<?= $transferida?' title="Duración de tu conversación como asesor. Se confirma por separado del tiempo de Recepción."':'' ?>><i class="bi bi-clock" aria-hidden="true"></i> <?= $call['segundos']===null?($transferida?'Duración del asesor pendiente':'Sin duración confirmada'):sprintf('%02d:%02d',intdiv($call['segundos'],60),$call['segundos']%60) ?></span>
    </div>
    <div class="linkage-incoming-audio-panel" data-sales-audio-slot hidden></div>
    <?php if(!empty($call['tramos_verificados'])): ?><p class="system-form-modal-subtitle mt-2 mb-0"><?php foreach($call['tramos_verificados'] as $n=>$part): ?><?= $n?' · ':'' ?>Tramo <?= $n+1 ?>: <?= (int)$part['segundos'] ?> s<?php endforeach; ?><?= count($call['tramos_verificados'])>1?' · Duraciones por tramo; no se suman posibles solapamientos.':'' ?></p><?php endif; ?>
   </div>
  </article>
 <?php endforeach; ?>
 <?php if(!$rows): ?><div class="linkage-incoming-empty-panel" role="status"><i class="bi bi-telephone" aria-hidden="true"></i><h3>No hay llamadas con estos filtros.</h3><p>Prueba con otro nombre, teléfono o estado.</p></div><?php endif; ?>
 </div>
 <?php $totalVentas=(int)($ventasBandeja['total']??0);$paginaVentas=(int)($ventasBandeja['pagina']??1);$paginasVentas=(int)($ventasBandeja['paginas']??1);$vistaVentas='';require __DIR__.'/ventas_paginacion.php'; ?>
</section>
<?php require __DIR__.'/resultado_ventas_form.php'; ?>

<script defer src="<?= BASE_URL ?>public/javascript/telefonia_ventas_auto_filtros.js?v=<?= rawurlencode((string)filemtime(ROOT_PATH.'/public/javascript/telefonia_ventas_auto_filtros.js')) ?>"></script>
