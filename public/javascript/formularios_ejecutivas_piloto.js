document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const form = document.querySelector('[data-ejecutivas-piloto]');
    if (!form) return;

    const fields = [...form.querySelectorAll('[data-ejecutivas-field]')];
    const result = form.querySelector('[data-ejecutivas-resultado]');
    const submit = form.querySelector('[data-ejecutivas-validar]');
    const field = (name) => form.elements.namedItem(name);

    function mark(element, ok, message) {
        if (!element) return ok;
        element.classList.toggle('is-invalid', !ok);
        element.classList.toggle('is-valid', ok && element.value.trim() !== '');
        element.setAttribute('aria-invalid', ok ? 'false' : 'true');
        const error = form.querySelector('[data-ejecutivas-error="' + element.name + '"]');
        if (error && message) error.textContent = message;
        return ok;
    }

    function limpiar() {
        if (field('curp')) field('curp').value = field('curp').value.trim().toUpperCase().slice(0, 18);
        for (const name of ['movil', 'movil_confirmacion', 'codigo_postal']) {
            const el = field(name);
            if (el) el.value = el.value.replace(/\D/g, '').slice(0, name === 'codigo_postal' ? 5 : 10);
        }
    }

    function checksum(curp) {
        const alphabet = [...'0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ'];
        let total = 0;
        for (let i = 0; i < 17; i++) {
            const value = alphabet.indexOf(curp[i]);
            if (value < 0) return null;
            total += value * (18 - i);
        }
        return (10 - (total % 10)) % 10;
    }

    function validarCurp() {
        const el = field('curp');
        const curp = String(el?.value || '').trim().toUpperCase();
        const date = String(field('fecha_nacimiento')?.value || '');
        const sex = String(field('genero')?.value || '');
        const re = /^[A-Z][AEIOUX][A-Z]{2}\d{6}[HM][A-Z]{2}[B-DF-HJ-NP-TV-Z]{3}[A-Z\d]\d$/;
        let message = '';
        if (!re.test(curp)) {
            message = 'La CURP debe contener 18 caracteres con el formato oficial.';
        } else if (date && date.slice(2).replace(/-/g, '') !== curp.slice(4, 10)) {
            message = 'La fecha de nacimiento no coincide con la CURP.';
        } else if (sex && sex !== curp[10]) {
            message = 'El sexo registrado no coincide con la CURP.';
        } else if (checksum(curp) === null || Number(curp[17]) !== checksum(curp)) {
            message = 'El dígito verificador de la CURP no coincide.';
        }
        return mark(el, !message, message);
    }

    function validarCapturaLocal() {
        limpiar();
        let valid = true;
        for (const el of fields) {
            let message = '';
            const value = String(el.value || '').trim();
            if (el.required && !value) {
                message = 'Este campo es obligatorio.';
            } else if (value && !el.checkValidity()) {
                message = el.type === 'email'
                    ? 'Ingresa un correo electrónico válido.'
                    : 'Revisa el formato de este campo.';
            } else if (['nombre', 'apellidos'].includes(el.name) &&
                !/^[\p{L}\p{M}]+(?:[ .'\u2019-][\p{L}\p{M}]+)*$/u.test(value)) {
                message = 'Escribe los nombres como aparecen en tus documentos, sin números ni caracteres extraños.';
            }
            if (!mark(el, !message, message)) valid = false;
        }

        if (!validarCurp()) valid = false;
        const confirm = [
            ['correo', 'correo_confirmacion', 'Los correos no coinciden.'],
            ['movil', 'movil_confirmacion', 'Los números de móvil no coinciden.']
        ];
        for (const [first, second, message] of confirm) {
            const a = String(field(first)?.value || '').trim();
            const b = String(field(second)?.value || '').trim();
            const ok = first === 'correo' ? a.toLowerCase() === b.toLowerCase() : a === b;
            if (!mark(field(second), ok && a !== '', message)) valid = false;
        }
        return valid;
    }

    function mostrar(status, title, notes) {
        if (!result) return;
        result.hidden = false;
        result.classList.toggle('is-error', status === 'error');
        result.replaceChildren();
        const heading = document.createElement('strong');
        heading.textContent = title;
        result.appendChild(heading);
        if (Array.isArray(notes) && notes.length) {
            const list = document.createElement('ul');
            notes.forEach(function (text) {
                const item = document.createElement('li');
                item.textContent = String(text);
                list.appendChild(item);
            });
            result.appendChild(list);
        }
    }

    fields.forEach(function (el) {
        el.addEventListener('blur', function () {
            if (['curp', 'fecha_nacimiento', 'genero'].includes(el.name)) {
                limpiar();
                validarCurp();
            } else if (el.required) {
                mark(el, !!el.value.trim() && el.checkValidity(), 'Comprueba este dato.');
            }
        });
    });

    for (const name of ['curp', 'fecha_nacimiento', 'genero']) {
        field(name)?.addEventListener('change', validarCurp);
    }
    field('curp')?.addEventListener('input', function () {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 18);
        if (this.value.length === 18) validarCurp();
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (submit.disabled) return;
        if (!validarCapturaLocal()) {
            mostrar('error', 'Hay datos por revisar.', [
                'Revisa los campos resaltados en rojo. Ninguna información se ha guardado.'
            ]);
            form.querySelector('.is-invalid')?.focus();
            return;
        }

        const data = new FormData(form);
        data.delete('comprobante_pago'); // Nunca enviar un archivo en este piloto.
        submit.disabled = true;
        mostrar('info', 'Comprobando datos en el servidor…', []);
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'fetch'}
            });
            const json = await response.json();
            const errors = json && typeof json.errores === 'object' ? json.errores : {};
            fields.forEach(function (el) {
                if (errors[el.name]) mark(el, false, errors[el.name]);
            });
            const entries = Object.values(errors).map(String);
            const notices = Array.isArray(json.avisos) ? json.avisos : [];
            if (!response.ok || !json.ok) {
                mostrar('error', json.mensaje || 'Hay datos por revisar.', entries);
                form.querySelector('.is-invalid')?.focus();
                return;
            }
            mostrar('info', 'Datos de captura revisados, sin guardar información.', notices);
        } catch (error) {
            mostrar('error', 'No fue posible validar en el servidor. Comprueba tu sesión e inténtalo nuevamente.', []);
        } finally {
            submit.disabled = false;
        }
    });
});