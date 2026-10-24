"""Entrega selectiva: ocho archivos; conserva el controlador Hostinger confirmado."""
import argparse
import base64
import gzip
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
BASE = 'aad6ca4aa30794185aa86b14cc278bd25977d926'
CONTROLLER = 'app/controllers/AliadoController.php'
HOST_SHA = '6cdfd9b2bb299623e91425632cfcd8a2a39ef7e8d4c9cd31a6053b2cd9918170'
FILES = [
    'app/services/AliadoHistorialService.php',
    'app/models/AliadoModel.php',
    'app/services/AliadoCompartirService.php',
    CONTROLLER,
    'public/javascript/aliados.js',
    'public/javascript/aliados_registro.js',
    'app/views/aliados/partials/registro_gestion.php',
    'app/views/aliados/estado.php',
]
EXPECTED = {
    'app/models/AliadoModel.php':'b1d557c1298789b43b448683eaf01d4426223f39c72aa3b9aa10c868e98f5dad',
    'app/services/AliadoCompartirService.php':'3531c6e47646e7cc369994e4fb06379ce72178e3d1e478e4a34275203fa23e3d',
    'app/views/aliados/estado.php':'0695ce31f02982271d02fed6fbe87e81d2ef579796e633ad685151984ca06f16',
    'app/views/aliados/partials/registro_gestion.php':'2c5c4f5ff1b913ee80983b89990b031fd2d10c78972741162a5c3c3f3f35ff52',
    'public/javascript/aliados.js':'e6d8a26cb958ad06eb7780eca2a247a3fabd4a1cd1eaf4840146bcd7dd48faf5',
    'public/javascript/aliados_registro.js':'5399ea25a5acab5f9c6df1e5ce30ad46f46d960e8a853dc3937f8c7c1c9ff9a8',
}

def git(ref, name):
    return subprocess.check_output(['git', 'show', ref+':'+name], cwd=str(ROOT))

def pack(data):
    return base64.b64encode(gzip.compress(data, mtime=0)).decode('ascii')

def reviewed_controller():
    # Reconstrucción determinista del diff pegado por el usuario. No es un
    # destino adicional de la entrega ni instala módulos internacionales.
    old = git('544697a162ddecd009fbc2669c4bbd8ab27f883c', CONTROLLER).decode('utf-8')
    custom = git('20ec4fb64f211b06cde114da76d6c84e65a96db5', CONTROLLER).decode('utf-8')
    start = '    public function internacionales()'
    end = '    public function estado()'
    old = old[:old.index(start)] + custom[custom.index(start):custom.index(end,custom.index(start))] + old[old.index(end,old.index(start)):]
    anchor = "require_once __DIR__ . '/../models/AliadoInternacionalModel.php';"
    old = old.replace(anchor, anchor+"\nrequire_once __DIR__ . '/../services/AliadoInternacionalService.php';", 1)
    old = old.replace("        $this->validarPermiso('aliados.ver');", "        if (!AliadoInternacionalService::puedeConsultar()) { $this->validarPermiso('aliados.ver'); }", 1)
    old = old.replace('        $territorios = $esAdministrador', "        $territorios = !tienePermiso('aliados.ver') ? [] : ($esAdministrador", 1)
    old = old.replace('            : $modeloSeguimiento->obtenerEstadosSupervisadosCuentaClave($usuarioId);', '            : $modeloSeguimiento->obtenerEstadosSupervisadosCuentaClave($usuarioId));', 1)
    old = old.replace('        if ($estructuraAliadosDisponible) {', "        if ($estructuraAliadosDisponible && tienePermiso('aliados.ver')) {", 1)
    data = old.encode('utf-8')
    if hashlib.sha256(data).hexdigest() != HOST_SHA:
        raise RuntimeError('El controlador reconstruido no coincide con el hash confirmado.')
    return data

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', required=True)
    parser.add_argument('--output', default=str(ROOT/'tools/aliados_historial_release.json'))
    args = parser.parse_args()
    spec = importlib.util.spec_from_file_location('core',str(ROOT/'tools/publicar_aliados_registro.py'))
    core = importlib.util.module_from_spec(spec); spec.loader.exec_module(core)
    host = reviewed_controller()
    rows = []
    with tempfile.TemporaryDirectory() as temp:
        for name in FILES:
            old = None if name.endswith('/AliadoHistorialService.php') else git(BASE,name)
            if name in EXPECTED and core.sha(old) != EXPECTED[name]:
                raise RuntimeError('La base no coincide con Hostinger: '+name)
            new = git(args.source,name)
            row = {'path':name,'base':pack(old) if old is not None else None,'target':pack(new),'sha256':core.sha(new)}
            if name == CONTROLLER:
                merged = core.merge(host,old,new,Path(temp))
                row['variants'] = [{'ref':'hostinger-confirmado-historial-20261023','base':pack(host),'target':pack(merged),'sha256':core.sha(merged)}]
            rows.append(row)
    Path(args.output).write_text(json.dumps({'base':BASE,'source':args.source,'files':rows},indent=2)+'\n',encoding='utf-8')
    print('ENTREGA: ocho archivos de Aliados. Personalizaciones preservadas. Sin SQL ni reenvíos.')

if __name__ == '__main__':
    main()
