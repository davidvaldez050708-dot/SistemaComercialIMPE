#!/usr/bin/env python3
"""Start three synthetic loopback HTTP workers; no production configuration."""
import argparse,json,os,secrets,shutil,socket,subprocess,time
from pathlib import Path

def main():
 p=argparse.ArgumentParser(description=__doc__);p.add_argument('--lab-root',required=True);p.add_argument('--php',default=shutil.which('php') or 'C:/xampp/php/php.exe');a=p.parse_args()
 root=Path(a.lab_root).resolve();m=json.loads((root/'lab.json').read_text());c=json.loads((root/'credentials.json').read_text())
 if m.get('synthetic') is not True or c['host']!='127.0.0.1' or c['port']!=3308 or c['database']!='impe_telefonia_lab':raise RuntimeError('Not synthetic lab')
 for port in (33180,33181,33182):
  with socket.socket() as s:
   if s.connect_ex(('127.0.0.1',port))==0:raise RuntimeError('HTTP port occupied; no existing process touched')
 c.setdefault('fixture_key',secrets.token_hex(24));(root/'credentials.json').write_text(json.dumps(c),encoding='utf-8');(root/'credentials.json').chmod(0o600)
 sessions=root/'sessions';sessions.mkdir(exist_ok=True,mode=0o700);workers=[]
 for port in (33180,33181,33182):
  env=os.environ.copy();env.update(IMPE_PHONE_LAB_ROOT=str(root),IMPE_PHONE_LAB_PORT=str(port))
  log=open(root/f'http-{port}.log','ab')
  cmd=[a.php,'-d','allow_url_fopen=0','-d','disable_functions=mail,curl_exec,fsockopen,pfsockopen,stream_socket_client','-d',f'session.save_path={sessions}','-d','display_errors=0','-d','log_errors=1','-d',f'error_log={root / "php-error.log"}','-S',f'127.0.0.1:{port}','-t',str(root/'site'),str(Path(__file__).with_name('router.php'))]
  proc=subprocess.Popen(cmd,env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
  workers.append(dict(pid=proc.pid,port=port))
  for _ in range(100):
   with socket.socket() as s:
    if s.connect_ex(('127.0.0.1',port))==0:break
   if proc.poll() is not None:raise RuntimeError('HTTP fixture failed; read private log')
   time.sleep(.05)
 (root/'http.json').write_text(json.dumps(dict(synthetic=True,workers=workers),indent=2))
 print('LAB_HTTP_READY: 127.0.0.1:33180,33181,33182; actual session/auth/CSRF endpoints; provider synthetic only.')
if __name__=='__main__':main()
