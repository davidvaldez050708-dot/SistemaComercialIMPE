<?php

if (!function_exists('obtenerInicialesUsuario')) {
    function obtenerInicialesUsuario($nombre, $apellidos = '')
    {
        $texto = trim((string)$nombre . ' ' . (string)$apellidos);

        if ($texto === '') {
            return 'US';
        }

        $partes = preg_split('/\s+/', $texto);
        $iniciales = '';

        if (count($partes) === 1) {
            return strtoupper(substr($partes[0], 0, 2));
        }

        foreach ($partes as $parte) {
            if ($parte === '') {
                continue;
            }

            $iniciales .= strtoupper(substr($parte, 0, 1));

            if (strlen($iniciales) >= 2) {
                break;
            }
        }

        return str_pad(substr($iniciales, 0, 2), 2, 'S');
    }
}

if (!function_exists('obtenerUrlFotoPerfil')) {
    function obtenerUrlFotoPerfil($ruta)
    {
        return obtenerUrlArchivoPublico($ruta, ['public/uploads/usuarios']);
    }
}

if (!function_exists('obtenerUrlArchivoPublico')) {
    function obtenerUrlArchivoPublico($ruta, $directoriosPermitidos)
    {
        $ruta = trim(str_replace('\\', '/', (string)$ruta));

        if ($ruta === '' || strpos($ruta, '..') !== false) {
            return '';
        }

        $ruta = ltrim($ruta, '/');
        $permitida = false;

        foreach ((array)$directoriosPermitidos as $directorioPermitido) {
            $directorioPermitido = trim(str_replace('\\', '/', $directorioPermitido), '/');

            if (strpos($ruta, $directorioPermitido . '/') === 0) {
                $permitida = true;
                break;
            }
        }

        if (!$permitida) {
            return '';
        }

        $extension = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return '';
        }

        $rutaFisica = ROOT_PATH . '/' . $ruta;

        if (!is_file($rutaFisica)) {
            return '';
        }

        return BASE_URL . $ruta;
    }
}

if (!function_exists('renderAvatarUsuario')) {
    function renderAvatarUsuario(
        $nombre,
        $apellidos = '',
        $rol = '',
        $fotoPerfil = '',
        $tamano = 'sm',
        $contexto = 'general',
        $clasesExtra = '',
        $clickeable = true
    ) {
        $nombreCompleto = trim((string)$nombre . ' ' . (string)$apellidos);

        if ($nombreCompleto === '') {
            $nombreCompleto = 'Usuario';
        }

        $iniciales = obtenerInicialesUsuario($nombre, $apellidos);
        $fotoUrl = obtenerUrlFotoPerfil($fotoPerfil);
        $tamanoClase = in_array($tamano, ['xs', 'sm', 'md', 'lg'], true)
            ? 'system-avatar-' . $tamano
            : 'system-avatar-sm';
        $contextoClase = in_array($contexto, ['cuenta-clave', 'analista'], true)
            ? 'system-avatar-' . $contexto
            : 'system-avatar-general';
        $clases = trim(
            'system-avatar ' .
            $tamanoClase . ' ' .
            $contextoClase . ' ' .
            $clasesExtra
        );
        $nombreSeguro = htmlspecialchars($nombreCompleto, ENT_QUOTES, 'UTF-8');
        $rolSeguro = htmlspecialchars((string)$rol, ENT_QUOTES, 'UTF-8');
        $inicialesSeguras = htmlspecialchars($iniciales, ENT_QUOTES, 'UTF-8');

        if ($fotoUrl !== '') {
            $fotoSegura = htmlspecialchars($fotoUrl, ENT_QUOTES, 'UTF-8');
            $imagen = '<img src="' . $fotoSegura . '" class="system-avatar-image" alt="Foto de ' .
                $nombreSeguro . '" data-avatar-initials="' . $inicialesSeguras . '">';

            if ($clickeable) {
                return '<button type="button" class="' . $clases . ' system-avatar-photo-button" ' .
                    'data-profile-photo data-photo-url="' . $fotoSegura . '" ' .
                    'data-photo-name="' . $nombreSeguro . '" data-photo-role="' . $rolSeguro . '" ' .
                    'aria-label="Ver foto de ' . $nombreSeguro . '">' .
                    $imagen .
                    '</button>';
            }

            return '<span class="' . $clases . '">' . $imagen . '</span>';
        }

        return '<span class="' . $clases . ' system-avatar-initials">' .
            $inicialesSeguras .
            '</span>';
    }
}
