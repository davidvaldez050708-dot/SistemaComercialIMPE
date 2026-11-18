"""Reconstruye las seis bases auditadas; verifica los SHA-256 proporcionados por el usuario."""
from pathlib import Path
import hashlib
import json
import re
import subprocess

BASE = 'a04beeb6d9b81f8e868ea8fdf5b40e77825c41b5'
SHA256 = {
    'app/helpers/ReminderHelper.php': '59ed1481aa5a9a2ff743f55ee35c7673f7210e86733dfcfeca91d58aa3c940e4',
    'app/services/AgendaReunionService.php': '9ee0480c567f247d122a7f2c11cc9974d5635f1de0d1dc3da9929beace151e60',
    'app/controllers/ReminderController.php': 'bd94416014a40727bcc80341b71801eb82f47f964c78d93f604dce8469dad0f8',
    'app/views/layout/topbar.php': '3a48f17b722e51464687bb336465e9035a7fa4d18638d71f7551b59d2f7d87de',
    'app/views/layout/dashboard_footer.php': '2d53a9cf2b769fe3f5b61976683b15a80d49f11d45a8ba9f7dae0857bffa1e74',
    'public/javascript/recordatorios.js': '4dfd457c1bc0b65c208c96a91a557c67dc41c349fed7992e8551e7f9943a1e58',
}


def reconstruir():
    root = Path(__file__).resolve().parents[2]
    audit = json.loads(Path(__file__).with_suffix('.patch.json').read_text(encoding='utf-8'))
    assert audit['base_revision'] == BASE
    patch = ''.join(audit['diff_lines'])
    archivos = {}
    for section in re.split(r'^ARCHIVO: ', patch, flags=re.M)[1:]:
        relative = section.splitlines()[0].strip()
        assert relative in SHA256 and relative not in archivos
        original = subprocess.check_output(['git', 'show', BASE+':'+relative], cwd=root).decode('utf-8').splitlines(True)
        lines = section.splitlines(True)[1:]
        result = []
        pos = i = 0
        while i < len(lines):
            match = re.match(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@', lines[i])
            if not match:
                i += 1
                continue
            start = int(match[1])-1
            assert start >= pos
            result.extend(original[pos:start])
            pos = start
            removed = added = 0
            i += 1
            while i < len(lines) and lines[i][:1] in (' ', '+', '-') and not lines[i].startswith(('+++', '---')):
                line = lines[i]
                if line[0] in (' ', '-'):
                    assert original[pos] == line[1:], (relative, pos)
                    pos += 1
                    removed += 1
                if line[0] in (' ', '+'):
                    result.append(line[1:])
                    added += 1
                i += 1
            assert removed == int(match[2] or 1) and added == int(match[4] or 1)
        result.extend(original[pos:])
        data = ''.join(result).encode('utf-8')
        assert hashlib.sha256(data).hexdigest() == SHA256[relative], relative
        archivos[relative] = data
    assert archivos.keys() == SHA256.keys()
    return archivos


if __name__ == '__main__':
    for relative in reconstruir():
        print('SHA256_HOSTINGER_OK:', relative)
