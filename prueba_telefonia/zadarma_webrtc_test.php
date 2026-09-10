<?php
session_start();

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$rolId = (int)($_SESSION['rol_id'] ?? 0);

if ($usuarioId <= 0) {
    http_response_code(401);
    exit('Sesión no activa. Inicia sesión en SistemaComercialIMPE desde este mismo dominio.');
}

if ($rolId !== 4) {
    http_response_code(403);
    exit('Esta prueba técnica está habilitada únicamente para el rol Analista.');
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prueba WebRTC Zadarma | SistemaComercialIMPE</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Arial, sans-serif;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: #f5f6f8;
            color: #20242a;
        }

        .wrap {
            max-width: 760px;
            margin: 48px auto;
            padding: 0 20px;
        }

        .card {
            background: #fff;
            border: 1px solid #e3e6ea;
            border-radius: 14px;
            padding: 28px;
            box-shadow: 0 12px 32px rgba(0, 0, 0, .06);
        }

        h1 {
            margin: 0 0 10px;
            font-size: 24px;
        }

        p {
            line-height: 1.55;
            margin: 8px 0;
        }

        .status {
            margin-top: 20px;
            padding: 14px 16px;
            border-radius: 10px;
            background: #f0f4f8;
            border: 1px solid #dbe3ec;
        }

        .status.ok {
            background: #edf8f0;
            border-color: #c7e6cf;
        }

        .status.error {
            background: #fff1f1;
            border-color: #efcaca;
        }

        .meta {
            margin-top: 18px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .meta div {
            padding: 12px;
            border-radius: 9px;
            background: #fafbfc;
            border: 1px solid #edf0f2;
        }

        .meta small {
            display: block;
            color: #69717c;
            margin-bottom: 4px;
        }

        code {
            word-break: break-all;
        }

        @media (max-width: 560px) {
            .meta {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Prueba técnica WebRTC · Zadarma</h1>
        <p>Esta pantalla no reemplaza todavía la telefonía de Twilio. Sirve únicamente para comprobar que la extensión PBX de Zadarma puede operar dentro del dominio HTTPS de SistemaComercialIMPE.</p>
        <p>Cuando el teléfono aparezca en la esquina inferior derecha, realiza una llamada corta al mismo número de prueba que utilizaste en el teléfono web de Zadarma.</p>

        <div id="zadarmaStatus" class="status">Preparando extensión y solicitando clave WebRTC…</div>

        <div class="meta">
            <div>
                <small>Usuario del sistema</small>
                <strong>#<?= htmlspecialchars((string)$usuarioId, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div>
                <small>Extensión Zadarma</small>
                <strong id="zadarmaExtension">—</strong>
            </div>
        </div>
    </div>
</div>

<script src="https://my.zadarma.com/webphoneWebRTCWidget/v8/js/loader-phone-lib.js?v=17"></script>
<script src="https://my.zadarma.com/webphoneWebRTCWidget/v8/js/loader-phone-fn.js?v=17"></script>
<script>
(function () {
    'use strict';

    const status = document.getElementById('zadarmaStatus');
    const extensionEl = document.getElementById('zadarmaExtension');

    const setStatus = function (message, type) {
        status.textContent = message;
        status.classList.remove('ok', 'error');
        if (type) {
            status.classList.add(type);
        }
    };

    const init = async function () {
        try {
            if (typeof window.zadarmaWidgetFn !== 'function') {
                throw new Error('No se cargó el componente WebRTC de Zadarma.');
            }

            const response = await fetch('api/zadarma_webrtc.php', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'fetch'
                },
                credentials: 'same-origin',
                cache: 'no-store'
            });

            const data = await response.json();

            if (!response.ok || !data.ok || !data.webrtc_key || !data.sip_login) {
                throw new Error(data.mensaje || 'No fue posible preparar WebRTC.');
            }

            extensionEl.textContent = data.extension || '—';

            window.zadarmaWidgetFn(
                data.webrtc_key,
                data.sip_login,
                'rounded',
                'es',
                true,
                "{right:'18px',bottom:'18px'}"
            );

            setStatus(
                'WebRTC preparado correctamente. Abre el teléfono de Zadarma en la esquina inferior derecha y realiza una llamada corta.',
                'ok'
            );
        } catch (error) {
            console.error(error);
            setStatus(error.message || 'Error preparando WebRTC.', 'error');
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
</script>
</body>
</html>
