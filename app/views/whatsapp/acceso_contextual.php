<?php if(tienePermiso('whatsapp.ver')): ?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>public/css/whatsapp_contextual.css?v=1">
<script defer src="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>public/javascript/whatsapp_contextual.js?v=1"
 data-whatsapp-contextual data-base-url="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>"></script>
<?php endif; ?>
