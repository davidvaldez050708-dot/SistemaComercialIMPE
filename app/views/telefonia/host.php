<?php

$extensionHost = trim(
    (string)($asignacionTelefonica['extension'] ?? '')
);
$usuarioHost = trim(
    (string)($_SESSION['nombre'] ?? '') . ' ' .
    (string)($_SESSION['apellidos'] ?? '')
);

if ($usuarioHost === '') {
    $usuarioHost = (string)($_SESSION['usuario'] ?? 'Usuario');
}

?>
<!DOCTYPE html>
<html lang="es" data-impe-telephony-host="1">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">
    <title>Telefonía activa | Sistema Comercial</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap"
        rel="stylesheet">
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/telefonia_host.css?v=<?= filemtime(ROOT_PATH . '/public/css/telefonia_host.css') ?>">
</head>
<body
    data-telephony-host
    data-user-id="<?= (int)($_SESSION['usuario_id'] ?? 0) ?>"
    data-extension="<?= htmlspecialchars($extensionHost, ENT_QUOTES, 'UTF-8') ?>">

    <main class="telephony-host-card">
        <div class="telephony-host-icon" aria-hidden="true">
            <i class="bi bi-headset"></i>
        </div>

        <div class="telephony-host-copy">
            <span>TELEFONÍA DEL SISTEMA</span>
            <strong data-host-title>
                Preparando extensión <?= htmlspecialchars($extensionHost, ENT_QUOTES, 'UTF-8') ?>
            </strong>
            <small data-host-status>
                Mantén esta ventana abierta mientras utilices llamadas.
            </small>
        </div>

        <div class="telephony-host-live" data-host-live hidden>
            <i></i>
            <span data-host-timer>00:00</span>
        </div>
    </main>

    <p class="telephony-host-user">
        <?= htmlspecialchars($usuarioHost, ENT_QUOTES, 'UTF-8') ?>
        · Ext. <?= htmlspecialchars($extensionHost, ENT_QUOTES, 'UTF-8') ?>
    </p>

    <script>
        window.IMPE_TELEPHONY_HOST = <?= json_encode([
            'userId' => (int)($_SESSION['usuario_id'] ?? 0),
            'extension' => $extensionHost,
            'webrtcUrl' => BASE_URL . 'prueba_telefonia/api/zadarma_webrtc.php',
            'estadoUrl' => BASE_URL . 'prueba_telefonia/api/zadarma_estado_llamada.php'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script
        src="<?= BASE_URL ?>public/javascript/telefonia_host.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/telefonia_host.js') ?>">
    </script>
</body>
</html>
