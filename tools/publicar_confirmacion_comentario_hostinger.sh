#!/usr/bin/env bash
# Sólo confirmación al añadir comentarios en agenda_comentarios.js. Sin SQL ni cambios en recordatorios.
set -euo pipefail
command -v php >/dev/null || { echo 'Falta PHP CLI.' >&2;exit 1; }
umask 077
TMP_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/impe-agenda-avisos.XXXXXX")"
trap 'rm -f -- "$TMP_SCRIPT"' EXIT
export IMPE_AGENDA_PUBLICADOR="$0"
cat > "$TMP_SCRIPT" <<'IMPE_AGENDA_PHP'
<?php
// Publicación selectiva. Sólo archivos listados; ninguna consulta SQL ni envío.
if (PHP_SAPI !== 'cli') { exit(1); }
$version = 'agenda-comentarios-confirmacion-v1';
$files = json_decode(base64_decode('W3sicGF0aCI6InB1YmxpYy9qYXZhc2NyaXB0L2FnZW5kYV9jb21lbnRhcmlvcy5qcyIsImZyYWdtZW50cyI6W3siYmVmb3JlIjoiICAgICAgICAgICAgc3RhdHVzLmhpZGRlbiA9ICFtZXNzYWdlO1xuICAgICAgICAgICAgc3RhdHVzLmNsYXNzTGlzdC50b2dnbGUoJ2lzLWVycm9yJywgZmFpbHVyZSk7XG4gICAgICAgIH07XG4gICAgICAgIGZ1bmN0aW9uIGVuZHBvaW50KGFjdGlvbikge1xuICAgICAgICAgICAgY29uc3QgdXJsID0gbmV3IFVSTCgnaW5kZXgucGhwJywgd2luZG93LmxvY2F0aW9uLmhyZWYpO1xuICAgICAgICAgICAgdXJsLnNlYXJjaCA9IG5ldyBVUkxTZWFyY2hQYXJhbXMoeyBjb250cm9sbGVyOidhZ2VuZGFDb21lbnRhcmlvJywgYWN0aW9uIH0pLnRvU3RyaW5nKCk7XG4iLCJhZnRlciI6IiAgICAgICAgICAgIHN0YXR1cy5oaWRkZW4gPSAhbWVzc2FnZTtcbiAgICAgICAgICAgIHN0YXR1cy5jbGFzc0xpc3QudG9nZ2xlKCdpcy1lcnJvcicsIGZhaWx1cmUpO1xuICAgICAgICB9O1xuICAgICAgICBmdW5jdGlvbiBtb3N0cmFyQ29uZmlybWFjaW9uQ29tZW50YXJpbyhtZXNzYWdlKSB7XG4gICAgICAgICAgICBpbmZvcm0oJycpO1xuICAgICAgICAgICAgbGV0IGNvbnRhaW5lciA9IGRvY3VtZW50LnF1ZXJ5U2VsZWN0b3IoJy50b2FzdC1jb250YWluZXInKTtcbiAgICAgICAgICAgIGlmICghY29udGFpbmVyKSB7XG4gICAgICAgICAgICAgICAgY29udGFpbmVyID0gbm9kZSgnZGl2JywndG9hc3QtY29udGFpbmVyIHBvc2l0aW9uLWZpeGVkIHRvcC0wIGVuZC0wIHAtMycpO1xuICAgICAgICAgICAgICAgIGRvY3VtZW50LmJvZHkuYXBwZW5kKGNvbnRhaW5lcik7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjb250YWluZXIuc3R5bGUuekluZGV4ID0gJzEwOTUnO1xuICAgICAgICAgICAgY29uc3QgdG9hc3QgPSBub2RlKCdkaXYnLCd0b2FzdCBzeXN0ZW0tdG9hc3QnKTtcbiAgICAgICAgICAgIHRvYXN0LmRhdGFzZXQuYWdlbmRhQ29tbWVudENvbmZpcm1hdGlvbiA9ICcnO1xuICAgICAgICAgICAgdG9hc3Quc2V0QXR0cmlidXRlKCdyb2xlJywnc3RhdHVzJyk7XG4gICAgICAgICAgICB0b2FzdC5zZXRBdHRyaWJ1dGUoJ2FyaWEtbGl2ZScsJ3BvbGl0ZScpO1xuICAgICAgICAgICAgdG9hc3Quc2V0QXR0cmlidXRlKCdhcmlhLWF0b21pYycsJ3RydWUnKTtcbiAgICAgICAgICAgIGNvbnN0IGJvZHkgPSBub2RlKCdkaXYnLCd0b2FzdC1ib2R5Jyk7XG4gICAgICAgICAgICBjb25zdCBpY29uID0gbm9kZSgnaScsJ2JpIGJpLWNoZWNrLWNpcmNsZScpO2ljb24uc2V0QXR0cmlidXRlKCdhcmlhLWhpZGRlbicsJ3RydWUnKTtcbiAgICAgICAgICAgIGJvZHkuYXBwZW5kKGljb24sbm9kZSgnc3BhbicsJycsbWVzc2FnZSkpO3RvYXN0LmFwcGVuZChib2R5KTtjb250YWluZXIuYXBwZW5kKHRvYXN0KTtcbiAgICAgICAgICAgIHRvYXN0LmFkZEV2ZW50TGlzdGVuZXIoJ2hpZGRlbi5icy50b2FzdCcsKCk9PnRvYXN0LnJlbW92ZSgpLHtvbmNlOnRydWV9KTtcbiAgICAgICAgICAgIGlmICh3aW5kb3cuYm9vdHN0cmFwPy5Ub2FzdCkge1xuICAgICAgICAgICAgICAgIHdpbmRvdy5ib290c3RyYXAuVG9hc3QuZ2V0T3JDcmVhdGVJbnN0YW5jZSh0b2FzdCx7YXV0b2hpZGU6dHJ1ZSxkZWxheToyNjAwfSkuc2hvdygpO1xuICAgICAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICAgICAgICB0b2FzdC5jbGFzc0xpc3QuYWRkKCdzaG93Jyk7dG9hc3Quc3R5bGUuZGlzcGxheT0nYmxvY2snO1xuICAgICAgICAgICAgICAgIHdpbmRvdy5zZXRUaW1lb3V0KCgpPT50b2FzdC5yZW1vdmUoKSwyNjAwKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgICAgICBmdW5jdGlvbiBlbmRwb2ludChhY3Rpb24pIHtcbiAgICAgICAgICAgIGNvbnN0IHVybCA9IG5ldyBVUkwoJ2luZGV4LnBocCcsIHdpbmRvdy5sb2NhdGlvbi5ocmVmKTtcbiAgICAgICAgICAgIHVybC5zZWFyY2ggPSBuZXcgVVJMU2VhcmNoUGFyYW1zKHsgY29udHJvbGxlcjonYWdlbmRhQ29tZW50YXJpbycsIGFjdGlvbiB9KS50b1N0cmluZygpO1xuIn0seyJiZWZvcmUiOiIgICAgICAgICAgICB0cnkge1xuICAgICAgICAgICAgICAgIGF3YWl0IHJlcXVlc3QoJ2NyZWFyJyx7ZmVjaGE6c2VsZWN0ZWQsY29tZW50YXJpbzpjb250ZW50LGhvcmE6bm9Ib3VyLmNoZWNrZWQ/Jyc6aG91ci52YWx1ZX0pO1xuICAgICAgICAgICAgICAgIGNoYW5nZVZlcnNpb24rKztcbiAgICAgICAgICAgICAgICBlZGl0b3IuY2xvc2UoKTtpbmZvcm0oJ0NvbWVudGFyaW8gYcOxYWRpZG8gYSBsYSBhZ2VuZGEuJyk7XG4gICAgICAgICAgICAgICAgYXdhaXQgbG9hZCgpO3JlZnJlc2hCZWxsKCk7XG4gICAgICAgICAgICB9IGNhdGNoKGUpIHtlcnJvci50ZXh0Q29udGVudD1lLm1lc3NhZ2U7ZXJyb3IuaGlkZGVuPWZhbHNlO31cbiAgICAgICAgICAgIGZpbmFsbHkge3NhdmluZz1mYWxzZTtmb3JtLnF1ZXJ5U2VsZWN0b3IoJ1tkYXRhLWFnZW5kYS1jb21tZW50LXNhdmVdJykuZGlzYWJsZWQ9ZmFsc2U7fVxuIiwiYWZ0ZXIiOiIgICAgICAgICAgICB0cnkge1xuICAgICAgICAgICAgICAgIGF3YWl0IHJlcXVlc3QoJ2NyZWFyJyx7ZmVjaGE6c2VsZWN0ZWQsY29tZW50YXJpbzpjb250ZW50LGhvcmE6bm9Ib3VyLmNoZWNrZWQ/Jyc6aG91ci52YWx1ZX0pO1xuICAgICAgICAgICAgICAgIGNoYW5nZVZlcnNpb24rKztcbiAgICAgICAgICAgICAgICBlZGl0b3IuY2xvc2UoKTttb3N0cmFyQ29uZmlybWFjaW9uQ29tZW50YXJpbygnQ29tZW50YXJpbyBhw7FhZGlkbyBhIGxhIGFnZW5kYS4nKTtcbiAgICAgICAgICAgICAgICBhd2FpdCBsb2FkKCk7cmVmcmVzaEJlbGwoKTtcbiAgICAgICAgICAgIH0gY2F0Y2goZSkge2Vycm9yLnRleHRDb250ZW50PWUubWVzc2FnZTtlcnJvci5oaWRkZW49ZmFsc2U7fVxuICAgICAgICAgICAgZmluYWxseSB7c2F2aW5nPWZhbHNlO2Zvcm0ucXVlcnlTZWxlY3RvcignW2RhdGEtYWdlbmRhLWNvbW1lbnQtc2F2ZV0nKS5kaXNhYmxlZD1mYWxzZTt9XG4ifV19XQ==', true), true, 512, JSON_THROW_ON_ERROR);
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
            $backup=$backups.'/agenda-comentarios-confirmacion-'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
            if (!mkdir($backup,0700)) stopDeploy('No se pudo crear el respaldo.');
            $manifest=['version'=>$version,'files'=>[]];
            foreach ($files as $i=>$file) {
                if ($originals[$i]!==null) putFile($backup.'/original.'.$i,$originals[$i]);
                $manifest['files'][]=['path'=>$file['path'],'before'=>$before[$i],'after'=>$after[$i],'mode'=>$modes[$i]];
            }
            putFile($backup.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        }
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
IMPE_AGENDA_PHP
php "$TMP_SCRIPT" "$@"
