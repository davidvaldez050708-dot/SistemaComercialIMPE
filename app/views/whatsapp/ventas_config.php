<?php $esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); ?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/whatsapp_institucional.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/whatsapp_institucional.css'),0,12) ?>">
<section class="wa-admin">
    <header class="wa-admin-header">
        <div class="wa-admin-heading"><span class="wa-admin-icon"><i class="bi bi-chat-square-text" aria-hidden="true"></i></span><div><span class="wa-eyebrow">WhatsApp Business</span><h2>Inicio comercial</h2><p class="wa-intro">Configura la presentación que los asesores enviarán a sus prospectos cuando la ventana de atención esté cerrada.</p></div></div>
        <a class="wa-button" href="<?= BASE_URL ?>index.php?controller=whatsapp&amp;action=index"><i class="bi bi-arrow-left" aria-hidden="true"></i>Conversaciones</a>
    </header>
    <div class="wa-admin-note"><i class="bi bi-info-circle" aria-hidden="true"></i><p>Crea primero una plantilla de texto en Meta y espera su aprobación. Aquí puedes validarla y guardarla para el equipo. Validar no envía mensajes.</p></div>
    <?php if(!$cuentasVentas): ?><div class="alert alert-warning">Configura primero un canal empresarial activo y predeterminado en Canales.</div><?php endif; ?>
    <?php foreach($cuentasVentas as $cuenta):$cfg=$configVentas[(int)$cuenta['id']]??[]; ?>
    <article class="wa-admin-card">
        <div class="wa-admin-card-header"><div><h3><?= $esc($cuenta['nombre']) ?></h3><span class="wa-channel-number"><?= $esc($cuenta['numero_mostrado']) ?></span></div><span data-wa-template-state class="wa-admin-status <?= empty($cfg['plantilla_nombre'])?'is-pending':'' ?>"><?= empty($cfg['plantilla_nombre'])?'Pendiente de configurar':'Plantilla configurada' ?></span></div>
        <form method="POST" action="<?= BASE_URL ?>index.php?controller=whatsapp&amp;action=guardarPlantillaVentas" data-wa-ventas-config>
            <input type="hidden" name="csrf_token" value="<?= $esc($_SESSION['csrf_token']??'') ?>"><input type="hidden" name="cuenta_id" value="<?= (int)$cuenta['id'] ?>">
            <div class="wa-admin-fields">
                <label>ID de cuenta de WhatsApp<input name="messaging_account_id" value="<?= $esc($cfg['messaging_account_id']??'') ?>" placeholder="Identificador WABA" pattern="[0-9]{5,80}" inputmode="numeric" required><span class="wa-help">ID de la cuenta que contiene la plantilla.</span></label>
                <label>Nombre de la plantilla<input name="plantilla_nombre" value="<?= $esc($cfg['plantilla_nombre']??'') ?>" placeholder="inicio_atencion_comercial" pattern="[a-z0-9_]{1,512}" required><span class="wa-help">Usa el nombre exacto aprobado en Meta.</span></label>
                <label>Idioma<input name="plantilla_idioma" value="<?= $esc($cfg['plantilla_idioma']??'es_MX') ?>" required maxlength="20"><span class="wa-help">Español de México: es_MX.</span></label>
            </div>
            <div class="wa-admin-preview"><span>Presentación del equipo</span><p data-wa-ventas-preview><?= $esc($cfg['plantilla_cuerpo']??'Al validar una plantilla aprobada, su mensaje aparecerá aquí.') ?></p></div>
            <div class="wa-admin-actions"><button type="submit" class="wa-button wa-button-primary"><i class="bi bi-check2-circle" aria-hidden="true"></i>Validar y guardar</button><div role="status" aria-live="polite" data-wa-ventas-feedback></div></div>
        </form>
    </article>
    <?php endforeach; ?>
</section>
<script src="<?= BASE_URL ?>public/javascript/whatsapp_ventas.js?v=<?= (int)filemtime(__DIR__.'/../../../public/javascript/whatsapp_ventas.js') ?>"></script>
