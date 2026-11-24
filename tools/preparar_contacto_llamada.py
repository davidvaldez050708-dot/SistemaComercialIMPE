"""Aplica bloques sobre lecturas instaladas; conserva las ampliaciones de producción."""
import base64, difflib, hashlib, json
from pathlib import Path

root=Path(__file__).resolve().parents[1]
base=root/'tmp/contacto-llamada-live'
rows=[]
def edit(name, changes):
    old=(base/name).read_text(encoding='utf-8');new=old
    local=(root/name).read_text(encoding='utf-8')
    for before,after in changes:
        if new.count(before)!=1: raise RuntimeError('Contexto distinto: '+name+' '+before[:90])
        new=new.replace(before,after,1)
        if local.count(after)==1: continue
        if local.count(before)==1: local=local.replace(before,after,1)
        else: print('Sólo paquete instalado: '+name+' '+before[:45])
    (root/name).write_text(local,encoding='utf-8')
    out=root/'tmp/contacto-llamada-stage'/name;out.parent.mkdir(parents=True,exist_ok=True);out.write_text(new,encoding='utf-8')
    a,b=old.splitlines(True),new.splitlines(True);hunks=[]
    for g in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(2):
        hunks.append(dict(before=''.join(a[g[0][1]:g[-1][2]]),after=''.join(b[g[0][3]:g[-1][4]])))
    rows.append(dict(path=name,new_file=False,hunks=hunks))

name='app/services/DatosContactoLlamadaService.php';data=(root/name).read_bytes().replace(b'\r\n',b'\n')
rows.append(dict(path=name,new_file=True,content=base64.b64encode(data).decode(),after_sha=hashlib.sha256(data).hexdigest()))

anchor="        $personaAtendio = VerificacionInstitucionalService::limpiarObservacion((string)($_POST['persona_atendio'] ?? ''));"
edit('app/controllers/SeguimientoVinculacionController.php',[
 (anchor,"""        require_once __DIR__.'/../services/DatosContactoLlamadaService.php';
        try { $contactoLlamada=DatosContactoLlamadaService::extraer($_POST,$canalFormulario,$resultadoFormulario); }
        catch (InvalidArgumentException $e) { $this->responderJson(['ok'=>false,'mensaje'=>$e->getMessage()],422); }
"""+anchor),
 ("        $datosInteraccion = [", "        $notas=trim($notas.\"\\n\".DatosContactoLlamadaService::nota($contactoLlamada));\n        $datosInteraccion = [\n            'contacto_llamada'=>$contactoLlamada,")])

edit('app/models/SeguimientoVinculacionModel.php',[("            $etapaPersistencia = 'actualizar el seguimiento';", """            if (!empty($datos['contacto_llamada'])) {
                require_once __DIR__.'/../services/DatosContactoLlamadaService.php';
                DatosContactoLlamadaService::guardar($this->connection,(int)$seguimientoId,(int)$usuarioId,$datos['contacto_llamada']);
            }
            $etapaPersistencia = 'actualizar el seguimiento';""")])

anchor="        $personaAtendio = VerificacionInstitucionalService::limpiarObservacion((string)($datos['persona_atendio'] ?? ''));"
edit('app/services/InteraccionRutaService.php',[
 (anchor,"""        require_once __DIR__.'/DatosContactoLlamadaService.php';
        try { $contactoLlamada=DatosContactoLlamadaService::extraer($datos,$canalFormulario,$resultadoFormulario); }
        catch (InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
"""+anchor),
 ("        if ($notas === '') {", "        $notas=trim($notas.\"\\n\".DatosContactoLlamadaService::nota($contactoLlamada));\n        if ($notas === '') {"),
 ("            $this->connection->commit();", "            DatosContactoLlamadaService::guardar($this->connection,$seguimientoId,$usuarioId,$contactoLlamada);\n            $this->connection->commit();")])

edit('app/services/TelefoniaEntrantesAnalistaService.php',[
 ("        $decision=strtolower(trim((string)($datos['decision']??'')));", """        require_once __DIR__.'/DatosContactoLlamadaService.php';
        $contactoLlamada=DatosContactoLlamadaService::extraer($datos,'LLAMADA',strtoupper(trim((string)($datos['resultado']??''))));
        $decision=strtolower(trim((string)($datos['decision']??'')));"""),
 ("            $interaccionId = $model->registrarInteraccionManual(", "            $notas=trim($notas.\"\\n\".DatosContactoLlamadaService::nota($contactoLlamada));\n            $interaccionId = $model->registrarInteraccionManual("),
 ("                    'preservar_estado'=>true,", "                    'preservar_estado'=>true,\n                    'contacto_llamada'=>$contactoLlamada,")])

