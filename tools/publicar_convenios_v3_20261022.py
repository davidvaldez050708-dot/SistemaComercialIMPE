#!/usr/bin/env python3
"""Entrega selectiva: Etapa 3 y versión por contenido del componente V3 existente."""
import importlib.util
from pathlib import Path

COMPONENT = 'app/views/convocatorias/partials/aliado_programas_modular.php'
ASSETS = (
    ('public/css/convocatorias_aliado_modular.css', '1'),
    ('public/javascript/convocatorias_aliado_modular.js', '1'),
    ('public/css/convocatorias_aliado_multiprograma_v3.css', '3'),
    ('public/javascript/convocatorias_aliado_multiprograma_v3.js', '3'),
)


def versionar_componente(current):
    if current is None:
        raise RuntimeError('Falta el componente modular existente; no se reemplazó el modal')
    normalized = current.replace(b'\r\n', b'\n')
    required = (b'data-convocatoria-aliado-modulo', b'impe-convocatoria-aliado-contexto',
                b'opciones_convocatoria', b'data-impe-programa-option')
    if not all(marker in normalized for marker in required):
        raise RuntimeError('CONFLICTO: componente modular no reconocido; SITIO INTACTO')
    for asset, version in ASSETS:
        old = (asset + '?v=' + version + '"').encode('ascii')
        new = (asset + "?v=<?= substr(hash_file('sha256', __DIR__ . '/../../../../" + asset + "'), 0, 16) ?>\"").encode('ascii')
        if normalized.count(old) == 1 and normalized.count(new) == 0:
            normalized = normalized.replace(old, new, 1)
        elif normalized.count(old) != 0 or normalized.count(new) != 1:
            raise RuntimeError('CONFLICTO: referencia de recurso no revisada: ' + asset + '; SITIO INTACTO')
    return normalized.replace(b'\n', b'\r\n') if b'\r\n' in current else normalized


def cargar_publicador():
    spec = importlib.util.spec_from_file_location('publicador_base', str(Path(__file__).with_name('publicar_aliados_registro.py')))
    core = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(core)
    original = core.prepare_candidate

    def prepare(row, current, stage):
        if row.get('overlay') != 'versiones-componente-v3':
            return original(row, current, stage)
        if row['path'] != COMPONENT:
            raise RuntimeError('Overlay fuera del componente autorizado')
        # Ningún recurso faltante debe generar HTML con URLs inválidas.
        site = Path(core._site_for_overlay)
        for asset, unused in ASSETS:
            if not core.target(site, asset).is_file():
                raise RuntimeError('Falta recurso del editor existente: ' + asset)
        candidate = versionar_componente(current)
        print('COMPONENTE CONSERVADO: sólo versiones de cuatro recursos por SHA256')
        return candidate

    core.prepare_candidate = prepare
    return core


if __name__ == '__main__':
    import sys
    core = cargar_publicador()
    # La CLI original valida sitio, modo, manifiesto y respaldos. La ruta sólo
    # permite comprobar dependencias durante el preflight, nunca ejecutar PHP.
    try:
        import argparse
        parser = argparse.ArgumentParser(add_help=False)
        parser.add_argument('--site', required=True)
        options, unused = parser.parse_known_args()
        core._site_for_overlay = str(Path(options.site).resolve())
        core.main()
    except (RuntimeError, OSError, ValueError, IndexError) as error:
        print('ERROR: ' + str(error), file=sys.stderr)
        sys.exit(1)
