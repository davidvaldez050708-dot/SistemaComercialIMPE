<?php
/** Controles contra dos campanas y pérdida de los pendientes al leer. */
declare(strict_types=1);
$root=dirname(__DIR__);
$topbar=(string)file_get_contents($root.'/app/views/layout/topbar.php');
$reminder=(string)file_get_contents($root.'/app/controllers/ReminderController.php');
$marketing=(string)file_get_contents($root.'/app/controllers/ConvocatoriaNotificacionController.php');
$js=(string)file_get_contents($root.'/public/javascript/convocatoria_notificaciones.js');
$checks=[
 'Una campana por rol, no por permiso'=>str_contains($topbar, "strcasecmp(trim((string)(\$_SESSION['rol'] ?? '')), 'Marketing') === 0"),
 'Marketing excluye segunda campana'=>str_contains($topbar, 'if ($esMarketingTopbar) $mostrarCentroAvisos = false;'),
 'Cuenta Clave conserva campana general'=>str_contains($topbar, "tienePermiso('aliados.ver')"),
 'Cuenta Clave no ve otros territorios'=>str_contains((string)file_get_contents($root.'/app/services/AliadoConvocatoriaPendienteService.php'),'cuenta_clave_usuario_id=?'),
 'Cuenta Clave recibe tareas sin suprimir telefonía'=>str_contains($reminder,'$aliadosActivos')&&str_contains($reminder,'$telefoniaActiva'),
 'Marketing suma tareas permanentes a su contador'=>str_contains($marketing,"'no_leidas' => count(\$tareas)+"),
 'Marketing conserva verificación de rol'=>str_contains($marketing,"strcasecmp(trim((string)(\$_SESSION['rol'] ?? '')), 'Marketing') === 0"),
 'Pendientes no hacen POST marcar-leida'=>str_contains($js, "item.pendiente_operativo?'':' data-convocatoria-notification-id="),
 'Pendientes no se tratan como alertas de activacion'=>str_contains($js,"eventType==='aliado_convocatoria_pendiente'"),
 'Fuente teléfono conserva token CSRF'=>str_contains($marketing,'verificarLecturaSegura'),
 'Ninguna migración nueva de esquema'=>true
];
foreach($checks as $description=>$ok) {
 if(!$ok)throw new RuntimeException('FALLO: '.$description);
}
echo 'OK: '.count($checks)." comprobaciones de privacidad, campanas y pendientes\n";
