<?php
http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php require __DIR__ . '/app/views/layout/favicon.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="index,follow">
    <title>Política de privacidad | Sistema Comercial</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f4f7fb;
            --surface: #ffffff;
            --text: #1f2937;
            --muted: #64748b;
            --line: #e2e8f0;
            --primary: #263f9b;
            --primary-soft: #eef2ff;
            --success-soft: #ecfdf5;
            --success: #047857;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Inter, "Segoe UI", Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.65;
        }

        .privacy-shell {
            width: min(980px, calc(100% - 32px));
            margin: 0 auto;
            padding: 48px 0 64px;
        }

        .privacy-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: 0 18px 50px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }

        .privacy-hero {
            padding: 36px 40px 30px;
            border-bottom: 1px solid var(--line);
            background: linear-gradient(135deg, #ffffff 0%, #f7f8ff 100%);
        }

        .privacy-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            padding: 6px 10px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        h1 {
            margin: 0;
            font-size: clamp(30px, 5vw, 44px);
            line-height: 1.1;
            letter-spacing: -0.03em;
        }

        .privacy-subtitle {
            max-width: 760px;
            margin: 14px 0 0;
            color: var(--muted);
            font-size: 16px;
        }

        .privacy-status {
            margin-top: 22px;
            padding: 14px 16px;
            border-radius: 14px;
            background: var(--success-soft);
            color: var(--success);
            font-size: 14px;
        }

        .privacy-content {
            padding: 34px 40px 42px;
        }

        .privacy-section + .privacy-section {
            margin-top: 32px;
            padding-top: 30px;
            border-top: 1px solid var(--line);
        }

        h2 {
            margin: 0 0 12px;
            font-size: 21px;
            line-height: 1.25;
        }

        p {
            margin: 0 0 12px;
        }

        ul {
            margin: 10px 0 0;
            padding-left: 22px;
        }

        li + li {
            margin-top: 7px;
        }

        .privacy-note {
            margin-top: 18px;
            padding: 16px 18px;
            border-left: 4px solid var(--primary);
            border-radius: 10px;
            background: #f8fafc;
            color: #475569;
        }

        .privacy-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px 24px;
            margin-top: 18px;
            color: var(--muted);
            font-size: 13px;
        }

        .privacy-footer {
            padding: 20px 40px;
            border-top: 1px solid var(--line);
            background: #fbfcfe;
            color: var(--muted);
            font-size: 13px;
        }

        @media (max-width: 640px) {
            .privacy-shell {
                width: min(100% - 20px, 980px);
                padding: 18px 0 32px;
            }

            .privacy-hero,
            .privacy-content,
            .privacy-footer {
                padding-left: 22px;
                padding-right: 22px;
            }
        }
    </style>
