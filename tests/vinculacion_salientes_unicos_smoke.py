from pathlib import Path
store = Path('app/services/ZadarmaWebhookEventStoreService.php').read_text()
api = Path('prueba_telefonia/api/vincular_interaccion_zadarma.php').read_text()
installer = Path('tools/desplegar_vinculacion_salientes_hostinger.py').read_text()
assert 'function buscarPbxSalienteUnico(' in store
assert "evento = 'NOTIFY_OUT_START'" in store
assert 'AND internal = ?' in store
assert 'RIGHT(destination_digits, 10) = ?' in store
assert 'LIMIT 2' in store
assert 'count($candidates) === 1' in store
assert 'abs(time() - $desdeUnix) > 1800' in store
assert 'buscarPbxSalienteUnico($extension, $destino, $desdeUnix)' in api
assert 'obtenerPorPbxCallId($pbxCallId)' in api
assert 'app/services/ZadarmaWebhookEventStoreService.php' in installer
assert 'prueba_telefonia/api/vincular_interaccion_zadarma.php' in installer
assert installer.count('".vinculacion-salientes.tmp"') == 3
print('Vinculación saliente inequívoca: verificación estática OK')
