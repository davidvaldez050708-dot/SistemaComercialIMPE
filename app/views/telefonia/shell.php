<?php
/**
 * Navegación persistente. No sustituye permisos ni acciones de ningún módulo:
 * cada GET del iframe pasa por index.php y sus controles habituales.
 * El motor WebRTC está en otro iframe hermano y no se recarga al navegar.
 */
$usuarioShell = (int)($_SESSION['usuario_id'] ?? 0);
$extensionShell = trim((string)($asignacionTelefonica['extension'] ?? ''));
$marketingShell = strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0 &&
    !empty($asignacionTelefonica['permite_entrantes']) &&
    tienePermiso('telefonia.recibir');
$nombreShell = trim((string)(($_SESSION['nombre'] ?? '') . ' ' . ($_SESSION['apellidos'] ?? '')));
$nombreShell = $nombreShell !== '' ? $nombreShell : 'Usuario';
?>
<!doctype html>
<html lang="es" data-impe-telephony-shell="1">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="same-origin">
    <title>Sistema Comercial · Telefonía integrada</title>
    <?php require __DIR__ . '/../layout/favicon.php'; ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/telefonia_shell.css?v=<?= filemtime(ROOT_PATH . '/public/css/telefonia_shell.css') ?>">
</head>
<body>
    <main class="impe-phone-shell" aria-label="Sistema comercial">
        <iframe
            id="impeCrmFrame"
            title="Sistema Comercial"
            src="<?= htmlspecialchars(BASE_URL . 'index.php?controller=home&action=index', ENT_QUOTES, 'UTF-8') ?>"
            allow="microphone; autoplay"
            loading="eager"></iframe>
    </main>
    <aside class="impe-phone-status" data-shell-status role="status" aria-live="polite" hidden>
        <span data-shell-status-text></span>
        <button type="button" data-shell-retry hidden>Conectar teléfono</button>
        <a class="impe-phone-classic-link"
           href="<?= htmlspecialchars(BASE_URL . 'index.php?controller=home&action=index', ENT_QUOTES, 'UTF-8') ?>"
           title="Volver al CRM sin navegación persistente">Modo clásico</a>
    </aside>
    <aside class="impe-phone-call" data-shell-call hidden aria-label="Teléfono institucional">
        <div class="impe-phone-call-header">
            <div>
                <strong data-shell-call-title>Teléfono institucional</strong>
                <small data-shell-call-status>Extensión <?= htmlspecialchars($extensionShell, ENT_QUOTES, 'UTF-8') ?></small>
            </div>
            <button type="button" data-shell-minimize title="Minimizar controles sin colgar" aria-label="Minimizar controles de llamada">−</button>
        </div>
        <div class="impe-phone-call-host" data-shell-host-region></div>
    </aside>
    <button type="button" class="impe-phone-restore" data-shell-restore hidden>
        <span aria-hidden="true">☎</span> Mostrar llamada
    </button>
    <script>
        window.IMPE_PHONE_SHELL_CONFIG = <?= json_encode([
            'userId' => $usuarioShell,
            'extension' => $extensionShell,
            'autoReception' => $marketingShell,
            'hostUrl' => BASE_URL . 'index.php?controller=telefonia&action=host&display=embed',
            'loginUrl' => BASE_URL . 'index.php?controller=login&action=mostrarLogin',
            'homeUrl' => BASE_URL . 'index.php?controller=home&action=index'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="<?= BASE_URL ?>public/javascript/telefonia_shell.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/telefonia_shell.js') ?>"></script>
</body>
</html>
