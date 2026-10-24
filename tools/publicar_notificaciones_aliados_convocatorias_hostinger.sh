#!/usr/bin/env bash
# Sin SQL; publica un servicio nuevo y aplica parches exactos a cuatro archivos.
# --check revisa compatibilidad sin escribir en el sitio.
set -euo pipefail
python3 - "$@" <<'PY'
import hashlib
import json
import os
import pathlib
import shutil
import stat
import subprocess
import sys
import tempfile

SPEC=json.loads(r'''{"name":"campana-aliados-convocatorias-20261024","changes":[{"path":"app/controllers/ReminderController.php","patches":[["        $puedeTelefonia=tienePermiso('telefonia.usar')","        $puedeTareasAliado = (int)($_SESSION['rol_id'] ?? 0) === 6 &&\n            tienePermiso('aliados.ver') &&\n            (tienePermiso('aliados.gestionar_programas') ||\n             (function_exists('puedeCrearConvocatoriaDesdeConvenio') && puedeCrearConvocatoriaDesdeConvenio()));\n        $puedeTelefonia=tienePermiso('telefonia.usar')"],["                !$puedeSeguimientoAliados && !$puedeTelefonia","                !$puedeSeguimientoAliados && !$puedeTareasAliado && !$puedeTelefonia"],["        $telefoniaOk=false;","        // Las tareas de Cuenta Clave provienen de la base de datos, no de los\n        // permisos de Marketing ni de marcar como leída una notificación.\n        if ($puedeTareasAliado) {\n            try {\n                require_once __DIR__ . '/../services/AliadoConvocatoriaPendienteService.php';\n                $tareas = (new AliadoConvocatoriaPendienteService((new Database())->connect()))\n                    ->obtener($usuarioId, false, 100);\n                // Un permiso de Aliados no concede acceso al editor de Convocatorias.\n                $puedePublicar = puedeCrearConvocatoriaDesdeConvenio();\n                $tareas = array_values(array_filter($tareas, static function ($tarea) use ($puedePublicar) {\n                    return $puedePublicar || ($tarea['etiqueta'] ?? '') === 'Configurar programas';\n                }));\n                $recordatorios = array_merge($tareas, $recordatorios);\n            } catch (Throwable $e) {\n                error_log('[campana_aliados_convocatorias] ' . get_class($e));\n            }\n        }\n\n        $telefoniaOk=false;"],["        $otros = array_values(array_filter($ordenados,\n            static fn($item) => (int)($item['telefonia_id'] ?? 0) <= 0 || empty($item['pendiente_operativo'])));\n        $recordatorios = array_merge($telefoniaActiva, array_slice($otros, 0, 12));\n\n","        $aliadosActivos = array_values(array_filter($ordenados,\n            static fn($item) => ($item['tipo'] ?? '') === 'ALIADO_CONVOCATORIA_PENDIENTE'));\n        $otros = array_values(array_filter($ordenados,\n            static fn($item) => !((int)($item['telefonia_id'] ?? 0) > 0 && !empty($item['pendiente_operativo']))\n                && ($item['tipo'] ?? '') !== 'ALIADO_CONVOCATORIA_PENDIENTE'));\n        $recordatorios = array_merge($telefoniaActiva, $aliadosActivos, array_slice($otros, 0, 12));\n\n"]]},{"path":"app/controllers/ConvocatoriaNotificacionController.php","patches":[["            echo json_encode([\n                'ok' => true,","            // Pendientes de preparación/publicación; no desaparecen con Marcar leídas.\n            // Marketing tiene acceso nacional, Cuenta Clave solo su territorio.\n            $tareas = [];\n            if (strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0 &&\n                tienePermiso('convocatorias.crear')) {\n                try {\n                    require_once __DIR__ . '/../services/AliadoConvocatoriaPendienteService.php';\n                    $tareas = (new AliadoConvocatoriaPendienteService((new Database())->connect()))\n                        ->obtener($usuarioId, true, 100);\n                } catch (Throwable $e) {\n                    error_log('[campana_marketing_aliados_pendientes] ' . get_class($e));\n                }\n            }\n\n            echo json_encode([\n                'ok' => true,"],["                'no_leidas' => $modelo->contarNoLeidas($usuarioId)+count(array_filter($phone,","                'no_leidas' => count($tareas)+$modelo->contarNoLeidas($usuarioId)+count(array_filter($phone,"],["                'notificaciones' => array_merge($phone,$modelo->obtenerPorUsuario($usuarioId, 20))","                'notificaciones' => array_merge($tareas,$phone,$modelo->obtenerPorUsuario($usuarioId, 20))"],["        return (int)($_SESSION['usuario_id'] ?? 0) > 0\n            && tienePermiso('convocatorias.gestionar');","        return (int)($_SESSION['usuario_id'] ?? 0) > 0\n            && strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0\n            && tienePermiso('convocatorias.gestionar');"]]},{"path":"app/views/layout/topbar.php","patches":[["    tienePermiso('aliados.seguimiento_convocatorias');","    tienePermiso('aliados.seguimiento_convocatorias') ||\n    ((int)($_SESSION['rol_id'] ?? 0) === 6 &&\n        tienePermiso('aliados.ver') &&\n        (tienePermiso('aliados.gestionar_programas') ||\n         (function_exists('puedeCrearConvocatoriaDesdeConvenio') && puedeCrearConvocatoriaDesdeConvenio())));"]]},{"path":"public/javascript/convocatoria_notificaciones.js","patches":[["        if(Number(item?.telefonia_id)>0)return {icon:item.icono||'bi-telephone-forward',tone:'warning',fallbackTitle:'Telefonía'};","        if(Number(item?.telefonia_id)>0)return {icon:item.icono||'bi-telephone-forward',tone:'warning',fallbackTitle:'Telefonía'};\n        if(eventType==='aliado_convocatoria_pendiente')return {\n            icon:item.icono||'bi-file-earmark-plus',tone:'warning',fallbackTitle:'Convocatoria pendiente'\n        };"],["(Number(item.telefonia_id)>0?' data-telefonia-id=\"'+Number(item.telefonia_id)+'\"':' data-convocatoria-notification-id=\"' + Number(item.id || 0) + '\"')+'>' +","(Number(item.telefonia_id)>0?' data-telefonia-id=\"'+Number(item.telefonia_id)+'\"':(item.pendiente_operativo?'':' data-convocatoria-notification-id=\"' + Number(item.id || 0) + '\"'))+'>' +"],["escapeHtml(formatDate(item.created_at)) +","escapeHtml(item.pendiente_operativo ? (item.etiqueta || 'Pendiente') : formatDate(item.created_at)) +"]]}],"new_file":{"path":"app/services/AliadoConvocatoriaPendienteService.php","content":"<?php\n/**\n * Pendientes vivos de convocatoria por aliado/programa.\n *\n * No almacena una segunda verdad ni marca tareas como atendidas al leer.\n * La publicación ligada al mismo origen y programa es la condición de cierre;\n * se incluye una publicación futura ya creada (estado=0).\n *\n * Cuenta Clave: sólo sus aliados institucionales asignados y los manuales de\n * territorios vigentes. Marketing: cobertura nacional, sin cambiar permisos.\n */\nfinal class AliadoConvocatoriaPendienteService\n{\n    private mysqli $db;\n\n    public function __construct(mysqli $db)\n    {\n        $this->db = $db;\n    }\n\n    private function tabla(string $nombre): bool\n    {\n        static $permitidas = [\n            'aliados_asignaciones', 'aliados_manuales',\n            'seguimientos_vinculacion', 'seguimientos_vinculacion_post_envio',\n            'formulario_aliado_registro', 'formulario_aliado_programas',\n            'formulario_programas', 'marketing_aliado_convocatorias_origen',\n            'marketing_aliado_manual_convocatorias',\n            'marketing_convenio_convocatorias', 'formulario_aliado_convocatorias',\n            'convocatorias', 'asignaciones_territorio'\n        ];\n        if (!in_array($nombre, $permitidas, true)) {\n            throw new InvalidArgumentException('Tabla no autorizada.');\n        }\n        $s = $this->db->prepare(\n            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'\n        );\n        $s->bind_param('s', $nombre);\n        $s->execute();\n        $ok = $s->get_result()->num_rows > 0;\n        $s->close();\n        return $ok;\n    }\n\n    /** Devuelve avisos de trabajo, no nuevos mensajes individuales leídos/no leídos. */\n    public function obtener(int $usuarioId, bool $marketing, int $limite = 40): array\n    {\n        if ($usuarioId <= 0) return [];\n        $limite = max(1, min(100, $limite));\n        foreach ([\n            'aliados_asignaciones', 'aliados_manuales', 'seguimientos_vinculacion',\n            'seguimientos_vinculacion_post_envio', 'formulario_aliado_registro',\n            'formulario_aliado_programas', 'formulario_programas',\n            'marketing_aliado_convocatorias_origen', 'convocatorias'\n        ] as $tabla) {\n            // En instalaciones anteriores a V5 no inventar pendientes de tareas.\n            if (!$this->tabla($tabla)) return [];\n        }\n        if (!$marketing && !$this->tabla('asignaciones_territorio')) return [];\n\n        $asignacion = \"asig.usuario_id=? AND asig.estado_id=%s\n            AND asig.tipo_asignacion='CUENTA_CLAVE' AND asig.activo=1\n            AND (asig.fecha_inicio IS NULL OR asig.fecha_inicio<=CURDATE())\n            AND (asig.fecha_fin IS NULL OR asig.fecha_fin>=CURDATE())\";\n        $condicionInstitucional = '';\n        $condicionManual = '';\n        $parametros = [];\n        if (!$marketing) {\n            $condicionInstitucional = \" AND aa.cuenta_clave_usuario_id=?\n                AND EXISTS (SELECT 1 FROM asignaciones_territorio asig WHERE \"\n                    .sprintf($asignacion, 's.estado_id').\")\";\n            $condicionManual = \" AND EXISTS (SELECT 1 FROM asignaciones_territorio asig WHERE \"\n                    .sprintf($asignacion, 'm.estado_id').\")\";\n            $parametros = [$usuarioId, $usuarioId, $usuarioId];\n        }\n        $historicoManual = '';\n        if ($this->tabla('marketing_aliado_manual_convocatorias')) {\n            $historicoManual = \" AND NOT EXISTS (\n                SELECT 1 FROM marketing_aliado_manual_convocatorias anterior\n                INNER JOIN convocatorias c ON c.id=anterior.convocatoria_id\n                WHERE origen.origen_tipo='manual' AND anterior.manual_id=origen.origen_id\n                  AND CONVERT(anterior.programa_codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci=programas.programa_codigo\n            )\";\n        }\n        $historicoInstitucional = '';\n        if ($this->tabla('marketing_convenio_convocatorias')) {\n            $clasificaciones = [\n                'bachillerato|bachillerato-general' => 'bachillerato-general',\n                'bachillerato|bachillerato-2-anos' => 'bachillerato-2-anos',\n                'bachillerato|ingles' => 'ingles',\n                'titulacion|ejecutivas' => 'ejecutivas',\n                'titulacion|experiencia-laboral' => 'titulacion-experiencia-laboral'\n            ];\n            $casos = [];\n            foreach ($clasificaciones as $clase => $codigo) {\n                $casos[] = \"WHEN '\".$clase.\"' THEN '\".$codigo.\"'\";\n            }\n            // inscripciones-abiertas tiene dos clasificaciones válidas.\n            $casos[] = \"WHEN 'bachillerato|inscripciones-abiertas' THEN 'inscripciones-abiertas'\";\n            $casos[] = \"WHEN 'titulacion|inscripciones-abiertas' THEN 'inscripciones-abiertas'\";\n            $historicoInstitucional = \" AND NOT EXISTS (\n                SELECT 1 FROM marketing_convenio_convocatorias anterior\n                INNER JOIN convocatorias c ON c.id=anterior.convocatoria_id\n                WHERE origen.origen_tipo='institucional'\n                  AND anterior.seguimiento_id=origen.origen_id\n                  AND (CASE CONCAT(c.tipo_convocatoria,'|',c.subtipo_convocatoria) \"\n                    .implode(' ', $casos).\" END)=programas.programa_codigo\n            )\";\n        }\n        $historicoFormulario = '';\n        if ($this->tabla('formulario_aliado_convocatorias')) {\n            $historicoFormulario = \" AND NOT EXISTS (\n                SELECT 1 FROM formulario_aliado_convocatorias anterior\n                INNER JOIN convocatorias c ON c.id=anterior.convocatoria_id\n                WHERE anterior.aliado_id=perfil.aliado_id AND anterior.formulario='registro'\n                  AND CONVERT(anterior.programa_codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci=programas.programa_codigo\n            )\";\n        }\n        $sql = \"\n            SELECT origen.origen_tipo, origen.origen_id, origen.estado_id,\n                   origen.nombre_entidad, origen.fecha_alta,\n                   programas.programa_codigo, catalogo.nombre AS programa_nombre\n            FROM (\n                SELECT 'institucional' AS origen_tipo, s.id AS origen_id,\n                       s.estado_id, s.nombre_entidad,\n                       COALESCE(post.convenio_formalizado_at,aa.asignado_at) AS fecha_alta\n                FROM aliados_asignaciones aa\n                INNER JOIN seguimientos_vinculacion s ON s.id=aa.seguimiento_id\n                INNER JOIN seguimientos_vinculacion_post_envio post ON post.seguimiento_id=s.id\n                WHERE aa.activo=1 AND s.activo=1\n                  AND post.convenio_formalizado_at IS NOT NULL\n                  $condicionInstitucional\n                UNION ALL\n                SELECT 'manual' AS origen_tipo, m.id AS origen_id,\n                       m.estado_id, m.nombre_entidad, m.created_at AS fecha_alta\n                FROM aliados_manuales m\n                WHERE m.activo=1 $condicionManual\n            ) origen\n            LEFT JOIN formulario_aliado_registro perfil ON\n                (origen.origen_tipo='institucional' AND perfil.seguimiento_id=origen.origen_id)\n                OR (origen.origen_tipo='manual' AND perfil.manual_id=origen.origen_id)\n            LEFT JOIN formulario_aliado_programas programas ON programas.aliado_id=perfil.aliado_id\n            LEFT JOIN formulario_programas catalogo ON catalogo.codigo=programas.programa_codigo\n            WHERE (\n                (programas.programa_codigo IS NULL AND origen.origen_tipo='institucional' AND ?=0)\n                OR (\n                    catalogo.activo=1\n                    AND NOT EXISTS (\n                        SELECT 1 FROM marketing_aliado_convocatorias_origen publicado\n                        INNER JOIN convocatorias c ON c.id=publicado.convocatoria_id\n                        WHERE publicado.origen_tipo=origen.origen_tipo\n                          AND publicado.origen_id=origen.origen_id\n                          AND CONVERT(publicado.programa_codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci=programas.programa_codigo\n                    )\n                    $historicoManual\n                    $historicoInstitucional\n                    $historicoFormulario\n                )\n            )\n            ORDER BY origen.fecha_alta DESC, origen.origen_id DESC,\n                     programas.programa_codigo ASC\n            LIMIT ?\";\n        $parametros[] = $marketing ? 1 : 0;\n        $parametros[] = $limite;\n        $s = $this->db->prepare($sql);\n        if (!$s) throw new RuntimeException('No se pudo consultar pendientes de convocatorias.');\n        $tipos = str_repeat('i', count($parametros));\n        $s->bind_param($tipos, ...$parametros);\n        $s->execute();\n        $filas = $s->get_result()->fetch_all(MYSQLI_ASSOC);\n        $s->close();\n\n        $items = [];\n        foreach ($filas as $fila) {\n            $tipo = (string)$fila['origen_tipo'];\n            $id = (int)$fila['origen_id'];\n            $estado = (int)$fila['estado_id'];\n            $programa = trim((string)($fila['programa_codigo'] ?? ''));\n            if ($id < 1 || $estado < 1) continue;\n            $nombre = (string)$fila['nombre_entidad'];\n            if ($programa === '') {\n                if ($marketing) continue;\n                $titulo = 'Aliado nuevo: completar programas';\n                $mensaje = $nombre.' · Vinculación. Verifica y autoriza los programas para preparar sus convocatorias.';\n                $url = 'index.php?controller=aliado&action=estado&estado_id='.$estado;\n                $clave = $tipo.':'.$id.':programas';\n            } else {\n                $programaNombre = trim((string)($fila['programa_nombre'] ?? $programa));\n                $titulo = 'Convocatoria pendiente: '.$programaNombre;\n                $mensaje = $nombre.' · Publicación pendiente para el programa '.$programaNombre.'.';\n                $url = 'index.php?'.http_build_query([\n                    'controller'=>'convocatoria', 'action'=>'index',\n                    'territorio_id'=>$estado, 'desde_convenio'=>1,\n                    'abrir_nueva_convocatoria'=>1, 'aliado_tipo'=>$tipo,\n                    'aliado_id'=>$id, 'programa_codigo'=>$programa\n                ], '', '&', PHP_QUERY_RFC3986);\n                $clave = $tipo.':'.$id.':'.$programa;\n            }\n            $items[] = [\n                'clave'=>'aliado_convocatoria:'.$clave,\n                'id'=>0, 'tipo_evento'=>'aliado_convocatoria_pendiente',\n                'tipo'=>'ALIADO_CONVOCATORIA_PENDIENTE', 'leida'=>1,\n                'pendiente_operativo'=>true, 'prioridad'=>2, 'estado'=>'proxima',\n                'titulo'=>$titulo, 'mensaje'=>$mensaje,\n                'nombre_entidad'=>$nombre, 'accion'=>$titulo,\n                'icono'=>$programa===''?'bi-building-add':'bi-file-earmark-plus',\n                'etiqueta'=>$programa===''?'Configurar programas':'Por publicar',\n                'created_at'=>(string)$fila['fecha_alta'],\n                'fecha'=>(string)$fila['fecha_alta'],\n                'url'=>$url\n            ];\n        }\n        return $items;\n    }\n}\n","blob_sha":"2b0a8ff5372ccfb562a315f4700e6164f167f2fa"}}''')

