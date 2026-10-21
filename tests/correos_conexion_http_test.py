"""Pruebas de los límites HTTP del controlador con contexto de sesión ficticio.
No inicia sesiones reales ni conecta cuentas. Uso: python archivo.py RUTA_PHP
"""
from pathlib import Path
import subprocess
import sys
import tempfile

root = Path(__file__).resolve().parents[1]
php = sys.argv[1]
entry = r'''<?php
$_SESSION = ['usuario_id' => 7, 'rol_id' => 4, 'rol' => 'Marketing', 'permisos' => [], 'csrf_token' => 'CSRF_QA'];
$_SERVER['REQUEST_METHOD'] = 'POST'; $_GET['action'] = 'iniciar'; $_POST = ['correo' => 'prueba@gmail.com'];
$case = $argv[2];
if ($case === 'role') { $_SESSION['rol'] = 'Otro'; }
if ($case === 'method') { $_SERVER['REQUEST_METHOD'] = 'GET'; }
if ($case === 'private') { $_GET['action'] = 'puedeAcceder'; }
if ($case === 'callback_method') { $_GET['action'] = 'callback'; }
register_shutdown_function(static function () { echo "\nHTTP_STATUS=" . http_response_code(); });
require $argv[1] . '/app/controllers/CorreoConexionController.php';
$c = new CorreoConexionController();
if ($case === 'callback_method') { $c->callback(); } else { $c->iniciar(); }
'''
with tempfile.TemporaryDirectory(prefix='impe-mail-http-') as tmp:
    path = Path(tmp) / 'entry.php'; path.write_text(entry, encoding='utf-8')
    for case, expected in [('csrf', 419), ('method', 405), ('role', 403), ('private', 404), ('callback_method', 405)]:
        r = subprocess.run([php, str(path), str(root), case], capture_output=True, text=True, timeout=10)
        assert r.returncode == 0 and f'HTTP_STATUS={expected}' in r.stdout, (case, r.stdout, r.stderr)

print('OK: autorización Gmail protegida por CSRF, rol, método, callback y acciones no expuestas. Sin proveedores ni envíos reales.')
