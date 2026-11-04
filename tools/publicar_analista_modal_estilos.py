"""Parche de un único CSS público. Sin SQL ni cambios del motor telefónico."""
import argparse, base64, gzip, hashlib, json, os, tempfile
from pathlib import Path

BEFORE = '69c543a4d8a79927030471efd84680ffeb3ac5bc6ec7e6e49a4250ebc0dacb27'
AFTER = '807e52adcb78070d81d1f7ed554ea641649206d3ae7ceeb04611f1d920a942a2'
PAYLOAD = 'H4sIAAAAAAAC/51WzW7jNhC+5ylYBIvsLiJHki3ZloCilx4L9F70QIlDm12aFEgqtjfIA/Wwh6KPkBfrkJJt+XfTtQFb/NHwm5nvG87TZ/I7VSBJrVcNrZ0mDAeNkNrpktBaO8o0aaihRGlCpQNDDeHarFpJjdCWKEEcyLe/uVZ6RD4/3T19Jr9KUmn39q8iraUEDTq6om//aLIlkhInGr0wlL99o+E0WtdgvXFCWyNqbzgYGolVAxFtmdARg2eBuyKnFwsJ5KXRVjihVWFAUieeoWTCNpJuC6GkUBBxCZuSSrFQkXCwskUNCsGXf7XWCb6Nao1D5XbTfnsRk5hkabMp14K5ZREelyAWS9c9N5QxoRZFXFbaMDBF0myI1VIwcs8qmPCkX4gMZaK1RTLBtypaf1kY3SpW3HPOy1pLbYr7lI35jJcYNhdZ8RWKZI6b69ZYXG20CLBebwRhpACYjQxOwrqgHPeTl51bDw/lPkS0Qoytg9IEV2Z4jNNN+O8cnR789I/HPmTxhyMXqtksTbNdBNJDBLxvF/EOssXFBlj5NRKKYbzTNPah3Hj/fVz7g3GmBzbOY8SzopuoG9dU1h+TOH5ek4ikuPap1M9guNQYgBYZ+9685BfyEoAske5rJEKCxskYt5H7ZJpClqbzkCoMBy6i10/JKCcPv1FldAMPj5YqG1kw4pDfybjO48sR+WMpGAP15+NwrQKUFUToo9ytk5cdq5VW8BNu1sZR5a7wYgnU8/Pw1ntFYFH6gADcGkCVC9oUyZDvnsYkmR2ogeJ2ejUMM4yB8uw7uKwz2sMbcN4LJAzXHf9m8ZWQkVuGqxbxKORZDzhDYPOd5e6gMQ4vo6s02x5e9dTofL0MQ9IK5OOlFeYOga+krr8Mj08u+NkTJU+n0/mV0BELEuozw7c0g/L4UK6EinpFT9IgIbMQyqsbS1wI+s7fuXc32af2SDpjxs+lE59KZ8YpP2V9kEreS+WyTC77yxh56cGmHVgPfyfyaG2Qm1Rt10swcMVEM0imV3EyCECAEx/hB+BTPr3kZe9SMs3r6RVWRnhlYl2zJ5LzPx1W/xMENduD2KsnvUqyjs/XqsOe7v8nZUf5Tm/n8HAzeZ4Gelwpde+4rXq0BQaIVhIwvxqrjXDbYpTvDDDgtJXumgZW3unr2hrk6qaWCq7r1kbPworK9xC6db5VGN5hWZpXdVX2K5Hm3IIrzmvHMBvfrbY32dBxNC7PKXueXchZXuWn2U3P7zLK99VlmmXjhA0EmYS7K3uPKIde4hWBjAttUkLwO4lvBuW0Iud9RT69dF/7dpFiVjTBJLWKEi6wSxTKd5m+t6OPvvf0C9gpCt9VMvB9ZN1SZt6++YYx9JHHbeioAWOF9fdb1Czx+txr9WdyNZV3BD8LIxhejbJdYXbIE4mSMsxfrrphaVB6w9jX324u7ie6ZPsO07MpzHVUwQrPC+uoceXd6w+h7lIzPDIwrqJoqEh3ifoBw7skHhqw4OHr3S8rYIJ+PMxn02w0R1J/Ii+3uvYBrsmhz54M+uxJetaABoIP5O5b1NfXu/8Ak8LSvroMAAA='
REL = 'public/css/telefonia_audio_dispositivos.css'
def sha(data): return hashlib.sha256(data).hexdigest()
def atomic(path, data, mode):
    fd, temp = tempfile.mkstemp(prefix='.impe-modal-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data); stream.flush(); os.fsync(stream.fileno())
        os.chmod(temp, mode); os.replace(temp, str(path))
    finally:
        if os.path.exists(temp): os.unlink(temp)

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True)
    parser.add_argument('--backup-root', required=True)
    actions = parser.add_mutually_exclusive_group(required=True)
    actions.add_argument('--check', action='store_true')
    actions.add_argument('--apply', action='store_true')
    actions.add_argument('--restore')
    args = parser.parse_args()
    site, root = Path(args.site).resolve(), Path(args.backup_root).resolve()
    dest = site/REL
    if not (site/'index.php').is_file() or not dest.is_file(): raise RuntimeError('Sitio o CSS no disponible.')
    if site == root or site in root.parents or site not in dest.resolve().parents or dest.is_symlink():
        raise RuntimeError('Ruta o respaldo fuera del alcance permitido.')
    target = gzip.decompress(base64.b64decode(PAYLOAD))
    if sha(target) != AFTER: raise RuntimeError('Paquete alterado.')
    current = dest.read_bytes()
    current_hash = sha(current.replace(b'\r\n', b'\n'))
    mode = dest.stat().st_mode & 0o777
    if args.restore:
        backup = Path(args.restore).resolve()
        if root not in backup.parents: raise RuntimeError('Respaldo fuera del directorio indicado.')
        meta = json.loads((backup/'respaldo.json').read_text(encoding='utf8'))
        original = (backup/'original.css').read_bytes()
        if meta['site'] != str(site) or sha(original) != meta['sha'] or current_hash != AFTER:
            raise RuntimeError('Respaldo ajeno, dañado o CSS editado después de instalar.')
        atomic(dest, original, meta['mode'])
        print('RESTAURADO: CSS original. Sin SQL.'); return
    if current_hash not in [BEFORE, AFTER]: raise RuntimeError('VERSIÓN DIFERENTE: '+REL+'. Sitio intacto.')
    if args.check:
        print('CHECK OK: '+REL+'. Sin escrituras.'); return
    if current_hash == AFTER and (os.name != 'posix' or mode == 0o644):
        print('YA INSTALADO: sin cambios.'); return
    root.mkdir(parents=True, exist_ok=True)
    backup = Path(tempfile.mkdtemp(prefix='analista-modal-', dir=str(root)))
    os.chmod(backup, 0o700)
    (backup/'original.css').write_bytes(current)
    (backup/'respaldo.json').write_text(json.dumps({'site':str(site),'sha':sha(current),'mode':mode}), encoding='utf8')
    if dest.read_bytes() != current: raise RuntimeError('CSS modificado durante la publicación; sitio intacto.')
    atomic(dest, target, 0o644)
    if sha(dest.read_bytes()) != AFTER:
        atomic(dest, current, mode); raise RuntimeError('Verificación fallida; CSS restaurado.')
    print('APLICADO: aviso de micrófono en su propia fila. Sólo CSS. RESPALDO: '+str(backup))

if __name__ == '__main__':
    try: main()
    except Exception as error:
        print('ERROR: '+str(error)); raise SystemExit(1)
