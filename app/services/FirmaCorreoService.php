<?php

class FirmaCorreoService
{
    private const MAX_BYTES = 3145728; // 3 MB

    private $directorio;

    public function __construct()
    {
        $this->directorio = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR .
            'storage' . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR . 'firmas';
    }

    public function obtenerRuta($correo)
    {
        $base = $this->nombreBase($correo);
        if ($base === '') {
            return '';
        }

        foreach (['png', 'jpg', 'jpeg', 'webp'] as $extension) {
            $ruta = $this->directorio . DIRECTORY_SEPARATOR . $base . '.' . $extension;
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        return '';
    }

    public function obtenerEstado($correo)
    {
        $ruta = $this->obtenerRuta($correo);

        return [
            'disponible' => $ruta !== '',
            'nombre_archivo' => $ruta !== '' ? basename($ruta) : '',
            'mime' => $ruta !== '' ? $this->detectarMime($ruta) : '',
            'actualizado_at' => $ruta !== '' ? (int)@filemtime($ruta) : 0
        ];
    }

    public function guardar($correo, $archivo)
    {
        $correo = strtolower(trim((string)$correo));
        if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Tu cuenta no tiene un correo válido para asociar la firma.', 422);
        }

        if (!is_array($archivo) || !isset($archivo['error'])) {
            return $this->error('Selecciona una imagen para la firma.', 422);
        }

        $errorCarga = (int)$archivo['error'];
        if ($errorCarga === UPLOAD_ERR_NO_FILE) {
            return $this->error('Selecciona una imagen para la firma.', 422);
        }
        if ($errorCarga !== UPLOAD_ERR_OK) {
            return $this->error('No fue posible recibir la imagen de la firma.', 422);
        }

        $tamano = (int)($archivo['size'] ?? 0);
        if ($tamano <= 0 || $tamano > self::MAX_BYTES) {
            return $this->error('La imagen de firma debe pesar máximo 3 MB.', 422);
        }

        $temporal = (string)($archivo['tmp_name'] ?? '');
        if ($temporal === '' || !is_file($temporal)) {
            return $this->error('El archivo temporal de la firma no está disponible.', 422);
        }

        $mime = $this->detectarMime($temporal);
        $extensiones = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp'
        ];

        if (!isset($extensiones[$mime])) {
            return $this->error('La firma debe ser una imagen PNG, JPG o WEBP.', 422);
        }

        if (!is_dir($this->directorio) && !mkdir($this->directorio, 0775, true) && !is_dir($this->directorio)) {
            return $this->error('No fue posible preparar la carpeta de firmas.', 500);
        }

        $base = $this->nombreBase($correo);
        $extension = $extensiones[$mime];
        $destino = $this->directorio . DIRECTORY_SEPARATOR . $base . '.' . $extension;
        $temporalDestino = $this->directorio . DIRECTORY_SEPARATOR .
            '.firma_' . $base . '_' . bin2hex(random_bytes(6)) . '.' . $extension;

        if (!move_uploaded_file($temporal, $temporalDestino)) {
            if (!@rename($temporal, $temporalDestino)) {
                return $this->error('No fue posible guardar la imagen de la firma.', 500);
            }
        }

        foreach (['png', 'jpg', 'jpeg', 'webp'] as $extensionAnterior) {
            $rutaAnterior = $this->directorio . DIRECTORY_SEPARATOR . $base . '.' . $extensionAnterior;
            if (is_file($rutaAnterior)) {
                @unlink($rutaAnterior);
            }
        }

        if (!@rename($temporalDestino, $destino)) {
            @unlink($temporalDestino);
            return $this->error('No fue posible activar la nueva firma.', 500);
        }

        return [
            'ok' => true,
            'mensaje' => 'Firma de correo actualizada correctamente.',
            'ruta' => $destino
        ];
    }

    public function eliminar($correo)
    {
        $base = $this->nombreBase($correo);
        if ($base === '') {
            return $this->error('Tu cuenta no tiene un correo válido.', 422);
        }

        $eliminada = false;
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $extension) {
            $ruta = $this->directorio . DIRECTORY_SEPARATOR . $base . '.' . $extension;
            if (is_file($ruta)) {
                $eliminada = @unlink($ruta) || $eliminada;
            }
        }

        return [
            'ok' => true,
            'mensaje' => $eliminada
                ? 'Firma de correo eliminada.'
                : 'No había una firma de correo configurada.'
        ];
    }

    public function detectarMime($ruta)
    {
        if ($ruta === '' || !is_file($ruta)) {
            return '';
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $ruta);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return strtolower(trim($mime));
                }
            }
        }

        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($ruta);
            if (is_string($mime)) {
                return strtolower(trim($mime));
            }
        }

        return '';
    }

    private function nombreBase($correo)
    {
        $correo = strtolower(trim((string)$correo));
        if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return '';
        }

        return (string)preg_replace('/[^a-z0-9._-]+/i', '_', $correo);
    }

    private function error($mensaje, $codigoHttp)
    {
        return [
            'ok' => false,
            'mensaje' => (string)$mensaje,
            'codigo_http' => (int)$codigoHttp
        ];
    }
}
