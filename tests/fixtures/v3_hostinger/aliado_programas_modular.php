<?php
/** Copia de referencia del componente Hostinger facilitado el 22/10/2026. Sólo pruebas. */
if (empty($aliadoContextoConvocatoria) || !($puedeCrear ?? false)) return;
$origenTipoModulo=(string)($_GET['aliado_tipo']??'');
$origenIdModulo=(int)($_GET['aliado_id']??0);
if (!in_array($origenTipoModulo,['manual','institucional'],true)||$origenIdModulo<=0) return;
$programasModulo=$aliadoContextoConvocatoria['opciones_convocatoria']??[];
$contextoModulo=['origen'=>$origenTipoModulo,'id'=>$origenIdModulo,'url'=>(string)($aliadoContextoConvocatoria['url']??''),'habilitado'=>(bool)($aliadoContextoConvocatoria['habilitado']??false)];
?>
<link rel="stylesheet" href="<?= $texto(BASE_URL) ?>public/css/convocatorias_aliado_modular.css?v=1">
<script type="application/json" id="impe-convocatoria-aliado-contexto"><?= json_encode($contextoModulo,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script>
<template id="impe-convocatoria-aliado-template">
    <section class="system-form-full convocatoria-aliado-modulo" data-convocatoria-aliado-modulo aria-labelledby="impe-convocatoria-aliado-title">
        <input type="hidden" name="aliado_tipo" value="<?= $texto($origenTipoModulo) ?>">
        <input type="hidden" name="aliado_id" value="<?= $origenIdModulo ?>">
        <input type="hidden" name="programa_codigo" value="" data-impe-programa-codigo>
        <span class="convocatoria-aliado-modulo-eyebrow">PROGRAMAS DEL ALIADO</span>
        <h3 id="impe-convocatoria-aliado-title"><?= $texto($aliadoContextoConvocatoria['origen']['nombre_entidad']??'Aliado') ?></h3>
        <p>Selecciona un programa para esta imagen. Las demás convocatorias de este aliado utilizarán el mismo enlace y QR de Registro.</p>
        <div class="convocatoria-aliado-modulo-grid" role="group" aria-label="Seleccionar programa de esta convocatoria">
            <?php foreach($programasModulo as $programaModulo): ?>
                <button type="button" class="convocatoria-aliado-modulo-programa" data-impe-programa-option data-codigo="<?= $texto($programaModulo['codigo']??'') ?>" data-clasificacion="<?= $texto($programaModulo['clasificacion']??'') ?>" aria-pressed="false">
                    <i class="bi bi-mortarboard" aria-hidden="true"></i><span><?= $texto($programaModulo['nombre']??'') ?></span><i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                </button>
            <?php endforeach; ?>
        </div>
        <div class="convocatoria-aliado-modulo-registro">
            <div class="convocatoria-aliado-modulo-registro-heading"><i class="bi bi-qr-code" aria-hidden="true"></i><div><strong>Enlace y QR compartidos</strong><small>El prospecto elegirá su programa en el formulario público de Registro.</small></div></div>
            <div class="convocatoria-aliado-modulo-acciones">
                <button type="button" class="btn btn-system-light" data-impe-crear-enlace><i class="bi bi-link-45deg" aria-hidden="true"></i> Generar enlace</button>
                <button type="button" class="btn btn-system-light" data-impe-crear-qr disabled><i class="bi bi-qr-code" aria-hidden="true"></i> Generar QR</button>
                <button type="button" class="btn btn-system-light" data-impe-habilitar hidden>Habilitar Registro</button>
            </div>
            <div class="convocatoria-aliado-modulo-url d-none" data-impe-url-wrap><input class="form-control system-form-control" type="text" readonly data-impe-url aria-label="Enlace exclusivo del aliado"><button type="button" class="btn btn-system-light" data-impe-copiar-enlace><i class="bi bi-copy" aria-hidden="true"></i> Copiar</button></div>
            <p class="convocatoria-aliado-modulo-estado" data-impe-estado role="status"></p>
            <div class="convocatoria-aliado-modulo-qr d-none" data-impe-qr-wrap>
                <canvas data-impe-qr-canvas width="600" height="600" role="img" aria-label="Código QR del Registro exclusivo del aliado"></canvas>
                <div class="convocatoria-aliado-modulo-acciones"><button type="button" class="btn btn-system-light" data-impe-copiar-qr><i class="bi bi-copy" aria-hidden="true"></i> Copiar QR</button><button type="button" class="btn btn-system-light" data-impe-descargar-qr><i class="bi bi-download" aria-hidden="true"></i> Descargar PNG</button></div>
            </div>
        </div>
        <div class="alert alert-danger d-none mt-2" data-impe-error role="alert"></div>
    </section>
</template>
<script defer src="<?= $texto(BASE_URL) ?>public/vendor/qrcode-generator/qrcode.js"></script>
<script defer src="<?= $texto(BASE_URL) ?>public/javascript/convocatorias_aliado_modular.js?v=1"></script>
<link rel="stylesheet" href="<?= $texto(BASE_URL) ?>public/css/convocatorias_aliado_multiprograma_v3.css?v=3">
<script defer src="<?= $texto(BASE_URL) ?>public/javascript/convocatorias_aliado_multiprograma_v3.js?v=3"></script>
