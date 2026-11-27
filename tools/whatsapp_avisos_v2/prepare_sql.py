from pathlib import Path
import subprocess,sys
root=Path(__file__).resolve().parents[2]
subprocess.run([sys.executable,str(root/'tools/whatsapp_asignacion_v2/prepare_ci.py')],check=True)
p=root/'tests/whatsapp_ventas_db.php'
s=p.read_text(encoding='utf-8')
anchor=" require __DIR__.'/../tools/whatsapp_asignacion_v2/test.php';"
assert s.count(anchor)==1
s=s.replace(anchor,anchor+"\n require __DIR__.'/../tools/whatsapp_avisos_v2/test_sql.php';")
p.write_text(s,encoding='utf-8')
# Resolve the service against the native app scope implementation, not tools/.
p=root/'tools/whatsapp_avisos_v2/test_sql.php'
s=p.read_text(encoding='utf-8').replace("require_once __DIR__.'/WhatsAppAvisosService.php';","require_once __DIR__.'/../../app/services/WhatsAppAvisosService.php';")
p.write_text(s,encoding='utf-8')
(root/'app/services/WhatsAppAvisosService.php').write_bytes((p.parent/'WhatsAppAvisosService.php').read_bytes())
