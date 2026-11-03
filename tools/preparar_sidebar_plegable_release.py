#!/usr/bin/env python3
"""Construye el paquete offline desde fuentes y valida la variante de Hostinger."""
import base64
import gzip
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TOPBAR = 'app/views/layout/topbar.php'
BASE_SHA = '69eb7bfc3d6730a8d145be49011c780a45bc2429354b280505f93775fb96b4af'
FILES = ['public/css/sidebar_plegable.css', 'public/javascript/sidebar_plegable.js', TOPBAR]
DEPENDENCIES = {
    'app/views/layout/sidebar.php': 'a5e17caeeb1e27ed0118c312d51d71351dad71fcc93075620f95bed3a3a03c10',
    'public/css/global.css': 'aee57680b8715161f46a87eef1594981dc799c88c11ac11dad5dc3112329585c',
    'public/css/responsive_sistema.css': '94c70da06f09d7f3bcd4f2a82519d2ee7b8d17d675d8fdb8c2323cd9b5566f63',
}


def sha(data):
    return hashlib.sha256(data).hexdigest()


def pack(data):
    return base64.b64encode(gzip.compress(data, mtime=0)).decode('ascii')


def baseline(data):
    start = data.index(b'<link rel="stylesheet" href="<?= BASE_URL ?>public/css/sidebar_plegable.css')
    end = data.index(b'<main class="admin-main">', start)
    data = data[:start] + data[end:]
    data = data.replace(b'                data-sidebar-toggle\n', b'')
    data = data.replace(b'                aria-expanded="false"\n                aria-label="Abrir navegaci', b'                aria-label="Abrir navegaci', 1)
    if sha(data) != BASE_SHA:
        raise RuntimeError('La cabecera ya no coincide con la variante revisada de Hostinger')
    return data


def build():
    rows = []
    for name in FILES:
        data = (ROOT / name).read_bytes().replace(b'\r\n', b'\n')
        original = baseline(data) if name == TOPBAR else None
        rows.append({'path': name, 'base_sha256': BASE_SHA if original else None,
                     'base': pack(original) if original else None,
                     'sha256': sha(data), 'target': pack(data)})
    return {'schema': 1, 'source': 'sidebar-plegable-escritorio-v1', 'files': rows,
            'dependencies': [{'path': name, 'sha256': value} for name, value in DEPENDENCIES.items()]}


if __name__ == '__main__':
    (ROOT / 'tools/sidebar_plegable_release.json').write_text(
        json.dumps(build(), ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print('Manifiesto generado: 3 archivos; 3 dependencias de sólo lectura.')
