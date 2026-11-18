"""Entrega completa contra la base sintética creada por supervision_comercial_v3_db.php."""
from pathlib import Path
import importlib
import os
import subprocess
import sys
import tempfile

if os.environ.get('IMPE_TIPOS_PUBLICACION_TEST') != 'YES':
    raise SystemExit(2)
nombre = sys.argv[1]
if not nombre.startswith('impe_supervision_test_') or not nombre.replace('_', '').isalnum():
    raise SystemExit(2)
root = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(root/'tools'))
p = importlib.import_module('publicar_tipos_actividad_v3_hostinger')

with tempfile.TemporaryDirectory(prefix='impe-tipos-deploy-db-') as tmp:
    site = Path(tmp)/'public_html'
    stage = Path(tmp)/'stage'
    backups = Path(tmp)/'backups'
    for rel, old, new in p.ARCHIVOS:
        for base, blob in [(site, old), (stage, new)]:
            if blob:
                target = base/rel
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(subprocess.check_output(['git', 'cat-file', 'blob', blob], cwd=root))
    for rel, blob in p.DEPENDENCIAS.items():
        target = site/rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(subprocess.check_output(['git', 'cat-file', 'blob', blob], cwd=root))
    (site/'config').mkdir()
    (site/'config/db_connection.php').write_text('''<?php
class Database {
 public function connect($x=false){
  $db=new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),
   '''+repr(nombre)+''',(int)getenv('MYSQL_PORT'));
  $db->set_charset('utf8mb4');return $db;
 }
}
''', encoding='utf-8')

    def sql(query):
        return subprocess.check_output(['php', '-r',
            "require $argv[1].'/config/db_connection.php';$db=(new Database())->connect();"+query,
            '--', str(site)], stderr=subprocess.STDOUT).decode()

    # Only the disposable fixture may have its new columns removed.
    sql("$db->query('ALTER TABLE prospecto_embudo_actividad_v3 DROP COLUMN tipo_actividad, DROP COLUMN detalle_actividad');")
    original = sql("echo json_encode($db->query('SELECT * FROM prospecto_embudo_actividad_v3 ORDER BY id')->fetch_all(MYSQLI_ASSOC));")
    common = [sys.executable, str(root/'tools/publicar_tipos_actividad_v3_hostinger.py'),
              '--site', str(site), '--stage', str(stage)]

    def run(*args):
        result = subprocess.run(common+list(args), capture_output=True, text=True)
        assert result.returncode == 0, result.stdout+result.stderr
        return result.stdout

    checked = run('--check')
    assert 'ESQUEMA_TIPOS_PENDIENTE' in checked and 'CHECK_OK' in checked
    assert not backups.exists(), 'CHECK no escribe ni respalda'
    assert not p.esquema_tipos(str(site)), 'CHECK no crea columnas'
    applied = run('--apply', '--backup-root', str(backups), '--confirm', 'PUBLICAR_SEGUIMIENTO_ACCIONES_V3_IMPE')
    assert 'APPLY_OK' in applied and 'ESQUEMA_TIPOS_OK' in applied
    assert p.esquema_tipos(str(site))
    assert 'ESQUEMA_TIPOS_OK' in run('--check')
    run('--apply', '--backup-root', str(backups), '--confirm', 'PUBLICAR_SEGUIMIENTO_ACCIONES_V3_IMPE')
    assert len(list(backups.iterdir())) == 1, 'APPLY idempotente'
    columns = 'id,asignacion_id,usuario_id,etapa,accion,motivo,fecha_anterior,fecha_pendiente,completada_at,subetapa_anterior,subetapa_nueva,creado_at'
    assert original == sql("echo json_encode($db->query('SELECT "+columns+" FROM prospecto_embudo_actividad_v3 ORDER BY id')->fetch_all(MYSQLI_ASSOC));"), 'histórico intacto'
    # ROLLBACK retains types written after publication; no DROP or historical rewrite.
    sql("$db->query(\"UPDATE prospecto_embudo_actividad_v3 SET tipo_actividad='DOCUMENTOS',detalle_actividad='Revisar certificado <prueba>.' WHERE asignacion_id=4\");")
    backup = next(backups.iterdir())
    assert 'ROLLBACK_OK' in run('--rollback', '--backup-dir', str(backup), '--confirm', 'REVERTIR_SEGUIMIENTO_ACCIONES_V3_IMPE')
    assert p.esquema_tipos(str(site))
    assert sql("echo $db->query(\"SELECT tipo_actividad FROM prospecto_embudo_actividad_v3 WHERE asignacion_id=4\")->fetch_row()[0];") == 'DOCUMENTOS'
    for rel, old, new in p.ARCHIVOS:
        assert (p.publicador.blob(str(site/rel)) == old) if old else not (site/rel).exists()
    # Exercise the actual additive publisher's recovery after a partial file swap.
    p.publicador.ARCHIVOS = p.ARCHIVOS
    p.configurar_dependencias(str(site))
    atomico = p.publicador.atomico
    llamadas = [0]

    def falla_segundo(dest, source, mode):
        llamadas[0] += 1
        if llamadas[0] == 2:
            raise OSError('fallo sintético de publicación')
        return atomico(dest, source, mode)

    p.publicador.atomico = falla_segundo
    try:
        try:
            p.publicar_tipos(str(site), str(stage), str(Path(tmp)/'fallo-backups'))
        except OSError:
            pass
        else:
            raise AssertionError('Se esperaba fallo sintético')
    finally:
        p.publicador.atomico = atomico
    for rel, old, new in p.ARCHIVOS:
        assert (p.publicador.blob(str(site/rel)) == old) if old else not (site/rel).exists()
    assert p.esquema_tipos(str(site)), 'reversión de código conserva ampliación'
    print('CHECK_OK entrega real aislada: CHECK sin SQL, ALTER instantáneo, APPLY, histórico intacto, idempotencia y ROLLBACK conservando tipos.')
