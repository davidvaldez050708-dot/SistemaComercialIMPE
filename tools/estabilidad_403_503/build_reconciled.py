"""Construye una propuesta desde los bytes verificados de producción. No despliega."""
from pathlib import Path
import difflib
import hashlib
import json

HERE = Path(__file__).resolve().parent
BASE = HERE / 'production_20261119'
DEST = HERE / 'reconciled'
CHANGED = ['config/db_connection.php', 'app/controllers/TelefoniaController.php',
           'public/javascript/convocatoria_notificaciones.js']


def replace_once(source, before, after):
    assert source.count(before) == 1, f'Fragmento inesperado: {before[:100]}'
    return source.replace(before, after, 1)


def main():
    manifest = {'version': 'impe-403-503-reconciled-v2', 'files': {}, 'changed': CHANGED,
                'github_ref': 'a90b6aded4a7f606eccc7a27749658e3fed9b9ed'}
    patches = []
    for line in (BASE / 'SHA256SUMS.txt').read_text().splitlines():
        expected, name = line.split(maxsplit=1)
        name = name.lstrip('*')
        original = (BASE / name).read_bytes()
        assert hashlib.sha256(original).hexdigest() == expected, name
        result = original
        if name == 'config/db_connection.php':
            result = (HERE / 'database_reconciled.php').read_bytes()
        elif name == 'app/controllers/TelefoniaController.php':
            text = original.decode('utf-8').replace('\r\n', '\n')
            text = replace_once(text,
                '        $modeloRol = new RolModel();\n        $modeloRol->inicializarPermisosSistema();',
                '''        // El catálogo se instala en las rutas normales. POLL mantiene la
        // autorización vigente sin reescribir permisos en cada tick.
        $esSondeo = ($_GET['action'] ?? '') === 'disponibilidad'
            && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && ($_POST['accion'] ?? '') === 'POLL';
        $modeloRol = $esSondeo ? new RolModel(Database::compartida()) : new RolModel();
        if (!$esSondeo) {
            $modeloRol->inicializarPermisosSistema();
        }''')
            text = replace_once(text,
                '        $this->service = new TelefoniaExtensionService();',
                '''        // POLL no usa este servicio. Las otras rutas conservan su
        // construcción y conexión independiente en el mismo lugar de siempre.
        if (!$esSondeo) {
            $this->service = new TelefoniaExtensionService();
        }''')
            result = text.encode('utf-8')
        elif name == 'public/javascript/convocatoria_notificaciones.js':
            text = original.decode('utf-8').replace('\r\n', '\n')
            text = replace_once(text, '    const load = async function () {', '''    let activeLoad = null;
    const load = function ({ fresh = false } = {}) {
        if (activeLoad) {
            // Coalescer ticks/eventos; una lectura después de marcar debe ser nueva.
            return fresh ? activeLoad.then(() => load({ fresh: true })) : activeLoad;
        }
        activeLoad = Promise.resolve().then(loadOnce).finally(() => { activeLoad = null; });
        return activeLoad;
    };

    const loadOnce = async function () {''')
            text = replace_once(text, "headers: { 'Accept': 'application/json' }",
                "headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' }")
            text = replace_once(text, '                await load();', '                await load({ fresh: true });')
            result = text.encode('utf-8')
        target = DEST / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(result)
        after = hashlib.sha256(result).hexdigest()
        manifest['files'][name] = {'before': expected, 'after': after}
        if result != original:
            assert name in CHANGED, name
            patches.extend(difflib.unified_diff(
                original.decode().replace('\r\n', '\n').splitlines(keepends=True),
                result.decode().splitlines(keepends=True), fromfile='a/' + name, tofile='b/' + name))
        else:
            assert name not in CHANGED, name
    (HERE / 'reconciled.patch').write_text(''.join(patches), encoding='utf-8')
    (DEST / 'release.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    print('Construidos 13 archivos: 3 modificados, 10 idénticos a producción.')


if __name__ == '__main__':
    main()
