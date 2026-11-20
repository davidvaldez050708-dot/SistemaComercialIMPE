"""Construye un paquete de parches acotados; no incorpora configuración o datos."""
from pathlib import Path
import difflib
import hashlib
import json
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[2]
BASELINE = '1cc319a0c24c54733835e95b9a84624468ea2fd2'
OUT = ROOT / 'output' / 'recordatorios_hostinger_20261120'
FILES = [
    'app/controllers/ReminderController.php',
    'app/helpers/ReminderHelper.php',
    'app/models/AliadoModel.php',
    'app/services/ReminderAgendaFilterService.php',
    'app/services/ReminderReunionFollowupService.php',
    'app/services/AliadoSeguimientoReminderService.php',
    'app/views/layout/topbar.php',
    'public/javascript/recordatorios.js',
]

def build():
    OUT.mkdir(parents=True, exist_ok=True)
    changes = {}
    for file in FILES:
        before = subprocess.check_output(['git', 'show', f'{BASELINE}:{file}'], cwd=ROOT).decode('utf-8').replace('\r\n', '\n')
        after = (ROOT / file).read_text(encoding='utf-8')
        if file == 'app/models/AliadoModel.php':
            # El modelo tiene trabajo ajeno en curso: empaquetar sólo este orden.
            old = 'WHEN seguimiento.proximo_seguimiento_at < NOW()\n                        THEN 0'
            new = 'WHEN seguimiento.proximo_seguimiento_at >= NOW()\n                        THEN 0'
            start = before.index('    public function obtenerRecordatoriosSeguimientoAliados(')
            end = before.index('    private function consultarSeguimientoConvocatoriaActual(', start)
            method = before[start:end]
            assert method.count(old) == 1 and method.replace(old, new) in after
            after = before[:start] + method.replace(old, new) + before[end:]
        a, b = before.splitlines(keepends=True), after.splitlines(keepends=True)
        changes[file] = []
        context = 12 if file == 'app/models/AliadoModel.php' else 3
        for group in difflib.SequenceMatcher(None, a, b, autojunk=False).get_grouped_opcodes(context):
            changes[file].append({'before': ''.join(a[group[0][1]:group[-1][2]]), 'after': ''.join(b[group[0][3]:group[-1][4]])})
    payload = json.dumps(changes, ensure_ascii=False, indent=2).encode('utf-8')
    (OUT / 'cambios.json').write_bytes(payload)
    script = Path(__file__).with_name('publicar.template.php').read_text(encoding='utf-8').replace('__PAYLOAD_SHA256__', hashlib.sha256(payload).hexdigest())
    (OUT / 'publicar.php').write_text(script, encoding='utf-8', newline='\n')
    Path(__file__).with_name('publicar.php').write_text(script, encoding='utf-8', newline='\n')
    Path(__file__).with_name('cambios.json').write_bytes(payload)
    commands = '''Subir el ZIP al directorio personal por el administrador de archivos/SFTP, fuera de public_html.
En SSH, desde el directorio que contiene el ZIP:

unzip -q proximas-acciones-hostinger-20261120.zip -d proximas-acciones-20261120
cd proximas-acciones-20261120
CRM="$HOME/domains/fundacionrededumex.org/public_html"
php publicar.php --check "$CRM"
php publicar.php --apply "$CRM"

Si el CRM está en una subcarpeta, ajustar CRM a la carpeta que contiene index.php y app/.
Si CHECK falla, no aplicar: conservar la salida para conciliar la variante de Hostinger.
El instalador conserva cambios ajenos y falla antes de escribir si no reconoce algún bloque.
No utiliza git pull, SQL, cron, cambios de permisos de usuario ni llamadas telefónicas.
Después: Ctrl+F5 en el CRM e interactuar con la página para habilitar el audio del navegador.
Los avisos se consultan cada minuto con la sesión abierta y la pestaña visible.

Restaurar usando la ruta exacta RESPALDO= que imprimió --apply:
php publicar.php --restore "$CRM" "/ruta/exacta/del/respaldo"
'''
    (OUT / 'COMANDOS.txt').write_text(commands, encoding='utf-8', newline='\n')
    archive = OUT / 'proximas-acciones-hostinger-20261120.zip'
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as z:
        for name in ['publicar.php', 'cambios.json', 'COMANDOS.txt']:
            z.write(OUT / name, name)
    (OUT / (archive.name + '.sha256')).write_text(hashlib.sha256(archive.read_bytes()).hexdigest() + '  ' + archive.name + '\n', encoding='ascii')
    print(archive)
    return OUT

if __name__ == '__main__':
    build()
