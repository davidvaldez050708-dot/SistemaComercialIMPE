<?php
require_once __DIR__ . '/FormularioEjecutivasPerfiles.php';

/**
 * Validador del PILOTO de Titulación - Ejecutivas.
 * No consulta RENAPO ni determina identidad oficial; solo verifica captura.
 * No guarda ni registra información personal.
 */
final class FormularioEjecutivasValidacion
{
    private const CURP_ENTIDADES = [
        'AS','BC','BS','CC','CL','CM','CS','CH','DF','DG','GT','GR','HG',
        'JC','MC','MN','MS','NT','NL','OC','PL','QT','QR','SP','SL','SR',
        'TC','TS','TL','VZ','YN','ZS','NE'
    ];

    public static function validar(array $entrada): array
    {
        $errores = [];
        $avisos = [];
        $valor = static function (string $clave) use ($entrada): string {
            $dato = $entrada[$clave] ?? '';
            if (!is_scalar($dato)) {
                return '';
            }
            return trim((string)$dato);
        };

        foreach (['nombre'=>'Nombre', 'apellidos'=>'Apellidos'] as $campo=>$etiqueta) {
            $contenido = $valor($campo);
            if (!self::nombreValido($contenido, $campo === 'nombre' ? 80 : 120)) {
                $errores[$campo] = $etiqueta . ': escribe los datos completos, tal como aparecen en tu constancia oficial, sin cifras ni símbolos extraños.';
            }
        }
        $nacimiento = self::fecha($valor('fecha_nacimiento'));
        $hoy = new DateTimeImmutable('today', new DateTimeZone('America/Mexico_City'));
        if ($nacimiento === null || $nacimiento > $hoy ||
            $nacimiento < new DateTimeImmutable('1900-01-01')) {
            $errores['fecha_nacimiento'] = 'Selecciona una fecha de nacimiento válida y no futura.';
        }
        $sexo = strtoupper($valor('genero'));
        if (!in_array($sexo, ['H','M'], true)) {
            $errores['genero'] = 'Selecciona el sexo registrado en tu constancia CURP.';
        }
        $curp = strtoupper($valor('curp'));
        $curpError = self::errorCurp($curp, $valor('fecha_nacimiento'), $sexo);
        if ($curpError !== null) {
            $errores['curp'] = $curpError;
        } else {
            // El prefijo de la CURP depende de reglas oficiales y excepciones;
            // una discrepancia genera revisión, nunca "corrección" automática.
            $apellidoInicial = self::primeraInicial($valor('apellidos'));
            if ($apellidoInicial !== '' && $curp[0] !== $apellidoInicial) {
                $avisos[] = 'Revisa la escritura de los apellidos contra la constancia CURP: las iniciales parecen diferentes.';
            }
        }

        $correo = $valor('correo');
        $correo2 = $valor('correo_confirmacion');
        if (strlen($correo) > 190 || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $errores['correo'] = 'Escribe un correo electrónico válido.';
        }
        if (strcasecmp($correo, $correo2) !== 0 || $correo2 === '') {
            $errores['correo_confirmacion'] = 'Los dos correos electrónicos deben coincidir.';
        }
        $movil = $valor('movil');
        if (!preg_match('/^[0-9]{10}$/D', $movil)) {
            $errores['movil'] = 'El móvil debe tener exactamente 10 dígitos.';
        }
        if ($valor('movil_confirmacion') !== $movil || $movil === '') {
            $errores['movil_confirmacion'] = 'Los números de móvil no coinciden.';
        }

        foreach ([
            'nombre_padres'=>[7,250,'Nombres de los padres'],
            'calle'=>[2,190,'Calle'],
            'numero'=>[1,20,'Número exterior o S/N'],
            'ciudad'=>[2,120,'Ciudad'],
            'municipio'=>[2,120,'Municipio'],
            'entidad_federativa'=>[2,90,'Entidad federativa'],
            'empresa_labora'=>[2,180,'Empresa donde labora'],
        ] as $campo=>$regla) {
            [$minimo, $maximo, $etiqueta] = $regla;
            $contenido = $valor($campo);
            if (mb_strlen($contenido, 'UTF-8') < $minimo ||
                mb_strlen($contenido, 'UTF-8') > $maximo ||
                preg_match('/[<>{}\x00-\x1F]/u', $contenido)) {
                $errores[$campo] = $etiqueta . ': revisa el dato obligatorio.';
            }
        }
        if (!preg_match('/^[0-9]{5}$/D', $valor('codigo_postal'))) {
            $errores['codigo_postal'] = 'Ingresa un código postal mexicano de cinco dígitos.';
        }
        if (mb_strlen($valor('cargo_puesto'), 'UTF-8') > 160) {
            $errores['cargo_puesto'] = 'El cargo o puesto excede 160 caracteres.';
        }
        if (!FormularioEjecutivasPerfiles::admite($valor('perfil_evaluacion'))) {
            $errores['perfil_evaluacion'] = 'Selecciona uno de los diez perfiles oficiales de Ejecutivas.';
        }
        $inscripcion = self::fecha($valor('fecha_inscripcion'));
        if ($inscripcion === null || $inscripcion > $hoy ||
            ($nacimiento !== null && $inscripcion < $nacimiento)) {
            $errores['fecha_inscripcion'] = 'Revisa la fecha real de inscripción; no puede estar en el futuro.';
        }

        // En esta etapa no se recibe ni persiste el comprobante de pago.
        // La validación del archivo y los documentos legales se implementará
        // únicamente junto con almacenamiento seguro y textos aprobados.
        $avisos[] = 'La validación de captura NO confirma identidad con RENAPO, domicilio, pago ni aceptación contractual.';
        return [
            'ok' => $errores === [],
            'errores' => $errores,
            'avisos' => $avisos,
            'curp_verificada_en_renapo' => false,
            'datos_guardados' => false
        ];
    }

