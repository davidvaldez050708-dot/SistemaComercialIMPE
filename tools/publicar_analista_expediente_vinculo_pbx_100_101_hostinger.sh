#!/usr/bin/env bash
# SOLO expediente del Analista. No modifica Recepcion/Ventas ni historial de Recibidas.
set -euo pipefail
umask 077
python3 - "$@" <<'PY'
import os,sys,json,hashlib,stat,tempfile,subprocess
from pathlib import Path
SPEC=json.loads(r'''{"file":"app/services/TelefoniaGrabacionExpedienteTransferidaService.php","patches":[["        if(!$row||(int)$row['usuario_id']!==$usuarioId)return $reply('ASOCIACION_PENDIENTE','El audio requiere verificar la participación de tu extensión en esta llamada.');\n        if(in_array(strtoupper((string)$row['resultado']),['NO_CONTESTO','SIN_RESPUESTA','OCUPADO','BUZON_VOZ','FUERA_SERVICIO','NUMERO_INCORRECTO'],true)||\n            preg_match('/\\[(BUZON_VOZ|FUERA_SERVICIO)\\]/',(string)$row['notas']))return $reply('SOLO_HISTORIAL','Este intento se conserva sin reproducción de audio.');\n        $proveedor=strtoupper((string)$row['proveedor_externo']);$pbx=null;\n        if($proveedor==='ZADARMA_WEBRTC')$pbx=$this->buscar($usuarioId,$seguimientoId,$interaccionId);\n        elseif($proveedor==='ZADARMA'){\n            $pbx=(string)$row['id_externo'];\n            // Una saliente ordinaria conserva su reproductor y autorización actuales.\n            if(preg_match('/^out_[a-fA-F0-9]{32,64}$/D',$pbx)){","        if(!$row||(int)$row['usuario_id']!==$usuarioId)return $reply('ASOCIACION_PENDIENTE','El audio requiere verificar la participación de tu extensión en esta llamada.');\n        if(in_array(strtoupper((string)$row['resultado']),['NO_CONTESTO','SIN_RESPUESTA','OCUPADO','BUZON_VOZ','FUERA_SERVICIO','NUMERO_INCORRECTO'],true)||\n            preg_match('/\\[(BUZON_VOZ|FUERA_SERVICIO)\\]/',(string)$row['notas']))return $reply('SOLO_HISTORIAL','Este intento se conserva sin reproducción de audio.');\n        $proveedor=strtoupper((string)$row['proveedor_externo']);$pbx=null;\n        if($proveedor==='ZADARMA_WEBRTC'){\n            // La interacción conserva WEBRTC-{session_id}. Preferir el vínculo\n            // exacto con la transferencia PBX confirmada, igual que Recibidas.\n            $pbx=$this->buscarTransferenciaExacta(\n                $usuarioId,$seguimientoId,$interaccionId,(string)$row['id_externo']);\n            if(!$pbx)$pbx=$this->buscar($usuarioId,$seguimientoId,$interaccionId);\n        }\n        elseif($proveedor==='ZADARMA'){\n            $pbx=(string)$row['id_externo'];\n            // Una saliente ordinaria conserva su reproductor y autorización actuales.\n            if(preg_match('/^out_[a-fA-F0-9]{32,64}$/D',$pbx)){"],["        if(count($ids)>8)return $reply('AUDIO_PENDIENTE','La grabación requiere revisar sus segmentos antes de reproducirla.');\n        return $reply('REPORTADA','Audio reportado por la centralita; su disponibilidad se comprueba al abrirlo.',$pbx,$ids);\n    }\n\n    public static function numero10(string $numero): string\n    {\n        $digits = preg_replace('/\\\\D+/', '', $numero) ?: '';\n        return strlen($digits) >= 10 ? substr($digits, -10) : '';","        if(count($ids)>8)return $reply('AUDIO_PENDIENTE','La grabación requiere revisar sus segmentos antes de reproducirla.');\n        return $reply('REPORTADA','Audio reportado por la centralita; su disponibilidad se comprueba al abrirlo.',$pbx,$ids);\n    }\n\n    /**\n     * Une la interacción existente con la misma sesión RTCSession utilizada\n     * durante la entrega, y de allí a la referencia de transferencia PBX.\n     * No utiliza teléfono, fechas, ni un PBX proporcionado por el navegador.\n     */\n    public function buscarTransferenciaExacta(int $usuarioId,int $seguimientoId,\n                                               int $interaccionId,string $externo): ?string\n    {\n        $session=self::sessionIdExterno($externo);\n        if($session===null)return null;\n        $audios=new TelefoniaGrabacionesTransferenciaInstitucionService($this->db);\n        // Si el registro PBX ya conservó la interacción exacta, no exigir\n        // también una sesión WebRTC \"ended\" para habilitar sus dos tramos.\n        $q=$this->db->prepare(\"SELECT pbx_call_id,extension\n            FROM telefonia_analista_entrantes\n            WHERE usuario_id=? AND seguimiento_id=? AND interaccion_id=?\n              AND pbx_call_id LIKE 'out_%'\n            LIMIT 2\");\n        $q->bind_param('iii',$usuarioId,$seguimientoId,$interaccionId);\n        $q->execute();$directas=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();\n        if(count($directas)>1)return null;\n        if(count($directas)===1){\n            $pbxDirecto=(string)$directas[0]['pbx_call_id'];\n            try{\n                $ok=$audios->verificar($pbxDirecto,$usuarioId,\n                    (string)$directas[0]['extension'],$seguimientoId);\n                if($ok['ids'])return $pbxDirecto;\n            }catch(DomainException $ignored){ /* sin privilegio PBX */ }\n        }\n        $stmt=$this->db->prepare(\"SELECT session_id,extension\n            FROM telefonia_analista_webrtc_sesiones\n            WHERE usuario_id=? AND seguimiento_id=? AND interaccion_id=?\n              AND session_id=BINARY ? AND clasificacion='VINCULADA'\n              AND fecha_atendida IS NOT NULL\n            LIMIT 2\");\n        $stmt->bind_param('iiis',$usuarioId,$seguimientoId,$interaccionId,$session);\n        $stmt->execute();$sesiones=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();\n        if(count($sesiones)!==1)return null;\n        $extension=(string)$sesiones[0]['extension'];\n        if(!preg_match('/^[0-9]{3,6}$/D',$extension))return null;\n        $stmt=$this->db->prepare(\"SELECT referencia,sdk_consulta\n            FROM telefonia_transferencia_operaciones\n            WHERE destino_usuario=? AND destino_extension=BINARY ?\n              AND sdk_consulta IS NOT NULL\n              AND (sdk_consulta=BINARY ? OR CONCAT(sdk_consulta,'-external')=BINARY ?)\n            LIMIT 3\");\n        $stmt->bind_param('isss',$usuarioId,$extension,$session,$session);\n        $stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();\n        if(count($rows)!==1||\n            !self::sdkDeTransferenciaCoincide((string)$rows[0]['sdk_consulta'],$session))return null;\n        $pbx=(string)$rows[0]['referencia'];\n        if(!preg_match('/^out_[a-fA-F0-9]{32,64}$/D',$pbx))return null;\n        try {\n            $verified=$audios->verificar($pbx,$usuarioId,$extension,$seguimientoId);\n            return $verified['ids']?$pbx:null;\n        } catch (DomainException $ignored) {\n            return null;\n        }\n    }\n\n    /** Nunca aceptar coincidencia por prefijo ni por proximidad temporal. */\n    public static function sdkDeTransferenciaCoincide(string $sdkConsulta,string $sesion): bool\n    {\n        return $sdkConsulta!=='' &&\n            ($sdkConsulta===$sesion || $sdkConsulta.'-external'===$sesion);\n    }\n\n    /** El prefijo de interacción WebRTC no debe confundirse con el PBX. */\n    public static function sessionIdExterno(string $externo): ?string\n    {\n        if(!str_starts_with($externo,'WEBRTC-'))return null;\n        $id=substr($externo,7);\n        // Misma convención usada al registrar la sesión telefónica.\n        return preg_match('/^sdk-[a-z0-9-]{12,94}$/D',$id) ? $id : null;\n    }\n\n    public static function numero10(string $numero): string\n    {\n        $digits = preg_replace('/\\\\D+/', '', $numero) ?: '';\n        return strlen($digits) >= 10 ? substr($digits, -10) : '';"]],"smoke":"<?php\ndeclare(strict_types=1);\nrequire_once __DIR__.'/../app/services/TelefoniaGrabacionExpedienteTransferidaService.php';\nfunction assertAudio(bool $v,string $why): void {if(!$v)throw new RuntimeException('FALLO: '.$why);}\n$real='sdk-123456789012abcd-external';\n$session=TelefoniaGrabacionExpedienteTransferidaService::sessionIdExterno('WEBRTC-'.$real);\nassertAudio($session===$real,'WebRTC external exacta');\nassertAudio(TelefoniaGrabacionExpedienteTransferidaService::sdkDeTransferenciaCoincide(\n 'sdk-123456789012abcd',$session),'Consultada real seguida de -external');\nassertAudio(TelefoniaGrabacionExpedienteTransferidaService::sdkDeTransferenciaCoincide(\n $real,$real),'Sesion directa con ID identico');\nforeach(['sdk-123456789012abcd-other','sdk-123456789012abc','',\n         'sdk-123456789012abcd-external-extra'] as $foreign)\n    assertAudio(!TelefoniaGrabacionExpedienteTransferidaService::sdkDeTransferenciaCoincide(\n        $foreign,$real),'No asociar otra RTCSession: '.$foreign);\nforeach(['','123456789012abcd','PBX-out_123','WEBRTC-out_123456789012abcd',\n         'WEBRTC-sdk-abc','WEBRTC-sdk-123456789012ABCD',\n         'WEBRTC-sdk-123456789012abcd?'] as $invalid)\n    assertAudio(TelefoniaGrabacionExpedienteTransferidaService::sessionIdExterno($invalid)===null,\n        'No aceptar ID no coincidente: '.$invalid);\n$code=file_get_contents(__DIR__.'/../app/services/TelefoniaGrabacionExpedienteTransferidaService.php');\nassertAudio(strpos($code,\"WHERE usuario_id=? AND seguimiento_id=? AND interaccion_id=?\")!==false,\n    'Solo interacción registrada en seguimiento del analista');\nassertAudio(strpos($code,\"AND session_id=BINARY ?\")!==false,\n    'Exigir misma identidad SDK, sin aproximación');\nassertAudio(strpos($code,\"sdk_consulta=BINARY ? OR CONCAT(sdk_consulta,'-external')=BINARY ?\")!==false,\n    'Buscar transferencia por token exacto');\nassertAudio(strpos($code,'$audios->verificar(')!==false,\n    'El permiso se valida por operación, transferencia institucional y respuesta del 101');\necho \"OK: expediente vincula interacción -> sesión SDK -> transferencia PBX exacta, sin cruce de llamadas.\\n\";\n"}''')
def fail(msg):
    sys.stderr.write('CONFLICTO: '+msg+'\n');sys.exit(2)
