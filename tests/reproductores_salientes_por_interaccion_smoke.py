from pathlib import Path
hist = Path('public/javascript/seguimiento_llamadas_expediente.js').read_text()
player = Path('public/javascript/seguimiento_llamadas_desplegable.js').read_text()
deploy = Path('tools/desplegar_reproductores_salientes_expediente_hostinger.py').read_text()
assert "tarjeta.dataset.callInteractionId = String(llamada.id || '')" in hist
assert "'impe:expediente-llamadas-actualizadas'" in hist
assert "historialPorId.get(id)" in player
assert "llamadas[indice]" not in player
assert "MutationObserver(prepararTarjetas)" in player
assert "if (tarjeta.querySelector('[data-call-recording-toggle]')) return;" in player
assert "'impe:expediente-llamadas-actualizadas'" in player
assert 'public/javascript/seguimiento_llamadas_expediente.js' in deploy
assert 'public/javascript/seguimiento_llamadas_desplegable.js' in deploy
assert 'BASE + ":" + relative' in deploy
assert deploy.count('".reproductores-salientes.tmp"') == 3
print('Reproductores salientes por interacción: OK')