def sha(data):
    return hashlib.sha256(data).hexdigest()

def git_blob(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\x00'+data).hexdigest()

def fail(message):
    sys.stderr.write('CONFLICTO: '+message+'\n')
    sys.exit(2)

def atomic(path, content, permissions):
    fd, temp = tempfile.mkstemp(prefix='.impe-aliados-', dir=str(path.parent))
    try:
        os.fchmod(fd, permissions)
        with os.fdopen(fd,'wb') as output:
            output.write(content)
            output.flush()
            os.fsync(output.fileno())
        os.replace(temp, path)
    finally:
        if os.path.exists(temp): os.unlink(temp)

args=sys.argv[1:]
if not args or args[0] not in ('--check','--apply','--restore'):
    fail('Uso: IMPE_CRM_PUBLIC=... bash publicador --check|--apply|--restore [ruta_respaldo]')
action=args[0]
if (action=='--restore' and len(args)!=2) or (action!='--restore' and len(args)!=1):
    fail('Argumentos incorrectos')
env=os.environ.get('IMPE_CRM_PUBLIC','')
if not env: fail('Configura IMPE_CRM_PUBLIC')
site=pathlib.Path(env).resolve()
if not (site/'index.php').is_file(): fail('No se reconoce la raiz del CRM')
private=pathlib.Path(os.environ.get('IMPE_CRM_BACKUPS',str(pathlib.Path.home()/'impe-aliados-convocatorias-backups'))).resolve()
if private==site or private in site.parents or site in private.parents:
    fail('El respaldo debe almacenarse fuera de public_html')
paths=[x['path'] for x in SPEC['changes']]+[SPEC['new_file']['path']]
def destination(relative):
    p=site/relative
    if p.is_symlink() or p.resolve().parent==site or site not in p.resolve().parents:
        fail('Ruta insegura: '+relative)
    return p

if action=='--restore':
    root=pathlib.Path(args[1])
    if not root.is_dir() or root.is_symlink() or private not in root.resolve().parents:
        fail('Respaldo inexistente o fuera del directorio privado')
    folder=root.resolve()
    manifest=folder/'manifest.json'
    if not manifest.is_file() or manifest.is_symlink(): fail('Falta manifiesto')
    entries=json.loads(manifest.read_text(encoding='utf8'))
    if entries.get('name')!=SPEC['name'] or sorted(entries.get('files',{}))!=sorted(paths):
        fail('El respaldo no corresponde a esta publicacion')
    for rel,entry in entries['files'].items():
        p=destination(rel)
        current=p.read_bytes() if p.exists() else b''
        if bool(p.exists())!=bool(entry['after_exists']) or (p.exists() and sha(current)!=entry['after']):
            fail('Modificacion posterior detectada; no restaurar: '+rel)
        if entry['before_exists']:
            b=folder/(str(entry['number'])+'.bak')
            if not b.is_file() or b.is_symlink() or sha(b.read_bytes())!=entry['before']:
                fail('Respaldo alterado: '+rel)
    for rel,entry in entries['files'].items():
        p=destination(rel)
        if entry['before_exists']:
            atomic(p,(folder/(str(entry['number'])+'.bak')).read_bytes(),int(entry['mode']))
        elif p.exists():
            p.unlink()
    print('OK: restauracion exacta terminada; BD y otros archivos intactos.')
    sys.exit(0)

plans=[]
for change in SPEC['changes']:
    rel=change['path']
    p=destination(rel)
    if not p.is_file(): fail('No existe archivo requerido: '+rel)
    raw=p.read_bytes()
    try:
        original=raw.decode('utf8')
    except UnicodeDecodeError:
        fail('Archivo no UTF-8: '+rel)
    if b'\r\n' in raw and raw.count(b'\r\n')!=raw.count(b'\n'):
        fail('Saltos de linea mixtos: '+rel)
    is_crlf=b'\r\n' in raw
    normalized=original.replace('\r\n','\n')
    # Cuenta Clave debe disponer de una campana; Marketing solo de su campana.
    if rel=='app/views/layout/topbar.php':
        if "strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0" not in normalized or 'if ($esMarketingTopbar)' not in normalized:
            fail('Instala primero la correccion de campana unica (#152)')
    updated=normalized
    pending=0
    already=0
    for before,after in change['patches']:
        if updated.count(after)==1:
            already+=1
        elif updated.count(before)==1:
            updated=updated.replace(before,after,1)
            pending+=1
        else:
            fail('Personalizacion desconocida o fragmento duplicado: '+rel)
    if pending and already:
        fail('Archivo parcialmente actualizado, requiere conciliacion: '+rel)
    data=updated.replace('\n','\r\n').encode('utf8') if is_crlf else updated.encode('utf8')
    plans.append(dict(rel=rel,path=p,old=raw,after=data,exists=True,
        mode=stat.S_IMODE(p.stat().st_mode),created=False))
nf=SPEC['new_file']
target=destination(nf['path'])
data=nf['content'].encode('utf8')
if git_blob(data)!=nf['blob_sha']: fail('La firma del servicio nuevo no coincide con GitHub')
if target.exists() and (not target.is_file() or target.read_bytes()!=data):
    fail('Ya existe un servicio personalizado: '+nf['path'])
plans.append(dict(rel=nf['path'],path=target,old=target.read_bytes() if target.exists() else b'',
                  after=data,exists=target.exists(),mode=stat.S_IMODE(target.stat().st_mode) if target.exists() else 0o644,
                  created=not target.exists()))
# Validar sin aplicar: php -l sobre los futuros archivos PHP; node --check si existe.
for plan in plans:
    if plan['old']==plan['after'] and plan['exists']: continue
    if plan['rel'].endswith('.php'):
        if not shutil.which('php'): fail('PHP CLI no disponible para validacion de sintaxis')
        fd,tmp=tempfile.mkstemp(suffix='.php')
        try:
            with os.fdopen(fd,'wb') as h: h.write(plan['after'])
            test=subprocess.run(['php','-l',tmp],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            if test.returncode: fail('Sintaxis PHP: '+plan['rel']+' '+(test.stdout+test.stderr).decode('utf8','replace')[-400:])
        finally:
            if os.path.exists(tmp): os.unlink(tmp)
    elif plan['rel'].endswith('.js') and shutil.which('node'):
        fd,tmp=tempfile.mkstemp(suffix='.js')
        try:
            with os.fdopen(fd,'wb') as h: h.write(plan['after'])
            test=subprocess.run(['node','--check',tmp],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            if test.returncode: fail('Sintaxis JS: '+plan['rel'])
        finally:
            if os.path.exists(tmp): os.unlink(tmp)

changes=sum(not p['exists'] or p['old']!=p['after'] for p in plans)
print('Archivos a modificar: '+str(changes))
for p in plans:
    print(('PREPARADO: ' if (not p['exists'] or p['old']!=p['after']) else 'YA ACTUALIZADO: ')+p['rel'])
if action=='--check':
    print('OK: comprobacion sin modificar produccion.')
    sys.exit(0)
if changes==0:
    print('OK: esta correccion ya esta instalada. Sin cambios.')
    sys.exit(0)
if not private.exists():
    private.mkdir(parents=True,mode=0o700)
os.chmod(private,0o700)
folder=pathlib.Path(tempfile.mkdtemp(prefix='publicacion-',dir=str(private)))
os.chmod(folder,0o700)
record=dict(name=SPEC['name'],files={})
for i,p in enumerate(plans):
    item=dict(number=i,before_exists=p['exists'],after_exists=True,after=sha(p['after']),
              before=sha(p['old']) if p['exists'] else None,mode=p['mode'])
    record['files'][p['rel']]=item
    if p['exists']:
        back=folder/(str(i)+'.bak')
        back.write_bytes(p['old'])
        os.chmod(back,0o600)
manifest=folder/'manifest.json'
manifest.write_text(json.dumps(record,indent=2,ensure_ascii=False),encoding='utf8')
os.chmod(manifest,0o600)
applied=[]
try:
    for p in plans:
        if p['exists'] and p['path'].read_bytes()!=p['old']:
            raise RuntimeError('Archivo alterado antes de publicacion: '+p['rel'])
        if p['exists'] and p['old']==p['after']: continue
        p['path'].parent.mkdir(parents=True,exist_ok=True)
        atomic(p['path'],p['after'],p['mode'])
        applied.append(p)
except BaseException:
    for p in reversed(applied):
        if p['path'].exists() and p['path'].read_bytes()==p['after']:
            if p['exists']: atomic(p['path'],p['old'],p['mode'])
            else: p['path'].unlink()
    raise
print('OK: publicacion segura terminada. Respaldo: '+str(folder))
print('Sin SQL ni modificaciones de telefonia, permisos o prospectos.')
PY