edit('public/javascript/telefonia_atencion.js',[
 ("        refs,localSave,", """        refs,localSave,
        actualizarFicha(datos) {
            const form=document.querySelector('[data-work-interaction-form]');
            if(!form || Number(form.elements.namedItem('seguimiento_id')?.value)!==Number(datos.get('seguimiento_id')))return;
            if(datos.get('actualizar_datos_llamada')!=='1' || !['CONTACTO_CORRECTO','CONTACTO_REFERIDO','SOLICITO_INFORMACION','SOLICITO_LLAMAR_DESPUES','NO_INTERESADO'].includes(datos.get('resultado')))return;
            const campos=[['nombre_entidad_actualizado','data-work-title',null],['nuevo_telefono_contacto','data-work-phone','telefono_verificado'],['nuevo_correo_contacto','data-work-email','correo_verificado'],['nuevo_contacto_nombre','data-work-contact-name','contacto_nombre'],['nuevo_contacto_cargo','data-work-contact-role','contacto_cargo']];
            for(const [nombre,selector,inputName] of campos) {
                const valor=String(datos.get(nombre)||'').trim();if(!valor)continue;
                const label=document.querySelector('['+selector+']');if(label)label.textContent=valor;
                const input=inputName && document.querySelector('[data-work-contact-form] [name="'+inputName+'"]');if(input)input.value=valor;
            }
            const correo=String(datos.get('nuevo_correo_contacto')||'').trim(),boton=document.querySelector('[data-work-email-button]');
            if(correo && boton){boton.disabled=false;boton.title='Enviar correo a '+correo;}
        },"""),
 ("            return out;\n        },\n        captureValues", """            for(const [source,target] of [['telefono_capturado','nuevo_telefono_contacto'],['correo_capturado','nuevo_correo_contacto'],['nombre_entidad','nombre_entidad_actualizado']]) {
                if(!(target in out) && out[source])out[target]=out[source];
            }
            return out;
        },
        captureValues"""),
 ("            return out;\n        },\n        async read", """            for(const [source,target] of [['nuevo_telefono_contacto','telefono'],['nuevo_correo_contacto','correo'],['nombre_entidad_actualizado','nombre']]) {
                if(source in values)out[target]=values[source];
            }
            return out;
        },
        async read"""),
 ("        window.IMPE_TELEPHONY_NOTIFY?.('Datos de la llamada recuperados. Revisa el resultado y confirma los datos verificados.');", """        form.querySelector('[name="resultado"]')?.dispatchEvent(new Event('change',{bubbles:true}));
        window.IMPE_TELEPHONY_NOTIFY?.('Borrador recuperado. Revisa los datos de contacto: se guardarán en la ficha al registrar el resultado.');""")])

edit('public/javascript/seguimiento_entrantes_analista.js',[
 ("        referred.hidden=!enabled || result!=='CONTACTO_REFERIDO';", "        referred.hidden=!allowEvidence;"),
 (".some(e=>e.value.trim())) {showError('Captura algún dato de la persona o área referida.');", ".some(e=>e.type!=='hidden' && e.name!=='nombre_entidad_actualizado' && e.value.trim())) {showError('Captura algún dato de la persona o área referida.');"),
 ("Borrador recuperado. Revisa los datos antes de guardar.", "Borrador recuperado. Revisa el contacto: al guardar se actualizará la ficha del expediente seleccionado.")])

edit('app/views/telefonia/resultado_analista_form.php',[
 ("                        <strong>Contacto proporcionado por la institución</strong>", """                        <input type="hidden" name="actualizar_datos_llamada" value="1">
                        <strong>Datos de contacto para el expediente</strong>"""),
 ("Estos datos se incorporarán al expediente. La persona referida puede ser distinta de quien atendió.", "Revisa la captura: al guardar el resultado se actualizará la ficha del expediente seleccionado. Los campos vacíos conservan sus datos actuales. La persona que atendió puede ser distinta del contacto institucional.")
 ])
