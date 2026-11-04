"""Genera variantes exactas; nunca obtiene ni incluye configuración privada."""
from pathlib import Path
import base64, gzip, hashlib, json, re, subprocess, sys

ROOT = Path(__file__).resolve().parents[1]
def digest(data): return hashlib.sha256(data).hexdigest()
def pack(data):
    encoded = bytearray(gzip.compress(data, mtime=0))
    encoded[9] = 255  # Cabecera reproducible entre Windows y Linux.
    return base64.b64encode(encoded).decode()
def blob(ref, path): return subprocess.check_output(['git', 'show', f'{ref}:{path}'], cwd=ROOT)
def lf(path): return (ROOT/path).read_bytes().replace(b'\r\n', b'\n')

controller = 'app/controllers/SeguimientoVinculacionController.php'
base = blob('0b30eb1e', controller)
target = lf(controller)
old_method = base.split(b'    public function buscarCandidatos()')[1].split(b'    public function actualizarSecretariasDenue()')[0]
new_method = target.split(b'    public function buscarCandidatos()')[1].split(b'    public function actualizarSecretariasDenue()')[0]
assert base.replace(old_method, new_method, 1) == target, 'Hay cambios fuera de buscarCandidatos'
rows = []
paths = ['config/certificates/inegi-ca-chain.pem', 'app/services/DenueTlsPolicy.php',
         'app/services/DenueCandidatosHttpClient.php', 'app/services/DenueService.php', controller]
for path in paths:
    variants = []
    if path == controller:
        for ref in ['0b30eb1e', '94b777efd875fca09df9c2c5e84f09373f40b39b']:
            original = blob(ref, path)
            assert original.count(old_method) == 1, 'La variante cambió el método de candidatos'
            patched = original.replace(old_method, new_method, 1)
            variants.append({'before': digest(original), 'base': pack(original),
                             'after': digest(patched), 'target': pack(patched)})
    elif path == 'app/services/DenueService.php':
        original = blob('ce3b126a8042c9d72b76656f42100f5b8587a9ca', path)
        variants.append({'before': digest(original), 'base': pack(original), 'after': digest(lf(path)), 'target': pack(lf(path))})
    else:
        variants.append({'before': None, 'after': digest(lf(path)), 'target': pack(lf(path))})
    rows.append({'path': path, 'variants': variants})
manifest = {'schema': 1, 'package': 'denue-estabilidad-20261103', 'files': rows}
raw = (json.dumps(manifest, indent=2)+'\n').encode()
release = ROOT/'tools/denue_estabilidad_release.json'
installer = ROOT/'tools/publicar_denue_estabilidad.py'
code = installer.read_text(encoding='utf-8')
updated = re.sub(r"MANIFEST_SHA256 = '[a-f0-9]{64}'", "MANIFEST_SHA256 = '"+digest(raw)+"'", code)
if '--check' in sys.argv:
    assert release.read_bytes() == raw and updated == code, 'Regenera el manifiesto y su publicador'
else:
    release.write_bytes(raw)
    installer.write_text(updated, encoding='utf-8', newline='\n')
print(digest(raw))
