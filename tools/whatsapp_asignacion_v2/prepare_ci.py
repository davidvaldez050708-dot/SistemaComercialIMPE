from pathlib import Path
import subprocess,sys
root=Path(__file__).resolve().parents[2]
here=Path(__file__).resolve().parent
subprocess.run([sys.executable,str(root/'tools/whatsapp_recepcion_v1/prepare_ci.py')],check=True)
sys.path.insert(0,str(here))
from check import build
for path,new in build(root): path.write_bytes(new)
p=root/'tests/whatsapp_ventas_db.php'
s=p.read_text(encoding='utf-8')
s=s.replace('whatsapp_verificado VARCHAR(80),activo INT','whatsapp_verificado VARCHAR(80),telefono_fuente VARCHAR(40) NULL,telefono_verificado VARCHAR(40) NULL,activo INT')
s=s.replace('INSERT INTO seguimientos_vinculacion VALUES','INSERT INTO seguimientos_vinculacion(id,estado_id,municipio_id,analista_id,nombre_entidad,contacto_nombre,whatsapp_verificado,activo) VALUES')
anchor=" require __DIR__.'/whatsapp_ventas_cases.php';"
assert s.count(anchor)==1
s=s.replace(anchor," require __DIR__.'/../tools/whatsapp_asignacion_v2/fixture.php';\n"+anchor+"\n require __DIR__.'/../tools/whatsapp_asignacion_v2/test.php';")
p.write_text(s,encoding='utf-8')
