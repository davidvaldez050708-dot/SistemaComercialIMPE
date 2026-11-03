(function () {
    'use strict';
    function iniciar() {
        ['crear','editar'].forEach(function (modo) {
            var modal=document.getElementById(modo==='crear'?'modalCrearUsuario':'modalEditarUsuario');
            if (!modal) return;
            var form=modal.querySelector('form'), summary=form.querySelector('[data-user-summary]'), attempted=false;
            var names=['nombre','apellidos','correo','usuario','rol_id','estado','password','confirmar_password','foto_perfil'];
            function input(name) {return Array.from(form.elements).find(function (el) {return el.name===name&&!el.disabled;});}
            function show(name,message) {
                var box=form.querySelector('[data-user-error="'+name+'"]'), field=input(name);
                if (!box) return;
                box.textContent=message;box.hidden=!message;
                if (field) {
                    field.setAttribute('aria-invalid',message?'true':'false');
                    var group=field.closest('.login-input-group');if(group)group.classList.toggle('users-invalid-group',Boolean(message));
                }
            }
            function message(name) {
                var field=input(name);if(!field||field.type==='hidden')return '';
                var value=field.type==='password'?field.value:field.value.trim();
                var required={nombre:'Escribe el nombre.',apellidos:'Escribe los apellidos.',correo:'Escribe el correo electrónico.',usuario:'Escribe el nombre de usuario.',rol_id:'Selecciona un rol.',estado:'Selecciona un estado.',password:'Escribe una contraseña.',confirmar_password:'Confirma la contraseña.'};
                if(field.required&&!value)return required[name]||'Completa este campo.';
                if(name==='correo'&&value&&field.validity.typeMismatch)return 'Escribe un correo electrónico válido.';
                if(name==='password'&&new TextEncoder().encode(field.value).length<8)return 'La contraseña debe tener al menos 8 caracteres.';
                if(name==='confirmar_password'&&field.value!==(input('password')?.value||''))return 'Las contraseñas no coinciden.';
                if(name==='foto_perfil'&&field.files?.length) {
                    var file=field.files[0];
                    if(file.size>2*1024*1024)return 'La foto de perfil no debe superar 2 MB.';
                    if(file.type&&!['image/jpeg','image/png','image/webp'].includes(file.type))return 'La foto debe ser JPG, PNG o WEBP.';
                }
                return '';
            }
            function focusFirst() {
                var first=Array.from(form.querySelectorAll('[aria-invalid="true"]')).find(function (el) {return !el.disabled&&el.type!=='hidden';});
                if(first){first.focus();first.scrollIntoView({block:'nearest'});}
            }
            function summaryUpdate() {
                var invalid=Array.from(form.querySelectorAll('[data-user-error]')).some(function (el) {return !el.hidden;});
                summary.hidden=!invalid;summary.querySelector('span').textContent='Revisa los campos señalados.';
            }
            form.addEventListener('submit',function (event) {
                attempted=true;var bad=false;
                names.forEach(function(name){var error=message(name);show(name,error);if(error)bad=true;});
                summaryUpdate();
                if(bad){event.preventDefault();focusFirst();return;}
                form.setAttribute('aria-busy','true');form.querySelector('button[type=submit]').disabled=true;
            });
            function changed(event) {
                var name=event.target.name;if(!names.includes(name))return;
                var box=form.querySelector('[data-user-error="'+name+'"]');
                if(attempted||(box&&!box.hidden)){show(name,message(name));if(name==='password')show('confirmar_password',message('confirmar_password'));summaryUpdate();}
            }
            form.addEventListener('input',changed);form.addEventListener('change',changed);
            modal.addEventListener('show.bs.modal',function (event) {
                if(!event.relatedTarget)return;
                names.forEach(function(name){show(name,'');});summary.hidden=true;attempted=false;
                form.removeAttribute('aria-busy');form.querySelector('button[type=submit]').disabled=false;
            });
            modal.addEventListener('shown.bs.modal',focusFirst);
            form.querySelectorAll('[aria-invalid=true]').forEach(function(field){var group=field.closest('.login-input-group');if(group)group.classList.add('users-invalid-group');});
        });
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',iniciar);else iniciar();
})();
