from pathlib import Path
js = Path('public/javascript/seguimiento_llamadas_desplegable.js').read_text()
css = Path('public/css/seguimiento_llamadas_expediente.css').read_text()
deploy = Path('tools/desplegar_unificacion_reproductores_expediente_hostinger.py').read_text()
assert "panel.className = 'linkage-call-inline-player linkage-outgoing-audio-panel';" in js
assert "'<span>Consultar grabación</span>' +" in js
assert "'<span>Grabación</span>' +" not in js
assert '.linkage-call-history-card .linkage-outgoing-audio-panel .linkage-call-custom-player' in css
assert 'width: min(720px, 100%);' in css
assert 'grid-template-columns: 34px auto minmax(80px, 1fr) auto 30px 30px;' in css
assert 'public/javascript/seguimiento_llamadas_desplegable.js' in deploy
assert 'public/css/seguimiento_llamadas_expediente.css' in deploy
assert deploy.count('".unificacion-reproductores.tmp"') == 3
print('Unificación de grabaciones del expediente: OK')