if len(sys.argv)<2 or sys.argv[1] not in ('--check','--apply','--restore'):
    sys.stderr.write('Uso: IMPE_CRM_PUBLIC=/ruta/public_html bash publicador --check|--apply|--restore [respaldo]\n');sys.exit(1)
action=sys.argv[1]
root_arg=os.environ.get('IMPE_CRM_PUBLIC','')
if not root_arg:fail('Falta IMPE_CRM_PUBLIC')
root=Path(root_arg).resolve()
if not (root/'index.php').is_file():fail('Raiz de CRM invalida')
backup_root=Path(os.environ.get('IMPE_CRM_BACKUPS',str(Path.home()/'impe-analista-audios-backups'))).resolve()
if backup_root==root or backup_root in root.parents or root in backup_root.parents:
    fail('Respaldo debe estar fuera de public_html')
name=SPEC['file'];file=root/name
if file.is_symlink() or root not in file.resolve().parents or not file.is_file():
    fail('Servicio ausente o inseguro: '+name)
def digest(raw):return hashlib.sha256(raw).hexdigest()
def atom(raw,mode):
    fd,temp=tempfile.mkstemp(prefix='.analista-expediente-',dir=str(file.parent))
    try:
        os.fchmod(fd,mode)
        with os.fdopen(fd,'wb') as out:
            out.write(raw);out.flush();os.fsync(out.fileno())
        os.replace(temp,str(file))
    finally:
        if os.path.exists(temp):os.unlink(temp)
