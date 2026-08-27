document.addEventListener('DOMContentLoaded', function () {

    const togglePassword =
        document.getElementById('togglePassword');

    const password =
        document.getElementById('password');

    const passwordIcon =
        document.getElementById('passwordIcon');

    if (!togglePassword || !password || !passwordIcon) {
        return;
    }

    togglePassword.addEventListener('click', function () {

        if (password.type === 'password') {

            password.type = 'text';

            passwordIcon.classList.remove('bi-eye');
            passwordIcon.classList.add('bi-eye-slash');

            togglePassword.setAttribute(
                'aria-label',
                'Ocultar contraseña'
            );

        } else {

            password.type = 'password';

            passwordIcon.classList.remove('bi-eye-slash');
            passwordIcon.classList.add('bi-eye');

            togglePassword.setAttribute(
                'aria-label',
                'Mostrar contraseña'
            );

        }

    });

});