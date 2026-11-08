#!/usr/bin/env python3
"""Garantiza que sólo la personalización de Hostinger ya revisada es admitida.

No copia, publica ni modifica PermissionHelper.php. Reconstruye EN MEMORIA
la variante según el diff aportado por el responsable del sitio, comprueba
su SHA y exige la integridad de las otras cuatro dependencias.
"""
import hashlib
import json
from pathlib import Path

root=Path(__file__).resolve().parents[1]
original=(root/'app/helpers/PermissionHelper.php').read_bytes()
text=original.decode('utf8')
changes=[
    (
        "require_once __DIR__ . '/AdminRolePolicy.php';\n",
        "require_once __DIR__ . '/AdminRolePolicy.php';\n"
        "require_once __DIR__ . '/SupervisionRolePolicy.php';\n"
    ),
    (
        " * convocatorias.crear; Cuenta Clave queda limitada por su asignación vigente.",
        " * convocatorias.crear; los roles territoriales conservan su asignación vigente."
    ),
    (
        """        $esMarketing = strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0;
        if ($esMarketing) {
            return tienePermiso('formularios_aliados.gestionar_enlaces');
        }
        return (int)($_SESSION['rol_id'] ?? 0) === 6;""",
        """        // Crear publicaciones y administrar enlaces son permisos independientes.
        return true;"""
    ),
    (
        """/** Marketing consulta Aliados nacionalmente; no hereda asignaciones de Cuenta Clave. */
if (!function_exists('puedeConsultarAliadosNacional')) {
    function puedeConsultarAliadosNacional()
    {
        return (int)($_SESSION['rol_id'] ?? 0) === 1 ||
            strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0;
    }
}""",
        """/** Sólo los tres roles operativos necesitan una asignación territorial vigente. */
function tipoAsignacionTerritorialRol(): ?string
{
    return [3 => 'ASESOR', 4 => 'ANALISTA_DATOS', 6 => 'CUENTA_CLAVE']
        [(int)($_SESSION['rol_id'] ?? 0)] ?? null;
}

/** Alcance de consulta: cada operación sigue exigiendo su permiso propio. */
if (!function_exists('puedeConsultarAliadosNacional')) {
    function puedeConsultarAliadosNacional()
    {
        return (int)($_SESSION['usuario_id'] ?? 0) > 0 &&
            (int)($_SESSION['rol_id'] ?? 0) > 0 &&
            tipoAsignacionTerritorialRol() === null;
    }
}"""
    )
]
for old,new in changes:
    assert text.count(old)==1, 'El código base de PermissionHelper cambió: abortar'
    text=text.replace(old,new)

h=lambda x: hashlib.sha256(x).hexdigest()
expected='1ef40e3df10307ec89d047c32a373c8244fd03e83828200d63af9d34680f5bc2'
assert h(text.encode('utf8'))==expected, (
    'El SHA de la variante no coincide exactamente con el diff de Hostinger'
)

manifest=json.loads(
    (root/'tools/prospectos_meta_historial_permisos_release.json').read_text(encoding='utf8')
)
assert manifest['schema']==1
assert manifest['package']=='impe_prospectos_meta_historial_permisos'
assert len(manifest['files'])==3 and len(manifest['dependencies'])==5

deps={x['path']:x['sha256'] for x in manifest['dependencies']}
assert deps['app/helpers/PermissionHelper.php']==expected
assert deps['app/helpers/AdminRolePolicy.php']==h(
    (root/'app/helpers/AdminRolePolicy.php').read_bytes()
), 'Se alteró AdminRolePolicy'
for path in [
    'app/services/ProspectoMetaImportador.php',
    'app/services/ProspectoMetaFormularioService.php',
    'app/views/prospectos/partials/historial_importaciones_meta.php'
]:
    assert deps[path]==h((root/path).read_bytes()), (
        'Se alteró dependencia de sólo lectura: '+path
    )
allowed={
    'app/controllers/ProspectoController.php',
    'app/views/prospectos/central.php',
    'app/services/ProspectoMetaImportacionesHistorial.php'
}
assert {item['path'] for item in manifest['files']}==allowed
for item in manifest['files']:
    assert h((root/item['path']).read_bytes())==item['sha256']
print('OK: variante Hostinger coincide byte a byte con el diff;')
print('OK: tres archivos editables, cinco dependencias verificadas;')
print('OK: PermissionHelper.php y SupervisionRolePolicy.php NO se publican.')
