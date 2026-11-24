"""Genera bloques contra las lecturas instaladas; nunca reemplaza archivos completos."""
import base64,difflib,hashlib,json
from pathlib import Path
root=Path(__file__).resolve().parents[1]
baseline=root/'tmp/prevencion-actual-live'
rows=[]
def new_file(name):
    data=(root/name).read_text(encoding='utf-8').encode()
    rows.append(dict(path=name,new_file=True,content=base64.b64encode(data).decode(),after_sha=hashlib.sha256(data).hexdigest()))
def patch(name,old,new):
    a,b=old.splitlines(True),new.splitlines(True);hunks=[]
    for group in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(1):
        hunks.append(dict(before=''.join(a[group[0][1]:group[-1][2]]),after=''.join(b[group[0][3]:group[-1][4]])))
    if not hunks:raise RuntimeError('Paquete sin cambio: '+name)
    rows.append(dict(path=name,new_file=False,hunks=hunks))
    stage=root/'tmp/prevencion-actual-stage'/name;stage.parent.mkdir(parents=True,exist_ok=True);stage.write_text(new,encoding='utf-8')
def replace_once(s,before,after):
    if s.count(before)!=1:raise RuntimeError('Contexto distinto: '+before[:100])
    return s.replace(before,after,1)

new_file('app/helpers/ControlCargaMysql.php')
new_file('app/helpers/CatalogoPermisosInicializador.php')
guard="require_once dirname(__DIR__).'/app/helpers/PausaConsultasAutomaticas.php';\n"
rows.append(dict(path='config/db_connection.php',new_file=False,hunks=[dict(before=guard,after=guard+"require_once dirname(__DIR__).'/app/helpers/ControlCargaMysql.php';\nImpeControlCargaMysql::comprobar();\n")]))
for name in ['app/models/RolModel.php','app/models/UsuarioModel.php']:
    old=(baseline/name).read_text(encoding='utf-8');new=old
    new=replace_once(new,'$this->connection = $connection ?? (new Database())->connect();',"require_once __DIR__.'/../helpers/ControlCargaMysql.php';\n        $this->connection = $connection ?? ImpeControlCargaMysql::conexion();")
    if name.endswith('RolModel.php'):
        local=(root/name).read_text(encoding='utf-8')
        start=local.index('    public function inicializarPermisosSistema()')
        end=local.index('    private function inicializarCatalogoPermisos()',start)+len('    private function inicializarCatalogoPermisos()')
        new=replace_once(new,'    public function inicializarPermisosSistema()',local[start:end])
    patch(name,old,new)
name='app/controllers/LoginController.php'
old=(baseline/name).read_text(encoding='utf-8')
patch(name,old,replace_once(old,'$conexion = Database::compartida();','$conexion = ImpeControlCargaMysql::conexion();'))
for name in ['app/services/TelefoniaExtensionService.php','app/services/TelefoniaVentasLlamadasService.php','app/services/TelefoniaDisponibilidadService.php',
             'public/javascript/telefonia_disponibilidad.js','public/javascript/telefonia_ventas_shell.js','public/javascript/telefonia_ventas_resultados.js',
             ]:
    old=(baseline/name).read_text(encoding='utf-8')
    if name.startswith('app/services/Telefonia'):
        # Aplicar sólo el constructor; conservar todas las ampliaciones instaladas.
        if name.endswith('TelefoniaExtensionService.php'):
            new=replace_once(old,'$this->connection = $connection ?? (new Database())->connect();',"require_once __DIR__.'/../helpers/ControlCargaMysql.php';\n        $this->connection = $connection ?? ImpeControlCargaMysql::conexion();")
        elif name.endswith('TelefoniaVentasLlamadasService.php'):
            new=replace_once(old,'public function __construct(?mysqli $db=null) {$this->db=$db??(new Database())->connect();}',"public function __construct(?mysqli $db=null) {require_once __DIR__.'/../helpers/ControlCargaMysql.php';$this->db=$db??ImpeControlCargaMysql::conexion();}")
        else:
            new=replace_once(old,'$this->db=$db??(new Database())->connect();',"require_once __DIR__.'/../helpers/ControlCargaMysql.php';\n        $this->db=$db??ImpeControlCargaMysql::conexion();")
    else:new=(root/name).read_text(encoding='utf-8')
    patch(name,old,new)
name='app/controllers/TelefoniaController.php';old=(baseline/name).read_text(encoding='utf-8')
patch(name,old,replace_once(old,"        if (isset($_SESSION['rol_id'])) {","        if (isset($_SESSION['rol_id']) && !defined('IMPE_PERMISOS_REFRESCADOS')) {"))
name='index.php';old=(baseline/name).read_text(encoding='utf-8')
new=replace_once(old,'set_exception_handler(static function (Throwable $error): void {','set_exception_handler(static function (Throwable $error): void {\n    if (class_exists(ImpeControlCargaMysql::class,false)) ImpeControlCargaMysql::registrarFallo($error);')
anchor="            (int)$_SESSION['rol_id']\n        );\n}"
new=replace_once(new,anchor,"            (int)$_SESSION['rol_id']\n        );\n    define('IMPE_PERMISOS_REFRESCADOS',true);\n}")
patch(name,old,new)
new_file('tools/explicar_consultas_mysql.php')
(root/'tools/prevencion_mysql_release.json').write_text(json.dumps(dict(release='prevencion-carga-mysql-v1',files=rows),ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
p=(root/'tools/publicar_marcador_estado.py').read_text(encoding='utf-8')
p=p.replace("FILES=('public/javascript/telefonia_marcador.js',)",'FILES='+repr(tuple(row['path'] for row in rows)))
p=p.replace('marcador_estado_release.json','prevencion_mysql_release.json').replace('marcador-estado-','prevencion-mysql-')
p=p.replace('Actualiza sólo la interfaz del marcador; no toca SIP ni permisos.','Reduce conexiones e inicializaciones repetidas y limita sondeos/reintentos; no activa el cron.')
p=p.replace('Estado del marcador actualizado; llamadas activas y resultados pendientes conservan su bloqueo.','Prevención instalada; pausa y cron conservan su estado. Validación de permisos de usuarios sin caché.')
(root/'tools/publicar_prevencion_mysql.py').write_text(p,encoding='utf-8')
print('Paquete selectivo: '+str(len(rows))+' archivos; Zoho y cron no se incluyen; no ejecuta consultas SQL al instalar.')
