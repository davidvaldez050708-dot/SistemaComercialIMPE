<?php

require_once __DIR__ . '/MailService.php';
require_once __DIR__ . '/CorreoLimiteService.php';

/** Contraseña temporal al correo registrado. Nunca devuelve datos de la cuenta al visitante. */
final class CorreoRecuperacionService
{
    private $usuarios, $mail;
    public function __construct($usuarios, ?MailService $mail = null) { $this->usuarios = $usuarios; $this->mail = $mail ?? new MailService(); }

    public function solicitar(string $dato, string $ip): void
    {
        try {
            if ($dato === '' || mb_strlen($dato) > 255 || !CorreoLimiteService::tomarClave('recovery-ip', $ip ?: 'unknown', 10, 900) ||
                !CorreoLimiteService::tomarClave('recovery-input', strtolower($dato), 3, 3600)) { return; }
            $u = $this->usuarios->buscarPorUsuarioOCorreo($dato);
            if (!$u || (int)$u['estado'] !== 1 || (int)($u['rol_estado'] ?? 0) !== 1 || !filter_var($u['correo'], FILTER_VALIDATE_EMAIL)) { return; }
            // Usuario y correo pueden referirse a la misma cuenta: compartir límite y exclusión.
            if (!CorreoLimiteService::tomar('recovery-user', (int)$u['id'], 3, 3600)) { return; }
            CorreoEstadoPrivado::ejecutar('recovery', (string)$u['id'], function ($state, $save) use ($u) {
                if (in_array($state['status'] ?? '', ['sending', 'DESCONOCIDO'], true) && (int)($state['at'] ?? 0) > time() - 1800) { return; }
                $password = $this->generar();
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $save(['status' => 'sending', 'at' => time()]);
                if (!$this->mail->enviarPasswordTemporal($u['correo'], trim($u['nombre'] . ' ' . ($u['apellidos'] ?? '')), $password)) {
                    $unknown = $this->mail->ultimoResultado()['estado_entrega'] ?? 'RECHAZADO';
                    $save(['status' => $unknown === 'DESCONOCIDO' ? 'DESCONOCIDO' : 'RECHAZADO', 'at' => time()]);
                    error_log('MAIL_RECOVERY status=not_accepted'); return;
                }
                // Sólo hash en BD, después de aceptación. No hay transacción distribuida con SMTP.
                $ok = $this->usuarios->establecerPasswordTemporalSiVigente((int)$u['id'], $u['password'], $hash, date('Y-m-d H:i:s', time() + 1800));
                $save(['status' => $ok ? 'ACEPTADO' : 'REGISTRO_FALLIDO', 'at' => time()]);
                error_log('MAIL_RECOVERY status=' . ($ok ? 'accepted_and_stored' : 'accepted_not_stored'));
            });
        } catch (Throwable $e) { error_log('MAIL_RECOVERY status=unconfirmed exception=' . get_class($e)); }
    }

    private function generar(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789']; $chars = [];
        foreach ($sets as $set) { $chars[] = $set[random_int(0, strlen($set) - 1)]; }
        $all = implode('', $sets);
        while (count($chars) < 12) { $chars[] = $all[random_int(0, strlen($all) - 1)]; }
        for ($i = count($chars) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]]; }
        return implode('', $chars);
    }
}
