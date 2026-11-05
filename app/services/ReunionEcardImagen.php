<?php

/** Imagen de reemplazo de un solo envío; nunca acepta rutas del cliente. */
final class ReunionEcardImagen
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_PIXELS = 12000000;

    public static function preparar($archivo, bool $esperada = false): ?array
    {
        if ($archivo === null || (is_array($archivo) && ($archivo['error'] ?? null) === UPLOAD_ERR_NO_FILE)) {
            if ($esperada) throw new DomainException('Vuelve a seleccionar la imagen de la Ecard o utiliza la predeterminada.');
            return null;
        }
        if (!is_array($archivo) || !isset($archivo['error']) || !is_int($archivo['error']) ||
            $archivo['error'] !== UPLOAD_ERR_OK) {
            throw new DomainException('No se pudo recibir la imagen. Selecciona un archivo JPG o PNG de hasta 5 MB.');
        }
        $tmp = $archivo['tmp_name'] ?? null;
        if (!is_string($tmp) || !is_uploaded_file($tmp) || !is_file($tmp)) {
            throw new DomainException('La imagen recibida no es válida. Vuelve a seleccionarla.');
        }
        $size = filesize($tmp);
        if (!$size || $size > self::MAX_BYTES) throw new DomainException('La imagen de la Ecard debe pesar hasta 5 MB.');
        $info = @getimagesize($tmp);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!$info || !in_array($mime, ['image/jpeg', 'image/png'], true) || ($info['mime'] ?? '') !== $mime) {
            throw new DomainException('Selecciona una imagen JPG o PNG válida para la Ecard.');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > 6000 || $info[1] > 6000 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new DomainException('La imagen es demasiado grande. Utiliza hasta 12 megapíxeles y 6,000 píxeles por lado.');
        }
        if (!function_exists('imagecreatefromstring')) throw new RuntimeException('No fue posible preparar la Ecard. Solicita revisar la extensión GD.');
        $image = @imagecreatefromstring(file_get_contents($tmp));
        if ($image === false) throw new DomainException('No fue posible leer la imagen de la Ecard. Selecciona otra imagen.');
        $dir = dirname(__DIR__, 2) . '/storage/mail/ecards/temporales';
        $path = false;
        try {
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('No fue posible preparar la Ecard.');
            $path = tempnam($dir, 'ecard-');
            if ($path === false) throw new RuntimeException('No fue posible preparar la Ecard.');
            chmod($path, 0600);
            // Recodificar elimina metadatos y contenido añadido al archivo original.
            $png = $mime === 'image/png';
            if ($png) { imagealphablending($image, false); imagesavealpha($image, true); }
            $ok = $png ? imagepng($image, $path) : imagejpeg($image, $path, 90);
            if (!$ok || !filesize($path) || filesize($path) > self::MAX_BYTES) throw new DomainException('No fue posible preparar la imagen dentro del límite de 5 MB. Selecciona otra imagen.');
            return ['ok'=>true, 'ruta'=>$path, 'archivo'=>'ecard-personalizada.'.($png?'png':'jpg'),
                'mime'=>$mime, 'cid'=>'ecard-reunion', 'template'=>'personalizada', 'ponente'=>'Ecard personalizada'];
        } catch (Throwable $error) {
            if (is_string($path) && is_file($path)) unlink($path);
            throw $error;
        } finally {
            imagedestroy($image);
        }
    }
}
