<?php
$texto=static fn($v)=>htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');
$hora=static function($fecha){try{return (new DateTime((string)$fecha))->format('H:i');}catch(Throwable $e){return '';}};
$estadoIcono=static fn($estado)=>match($estado){'LEIDO','ENTREGADO'=>'bi-check2-all','ENVIADO'=>'bi-check2','ERROR'=>'bi-exclamation-circle',default=>'bi-clock'};
$ultimaIdMensaje=0;
foreach($mensajesIniciales as $m)$ultimaIdMensaje=max($ultimaIdMensaje,(int)$m['id']);
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Conversación de WhatsApp</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= $texto(BASE_URL) ?>public/css/global.css">
<link rel="stylesheet" href="<?= $texto(BASE_URL) ?>public/css/whatsapp.css">
<link rel="stylesheet" href="<?= $texto(BASE_URL) ?>public/css/whatsapp_contextual.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/whatsapp_contextual.css'),0,12) ?>">
</head><body class="wa-panel-body">
<main class="whatsapp-module" data-whatsapp-module data-base-url="<?= $texto(BASE_URL) ?>"
 data-conversation-id="<?= (int)$conversacionSeleccionada['id'] ?>" data-last-message-id="<?= $ultimaIdMensaje ?>">
<?php if(!$estadoConfiguracionWhatsapp['lista']): ?>
<p class="alert alert-warning wa-config-warning" role="status">WhatsApp está pendiente de configuración. El envío aún no está disponible.</p>
<?php endif; ?>
<div class="whatsapp-chat-column"><?php require __DIR__.'/chat.php'; ?></div>
</main>
<script>window.IMPE_CSRF_TOKEN=<?= json_encode((string)($_SESSION['csrf_token']??''),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script>
<script src="<?= $texto(BASE_URL) ?>public/javascript/whatsapp.js?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/javascript/whatsapp.js'),0,12) ?>"></script>
</body></html>