# Añadir el nombre institucional sin duplicar el campo de alta de expediente.
edit_index=len(rows)-1
# La segunda transformación se integra en el mismo archivo/paquete abajo.
name='app/views/telefonia/resultado_analista_form.php'
stage=root/'tmp/contacto-llamada-stage'/name
old=(base/name).read_text(encoding='utf-8');new=stage.read_text(encoding='utf-8')
anchor='                        <strong>Datos de contacto para el expediente</strong>'
after=anchor+'\n                        <div class="mt-2"><label class="form-label" for="incomingInstitutionUpdate">Nombre institucional actualizado (opcional)</label><input id="incomingInstitutionUpdate" name="nombre_entidad_actualizado" maxlength="180" class="form-control"></div>'
new=new.replace(anchor,after,1);stage.write_text(new,encoding='utf-8')
local=(root/name).read_text(encoding='utf-8')
if after not in local: (root/name).write_text(local.replace(anchor,after,1),encoding='utf-8')
a,b=old.splitlines(True),new.splitlines(True)
rows[edit_index]['hunks']=[dict(before=''.join(a[g[0][1]:g[-1][2]]),after=''.join(b[g[0][3]:g[-1][4]])) for g in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(2)]

edit('public/javascript/seguimiento_interacciones.js',[
 ("                agregarActividadInformativa(datos.interaccion);", "                window.IMPE_CALL_DRAFTS?.actualizarFicha(formData);\n                agregarActividadInformativa(datos.interaccion);"),
 ("<strong>Nuevo contacto proporcionado</strong>", '<strong>Datos de contacto para el expediente</strong><input type="hidden" name="actualizar_datos_llamada" value="1">'),
 ("El teléfono original se conservará como teléfono fuente.", 'Al guardar el resultado se actualizará la ficha. Los campos vacíos conservan los datos actuales.'),
 ("                '<div class=\"row g-2\">' +", "                '<div class=\"row g-2\"><div class=\"col-12\"><label class=\"form-label\" for=\"work_institution_update\">Nombre institucional actualizado (opcional)</label><input id=\"work_institution_update\" name=\"nombre_entidad_actualizado\" maxlength=\"180\" class=\"form-control\"></div>' +"),
 ("            const visible = resultado === 'CONTACTO_REFERIDO' && canal === 'LLAMADA';", "            const visible = ['CONTACTO_CORRECTO','CONTACTO_REFERIDO','SOLICITO_INFORMACION','SOLICITO_LLAMAR_DESPUES','NO_INTERESADO'].includes(resultado) && canal === 'LLAMADA';"),
 ("""            if (!visible) {
                [campoTelefonoReferido, campoCorreoReferido, campoNombreReferido, campoCargoReferido]
                    .forEach(function (campo) {
                        if (campo) {
                            campo.value = '';
                        }
                    });
            }""", "            contenedorContactoReferido.querySelectorAll('input').forEach(input=>input.disabled=!visible);")])

edit('app/services/TelefoniaBorradorService.php',[("'tipo_entidad'=>30,'resultado'=>40", "'nombre_entidad_actualizado'=>180,'actualizar_datos_llamada'=>1,\n            'tipo_entidad'=>30,'resultado'=>40")])
(root/'tools/contacto_llamada_release.json').write_text(json.dumps(dict(release='contacto-llamada-analista-v1',files=rows),ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
p=(root/'tools/publicar_marcador_estado.py').read_text(encoding='utf-8')
p=p.replace("FILES=('public/javascript/telefonia_marcador.js',)",'FILES='+repr(tuple(r['path'] for r in rows)))
p=p.replace('marcador_estado_release.json','contacto_llamada_release.json').replace('marcador-estado-','contacto-llamada-')
p=p.replace('Actualiza sólo la interfaz del marcador; no toca SIP ni permisos.','Integra el contacto revisado al expediente al guardar el resultado del analista.')
p=p.replace('Estado del marcador actualizado; llamadas activas y resultados pendientes conservan su bloqueo.','Datos de llamada integrados al expediente; guardado transaccional y permisos conservados.')
(root/'tools/publicar_contacto_llamada.py').write_text(p,encoding='utf-8')
print('Paquete selectivo: '+str(len(rows))+' archivos.')
