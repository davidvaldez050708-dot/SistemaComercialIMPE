<?php
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../index.php?controller=login&action=mostrarLogin');
    exit;
}

$configPath = dirname(__DIR__) . '/config/voip_config.php';
$config = is_file($configPath) ? require $configPath : [];
$callerId = trim((string)($config['caller_id'] ?? ''));

function telefonoMascara(string $numero): string
{
    if ($numero === '') {
        return 'No configurado';
    }

    $limpio = preg_replace('/\D+/', '', $numero) ?? '';
    if (strlen($limpio) <= 4) {
        return $numero;
    }

    return '••••••' . substr($limpio, -4);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prueba de Telefonía IMPE</title>
    <link rel="stylesheet" href="assets/telefonia.css">
</head>
<body>
<div class="phone-shell">
    <header class="phone-header">
        <div>
            <p class="eyebrow">PRUEBA AISLADA · NO PRODUCTIVA</p>
            <h1>Telefonía IMPE</h1>
            <p class="subtitle">Llamada real desde navegador con historial y grabación.</p>
        </div>
        <a class="back-link" href="../index.php?controller=home&action=index">Volver al sistema</a>
    </header>

    <?php if (!is_file($configPath)): ?>
        <div class="alert alert-warning">
            Falta <strong>config/voip_config.php</strong>. Copia el archivo de ejemplo y completa los datos locales antes de iniciar el dispositivo.
        </div>
    <?php endif; ?>

    <main class="phone-grid">
        <section class="card dialer-card">
            <div class="card-heading">
                <div>
                    <span class="status-dot" id="status-dot"></span>
                    <span id="device-status">Dispositivo sin iniciar</span>
                </div>
                <span class="caller-pill">Salida: <?= htmlspecialchars(telefonoMascara($callerId), ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <label for="phone-number">Número destino</label>
            <input id="phone-number" type="tel" autocomplete="off" placeholder="+52XXXXXXXXXX">
            <p class="field-help">Usa formato internacional, por ejemplo +52 seguido de 10 dígitos.</p>

            <div class="call-state" id="call-state">
                <span id="call-label">Listo para iniciar</span>
                <strong id="call-timer">00:00</strong>
            </div>

            <div class="volume-wrap" id="volume-wrap" hidden>
                <div>
                    <span>Micrófono</span>
                    <div class="meter"><i id="mic-meter"></i></div>
                </div>
                <div>
                    <span>Audio recibido</span>
                    <div class="meter"><i id="speaker-meter"></i></div>
                </div>
            </div>

            <div class="actions">
                <button type="button" class="btn btn-secondary" id="btn-init">Iniciar teléfono</button>
                <button type="button" class="btn btn-primary" id="btn-call" disabled>Llamar</button>
                <button type="button" class="btn btn-secondary" id="btn-mute" disabled>Silenciar</button>
                <button type="button" class="btn btn-danger" id="btn-hangup" disabled>Colgar</button>
            </div>

            <div class="event-log" id="event-log" aria-live="polite"></div>
        </section>

        <section class="card history-card">
            <div class="section-title-row">
                <div>
                    <p class="eyebrow">REGISTRO REAL DE TWILIO</p>
                    <h2>Últimas llamadas</h2>
                </div>
                <button type="button" class="btn btn-small" id="btn-refresh">Actualizar</button>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Destino</th>
                        <th>Estado</th>
                        <th>Duración</th>
                    </tr>
                    </thead>
                    <tbody id="calls-body">
                    <tr><td colspan="4" class="empty">Cargando historial…</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="section-title-row recordings-title">
                <div>
                    <p class="eyebrow">AUDIO</p>
                    <h2>Grabaciones recientes</h2>
                </div>
            </div>

            <div id="recordings-list" class="recordings-list">
                <p class="empty">Cargando grabaciones…</p>
            </div>
        </section>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/@twilio/voice-sdk@2.0.1/dist/twilio.min.js"></script>
<script src="assets/telefonia.js"></script>
</body>
</html>
