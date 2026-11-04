import base64,gzip,json,pathlib,re
R=pathlib.Path(__file__).resolve().parents[1]
manifest=json.loads((R/'tools/analista_salientes_release.json').read_text(encoding='utf8'))
for row in manifest['files']:
 for v in row['variants']:
  if v['base'] is None:continue
  old=gzip.decompress(base64.b64decode(v['base'])).decode();new=gzip.decompress(base64.b64decode(v['target'])).decode()
  if row['path'].endswith('telefonia_host.js'):
   def section(s,name):
    match=re.search(r'^    const '+name+r' =',s,re.M);assert match,name
    following=re.search(r'^    const ',s[match.end():],re.M)
    return s[match.start():match.end()+following.start()] if following else s[match.start():]
   for name in ['ensureWidget','endOutgoingWebrtc','startCall','applyIncomingState']:
    assert section(old,name)==section(new,name),'Transporte alterado: '+name
  if row['path'].endswith('TelefoniaEntrantesAnalistaService.php'):
   for name,end in [('registrar','registrarAtencionEnExpediente')]:
    begin=old.index('    public function '+name+'(');finish=old.index('    public function '+end+'(',begin)
    begin2=new.index('    public function '+name+'(');finish2=new.index('    public function '+end+'(',begin2)
    assert old[begin:finish]==new[begin2:finish2],'Transacción original alterada'
   marker='    public function registrarAtencionEnExpediente('
   assert old[old.index(marker):]==new[new.index(marker):],'No duplicar reglas del expediente'
  if row['path'].endswith('telefonia_analista_historial_pasivo.js'):
   assert new.startswith(old),'Observador de recepción alterado'
print('PASS preservacion: SDK, marcacion, audio, recepcion, observador entrante y transaccion de expediente identicos en ambas variantes')
