#!/usr/bin/env python3
"""Ensayo de archivos en copia privada: no ejecuta PHP de la aplicación ni abre MySQL."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
from publicar_supervision_personal import safe, candidate, lint


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--site', required=True)
    p.add_argument('--private-root', required=True)
    args = p.parse_args()
    site, private = Path(args.site).resolve(), Path(args.private_root).resolve()
    if not (site / 'index.php').is_file() or not private.is_dir() or site == private or site in private.parents:
        raise RuntimeError('El ensayo requiere una carpeta privada existente fuera del sitio.')
    here = Path(__file__).resolve().parent
    release = here / 'supervision_personal_release.json'
    spec = json.loads(release.read_text(encoding='utf-8'))
    # Comprobar todo antes de crear la copia. No se incluyen config, datos o credenciales.
    plan = []
    for row in spec['files']:
        src = safe(site, row['path'])
        old = src.read_bytes() if src.exists() else None
        new = candidate(old, row)
        lint(new, row['path'])
        plan.append((row['path'], old, new))
    work = Path(tempfile.mkdtemp(prefix='supervision-ensayo-', dir=private))
    work.chmod(0o700)
    copy = work / 'copia'
    copy.mkdir(mode=0o700)
    fingerprints = {}
    for name, old, new in plan:
        fingerprints[name] = None if old is None else hashlib.sha256(old).hexdigest()
        if old is not None:
            target = safe(copy, name)
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(old)
    config = copy / 'config'
    config.mkdir(mode=0o700)
    (config / 'db_connection.php').write_bytes(b"<?php throw new RuntimeException('Copia privada sin conexion MySQL; no ejecutar la aplicacion.');\n")
    cmd = [sys.executable, str(here / 'publicar_supervision_personal.py'), '--site', str(copy), '--backup-root', str(work / 'respaldo')]
    subprocess.run(cmd + ['--check'], check=True)
    subprocess.run(cmd + ['--apply', '--autorizar-publicacion'], check=True)
    subprocess.run(cmd + ['--check'], check=True)
    for name, old, new in plan:
        if safe(copy, name).read_bytes() != new:
            raise RuntimeError('Copia no coincide: ' + name)
        current = safe(site, name)
        if (current.read_bytes() if current.exists() else None) != old:
            raise RuntimeError('Producción cambió durante el ensayo: ' + name)
    report = dict(version=1, release_sha256=hashlib.sha256(release.read_bytes()).hexdigest(), archivos_origen=fingerprints,
                  copia=str(copy), resultado='ENSAYO_ARCHIVOS_OK', validacion_funcional_completa=False,
                  nota='Sólo compatibilidad, parches y sintaxis. Sin credenciales, SQL, llamadas ni ejecución de la aplicación.')
    output = work / 'resultado.json'
    output.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
    output.chmod(0o600)
    print('ENSAYO_ARCHIVOS_OK: origen intacto; INFORME=' + str(output))


if __name__ == '__main__':
    try:
        main()
    except (OSError, RuntimeError, ValueError, subprocess.CalledProcessError) as e:
        raise SystemExit('ENSAYO_BLOQUEADO: ' + str(e))
