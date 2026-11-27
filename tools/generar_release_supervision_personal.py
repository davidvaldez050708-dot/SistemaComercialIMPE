#!/usr/bin/env python3
"""Construye parches mínimos contra una copia privada de los archivos instalados."""
import argparse
import base64
import difflib
import hashlib
import json
from pathlib import Path

# Dependencias primero; navegación al final. Estos archivos ya existen en Hostinger,
# aunque algunos aún no estaban en origin/main al iniciar esta rama.
EXISTENTES = (
    'app/helpers/SupervisionRolePolicy.php',
    'app/services/SupervisionLlamadasRepository.php',
    'app/services/VentasActividadRepository.php',
    'app/services/VentasActividadService.php',
    'app/services/DesempenoService.php',
    'app/models/SeguimientoVinculacionModel.php',
    'app/services/SeguimientoReporteAnaliticaService.php',
    'app/controllers/SeguimientoVinculacionReporteController.php',
    'prueba_telefonia/api/grabacion_interaccion.php',
    'index.php',
    'app/views/layout/sidebar.php',
)
NUEVOS = (
    'app/services/SupervisionPersonalAcceso.php',
    'app/services/SupervisionPersonalRepository.php',
    'app/services/SupervisionPersonalAuditoria.php',
    'app/services/SupervisionPersonalMigracion.php',
    'app/services/SupervisionPersonalGrabacionPolicy.php',
    'app/controllers/SupervisionPersonalController.php',
    'app/views/supervision_personal/index.php',
    'public/css/supervision_personal.css',
    'public/javascript/supervision_personal.js',
    'tools/supervision_personal_migrar.php',
)


def crear(root, baseline):
    rows = []
    for name in NUEVOS + EXISTENTES:
        after = (root / name).read_bytes().replace(b'\r\n', b'\n')
        row = dict(path=name, new_file=name in NUEVOS, after_sha=hashlib.sha256(after).hexdigest())
        if row['new_file']:
            row['content'] = base64.b64encode(after).decode('ascii')
        else:
            before = (baseline / name).read_bytes().replace(b'\r\n', b'\n')
            row['baseline_sha'] = hashlib.sha256(before).hexdigest()
            a, b = before.decode('utf-8').splitlines(True), after.decode('utf-8').splitlines(True)
            for context in (4, 8, 16, 32, 64):
                hunks = []
                for group in difflib.SequenceMatcher(None, a, b, autojunk=False).get_grouped_opcodes(context):
                    old = ''.join(a[group[0][1]:group[-1][2]])
                    new = ''.join(b[group[0][3]:group[-1][4]])
                    hunks.append(dict(before=old, after=new))
                if all(h['before'] and h['after'] and before.decode('utf-8').count(h['before']) == 1 for h in hunks):
                    break
            else:
                raise RuntimeError('Contexto ambiguo: ' + name)
            if not hunks:
                raise RuntimeError('Archivo existente sin cambios: ' + name)
            row['hunks'] = hunks
        rows.append(row)
    return dict(release='supervision-personal-v1', referencia='Hostinger personalizado; lectura 2026-11-27', activacion_automatica=False, files=rows)


if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--baseline-dir', required=True)
    p.add_argument('--output', default=str(Path(__file__).with_name('supervision_personal_release.json')))
    args = p.parse_args()
    root = Path(__file__).resolve().parents[1]
    spec = crear(root, Path(args.baseline_dir).resolve())
    Path(args.output).write_bytes((json.dumps(spec, ensure_ascii=False, indent=2) + '\n').encode('utf-8'))
    print('PAQUETE_LOCAL_OK: ' + str(len(spec['files'])) + ' archivos; sin acceso a producción.')