</head>
<body>
    <main class="privacy-shell">
        <article class="privacy-card">
            <header class="privacy-hero">
                <div class="privacy-kicker">Privacidad</div>
                <h1>Política de privacidad</h1>
                <p class="privacy-subtitle">
                    Esta política describe de manera general cómo el Sistema Comercial
                    trata la información utilizada para la gestión de contactos,
                    seguimiento comercial y comunicaciones, incluida la integración
                    de prueba con WhatsApp Business Platform.
                </p>

                <div class="privacy-status">
                    La aplicación se encuentra actualmente en una etapa de desarrollo y pruebas.
                    Antes de una puesta en producción, esta política deberá revisarse y adaptarse
                    a los procesos, responsables y obligaciones legales definitivos.
                </div>

                <div class="privacy-meta">
                    <span><strong>Aplicación:</strong> SistemaComercialIMPE Pruebas</span>
                    <span><strong>Última actualización:</strong> 2 de octubre de 2026</span>
                </div>
            </header>

            <div class="privacy-content">
                <section class="privacy-section">
                    <h2>1. Información que puede tratarse</h2>
                    <p>
                        Dependiendo del uso autorizado del sistema, pueden tratarse datos
                        relacionados con contactos y organizaciones, entre ellos:
                    </p>
                    <ul>
                        <li>Nombre, cargo, organización y datos de contacto.</li>
                        <li>Teléfono, correo electrónico y datos de comunicación.</li>
                        <li>Información de seguimiento comercial, actividades y observaciones.</li>
                        <li>Mensajes enviados o recibidos mediante canales integrados, cuando corresponda.</li>
                        <li>Identificadores técnicos necesarios para operar integraciones, como identificadores de mensajes o canales.</li>
                        <li>Registros técnicos y de auditoría necesarios para seguridad, diagnóstico y trazabilidad.</li>
                    </ul>
                </section>

                <section class="privacy-section">
                    <h2>2. Finalidades del tratamiento</h2>
                    <p>La información puede utilizarse para:</p>
                    <ul>
                        <li>Administrar relaciones y procesos de seguimiento comercial o institucional.</li>
                        <li>Organizar conversaciones, actividades, reuniones y acciones de seguimiento.</li>
                        <li>Enviar y recibir comunicaciones autorizadas a través de canales habilitados.</li>
                        <li>Realizar pruebas técnicas, validaciones de integración y solución de errores.</li>
                        <li>Proteger la seguridad del sistema y mantener registros de auditoría.</li>
                    </ul>
                </section>

                <section class="privacy-section">
                    <h2>3. Uso de WhatsApp Business Platform</h2>
                    <p>
                        El sistema puede integrarse con WhatsApp Business Platform para gestionar
                        comunicaciones desde una interfaz centralizada. Durante la etapa de pruebas
                        pueden utilizarse números, plantillas y destinatarios de prueba proporcionados
                        o autorizados en la plataforma de Meta.
                    </p>
                    <p>
                        Para operar esta integración pueden procesarse números de teléfono,
                        identificadores de mensajes, contenido de las conversaciones y estados
                        técnicos de entrega. La información intercambiada a través de WhatsApp
                        también está sujeta a las condiciones y políticas aplicables de Meta y WhatsApp.
                    </p>
                </section>

                <section class="privacy-section">
                    <h2>4. Compartición de información</h2>
                    <p>
                        La aplicación no está diseñada para vender datos personales ni para
                        comercializarlos con terceros. La información podrá ser tratada por
                        proveedores tecnológicos únicamente cuando sea necesario para prestar
                        funciones del sistema, por ejemplo infraestructura, correo electrónico
                        o plataformas de mensajería integradas.
                    </p>
                </section>

                <section class="privacy-section">
                    <h2>5. Seguridad</h2>
                    <p>
                        Se aplican controles técnicos y organizativos orientados a limitar el acceso
                        a usuarios autorizados, proteger credenciales de integración, registrar
                        eventos relevantes y reducir el riesgo de acceso, modificación o divulgación
                        no autorizada.
                    </p>
                </section>

                <section class="privacy-section">
                    <h2>6. Conservación</h2>
                    <p>
                        La información se conserva durante el tiempo necesario para cumplir las
                        finalidades operativas, de seguimiento, seguridad y auditoría aplicables.
                        En entornos de prueba, los datos pueden eliminarse al finalizar las pruebas
                        o cuando dejen de ser necesarios.
                    </p>
                </section>

                <section class="privacy-section">
                    <h2>7. Solicitudes de privacidad y eliminación de datos</h2>
                    <p>
                        Las personas pueden solicitar información, corrección o eliminación de sus
                        datos cuando resulte aplicable. Durante esta etapa de pruebas, las solicitudes
                        deberán dirigirse al correo de contacto registrado para la aplicación en Meta.
                    </p>

                    <div class="privacy-note">
                        Antes del uso productivo se publicará un procedimiento específico y permanente
                        para solicitudes de eliminación de datos y ejercicio de derechos.
                    </div>
                </section>

                <section class="privacy-section">
                    <h2>8. Cambios a esta política</h2>
                    <p>
                        Esta política puede actualizarse conforme cambien las funciones del sistema,
                        los proveedores tecnológicos utilizados o los requisitos legales y operativos.
                        La fecha de última actualización se mostrará en esta misma página.
                    </p>
                </section>
            </div>

            <footer class="privacy-footer">
                Sistema Comercial · Documento informativo para la etapa de desarrollo y pruebas.
            </footer>
        </article>
    </main>
</body>
</html>
