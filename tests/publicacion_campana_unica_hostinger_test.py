#!/usr/bin/env python3
"""Comprueba parche Hostinger sin reemplazar personalizaciones ni perder rollback."""
import json
import os
import pathlib
import subprocess
import tempfile

root = pathlib.Path(__file__).resolve().parents[1]
script = root / 'tools/publicar_campana_unica_cuenta_clave_hostinger.sh'
source = (root / 'app/views/layout/topbar.php').read_text(encoding='utf8')
old_role = """$esMarketingTopbar =
    !$esAdministradorTopbar &&
    tienePermiso('convocatorias.gestionar');"""
new_role = """// Un permiso compartido no convierte a Cuenta Clave en Marketing.
$esMarketingTopbar =
    !$esAdministradorTopbar &&
    strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0 &&
    tienePermiso('convocatorias.gestionar');"""
old_general = "    tienePermiso('aliados.seguimiento_convocatorias');"
new_general = """    tienePermiso('aliados.seguimiento_convocatorias');
// Solo una campana: Marketing utiliza su centro de Convocatorias.
if ($esMarketingTopbar) {
    $mostrarCentroAvisos = false;
}"""
assert source.count(new_role) == 1
assert source.count(new_general) == 1
before = source.replace(new_role, old_role, 1).replace(new_general, old_general, 1)
assert before != source
with tempfile.TemporaryDirectory() as temp:
    rootdir = pathlib.Path(temp)
    site = rootdir / 'public_html'
    target = site / 'app/views/layout/topbar.php'
    target.parent.mkdir(parents=True)
    (site / 'index.php').write_text('<?php', encoding='utf8')
    customization = '\n<!-- Hostinger: personalizacion ajena preservada -->\n'
    target.write_text(before + customization, encoding='utf8')
    original = target.read_bytes()
    backups = rootdir / 'private'
    def run(*args):
        env = os.environ.copy()
        env['IMPE_CRM_PUBLIC'] = str(site)
        env['IMPE_CRM_BACKUPS'] = str(backups)
        return subprocess.run(['bash', str(script)] + list(args),
                              env=env, stdout=subprocess.PIPE,
                              stderr=subprocess.PIPE, universal_newlines=True)
    check = run('--check')
    assert check.returncode == 0, (check.stdout, check.stderr)
    assert 'Archivos a modificar: 1' in check.stdout
    assert target.read_bytes() == original and not backups.exists()
    apply = run('--apply')
    assert apply.returncode == 0, (apply.stdout, apply.stderr)
    assert target.read_text(encoding='utf8') == source + customization
    folder = pathlib.Path(apply.stdout.split('Respaldo: ')[1].splitlines()[0])
    second = run('--check')
    assert second.returncode == 0 and 'Archivos a modificar: 0' in second.stdout
    restored = run('--restore', str(folder))
    assert restored.returncode == 0, (restored.stdout, restored.stderr)
    assert target.read_bytes() == original
    # Ninguna personalizacion que cambie los fragmentos se publica.
    target.write_text(before.replace("tienePermiso('convocatorias.gestionar');",
                                     "tienePermiso('otra_funcion');", 1), encoding='utf8')
    unknown = run('--check')
    assert unknown.returncode != 0 and 'CONFLICTO' in unknown.stderr
    # No se restauran modificaciones posteriores al despliegue.
    target.write_bytes(original)
    assert run('--apply').returncode == 0
    target.write_bytes(target.read_bytes() + b'\n<!-- cambio posterior -->')
    unsafe = run('--restore', str(folder))
    assert unsafe.returncode != 0 and 'CONFLICTO' in unsafe.stderr
    # CRLF completo también conserva el formato al publicar.
    target.write_bytes(original.replace(b'\n', b'\r\n'))
    crlf_check = run('--check')
    assert crlf_check.returncode == 0, (crlf_check.stdout, crlf_check.stderr)
    crlf_apply = run('--apply')
    assert crlf_apply.returncode == 0, (crlf_apply.stdout, crlf_apply.stderr)
    assert target.read_bytes() == (source + customization).encode('utf8').replace(b'\n', b'\r\n')
print('OK: publicador --check/--apply/--restore, personalizaciones, CRLF, SHA y errores')
