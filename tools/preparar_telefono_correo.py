"""Regla única de teléfono y correo confirmados para avanzar y contabilizar."""
import base64,difflib,hashlib,json,re
from pathlib import Path
root=Path(__file__).resolve().parents[1];base=root/'tmp/telefono-correo-live';rows=[]
def edit(name,changes):
 old=(base/name).read_text(encoding='utf-8');new=old;local=(root/name).read_text(encoding='utf-8')
 for before,after in changes:
  if new.count(before)!=1:raise RuntimeError(name+' contexto distinto: '+before[:90])
  new=new.replace(before,after,1)
  if local.count(after)==1:continue
  if local.count(before)==1:local=local.replace(before,after,1)
  else:print('Conservar ampliacion instalada en paquete: '+name)
 (root/name).write_text(local,encoding='utf-8');p=root/'tmp/telefono-correo-stage'/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_text(new,encoding='utf-8')
 a,b=old.splitlines(True),new.splitlines(True);h=[]
 for g in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(2):h.append(dict(before=''.join(a[g[0][1]:g[-1][2]]),after=''.join(b[g[0][3]:g[-1][4]])))
 rows.append(dict(path=name,new_file=False,hunks=h))
name='app/services/VerificacionTelefonoCorreoService.php';data=(root/name).read_bytes().replace(b'\r\n',b'\n')
rows.append(dict(path=name,new_file=True,content=base64.b64encode(data).decode(),after_sha=hashlib.sha256(data).hexdigest()))
name='app/services/VerificacionInstitucionalService.php';old=(base/name).read_text(encoding='utf-8')
start=old.index('    public static function detalles(');end=old.index('    public static function mismoDato(',start)
before=old[start:end];after=(root/'tmp/nuevo_detalles_telefono_correo.php.txt').read_text(encoding='utf-8')
local=(root/name).read_text(encoding='utf-8');s=local.index('    public static function datoConfirmable(') if '    public static function datoConfirmable(' in local else local.index('    public static function detalles(');m=re.search(r'    public static function (?:mismoDato|estadoAtencion)\(',local[s+10:]);e=s+10+m.start()
(root/name).write_text(local[:s]+after+local[e:],encoding='utf-8')
edit(name,[(before,after)])

for name,datos,is_controller in [('app/controllers/SeguimientoVinculacionController.php','$_POST',True),('app/services/InteraccionRutaService.php','$datos',False)]:
 old=(base/name).read_text(encoding='utf-8');s=old.index('        $telefonoActual = trim(');e=old.index("        $contactoActual = trim((string)($seguimiento['contacto_nombre'] ?? ''));",s)+len("        $contactoActual = trim((string)($seguimiento['contacto_nombre'] ?? ''));")
 body="        try {\n"+''.join("            $%s=VerificacionInstitucionalService::datoConfirmable(%s,$seguimiento,'%s');\n"%(var,datos,key) for var,key in [('telefonoActual','telefono'),('correoActual','correo'),('contactoActual','contacto')])+"        } catch (InvalidArgumentException $e) { "
 body+=("$this->responderJson(['ok'=>false,'mensaje'=>$e->getMessage()],422);" if is_controller else "return $this->error($e->getMessage(),422);")+" }\n"
 edit(name,[(old[s:e],body)])

name='app/services/SeguimientoFlujoService.php'
edit(name,[("        return [\n            'ok' => true,\n            'flujo' => $this->resolverFlujo($seguimiento)","""        if ((int)($seguimiento['total_llamadas_validacion']??0)>0) {
            require_once __DIR__.'/VerificacionTelefonoCorreoService.php';
            if (!VerificacionTelefonoCorreoService::tieneEfectiva($this->connection,$seguimientoId,(int)$seguimiento['analista_id'])) {
                $seguimiento['total_llamadas_validacion']=0;
            }
        }
        return [
            'ok' => true,
            'flujo' => $this->resolverFlujo($seguimiento)"""),
 ("Ya se registró una llamada, pero todavía no existe una comunicación que permita validar el contacto. Vuelve a intentar hasta hablar con la institución o con la persona adecuada.","Para avanzar debes registrar una llamada con teléfono y correo confirmados durante esa misma conversación y con respuesta y cierre acreditados por telefonía.")])

name='app/models/SeguimientoVinculacionModel.php';old=(base/name).read_text(encoding='utf-8');changes=[]
anchor='    public function marcarDatosVerificadosSeguimiento($seguimientoId, $usuarioId)\n    {'
changes.append((anchor,anchor+"\n        require_once __DIR__.'/../services/VerificacionTelefonoCorreoService.php';\n        if (!VerificacionTelefonoCorreoService::tieneEfectiva($this->connection,(int)$seguimientoId,(int)$usuarioId)) return false;"))
anchor='    public function actualizarContactoSeguimiento($seguimientoId, $usuarioId, $datos, $marcarVerificado)\n    {'
changes.append((anchor,anchor+"\n        require_once __DIR__.'/../services/VerificacionTelefonoCorreoService.php';\n        if ($marcarVerificado && !VerificacionTelefonoCorreoService::tieneEfectiva($this->connection,(int)$seguimientoId,(int)$usuarioId)) return false;"))
s=old.index('    public function obtenerResumenVerificacionTelefonicaDia(');pos=old.index('        $sql = "SELECT',s)
anchor=old[pos:pos+len('        $sql = "SELECT')]
context=old[pos-25:pos+len(anchor)]
changes.append((context,context.replace(anchor,"        require_once __DIR__.'/../services/VerificacionTelefonoCorreoService.php';\n        $condicionVerificacion=VerificacionTelefonoCorreoService::tablaDisponible($this->connection)\n            ? VerificacionTelefonoCorreoService::condicionSql('interacciones_vinculacion') : '0=1';\n"+anchor)))
changes.append(("WHEN notas LIKE '%[VERIFICACION_EFECTIVA]%'",'WHEN {$condicionVerificacion}'))
edit(name,changes)