    public static function errorCurp(string $curp, string $fecha, string $sexo): ?string
    {
        $curp = strtoupper(trim($curp));
        if (!preg_match('/^[A-Z][AEIOUX][A-Z]{2}[0-9]{6}[HM][A-Z]{2}[B-DF-HJ-NP-TV-Z]{3}[A-Z0-9][0-9]$/D', $curp)) {
            return 'La CURP debe tener 18 caracteres y respetar su estructura oficial.';
        }
        if (!in_array(substr($curp, 11, 2), self::CURP_ENTIDADES, true)) {
            return 'El código de entidad de nacimiento de la CURP no es válido.';
        }
        $mes = (int)substr($curp, 6, 2);
        $dia = (int)substr($curp, 8, 2);
        $fechaReal = self::fecha($fecha);
        if (!checkdate($mes, $dia, $fechaReal ? (int)$fechaReal->format('Y') : 2000)) {
            return 'La fecha codificada en la CURP no existe.';
        }
        if ($fechaReal !== null && $fechaReal->format('ymd') !== substr($curp, 4, 6)) {
            return 'La fecha de nacimiento no coincide con los seis dígitos de tu CURP.';
        }
        if (in_array($sexo, ['H','M'], true) && $curp[10] !== $sexo) {
            return 'El sexo capturado no coincide con la CURP.';
        }
        if (!hash_equals(substr($curp, 17, 1), (string)self::digitoVerificador($curp))) {
            return 'El dígito verificador de la CURP es incorrecto. Revísala con tu constancia.';
        }
        return null;
    }

    public static function digitoVerificador(string $curp): int
    {
        $caracteres = preg_split('//u', '0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ', -1, PREG_SPLIT_NO_EMPTY);
        $valores = array_flip($caracteres ?: []);
        $curp = strtoupper(trim($curp));
        if (strlen($curp) < 17) {
            return -1;
        }
        $suma = 0;
        for ($i=0; $i<17; ++$i) {
            if (!array_key_exists($curp[$i], $valores)) {
                return -1;
            }
            $suma += $valores[$curp[$i]] * (18 - $i);
        }
        return (10 - ($suma % 10)) % 10;
    }

    private static function primeraInicial(string $texto): string
    {
        $texto = mb_strtoupper(trim($texto), 'UTF-8');
        $letra = mb_substr($texto, 0, 1, 'UTF-8');
        $reemplazos = ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'X'];
        return $reemplazos[$letra] ?? $letra;
    }

    private static function nombreValido(string $nombre, int $maximo): bool
    {
        $n = mb_strlen($nombre, 'UTF-8');
        return $n >= 2 && $n <= $maximo &&
            preg_match("/^[\p{L}\p{M}]+(?:[ .'\x{2019}-][\p{L}\p{M}]+)*$/uD", $nombre) === 1;
    }

    private static function fecha(string $fecha): ?DateTimeImmutable
    {
        $fecha = trim($fecha);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $fecha)) {
            return null;
        }
        $valor = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        return $valor && $valor->format('Y-m-d') === $fecha ? $valor : null;
    }
}
