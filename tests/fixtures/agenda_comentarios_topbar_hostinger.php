<?php

require_once __DIR__ . '/../../helpers/AvatarHelper.php';
require_once __DIR__ . '/../../helpers/ReminderHelper.php';
require_once __DIR__ . '/../../helpers/TelefoniaMarketingAccessHelper.php';

$puedeSolicitarReuniones = tienePermiso('reuniones.solicitar');
$puedeGestionarReuniones = tienePermiso('reuniones.gestionar');
$esAdministradorTopbar = (int)($_SESSION['rol_id'] ?? 0) === 1;
$esAnalistaDatos =
    !$esAdministradorTopbar &&
    tienePermiso('seguimientos_vinculacion.operar_propios');
// Un permiso compartido no convierte a Cuenta Clave en Marketing.
$esMarketingTopbar =
    !$esAdministradorTopbar &&
    strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0 &&
    tienePermiso('convocatorias.gestionar');
$mostrarCentroAvisos =
    $esAdministradorTopbar ||
    (
        tienePermiso('reuniones.ver') &&
        ($puedeSolicitarReuniones || $puedeGestionarReuniones)
    ) ||
    tienePermiso('aliados.seguimiento_convocatorias') ||
    ((int)($_SESSION['rol_id'] ?? 0) === 6 &&
        tienePermiso('aliados.ver') &&
        (tienePermiso('aliados.gestionar_programas') ||
         (function_exists('puedeCrearConvocatoriaDesdeConvenio') && puedeCrearConvocatoriaDesdeConvenio())));
// Solo una campana: Marketing utiliza su centro de Convocatorias.
if ($esMarketingTopbar) {
    $mostrarCentroAvisos = false;
}
$agendaDisponible = is_file(ROOT_PATH . '/app/controllers/AgendaReunionController.php');
$mostrarAgendaReuniones =
    tienePermiso('reuniones.ver') &&
    $agendaDisponible;
$trabajarNotificacionId = (int)($_GET['trabajar_id'] ?? 0);
$puedeUsarTelefoniaTopbar = tienePermiso('telefonia.usar');
$puedeRecibirTelefoniaTopbar = tienePermiso('telefonia.recibir');
if(($esMarketingTopbar||($puedeUsarTelefoniaTopbar&&($puedeRecibirTelefoniaTopbar||((int)($_SESSION['rol_id']??0)===3&&tienePermiso('telefonia.salientes')))))&&empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));
if($puedeUsarTelefoniaTopbar&&!$esMarketingTopbar&&($puedeRecibirTelefoniaTopbar||((int)($_SESSION['rol_id']??0)===3&&tienePermiso('telefonia.salientes'))))$mostrarCentroAvisos=true;
// En Marketing, los permisos son del rol pero la recepción depende de la
// extensión personal. No ofrecer audífonos inactivos a otras cuentas.
if (strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0) {
    $puedeRecibirTelefoniaTopbar =
        $puedeRecibirTelefoniaTopbar && marketingTieneRecepcionAsignada();
}

/*
 * La campana se hidrata únicamente desde ReminderController.
 * Antes se precargaban aquí recordatorios genéricos de seguimiento y,
 * al terminar el fetch del frontend, eran sustituidos por la fuente
 * operativa completa (agenda + reuniones + acuerdos + seguimiento).
 * Eso provocaba un "flash" de estados antiguos al recargar.
 */
$totalRecordatoriosSeguimiento = 0;

?>
