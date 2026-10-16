from pathlib import Path
store = Path('app/services/ZadarmaWebhookEventStoreService.php').read_text()
api = Path('prueba_telefonia/api/vincular_interaccion_zadarma.php').read_text()
assert 'buscarPbxSalienteUnico' not in store
assert 'buscarPbxSalienteUnico' not in api
assert '$sqlPbxOcupada' not in api
assert 'function obtenerPorPbxCallId' in store
assert 'class ZadarmaWebhookEventStoreService' in store
assert 'class ZadarmaCallLookupService' in Path('app/services/ZadarmaCallLookupService.php').read_text()
print('Reversión PR61: validaciones estáticas OK')
