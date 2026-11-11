<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/TelefoniaPresenciaRitmo.php';

function exigir(bool $ok, string $caso): void
{
    if (!$ok) {
        throw new RuntimeException('Falló: ' . $caso);
    }
}
$sesion = [];
exigir(TelefoniaPresenciaRitmo::debeActualizar($sesion, 10, true, 100), 'primera presencia');
exigir(TelefoniaPresenciaRitmo::debeActualizar($sesion, 10, true, 101), 'fallo sin confirmar');
TelefoniaPresenciaRitmo::confirmar($sesion, 10, true, 100);
exigir(!TelefoniaPresenciaRitmo::debeActualizar($sesion, 10, true, 105), 'segunda pestaña, mismo estado');
exigir(TelefoniaPresenciaRitmo::debeActualizar($sesion, 10, true, 106), 'heartbeat a los seis segundos');
exigir(TelefoniaPresenciaRitmo::debeActualizar($sesion, 10, false, 101), 'cambio a ocupado');
exigir(TelefoniaPresenciaRitmo::debeActualizar($sesion, 11, true, 101), 'cambio de usuario');
exigir(TelefoniaPresenciaRitmo::debeActualizar($sesion, 10, true, 99), 'reloj retrocedido');
TelefoniaPresenciaRitmo::invalidar($sesion);
exigir(TelefoniaPresenciaRitmo::debeActualizar($sesion, 10, true, 102), 'PRESENCIA invalida el control');
echo "PASS: heartbeat, pestañas compartidas, cambio de estado, fallo y sesión.\n";
