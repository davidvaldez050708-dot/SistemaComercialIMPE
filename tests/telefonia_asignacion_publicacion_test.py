"""Comprueba instalación selectiva, conflicto seguro, idempotencia y reversión."""
import importlib.util
import json
import os
import re
from pathlib import Path
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
publisher = root/'tools/publicar_fecha_asignacion_analista.py'
spec = json.loads((root/'tools/fecha_asignacion_analista_release.json').read_text(encoding='utf-8'))
module_spec = importlib.util.spec_from_file_location('publicador_fecha', publisher)
module = importlib.util.module_from_spec(module_spec)
module_spec.loader.exec_module(module)
name = 'app/services/TelefoniaEntrantesAnalistaService.php'
baseline = root/'tmp/analista-161-live'/name
old = baseline.read_bytes() if baseline.exists() else subprocess.check_output(['git', 'show', 'HEAD:'+name], cwd=str(root))
row = spec['files'][1]
# Puede ejecutarse también después del commit de la corrección.
for hunk in row['hunks']:
    old = old.replace(hunk['after'].encode(), hunk['before'].encode())
new = module.candidate(old, row)
assert module.candidate(new, row) == new
expected = old.replace(b'\r\n', b'\n').decode().replace(row['hunks'][0]['before'], row['hunks'][0]['after'], 1)
assert new.replace(b'\r\n', b'\n').decode() == expected
assert len(new) > len(old)

with tempfile.TemporaryDirectory(prefix='analista-fecha-test-') as directory:
    site = Path(directory)/'site'
    backups = Path(directory)/'privado'
    target = site/name
    target.parent.mkdir(parents=True)
    (site/'app/helpers').mkdir()
    (site/'index.php').write_text('<?php', encoding='utf-8')
    target.write_bytes(old)
    def run(*args, success=True):
        r = subprocess.run([os.sys.executable, str(publisher), '--site', str(site), '--backup-root', str(backups), *args], stdout=subprocess.PIPE, stderr=subprocess.STDOUT, env=dict(os.environ, PYTHONIOENCODING='utf-8'))
        assert (r.returncode == 0) == success, r.stdout.decode()
        return r.stdout.decode()
    assert 'COMPROBACION_OK: 2 archivos' in run('--check')
    assert target.read_bytes() == old and not (site/spec['files'][0]['path']).exists()
    assert 'PUBLICACION_OK: 2 archivos' in run('--apply')
    assert target.read_bytes() == new
    # Ejecutar el método real instalado con una lectura MySQL simulada.
    # Se omiten sólo imports de arranque para no abrir conexiones ni APIs.
    isolated = re.sub(r'^require_once[^\n]*\n', '', new.decode('utf-8'), flags=re.M)
    target.write_text(isolated, encoding='utf-8')
    probe = r'''<?php
require $argv[1];
class FilaPrueba { public function fetch_assoc(){return ['extension'=>'161','desde'=>'2026-11-24 17:24:46','permite_salientes'=>1,'permiso_salientes'=>1];} }
class LecturaPrueba {
 public function bind_param($tipos,&$usuario){if($usuario!==30)throw new Exception('Usuario alterado');}
 public function execute(){} public function get_result(){return new FilaPrueba();} public function close(){}
}
class DbPrueba {
 public function prepare($sql){
  foreach(['te.usuario_id = ?', 'te.activo = 1', 'u.estado = 1', 'te.permite_entrantes = 1'] as $filtro)
   if(strpos($sql,$filtro)===false)throw new Exception('Filtro de propietario eliminado');
  return new LecturaPrueba();
 }
}
date_default_timezone_set('America/Mexico_City');
$r=new ReflectionClass('TelefoniaEntrantesAnalistaService');$obj=$r->newInstanceWithoutConstructor();
$db=$r->getProperty('db');$db->setAccessible(true);$db->setValue($obj,new DbPrueba());
$m=$r->getMethod('extensionActual');$m->setAccessible(true);$fila=$m->invoke($obj,30);
if($fila['desde']!=='2026-11-24 11:24:46'||$fila['extension']!=='161'||$fila['permiso_salientes']!==1)throw new Exception('Asignacion incorrecta');
echo 'ASIGNACION_NORMALIZADA_OK';
'''
    probe_file = Path(directory)/'probe.php'
    probe_file.write_text(probe, encoding='utf-8')
    result = subprocess.check_output(['php', str(probe_file), str(target)])
    assert b'ASIGNACION_NORMALIZADA_OK' in result
    target.write_bytes(new)
    assert 'SIN_CAMBIOS' in run('--apply')
    backup = next(backups.iterdir())
    assert 'RESTAURACION_OK: 2 archivos' in run('--restore', str(backup))
    assert target.read_bytes() == old and not (site/spec['files'][0]['path']).exists()
    conflict = old.replace(b'Asigna y activa', b'Asignacion personalizada')
    target.write_bytes(conflict)
    assert 'BLOQUEADO' in run('--apply', success=False)
    assert target.read_bytes() == conflict and not (site/spec['files'][0]['path']).exists()
print('OK: archivo instalado conservado; validación previa, idempotencia, conflicto y restauración sin MySQL.')
