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
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->Port = MAIL_PORT;
            $mail->CharSet = 'UTF-8';

            // Remitente y destinatario
            $mail->setFrom(
                MAIL_FROM_ADDRESS,
                MAIL_FROM_NAME
            );
            $mail->addAddress(
                $correoDestino,
                $nombreDestino
            );

            $mail->isHTML(true);
            $mail->Subject = 'Recuperación de acceso | Sistema Comercial';

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
            $urlLoginSeguro = htmlspecialchars(
                $urlLogin,
                ENT_QUOTES,
                'UTF-8'
            );

            // El logo se incrusta en el propio correo para evitar depender de que
            // el cliente de correo permita cargar imágenes remotas.
            $logoHtml = $this->marcaTextoFallback();
            $rutaLogo = ROOT_PATH . '/public/img/brand/porcayo-grupo.png';

            if (is_file($rutaLogo)) {
                try {
                    $mail->addEmbeddedImage(
                        $rutaLogo,
                        'porcayo_grupo_logo',
                        'porcayo-grupo.png',
                        'base64',
                        'image/png'
                    );
                    $logoHtml = '<img src="cid:porcayo_grupo_logo" '
                        . 'alt="Porcayo Learning Group" width="168" '
                        . 'style="display:block;width:168px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;margin:0 auto;">';
                } catch (Throwable $logoError) {
                    error_log(
                        'No fue posible incrustar el logo en el correo de recuperación: ' .
                        $logoError->getMessage()
                    );
                }
            }

            $mail->Body = $this->construirCorreoPasswordTemporal(
                $nombreSeguro,
                $passwordSegura,
                $urlLoginSeguro,
                $logoHtml
            );

            // Versión para clientes que no muestran HTML.
            $mail->AltBody =
                "Sistema Comercial | Porcayo Learning Group\n\n" .
                "Hola, {$nombreDestino}.\n\n" .
                "Recibimos una solicitud para recuperar el acceso a tu cuenta.\n\n" .
                "Contraseña temporal: {$passwordTemporal}\n\n" .
                "Esta contraseña tiene una vigencia de 30 minutos. " .
                "Después de iniciar sesión deberás establecer una contraseña nueva.\n\n" .
                "Ingresar al sistema: {$urlLogin}\n\n" .
                "Si no solicitaste esta recuperación, comunícate con el administrador del sistema.";

            $mail->send();

            return true;
        } catch (Exception $e) {
            error_log(
                'Error al enviar correo de recuperación: ' .
                $mail->ErrorInfo
            );

            return false;
        }
    }

    private function construirCorreoPasswordTemporal(
        $nombreSeguro,
        $passwordSegura,
        $urlLoginSeguro,
        $logoHtml
    ) {
        return '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperación de acceso | Sistema Comercial</title>
</head>
<body style="margin:0;padding:0;background-color:#F5F7FA;font-family:Arial,Helvetica,sans-serif;color:#252525;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">
        Tu contraseña temporal para ingresar al Sistema Comercial está lista.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background-color:#F5F7FA;margin:0;padding:0;">
        <tr>
            <td align="center" style="padding:36px 14px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background-color:#FFFFFF;border:1px solid #E5E9EF;border-radius:14px;overflow:hidden;box-shadow:0 14px 34px rgba(22,34,59,0.10);">

                    <tr>
                        <td height="5" style="height:5px;background-color:#0A8F7A;font-size:0;line-height:0;">&nbsp;</td>
                    </tr>

                    <tr>
                        <td align="center" style="padding:24px 32px 20px;background-color:#FFFFFF;">
                            ' . $logoHtml . '
                            <div style="margin-top:11px;font-size:10px;line-height:1.4;font-weight:700;letter-spacing:1.8px;color:#6D7480;text-transform:uppercase;">
                                Sistema Comercial
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:27px 38px 29px;background-color:#16223B;">
                            <div style="margin:0 0 8px;font-size:10px;line-height:1.4;font-weight:700;letter-spacing:1.5px;color:#9FB7E7;text-transform:uppercase;">
                                Seguridad de la cuenta
                            </div>
                            <div style="margin:0 0 9px;font-size:25px;line-height:1.25;font-weight:700;color:#FFFFFF;">
                                Recuperación de acceso
                            </div>
                            <div style="max-width:470px;font-size:13px;line-height:1.65;color:#D8E0EF;">
                                Generamos una contraseña temporal para que puedas volver a ingresar de forma segura.
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:34px 38px 36px;background-color:#FFFFFF;">
                            <p style="margin:0 0 14px;font-size:15px;line-height:1.6;font-weight:700;color:#252525;">
                                Hola, ' . $nombreSeguro . '.
                            </p>

                            <p style="margin:0 0 24px;font-size:13.5px;line-height:1.7;color:#6D7480;">
                                Recibimos una solicitud para recuperar el acceso a tu cuenta. Utiliza la siguiente contraseña temporal para iniciar sesión en el Sistema Comercial.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:0 0 22px;background-color:#EDF2FA;border:1px solid #D8E1F3;border-radius:10px;">
                                <tr>
                                    <td align="center" style="padding:20px 18px;">
                                        <div style="margin-bottom:8px;font-size:10px;line-height:1.4;font-weight:700;letter-spacing:1.2px;color:#6D7480;text-transform:uppercase;">
                                            Contraseña temporal
                                        </div>
                                        <div style="font-family:Consolas,Monaco,\'Courier New\',monospace;font-size:26px;line-height:1.25;font-weight:700;letter-spacing:3px;color:#273A8A;word-break:break-all;">
                                            ' . $passwordSegura . '
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:0 0 26px;">
                                <tr>
                                    <td width="4" style="width:4px;background-color:#0A8F7A;border-radius:4px;font-size:0;line-height:0;">&nbsp;</td>
                                    <td style="padding:11px 14px;background-color:#F8FAFC;">
                                        <div style="font-size:12.5px;line-height:1.65;color:#596273;">
                                            <strong style="color:#252525;">Vigencia: 30 minutos.</strong><br>
                                            Después de iniciar sesión, el sistema te pedirá establecer una contraseña nueva.
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 25px;">
                                <tr>
                                    <td align="center" bgcolor="#273A8A" style="border-radius:8px;">
                                        <a href="' . $urlLoginSeguro . '" style="display:inline-block;padding:13px 26px;font-size:13px;line-height:1.2;font-weight:700;color:#FFFFFF;text-decoration:none;border-radius:8px;">
                                            Ir al Sistema Comercial
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <div style="margin:0 0 6px;font-size:11px;line-height:1.6;color:#8B94A2;text-align:center;">
                                Si el botón no funciona, copia y pega este enlace en tu navegador:
                            </div>
                            <div style="margin:0 0 26px;font-size:10.5px;line-height:1.55;color:#0563A6;text-align:center;word-break:break-all;">
                                ' . $urlLoginSeguro . '
                            </div>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background-color:#FFF8E8;border:1px solid #F3E0B2;border-radius:9px;">
                                <tr>
                                    <td style="padding:14px 16px;">
                                        <div style="margin-bottom:3px;font-size:11.5px;line-height:1.5;font-weight:700;color:#765718;">
                                            ¿No solicitaste este cambio?
                                        </div>
                                        <div style="font-size:11.5px;line-height:1.65;color:#86682A;">
                                            No compartas esta contraseña. Si no solicitaste la recuperación, comunícate con el administrador del sistema.
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 28px;background-color:#F8FAFC;border-top:1px solid #E5E9EF;text-align:center;">
                            <div style="font-size:11px;line-height:1.6;font-weight:700;color:#596273;">
                                Porcayo Learning Group · Sistema Comercial
                            </div>
                            <div style="margin-top:4px;font-size:10px;line-height:1.6;color:#8B94A2;">
                                Mensaje generado automáticamente. No respondas a este correo.
                            </div>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    private function marcaTextoFallback()
    {
        return '<div style="font-size:18px;line-height:1.3;font-weight:700;color:#16223B;text-align:center;">'
            . 'Porcayo Learning Group'
            . '</div>';
    }
}
