#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Prueba local y reversible del publicador comercial, con archivos ficticios.
No escribe en Hostinger ni requiere datos, credenciales o base de datos.
Ejecutar desde checkout: python3 tests/publicar_embudo_cierre_api_hostinger_v1_test.py
"""
from pathlib import Path
import importlib.util
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'tools/publicar_embudo_cierre_api_hostinger_v1.py'
spec = importlib.util.spec_from_file_location('pub_embudo_v1', str(SCRIPT))
pub = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pub)

GIT_BASE_MOVIMIENTO = 'f1724383a62b22d22ad3176ae2a38b91259c8618'
GIT_BASE_TELEFONIA = 'bc1c6c635fca7ed3b89e61c15aa2edef7064d911'


def need(value, reason):
    if not value:
        raise AssertionError(reason)


def git(ref, path):
    proc = subprocess.run(
        ['git', 'show', ref + ':' + path], cwd=str(ROOT),
        stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    need(proc.returncode == 0, 'No disponible en Git: ' + ref + ':' + path)
    return proc.stdout


def unique_replace(text, old, new):
    need(text.count(old) == 1, 'Marcador distinto: ' + repr(old[:72]))
    return text.replace(old, new, 1)


def remove_between(text, left, right):
    need(text.count(left) == 1 and text.count(right) == 1,
         'Falta bloque: ' + left)
    i = text.index(left)
    j = text.index(right, i + len(left))
    need(j > i, 'Orden de marcador incorrecto')
    return text[:i] + text[j:]


js_path = 'public/javascript/telefonia_ventas_resultados.js'
service_path = 'app/services/TelefoniaResultadoVentasService.php'
bridge_path = 'app/services/EmbudoComercialResultadoBridge.php'
board_path = 'app/views/embudo_comercial/index.php'
move_path = 'public/javascript/embudo_comercial_movimientos.js'
need(len(pub.FILES) == 5, 'Solo cinco archivos autorizados')
need(len(pub.GUARDS) == 3, 'Formulario, cierre API y etapas protegidos')
need(service_path in pub.FILES and bridge_path in pub.FILES, 'Puente y servicio en lista')
need(js_path in pub.FILES and move_path in pub.FILES, 'Solo JS del embudo y resultados')
need(board_path in pub.FILES, 'Cache-busting de embudo')

sources = {}
for name, (_, after) in pub.FILES.items():
    raw = (ROOT / name).read_bytes()
    need(pub.blob(raw) == after, 'Fuente nueva inesperada: ' + name)
    sources[name] = raw
need(b'if(nextDate)nextDate.required=seguimiento;' in sources[js_path],
     'Validacion V4 de fecha perdida')
need(b"'PROSPECTO_POTENCIAL'" in
     (ROOT / 'app/services/EmbudoComercialEtapas.php').read_bytes(),
     'Prospecto potencial sin mapeo comercial')

# Reconstruir las versiones anteriores exactas, sin incluir datos reales.
before = {}
js = sources[js_path].decode('utf-8')
js = unique_replace(
    js,
    'if(!call||call.registro||call.pbx_call_id!==callRef||',
    'if(!call||call.registro||!call.conectada||call.pbx_call_id!==callRef||')
js = remove_between(
    js, ' // Este dato nació de un clic explícito',
    " form.addEventListener('submit',async e=>{")
js = remove_between(
    js, '  // El formulario iniciado por el cierre automático',
    '  const savedContext=')
js = unique_replace(
    js,
    "   try{\n    for(const key of savedDrafts)sessionStorage.removeItem(key);\n"
    "    if(vinculo)sessionStorage.removeItem('impe:embudo:llamada-pendiente');\n"
    "   }catch(_){}\n\n",
    "   try{for(const key of savedDrafts)sessionStorage.removeItem(key);}catch(_){}\n")
before[js_path] = js.encode('utf-8')

service = sources[service_path].decode('utf-8')
service = unique_replace(
    service,
    '                    $usuarioId, (int)$idComercial, $call, $resultado, $next ?? null,\n'
    '                    (bool)$cierreApiVerificado\n',
    '                    $usuarioId, (int)$idComercial, $call, $resultado, $next ?? null\n')
before[service_path] = service.encode('utf-8')

bridge = sources[bridge_path].decode('utf-8')
bridge = unique_replace(
    bridge,
    '        array $call,string $resultado,?string $proximaAccion,\n'
    '        bool $cierreApiVerificado=false): array',
    '        array $call,string $resultado,?string $proximaAccion): array')
bridge = unique_replace(
    bridge,
    r"""        // Cierre saliente acreditado por API: no es un evento NOTIFY_OUT_END.
        $finalizada = !empty($call['finalizada']) ||
            ($cierreApiVerificado &&
             ($call['direccion']??'')==='Saliente' &&
             preg_match('/^out_[A-Za-z0-9_-]{6,100}$/D',$pbx));
        if (!preg_match('/^\d{3,6}$/D',$ext) ||
            !preg_match('/^[a-zA-Z0-9_-]{6,100}$/D',$pbx) ||
            !$finalizada) {""",
    r"""        if (!preg_match('/^\d{3,6}$/D',$ext) ||
            !preg_match('/^[a-zA-Z0-9_-]{6,100}$/D',$pbx) ||
            empty($call['finalizada'])) {""")
before[bridge_path] = bridge.encode('utf-8')

board = sources[board_path].decode('utf-8')
board = unique_replace(
    board,
    "public/javascript/embudo_comercial_movimientos.js?v=<?= filemtime(__DIR__.'/../../../public/javascript/embudo_comercial_movimientos.js') ?>",
    "public/javascript/embudo_comercial_movimientos.js?v=2")
before[board_path] = board.encode('utf-8')
before[move_path] = git(GIT_BASE_MOVIMIENTO, move_path)

for name, (old, _) in pub.FILES.items():
    need(pub.blob(before[name]) == old, 'Base Hostinger distinta: ' + name)

guards = {}
for name, expected in pub.GUARDS.items():
    raw = ((ROOT / name).read_bytes() if 'EmbudoComercialEtapas' in name
           else git(GIT_BASE_TELEFONIA, name))
    need(pub.blob(raw) == expected, 'Guardia inesperada: ' + name)
    guards[name] = raw

with tempfile.TemporaryDirectory(prefix='impe-embudo-publisher-test-') as work:
    root = Path(work)
    site = root / 'public_html'
    site.mkdir()
    inputs = root / 'fuentes'
    backup_root = root / 'backups'

    for name, raw in before.items():
        dest = site / name
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(raw)
    for name, raw in guards.items():
        dest = site / name
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(raw)
    for name, raw in sources.items():
        dest = inputs / name
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(raw)

    need(pub.scan(site, inputs) == {name: pair[0] for name, pair in pub.FILES.items()},
         'Precheck no reconocio sitio original')
    need(not backup_root.exists(), 'El precheck genero respaldo')

    try:
        pub.publish(site, inputs, backup_root, None)
    except pub.Blocked:
        pass
    else:
        raise AssertionError('Se publico sin confirmacion')
    need(all((site / name).read_bytes() == raw for name, raw in before.items()),
         'Intento no autorizado modifico archivos')

    pub.publish(site, inputs, backup_root, pub.CONFIRM)
    need(all((site / name).read_bytes() == raw for name, raw in sources.items()),
         'Publicacion simulada incompleta')
    need(all((site / name).read_bytes() == raw for name, raw in guards.items()),
         'Se modifico telefonia protegida')
    backups = list(backup_root.iterdir())
    need(len(backups) == 1, 'Respaldo privado no registrado')
    pub.publish(site, inputs, backup_root, pub.CONFIRM)
    need(len(list(backup_root.iterdir())) == 1, 'Repetir no debe crear respaldo')

    target = site / bridge_path
    target.write_bytes(target.read_bytes() + b'\n# personalizacion ajena')
    try:
        pub.rollback(site, backups[0], pub.CONFIRM)
    except pub.Blocked:
        pass
    else:
        raise AssertionError('Rollback borro personalizacion desconocida')
    target.write_bytes(sources[bridge_path])
    pub.rollback(site, backups[0], pub.CONFIRM)
    need(all((site / name).read_bytes() == raw for name, raw in before.items()),
         'Rollback no restauro originales exactos')
    need(all((site / name).read_bytes() == raw for name, raw in guards.items()),
         'Rollback modifico formulario o servicios protegidos')

print('CHECK OK: 5 archivos, hashes exactos, precheck, guardias, respaldo, idempotencia y rollback; solo fixtures.')
