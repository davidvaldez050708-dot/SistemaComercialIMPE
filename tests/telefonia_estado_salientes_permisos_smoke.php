<?php
/** Comprobaciones sin MySQL ni proveedor Zadarma. */
declare(strict_types=1);
$root = dirname(__DIR__);
$s = (string)file_get_contents($root.'/prueba_telefonia/api/zadarma_estado_llamada.php');
$host = (string)file_get_contents($root.'/public/javascript/telefonia_host.js');
$tests = [
 'Sin bloqueo por nombre del rol' =>
     !str_contains($s,"['Analista de Datos', 'Asesor de Ventas']") &&
     !str_contains($s,'Tu perfil no tiene acceso al estado telefónico.'),
 'Solo sesiones autorizadas' =>
     str_contains($s, 'if ($usuarioId <= 0)') &&
     str_contains($s,"tienePermiso('telefonia.usar')") &&
     str_contains($s,"tienePermiso('telefonia.salientes')") &&
     str_contains($s,'obtenerCodigosPermisosPorRol('),
 'Extensión propia activa para marcar' =>
     str_contains($s,'->resolverParaUsuario($usuarioId)') &&
     str_contains($s,"empty(\$asignacionTelefonica['permite_salientes'])"),
 'Consulta PBX siempre filtrada por extensión' =>
     str_contains($s,'ultimoInicioSalienteId($extension)') &&
     str_contains($s,'buscarInicioSalienteReciente(') &&
     str_contains($s,'$extension,'),
 'El SDK consulta el checkpoint antes de marcar' =>
     str_contains($host,"checkpoint: '1'") &&
     str_contains($host,'await fetchDialCheckpoint(destination)') &&
     str_contains($host,'window.zdrmWebPhone.regToCall('),
];
foreach ($tests as $name => $ok) {
 if (!$ok) { fwrite(STDERR,"FALLO: $name\n"); exit(1); }
}
echo 'Estado saliente: OK ('.count($tests)." verificaciones)\n";
