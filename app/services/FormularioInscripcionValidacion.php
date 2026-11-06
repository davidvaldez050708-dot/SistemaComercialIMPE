<?php
require_once __DIR__ . '/FormularioInscripcionProgramas.php';
require_once __DIR__ . '/FormularioEjecutivasValidacion.php';

/** Reglas compartidas por la captura interna y el enlace público. */
final class FormularioInscripcionValidacion
{
    public static function perfiles(string $slug): array
    {
        if ($slug === 'titulacion-ejecutivas') return FormularioEjecutivasPerfiles::todos();
        // Catálogo publicado por la Fundación, consultado el 05/11/2026.
        // https://www.fundacionredmex.org/titulacion-experiencia.html
        if ($slug === 'titulacion-experiencia-laboral') return [
            'Ingeniería industrial A286', 'Licenciatura en derecho A286',
            'Licenciatura en contaduría A286', 'Licenciatura en educación preescolar A286',
            'Ingeniería computacional A286', 'Licenciatura en ciencias de la comunicación A286',
            'Licenciatura en mercadotecnia A286',
            'Licenciatura en ciencias políticas y administración pública A286'
        ];
        $programa = FormularioInscripcionProgramas::porSlug($slug);
        return $programa ? [$programa['nombre']] : [];
    }

    public static function legales(string $slug): array
    {
        $dir = __DIR__ . '/../resources/inscripcion/';
        $terminos = $slug === 'titulacion-ejecutivas'
            ? (string)file_get_contents($dir . 'terminos-ejecutivas.txt') : '';
        return ['terminos'=>$terminos, 'privacidad'=>(string)file_get_contents($dir . 'privacidad.txt')];
    }

    public static function validar(array $entrada, string $slug): array
    {
        $programa = FormularioInscripcionProgramas::porSlug($slug);
        if (!$programa) throw new DomainException('Programa de inscripción no disponible.', 404);
        $perfiles = self::perfiles($slug);
        $perfil = $entrada['perfil_evaluacion'] ?? null;
        $copia = $entrada;
        $copia['perfil_evaluacion'] = FormularioEjecutivasPerfiles::todos()[0];
        $resultado = FormularioEjecutivasValidacion::validar($copia);
        if (!is_string($perfil) || !in_array($perfil, $perfiles, true)) {
            $resultado['errores']['perfil_evaluacion'] = 'Selecciona un perfil del programa elegido.';
        }
        $curp = strtoupper(is_string($entrada['curp'] ?? null) ? trim($entrada['curp']) : '');
        $fecha = is_string($entrada['fecha_nacimiento'] ?? null) ? $entrada['fecha_nacimiento'] : '';
        if (strlen($curp) === 18 && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fecha)) {
            $siglo = ctype_digit($curp[16]) ? 1900 : 2000;
            if ((int)substr($fecha, 0, 4) !== $siglo + (int)substr($curp, 4, 2)) {
                $resultado['errores']['curp'] = 'El año de nacimiento no coincide con el siglo indicado por la CURP.';
            }
        }
        foreach (['acepta_terminos','acepta_privacidad'] as $campo) {
            if (($entrada[$campo] ?? null) !== '1') $resultado['errores'][$campo] = 'Debes leer y aceptar este documento.';
        }
        if (self::legales($slug)['terminos'] === '') {
            $resultado['errores']['programa_slug'] = 'Este programa está pendiente de sus términos de inscripción.';
        }
        $resultado['ok'] = $resultado['errores'] === [];
        return $resultado;
    }

    public static function comprobante(array $archivo): array
    {
        if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
            !is_string($archivo['tmp_name'] ?? null) || !is_uploaded_file($archivo['tmp_name'])) {
            throw new DomainException('Selecciona un comprobante de pago válido.', 422);
        }
        $ruta = $archivo['tmp_name'];
        $tamano = filesize($ruta);
        if (!$tamano || $tamano > 8 * 1024 * 1024) throw new DomainException('El comprobante debe pesar hasta 8 MB.', 422);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($ruta);
        $extensiones = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'];
        if (!isset($extensiones[$mime])) throw new DomainException('Usa un comprobante PDF, JPG o PNG.', 422);
        if ($mime === 'application/pdf') {
            $f = fopen($ruta, 'rb'); $cabecera = fread($f, 5); fclose($f);
            if ($cabecera !== '%PDF-') throw new DomainException('El comprobante PDF no es válido.', 422);
        } else {
            $imagen = @getimagesize($ruta);
            if (!$imagen || $imagen[0] * $imagen[1] > 40000000 || $imagen['mime'] !== $mime) {
                throw new DomainException('La imagen del comprobante no es válida o es demasiado grande.', 422);
            }
        }
        return ['mime'=>$mime,'extension'=>$extensiones[$mime],'tamano'=>$tamano,
            'sha256'=>hash_file('sha256',$ruta)];
    }
}
