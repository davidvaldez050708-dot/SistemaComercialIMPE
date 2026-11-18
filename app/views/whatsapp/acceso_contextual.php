<?php if(tienePermiso('whatsapp.ver')): ?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>public/css/whatsapp_contextual.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/whatsapp_contextual.css'),0,12) ?>">
<script defer src="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>public/javascript/whatsapp_contextual.js?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/javascript/whatsapp_contextual.js'),0,12) ?>"
 data-whatsapp-contextual data-base-url="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>"></script>
<?php endif; ?>
