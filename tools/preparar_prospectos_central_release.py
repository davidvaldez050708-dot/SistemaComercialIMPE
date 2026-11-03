"""Generar paquete únicamente con las cuatro bases reales revisadas de Hostinger."""
import argparse
import base64
import gzip
import hashlib
import importlib.util
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASES = {
    'app/controllers/ProspectoController.php': '3d01c85677519ece365157ea2164bbc4fdeddf69994a94694e49d823d8e56cac',
    'app/models/ProspectoModel.php': '48d266594927188026e30a4f58537c7261832e3e2d10690f6d86afdaa6ca643c',
    'app/models/FormularioRegistroModel.php': '045fca2de57c70c2f87a33dece4a5571f5a5102d78dbe4c92e7415a1fb497f7c',
    'app/views/prospectos/index.php': '063e56915a1ace76639db175597892254d0379470918fb690eb15e43723d3179',
}
DEPENDENCIAS = {
    'app/controllers/FormularioPublicoController.php': 'da1a10ad48a9bff565a584c4c97bcdb20774df2a8107109b6c3bd87132dd9890',
    'app/controllers/FormularioController.php': '2854d4e83cc6d2ecc3e5abe0c55c7b19aeb94f8d85344b19bd04a0e8935e03b1',
    'app/services/FormularioAliadoService.php': 'f312486e7080aa163c923a94d87bd354c41a3fe5f52ec41f7fd13a73b6c1ff43',
    'app/services/FormularioUbicacionService.php': '2506d5f332b5efecb73bd47f4225d1b9d2c60c79f53a71499a8b1fe6dbdc70d4',
    'app/helpers/HorarioContacto.php': '0e03d42f9f508c0e1db666e88d5dbcc81978c55f0187a605b519e6cb63b12156',
    'app/helpers/ProspectoFechaHoraHelper.php': '7b578d6539e5055259e45f40c979bb76a37b83ee448eadcb1bd5c0ba82b12eff',
    'app/helpers/ProgramaEducativo.php': '21cf8e1a52be8245511bb048b1439f957768a5d4350f7ae8600e44b98063e455',
    'config/db_connection.php': '68facf921ad4649c8ca554d9bee8207b30aa70dfe1ab26cee0fcca162e23e082',
    'public/css/prospectos_fase1.css': '8c85857813aa392fb5e15c0a4daa4fedd17d7a94774e538ce785680a8bd3fd20',
    'public/javascript/prospectos_fase1.js': '25e9f21344696ea293b4f9b85a358e381c01552a5466dc0855d39b9504a8d4ea',
}


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--baseline-root')
    a = p.parse_args()
    spec = importlib.util.spec_from_file_location('publicar', ROOT / 'tools/publicar_prospectos_central.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    previous = {}
    if not a.baseline_root:
        previous = {r['path']: r for r in json.loads((ROOT / 'tools/prospectos_central_release.json').read_text(encoding='utf-8'))['files']}
    rows = []
    for name in sorted(module.FILES):
        old = ((Path(a.baseline_root) / name).read_bytes() if a.baseline_root else gzip.decompress(base64.b64decode(previous[name]['baseline']))) if name in BASES else None
        if old is not None and module.sha(old) != BASES[name]:
            raise RuntimeError('La base no coincide con Hostinger: ' + name)
        content = (ROOT / name).read_bytes().replace(b'\r\n', b'\n')
        rows.append({'path': name, 'before': BASES.get(name), 'baseline': base64.b64encode(gzip.compress(old, mtime=0)).decode('ascii') if old is not None else None, 'sha256': module.sha(content),
                     'content': base64.b64encode(gzip.compress(content, mtime=0)).decode('ascii')})
    for name, expected in DEPENDENCIAS.items():
        if hashlib.sha256((ROOT / name).read_bytes().replace(b'\r\n', b'\n')).hexdigest() != expected:
            raise RuntimeError('Dependencia local diferente: ' + name)
    data = {'version': 1, 'scope': 'Prospectos Fase 1; sin SQL automático',
            'dependencies': [{'path': name, 'sha256': digest} for name, digest in DEPENDENCIAS.items()], 'files': rows}
    (ROOT / 'tools/prospectos_central_release.json').write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print('PAQUETE: 17 archivos, 10 dependencias verificadas; cuatro bases reales de Hostinger.')


if __name__ == '__main__':
    main()
