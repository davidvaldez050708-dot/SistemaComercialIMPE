"""Reconstruye el publicador selectivo; no publica ni conecta con el servidor."""
import base64
import difflib
import hashlib
import json
from pathlib import Path
import subprocess
import sys
sys.dont_write_bytecode = True
from agenda_comentarios_compat import reminder_hostinger, recordatorios_hostinger

ROOT = Path(__file__).resolve().parents[1]
BASE = '5116b62c284de2f9e27440f77982964e876c7402'
MODIFIED = ['index.php', 'app/views/seguimiento_vinculacion/agenda.php',
            'app/views/layout/topbar.php', 'app/controllers/ReminderController.php',
            'public/javascript/recordatorios.js']
NEW = ['app/controllers/AgendaComentarioController.php',
       'app/services/AgendaComentarioRepository.php', 'app/services/AgendaComentarioService.php',
       'app/views/seguimiento_vinculacion/partials/agenda_comentarios.php',
       'public/css/agenda_comentarios.css', 'public/javascript/agenda_comentarios.js',
       'database/migrations/2026_10_28_agenda_comentarios.sql',
       'tools/configurar_agenda_comentarios.php']

payload = []
def fragments(before,after):
    old,new=before.splitlines(keepends=True),after.splitlines(keepends=True)
    result=[]
    for group in difflib.SequenceMatcher(None,old,new,autojunk=False).get_grouped_opcodes(3):
        first,last=group[0],group[-1]
        result.append({'before':''.join(old[first[1]:last[2]]),'after':''.join(new[first[3]:last[4]])})
    return result

for name in NEW + MODIFIED:
    current = (ROOT / name).read_text(encoding='utf-8').replace('\r\n', '\n')
    entry = {'path': name}
    if name in NEW:
        entry.update(content=current, sha256=hashlib.sha256(current.encode()).hexdigest())
    else:
        before = subprocess.check_output(['git', 'show', BASE + ':' + name], cwd=ROOT).decode().replace('\r\n', '\n')
        # El salto final no forma parte del cambio de enrutamiento.
        current = current.rstrip('\n') + ('\n' if before.endswith('\n') else '')
        entry['fragments'] = fragments(before,current)
        if not entry['fragments']:
            raise RuntimeError('No hay integración en ' + name)
        if name == 'app/views/layout/topbar.php':
            # Hostinger conserva reglas propias para Marketing alrededor de esta
            # asignación. No exigir contexto de otro rol ni tocar esos filtros.
            anchor = "$mostrarCentroAvisos =\n    $esAdministradorTopbar ||\n"
            added = "$mostrarCentroAvisos =\n    tienePermiso('reuniones.ver') ||\n    $esAdministradorTopbar ||\n"
            if before.count(anchor) != 1 or current != before.replace(anchor, added):
                raise RuntimeError('La integración de campana excede el fragmento revisado.')
            entry['fragments'] = [{'before': anchor, 'after': added}]
        if name == 'app/controllers/ReminderController.php':
            host_before,host_after=reminder_hostinger(before,current)
            entry['alternatives']=[fragments(host_before,host_after)]
        if name == 'public/javascript/recordatorios.js':
            host_before,host_after=recordatorios_hostinger(before,current)
            entry['alternatives']=[fragments(host_before,host_after)]
    payload.append(entry)

