#!/usr/bin/env bash
# Actualización exclusiva de tipografía Ecard. Sin SQL, red, aliados ni avisos.
set -euo pipefail
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply || "$MODE" == --probe ) ) || ( "$MODE" == --restore && "$#" == 2 ) ]] || { echo 'Uso: --check | --apply | --probe | --restore RUTA' >&2;exit 2; }
VERSION='ecard-tipografia-v2'
FILES=('app/services/EcardReunionService.php')
LIVE="${IMPE_CORREOS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_CORREOS_BACKUPS:-$HOME/respaldos-crm}"
fail(){ echo "ERROR: $*" >&2;exit 1; }
hash(){ sha256sum "$1" | cut -d' ' -f1; }
no_links(){ local part="$1";while [[ "$part" != / && "$part" != . ]];do
 [[ ! -L "$part" ]] || fail "Enlace simbólico no permitido: $1"
 case "$part" in */*) part="${part%/*}";[[ -n "$part" ]] || part=/;; *) part=.;;esac
done; }
for tool in php sha256sum cut mktemp realpath cp mv mkdir rm rmdir dirname date chmod;do command -v "$tool" >/dev/null || fail "Falta $tool";done
no_links "$LIVE";[[ -d "$LIVE" ]] || fail 'Raíz del CRM no encontrada.'
LIVE="$(cd "$LIVE" && pwd -P)";[[ -f "$LIVE/index.php" ]] || fail 'Falta el índice del CRM.'
for file in "${FILES[@]}";do no_links "$LIVE/$file";[[ -f "$LIVE/$file" ]] || fail "Falta $file";done
no_links "$BACKUPS";BACKUPS="$(realpath -m "$BACKUPS")"
[[ "$BACKUPS" != / && "$BACKUPS/" != "$LIVE/"* && "$LIVE/" != "$BACKUPS/"* ]] || fail 'El respaldo debe estar fuera del sitio y sus antecesores.'
SCRATCH="$(cd "${TMPDIR:-/tmp}" && pwd -P)";[[ "$SCRATCH/" != "$LIVE/"* ]] || fail 'Temporales fuera del sitio requeridos.'
TMP="$(mktemp -d "$SCRATCH/impe-ecard-tipografia.XXXXXX")"
LOCK="$(dirname "$LIVE")/.impe-correos-deploy.lock";LOCKED=0;INSTALLING=-1
BEFORE=();AFTER=();WRITTEN=();CHANGED=()
finish(){ local code=$?;trap - EXIT INT TERM
 if [[ "$code" != 0 ]];then
  for i in "${WRITTEN[@]}";do
   if [[ -f "$LIVE/${FILES[$i]}" && "$(hash "$LIVE/${FILES[$i]}")" == "${AFTER[$i]}" ]];then
    cp -p -- "$TMP/original.$i" "$LIVE/${FILES[$i]}" || echo 'REVISAR RESTAURACIÓN DEL ARCHIVO' >&2
   else echo "REVISAR: archivo cambiado durante la publicación: ${FILES[$i]}" >&2;fi
  done
 fi
 if [[ "$INSTALLING" != -1 ]];then rm -f -- "$LIVE/${FILES[$INSTALLING]}.impe-ecard-tipografia-$$";fi
 [[ "$LOCKED" == 0 ]] || rmdir -- "$LOCK"
 rm -f -- "$TMP/original.0" "$TMP/stage.0" "$TMP/patch.php" "$TMP/probe.php"
 rmdir -- "$TMP";exit "$code"
}
trap finish EXIT;trap 'exit 130' INT;trap 'exit 143' TERM
if [[ "$MODE" != --check && "$MODE" != --probe ]];then no_links "$LOCK";mkdir "$LOCK" 2>/dev/null || fail 'Otra publicación está en curso.';LOCKED=1;fi
if [[ "$MODE" == --probe ]];then
 cat > "$TMP/probe.php" <<'IMPE_ECARD_PROBE'
<?php
// Sólo CLI: imagen ficticia en el temporal del sistema; sin BD ni archivos del CRM.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? dirname(__DIR__));
$temporal = false;
$salida = 0;
try {
    if (!$root || !is_file($root . '/app/services/EcardReunionService.php')) {
        throw new RuntimeException('No se encontró el generador de Ecard.');
    }
    require_once $root . '/app/services/EcardReunionService.php';
    echo 'VERSION_ECARD=', EcardReunionService::VERSION, PHP_EOL;
    if (EcardReunionService::VERSION !== '20261026-tipografia-1') {
        throw new RuntimeException('Sigue instalado el generador anterior. Aplica la actualización de Ecard.');
    }
    foreach (['imagettftext','imagettfbbox','imagecreatefromjpeg','imagejpeg'] as $funcion) {
        if (!function_exists($funcion)) {
            throw new RuntimeException('GD/FreeType incompleto para generar la Ecard.');
        }
    }
    $ref = new ReflectionClass(EcardReunionService::class);
    // El render de la plantilla aprobada no necesita abrir una conexión de BD.
    $service = $ref->newInstanceWithoutConstructor();
    $ref->getProperty('rootPath')->setValue($service, $root);
    $fuentes = $ref->getMethod('resolverFuente');
    foreach ([false, true] as $bold) {
        $fuente = $fuentes->invoke($service, $bold);
        if ($fuente === '' || !is_file($fuente)) {
            throw new RuntimeException('No se encontró una fuente TrueType válida.');
        }
        echo $bold ? 'FUENTE_NEGRITA=' : 'FUENTE_NORMAL=', basename($fuente), PHP_EOL;
    }
    $temporal = tempnam(sys_get_temp_dir(), 'impe-ecard-tipo-');
    if ($temporal === false) {
        throw new RuntimeException('No se pudo preparar la prueba temporal.');
    }
    $resultado = $ref->getMethod('crearImagenManuelPlantilla')->invoke(
        $service, $temporal, new DateTime('2026-10-26 12:37:00'),
        'PRUEBA 2', 'En línea', 'Virtual', ''
    );
    if (!($resultado['ok'] ?? false)) {
        throw new RuntimeException('No se pudo renderizar la plantilla aprobada.');
    }
    $imagen = imagecreatefromjpeg($temporal);
    if (!$imagen || imagesx($imagen) !== 1200 || imagesy($imagen) !== 1212) {
        throw new RuntimeException('La imagen de prueba tiene una resolución incorrecta.');
    }
    // Medir los píxeles de tinta real en fecha/hora, no sólo el tamaño configurado.
    foreach (['FECHA' => [700, 780, 25], 'HORA' => [790, 850, 20]] as $campo => [$inicio,$fin,$minimo]) {
        $superior = $fin; $inferior = $inicio;
        for ($y = $inicio; $y <= $fin; $y++) {
            for ($x = 120; $x < 1080; $x++) {
                $color = imagecolorat($imagen, $x, $y);
                if ((($color >> 16) & 255) < 35 && (($color >> 8) & 255) < 95 && ($color & 255) < 135) {
                    $superior = min($superior, $y); $inferior = max($inferior, $y);
                }
            }
        }
        $alto = max(0, $inferior - $superior + 1);
        echo 'ALTURA_', $campo, '_PX=', $alto, PHP_EOL;
        if ($alto < $minimo) {
            throw new RuntimeException('La prueba sigue mostrando letra demasiado pequeña.');
        }
    }
    imagedestroy($imagen);
    echo "OK: Ecard 1200x1212 con tipografía legible. Prueba ficticia, sin BD ni envíos. Temporal retirado.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    $salida = 1;
} finally {
    if ($temporal !== false && is_file($temporal)) {
        unlink($temporal);
    }
}
exit($salida);
IMPE_ECARD_PROBE
 php "$TMP/probe.php" "$LIVE"
 exit $?
fi
if [[ "$MODE" != --restore ]];then
 php -r '$r=$argv[1];foreach(["imagettftext","imagettfbbox","imagecreatefromstring","imagejpeg","mb_strtoupper"] as $f){if(!function_exists($f))exit(2);}foreach(["DejaVuSans.ttf","DejaVuSans-Bold.ttf"] as $f){$p=$r."/vendor/dompdf/dompdf/lib/fonts/".$f;if(!is_file($p)||@imagettfbbox(24,0,$p,"Reunión México")===false)exit(2);}echo "OK: FreeType y fuentes TrueType de Ecard disponibles.\n";' "$LIVE" || fail 'Faltan FreeType o fuentes de Dompdf. SITIO INTACTO.'
fi
for i in "${!FILES[@]}";do BEFORE[$i]="$(hash "$LIVE/${FILES[$i]}")";cp -p -- "$LIVE/${FILES[$i]}" "$TMP/original.$i";done
if [[ "$MODE" == --restore ]];then
 no_links "$2";RESTORE="$(realpath -e "$2")"
 [[ "$(dirname "$RESTORE")" == "$BACKUPS" ]] || fail 'Respaldo fuera de la carpeta prevista.'
 no_links "$RESTORE/version.txt";[[ -f "$RESTORE/version.txt" && "$(<"$RESTORE/version.txt")" == "$VERSION" ]] || fail 'Respaldo de otra entrega.'
 for i in "${!FILES[@]}";do
  for file in "original.$i" "original.$i.sha256" "publicado.$i.sha256";do no_links "$RESTORE/$file";[[ -f "$RESTORE/$file" ]] || fail 'Respaldo incompleto.';done
  original="$(<"$RESTORE/original.$i.sha256")";published="$(<"$RESTORE/publicado.$i.sha256")"
  [[ "$original" =~ ^[a-f0-9]{64}$ && "$published" =~ ^[a-f0-9]{64}$ && "$(hash "$RESTORE/original.$i")" == "$original" ]] || fail 'Respaldo alterado.'
  [[ "${BEFORE[$i]}" == "$published" || "${BEFORE[$i]}" == "$original" ]] || fail 'Archivo editado después de publicar. No se sobrescribe.'
  cp -p -- "$RESTORE/original.$i" "$TMP/stage.$i"
 done
else
 cat > "$TMP/patch.php" <<'IMPE_PATCH'
<?php
$cfg=json_decode(base64_decode('W1siICAgIHB1YmxpYyBjb25zdCBURU1QTEFURV9TRVJHSU8gPSAnU0VSR0lPJztcbiAgICBwdWJsaWMgY29uc3QgVEVNUExBVEVfTUFOVUVMID0gJ01BTlVFTCc7XG4gICAgcHVibGljIGNvbnN0IENJRCA9ICdlY2FyZC1yZXVuaW9uJztcbiAgICBwdWJsaWMgY29uc3QgVkVSU0lPTiA9ICcyMDI2MDkyMC0xNCc7XG5cbiAgICBwcml2YXRlICRjb25uZWN0aW9uO1xuICAgIHByaXZhdGUgJHJvb3RQYXRoO1xuIiwgIiAgICBwdWJsaWMgY29uc3QgVEVNUExBVEVfU0VSR0lPID0gJ1NFUkdJTyc7XG4gICAgcHVibGljIGNvbnN0IFRFTVBMQVRFX01BTlVFTCA9ICdNQU5VRUwnO1xuICAgIHB1YmxpYyBjb25zdCBDSUQgPSAnZWNhcmQtcmV1bmlvbic7XG4gICAgcHVibGljIGNvbnN0IFZFUlNJT04gPSAnMjAyNjEwMjYtdGlwb2dyYWZpYS0xJztcblxuICAgIHByaXZhdGUgJGNvbm5lY3Rpb247XG4gICAgcHJpdmF0ZSAkcm9vdFBhdGg7XG4iXSwgWyIgICAgICAgICAgICByZXR1cm4gJHRoaXMtPmVycm9yKFxuICAgICAgICAgICAgICAgICdObyBmdWUgcG9zaWJsZSBnZW5lcmFyIGxhIEVjYXJkIHBvcnF1ZSBsYSBleHRlbnNpw7NuIEdEIGRlIFBIUCBubyBlc3TDoSBkaXNwb25pYmxlLidcbiAgICAgICAgICAgICk7XG4gICAgICAgIH1cblxuICAgICAgICAkcmV1bmlvbklkID0gKGludCkoJHJldW5pb25bJ2lkJ10gPz8gMCk7XG4iLCAiICAgICAgICAgICAgcmV0dXJuICR0aGlzLT5lcnJvcihcbiAgICAgICAgICAgICAgICAnTm8gZnVlIHBvc2libGUgZ2VuZXJhciBsYSBFY2FyZCBwb3JxdWUgbGEgZXh0ZW5zacOzbiBHRCBkZSBQSFAgbm8gZXN0w6EgZGlzcG9uaWJsZS4nXG4gICAgICAgICAgICApO1xuICAgICAgICB9XG5cbiAgICAgICAgaWYgKCFmdW5jdGlvbl9leGlzdHMoJ2ltYWdldHRmdGV4dCcpIHx8ICFmdW5jdGlvbl9leGlzdHMoJ2ltYWdldHRmYmJveCcpIHx8XG4gICAgICAgICAgICAkdGhpcy0+cmVzb2x2ZXJGdWVudGUoZmFsc2UpID09PSAnJyB8fCAkdGhpcy0+cmVzb2x2ZXJGdWVudGUodHJ1ZSkgPT09ICcnKSB7XG4gICAgICAgICAgICByZXR1cm4gJHRoaXMtPmVycm9yKCdObyBmdWUgcG9zaWJsZSBnZW5lcmFyIGxhIEVjYXJkOiBmYWx0YW4gbGFzIGZ1ZW50ZXMgVHJ1ZVR5cGUgbyBGcmVlVHlwZSBkZWwgc2Vydmlkb3IuJyk7XG4gICAgICAgIH1cblxuICAgICAgICAkcmV1bmlvbklkID0gKGludCkoJHJldW5pb25bJ2lkJ10gPz8gMCk7XG4iXSwgWyJcbiAgICAgICAgJGFsdG9Ub3RhbCArPSBtYXgoMCwgY291bnQoJG1ldHJpY2FzKSAtIDEpICogKGludCkkc2VwYXJhY2lvbjtcbiAgICAgICAgJHpvbmFBbHRvID0gbWF4KDEsIChpbnQpJHlJbmZlcmlvciAtIChpbnQpJHlTdXBlcmlvcik7XG4gICAgICAgICRjdXJzb3JZID0gKGludCkkeVN1cGVyaW9yICsgbWF4KDAsIChpbnQpKCgkem9uYUFsdG8gLSAkYWx0b1RvdGFsKSAvIDIpKTtcblxuICAgICAgICBmb3JlYWNoICgkbWV0cmljYXMgYXMgJG1ldHJpY2EpIHtcbiIsICJcbiAgICAgICAgJGFsdG9Ub3RhbCArPSBtYXgoMCwgY291bnQoJG1ldHJpY2FzKSAtIDEpICogKGludCkkc2VwYXJhY2lvbjtcbiAgICAgICAgJHpvbmFBbHRvID0gbWF4KDEsIChpbnQpJHlJbmZlcmlvciAtIChpbnQpJHlTdXBlcmlvcik7XG4gICAgICAgIGlmICgkYWx0b1RvdGFsID4gJHpvbmFBbHRvICYmICR0YW1hbm8gPiAxMikge1xuICAgICAgICAgICAgJHRoaXMtPmRpYnVqYXJCbG9xdWVUZXh0b0NlbnRyYWRvVmVydGljYWwoXG4gICAgICAgICAgICAgICAgJGltYWdlbiwgJGxpbmVhcywgJHRhbWFubyAtIDEsICR5U3VwZXJpb3IsICR5SW5mZXJpb3IsXG4gICAgICAgICAgICAgICAgJGNvbG9yLCAkZnVlbnRlLCAkc2VwYXJhY2lvblxuICAgICAgICAgICAgKTtcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgfVxuICAgICAgICAkY3Vyc29yWSA9IChpbnQpJHlTdXBlcmlvciArIG1heCgwLCAoaW50KSgoJHpvbmFBbHRvIC0gJGFsdG9Ub3RhbCkgLyAyKSk7XG5cbiAgICAgICAgZm9yZWFjaCAoJG1ldHJpY2FzIGFzICRtZXRyaWNhKSB7XG4iXSwgWyIgICAge1xuICAgICAgICAkY2FuZGlkYXRhcyA9ICRib2xkXG4gICAgICAgICAgICA/IFtcbiAgICAgICAgICAgICAgICAnQzovV2luZG93cy9Gb250cy9hcmlhbGJkLnR0ZicsXG4gICAgICAgICAgICAgICAgJy91c3Ivc2hhcmUvZm9udHMvdHJ1ZXR5cGUvZGVqYXZ1L0RlamFWdVNhbnMtQm9sZC50dGYnLFxuICAgICAgICAgICAgICAgICcvdXNyL3NoYXJlL2ZvbnRzL2RlamF2dS9EZWphVnVTYW5zLUJvbGQudHRmJ1xuICAgICAgICAgICAgXVxuICAgICAgICAgICAgOiBbXG4gICAgICAgICAgICAgICAgJ0M6L1dpbmRvd3MvRm9udHMvYXJpYWwudHRmJyxcbiAgICAgICAgICAgICAgICAnL3Vzci9zaGFyZS9mb250cy90cnVldHlwZS9kZWphdnUvRGVqYVZ1U2Fucy50dGYnLFxuICAgICAgICAgICAgICAgICcvdXNyL3NoYXJlL2ZvbnRzL2RlamF2dS9EZWphVnVTYW5zLnR0ZidcbiAgICAgICAgICAgIF07XG5cbiAgICAgICAgZm9yZWFjaCAoJGNhbmRpZGF0YXMgYXMgJHJ1dGEpIHtcbiAgICAgICAgICAgIGlmIChpc19maWxlKCRydXRhKSkge1xuICAgICAgICAgICAgICAgIHJldHVybiAkcnV0YTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuIiwgIiAgICB7XG4gICAgICAgICRjYW5kaWRhdGFzID0gJGJvbGRcbiAgICAgICAgICAgID8gW1xuICAgICAgICAgICAgICAgICR0aGlzLT5yb290UGF0aCAuICcvdmVuZG9yL2RvbXBkZi9kb21wZGYvbGliL2ZvbnRzL0RlamFWdVNhbnMtQm9sZC50dGYnLFxuICAgICAgICAgICAgICAgICdDOi9XaW5kb3dzL0ZvbnRzL2FyaWFsYmQudHRmJyxcbiAgICAgICAgICAgICAgICAnL3Vzci9zaGFyZS9mb250cy90cnVldHlwZS9kZWphdnUvRGVqYVZ1U2Fucy1Cb2xkLnR0ZicsXG4gICAgICAgICAgICAgICAgJy91c3Ivc2hhcmUvZm9udHMvZGVqYXZ1L0RlamFWdVNhbnMtQm9sZC50dGYnXG4gICAgICAgICAgICBdXG4gICAgICAgICAgICA6IFtcbiAgICAgICAgICAgICAgICAkdGhpcy0+cm9vdFBhdGggLiAnL3ZlbmRvci9kb21wZGYvZG9tcGRmL2xpYi9mb250cy9EZWphVnVTYW5zLnR0ZicsXG4gICAgICAgICAgICAgICAgJ0M6L1dpbmRvd3MvRm9udHMvYXJpYWwudHRmJyxcbiAgICAgICAgICAgICAgICAnL3Vzci9zaGFyZS9mb250cy90cnVldHlwZS9kZWphdnUvRGVqYVZ1U2Fucy50dGYnLFxuICAgICAgICAgICAgICAgICcvdXNyL3NoYXJlL2ZvbnRzL2RlamF2dS9EZWphVnVTYW5zLnR0ZidcbiAgICAgICAgICAgIF07XG5cbiAgICAgICAgZm9yZWFjaCAoJGNhbmRpZGF0YXMgYXMgJHJ1dGEpIHtcbiAgICAgICAgICAgIGlmIChpc19maWxlKCRydXRhKSAmJiBmdW5jdGlvbl9leGlzdHMoJ2ltYWdldHRmYmJveCcpICYmXG4gICAgICAgICAgICAgICAgQGltYWdldHRmYmJveCgyMCwgMCwgJHJ1dGEsICdSZXVuacOzbiBNw6l4aWNvJykgIT09IGZhbHNlKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuICRydXRhO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4iXV0='),true,512,JSON_THROW_ON_ERROR);
$s=file_get_contents($argv[2]);$crlf=substr_count($s,"\r\n");
if($crlf&&$crlf!==substr_count($s,"\n"))throw new RuntimeException('Finales de línea mixtos.');
$s=str_replace("\r\n","\n",$s);
foreach($cfg as [$old,$new]){
 if(substr_count($s,$new)===1)continue;
 if(substr_count($s,$new)!==0||substr_count($s,$old)!==1)throw new RuntimeException('Generador personalizado o incompatible. No se sobrescribe completo.');
 $s=str_replace($old,$new,$s);
}
if($crlf)$s=str_replace("\n","\r\n",$s);
if(file_put_contents($argv[3],$s)!==strlen($s))throw new RuntimeException('No se pudo preparar el archivo.');
IMPE_PATCH
 for i in "${!FILES[@]}";do php "$TMP/patch.php" "$i" "$TMP/original.$i" "$TMP/stage.$i" || fail 'Generador Ecard incompatible. SITIO INTACTO.';chmod --reference="$TMP/original.$i" "$TMP/stage.$i";done
fi
for i in "${!FILES[@]}";do php -l "$TMP/stage.$i" >/dev/null || fail 'Error de sintaxis en el servicio preparado.';done
for i in "${!FILES[@]}";do
 no_links "$LIVE/${FILES[$i]}";[[ "$(hash "$LIVE/${FILES[$i]}")" == "${BEFORE[$i]}" ]] || fail 'Un archivo cambió durante la revisión.'
 AFTER[$i]="$(hash "$TMP/stage.$i")";[[ "${AFTER[$i]}" == "${BEFORE[$i]}" ]] || CHANGED+=("$i")
done
if [[ "${#CHANGED[@]}" == 0 ]];then echo 'OK: ya aplicado o restaurado. Ningún archivo reemplazado.';exit 0;fi
if [[ "$MODE" == --check ]];then echo 'OK: revisión compatible. Sólo EcardReunionService.php; versión 20261026-tipografia-1. SITIO INTACTO. Sin envíos ni SQL.';exit 0;fi
if [[ "$MODE" == --apply ]];then
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/ecard-tipografia-$(date +%Y%m%d-%H%M%S)-$$";(umask 077;mkdir "$BACKUP")
 for i in "${!FILES[@]}";do
  cp -p -- "$TMP/original.$i" "$BACKUP/original.$i";[[ "$(hash "$BACKUP/original.$i")" == "${BEFORE[$i]}" ]] || fail 'Respaldo inconsistente.'
  printf '%s\n' "${BEFORE[$i]}" > "$BACKUP/original.$i.sha256";printf '%s\n' "${AFTER[$i]}" > "$BACKUP/publicado.$i.sha256"
 done
 printf '%s\n' "$VERSION" > "$BACKUP/version.txt"
fi
for i in "${CHANGED[@]}";do
 install="$LIVE/${FILES[$i]}.impe-ecard-tipografia-$$";[[ ! -e "$install" && ! -L "$install" ]] || fail 'Existe un temporal de instalación.'
 INSTALLING="$i";cp -p -- "$TMP/stage.$i" "$install"
 for j in "${CHANGED[@]}";do
  expected="${BEFORE[$j]}";for done_i in "${WRITTEN[@]}";do [[ "$done_i" != "$j" ]] || expected="${AFTER[$j]}";done
  no_links "$LIVE/${FILES[$j]}";[[ "$(hash "$LIVE/${FILES[$j]}")" == "$expected" ]] || fail 'Un archivo cambió antes de instalar.'
 done
 WRITTEN+=("$i");mv -f -- "$install" "$LIVE/${FILES[$i]}";INSTALLING=-1
 [[ "$(hash "$LIVE/${FILES[$i]}")" == "${AFTER[$i]}" ]] || fail 'Archivo instalado distinto.'
done
echo "OK: $MODE terminado. Ecard actualizada. Volver a abrir la vista previa regenerará la imagen. Sin SQL, correos ni cambios de aliados."
if [[ "$MODE" == --apply ]];then echo "Respaldo: $BACKUP";printf 'Rollback: bash %q --restore %q\n' "$(realpath "$0")" "$BACKUP";fi