def lint(raw):
    fd,path=tempfile.mkstemp(suffix='.php')
    try:
        with os.fdopen(fd,'wb') as out:out.write(raw)
        proc=subprocess.run(['php','-l',path],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if proc.returncode:fail('Error de sintaxis: '+(proc.stdout+proc.stderr).decode('utf8','replace')[-650:])
    finally:
        if os.path.exists(path):os.unlink(path)
if action=='--restore':
    if len(sys.argv)!=3:fail('Indica el respaldo exacto')
    folder=Path(sys.argv[2]).resolve()
    if backup_root not in folder.parents or folder.is_symlink():fail('Respaldo no autorizado')
    manifest=folder/'manifest.json'
    if not manifest.is_file():fail('Falta manifiesto')
    record=json.loads(manifest.read_text(encoding='utf8'))
    if record.get('path')!=name:fail('Respaldo de otra entrega')
    old=(folder/'original.bak').read_bytes()
    if digest(old)!=record['old_sha']:fail('Respaldo alterado')
    current=file.read_bytes()
    if digest(current) not in (record['new_sha'],record['old_sha']):
        fail('Archivo personalizado tras la publicacion: no restaurar automaticamente')
    if digest(current)==record['new_sha']:atom(old,record['mode'])
    print('OK: restauracion exacta del expediente; no se modificaron otras vistas')
    sys.exit(0)
if len(sys.argv)!=2:fail('Argumentos extras no permitidos')
raw=file.read_bytes()
if b'\r\n' in raw and raw.count(b'\r\n')!=raw.count(b'\n'):
    fail('Finales de linea mixtos')
try:source=raw.decode('utf8').replace('\r\n','\n')
except UnicodeDecodeError:fail('Archivo no UTF8')
updated=source;old_count=0;new_count=0
for before,after in SPEC['patches']:
    before=(before+'\n' if before.endswith('\r') else before).replace('\r\n','\n')
    after=(after+'\n' if after.endswith('\r') else after).replace('\r\n','\n')
    if source.count(before)==1 and source.count(after)==0:
        if updated.count(before)!=1:fail('Parches solapados')
        updated=updated.replace(before,after,1);old_count+=1
    elif source.count(before)==0 and source.count(after)==1:new_count+=1
    else:fail('Version de expediente diferente; no se aplican cambios')
if old_count and new_count:fail('Publicacion parcial: revisar antes de continuar')
target=(updated.replace('\n','\r\n') if b'\r\n' in raw else updated).encode('utf8')
if old_count:
    # Ejecutar el mismo caso real de SDK y PBX exactos, aislado del sitio.
    # No hay SQL, conexiones al proveedor ni escritura en public_html.
    with tempfile.TemporaryDirectory(prefix='analista-expediente-test-') as td:
        tmp=Path(td)
        files=[
            (name,target),
            ('app/services/TelefoniaPbxSegmentosService.php',
             (root/'app/services/TelefoniaPbxSegmentosService.php').read_bytes()),
            ('app/services/TelefoniaGrabacionesTransferenciaInstitucionService.php',
             (root/'app/services/TelefoniaGrabacionesTransferenciaInstitucionService.php').read_bytes()),
            ('app/helpers/ZadarmaPbxIdentifierHelper.php',
             (root/'app/helpers/ZadarmaPbxIdentifierHelper.php').read_bytes()),
            ('tests/telefonia_analista_expediente_vinculo_exacto_smoke.php',
             SPEC['smoke'].encode('utf8'))
        ]
        for dest,data in files:
            f=tmp/dest;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(data)
        proc=subprocess.run(['php',str(tmp/'tests/telefonia_analista_expediente_vinculo_exacto_smoke.php')],
            stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if proc.returncode:fail('Prueba del vínculo exacto no aprobada: '+
            (proc.stdout+proc.stderr).decode('utf8','replace')[-500:])
        print(proc.stdout.decode('utf8','replace').strip())
print(name+': '+('pendiente' if old_count else 'actualizado'))
print('Archivos a modificar:',1 if old_count else 0)
if action=='--check':
    print('OK: verificacion de solo lectura, sin modificaciones')
    sys.exit(0)
if not old_count:
    print('OK: expediente ya actualizado')
    sys.exit(0)
backup_root.mkdir(parents=True,exist_ok=True,mode=0o700)
os.chmod(str(backup_root),0o700)
folder=Path(tempfile.mkdtemp(prefix='analista-expediente-pbx-',dir=str(backup_root)))
os.chmod(str(folder),0o700)
original=folder/'original.bak';original.write_bytes(raw);os.chmod(str(original),0o600)
meta=folder/'manifest.json'
meta.write_text(json.dumps({'path':name,'old_sha':digest(raw),
    'new_sha':digest(target),'mode':stat.S_IMODE(file.stat().st_mode)}),encoding='utf8')
os.chmod(str(meta),0o600)
if file.read_bytes()!=raw:fail('Edicion concurrente: no aplicar')
atom(target,stat.S_IMODE(file.stat().st_mode))
print('OK: vínculo exacto del expediente publicado. Respaldo:',str(folder))
print('Sin modificar BD, Analista Recibidas, Recepcion/Ventas, transferencias ni grabaciones')
PY
