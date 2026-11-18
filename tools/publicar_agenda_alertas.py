"""Publicación selectiva de alertas de Agenda; sin SQL ni configuración privada."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile

BASE = '714ce7d1'
BASES_INSTALADAS = {
    'public/javascript/recordatorios.js': 'tests/fixtures/recordatorios_hostinger_20261118.js'
}
RUTAS = (
    'app/controllers/ReminderController.php',
    'app/helpers/ReminderHelper.php',
    'app/services/AgendaComentarioService.php',
    'app/services/AgendaReunionService.php',
    'app/views/layout/dashboard_footer.php',
    'app/views/seguimiento_vinculacion/agenda.php',
    'public/javascript/recordatorios.js',
    'public/javascript/agenda_alertas.js',
    'public/audio/agenda-aviso.mp3',
    'public/audio/LICENSE-agenda.txt',
)

def sha(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None

def lectura(path):
    return path.read_bytes() if path.is_file() else None

def git(repo, ref, name):
    p = subprocess.run(['git', '-C', str(repo), 'show', ref + ':' + name],
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return p.stdout if p.returncode == 0 else None

def destino(site, name):
    p = site / name
    if name not in RUTAS or p.resolve() != p or site not in p.resolve().parents:
        raise ValueError('Ruta inválida o enlazada: ' + name)
    return p

def atomico(path, data, mode=0o644):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(prefix='.impe-agenda-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        os.chmod(name, mode)
        os.replace(name, str(path))
    finally:
        if os.path.exists(name):
            os.unlink(name)

def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--site', type=Path, required=True)
    p.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[1])
    p.add_argument('--revision', default='HEAD')
    p.add_argument('--backup-root', type=Path)
    group = p.add_mutually_exclusive_group(required=True)
    group.add_argument('--check', action='store_true')
    group.add_argument('--apply', action='store_true')
    group.add_argument('--restore', type=Path)
    a = p.parse_args()
    site, repo = a.site.resolve(), a.repo.resolve()
    if not (site / 'index.php').is_file():
        raise ValueError('No se encontró la raíz del CRM.')
    if a.restore:
        backup = a.restore.resolve()
        manifest = json.loads((backup / 'manifiesto.json').read_text(encoding='utf-8'))
        if manifest['sitio'] != str(site):
            raise ValueError('El respaldo pertenece a otro sitio.')
        for name, item in manifest['archivos'].items():
            target = destino(site, name)
            if sha(lectura(target)) != item['despues']:
                raise ValueError('Cambió después de publicar; restauración detenida: ' + name)
            if item['antes'] is not None and sha(lectura(backup / name)) != item['antes']:
                raise ValueError('Respaldo alterado: ' + name)
        for name, item in manifest['archivos'].items():
            target = destino(site, name)
            if item['antes'] is None:
                target.unlink()
            else:
                atomico(target, (backup / name).read_bytes(), item['modo'])
        print('Restauración completada.')
        return
    changes = {}
    with tempfile.TemporaryDirectory(prefix='impe-agenda-check-') as tmp:
        tmp = Path(tmp)
        for name in RUTAS:
            target = destino(site, name)
            old, new = git(repo, BASE, name), git(repo, a.revision, name)
            if name in BASES_INSTALADAS:
                old = git(repo, a.revision, BASES_INSTALADAS[name])
                if old is None:
                    raise ValueError('Falta la base personalizada para conciliar: ' + name)
            current = lectura(target)
            if new is None:
                raise ValueError('Falta archivo en la entrega: ' + name)
            if current == new:
                continue
            merged = new
            if current != old:
                if current is None or old is None or name.endswith('.mp3'):
                    raise ValueError('Conflicto de integridad: ' + name)
                for tag, data in [('local', current), ('base', old), ('nuevo', new)]:
                    (tmp / tag).write_bytes(data.replace(b'\r\n', b'\n'))
                result = subprocess.run(['git', 'merge-file', '-p', str(tmp/'local'),
                                         str(tmp/'base'), str(tmp/'nuevo')], stdout=subprocess.PIPE)
                if result.returncode:
                    raise ValueError('Personalización en conflicto; sitio intacto: ' + name)
                merged = result.stdout
            if name.endswith('.php'):
                (tmp/'validar.php').write_bytes(merged)
                subprocess.run(['php', '-l', str(tmp/'validar.php')], check=True,
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            changes[name] = (target, current, merged)
            print('Compatible | ' + name + ' | SHA256 instalado: ' + str(sha(current)))
    if a.check:
        print('Comprobación completa. Sitio intacto.')
        return
    if not a.backup_root:
        raise ValueError('--apply requiere --backup-root.')
    backup_root = a.backup_root.resolve()
    if backup_root == site or site in backup_root.parents:
        raise ValueError('El respaldo debe estar fuera del sitio público.')
    backup_root.mkdir(parents=True, exist_ok=True)
    backup = Path(tempfile.mkdtemp(prefix='agenda-alertas-', dir=str(backup_root)))
    manifest = {'sitio':str(site), 'archivos':{}}
    for name, (target, current, new) in changes.items():
        if lectura(target) != current:
            raise ValueError('El sitio cambió durante la revisión: ' + name)
        mode = stat.S_IMODE(target.stat().st_mode) if current is not None else 0o644
        manifest['archivos'][name] = {'antes':sha(current), 'despues':sha(new), 'modo':mode}
        if current is not None:
            saved = backup/name
            saved.parent.mkdir(parents=True, exist_ok=True)
            saved.write_bytes(current)
    (backup/'manifiesto.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    installed = []
    try:
        for name, (target, current, new) in changes.items():
            if lectura(target) != current:
                raise ValueError('El sitio cambió antes de escribir: ' + name)
            atomico(target, new, manifest['archivos'][name]['modo'])
            installed.append(name)
            if lectura(target) != new:
                raise ValueError('Falló la integridad posterior: ' + name)
    except BaseException:
        for name in reversed(installed):
            target, current, _ = changes[name]
            if current is None:
                target.unlink()
            else:
                atomico(target, current, manifest['archivos'][name]['modo'])
        raise
    print('Publicación completada. Respaldo y manifiesto: ' + str(backup))

if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        raise SystemExit('Operación detenida: ' + str(error))
