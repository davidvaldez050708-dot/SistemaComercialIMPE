"""Reconstruye sólo las variantes verificadas por diff y SHA256 del usuario."""
import argparse
import hashlib
from pathlib import Path
import re
import subprocess

ROOT=Path(__file__).resolve().parents[1]
BASE='5f7a0570'
EXPECTED={
 'app/controllers/CrearConvocatoriaController.php':'085d17fb38a9ca8626b27030aa90e0dc32af1da564acda3b654c292ba0f32f67',
 'app/controllers/ConvocatoriaController.php':'6afd0bef722f2cd6ea804572544f9b2273a5327b26976272f0367f20f9623153',
 'app/models/ConvocatoriaModel.php':'4047636f2a07dcdc3b4574bf5ebf5c3ce9ef49b04cb73d07de03ccd96921351f',
 'app/views/convocatorias/crear_desde_convenio.php':'b3808d372164044b83b90fed5305694f57233df1e384616072b8267e98a650c7',
}
def git(ref,name):return subprocess.check_output(['git','show',ref+':'+name],cwd=str(ROOT)).decode('utf-8').replace('\r\n','\n')
def once(text,old,new):
    if text.count(old)!=1:raise RuntimeError('Variante no reconocida: '+old[:90])
    return text.replace(old,new,1)
