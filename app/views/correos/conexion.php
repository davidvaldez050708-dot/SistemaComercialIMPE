<?php $escMail = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }; ?>
<section class="dashboard-panel">
    <h2 class="panel-title">Conectar cuenta de correo</h2>
    <p>Autoriza la dirección que utilizarás en el campo De. Tu sesión y tus permisos del CRM no cambian.</p>
    <?php if ($notice !== ''): ?><div class="alert alert-info" role="status"><?= $escMail($notice) ?></div><?php endif; ?>
    <form method="get" action="index.php" class="mb-3">
        <input type="hidden" name="controller" value="correoConexion">
        <input type="hidden" name="action" value="index">
        <label class="form-label" for="mail-connection-address">Correo de origen</label>
        <input class="form-control" type="email" name="correo" id="mail-connection-address" value="<?= $escMail($email) ?>" autocomplete="email" maxlength="254" required>
        <button class="btn btn-system-light mt-2" type="submit">Consultar conexión</button>
    </form>
    <?php if (filter_var($email, FILTER_VALIDATE_EMAIL)): ?>
        <?php if ($connected): ?><p>Esta cuenta tiene una conexión OAuth para tu usuario.</p><?php endif; ?>
        <?php foreach (['google' => 'Google / Gmail', 'microsoft' => 'Microsoft / Outlook / Microsoft 365'] as $provider => $name): ?>
            <form method="post" action="index.php?controller=correoConexion&amp;action=iniciar" class="mb-2">
                <input type="hidden" name="csrf_token" value="<?= $escMail($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="correo" value="<?= $escMail($email) ?>">
                <input type="hidden" name="proveedor" value="<?= $escMail($provider) ?>">
                <button class="btn btn-system-save" type="submit" <?= empty($providers[$provider]) ? 'disabled' : '' ?>>Autorizar con <?= $escMail($name) ?></button>
                <?php if (empty($providers[$provider])): ?><span>Falta configurar la aplicación de este proveedor.</span><?php endif; ?>
            </form>
        <?php endforeach; ?>
        <?php if ($connected): ?>
            <form method="post" action="index.php?controller=correoConexion&amp;action=desconectar" class="mb-3">
                <input type="hidden" name="csrf_token" value="<?= $escMail($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="correo" value="<?= $escMail($email) ?>">
                <button class="btn btn-system-cancel" type="submit">Desconectar mi autorización OAuth</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
    <p>La autorización se realiza en Google o Microsoft. No escribas contraseñas ni tokens en la redacción del correo.</p>
    <p>La nueva conexión Google solicita permiso para enviar y verificar la dirección de tu cuenta; no solicita lectura ni eliminación de mensajes.</p>
    <p>Hostinger utiliza los buzones autorizados en su API o SMTP. Las cuentas institucionales con SMTP y las claves de aplicación Gmail se conectan mediante configuración privada del administrador.</p>
    <p>Autorizar la cuenta comprueba el acceso al buzón sin enviar mensajes. La recepción de correos y adjuntos se verifica después con un destinatario de prueba autorizado.</p>
</section>
