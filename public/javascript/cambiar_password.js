document.addEventListener('DOMContentLoaded', function () {

    const botones =
        document.querySelectorAll('.password-view-toggle');

    botones.forEach(function (boton) {

        boton.addEventListener('click', function () {

            const contenedor =
                boton.closest('.login-input-group');

            if (!contenedor) {
                return;
            }

            const input =
                contenedor.querySelector('input');

            const icono =
                boton.querySelector('i');

            if (!input || !icono) {
                return;
            }


            if (input.type === 'password') {

                input.type = 'text';

                icono.classList.remove('bi-eye');
                icono.classList.add('bi-eye-slash');

                boton.setAttribute(
                    'aria-label',
                    'Ocultar contraseña'
                );

            } else {

                input.type = 'password';

                icono.classList.remove('bi-eye-slash');
                icono.classList.add('bi-eye');

                boton.setAttribute(
                    'aria-label',
                    'Mostrar contraseña'
                );

            }

        });

    });

});