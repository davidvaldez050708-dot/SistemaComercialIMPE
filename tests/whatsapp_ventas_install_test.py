import json,os,shutil,subprocess,sys,tempfile
from pathlib import Path
root=Path(__file__).resolve().parents[1];schema=sys.argv[1]
assert schema.startswith('impe_wa_ventas_test_') and schema.replace('_','').isalnum()
env=dict(os.environ,IMPE_DB_HOST='127.0.0.1',IMPE_DB_USER=os.environ['MYSQL_USER'],IMPE_DB_PASSWORD=os.environ['MYSQL_PASSWORD'],IMPE_DB_NAME=schema)
# PHP -r helper receives only a disposable local schema, never production credentials.
def run(args,ok=True):
 p=subprocess.run(args,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 if ok:assert p.returncode==0,(p.stdout+p.stderr).decode(errors='replace')
 return p
php=['php','-d','mysqli.default_port='+env['MYSQL_PORT']]
env['PHPRC']='' # Explicit port for PHP children launched by the publisher as well.
with tempfile.TemporaryDirectory(prefix='wa-ventas-install-') as tmp:
 tmp=Path(tmp);site=tmp/'site';site.mkdir();backup=tmp/'private';backup.mkdir()
 release=json.loads((root/'tools/whatsapp_ventas_release.json').read_text(encoding='utf-8'))
 for row in release['files']:
  p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True)
  if not row['new_file']:p.write_bytes(subprocess.check_output(['git','show','626a7f4:'+row['path']],cwd=str(root)))
 (site/'index.php').write_text('<?php')
 (site/'config').mkdir();(site/'config/db_connection.php').write_text("<?php class Database {public function connect($p=false){return new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),getenv('IMPE_DB_NAME'),(int)getenv('MYSQL_PORT'));}}")
 original={row['path']:(site/row['path']).read_bytes() if (site/row['path']).exists() else None for row in release['files']}
 command=[sys.executable,str(root/'tools/publicar_whatsapp_ventas.py'),'--site',str(site),'--backup-root',str(backup)]
 run(command+['--check']);assert original=={name:(site/name).read_bytes() if (site/name).exists() else None for name in original}
 run(command+['--apply']);assert b'WhatsAppVentasService' in (site/'app/models/WhatsAppModel.php').read_bytes()
 run(command+['--apply']);records=list(backup.glob('whatsapp-ventas-*/manifest.json'));assert len(records)==1
 marker=backup/'permissions.json';permissions=php+[str(root/'tools/whatsapp_ventas_permisos.php'),str(site)]
 run(permissions+['check',str(marker)]);run(permissions+['apply',str(marker)]);saved=marker.read_bytes();run(permissions+['apply',str(marker)]);assert marker.read_bytes()==saved
 data=json.loads(saved);assert len(data['after'])==6 and all(int(row['rol_id']) in [1,2,6] for row in data['after'])
 run(permissions+['restore',str(marker)])
 run(command+['--restore',str(records[0].parent)])
 assert original=={name:(site/name).read_bytes() if (site/name).exists() else None for name in original}
 # One incompatible block must leave the complete site unchanged.
 model=site/'app/models/WhatsAppModel.php';model.write_text('<?php /* unrelated custom version */')
 assert run(command+['--apply'],False).returncode!=0
 assert not (site/'app/services/WhatsAppVentasService.php').exists()
print('PASS instalación: comprobación, aplicación, permisos con respaldo, repetición, restauración y bloqueo íntegro ante conflicto; sólo BD desechable.')
