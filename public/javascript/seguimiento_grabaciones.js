(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const parametros = new URLSearchParams(window.location.search);
        const esExpediente =
            parametros.get('controller') === 'seguimientoVinculacion' &&
            parametros.get('action') === 'detalle';
        const seguimientoId = Number(parametros.get('id') || 0);

        if (!esExpediente || seguimientoId <= 0) {
            return;
        }

        const normalizar = function (valor) {
            return String(valor || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .trim()
                .toLowerCase();
        };

        const seccionInteracciones = Array.from(
            document.querySelectorAll('section.dashboard-panel.linkage-detail-panel')
        ).find(function (seccion) {
            const titulo = seccion.querySelector('.panel-title');
            return normalizar(titulo?.textContent) === 'historial de interacciones';
        });

        if (!seccionInteracciones || seccionInteracciones.querySelector('[data-call-recordings]')) {
            return;
        }

        const tituloHistorial = seccionInteracciones.querySelector('.panel-title');
        const bloque = document.createElement('div');
        bloque.className = 'linkage-call-recordings';
        bloque.setAttribute('data-call-recordings', '');
        bloque.innerHTML =
            '<div class="linkage-call-recordings-heading">' +
                '<div class="linkage-call-recordings-title">' +
                    '<span class="linkage-call-recordings-icon"><i class="bi bi-telephone"></i></span>' +
                    '<div>' +
                        '<strong>Llamadas realizadas</strong>' +
                        '<p>Registro telefónico y grabaciones asociadas a este seguimiento.</p>' +
                    '</div>' +
                '</div>' +
                '<span class="linkage-call-recordings-count" data-call-count>—</span>' +
            '</div>' +
            '<div class="linkage-call-recordings-list" data-call-list>' +
                '<div class="linkage-call-recordings-loading">' +
                    '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>' +
                    '<span>Cargando llamadas…</span>' +
                '</div>' +
            '</div>';

        if (tituloHistorial) {
            tituloHistorial.insertAdjacentElement('afterend', bloque);
        } else {
            seccionInteracciones.prepend(bloque);
        }

        const lista = bloque.querySelector('[data-call-list]');
        const contador = bloque.querySelector('[data-call-count]');
        const resultados = {
            CONTACTADO: 'Contactado',
            NO_CONTESTO: 'No contestó',
            OCUPADO: 'Ocupado',
            NUMERO_INCORRECTO: 'Número incorrecto',
            SOLICITO_LLAMAR_DESPUES: 'Solicitó llamar después',
            MENSAJE_ENVIADO: 'Mensaje enviado',
            CORREO_ENVIADO: 'Correo enviado',
            SIN_RESPUESTA: 'Sin respuesta',
            OTRO: 'Otro'
        };

        const fechaLegible = function (valor) {
            const cadena = String(valor || '').trim();
            const coincidencia = cadena.match(
                /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/
            );

            if (!coincidencia) {
                return cadena || '—';
            }

            const meses = [
                'ene', 'feb', 'mar', 'abr', 'may', 'jun',
                'jul', 'ago', 'sep', 'oct', 'nov', 'dic'
            ];
            let salida =
                coincidencia[3] + ' ' +
                meses[Math.max(0, Number(coincidencia[2]) - 1)] + ' ' +
                coincidencia[1];

            if (coincidencia[4]) {
                salida += ' · ' + coincidencia[4] + ':' + coincidencia[5];
            }

            return salida;
        };

        const duracionLegible = function (segundos) {
            const total = Math.max(0, Number(segundos) || 0);

            if (total <= 0) {
                return '—';
            }

            const minutos = Math.floor(total / 60);
            const resto = total % 60;
            return String(minutos).padStart(2, '0') + ':' + String(resto).padStart(2, '0');
        };

        const crearTarjeta = function (llamada) {
            const tarjeta = document.createElement('article');
            tarjeta.className = 'linkage-call-recording-card';

            const icono = document.createElement('span');
            icono.className = 'linkage-call-recording-card-icon';
            icono.innerHTML = '<i class="bi bi-telephone-outbound"></i>';

            const cuerpo = document.createElement('div');
            cuerpo.className = 'linkage-call-recording-body';

            const cabecera = document.createElement('div');
            cabecera.className = 'linkage-call-recording-top';

            const identidad = document.createElement('div');
            const resultado = document.createElement('strong');
            const claveResultado = String(llamada.resultado || '').toUpperCase();
            resultado.textContent = resultados[claveResultado] || 'Llamada registrada';

            const meta = document.createElement('span');
            const partesMeta = [fechaLegible(llamada.fecha_inicio)];
            const usuario = String(llamada.usuario || '').trim();

            if (usuario) {
                partesMeta.push(usuario);
            }

            meta.textContent = partesMeta.join(' · ');
            identidad.appendChild(resultado);
            identidad.appendChild(meta);

            const duracion = document.createElement('span');
            duracion.className = 'linkage-call-recording-duration';
            duracion.innerHTML = '<i class="bi bi-clock"></i><span></span>';
            duracion.querySelector('span').textContent = duracionLegible(llamada.duracion_segundos);

            cabecera.appendChild(identidad);
            cabecera.appendChild(duracion);
            cuerpo.appendChild(cabecera);

            const notasTexto = String(llamada.notas || '').trim();
            if (notasTexto) {
                const notas = document.createElement('p');
                notas.className = 'linkage-call-recording-notes';
                notas.textContent = notasTexto;
                cuerpo.appendChild(notas);
            }

            const audioWrap = document.createElement('div');
            audioWrap.className = 'linkage-call-recording-audio';

            if (llamada.tiene_grabacion && llamada.grabacion_url) {
                const etiqueta = document.createElement('span');
                etiqueta.className = 'linkage-call-recording-audio-label';
                etiqueta.innerHTML = '<i class="bi bi-record-circle"></i><span>Grabación</span>';

                const audio = document.createElement('audio');
                audio.controls = true;
                audio.preload = 'none';
                audio.controlsList = 'nodownload';
                audio.src = String(llamada.grabacion_url);
                audio.setAttribute('aria-label', 'Grabación de llamada');

                const error = document.createElement('span');
                error.className = 'linkage-call-recording-unavailable d-none';
                error.innerHTML = '<i class="bi bi-exclamation-circle"></i><span>Grabación no disponible.</span>';

                audio.addEventListener('error', function () {
                    audio.classList.add('d-none');
                    error.classList.remove('d-none');
                });

                audioWrap.appendChild(etiqueta);
                audioWrap.appendChild(audio);
                audioWrap.appendChild(error);
            } else {
                audioWrap.classList.add('is-unavailable');
                audioWrap.innerHTML =
                    '<span class="linkage-call-recording-unavailable">' +
                        '<i class="bi bi-mic-mute"></i>' +
                        '<span>Sin grabación disponible</span>' +
                    '</span>';
            }

            cuerpo.appendChild(audioWrap);
            tarjeta.appendChild(icono);
            tarjeta.appendChild(cuerpo);

            return tarjeta;
        };

        const renderizarVacio = function (mensaje) {
            lista.innerHTML = '';
            const vacio = document.createElement('div');
            vacio.className = 'linkage-call-recordings-empty';
            vacio.innerHTML = '<i class="bi bi-telephone-x"></i><span></span>';
            vacio.querySelector('span').textContent = mensaje;
            lista.appendChild(vacio);
        };

        fetch(
            'prueba_telefonia/api/llamadas_seguimiento.php?seguimiento_id=' +
                encodeURIComponent(seguimientoId),
            {
                headers: { 'X-Requested-With': 'fetch' },
                credentials: 'same-origin',
                cache: 'no-store'
            }
        )
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || !data.ok) {
                        throw new Error(data.mensaje || 'No fue posible cargar las llamadas.');
                    }
                    return data;
                });
            })
            .then(function (data) {
                const llamadas = Array.isArray(data.llamadas) ? data.llamadas : [];
                contador.textContent = String(llamadas.length);
                contador.setAttribute(
                    'aria-label',
                    llamadas.length === 1 ? '1 llamada registrada' : llamadas.length + ' llamadas registradas'
                );

                if (llamadas.length === 0) {
                    renderizarVacio('Todavía no hay llamadas registradas en este seguimiento.');
                    return;
                }

                lista.innerHTML = '';
                llamadas.forEach(function (llamada) {
                    lista.appendChild(crearTarjeta(llamada));
                });
            })
            .catch(function (error) {
                contador.textContent = '—';
                renderizarVacio(error.message || 'No fue posible cargar las llamadas.');
            });
    });
})();
