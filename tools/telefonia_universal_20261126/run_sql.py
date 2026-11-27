#!/usr/bin/env python3
"""Run native SQL regressions only in the private synthetic loopback laboratory."""
import argparse,json,os,shutil,subprocess,time
from pathlib import Path
SOURCE=Path(__file__).resolve().parents[2]
def main():
 p=argparse.ArgumentParser(description=__doc__);p.add_argument('--lab-root',required=True);p.add_argument('--php',default=shutil.which('php') or 'C:/xampp/php/php.exe');a=p.parse_args()
 root=Path(a.lab_root).resolve();m=json.loads((root/'lab.json').read_text());c=json.loads((root/'credentials.json').read_text())
 if m.get('synthetic') is not True or c.get('host')!='127.0.0.1' or c.get('port')!=3308 or c.get('database')!='impe_telefonia_lab':raise RuntimeError('Only private synthetic loopback allowed')
 env=os.environ.copy();env['IMPE_PHONE_LAB_ROOT']=str(root)
 scripts=['provider_sdk','prepare_sales','prepare_attention','incoming_reducer_sql','sales_native_sql','approved_result_sql','callback_result_sql','callback_intent_sql','followup_receipt_sql','informative_receipt_sql','institution_funnel_sql','funnel_v3_sql','outgoing_intent_sql','canonical_capture_sql']
 results=[];started=time.monotonic()
 for name in scripts:
  r=subprocess.run([a.php,str(Path(__file__).parent/'integration'/(name+'.php'))],cwd=SOURCE,env=env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=120)
  print(r.stdout.strip(),flush=True)
  if r.returncode:print(r.stderr.strip(),flush=True)
  results.append(dict(test=name,exit_code=r.returncode,output=r.stdout.strip()))
  if r.returncode:break
 report=dict(synthetic=True,elapsed_monotonic_seconds=time.monotonic()-started,tests=results,complete_call_acceptance=False)
 (root/'native-sql-validation.json').write_text(json.dumps(report,indent=2,ensure_ascii=False),encoding='utf-8')
 if len(results)!=len(scripts) or any(x['exit_code'] for x in results):raise SystemExit(1)
 print('NATIVE_SQL_REGRESSIONS_OK: not a 1000-call or eight-hour acceptance result.')
if __name__=='__main__':main()
