<?php

require_once ROOT_PATH . '/vendor/autoload.php';
require_once ROOT_PATH . '/config/mail_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailService
{
    public function enviarPasswordTemporal(
        $correoDestino,
        $nombreDestino,
        $passwordTemporal
    ) {
        $mail = new PHPMailer(true);

        try {

            // Configuración SMTP
            $mail->isSMTP();

            $mail->Host = MAIL_HOST;
            $mail->SMTPAuth = true;

            $mail->Username = MAIL_USERNAME;
            $mail->Password = MAIL_PASSWORD;

            if (MAIL_ENCRYPTION === 'ssl') {
                $mail->SMTPSecure =
                    PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure =
                    PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->Port = MAIL_PORT;

            // Codificación
            $mail->CharSet = 'UTF-8';

            // Remitente
            $mail->setFrom(
                MAIL_FROM_ADDRESS,
                MAIL_FROM_NAME
            );

            // Destinatario
            $mail->addAddress(
                $correoDestino,
                $nombreDestino
            );

            // Contenido
            $mail->isHTML(true);

            $mail->Subject =
                'Contraseña temporal - Portal Institucional';

            $nombreSeguro = htmlspecialchars(
                $nombreDestino,
                ENT_QUOTES,
                'UTF-8'
            );

            $passwordSegura = htmlspecialchars(
                $passwordTemporal,
                ENT_QUOTES,
                'UTF-8'
            );

            $urlLogin =
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin';

            $mail->Body = '
            <!DOCTYPE html>
            <html lang="es">

            <head>
                <meta charset="UTF-8">
            </head>

            <body style="
                margin:0;
                padding:0;
                background:#f5f7fa;
                font-family:Arial, Helvetica, sans-serif;
                color:#333333;
            ">

                <table
                    width="100%"
                    cellpadding="0"
                    cellspacing="0"
                    style="padding:30px 15px;">

                    <tr>
                        <td align="center">

                            <table
                                width="100%"
                                cellpadding="0"
                                cellspacing="0"
                                style="
                                    max-width:520px;
                                    background:#ffffff;
                                    border-radius:12px;
                                    overflow:hidden;
                                    box-shadow:0 8px 25px rgba(0,0,0,0.08);
                                ">

                                <tr>
                                    <td style="
                                        background:#123c82;
                                        padding:24px 30px;
                                        text-align:center;
                                    ">

                                        <div style="
                                            color:#ffffff;
                                            font-size:20px;
                                            font-weight:bold;
                                        ">
                                            Portal Institucional
                                        </div>

                                    </td>
                                </tr>


                                <tr>

                                    <td style="
                                        padding:32px 34px;
                                    ">

                                        <h2 style="
                                            margin:0 0 12px;
                                            font-size:20px;
                                            color:#252525;
                                        ">
                                            Recuperación de acceso
                                        </h2>

                                        <p style="
                                            margin:0 0 18px;
                                            font-size:14px;
                                            line-height:1.6;
                                            color:#606773;
                                        ">
                                            Hola, ' . $nombreSeguro . '.
                                        </p>

                                        <p style="
                                            font-size:14px;
                                            line-height:1.6;
                                            color:#606773;
                                        ">
                                            Recibimos una solicitud para
                                            recuperar el acceso a su cuenta.
                                            Utilice la siguiente contraseña
                                            temporal para iniciar sesión:
                                        </p>


                                        <div style="
                                            margin:25px 0;
                                            padding:18px;
                                            background:#f3f6fa;
                                            border-radius:8px;
                                            text-align:center;
                                        ">

                                            <div style="
                                                margin-bottom:8px;
                                                font-size:12px;
                                                color:#7b8491;
                                            ">
                                                Contraseña temporal
                                            </div>

                                            <div style="
                                                font-size:22px;
                                                font-weight:bold;
                                                letter-spacing:2px;
                                                color:#123c82;
                                            ">
                                                ' . $passwordSegura . '
                                            </div>

                                        </div>


                                        <p style="
                                            font-size:13px;
                                            line-height:1.6;
                                            color:#606773;
                                        ">
                                            Esta contraseña tiene una vigencia
                                            de <strong>30 minutos</strong>.
                                            Después de iniciar sesión, el
                                            sistema solicitará establecer una
                                            contraseña nueva.
                                        </p>


                                        <div style="
                                            margin:25px 0;
                                            text-align:center;
                                        ">

                                            <a
                                                href="' . $urlLogin . '"
                                                style="
                                                    display:inline-block;
                                                    padding:12px 25px;
                                                    background:#123c82;
                                                    color:#ffffff;
                                                    text-decoration:none;
                                                    border-radius:6px;
                                                    font-size:13px;
                                                    font-weight:bold;
                                                ">

                                                Iniciar sesión

                                            </a>

                                        </div>


                                        <div style="
                                            margin-top:25px;
                                            padding:14px 16px;
                                            background:#fff8e8;
                                            border-radius:7px;
                                            font-size:12px;
                                            line-height:1.6;
                                            color:#765718;
                                        ">

                                            Si usted no solicitó esta
                                            recuperación, comuníquese con el
                                            administrador del sistema.

                                        </div>

                                    </td>

                                </tr>


                                <tr>

                                    <td style="
                                        padding:18px 30px;
                                        border-top:1px solid #edf0f4;
                                        text-align:center;
                                        font-size:11px;
                                        color:#969da7;
                                    ">

                                        Mensaje generado automáticamente
                                        por el Portal Institucional.

                                    </td>

                                </tr>

                            </table>

                        </td>
                    </tr>

                </table>

            </body>

            </html>';

            // Versión para clientes que no muestran HTML
            $mail->AltBody =
                "Hola, {$nombreDestino}.\n\n" .
                "Su contraseña temporal es: {$passwordTemporal}\n\n" .
                "Tiene una vigencia de 30 minutos.\n" .
                "Ingrese al Portal Institucional y cambie su contraseña.";

            $mail->send();

            return true;

        } catch (Exception $e) {

            error_log(
                'Error al enviar correo: ' .
                $mail->ErrorInfo
            );

            return false;
        }
    }
}