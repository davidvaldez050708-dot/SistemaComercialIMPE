from pathlib import Path
s = Path('tools/desplegar_diagnostico_audio_remoto_hostinger.py').read_text()
assert s.count('".diagnostico-audio.tmp"') == 3
assert '".grabaciones.tmp"' not in s
assert 'shutil.copy2(dest, path)' in s
assert 'if args.check:' in s
print('Instalador diagnostico audio: temporales consistentes OK')
