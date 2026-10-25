"""Renderiza el sidebar real en un sitio temporal, sin sesiones ni base de datos reales."""
import json, os, pathlib, re, shutil, subprocess, tempfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as folder:
    site = pathlib.Path(folder)
    for name in ['app/views/layout/sidebar.php', 'app/helpers/AvatarHelper.php',
                 'app/helpers/PermissionHelper.php', 'app/helpers/AdminRolePolicy.php',
                 'app/helpers/SupervisionRolePolicy.php']:
        target = site/name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT/name, target)
    service = site/'app/services/AliadoInternacionalService.php'
    service.parent.mkdir(parents=True)
    service.write_text('<?php class AliadoInternacionalService { public static function puedeConsultar() { return false; } }', encoding='utf8')
    # El permiso se evalúa con los helpers reales y un usuario ficticio.
    permissions = sorted(set(re.findall(r"'([a-z_]+\.[a-z_.]+)'", (ROOT/'app/views/layout/sidebar.php').read_text(encoding='utf8'))))
    php = '''<?php
    define('BASE_URL', 'http://sidebar.test/'); define('ROOT_PATH', __DIR__);
    $_SESSION = ['usuario_id'=>999, 'rol_id'=>2, 'nombre'=>'Susana', 'apellidos'=>'Molina',
        'rol'=>'Director de Operaciones', 'permisos'=>json_decode($argv[1],true)];
    $nombreCompleto='Susana Molina';
    require __DIR__.'/app/views/layout/sidebar.php';
    '''
    (site/'render.php').write_text(php, encoding='utf8')
    result = subprocess.run([os.environ.get('PHP_BINARY', 'php'), str(site/'render.php'), json.dumps(permissions)], capture_output=True, check=True)
    html = '''<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="/bootstrap.css"><link rel="stylesheet" href="/icons/bootstrap-icons.css">
    <link rel="stylesheet" href="/public/css/global.css"><link rel="stylesheet" href="/public/css/dashboard.css">
    <link rel="stylesheet" href="/public/css/responsive_sistema.css"><body>
    <button id="open" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar">Abrir menú</button>
    ''' + result.stdout.decode('utf8') + '<script src="/bootstrap.js"></script></body></html>'
    (site/'fixture.html').write_text(html, encoding='utf8')
    subprocess.run(['node', str(ROOT/'tests/sidebar_movil_browser.cjs'), str(site/'fixture.html')], check=True)
