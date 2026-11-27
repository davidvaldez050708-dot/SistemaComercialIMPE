"""Instalador de permisos únicamente contra la base ficticia local de la prueba PHP."""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]


class PermisosCliTest(unittest.TestCase):
    def test_actualizacion_idempotente_sin_activar_ni_conceder_a_otros_roles(self):
        cfg=json.loads((ROOT/'tmp/sp-test-state.json').read_text())
        import re
        self.assertRegex(cfg['database'],r'^impe_sp_test_[a-f0-9]{8}$')
        self.assertIn(cfg['port'],[33319,33320])
        connection="mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=new mysqli('127.0.0.1','root','',"+repr(cfg['database'])+","+str(cfg['port'])+");"
        def sql(code):
            return subprocess.check_output(['php'],input='<?php '+connection+code,encoding='utf-8',cwd=ROOT)
        before=sql("echo json_encode($db->query('SELECT * FROM supervision_personal_estado')->fetch_all(MYSQLI_ASSOC));")
        # Simular exactamente la instalación anterior: Gestión existe, Ver/Bitácora todavía no.
        sql("$db->query(\"DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE p.codigo IN ('personal.supervision.ver','personal.supervision.bitacora')\");$db->query(\"DELETE FROM permisos WHERE codigo IN ('personal.supervision.ver','personal.supervision.bitacora')\");")
        with tempfile.TemporaryDirectory(prefix='sp-permisos-cli-') as tmp:
            private=Path(tmp);site=private/'sitio';(site/'config').mkdir(parents=True);(site/'index.php').write_text('<?php')
            (site/'config/db_connection.php').write_text("<?php class Database { public function connect($p=false) { return new mysqli('127.0.0.1','root','',"+repr(cfg['database'])+","+str(cfg['port'])+"); } }")
            command=['php',str(ROOT/'tools/supervision_personal_permisos.php'),'--site',str(site)]
            def run(*args):return subprocess.run(command+list(args),capture_output=True,text=True)
            self.assertIn('existentes=1 de 3',run('--check').stdout)
            self.assertNotEqual(run('--apply','--actor','1','--respaldo',str(private/'sin-autorizacion.json')).returncode,0)
            first=run('--apply','--autorizar','--actor','1','--respaldo',str(private/'antes.json'))
            self.assertEqual(first.returncode,0,first.stdout+first.stderr);self.assertIn('nuevos=2',first.stdout)
            self.assertIn('existentes=3 de 3',run('--check').stdout)
            second=run('--apply','--autorizar','--actor','1','--respaldo',str(private/'segunda.json'))
            self.assertEqual(second.returncode,0);self.assertIn('nuevos=0',second.stdout)
            self.assertEqual(len({r['codigo'] for r in json.loads((private/'antes.json').read_text())['permisos_antes']}),1)
        after=sql("echo json_encode($db->query('SELECT * FROM supervision_personal_estado')->fetch_all(MYSQLI_ASSOC));")
        self.assertEqual(before,after,'El instalador no activa ni cambia la revisión de supervisión')
        self.assertEqual(sql("echo $db->query(\"SELECT COUNT(*) n FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE p.codigo LIKE 'personal.supervision.%' AND rp.rol_id<>1\")->fetch_assoc()['n'];"),'0')


if __name__=='__main__':unittest.main()
