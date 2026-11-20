"""Construye bloques verificables de los cambios de Ventas contra las dos bases conocidas."""
import base64,difflib,hashlib,json,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE='626a7f4f4530c8a59747e8801066ccf13d169ca5'
ALT='46742dbec625e02c050012fca366628bd418ba1b'
FILES=('app/services/WhatsAppVentasService.php','app/services/WhatsAppContextoService.php','app/services/WhatsAppCloudApiService.php','app/models/WhatsAppModel.php','app/controllers/WhatsAppController.php','app/models/RolModel.php','app/views/whatsapp/index.php','app/views/whatsapp/chat.php','app/views/whatsapp/ventas_config.php','app/views/whatsapp/ventas_etiqueta.php','app/views/whatsapp/ventas_inicio.php','public/javascript/whatsapp_ventas.js')
def original(rev,name):
    p=subprocess.run(['git','show',rev+':'+name],cwd=str(ROOT),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    return None if p.returncode else p.stdout.replace(b'\r\n',b'\n').decode('utf-8')
def sha(b):return hashlib.sha256(b).hexdigest()
def build():
    rows=[]
    for name in FILES:
        after=(ROOT/name).read_bytes().replace(b'\r\n',b'\n');before=original(BASE,name)
        row=dict(path=name,new_file=before is None,after_sha=sha(after))
        if before is None:row['content']=base64.b64encode(after).decode()
        else:
            a,b=before.splitlines(True),after.decode('utf-8').splitlines(True);hunks=[]
            for group in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(3):
                first,last=group[0],group[-1];old=''.join(a[first[1]:last[2]]);new=''.join(b[first[3]:last[4]])
                h=dict(before=old,after=new)
                if name=='app/views/whatsapp/index.php' and 'action=configuracionVentas' in new:
                    anchor='\n            <?php if ($puedeGestionarCuentas): ?>\n'
                    addition=next(line for line in new.splitlines(True) if 'action=configuracionVentas' in line)
                    h=dict(before=anchor,after=anchor+addition)
                if name=='app/controllers/WhatsAppController.php' and '$plantillaVentas=' in new:
                    additions=[line for line in new.splitlines(True) if '$plantillaVentas=' in line]
                    needle='$cuenta=$modelo->' if '$cuenta=$modelo->' in old else '$ventanaServicioAbierta = '
                    anchor=next(line for line in old.splitlines(True) if needle in line)
                    if '$servicio=new WhatsAppCloudApiService(' in old:
                        service=next(line for line in old.splitlines(True) if '$servicio=new WhatsAppCloudApiService(' in line)
                        hunks.append(dict(before=service,after=service.replace('new WhatsAppCloudApiService($modelo->conexionParaAutorizacion())','$this->servicioWhatsApp($modelo)')))
                    h=dict(before=anchor,after=''.join(additions)+anchor)
                if name=='app/models/WhatsAppModel.php' and 'WhatsAppVentasService.php' in new and 'require_once' in old:
                    h=dict(before="require_once __DIR__.'/../services/WhatsAppContextoService.php';\n",after="require_once __DIR__.'/../services/WhatsAppContextoService.php';\nrequire_once __DIR__.'/../services/WhatsAppVentasService.php';\n")
                alternate=original(ALT,name)
                if alternate is not None and old not in alternate and name=='app/models/WhatsAppModel.php' and 'vincularEntradaProspecto' in old:
                    start=alternate.index('    public function vincularEntradaProspecto(')
                    end=alternate.index('    public function registrarMensajeSalida(',start)
                    # Sólo sustituir el método de reconocimiento; conservar los otros cambios de esa versión.
                    h=dict(before=before[before.index('    public function vincularEntradaProspecto('):before.index('    public function registrarMensajeSalida(')],after=after.decode()[after.decode().index('    public function vincularEntradaProspecto('):after.decode().index('    public function registrarMensajeSalida(')],alternates=[alternate[start:end]])
                hunks.append(h)
            row['hunks']=hunks
        rows.append(row)
    sql=(ROOT/'database/migrations/2026_11_20_whatsapp_ventas_config.sql').read_bytes()
    spec=dict(release='whatsapp-ventas-cartera-empresa-v1',files=rows,schema=base64.b64encode(sql).decode(),schema_sha=sha(sql))
    (ROOT/'tools/whatsapp_ventas_release.json').write_text(json.dumps(spec,ensure_ascii=False,indent=2),encoding='utf-8')
if __name__=='__main__':build()