def bases():
    result={name:git(BASE,name) for name in EXPECTED}
    name='app/controllers/CrearConvocatoriaController.php'
    extra="""
        require_once __DIR__.'/../services/AliadoInternacionalService.php';
        try {
            $db=(new Database())->connect();
            $tabla=$db->query("SHOW TABLES LIKE 'formulario_aliado_convocatorias'");
            if ($tabla->num_rows && tienePermiso('aliados.ver')) {
                $externos=$db->query("SELECT a.id AS aliado_internacional_id,a.seguimiento_id,0 AS estado_id,a.region AS estado_nombre,a.ciudad AS municipio_nombre,a.nombre AS nombre_entidad,a.contacto_nombre,post.convenio_fecha,post.convenio_referencia,post.convenio_notas,post.convenio_formalizado_at,(SELECT COUNT(*) FROM formulario_aliado_convocatorias x WHERE x.aliado_id=a.id)>0 AS tiene_convocatoria FROM formulario_aliados a INNER JOIN seguimientos_vinculacion_post_envio post ON post.seguimiento_id=a.seguimiento_id WHERE a.pais_codigo<>'MX' AND a.activo=1 AND post.convenio_formalizado_at IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
                $convenios=array_merge($convenios,$externos);
            }
        } catch (Throwable $e) { error_log('[convenios_externos] '.$e->getMessage()); }


"""
    result[name]=once(result[name],'        $puedeCrearDesdeConvenio =',extra+'        $puedeCrearDesdeConvenio =')
    name='app/controllers/ConvocatoriaController.php';text=result[name]
    text=once(text,'class ConvocatoriaController',"require_once __DIR__ . '/../services/AliadoInternacionalService.php';\n\nclass ConvocatoriaController")
    text=once(text,"        $modelo = new ConvocatoriaModel();\n        $territorioId = (int)($_POST['territorio_id'] ?? 0);\n        $seguimientoOrigenIdGuardar", "        if (array_key_exists('aliado_internacional_id', $_POST)) { $this->guardarInternacional(); return; }\n        $modelo = new ConvocatoriaModel();\n        $territorioId = (int)($_POST['territorio_id'] ?? 0);\n        $seguimientoOrigenIdGuardar")
    old=git('20ec4fb64f211b06cde114da76d6c84e65a96db5',name)
    method=re.search(r'    private function guardarInternacional\(\)\n    \{.*?\n    \}\n',old,re.S).group()
    text=once(text,'    /**\n     * Crea una publicación real por programa autorizado',method+'\n    /**\n     * Crea una publicación real por programa autorizado')
    extra="""        $dbInternacional=(new Database())->connect();
        if ($dbInternacional->query("SHOW TABLES LIKE 'formulario_aliado_convocatorias'")->num_rows) {
            $identificador=(int)($_POST['id']??0);
            $q=$dbInternacional->prepare('SELECT convocatoria_id FROM formulario_aliado_convocatorias WHERE convocatoria_id=?');
            $q->bind_param('i',$identificador);$q->execute();
            if ($q->get_result()->num_rows) { http_response_code(403);echo 'Esta convocatoria se gestiona desde Aliados internacionales.';return; }
        }
"""
    text=once(text,"        $this->validarPermiso('convocatorias.editar');\n        $this->validarMetodoPost();\n\n", "        $this->validarPermiso('convocatorias.editar');\n        $this->validarMetodoPost();\n\n"+extra)
    text=once(text,'        $fechasOpcionales = false\n    ) {','        $fechasOpcionales = false,\n        $esInternacional = false\n    ) {')
    text=once(text,'        if (empty($estadosIds)) {','        if (!$esInternacional && empty($estadosIds)) {');result[name]=text
    name='app/models/ConvocatoriaModel.php';text=result[name]
    text=once(text,"    public function __construct()\n    {\n        $database = new Database();\n        $this->connection = $database->connect();\n    }","    public function __construct(?mysqli $connection = null)\n    {\n        $this->connection = $connection ?? (new Database())->connect();\n    }")
    text=once(text,'public function crear($datos, $estadosIds, $transaccionExterna = false)','public function crear($datos, $estadosIds, $relacionar = null)')
    text=once(text,'        $datos = ProgramaEducativo::entrada($datos);\n        $esInscripcionesAbiertas =',"        $datos = ProgramaEducativo::entrada($datos);\n        // Compatibilidad: PR114 entrega un callable; V3 entrega true para un lote.\n        $transaccionExterna = is_bool($relacionar) ? $relacionar : false;\n        if (is_bool($relacionar)) $relacionar = null;\n\n        $esInscripcionesAbiertas =") if text.count('        $datos = ProgramaEducativo::entrada($datos);\n        $esInscripcionesAbiertas =')==1 else text.replace('        $datos = ProgramaEducativo::entrada($datos);\n        $esInscripcionesAbiertas =',"        $datos = ProgramaEducativo::entrada($datos);\n        // Compatibilidad: PR114 entrega un callable; V3 entrega true para un lote.\n        $transaccionExterna = is_bool($relacionar) ? $relacionar : false;\n        if (is_bool($relacionar)) $relacionar = null;\n\n        $esInscripcionesAbiertas =",1)
    text=once(text,'            $this->guardarEstados($convocatoriaId, $estadosIds);\n\n','            $this->guardarEstados($convocatoriaId, $estadosIds);\n')
    text=once(text,'            // En la misma transacción: imagen/clasificación y origen manual indivisibles.',"            if ($relacionar) { $relacionar($this->connection, $convocatoriaId); }\n\n            // En la misma transacción: imagen/clasificación y origen manual indivisibles.")
    text=once(text,'        $sql = "SELECT id, imagen\n                FROM convocatorias\n                WHERE COALESCE(fecha_inicio, DATE(created_at)) < ?',"        $tablaInternacional = $this->connection->query(\"SHOW TABLES LIKE 'formulario_aliado_convocatorias'\");\n        $excluirInternacionales = $tablaInternacional && $tablaInternacional->num_rows\n            ? ' AND NOT EXISTS (SELECT 1 FROM formulario_aliado_convocatorias x WHERE x.convocatoria_id=convocatorias.id)' : '';\n        $sql = \"SELECT id, imagen\n                FROM convocatorias\n                WHERE COALESCE(fecha_inicio, DATE(created_at)) < ? {$excluirInternacionales}")
    result[name]=text
    name='app/views/convocatorias/crear_desde_convenio.php';text=result[name]
    result[name]=once(text,"                        'estado_id' => (int)($convenio['estado_id'] ?? 0),","                        'aliado_internacional_id' => (int)($convenio['aliado_internacional_id'] ?? 0),\n                        'estado_id' => (int)($convenio['estado_id'] ?? 0),")
    encoded={name:text.encode('utf-8') for name,text in result.items()}
    for name,data in encoded.items():
        if hashlib.sha256(data).hexdigest()!=EXPECTED[name]:raise RuntimeError('El diff reconstruido no coincide con el SHA de Hostinger: '+name+' '+hashlib.sha256(data).hexdigest())
    return encoded
if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--output',required=True);args=parser.parse_args();out=Path(args.output)
    for name,data in bases().items():dest=out/name;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(data);print('SHA HOSTINGER CONFIRMADO: '+name)
