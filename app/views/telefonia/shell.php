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
    <!-- La capa persistente es otro documento; no hereda las fuentes e
         iconos cargados por dashboard_head.php en el iframe del CRM. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
        rel="stylesheet">
    <!-- Estilos críticos servidos con el documento PHP. Si Hostinger bloquea
         o cachea el CSS estático, el iframe nunca queda a 300 x 150 px. -->
    <style id="impeShellStyles">
    <?php
    $shellCssPath = ROOT_PATH . '/public/css/telefonia_shell.css';
    $shellCssContent = is_readable($shellCssPath)
        ? file_get_contents($shellCssPath)
        : '';
    // El CSS procede de un archivo local controlado por el proyecto.
    echo str_ireplace('</style', '<\\/style', (string)$shellCssContent);
    ?>
    </style>
    <!-- Usar el mismo CSS del teléfono de Seguimiento (sin duplicar estilos). -->
    <style id="impePersistentPhoneStyles">
    <?php
    $persistentPhoneCss = ROOT_PATH . '/public/css/telefonia_persistente.css';
    if (is_readable($persistentPhoneCss)) {
        echo str_ireplace('</style', '<\\/style', (string)file_get_contents($persistentPhoneCss));
    }
    ?>
    </style>
    <style id="impeDialerStyles">
    <?php
    $dialerCssPath = ROOT_PATH . '/public/css/telefonia_teclado.css';
    if (is_readable($dialerCssPath)) {
        echo str_ireplace('</style', '<\\/style', (string)file_get_contents($dialerCssPath));
    }
    ?>
    </style>
</head>
<body>
    <noscript>
      <p style="margin:20px;font:600 14px system-ui">
        Activa JavaScript para usar telefonía integrada o
        <a href="<?= htmlspecialchars(BASE_URL . 'index.php?controller=home&action=index', ENT_QUOTES, 'UTF-8') ?>">
            entra al CRM clásico
        </a>.
      </p>
    </noscript>
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
        <p class="impe-phone-call-help" data-shell-call-help>
            Aquí aparecen los controles propios de Zadarma para contestar.
        </p>
        <div class="impe-phone-call-host" data-shell-host-region></div>
    </aside>
    <section class="persistent-phone-panel impe-incoming-panel" data-incoming-panel hidden
        aria-label="Recepción telefónica">
        <header class="persistent-phone-header" data-incoming-drag-handle
            title="Arrastra para mover la llamada">
            <span class="persistent-phone-live" aria-hidden="true"><i></i></span>
            <div class="persistent-phone-heading">
                <span data-incoming-eyebrow>RECEPCIÓN TELEFÓNICA</span>
                <strong data-incoming-title>Llamada entrante</strong>
            </div>
            <strong class="persistent-phone-header-timer" data-incoming-compact-timer>00:00</strong>
            <button type="button" class="persistent-phone-toggle" data-incoming-minimize
                title="Minimizar" aria-label="Minimizar llamada">
                <i class="bi bi-dash-lg" aria-hidden="true"></i><span>Minimizar</span>
            </button>
        </header>
        <div class="persistent-phone-body">
            <div class="persistent-phone-number" data-incoming-number>—</div>
            <div class="persistent-phone-origin">
                <i class="bi bi-headset" aria-hidden="true"></i>
                Desde: <strong>Extensión <?= htmlspecialchars($extensionShell, ENT_QUOTES, 'UTF-8') ?> · Telefonía IP</strong>
            </div>
            <div class="persistent-phone-state" role="status" aria-live="polite">
                <span data-incoming-state>Llamada entrante</span>
                <strong data-incoming-timer>00:00</strong>
            </div>
            <div class="persistent-phone-actions impe-incoming-actions">
                <button type="button" class="persistent-phone-action is-primary" data-incoming-answer>
                    <i class="bi bi-telephone-inbound" aria-hidden="true"></i>Contestar
                </button>
                <button type="button" class="persistent-phone-action is-danger" data-incoming-decline>
                    <i class="bi bi-telephone-x" aria-hidden="true"></i>Rechazar
                </button>
                <button type="button" class="persistent-phone-action" data-incoming-mute hidden>
                    <i class="bi bi-mic-mute" aria-hidden="true"></i>Silenciar
                </button>
                <button type="button" class="persistent-phone-action is-danger" data-incoming-hangup hidden>
                    <i class="bi bi-telephone-x" aria-hidden="true"></i>Colgar
                </button>
            </div>
            <p class="impe-incoming-notice" data-incoming-notice role="status" hidden></p>
        </div>
        <div class="persistent-phone-compact-actions">
            <button type="button" class="persistent-phone-compact-button is-primary"
                data-incoming-answer-compact title="Contestar" aria-label="Contestar">
                <i class="bi bi-telephone-inbound" aria-hidden="true"></i>
            </button>
            <button type="button" class="persistent-phone-compact-button is-danger"
                data-incoming-decline-compact title="Rechazar" aria-label="Rechazar">
                <i class="bi bi-telephone-x" aria-hidden="true"></i>
            </button>
            <button type="button" class="persistent-phone-compact-button"
                data-incoming-mute-compact title="Silenciar" aria-label="Silenciar" hidden>
                <i class="bi bi-mic-mute" aria-hidden="true"></i>
            </button>
            <button type="button" class="persistent-phone-compact-button is-danger"
                data-incoming-hangup-compact title="Colgar" aria-label="Colgar" hidden>
                <i class="bi bi-telephone-x" aria-hidden="true"></i>
            </button>
        </div>
    </section>
    <button type="button" class="impe-phone-restore" data-shell-restore hidden>
        <span aria-hidden="true">☎</span> Mostrar llamada
    </button>
    <script>
        window.IMPE_PHONE_SHELL_CONFIG = <?= json_encode([
            'userId' => $usuarioShell,
            'extension' => $extensionShell,
            'autoReception' => $marketingShell,
            'canCallOut' => tienePermiso('telefonia.salientes') &&
                !empty($asignacionTelefonica['permite_salientes']),
            'hostUrl' => BASE_URL . 'index.php?controller=telefonia&action=host&display=embed',
            'loginUrl' => BASE_URL . 'index.php?controller=login&action=mostrarLogin',
            'homeUrl' => BASE_URL . 'index.php?controller=home&action=index'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script id="impeDialerRuntime">
    <?php
    $dialerJsPath = ROOT_PATH . '/public/javascript/telefonia_teclado.js';
    if (is_readable($dialerJsPath)) {
        echo str_ireplace('</script', '<\\/script', (string)file_get_contents($dialerJsPath));
    }
    ?>
    </script>
    <!-- El controlador telefónico se incluye desde disco en la respuesta
         autenticada, sin depender de otra petición HTTP para su carga. -->
    <script id="impeShellRuntime">
    <?php
    $shellJsPath = ROOT_PATH . '/public/javascript/telefonia_shell.js';
    $shellJsContent = is_readable($shellJsPath)
        ? file_get_contents($shellJsPath)
        : '';
    echo str_ireplace('</script', '<\\/script', (string)$shellJsContent);
    ?>
    </script>
</body>
</html>