PROGRAM = r'''<?php
// Publicación selectiva. Sólo archivos listados; ninguna consulta SQL ni envío.
if (PHP_SAPI !== 'cli') { exit(1); }
$version = 'agenda-comentarios-v1';
$files = json_decode(base64_decode('__PAYLOAD__', true), true, 512, JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? '--check';
if (!(count($argv) <= 2 && in_array($mode, ['--check','--apply'], true)) &&
    !(count($argv) === 3 && $mode === '--restore')) {
    fwrite(STDERR, "Uso: --check | --apply | --restore RUTA\n");exit(2);
}
function stopDeploy($message) { throw new RuntimeException($message); }
function safePath($path) {
    $path = str_replace('\\','/',$path);
    for ($part=$path; $part!==dirname($part); $part=dirname($part)) {
        if (is_link($part)) stopDeploy('Enlace simbólico no permitido: '.$path);
    }
}
function absolutePath($path) {
    if (!preg_match('~^(?:/|[A-Za-z]:/)~',str_replace('\\','/',$path)) || strpos($path,"\0")!==false) stopDeploy('Ruta absoluta requerida.');
    safePath($path);
    if (file_exists($path)) return str_replace('\\','/',realpath($path));
    $name=basename($path);
    if ($name==='.' || $name==='..' || $name==='') stopDeploy('Ruta inválida.');
    return rtrim(absolutePath(dirname($path)),'/').'/'.$name;
}
function beneath($path,$parent) { return $path===$parent || strpos($path,$parent.'/')===0; }
function digest($path) { return is_file($path) ? hash_file('sha256',$path) : null; }
function putFile($path,$text,$mode=0600) {
    if (file_put_contents($path,$text) !== strlen($text) || !chmod($path,$mode)) stopDeploy('No fue posible preparar un archivo.');
}
function atomicFile($path,$text,$mode) {
    safePath($path);
    $dir=dirname($path);
    if (!is_dir($dir) && !mkdir($dir,0755,true)) stopDeploy('No fue posible crear la carpeta de destino.');
    safePath($dir);
    $tmp=tempnam($dir,'.impe-agenda-');
    if ($tmp===false) stopDeploy('No se pudo preparar el destino.');
    try { putFile($tmp,$text,$mode); if (!rename($tmp,$path)) stopDeploy('No se pudo instalar el archivo.'); }
    finally { if (is_file($tmp)) unlink($tmp); }
}
$lock=null;$ownedLock=false;$written=[];$staged=[];$originals=[];$before=[];$after=[];$modes=[];
try {
    $live=absolutePath(getenv('IMPE_CORREOS_PUBLIC') ?: getenv('HOME').'/domains/fundacionrededumex.org/public_html');
    if (!is_dir($live) || !is_file($live.'/index.php')) stopDeploy('Raíz del CRM no encontrada.');
    $backups=absolutePath(getenv('IMPE_CORREOS_BACKUPS') ?: getenv('HOME').'/respaldos-crm');
    if (beneath($backups,$live) || beneath($live,$backups)) stopDeploy('Los respaldos deben estar fuera del sitio y de sus antecesores.');
    if ($mode!=='--check') {
        $lock=dirname($live).'/.impe-correos-deploy.lock';safePath($lock);
        if (!@mkdir($lock,0700)) stopDeploy('Otra publicación está en curso.');
        $ownedLock=true;
    }
    foreach ($files as $i=>$file) {
        $path=$live.'/'.$file['path'];safePath($path);
        if (file_exists($path) && !is_file($path)) stopDeploy('Destino incompatible: '.$file['path']);
        $before[$i]=digest($path);
        $originals[$i]=$before[$i]===null ? null : file_get_contents($path);
        $modes[$i]=$before[$i]===null ? 0644 : (fileperms($path)&0777);
    }
    if ($mode==='--restore') {
        $restore=absolutePath($argv[2]);
        if (dirname($restore)!==$backups) stopDeploy('Respaldo fuera de la carpeta prevista.');
        safePath($restore.'/manifest.json');
        $manifest=json_decode(file_get_contents($restore.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        if (($manifest['version']??'')!==$version || count($manifest['files']??[])!==count($files)) stopDeploy('Respaldo de otra entrega.');
        foreach ($files as $i=>$file) {
            $entry=$manifest['files'][$i];
            if ($entry['path']!==$file['path'] || !in_array($before[$i],[$entry['before'],$entry['after']],true)) stopDeploy('Archivo editado después de publicar: '.$file['path']);
            $staged[$i]=null;
            if ($entry['before']!==null) {
                $saved=$restore.'/original.'.$i;safePath($saved);
                if (digest($saved)!==$entry['before']) stopDeploy('Respaldo incompleto o alterado.');
                $staged[$i]=file_get_contents($saved);
            }
            $after[$i]=$entry['before'];$modes[$i]=$entry['mode'];
        }
    } else {
        foreach ($files as $i=>$file) {
            if (isset($file['content'])) {
                if (hash('sha256',$file['content'])!==$file['sha256']) stopDeploy('Contenido integrado alterado.');
                $text=$file['content'];
                if ($originals[$i]!==null && str_replace("\r\n","\n",$originals[$i])!==$text) stopDeploy('Archivo de comentarios personalizado: '.$file['path']);
                if ($originals[$i]!==null) $text=$originals[$i];
            } else {
                if ($originals[$i]===null) stopDeploy('Falta '.$file['path']);
                $crlf=strpos($originals[$i],"\r\n")!==false;
                $text=str_replace("\r\n","\n",$originals[$i]);
                $compatible=false;
                foreach (array_merge([$file['fragments']],$file['alternatives']??[]) as $variant) {
                    $candidate=$text;$matches=true;
                    foreach ($variant as $fragment) {
                        $old=$fragment['before'];$new=$fragment['after'];
                        $oldCount=substr_count($candidate,$old);$newCount=substr_count($candidate,$new);
                        if ($oldCount===1 && $newCount===0) $candidate=str_replace($old,$new,$candidate);
                        elseif ($oldCount!==0 || $newCount!==1) { $matches=false;break; }
                    }
                    if ($matches) { $text=$candidate;$compatible=true;break; }
                }
                if (!$compatible) stopDeploy('Integración incompatible en '.$file['path'].'. SITIO INTACTO.');
                if ($crlf) $text=str_replace("\n","\r\n",$text);
            }
            if (substr($file['path'],-4)==='.php') token_get_all($text,TOKEN_PARSE);
            $staged[$i]=$text;$after[$i]=hash('sha256',$text);
        }
    }
    $changed=[];
    foreach ($files as $i=>$file) if ($before[$i]!==$after[$i]) $changed[]=$i;
    if ($mode==='--check') {
        echo 'OK: revisión compatible. '.count($changed)." archivo(s) pendientes. SITIO INTACTO. Sin SQL ni envíos.\n";
    } elseif (!$changed) {
        echo "OK: sin cambios pendientes. Sin SQL ni envíos.\n";
    } else {
        $backup=null;
        if ($mode==='--apply') {
            if (!is_dir($backups) && !mkdir($backups,0700,true)) stopDeploy('No se pudo crear la carpeta privada de respaldos.');
            safePath($backups);
            $backup=$backups.'/agenda-comentarios-'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
            if (!mkdir($backup,0700)) stopDeploy('No se pudo crear el respaldo.');
            $manifest=['version'=>$version,'files'=>[]];
            foreach ($files as $i=>$file) {
                if ($originals[$i]!==null) putFile($backup.'/original.'.$i,$originals[$i]);
                $manifest['files'][]=['path'=>$file['path'],'before'=>$before[$i],'after'=>$after[$i],'mode'=>$modes[$i]];
            }
            putFile($backup.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        }
        // Los ocho archivos nuevos preceden a sus cinco puntos de integración.
        // En restauración se retiran primero las referencias a los archivos nuevos.
        if ($mode==='--restore') $changed=array_reverse($changed);
        foreach ($changed as $i) {
            $path=$live.'/'.$files[$i]['path'];safePath($path);
            if (digest($path)!==$before[$i]) stopDeploy('Cambio concurrente: '.$files[$i]['path']);
            if ($staged[$i]===null) { if (is_file($path) && !unlink($path)) stopDeploy('No se pudo retirar el archivo nuevo.'); }
            else atomicFile($path,$staged[$i],$modes[$i]);
            $written[]=$i;
            if (digest($path)!==$after[$i]) stopDeploy('No se pudo verificar el archivo publicado.');
        }
        $written=[];
        echo 'OK: '.$mode.' terminado. '.count($changed)." archivo(s). Sin SQL, envíos ni cambios de credenciales.\n";
        if ($backup) {
            echo 'Respaldo: '.$backup."\n";
            echo 'Rollback: bash '.escapeshellarg(getenv('IMPE_AGENDA_PUBLICADOR')).' --restore '.escapeshellarg($backup)."\n";
            echo 'Crear sólo las tablas nuevas: php '.escapeshellarg($live.'/tools/configurar_agenda_comentarios.php')." --apply\n";
        }
        if ($mode==='--restore') echo "Los comentarios y sus lecturas se conservan en la base de datos.\n";
    }
} catch (Throwable $e) {
    foreach (array_reverse($written) as $i) {
        $path=$live.'/'.$files[$i]['path'];
        try {
            safePath($path);
            if (digest($path)!==$after[$i]) { fwrite(STDERR,'REVISAR: cambio concurrente en '.$files[$i]['path']."\n");continue; }
            if ($originals[$i]===null) { if (is_file($path)) unlink($path); }
            else atomicFile($path,$originals[$i],$modes[$i]);
        } catch (Throwable $rollbackError) { fwrite(STDERR,'REVISAR restauración de '.$files[$i]['path']."\n"); }
    }
    fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");$failed=true;
} finally {
    if ($ownedLock) rmdir($lock);
}
exit(empty($failed)?0:1);
'''

encoded = base64.b64encode(json.dumps(payload, ensure_ascii=False, separators=(',', ':')).encode()).decode()
shell = '''#!/usr/bin/env bash
# Comentarios de agenda: publicación selectiva, sin SQL, correos ni credenciales.
set -euo pipefail
command -v php >/dev/null || { echo 'Falta PHP CLI.' >&2;exit 1; }
umask 077
TMP_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/impe-agenda-publicador.XXXXXX")"
trap 'rm -f -- "$TMP_SCRIPT"' EXIT
export IMPE_AGENDA_PUBLICADOR="$0"
cat > "$TMP_SCRIPT" <<'IMPE_AGENDA_PHP'
''' + PROGRAM.replace('__PAYLOAD__', encoded) + '''
IMPE_AGENDA_PHP
php "$TMP_SCRIPT" "${@:- --check}"
'''
# Sin argumentos el modo por defecto es --check.
shell = shell.replace('"${@:- --check}"', '"${@}"')
(ROOT / 'tools/publicar_agenda_comentarios_hostinger.sh').write_text(shell, encoding='utf-8', newline='\n')
print('OK: publicador generado. ' + str(len(payload)) + ' archivos; sin publicación.')
