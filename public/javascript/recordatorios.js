(function () {
    'use strict';

    const iniciar = function () {
        const root = document.querySelector('[data-reminder-root]');

        if (!root || root.dataset.reminderInitialized === '1') {
            return;
        }

        const endpoint = root.getAttribute('data-reminder-endpoint') || '';
        const badge = root.querySelector('[data-reminder-badge]');
        const contenido = root.querySelector('[data-reminder-content]');
        const usarTotalBackend =
            root.getAttribute('data-reminder-count-mode') === 'total';
        const tituloVacio =
            root.getAttribute('data-reminder-empty-title') ||
            'Sin notificaciones pendientes';
        const mensajeVacio =
            root.getAttribute('data-reminder-empty-message') ||
            'No tienes acciones o reuniones pendientes.';
        let consultaEnCurso = false;
        let versionComercial = 0;
        let refrescoComercialPendiente = false;
        let repetirConsulta = false;
        let avisoMigracionMostrado = false;
        let recordatoriosActuales = [];
        let cargaInicialCompletada = false;
        let consultasCompletadas = 0;
        let fallosDeCarga = 0;
        let temporizadorReintentoCarga = null;
        let temporizadorToastVencido = null;
        let proximoToastAt = 0;
        let toastActivo = null;
        const memoriaSesion = new Map();
        let almacenamientoPersistente = true;

        const INTERVALO_TOAST_VENCIDO = 8 * 60 * 1000;
        const REINTENTO_TOAST_OCULTO = 60 * 1000;
        const DURACION_TOAST = 7000;
        const claveUsuario = ':usuario:' + Number(window.IMPE_CURRENT_USER_ID || 0);
        const CLAVE_ULTIMO_TOAST_VENCIDO_AT =
            'recordatorios:ultimo-toast-vencido-at' + claveUsuario;
        const CLAVE_ULTIMO_TOAST_VENCIDO_ID =
            'recordatorios:ultimo-toast-vencido-id' + claveUsuario;
        // Un turno de ocho minutos por usuario, tanto para Seguimiento como para Telefonía.
        const CLAVE_ULTIMO_TOAST_COMPARTIDO_AT = 'recordatorios:ultimo-toast-global-at' + claveUsuario;
        const CLAVE_INICIO_CADENCIA = 'recordatorios:inicio-toast-global-at' + claveUsuario;

        if (endpoint === '') {
            return;
        }
        root.dataset.reminderInitialized = '1';

        const escapar = function (valor) {
            const div = document.createElement('div');
            div.textContent = String(valor || '');
            return div.innerHTML;
        };

        const urlRecordatorio = function (recordatorio) {
            const personalizada = String(recordatorio.url || '').trim();
            if (personalizada !== '') {
                return personalizada;
            }

            return 'index.php?controller=seguimientoVinculacion&action=detalle&id=' +
                Number(recordatorio.id || recordatorio.seguimiento_id || 0);
        };

        const dosDigitos = function (valor) {
            return String(valor).padStart(2, '0');
        };

        const leerSesion = function (clave) {
            try {
                const valor = window.sessionStorage.getItem(clave);
                if (valor !== null) return valor;
            } catch (_) {
                almacenamientoPersistente = false;
            }
            return memoriaSesion.get(clave) || '';
        };

        const guardarSesion = function (clave, valor) {
            memoriaSesion.set(clave, String(valor));
            try {
                window.sessionStorage.setItem(clave, String(valor));
            } catch (_) {
                almacenamientoPersistente = false;
            }
        };

        const fechaSesion = function (clave) {
            const valor = Number(leerSesion(clave));
            // Una clave vieja, corrupta o del futuro no debe detener el reloj.
            return Number.isFinite(valor) && valor > 0 && valor <= Date.now()
                ? valor : 0;
        };
        if (!fechaSesion(CLAVE_INICIO_CADENCIA)) {
            guardarSesion(CLAVE_INICIO_CADENCIA, Date.now());
        }
        const CLAVE_COLA_AVISOS = 'recordatorios:cola-toast-v1' + claveUsuario;
        let colaAvisos = [];
        try {
            const guardados = JSON.parse(leerSesion(CLAVE_COLA_AVISOS) || '[]');
            if (Array.isArray(guardados)) colaAvisos = guardados.filter(item =>
                item && typeof item.clave === 'string' && item.aviso &&
                Number(item.recibido) > Date.now() - 86400000 &&
                Number(item.recibido) <= Date.now()
            ).slice(0, 30);
        } catch (_) { /* Una cola antigua inválida no impide cargar la campana. */ }

        const esComercial = r => Number(r.comercial_id || 0) > 0;
        const claveComercial = r => [Number(r.comercial_id), Number(r.actividad_ciclo), String(r.fecha || '')].join('|');
        const CLAVE_COMERCIAL = 'recordatorios:comercial:v1' + claveUsuario;
        const CLAVE_TURNO_COMERCIAL = 'recordatorios:comercial:ultimo-turno' + claveUsuario;
        let memoriaComercial={};
        let turnoComercialMemoria=0;
        const leerComercial = () => {
            try { const data=JSON.parse(localStorage.getItem(CLAVE_COMERCIAL) || '{}');return data&&typeof data==='object'&&!Array.isArray(data)?data:{}; }
            catch (_) { return memoriaComercial; }
        };
        const ultimoComercial = () => {
            try { const t=Number(localStorage.getItem(CLAVE_TURNO_COMERCIAL));return t>0&&t<=Date.now()?t:0; }
            catch (_) { return turnoComercialMemoria; }
        };
        const comercialVisto = r => {
            const t=Number(leerComercial()[claveComercial(r)+'|'+r.ventana] || 0);
            return t>0 && t<=Date.now() && (r.ventana!=='VENCIDA' || Date.now()-t<INTERVALO_TOAST_VENCIDO);
        };
        const guardarComercial = r => {
            const vistos=leerComercial();vistos[claveComercial(r)+'|'+r.ventana]=Date.now();
            memoriaComercial=vistos;turnoComercialMemoria=Date.now();
            try {
                const claves=Object.keys(vistos).sort((a,b)=>Number(vistos[b])-Number(vistos[a]));
                localStorage.setItem(CLAVE_COMERCIAL,JSON.stringify(Object.fromEntries(claves.slice(0,1000).map(k=>[k,vistos[k]]))));
                localStorage.setItem(CLAVE_TURNO_COMERCIAL,String(Date.now()));
            } catch (_) {}
        };
        const bloqueoComercial = async fn => {
            if (navigator.locks?.request) {
                try { return await navigator.locks.request('impe-comercial-avisos'+claveUsuario,{ifAvailable:true},lock=>lock?fn():false); }
                catch (_) { /* Usar compatibilidad si el navegador bloquea Web Locks. */ }
            }
            // Compatibilidad sin Web Locks: una concesión breve compartida entre pestañas.
            const key='recordatorios:comercial:bloqueo'+claveUsuario;
            const token=String(Date.now())+':'+Math.random();
            let compartido=false;
            try {
                const previo=JSON.parse(localStorage.getItem(key)||'null');
                if(previo && previo.hasta>Date.now())return false;
                localStorage.setItem(key,JSON.stringify({token,hasta:Date.now()+2000}));compartido=true;
                await new Promise(resolve=>setTimeout(resolve,60));
                if(JSON.parse(localStorage.getItem(key)||'null')?.token!==token)return false;
            } catch (_) {}
            try{return fn();}
            finally{if(compartido)try{if(JSON.parse(localStorage.getItem(key)||'null')?.token===token)localStorage.removeItem(key);}catch(_){}}
        };

        const claveRecordatorio = function (recordatorio) {
            if (esComercial(recordatorio)) return 'comercial:'+claveComercial(recordatorio);
            const telefonoId = Number(recordatorio.telefonia_id || 0);
            if (telefonoId > 0) return 'telefonia:' + telefonoId;
            const reunionId = Number(recordatorio.reunion_id || 0);
            const seguimientoConvocatoriaId = Number(
                recordatorio.seguimiento_convocatoria_id || 0
            );
            const seguimientoId = Number(
                recordatorio.seguimiento_id || recordatorio.id || 0
            );
            const accion = String(recordatorio.accion || '').trim();
            const fecha = String(recordatorio.fecha || '').trim();
            let referencia = 'seguimiento:' + seguimientoId;

            if (seguimientoConvocatoriaId > 0) {
                referencia =
                    'aliado-seguimiento:' + seguimientoConvocatoriaId;
            } else if (reunionId > 0) {
                referencia = 'reunion:' + reunionId;
            }

            return [
                referencia,
                accion,
                fecha
            ].join('|');
        };

        const registrarToastVencido = function (recordatorio) {
            guardarSesion(CLAVE_ULTIMO_TOAST_COMPARTIDO_AT, Date.now());
            guardarSesion(CLAVE_ULTIMO_TOAST_VENCIDO_AT, Date.now());
            guardarSesion(
                CLAVE_ULTIMO_TOAST_VENCIDO_ID,
                claveRecordatorio(recordatorio)
            );
        };

        const fechaRecordatorio = function (recordatorio) {
            const fechaTexto = String(recordatorio.fecha || '').trim();
            if (fechaTexto === '') {
                return null;
            }

            const momento = new Date(fechaTexto.replace(' ', 'T'));
            return Number.isNaN(momento.getTime()) ? null : momento;
        };

        const estadoVisibleRecordatorio = function (recordatorio) {
            const estado = String(recordatorio.estado || 'normal').trim().toLowerCase();
            if(esComercial(recordatorio))return estado;
            const momento = fechaRecordatorio(recordatorio);

            // Algunos avisos de agenda representan una acción que requiere atención
            // (por ejemplo, reprogramar una reunión) y el backend puede marcarlos
            // como "vencida" para darles prioridad. Si la fecha de la reunión sigue
            // siendo futura, no debe mostrarse visualmente como una reunión vencida.
            if (estado === 'vencida' && momento && momento.getTime() > Date.now()) {
                return 'proxima';
            }

            return estado || 'normal';
        };

        const etiquetaVisibleRecordatorio = function (recordatorio) {
            const etiquetaOriginal = String(recordatorio.etiqueta || '').trim();
            if(esComercial(recordatorio))return etiquetaOriginal;
            const estado = String(recordatorio.estado || '').trim().toLowerCase();
            const momento = fechaRecordatorio(recordatorio);

            if (estado !== 'vencida' || !momento) {
                return etiquetaOriginal;
            }

            // Si la fecha todavía no ocurre, conservamos la etiqueta operativa
            // (por ejemplo, "Requiere ajuste") en lugar de mostrar "Vencida".
            if (momento.getTime() > Date.now()) {
                return etiquetaOriginal;
            }

            const ahora = new Date();
            const hoy = new Date(
                ahora.getFullYear(),
                ahora.getMonth(),
                ahora.getDate()
            );
            const diaEvento = new Date(
                momento.getFullYear(),
                momento.getMonth(),
                momento.getDate()
            );
            const diferenciaDias = Math.round(
                (diaEvento.getTime() - hoy.getTime()) / 86400000
            );
            const hora = dosDigitos(momento.getHours()) + ':' +
                dosDigitos(momento.getMinutes());
            const diaMes = dosDigitos(momento.getDate()) + '/' +
                dosDigitos(momento.getMonth() + 1);

            if (diferenciaDias === 0) {
                return 'Vencida hoy · ' + hora;
            }

            if (diferenciaDias === -1) {
                return 'Ayer · ' + diaMes + ' · ' + hora;
            }

            if (momento.getFullYear() === ahora.getFullYear()) {
                return 'Vencida · ' + diaMes + ' · ' + hora;
            }

            return 'Vencida · ' + diaMes + '/' + momento.getFullYear() +
                ' · ' + hora;
        };

        const asegurarContenedorToasts = function () {
            let contenedor = document.querySelector('[data-reminder-toast-container]');

            if (contenedor) {
                return contenedor;
            }

            contenedor = document.createElement('div');
            contenedor.className = 'toast-container position-fixed top-0 end-0 p-3 reminder-toast-container';
            contenedor.setAttribute('data-reminder-toast-container', '');
            // Si el CDN de Bootstrap JS/CSS no carga, conservar posición y visibilidad.
            contenedor.style.position = 'fixed';
            contenedor.style.top = '0';
            contenedor.style.right = '0';
            contenedor.style.padding = '12px';
            contenedor.style.zIndex = '1095';
            document.body.appendChild(contenedor);

            return contenedor;
        };

        const mostrarToast = function (aviso) {
            if (document.hidden || toastActivo?.isConnected) return false;
            if (esComercial(aviso) && (document.querySelector('.system-toast.show,dialog[open],.modal.show') ||
                window.IMPE_TELEPHONY_PERSISTENT?.getState?.()?.active)) return false;
            // Marketing conserva su presentador. Evitar superponer un aviso suyo.
            if (document.querySelector('.convocatoria-activation-toast.is-visible')) return false;
            const contenedor = asegurarContenedorToasts();
            const toast = document.createElement('div');
            const vencida = String(aviso.tipo || '') === 'VENCIDA';
            toast.className = 'toast reminder-toast show' + (vencida ? ' is-overdue' : '');
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.setAttribute('aria-atomic', 'true');
            toast.innerHTML =
                '<div class="reminder-toast-body">' +
                    '<span class="reminder-toast-icon"><i class="bi ' +
                        escapar(aviso.icono || 'bi-bell') + '"></i></span>' +
                    '<span class="reminder-toast-copy">' +
                        '<strong>' + escapar(aviso.titulo || 'Notificación') + '</strong>' +
                        '<span>' + escapar(aviso.mensaje || '') + '</span>' +
                    '</span>' +
                '</div>';

            const url = urlRecordatorio(aviso);
            if (Number(aviso.telefonia_id) > 0) {
                // El lector existente captura este atributo, registra lectura con CSRF
                // y navega únicamente al terminar. El temporizador nunca llama al lector.
                toast.dataset.telefoniaId = String(aviso.telefonia_id);
                toast.setAttribute('href', url);
            }
            if (url !== '') {
                toast.classList.add('is-clickable');
                toast.setAttribute('tabindex', '0');
                toast.addEventListener('click', function () { window.location.href = url; });
                toast.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        toast.click();
                    }
                });
            }
            // La presentación usa las clases existentes del CRM. No depende del
            // constructor, los eventos o el CDN de Bootstrap JS, aunque esté presente.
            toast.style.display = 'block';
            contenedor.appendChild(toast);
            const estilo = window.getComputedStyle(toast);
            const rect = toast.getBoundingClientRect();
            const marco = window.frameElement;
            const marcoVisible = !marco || marco.getBoundingClientRect().width > 0;
            if (!toast.isConnected || rect.width <= 0 || rect.height <= 0 ||
                estilo.display === 'none' || estilo.visibility === 'hidden' ||
                Number(estilo.opacity) === 0 || !marcoVisible) {
                toast.remove();
                return false;
            }
            if(esComercial(aviso)){
                toast.dataset.comercialClave=claveRecordatorio(aviso);
                const cerrar=document.createElement('button');cerrar.type='button';cerrar.className='btn-close reminder-toast-close';
                cerrar.setAttribute('aria-label','Cerrar aviso');
                cerrar.addEventListener('click',e=>{e.stopPropagation();toast.remove();if(toastActivo===toast)toastActivo=null;});
                cerrar.addEventListener('keydown',e=>e.stopPropagation());
                toast.querySelector('.reminder-toast-body').appendChild(cerrar);
            }
            toastActivo = toast;
            window.setTimeout(function () {
                toast.remove();
                if (toastActivo === toast) toastActivo = null;
            }, DURACION_TOAST);
            return true;
        };

        const esRecordatorioVencidoRecurrente = function (recordatorio) {
            return estadoVisibleRecordatorio(recordatorio) === 'vencida';
        };

        const actualizarColaAvisos = function (avisos) {
            const pendientes = new Set(recordatoriosActuales.map(claveRecordatorio));
            colaAvisos = colaAvisos.filter(item => pendientes.has(item.clave) && !esComercial(item.aviso));
            for (const aviso of Array.isArray(avisos) ? avisos : []) {
                if(esComercial(aviso))continue;
                const clave = claveRecordatorio(aviso);
                // Un aviso sigue a su pendiente real; no revivir acciones resueltas.
                if (!pendientes.has(clave)) continue;
                const existente = colaAvisos.findIndex(item => item.clave === clave);
                const item = { clave, aviso, recibido: Date.now() };
                if (existente >= 0) colaAvisos[existente] = item;
                else colaAvisos.push(item);
            }
            colaAvisos = colaAvisos.slice(0, 30);
            guardarSesion(CLAVE_COLA_AVISOS, JSON.stringify(colaAvisos));
        };

        const mostrarSiguienteToastVencido = async function () {
            const claves = new Set();
            const disponibles = recordatoriosActuales.filter(function (r) {
                const clave = claveRecordatorio(r);
                if (claves.has(clave) || Number(r.leida || 0) !== 0) return false;
                if(esComercial(r)){
                    const disponible=!refrescoComercialPendiente && Boolean(r.ventana) && !comercialVisto(r);
                    if(disponible)claves.add(clave);return disponible;
                }
                const disponible = esRecordatorioVencidoRecurrente(r) ||
                    Number(r.telefonia_id || 0) > 0 ||
                    colaAvisos.some(item => item.clave === clave);
                if (disponible) claves.add(clave);
                return disponible;
            });
            if (!disponibles.length) return false;
            const ultimaClave = leerSesion(CLAVE_ULTIMO_TOAST_VENCIDO_ID);
            const anterior = disponibles.findIndex(r => claveRecordatorio(r) === ultimaClave);
            const recordatorio = disponibles[(anterior + 1) % disponibles.length];
            const clave = claveRecordatorio(recordatorio);
            if(esComercial(recordatorio))return bloqueoComercial(()=>{
                if(document.hidden || refrescoComercialPendiente || comercialVisto(recordatorio) ||
                    ultimoComercial()+INTERVALO_TOAST_VENCIDO>Date.now() ||
                    !recordatoriosActuales.some(r=>claveRecordatorio(r)===clave && r.ventana===recordatorio.ventana))return false;
                const aviso={...recordatorio,tipo:recordatorio.ventana,
                    titulo:recordatorio.ventana==='VENCIDA'?'Actividad comercial vencida':'Próxima actividad comercial',
                    mensaje:recordatorio.nombre_entidad+' · '+recordatorio.accion+
                        (recordatorio.detalle?' · '+recordatorio.detalle:'')+' · '+recordatorio.fecha_texto};
                if(!mostrarToast(aviso))return false;
                guardarComercial(recordatorio);registrarToastVencido(recordatorio);return true;
            });
            const enCola = colaAvisos.find(item => item.clave === clave);
            const telefono = Number(recordatorio.telefonia_id || 0) > 0;
            const aviso = enCola?.aviso || {
                ...recordatorio,
                tipo: telefono ? 'TELEFONIA_PERDIDA' : 'VENCIDA',
                titulo: telefono ? (recordatorio.titulo || recordatorio.nombre_entidad || 'Aviso telefónico') : 'Acción vencida',
                mensaje: telefono ? (recordatorio.mensaje || recordatorio.accion || 'Revisa tu historial') :
                    String(recordatorio.accion || 'Revisar pendiente') + ' · ' +
                    String(recordatorio.nombre_entidad || 'Seguimiento') +
                    (etiquetaVisibleRecordatorio(recordatorio) ? ' · ' + etiquetaVisibleRecordatorio(recordatorio) : '')
            };
            if (!mostrarToast(aviso)) return false;
            // Sólo un aviso realmente visible consume turno y posición de rotación.
            registrarToastVencido(recordatorio);
            colaAvisos = colaAvisos.filter(item => item.clave !== clave);
            guardarSesion(CLAVE_COLA_AVISOS, JSON.stringify(colaAvisos));
            return true;
        };

        const ultimoTurno = function () {
            return Math.max(fechaSesion(CLAVE_INICIO_CADENCIA),
                fechaSesion(CLAVE_ULTIMO_TOAST_COMPARTIDO_AT),
                fechaSesion(CLAVE_ULTIMO_TOAST_VENCIDO_AT),ultimoComercial());
        };
        const programarSiguienteToastVencido = function () {
            if (temporizadorToastVencido !== null) window.clearTimeout(temporizadorToastVencido);
            proximoToastAt = ultimoTurno() + INTERVALO_TOAST_VENCIDO;
            temporizadorToastVencido = window.setTimeout(async function ejecutar() {
                temporizadorToastVencido = null;
                // Otra página o fuente pudo usar el turno después de programarlo.
                if (ultimoTurno() + INTERVALO_TOAST_VENCIDO > Date.now()) {
                    programarSiguienteToastVencido();
                    return;
                }
                if (!document.hidden && cargaInicialCompletada && await mostrarSiguienteToastVencido()) {
                    programarSiguienteToastVencido();
                    return;
                }
                // Sin pendientes, sin visibilidad o con otro toast: conservar el
                // turno vencido. Un fetch o visibilitychange vuelve a comprobarlo.
                temporizadorToastVencido = window.setTimeout(ejecutar, REINTENTO_TOAST_OCULTO);
            }, Math.max(0, proximoToastAt - Date.now()));
        };

        window.IMPE_REMINDER_STATUS = function () {
            return Object.freeze({
                version: 'toasts-8m-v1', initialized: true,
                userId: Number(window.IMPE_CURRENT_USER_ID || 0),
                bootstrapAvailable: typeof window.bootstrap?.Toast === 'function',
                persistentStorage: almacenamientoPersistente,
                startedAt: fechaSesion(CLAVE_INICIO_CADENCIA),
                lastShownAt: fechaSesion(CLAVE_ULTIMO_TOAST_COMPARTIDO_AT),
                nextToastAt: proximoToastAt,
                timerActive: temporizadorToastVencido !== null,
                pendingCount: recordatoriosActuales.length,
                loadedCount: consultasCompletadas,
                visibleToasts: toastActivo?.isConnected ? 1 : 0
            });
        };

        const renderizarRecordatorios = function (
            recordatorios,
            totalBackend, falloComercial=false
        ) {
            const lista = Array.isArray(recordatorios) ? recordatorios : [];
            const totalBadge = usarTotalBackend
                ? Math.max(0, Number(totalBackend || 0))
                : lista.length;
            recordatoriosActuales = lista;
            if(toastActivo?.dataset.comercialClave && !lista.some(r=>claveRecordatorio(r)===toastActivo.dataset.comercialClave)){
                toastActivo.remove();toastActivo=null;
            }

            if (contenido) {
                contenido.setAttribute('aria-busy', 'false');
            }

            if (badge) {
                if (totalBadge > 0) {
                    badge.textContent =
                        totalBadge > 9 ? '9+' : String(totalBadge);
                    badge.classList.remove('d-none');
                } else {
                    badge.textContent = '0';
                    badge.classList.add('d-none');
                }
            }

            if (!contenido) {
                return;
            }

            if (lista.length === 0 && falloComercial) {
                contenido.innerHTML='<div class="topbar-reminder-empty" role="status"><strong>No se pudieron actualizar las actividades comerciales</strong><span>Reintentando automáticamente.</span></div>';
                return;
            }
            if (lista.length === 0) {
                contenido.innerHTML =
                    '<div class="topbar-reminder-empty">' +
                        '<i class="bi bi-check2-circle"></i>' +
                        '<strong>' + escapar(tituloVacio) + '</strong>' +
                        '<span>' + escapar(mensajeVacio) + '</span>' +
                    '</div>';
                return;
            }

            contenido.innerHTML =
                '<div class="topbar-reminder-list">' +
                lista.map(function (recordatorio) {
                    const url = escapar(urlRecordatorio(recordatorio));
                    const etiqueta = etiquetaVisibleRecordatorio(recordatorio);
                    const estadoVisual = estadoVisibleRecordatorio(recordatorio);
                    return (
                        '<a class="topbar-reminder-item" '+(Number(recordatorio.telefonia_id)>0?'data-telefonia-id="'+Number(recordatorio.telefonia_id)+'" ':'')+'href="' + url + '">' +
                            '<span class="topbar-reminder-icon">' +
                                '<i class="bi ' + escapar(recordatorio.icono || 'bi-bell') + '"></i>' +
                            '</span>' +
                            '<span class="topbar-reminder-copy">' +
                                '<strong>' + escapar(recordatorio.nombre_entidad || 'Seguimiento') + '</strong>' +
                                '<span>' + escapar(recordatorio.accion || '') + '</span>' +
                                (Number(recordatorio.telefonia_id)>0 ? fechaAvisoTelefonico(recordatorio) : '') +
                                (esComercial(recordatorio)?'<span>'+escapar(recordatorio.detalle||'')+'</span><span><time datetime="'+escapar(recordatorio.fecha)+'">'+escapar(recordatorio.fecha_texto)+'</time></span>':'') +
                            '</span>' +
                            '<span class="topbar-reminder-time is-' + escapar(estadoVisual) + '">' +
                                escapar(etiqueta) +
                            '</span>' +
                        '</a>'
                    );
                }).join('') +
                '</div>' + (falloComercial?'<div class="topbar-reminder-header" role="status"><span>No se pudieron actualizar las actividades comerciales. Reintentando.</span></div>':'');
        };

        function fechaAvisoTelefonico(recordatorio) {
            const raw=String(recordatorio.created_at||recordatorio.fecha||'').trim();
            const fecha=new Date(raw.replace(' ','T'));
            if(!raw||Number.isNaN(fecha.getTime()))return '<span>Fecha pendiente de confirmar</span>';
            const texto=new Intl.DateTimeFormat('es-MX',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',hour12:false}).format(fecha);
            return '<span><time datetime="'+escapar(raw.replace(' ','T'))+'">'+escapar(texto)+'</time></span>';
        }

        const programarReintentoCarga = function () {
            if (temporizadorReintentoCarga !== null || fallosDeCarga > 4) return;
            const espera = Math.min(15000, 2500 * Math.max(1, fallosDeCarga));
            temporizadorReintentoCarga = window.setTimeout(function () {
                temporizadorReintentoCarga = null;
                void consultarRecordatorios();
            }, espera);
        };

        const mostrarFalloCarga = function () {
            // Mantener los avisos previamente cargados; no vaciar la campana.
            if (cargaInicialCompletada || !contenido) return;
            contenido.setAttribute('aria-busy', 'false');
            contenido.innerHTML =
                '<div class="topbar-reminder-empty">' +
                    '<i class="bi bi-arrow-clockwise"></i>' +
                    '<strong>No se pudieron cargar las notificaciones</strong>' +
                    '<span>Reintentando automáticamente. También puedes recargar la página.</span>' +
                '</div>';
        };

        const refrescarComercial = event => {
            if(event?.detail?.usuarioId && Number(event.detail.usuarioId)!==Number(window.IMPE_CURRENT_USER_ID))return;
            versionComercial++;refrescoComercialPendiente=true;
            if(consultaEnCurso){repetirConsulta=true;return;}
            void consultarRecordatorios();
        };
        window.addEventListener('impe:phone-notices-refresh',refrescarComercial);
        window.addEventListener('impe:comercial-actividad-refresh',refrescarComercial);
        window.addEventListener('impe:embudo:confirmacion-resultado',refrescarComercial);
        window.addEventListener('storage',event=>{
            if(event.key==='impe:comercial:actividad:'+Number(window.IMPE_CURRENT_USER_ID||0) ||
                event.key==='impe:embudo:resultado-guardado:'+Number(window.IMPE_CURRENT_USER_ID||0))refrescarComercial();
        });
        const consultarRecordatorios = async function () {
            if (consultaEnCurso || document.hidden) {
                return;
            }

            consultaEnCurso = true;
            const versionConsulta=versionComercial;

            try {
                const respuesta = await fetch(endpoint, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    cache: 'no-store'
                });

                if (!respuesta.ok) {
                    throw new Error('Respuesta HTTP ' + respuesta.status);
                }

                const datos = await respuesta.json();

                if (!datos || (!datos.ok && !datos.parcial)) {
                    throw new Error('Respuesta de recordatorios no disponible');
                }

                if(versionConsulta!==versionComercial){repetirConsulta=true;return;}
                const falloComercial=datos.comercial_ok===false;
                const comercialesPrevios=falloComercial?recordatoriosActuales.filter(esComercial):[];
                const recibidos=Array.isArray(datos.recordatorios)?datos.recordatorios:[];
                refrescoComercialPendiente=falloComercial;
                renderizarRecordatorios(
                    falloComercial?[...recibidos.filter(r=>!esComercial(r)),...comercialesPrevios]:recibidos,
                    Number(datos.total||0)+comercialesPrevios.length,falloComercial
                );
                cargaInicialCompletada = true;
                consultasCompletadas += 1;
                fallosDeCarga = 0;
                if (temporizadorReintentoCarga !== null) {
                    window.clearTimeout(temporizadorReintentoCarga);
                    temporizadorReintentoCarga = null;
                }
                actualizarColaAvisos(datos.avisos || []);
                programarSiguienteToastVencido();
                if (datos.requiere_migracion) {
                    if (!avisoMigracionMostrado) {
                        avisoMigracionMostrado = true;
                        console.warn(
                            'Recordatorios: ejecuta database/migrations/2026_09_03_recordatorios_vinculacion.sql para habilitar los avisos automáticos.'
                        );
                    }
                    return;
                }

            } catch (error) {
                if(recordatoriosActuales.some(esComercial))refrescoComercialPendiente=true;
                fallosDeCarga += 1;
                mostrarFalloCarga();
                console.error('No fue posible actualizar las notificaciones.', error);
            } finally {
                consultaEnCurso = false;
                if(repetirConsulta && !document.hidden){repetirConsulta=false;void consultarRecordatorios();}
                if (!cargaInicialCompletada) programarReintentoCarga();
            }
        };

        consultarRecordatorios();
        window.setInterval(consultarRecordatorios, 60000);
        programarSiguienteToastVencido();

        window.addEventListener('pageshow', function () {
            if(recordatoriosActuales.some(esComercial))refrescarComercial();else void consultarRecordatorios();
            programarSiguienteToastVencido();
        });
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                if(recordatoriosActuales.some(esComercial))refrescarComercial();else consultarRecordatorios();
                programarSiguienteToastVencido();
            }
        });
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    } else {
        iniciar();
    }
})();
