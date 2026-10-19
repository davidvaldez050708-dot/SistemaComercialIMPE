<?php $esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); ?>
<style><?php foreach(['seguimiento_llamadas_expediente.css','seguimiento_entrantes_analista.css','telefonia_devoluciones.css'] as $css)echo file_get_contents(ROOT_PATH.'/public/css/'.$css); ?></style>
<section class="dashboard-panel linkage-incoming-page impe-callbacks" data-callbacks data-linkage-incoming data-result-mode="DEVOLUCION" data-current-user="<?= (int)($_SESSION['usuario_id']??0) ?>"
 data-save-url="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=devolucion"
 data-endpoint="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=devolucion"
 data-search-url="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&amp;action=buscarEntrantesSeguimientos"
 data-csrf="<?= $esc($_SESSION['csrf_token']??'') ?>" data-auto-attempt="<?= (int)($_GET['intento_id']??0) ?>"
 data-focus-attempt="<?= (int)($_GET['detalle_intento']??0) ?>">
 <header class="linkage-incoming-heading"><div class="linkage-incoming-heading-copy"><span class="linkage-incoming-eyebrow"><i class="bi bi-telephone-forward"></i> SEGUIMIENTO · TELEFONÍA</span><h2>Llamadas por devolver</h2><p>Contacta a las personas que recepción dejó para ti y registra el resultado de cada intento.</p></div><div class="linkage-incoming-heading-actions"><?php if (empty($desdeMisLlamadas)): ?><a class="btn btn-system-light linkage-incoming-back" href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&amp;action=llamadasEntrantes"><i class="bi bi-arrow-left"></i> Llamadas recibidas</a><?php endif; ?></div></header>
 <div class="linkage-call-summary linkage-incoming-stats"><div><span>Por devolver</span><strong data-callback-stat="pending">0</strong></div><div><span>Para reintentar</span><strong data-callback-stat="retry">0</strong></div><div><span>Atendidas</span><strong data-callback-stat="done">0</strong></div><div><span>Total</span><strong data-callback-stat="total">0</strong></div></div>
 <div class="impe-callback-filter-panel">
  <div class="impe-callback-filter-field"><label for="callbackSearch">Buscar contacto o institución</label><input id="callbackSearch" type="search" class="form-control" data-callback-search placeholder="Nombre, teléfono o motivo..."></div>
  <div class="impe-callback-filter-field"><label for="callbackFilter">Estado</label><select id="callbackFilter" data-native-select class="form-select" data-callback-filter><option value="PENDIENTE">Por devolver</option><option value="ATENDIDA">Atendidas</option><option value="TODAS">Todas</option></select></div>
  <div class="impe-callback-filter-action"><button type="button" class="btn btn-system-light w-100" data-callback-refresh><i class="bi bi-arrow-clockwise"></i> Actualizar</button></div>
 </div>
 <div class="linkage-call-section-heading linkage-incoming-list-head"><div><strong>Gestión de devoluciones</strong><p>Buzón y sin respuesta conservan el pendiente para reintentar.</p></div><span data-callback-count class="linkage-call-recordings-count">0</span></div>
 <div class="linkage-call-history-list linkage-incoming-history" role="list" data-callback-list></div>
 <nav class="linkage-incoming-pagination" aria-label="Páginas de devoluciones"><span class="linkage-incoming-pagination-range" data-callback-range></span><div class="linkage-incoming-pagination-pages"><button class="linkage-incoming-pagination-arrow" data-callback-prev>Anterior</button><span class="linkage-incoming-pagination-number is-current" aria-current="page" data-callback-page></span><button class="linkage-incoming-pagination-arrow" data-callback-next>Siguiente</button></div></nav>
 <script type="application/json" data-callback-data><?= json_encode($devoluciones,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
</section>
<?php $esDevolucion=true;require __DIR__.'/resultado_analista_form.php';unset($esDevolucion); ?>
<script src="<?= BASE_URL ?>public/javascript/telefonia_devoluciones.js?v=<?= filemtime(ROOT_PATH.'/public/javascript/telefonia_devoluciones.js') ?>"></script>
<?php $aviso=$_SESSION['telefonia_devolucion_aviso']??null;unset($_SESSION['telefonia_devolucion_aviso']);if($aviso): ?><script>document.addEventListener('DOMContentLoaded',()=>{const a=<?= json_encode($aviso,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;window.IMPE_TELEPHONY_NOTIFY?.(a.mensaje,a.error);});</script><?php endif; ?>
