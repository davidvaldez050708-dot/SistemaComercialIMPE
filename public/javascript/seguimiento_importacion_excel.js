/* Seguimiento Excel V3: consulta antes de guardar; sin efectos sobre otros módulos. */
(function(){
  'use strict';
  const modal=document.getElementById('modalImportacionSeguimientoExcel');
  if(!modal)return;
  const form=modal.querySelector('[data-impe-excel-form]');
  const analyze=modal.querySelector('[data-impe-excel-analyze]');
  const confirm=modal.querySelector('[data-impe-excel-confirm]');
  const status=modal.querySelector('[data-impe-excel-message]');
  const summary=modal.querySelector('[data-impe-excel-summary]');
  const rows=modal.querySelector('[data-impe-excel-rows]');
  const preview=modal.querySelector('[data-impe-excel-preview]');
  const stateSelect=form.querySelector('[name="estado_id"]');
  const analystSelect=form.querySelector('[data-impe-excel-analista]');
  const fileInput=form.querySelector('[name="archivo"]');
  const download=modal.querySelector('[data-impe-excel-download]');
  const url=new URL(location.href);
  url.search='';
  url.hash='';
  url.searchParams.set('controller','seguimientoVinculacion');
  url.searchParams.set('action','importarInstitucionesExcel');
  let token='',busy=false,loadingAnalysts=0;
  const show=(message,error=false)=>{
    status.hidden=false;
    status.textContent=message;
    status.dataset.error=error?'1':'0';
  };
  const hideReview=()=>{
    token='';
    preview.hidden=true;
    confirm.hidden=true;
    confirm.disabled=true;
    rows.replaceChildren();
    summary.replaceChildren();
  };
  const setBusy=value=>{
    busy=value;
    analyze.disabled=value;
    confirm.disabled=value||!token;
    fileInput.disabled=value;
    stateSelect.disabled=value;
    if(analystSelect)analystSelect.disabled=value;
  };
  async function request(where,options={}) {
    const response=await fetch(where,{credentials:'same-origin',cache:'no-store',...options});
    let json;
    try{json=await response.json();}catch(_){
      throw Error('No fue posible interpretar la respuesta. Verifica tu sesión.');
    }
    if(!response.ok||!json.ok)throw Error(json.mensaje||'No se pudo completar la operación.');
    return json;
  }
  async function loadAnalysts(){
    if(!analystSelect)return;
    const generation=++loadingAnalysts;
    analystSelect.replaceChildren(new Option('Consultando analistas…',''));
    analystSelect.disabled=true;
    if(!stateSelect.value){
      analystSelect.replaceChildren(new Option('Selecciona primero un estado',''));
      analystSelect.disabled=false;
      return;
    }
    const api=new URL(url.href);
    api.searchParams.set('action','analistasImportacionExcel');
    api.searchParams.set('estado_id',stateSelect.value);
    try{
      const response=await request(api);
      if(generation!==loadingAnalysts)return;
      analystSelect.replaceChildren(new Option('Seleccionar analista',''));
      (response.analistas||[]).forEach(item=>{
        analystSelect.add(new Option(String(item.nombre||'Analista'),String(item.id)));
      });
      if((response.analistas||[]).length===0)show('No hay analistas autorizados en este territorio.',true);
    }catch(error){
      if(generation===loadingAnalysts){
        analystSelect.replaceChildren(new Option('No fue posible cargar analistas',''));
        show(error.message,true);
      }
    }finally{
      if(generation===loadingAnalysts)analystSelect.disabled=busy;
    }
  }
  for(const element of [stateSelect,fileInput,analystSelect]){
    if(!element)continue;
    element.addEventListener('change',()=>{
      hideReview();
      status.hidden=true;
      if(element===stateSelect)loadAnalysts();
    });
  }
  const addCell=(parent,label)=>{
    const td=document.createElement('td');td.textContent=String(label??'');parent.append(td);
  };
  const addMetric=(label,value)=>{
    const card=document.createElement('div');card.className='impe-excel-metric';
    const strong=document.createElement('strong');strong.textContent=String(value);
    const span=document.createElement('span');span.textContent=label;
    card.append(strong,span);summary.append(card);
  };
  download?.addEventListener('click',async()=>{
    if(busy)return;
    download.disabled=true;
    show('Preparando plantilla Excel V3…');
    try{
      const response=await fetch(download.dataset.url,{credentials:'same-origin',cache:'no-store'});
      const type=response.headers.get('content-type')||'';
      if(!response.ok||!type.includes('spreadsheetml.sheet')){
        throw Error('No fue posible obtener la plantilla. Verifica que la extensión ZIP de PHP esté habilitada.');
      }
      const blob=await response.blob();
      if(!blob.size||blob.size>2097152)throw Error('La plantilla no tiene un tamaño válido.');
      const local=URL.createObjectURL(blob);
      const a=document.createElement('a');
      a.href=local;
      a.download='plantilla_instituciones_seguimiento_impe_v3.xlsx';
      document.body.append(a);a.click();a.remove();
      setTimeout(()=>URL.revokeObjectURL(local),4000);
      show('Plantilla Excel V3 preparada para descargar.');
    }catch(error){show(error.message,true);}
    finally{download.disabled=false;}
  });
  analyze.addEventListener('click',async()=>{
    if(busy)return;
    hideReview();
    if(!stateSelect.value){show('Selecciona el territorio de la plantilla.',true);return;}
    if(analystSelect&&!analystSelect.value){show('Selecciona un analista responsable.',true);return;}
    if(!fileInput.files?.length){show('Selecciona el archivo Excel V3.',true);return;}
    if(fileInput.files[0].size>2097152){show('El archivo no debe superar 2 MB.',true);return;}
    setBusy(true);show('Revisando archivo, instituciones y posibles duplicados…');
    try{
      const data=new FormData(form);data.set('operacion','analizar');
      const answer=await request(url,{method:'POST',body:data});
      token=answer.token||'';
      const counts=answer.resumen||{};
      addMetric('Nuevas',counts.nuevas||0);
      addMetric('Ya existentes',counts.existentes||0);
      addMetric('Para revisar',counts.revisar||0);
      addMetric('Inválidas',counts.invalidas||0);
      (answer.filas||[]).forEach(item=>{
        const tr=document.createElement('tr');
        tr.dataset.estado=item.estado;
        addCell(tr,item.fila);
        addCell(tr,item.institucion);
        const st=document.createElement('td');
        const badge=document.createElement('span');
        badge.className='impe-excel-badge';
        badge.textContent=({
          nueva:'Nueva',existente:'Existente',revisar:'Revisar',invalida:'Inválida'
        })[item.estado]||'—';
        st.append(badge);tr.append(st);
        addCell(tr,item.mensaje);
        rows.append(tr);
      });
      preview.hidden=false;
      confirm.hidden=!(counts.nuevas>0);
      confirm.disabled=!(counts.nuevas>0);
      show(counts.nuevas>0
        ?'Revisión completada. Confirma para importar únicamente las instituciones nuevas.'
        :'Revisión completada. No hay instituciones nuevas para importar.');
    }catch(error){hideReview();show(error.message,true);}
    finally{setBusy(false);}
  });
  confirm.addEventListener('click',async()=>{
    if(busy||!token)return;
    setBusy(true);show('Guardando únicamente las instituciones nuevas…');
    try{
      const data=new FormData();
      data.set('csrf_token',form.querySelector('[name="csrf_token"]').value);
      data.set('operacion','confirmar');data.set('token',token);
      const answer=await request(url,{method:'POST',body:data});
      hideReview();
      const saved=answer.resultado?.insertadas??0;
      const skipped=answer.resultado?.omitidas_adicionales??0;
      show('Importación finalizada: '+saved+' instituciones nuevas registradas.'+
           (skipped?' '+skipped+' coincidencias adicionales omitidas.':'')+
           ' Actualiza los estados para ver los nuevos seguimientos.');
      analyze.disabled=true;
      fileInput.value='';
    }catch(error){show(error.message,true);}
    finally{setBusy(false);}
  });
  modal.addEventListener('hidden.bs.modal',()=>{
    if(busy)return;
    hideReview();form.reset();status.hidden=true;
    if(analystSelect)analystSelect.replaceChildren(new Option('Selecciona primero un estado',''));
    analyze.disabled=false;
  });
})();
