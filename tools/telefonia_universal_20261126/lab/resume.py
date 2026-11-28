#!/usr/bin/env python3
"""Resume the existing private synthetic database; never initialize or migrate it."""
import argparse,json,os,shutil,socket,subprocess,time
from pathlib import Path

def main():
 p=argparse.ArgumentParser(description=__doc__);p.add_argument('--lab-root',required=True);p.add_argument('--mysql-bin',default='C:/xampp/mysql/bin');a=p.parse_args()
 root=Path(a.lab_root).resolve();m=json.loads((root/'lab.json').read_text());c=json.loads((root/'credentials.json').read_text())
 if m.get('synthetic') is not True or Path(m['root']).resolve()!=root or c['host']!='127.0.0.1' or c['port']!=3308 or c['database']!='impe_telefonia_lab':raise RuntimeError('Only the existing synthetic fixture is allowed')
 data=(root/'mysql').resolve()
 if data.parent!=root or data.is_symlink() or not (data/c['database']).is_dir():raise RuntimeError('Existing synthetic data directory required')
 with socket.socket() as s:
  if s.connect_ex(('127.0.0.1',3308))==0:raise RuntimeError('3308 occupied; no server touched')
 binary=Path(a.mysql_bin).resolve();server=binary/('mysqld.exe' if os.name=='nt' else 'mariadbd')
 log=open(root/'mysql-stdout.log','ab')
 opts=[str(server),'--no-defaults','--datadir='+str(data),'--basedir='+str(binary.parent),'--port=3308','--bind-address=127.0.0.1',
  '--skip-name-resolve','--skip-log-bin','--max-connections=30','--innodb-buffer-pool-size=64M','--log-error='+str(root/'mysql-error.log')]
 proc=subprocess.Popen(opts,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
 for _ in range(100):
  with socket.socket() as s:
   if s.connect_ex(('127.0.0.1',3308))==0:break
  if proc.poll() is not None:raise RuntimeError('Synthetic database did not resume; inspect its private log')
  time.sleep(.1)
 else:raise RuntimeError('Synthetic database startup deadline exceeded')
 m['pid']=proc.pid;m['resumed_utc']=time.time();(root/'lab.json').write_text(json.dumps(m,indent=2))
 subprocess.run([shutil.which('python') or 'python',str(Path(__file__).with_name('start_http.py')),'--lab-root',str(root),'--php',m['php']],check=True)
 print('LAB_RESUMED: existing data preserved; schema unchanged; no eight-hour continuity claim.')
if __name__=='__main__':main()
