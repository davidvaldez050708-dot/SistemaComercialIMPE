<?php if (!empty($_SESSION['usuario_id']) && tienePermiso('whatsapp.ver')): ?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/whatsapp_contextual.css?v=<?= substr(hash_file('sha256', ROOT_PATH.'/public/css/whatsapp_contextual.css'),0,16) ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/whatsapp_avisos_globales.css?v=<?= substr(hash_file('sha256', ROOT_PATH.'/public/css/whatsapp_avisos_globales.css'),0,16) ?>">
<div class="dropdown wa-global" data-wa-global data-base-url="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>" data-user-id="<?= (int)$_SESSION['usuario_id'] ?>">
  <button class="topbar-reminder-button dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Abrir WhatsApp" title="WhatsApp" data-wa-button>
    <i class="bi bi-whatsapp" aria-hidden="true"></i>
    <span class="topbar-reminder-badge d-none" data-wa-count>0</span>
  </button>
  <div class="dropdown-menu dropdown-menu-end topbar-reminder-menu">
    <div class="topbar-reminder-header"><strong>WhatsApp</strong><span data-wa-summary>Consultando mensajes…</span></div>
    <div class="topbar-reminder-list" data-wa-list></div>
    <div class="topbar-reminder-empty" data-wa-status role="status"><i class="bi bi-arrow-repeat" aria-hidden="true"></i><strong>Actualizando mensajes</strong><span>Consultando conversaciones pendientes.</span></div>
    <a class="wa-global-all" href="<?= BASE_URL ?>index.php?controller=whatsapp&amp;action=index">Ver conversaciones <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
  </div>
</div>
<script defer src="<?= BASE_URL ?>public/javascript/whatsapp_contextual.js?v=<?= substr(hash_file('sha256', ROOT_PATH.'/public/javascript/whatsapp_contextual.js'),0,16) ?>" data-whatsapp-contextual data-base-url="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>"></script>
<script defer src="<?= BASE_URL ?>public/javascript/whatsapp_avisos_globales.js?v=<?= substr(hash_file('sha256', ROOT_PATH.'/public/javascript/whatsapp_avisos_globales.js'),0,16) ?>"></script>
<?php endif; ?>
