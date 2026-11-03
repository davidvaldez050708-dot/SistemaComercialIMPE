#!/usr/bin/env python3
"""Reconstruye la variante revisada por diff y hash; nunca accede a Hostinger."""
import base64
import gzip
import hashlib
import json
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
BASE='13f0cae3da684f43149e9f51c6093598bd2e02fb'
FEATURE='e4aef1e2685e2c6e7d82cedb7d23f87e1deb9652'
POLICY='82a7451d2bbc1e1f88009481ae07d78966ba2532'
MODEL='app/models/RolModel.php'
POLICY_PATH='app/helpers/SupervisionRolePolicy.php'
BASE_SHA='94fe92be07ae319335b4c1fbc11e5a1a1f0026ae79b0418616f3d1a9cd9b1225'
POLICY_SHA='f9542f3c578d953cfde72f807cf98bc770beffac7e606f8bf9f975d5324d001b'


def sha(data):
    return hashlib.sha256(data).hexdigest()


def source(ref,name):
    return subprocess.check_output(['git','show',ref+':'+name],cwd=str(ROOT))


def reviewed_variant(data):
    text=data.decode('utf-8')

    def change(before,after):
        nonlocal text
        if text.count(before)!=1:
            raise RuntimeError('Contexto ambiguo al reconstruir la variante revisada')
        text=text.replace(before,after,1)

    change("require_once __DIR__ . '/../helpers/AdminRolePolicy.php';",
           "require_once __DIR__ . '/../helpers/AdminRolePolicy.php';\nrequire_once __DIR__ . '/../helpers/SupervisionRolePolicy.php';")
    change("            !$this->existePermisoPorCodigo('telefonia.transferir');",
           "            !$this->existePermisoPorCodigo('telefonia.transferir');\n        $permisoControlTelefoniaNuevo =\n            !$this->existePermisoPorCodigo('telefonia.control_ver');")
    change("        if ($permisosTelefoniaNuevos) {\n            $this->asignarPermisosInicialesTelefonia();\n        }",
           "        if ($permisosTelefoniaNuevos) {\n            $this->asignarPermisosInicialesTelefonia();\n        }\n        if ($permisoControlTelefoniaNuevo) {\n            $this->asignarPermisoInicialControlTelefonia();\n        }")
    method='''    /** Se concede al Director una sola vez: nunca se restaura tras revocación. */
    private function asignarPermisoInicialControlTelefonia()
    {
        $this->connection->query(
            "INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
             SELECT r.id, p.id FROM roles r
             INNER JOIN permisos p
                 ON p.codigo = 'telefonia.control_ver' AND p.estado = 1
             WHERE r.nombre = 'Director de Operaciones' AND r.estado = 1"
        );
    }

'''
    change('    private function asignarPermisosInicialesReporteConvocatorias()',method+'    private function asignarPermisosInicialesReporteConvocatorias()')
    start=text.index('        /*\n         * El Reporte de Convocatorias forma parte del Centro de Reportes.')
    end=text.index('        foreach (array_keys($seleccionados)',start)
    text=text[:start]+text[end:]
    start=text.index('        $resultado = $this->connection->query(\n            "SELECT id\n             FROM permisos\n             WHERE codigo IN (')
    end=text.index('        return array_values(array_filter(',start)
    text=text[:start]+'''        $resultado = $this->connection->query('SELECT id, codigo FROM permisos');
        if (!$resultado) throw new RuntimeException('No fue posible validar los permisos reservados.');
        $permisosSoloAdministrador = [];
        while ($permiso = $resultado->fetch_assoc()) {
            if (permisoReservadoParaRol((int)$rolId, (string)$permiso['codigo'])) {
                $permisosSoloAdministrador[(int)$permiso['id']] = true;
            }
        }

'''+text[end:]
    marker="            ['modulo' => 'Telefonía', 'codigo' => 'telefonia.configurar',"
    line="            ['modulo' => 'Telefonía', 'codigo' => 'telefonia.control_ver', 'nombre' => 'Ver control de llamadas', 'descripcion' => 'Consultar los indicadores y resultados telefónicos del equipo, sin usar la extensión ni administrar la PBX.'],\n"
    change(marker,line+marker)
    change('Generar el reporte transversal de usuarios. Exclusivo del Administrador.',
           'Generar el reporte transversal de usuarios. Disponible para Administración y Dirección de Operaciones con este permiso.')
    return text.encode('utf-8')


def pack(data):
    return base64.b64encode(gzip.compress(data,mtime=0)).decode('ascii')


def build():
    before=reviewed_variant(source(BASE,MODEL))
    if sha(before)!=BASE_SHA:
        raise RuntimeError('La reconstrucción no coincide con el hash real de Hostinger')
    after=reviewed_variant(source(FEATURE,MODEL))
    policy=source(POLICY,POLICY_PATH)
    if sha(policy)!=POLICY_SHA:
        raise RuntimeError('La política compartida no coincide con Hostinger')
    return {'schema':1,'path':MODEL,'base_sha256':BASE_SHA,'sha256':sha(after),
            'base':pack(before),'target':pack(after),
            'dependency':{'path':POLICY_PATH,'sha256':POLICY_SHA,'fixture':pack(policy)}}


if __name__=='__main__':
    (ROOT/'tools/prospectos_meta_rol_hostinger_compat.json').write_text(
        json.dumps(build(),ensure_ascii=False,indent=2)+'\n',encoding='utf-8',newline='\n')
    print('Variante reconstruida exactamente; se conservan Dirección, Control de llamadas y reportes.')
