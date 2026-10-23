#!/usr/bin/env python3
"""Construye una entrega V5 mensual desde las variantes verificadas de Hostinger.

No accede ni escribe en producción. Se alimenta de commits GitHub fijados y
manifiestos V5 históricos; el publicador posterior exige los hashes revisados.
Compatible con Python 3.6 y con los componentes modulares existentes.
"""
import argparse
import base64
import gzip
import hashlib
import io
import importlib.util
import json
import subprocess
import tempfile
from pathlib import Path

HOST_CONTROLLER_SHA = 'bbf084c48239494d6e1a0d701d06187c00376dea04ebfe2bae1eea59aed4c1d5'
HOST_VIEW_SHA = '80cc94ac0a3a5ceddda6b92e7ddf0b57c426e8a6b1e7c0f6e8c59969591b0269'
TARGET_COMMIT = '932c3fbb86fb1941fa6d232c0812198652d36fda'
BASE_COMMIT = 'ebe671564cd9a33c268156f2cb1a5e6fdae4170a'


def sha(data):
    return hashlib.sha256(data).hexdigest()


def encode(data):
    # Python 3.6 no permite mtime en gzip.compress; GzipFile sí lo soporta.
    # No incluir nombre de archivo ni timestamp: entrega reproducible.
    output = io.BytesIO()
    with gzip.GzipFile(fileobj=output, mode='wb', filename='', mtime=0) as stream:
        stream.write(data)
    return base64.b64encode(output.getvalue()).decode('ascii')


def decode(value):
    return gzip.decompress(base64.b64decode(value))


def load_core(path):
    spec = importlib.util.spec_from_file_location('v5_monthly_base', str(path))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--directory', type=Path, required=True,
                        help='Directorio con las fuentes y manifiestos revisados')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    root = args.directory.resolve()
    files = {
        'app/controllers/ConvocatoriaController.php': (
            'controller-before.php', 'controller-after.php',
            'convocatorias_gestion_v5_release.json', HOST_CONTROLLER_SHA
        ),
        'app/views/convocatorias/index.php': (
            'view-before.php', 'view-after.php',
            'convocatorias_gestion_v5_qr_release.json', HOST_VIEW_SHA
        ),
    }
    core = load_core(root / 'publicar_aliados_registro.py')
    manifest = {
        'base': BASE_COMMIT,
        'source': TARGET_COMMIT,
        'files': [],
        'dependencies': [],
    }
    qr_manifest = json.loads((root / 'convocatorias_gestion_v5_qr_release.json').read_text(encoding='utf-8'))
    manifest['dependencies'] = qr_manifest['dependencies']
    with tempfile.TemporaryDirectory() as tmp:
        for name, (old_file, next_file, old_manifest_file, original_sha) in files.items():
            old = (root / old_file).read_bytes()
            nxt = (root / next_file).read_bytes()
            previous = json.loads((root / old_manifest_file).read_text(encoding='utf-8'))
            entry = next(r for r in previous['files'] if r['path'] == name)
            if len(entry.get('variants', [])) != 1:
                raise RuntimeError('Falta variante Hostinger revisada: ' + name)
            reviewed = decode(entry['variants'][0]['target'])
            if sha(reviewed) != original_sha or entry['variants'][0]['sha256'] != original_sha:
                raise RuntimeError('La variante preaprobada no coincide con el hash Hostinger: ' + name)
            # El diff sólo puede referirse al commit V5 de partida y a la etapa mensual.
            expected = subprocess.run(
                ['git', 'diff', '--no-index', '--', str(root / old_file), str(root / next_file)],
                stdout=subprocess.PIPE, stderr=subprocess.PIPE
            )
            if expected.returncode != 1 or len(expected.stdout) > 45000:
                raise RuntimeError('Cambio inesperado en los fuentes de la etapa mensual: ' + name)
            with tempfile.TemporaryDirectory(dir=tmp) as merge_dir:
                merged = core.merge(reviewed, old, nxt, Path(merge_dir))
            if merged == reviewed:
                raise RuntimeError('La entrega no modifica ' + name)
            markers = ('ConvocatoriaVentanaMensual', 'periodo') if 'Controller' in name else ('filtro_convocatoria_periodo', 'sincronizarPeriodoEnUrl')
            if not all(marker.encode('utf-8') in merged for marker in markers):
                raise RuntimeError('La conciliación perdió marcadores de la etapa mensual: ' + name)
            with tempfile.NamedTemporaryFile(suffix='.php', dir=tmp) as php:
                php.write(merged)
                php.flush()
                check = subprocess.run(['php', '-l', php.name], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                if check.returncode:
                    raise RuntimeError('Conciliación PHP inválida en ' + name + ': ' + check.stderr.decode('utf-8','replace'))
            manifest['files'].append({
                'path': name, 'base': encode(reviewed), 'target': encode(merged),
                'sha256': sha(merged)
            })
            print('CONCILIADO: {} ({} → {})'.format(name, original_sha[:12], sha(merged)[:12]))
    helper = (root / 'ConvocatoriaVentanaMensual.php').read_bytes()
    if b'final class ConvocatoriaVentanaMensual' not in helper:
        raise RuntimeError('No se recibió el helper de ventana mensual')
    manifest['files'].append({
        'path': 'app/helpers/ConvocatoriaVentanaMensual.php',
        'base': None, 'target': encode(helper), 'sha256': sha(helper)
    })
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(manifest, indent=2, ensure_ascii=False)+'\n',encoding='utf-8')
    print('ENTREGA SEGURA: 3 archivos; sin SQL ni borrados. Manifest: '+str(args.output))


if __name__ == '__main__':
    main()
