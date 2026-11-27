<?php if (!empty($_SESSION['usuario_id']) && tienePermiso('whatsapp.ver')): ?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/whatsapp_contextual.css?v=<?= substr(hash_file('sha256', ROOT_PATH.'/public/css/whatsapp_contextual.css'),0,16) ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/whatsapp_avisos_globales.css?v=<?= substr(hash_file('sha256', ROOT_PATH.'/public/css/whatsapp_avisos_globales.css'),0,16) ?>">
<div class="dropdown wa-global" data-wa-global data-base-url="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>">
  <button class="topbar-reminder-button dropdown-toggle wa-global-button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="WhatsApp" title="WhatsApp">
    <i class="bi bi-whatsapp" aria-hidden="true"></i>
    <span class="wa-global-count" data-wa-count hidden></span>
  </button>
  <div class="dropdown-menu dropdown-menu-end wa-global-menu">
    <div class="wa-global-heading"><strong>WhatsApp</strong><span data-wa-summary>Consultando mensajes…</span></div>
    <div class="wa-global-list" data-wa-list></div>
    <div class="wa-global-status" data-wa-status role="status"></div>
    <a class="wa-global-all" href="<?= BASE_URL ?>index.php?controller=whatsapp&amp;action=index">Ver conversaciones <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
  </div>
</div>
<script defer src="<?= BASE_URL ?>public/javascript/whatsapp_contextual.js?v=<?= substr(hash_file('sha256', ROOT_PATH.'/public/javascript/whatsapp_contextual.js'),0,16) ?>" data-whatsapp-contextual data-base-url="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>"></script>
<script defer src="<?= BASE_URL ?>public/javascript/whatsapp_avisos_globales.js?v=<?= substr(hash_file('sha256', ROOT_PATH.'/public/javascript/whatsapp_avisos_globales.js'),0,16) ?>"></script>
<?php endif; ?>
