<?php
// Destino efímero de una notificación; no se arrastra a filtros ni paginadores.
$destacado=filter_var($_GET['destacar']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$aviso=$_GET['aviso_actividad']??'';
$mensajes=[
    'pendiente'=>'Actividad localizada. Abrir este aviso no completa la gestión.',
    'actualizada'=>'La actividad fue reprogramada. Se muestra el estado actual del prospecto.',
    'sin_pendiente'=>'La actividad del aviso ya no está pendiente. Se muestra el estado actual del prospecto.',
    'otra_seccion'=>'El prospecto cambió de sección. Se muestra su ubicación actual.',
    'no_disponible'=>'El prospecto de esta notificación ya no está disponible para tu cuenta.'
];
if (is_string($aviso) && isset($mensajes[$aviso])):
?>
<div class="impe-embudo-alert" role="status" data-notificacion-destino="<?= (int)$destacado ?>">
    <?= htmlspecialchars($mensajes[$aviso],ENT_QUOTES,'UTF-8') ?>
</div>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>public/css/embudo_notificacion_destino.css?v=1">
<script defer src="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>public/javascript/embudo_notificacion_destino.js?v=1"></script>
<?php endif; ?>
