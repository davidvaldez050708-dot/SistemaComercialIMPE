from pathlib import Path
import json
import hashlib
import subprocess

HERE=Path(__file__).resolve().parent
controller='app/controllers/ConvocatoriaNotificacionController.php'
script='public/javascript/convocatoria_notificaciones.js'
replacements={controller:[],script:[]}
def change(name,before,after): replacements[name].append({'before':before,'after':after})

anchor="            echo json_encode([\n                'ok' => true,\n                // La insignia"
change(controller,anchor,"            require_once __DIR__.'/../services/AgendaMarketingNotificationService.php';\n            $agendaMarketing=(new AgendaMarketingNotificationService())->obtener($usuarioId);\n\n"+anchor)
change(controller,"'no_leidas' => count($tareas)+$modelo->contarNoLeidas($usuarioId)+count(array_filter($phone,", "'no_leidas' => count($agendaMarketing['recordatorios'])+count($tareas)+$modelo->contarNoLeidas($usuarioId)+count(array_filter($phone,")
change(controller,"                'notificaciones' => array_merge($tareas,$phone,$modelo->obtenerPorUsuario($usuarioId, 20))", "                'notificaciones' => array_merge($tareas,$phone,$agendaMarketing['notificaciones'],$modelo->obtenerPorUsuario($usuarioId, 20)),\n                'agenda_ok' => $agendaMarketing['agenda_ok'],\n                'recordatorios' => $agendaMarketing['recordatorios'],\n                'avisos' => $agendaMarketing['avisos'],\n                'horarios' => $agendaMarketing['horarios'],\n                'usuario_id' => $usuarioId,\n                'servidor_at' => time(),\n                'zona_horaria' => date_default_timezone_get()")
change(script,"currentItems=Array.isArray(items)?items:[];if(toastBusy)return;", "currentItems=Array.isArray(items)?items:[];if(toastBusy||document.querySelector('[data-agenda-marketing-toast]'))return;")
change(script,"if(toastBusy||document.hidden)return false;", "if(toastBusy||document.hidden||document.querySelector('[data-agenda-marketing-toast]'))return false;")
change(script,"            notifyUnseen(data.notificaciones || []);", "            notifyUnseen(data.notificaciones || []);\n            window.dispatchEvent(new CustomEvent('impe:marketing-agenda-data',{detail:data}));")
change(script,"        } catch (error) {\n            content.innerHTML =", "        } catch (error) {\n            window.dispatchEvent(new CustomEvent('impe:marketing-agenda-data',{detail:{agenda_ok:false}}));\n            content.innerHTML =")
anchor="    window.addEventListener('impe:phone-notices-refresh',()=>void load());"
change(script,anchor,anchor+"\n    window.addEventListener('impe:agenda-comments-refresh',()=>void load({fresh:true}));\n    window.addEventListener('impe:marketing-agenda-toast-closed',()=>notifyUnseen(currentItems));")
helper='app/services/AgendaMarketingNotificationService.php'
previous=subprocess.check_output(['git','show','b3643b4c:tools/agenda_marketing_20261124/agenda_marketing.js'],cwd=HERE).decode('utf-8').replace('\r\n','\n')
data={'version':'marketing-agenda-v2','patches':replacements,
      'append':{'path':script,'marker':'/* IMPE_AGENDA_MARKETING_V1 */','previous':[previous],'text':(HERE/'agenda_marketing.js').read_text(encoding='utf-8')},
      'new_files':{helper:{'sha256':hashlib.sha256((HERE/'files'/helper).read_text(encoding='utf-8').encode()).hexdigest()}}}
(HERE/'cambios.json').write_text(json.dumps(data,ensure_ascii=False,indent=2),encoding='utf-8',newline='\n')
print('Cambios acotados: campana de Marketing, conexión de Agenda y servicio adaptador.')
