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
                        . 'alt="Porcayo Learning Group" width="118" '
                        . 'style="display:block;width:118px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;">';
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

            $mail->AltBody =
                "Sistema Comercial | Porcayo Learning Group\n\n" .
                "Hola, {$nombreDestino}.\n\n" .
                "Recibimos una solicitud para restablecer el acceso a tu cuenta.\n\n" .
                "Contraseña temporal: {$passwordTemporal}\n" .
                "Vigencia: 30 minutos.\n\n" .
                "Después de iniciar sesión deberás establecer una contraseña nueva.\n\n" .
                "Ingresar al sistema: {$urlLogin}\n\n" .
                "Si no realizaste esta solicitud, comunícate con el administrador del sistema.";

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
<body style="margin:0;padding:0;background-color:#F4F6F8;font-family:Arial,Helvetica,sans-serif;color:#252525;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">
        Tu acceso temporal al Sistema Comercial está listo.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:0;padding:0;background-color:#F4F6F8;">
        <tr>
            <td align="center" style="padding:30px 14px;">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:560px;background-color:#FFFFFF;border:1px solid #E3E8EF;border-radius:12px;overflow:hidden;">

                    <tr>
                        <td height="4" style="height:4px;background-color:#0A8F7A;font-size:0;line-height:0;">&nbsp;</td>
                    </tr>

                    <tr>
                        <td style="padding:21px 30px 18px;border-bottom:1px solid #E8ECF2;background-color:#FFFFFF;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td width="130" valign="middle" style="width:130px;">
                                        ' . $logoHtml . '
                                    </td>
                                    <td valign="middle" align="right" style="padding-left:18px;">
                                        <div style="font-size:13px;line-height:1.4;font-weight:700;color:#16223B;">
                                            Sistema Comercial
                                        </div>
                                        <div style="margin-top:3px;font-size:10.5px;line-height:1.4;color:#8B94A2;">
                                            Porcayo Learning Group
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:31px 34px 34px;background-color:#FFFFFF;">
                            <div style="margin:0 0 7px;font-size:10px;line-height:1.4;font-weight:700;letter-spacing:1.2px;color:#0563A6;text-transform:uppercase;">
                                Acceso a tu cuenta
                            </div>

                            <h1 style="margin:0 0 13px;font-size:24px;line-height:1.3;font-weight:700;color:#16223B;">
                                Recuperación de acceso
                            </h1>

                            <p style="margin:0 0 9px;font-size:14px;line-height:1.65;font-weight:700;color:#252525;">
                                Hola, ' . $nombreSeguro . '.
                            </p>

                            <p style="margin:0 0 24px;font-size:13.5px;line-height:1.7;color:#626B78;">
                                Recibimos una solicitud para restablecer el acceso a tu cuenta. Usa esta contraseña temporal para ingresar al Sistema Comercial.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:0 0 24px;background-color:#F7F9FC;border:1px solid #DCE4F0;border-radius:9px;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td valign="middle">
                                                    <div style="font-size:10px;line-height:1.4;font-weight:700;letter-spacing:1px;color:#6D7480;text-transform:uppercase;">
                                                        Contraseña temporal
                                                    </div>
                                                    <div style="margin-top:7px;font-family:Consolas,Monaco,\'Courier New\',monospace;font-size:24px;line-height:1.25;font-weight:700;letter-spacing:2.5px;color:#273A8A;word-break:break-all;">
                                                        ' . $passwordSegura . '
                                                    </div>
                                                </td>
                                                <td width="118" valign="middle" align="right" style="width:118px;padding-left:15px;">
                                                    <div style="font-size:10px;line-height:1.45;color:#8B94A2;">
                                                        Vigencia
                                                    </div>
                                                    <div style="margin-top:2px;font-size:12.5px;line-height:1.45;font-weight:700;color:#16223B;">
                                                        30 minutos
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 23px;font-size:12.5px;line-height:1.7;color:#626B78;">
                                Al iniciar sesión, el sistema te pedirá crear una contraseña nueva antes de continuar.
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
                                <tr>
                                    <td bgcolor="#273A8A" style="border-radius:7px;">
                                        <a href="' . $urlLoginSeguro . '" style="display:inline-block;padding:12px 22px;font-size:13px;line-height:1.2;font-weight:700;color:#FFFFFF;text-decoration:none;border-radius:7px;">
                                            Iniciar sesión
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <div style="margin:0 0 27px;font-size:11px;line-height:1.6;color:#8B94A2;">
                                Si el botón no abre correctamente, <a href="' . $urlLoginSeguro . '" style="color:#0563A6;text-decoration:underline;">usa este enlace de acceso</a>.
                            </div>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-top:1px solid #E8ECF2;">
                                <tr>
                                    <td style="padding:20px 0 0;">
                                        <div style="font-size:11.5px;line-height:1.55;font-weight:700;color:#16223B;">
                                            Si no fuiste tú
                                        </div>
                                        <div style="margin-top:4px;font-size:11.5px;line-height:1.65;color:#7A8390;">
                                            No compartas esta contraseña. Si no realizaste la solicitud, puedes ignorar este correo y comunicarte con el administrador del sistema.
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:17px 28px;background-color:#F8FAFC;border-top:1px solid #E8ECF2;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="font-size:10.5px;line-height:1.6;color:#8B94A2;">
                                        Mensaje automático del Sistema Comercial.
                                    </td>
                                    <td align="right" style="font-size:10.5px;line-height:1.6;color:#8B94A2;">
                                        Porcayo Learning Group
                                    </td>
                                </tr>
                            </table>
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
        return '<div style="font-size:16px;line-height:1.3;font-weight:700;color:#16223B;">'
            . 'Porcayo Learning Group'
            . '</div>';
    }
}
