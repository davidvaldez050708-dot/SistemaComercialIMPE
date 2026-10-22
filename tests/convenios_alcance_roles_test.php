<?php
/**
 * Regresión de permisos: PHP puro, sin base de datos ni escrituras.
 * Ejecutar: php tests/convenios_alcance_roles_test.php
 */
require_once __DIR__ . '/../app/helpers/PermissionHelper.php';
$casos = 0;
function comprobarConvenios($condicion, $mensaje) {
    global $casos;
    if (!$condicion) { throw new RuntimeException($mensaje); }
    ++$casos;
}
function sesionConvenios($rolId, $rol, array $permisos) {
    $_SESSION = [
        'usuario_id'=>1001,
        'rol_id'=>$rolId,
        'rol'=>$rol,
        'permisos'=>$permisos
    ];
}
sesionConvenios(6,'Cuenta Clave',['convenios.ver']);
comprobarConvenios(puedeConsultarDirectorioConvenios(), 'Cuenta Clave puede ver convenios');
comprobarConvenios(!puedeCrearConvocatoriaDesdeConvenio(), 'Ver convenios no concede publicar');
comprobarConvenios(!puedeConsultarAliadosNacional(), 'Cuenta Clave no ve los 32 estados');

sesionConvenios(6,'Cuenta Clave',['convenios.ver','convocatorias.crear']);
comprobarConvenios(!puedeCrearConvocatoriaDesdeConvenio(), 'Sin convocatorias.ver no puede abrir Gestión');
sesionConvenios(6,'Cuenta Clave',['convenios.ver','convocatorias.ver','convocatorias.crear']);
comprobarConvenios(puedeCrearConvocatoriaDesdeConvenio(), 'Cuenta Clave puede crear con permiso explícito');
comprobarConvenios(!puedeConsultarAliadosNacional(), 'Crear no concede cobertura nacional');

sesionConvenios(6,'Cuenta Clave',['convocatorias.ver','convocatorias.crear']);
comprobarConvenios(!puedeCrearConvocatoriaDesdeConvenio(), 'Sin permiso Convenios no crea desde directorio');

sesionConvenios(5,'Marketing',['aliados.ver','convenios.ver','convocatorias.ver','convocatorias.crear','formularios_aliados.gestionar_enlaces']);
comprobarConvenios(puedeConsultarAliadosNacional(), 'Marketing consulta 32 estados');
comprobarConvenios(puedeCrearConvocatoriaDesdeConvenio(), 'Marketing puede crear con permisos completos');

sesionConvenios(5,'Marketing',['aliados.ver','convenios.ver','convocatorias.ver','convocatorias.crear']);
comprobarConvenios(!puedeCrearConvocatoriaDesdeConvenio(), 'Marketing no administra enlaces sin permiso');

sesionConvenios(1,'Administrador',['aliados.ver','convenios.ver','convocatorias.ver','convocatorias.crear','formularios_aliados.gestionar_enlaces']);
comprobarConvenios(puedeConsultarAliadosNacional(), 'Administrador consulta Aliados nacionalmente');
comprobarConvenios(!puedeCrearConvocatoriaDesdeConvenio(), 'Administrador no realiza acción operativa');

sesionConvenios(4,'Analista de Datos',['convenios.ver','convocatorias.ver','convocatorias.crear']);
comprobarConvenios(!puedeCrearConvocatoriaDesdeConvenio(), 'Analista no hereda creación de Cuenta Clave');
comprobarConvenios(!puedeConsultarAliadosNacional(), 'Analista no obtiene cobertura nacional');
echo "OK: $casos verificaciones de permisos Convenios y Aliados. SQL NO ejecutado.\n";