name='public/javascript/seguimiento_interacciones.js';old=(base/name).read_text(encoding='utf-8')
s=old.index('            if (notaEvidenciaVerificacion) {',old.index('const actualizarEvidenciaVerificacion'));e=old.index('\n        };',s)
edit(name,[("[campoPersonaAtendio, campoTelefonoReferido, campoCorreoReferido].forEach","[campoPersonaAtendio, campoTelefonoReferido, campoCorreoReferido, checkTelefono, checkCorreo, checkContacto].forEach"),
 ("resultadosConConversacion.includes(resultado) &&\n                !contactoEstaVerificado();","resultadosConConversacion.includes(resultado);"),
 ("            const telefonoActual = leerDatoVisible('[data-work-phone]');","            const telefonoActual = String(campoTelefonoReferido?.value||'').trim() || leerDatoVisible('[data-work-phone]');"),
 ("            const correoActual = leerDatoVisible('[data-work-email]');","            const correoActual = String(campoCorreoReferido?.value||'').trim() || leerDatoVisible('[data-work-email]');"),
 ("            const contactoActual = leerDatoVisible('[data-work-contact-name]');","            const contactoActual = String(campoNombreReferido?.value||'').trim() || leerDatoVisible('[data-work-contact-name]');"),
 (old[s:e],"            if (notaEvidenciaVerificacion) notaEvidenciaVerificacion.textContent='Para Contacto correcto y para que la llamada cuente, confirma teléfono y correo durante esta misma conversación. Capturar datos en el borrador no los confirma automáticamente.';"),
 ("            actualizarEvidenciaVerificacion();\n\n            if (resultado === 'CONTACTO_REFERIDO') {","""            actualizarEvidenciaVerificacion();
            const algunaConfirmacion=Boolean(checkTelefono?.checked||checkCorreo?.checked||checkContacto?.checked);
            if ((resultado==='CONTACTO_CORRECTO'||algunaConfirmacion) && !(checkTelefono?.checked&&checkCorreo?.checked)) {
                const mensaje='Debes confirmar teléfono y correo durante esta misma llamada.';
                mostrarToastLocal(mensaje,true);marcarCampoInvalido(!checkTelefono?.checked?checkTelefono:checkCorreo,mensaje);return false;
            }

            if (resultado === 'CONTACTO_REFERIDO') {"""),
 ("        const campoPersonaAtendio = formulario.querySelector(","        formulario.addEventListener('input',event=>{if(event.target.matches('[name=\"nuevo_telefono_contacto\"],[name=\"nuevo_correo_contacto\"]'))actualizarEvidenciaVerificacion();});\n        const campoPersonaAtendio = formulario.querySelector(")])

name='public/javascript/seguimiento_entrantes_analista.js'
edit(name,[("        evidence.hidden = !allowEvidence;","        evidence.hidden = !allowEvidence;\n        evidence.querySelector('p').textContent='Para Contacto correcto y para acreditar esta llamada debes confirmar teléfono y correo durante la conversación.';"),
 ("        if(!outgoingReady){showError('Puedes seguir capturando. El guardado se habilitará al confirmar esta llamada.');return;}","""        if(!outgoingReady){showError('Puedes seguir capturando. El guardado se habilitará al confirmar esta llamada.');return;}
        const confirmaTelefono=form.elements.verificacion_telefono_confirmado.checked;
        const confirmaCorreo=form.elements.verificacion_correo_confirmado.checked;
        const confirmaContacto=form.elements.verificacion_contacto_confirmado.checked;
        if ((resultInput.value==='CONTACTO_CORRECTO'||confirmaTelefono||confirmaCorreo||confirmaContacto) && !(confirmaTelefono&&confirmaCorreo)) {
            showError('Debes confirmar teléfono y correo durante esta misma llamada.');return;
        }""")])

(root/'tools/telefono_correo_release.json').write_text(json.dumps(dict(release='telefono-correo-obligatorios-v1',files=rows),ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
p=(root/'tools/publicar_marcador_estado.py').read_text(encoding='utf-8').replace("FILES=('public/javascript/telefonia_marcador.js',)",'FILES='+repr(tuple(x['path'] for x in rows))).replace('marcador_estado_release.json','telefono_correo_release.json').replace('marcador-estado-','telefono-correo-').replace('Actualiza sólo la interfaz del marcador; no toca SIP ni permisos.','Exige telefono y correo confirmados en la misma llamada para avanzar y contabilizar.').replace('Estado del marcador actualizado; llamadas activas y resultados pendientes conservan su bloqueo.','Telefono y correo obligatorios; avance y contador requieren evidencia confirmada en la misma llamada.')
(root/'tools/publicar_telefono_correo.py').write_text(p,encoding='utf-8')
print('Paquete selectivo: '+str(len(rows))+' archivos; no modifica registros historicos ni crea tablas al instalar.')
