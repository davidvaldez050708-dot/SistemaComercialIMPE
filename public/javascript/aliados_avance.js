document.addEventListener('DOMContentLoaded',()=>{
  'use strict';
  const root=document.querySelector('[data-aliados-module]'),modal=document.getElementById('modalAliadoAvance');
  if(!root||!modal||!window.bootstrap)return;
  const popup=bootstrap.Modal.getOrCreateInstance(modal),list=modal.querySelector('[data-avance-list]'),totals=modal.querySelector('[data-avance-totals]'),status=modal.querySelector('[data-avance-status]'),filter=modal.querySelector('[data-avance-only-pending]'),form=modal.querySelector('[data-avance-response-form]');
  let current=null,revision=0,saving=false;
  const el=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=String(text);return n;};
  const icon=name=>{const i=el('i','bi bi-'+name);i.setAttribute('aria-hidden','true');return i;};
  const date=v=>{if(!v)return '';const d=new Date(String(v).replace(' ','T'));return Number.isNaN(d.getTime())?'':d.toLocaleString('es-MX',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});};
  const endpoint=(action,tipo,id)=>{const u=new URL('index.php',root.dataset.baseUrl||location.href);u.searchParams.set('controller','aliado');u.searchParams.set('action',action);u.searchParams.set('origen_tipo',tipo);u.searchParams.set('origen_id',id);return u;};
  const request=async(url,options={})=>{const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});let d;try{d=await r.json();}catch(_){throw Error('No se pudo leer la respuesta. Recarga la página e intenta nuevamente.');}if(!r.ok||!d.ok)throw Error(d.mensaje||'No se pudo completar la operación.');return d;};
  function message(text,error=false){status.textContent=text;status.classList.toggle('is-error',error);}
  function updateSummary(node,a){
    node.querySelector('[data-avance-count]').textContent=a.compartidas+' de '+a.total+' compartidas';
    node.querySelector('[data-avance-pending]').textContent=a.total?(a.pendientes?a.pendientes+' pendientes de compartir':'Todas las convocatorias vigentes compartidas'):'Sin convocatorias vigentes para compartir';
    const p=node.querySelector('progress');p.max=Math.max(1,a.total);p.value=a.compartidas;
  }
  function syncRows(data){
    document.querySelectorAll('[data-avance-summary]').forEach(n=>{
      if(n.dataset.origen!==data.origen_tipo||Number(n.dataset.id)!==Number(data.origen_id))return;
      updateSummary(n,data.avance);
      const last=data.ultima_difusion,cell=n.closest('tr')?.querySelector('[data-avance-last]');
      if(last&&cell){const wrap=el('div','aliados-last-send');wrap.append(el('strong','',last.convocatoria_titulo||'Convocatoria'),el('span','',(last.canal==='CORREO'?'Correo':'WhatsApp confirmado manualmente')+' · '+date(last.enviado_at)));cell.replaceChildren(wrap);}
    });
  }
  function responseEditor(p){
    form.hidden=false;form.elements.convocatoria_id.value=p.id;form.elements.version.value=p.respuesta.version;
    form.elements.estado.replaceChildren(...Object.entries(current.estados_respuesta).map(([value,label])=>{const o=el('option','',label);o.value=value;return o;}));
    form.elements.estado.value=p.respuesta.estado;form.elements.nota.value=p.respuesta.nota||'';
    form.querySelector('[data-avance-response-title]').textContent='Respuesta · '+p.titulo;
    form.scrollIntoView({behavior:'smooth',block:'nearest'});form.elements.estado.focus();
  }
  function render(){
    if(!current)return;list.replaceChildren();totals.replaceChildren();
    const a=current.avance;
    for(const [key,label,symbol] of [['total','Vigentes para compartir','megaphone'],['compartidas','Compartidas','check2-circle'],['pendientes','Pendientes de compartir','hourglass-split']]){
      const card=el('div','aliados-avance-total');card.append(icon(symbol),el('strong','',a[key]),el('span','',label));totals.append(card);
    }
    const items=a.convocatorias.filter(p=>!filter.checked||!p.compartida);
    if(!items.length)list.append(el('div','aliados-avance-empty',a.total?'No hay convocatorias pendientes de compartir.':'No hay convocatorias publicadas, vigentes y con Registro habilitado para este aliado.'));
    for(const p of items){
      const card=el('article','aliados-avance-card'),head=el('div','aliados-avance-card-header'),title=el('div');
      title.append(el('small','',(p.programa_nombre||'Convocatoria')+' · #'+p.id),el('h3','',p.titulo));
      head.append(title,el('span','aliados-avance-badge'+(p.compartida?' is-shared':''),p.compartida?'Compartida':'Pendiente'));card.append(head);
      const channels=el('div','aliados-avance-channels');
      for(const [channel,label,symbol] of [['CORREO','Correo enviado','envelope-check'],['WHATSAPP_MANUAL','WhatsApp confirmado manualmente','whatsapp']]){
        const e=p.difusion_canales[channel],line=el('span');line.append(icon(symbol),document.createTextNode(e?label+' · '+date(e.fecha):(channel==='CORREO'?'Correo sin enviar':'WhatsApp sin confirmar')));channels.append(line);
      }card.append(channels);
      const response=el('div','aliados-avance-response'),copy=el('div','aliados-avance-response-copy');
      copy.append(el('small','','RESPUESTA DE LA INSTITUCIÓN'),el('strong','',p.compartida?p.respuesta.etiqueta:'Comparte la convocatoria antes de registrar una respuesta.'));
      if(p.respuesta.nota)copy.append(el('p','',p.respuesta.nota));
      if(p.respuesta.nota)copy.append(el('small','',(p.respuesta.responsable||'Responsable')+' · '+date(p.respuesta.fecha)));
      response.append(copy);
      if(p.puede_responder){const button=el('button','btn aliados-btn-primary aliados-respuesta-button');button.type='button';button.append(icon('chat-left-text'),el('span','',p.respuesta.nota?'Actualizar respuesta':'Registrar respuesta'));button.dataset.avanceResponder=p.id;button.addEventListener('click',()=>{message('');responseEditor(p);});response.append(button);}
      card.append(response);list.append(card);
    }
    if(!current.respuestas_disponibles&&a.compartidas)list.append(el('p','aliados-avance-empty','La consulta está disponible. El registro de respuestas todavía no está habilitado.'));
  }
  async function open(tipo,id){
    const rev=++revision;current=null;form.hidden=true;filter.checked=false;totals.replaceChildren();list.replaceChildren(el('p','aliados-avance-empty','Consultando avance…'));message('');modal.querySelector('[data-avance-context]').textContent='Consultando convocatorias…';popup.show();
    try{const data=await request(endpoint('avanceDifusion',tipo,id));if(rev!==revision)return;current=data;modal.querySelector('[data-avance-context]').textContent=data.aliado.nombre_entidad;syncRows(data);render();}
    catch(e){if(rev===revision){list.replaceChildren();message(e.message,true);}}
  }
  document.addEventListener('click',e=>{const b=e.target.closest('[data-avance-abrir]');if(b)open(b.dataset.origen,Number(b.dataset.id));});
  modal.addEventListener('hidden.bs.modal',()=>{revision++;current=null;form.hidden=true;});
  filter.addEventListener('change',render);form.querySelector('[data-avance-response-cancel]').addEventListener('click',()=>{form.hidden=true;});
  form.addEventListener('submit',async e=>{
    e.preventDefault();if(saving||!current||!form.reportValidity())return;
    const rev=revision,body=new FormData(form);body.set('csrf_token',window.IMPE_CSRF_TOKEN||document.querySelector('meta[name="csrf-token"]')?.content||'');body.set('origen_tipo',current.origen_tipo);body.set('origen_id',current.origen_id);
    saving=true;form.inert=true;message('Guardando respuesta…');
    try{const data=await request(endpoint('guardarRespuestaDifusion',current.origen_tipo,current.origen_id),{method:'POST',body});if(rev!==revision)return;current=data;form.hidden=true;render();syncRows(data);message('Respuesta guardada. El avance de difusión se conserva.');}
    catch(error){if(rev===revision)message(error.message,true);}
    finally{saving=false;form.inert=false;}
  });
  document.addEventListener('impe:difusion-actualizada',async e=>{try{syncRows(await request(endpoint('avanceDifusion',e.detail.origen,e.detail.id)));}catch(_){/* El próximo acceso recupera el avance persistido. */}});
  // En Compartir, la selección pendiente es visible antes de preparar el mensaje.
  const share=document.getElementById('modalAliadoCompartir'),surface=share?.querySelector('[data-aliado-envio-avance]'),select=share?.querySelector('[name="convocatoria_id"]');
  let shareItems=[],onlyPending=false;
  function renderShare(){
    if(!surface||!select)return;surface.replaceChildren();
    if(!shareItems.length)return;
    const sent=shareItems.filter(p=>p.compartida).length,heading=el('div','aliados-envio-heading');heading.append(el('strong','',sent+' de '+shareItems.length+' compartidas · '+(shareItems.length-sent)+' pendientes'));
    const label=el('label','aliados-avance-filter'),input=el('input');input.type='checkbox';input.checked=onlyPending;input.dataset.envioOnlyPending='';input.addEventListener('change',()=>{onlyPending=input.checked;renderShare();});label.append(input,document.createTextNode('Sólo pendientes'));heading.append(label);surface.append(heading,el('p','aliados-envio-help','Selecciona una publicación. Basta un correo enviado o un WhatsApp confirmado para contarla como compartida.'));
    const grid=el('div','aliados-envio-list');
    for(const p of shareItems.filter(p=>!onlyPending||!p.compartida)){
      const b=el('button','aliados-envio-item'+(Number(select.value)===Number(p.id)?' is-selected':''));b.type='button';b.dataset.envioConvocatoria=p.id;
      b.append(el('span','',p.titulo),el('span','aliados-avance-badge'+(p.compartida?' is-shared':''),p.compartida?'Compartida':'Pendiente'));
      const labels=[];for(const [c,l] of [['CORREO','Correo enviado'],['WHATSAPP_MANUAL','WhatsApp confirmado']])if(p.difusion_canales?.[c])labels.push(l+' · '+date(p.difusion_canales[c].fecha));
      b.append(el('small','',(p.programa_nombre||'')+(labels.length?' · '+labels.join(' · '):' · Sin difusión registrada')));
      b.addEventListener('click',()=>{select.value=String(p.id);select.dispatchEvent(new Event('change',{bubbles:true}));renderShare();});grid.append(b);
    }
    if(!grid.children.length)grid.append(el('p','aliados-avance-empty','Todas las convocatorias vigentes están compartidas.'));surface.append(grid);
  }
  document.addEventListener('impe:share-progress',e=>{shareItems=e.detail.items||[];onlyPending=false;renderShare();});
  select?.addEventListener('change',renderShare);
});
