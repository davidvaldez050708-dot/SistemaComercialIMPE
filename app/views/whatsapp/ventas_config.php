<?php $esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); ?>
<section class="dashboard-panel p-4">
    <div class="d-flex gap-3 align-items-center justify-content-between mb-3"><h2 class="h5 mb-0">Inicio comercial por WhatsApp</h2><a class="btn btn-system-light" href="<?= BASE_URL ?>index.php?controller=whatsapp">Volver</a></div>
    <p>Valida una plantilla de texto aprobada, sin variables ni botones, para que cada asesor inicie el contacto desde su prospecto. La validación consulta Meta y no envía mensajes.</p>
    <?php if(!$cuentasVentas): ?><div class="alert alert-warning">Configura primero un canal empresarial activo y predeterminado en Canales.</div><?php endif; ?>
    <?php foreach($cuentasVentas as $cuenta):$cfg=$configVentas[(int)$cuenta['id']]??[]; ?>
    <article class="border rounded p-3 mb-3">
        <h3 class="h6"><?= $esc($cuenta['nombre']) ?> · <?= $esc($cuenta['numero_mostrado']) ?></h3>
        <form method="POST" action="<?= BASE_URL ?>index.php?controller=whatsapp&amp;action=guardarPlantillaVentas" data-wa-ventas-config>
            <input type="hidden" name="csrf_token" value="<?= $esc($_SESSION['csrf_token']??'') ?>"><input type="hidden" name="cuenta_id" value="<?= (int)$cuenta['id'] ?>">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">ID de cuenta de WhatsApp (WABA)<input class="form-control" name="messaging_account_id" value="<?= $esc($cfg['messaging_account_id']??'') ?>" pattern="[0-9]{5,80}" required></label></div>
                <div class="col-md-4"><label class="form-label">Nombre de plantilla aprobada<input class="form-control" name="plantilla_nombre" value="<?= $esc($cfg['plantilla_nombre']??'') ?>" placeholder="inicio_atencion_comercial" pattern="[a-z0-9_]{1,512}" required></label></div>
                <div class="col-md-4"><label class="form-label">Idioma de la plantilla<input class="form-control" name="plantilla_idioma" value="<?= $esc($cfg['plantilla_idioma']??'es_MX') ?>" required maxlength="20"></label></div>
            </div>
            <p class="mt-2 mb-2" data-wa-ventas-preview><?= $esc($cfg['plantilla_cuerpo']??'La plantilla debe figurar como APROBADA en Meta antes de validarla aquí.') ?></p>
            <button type="submit" class="btn btn-system-save">Validar y guardar plantilla</button>
            <div class="mt-2" role="status" aria-live="polite" data-wa-ventas-feedback></div>
        </form>
    </article>
    <?php endforeach; ?>
</section>
<script src="<?= BASE_URL ?>public/javascript/whatsapp_ventas.js?v=<?= (int)filemtime(__DIR__.'/../../../public/javascript/whatsapp_ventas.js') ?>"></script>
