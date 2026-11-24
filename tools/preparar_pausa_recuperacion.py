import difflib,json,subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1]
name='app/helpers/PausaConsultasAutomaticas.php'
old=subprocess.check_output(['git','show','79a5f969bb59ed5fc6e4cd9651b7f437bd50dd59:'+name],cwd=str(root)).decode('utf-8').replace('\r\n','\n').splitlines(True)
new=(root/name).read_text(encoding='utf-8').splitlines(True)
hunks=[]
for group in difflib.SequenceMatcher(None,old,new,autojunk=False).get_grouped_opcodes(2):
    hunks.append(dict(before=''.join(old[group[0][1]:group[-1][2]]),after=''.join(new[group[0][3]:group[-1][4]])))
rows=[dict(path=name,new_file=False,hunks=hunks),dict(path='config/db_connection.php',new_file=False,prepend_guard=True)]
(root/'tools/pausa_recuperacion_release.json').write_text(json.dumps(dict(release='pausa-recuperacion-sin-llamadas-v1',files=rows),ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
p=(root/'tools/publicar_contencion_mysql.py').read_text(encoding='utf-8')
p=p.replace('import time','import time\nimport re')
p=p.replace("FILES=('app/helpers/PausaConsultasAutomaticas.php', 'index.php', 'prueba_telefonia/api/avisos_llamadas.php')",'FILES='+repr(tuple(row['path'] for row in rows)))
p=p.replace('contencion_mysql_release.json','pausa_recuperacion_release.json').replace('contencion-mysql-','pausa-recuperacion-')
p=p.replace('Pausa temporal de consultas automáticas, sin modificar privilegios ni llamadas.','Pausa web completa de recuperación, exclusivamente cuando no hay llamadas activas.')
p=p.replace("    if row['new_file']:","""    if row.get('prepend_guard'):
        if old is None:raise RuntimeError('Falta el archivo de conexión instalado')
        text=old.replace(b'\\r\\n',b'\\n').decode('utf-8')
        guard=\"require_once dirname(__DIR__).'/app/helpers/PausaConsultasAutomaticas.php';\\n\"
        if text.count(guard)==1:return old
        # Insertar sólo una línea de guarda. No leer/imprimir valores privados.
        header=re.match(r'\\A(?:\\ufeff)?<\\?php\\s*(?:(?://[^\\n]*(?:\\n|$)|/\\*.*?\\*/|\\#[^\\n]*(?:\\n|$))\\s*)*(?:declare\\s*\\(\\s*strict_types\\s*=\\s*[01]\\s*\\)\\s*;\\s*)?',text,re.S)
        if not header:raise RuntimeError('Encabezado PHP no reconocido; sitio intacto')
        new=(text[:header.end()]+guard+text[header.end():]).encode('utf-8')
        return new.replace(b'\\n',b'\\r\\n') if b'\\r\\n' in old else new
    if row['new_file']:
""",1)
p=p.replace("'hasta':int(time.time())+3600","'hasta':int(time.time())+3600,'modo':'RECUPERACION_SIN_LLAMADAS'")
p=p.replace('PAUSA_ACTIVA: 60 minutos; avisos y sondeos no críticos. Sin cambios al cron. Webhooks, telefonía activa y guardado de resultados no se interceptan.','PAUSA_RECUPERACION_ACTIVA: 60 minutos; solicitudes web del CRM detenidas antes de MySQL. No iniciar llamadas. Webhooks y diagnóstico SSH excluidos.')
p=p.replace('Contención temporal instalada; no se cambiaron privilegios MySQL.','Pausa de recuperación instalada; no se cambiaron credenciales ni privilegios MySQL.')
(root/'tools/publicar_pausa_recuperacion.py').write_text(p,encoding='utf-8')
print('Paquete: helper de pausa y una línea de guarda antes de la conexión, con respaldo privado.')
