<?php

require_once __DIR__ . '/../../config/db_connection.php';

class EcardReunionService
{
    public const TEMPLATE_SERGIO = 'SERGIO';
    public const TEMPLATE_MANUEL = 'MANUEL';
    public const CID = 'ecard-reunion';

    private $connection;
    private $rootPath;

    public function __construct($connection = null)
    {
        $this->rootPath = dirname(__DIR__, 2);
        $this->connection = $connection;

        if (!$this->connection) {
            $database = new Database();
            $this->connection = $database->connect();
        }

        $config = $this->rootPath . DIRECTORY_SEPARATOR .
            'config' . DIRECTORY_SEPARATOR . 'ecard_reunion_config.php';

        if (is_file($config)) {
            require_once $config;
        }
    }

    public function metadatos(array $reunion)
    {
        $template = $this->resolverTemplate($reunion);
        $esSergio = $template === self::TEMPLATE_SERGIO;

        return [
            'template' => strtolower($template),
            'ponente' => $esSergio
                ? 'Sergio López Porcayo'
                : 'Mtro. Manuel Porcayo',
            'cargo' => $esSergio
                ? 'Rector Universidad IMPE'
                : 'Presidente',
            'equipo_yulissa' => $esSergio,
            'evento' => $this->resolverEvento($reunion),
            'modalidad' => $this->etiquetaModalidad($reunion['modalidad'] ?? ''),
            'enlace' => trim((string)($reunion['zoom_url'] ?? ''))
        ];
    }

    public function generar(array $reunion)
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            return $this->error(
                'No fue posible generar la Ecard porque la extensión GD de PHP no está disponible.'
            );
        }

        $reunionId = (int)($reunion['id'] ?? 0);
        if ($reunionId <= 0) {
            return $this->error('No fue posible identificar la reunión para generar la Ecard.');
        }

        $fechaRaw = trim((string)($reunion['fecha_propuesta'] ?? ''));
        if ($fechaRaw === '') {
            return $this->error('La reunión no tiene fecha y hora para generar la Ecard.');
        }

        try {
            $fecha = new DateTime($fechaRaw);
        } catch (Throwable $error) {
            return $this->error('La fecha de la reunión no es válida para generar la Ecard.');
        }

        $meta = $this->metadatos($reunion);
        $evento = trim((string)$meta['evento']);
        $modalidad = trim((string)$meta['modalidad']);
        $enlace = trim((string)$meta['enlace']);

        if ($evento === '') {
            $evento = 'Reunión de vinculación';
        }

        $directorio = $this->rootPath . DIRECTORY_SEPARATOR .
            'storage' . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR . 'ecards';

        if (!is_dir($directorio) && !@mkdir($directorio, 0775, true) && !is_dir($directorio)) {
            return $this->error('No fue posible preparar el directorio de Ecards.');
        }

        $firma = sha1(implode('|', [
            $meta['template'],
            $evento,
            $fecha->format('Y-m-d H:i:s'),
            $modalidad,
            $enlace,
            trim((string)($reunion['ubicacion'] ?? '')),
            trim((string)($reunion['nombre_entidad'] ?? ''))
        ]));
        $nombreArchivo = 'reunion_' . $reunionId . '_' . substr($firma, 0, 12) . '.jpg';
        $ruta = $directorio . DIRECTORY_SEPARATOR . $nombreArchivo;

        if (!is_file($ruta)) {
            $resultado = $this->crearImagen(
                $ruta,
                $meta,
                $fecha,
                $evento,
                $modalidad,
                $enlace
            );

            if (!($resultado['ok'] ?? false)) {
                return $resultado;
            }

            $this->limpiarVersionesAnteriores($directorio, $reunionId, $ruta);
        }

        return [
            'ok' => true,
            'ruta' => $ruta,
            'archivo' => $nombreArchivo,
            'mime' => 'image/jpeg',
            'cid' => self::CID,
            'template' => $meta['template'],
            'ponente' => $meta['ponente'],
            'cargo' => $meta['cargo'],
            'evento' => $evento,
            'modalidad' => $modalidad,
            'enlace' => $enlace
        ];
    }

    private function limpiarVersionesAnteriores($directorio, $reunionId, $rutaActual)
    {
        $patron = rtrim((string)$directorio, DIRECTORY_SEPARATOR) .
            DIRECTORY_SEPARATOR . 'reunion_' . (int)$reunionId . '_*.jpg';
        $actual = realpath((string)$rutaActual);

        foreach (glob($patron) ?: [] as $ruta) {
            $real = realpath($ruta);

            if (
                $real !== false &&
                $actual !== false &&
                $real !== $actual &&
                is_file($real)
            ) {
                @unlink($real);
            }
        }
    }

    private function resolverTemplate(array $reunion)
    {
        $cuentaClaveId = (int)($reunion['cuenta_clave_id'] ?? 0);
        $cuentaClaveCorreo = strtolower(trim((string)($reunion['cuenta_clave_correo'] ?? '')));
        $cuentaClaveNombre = $this->normalizarTexto(
            (string)($reunion['cuenta_clave_nombre'] ?? '')
        );

        $idConfigurado = (int)$this->config('ECARD_REUNION_SERGIO_CUENTA_CLAVE_ID', '0');
        $correoConfigurado = strtolower(
            $this->config('ECARD_REUNION_SERGIO_CUENTA_CLAVE_EMAIL', '')
        );

        if ($idConfigurado > 0 && $cuentaClaveId === $idConfigurado) {
            return self::TEMPLATE_SERGIO;
        }

        if (
            $correoConfigurado !== '' &&
            $cuentaClaveCorreo !== '' &&
            $cuentaClaveCorreo === $correoConfigurado
        ) {
            return self::TEMPLATE_SERGIO;
        }

        // Respaldo funcional mientras se fija el ID/correo de Yulissa en config.
        if (
            $cuentaClaveNombre !== '' &&
            (
                strpos($cuentaClaveNombre, 'yulissa') !== false ||
                strpos($cuentaClaveNombre, 'yulisa') !== false
            )
        ) {
            return self::TEMPLATE_SERGIO;
        }

        return self::TEMPLATE_MANUEL;
    }

    private function resolverEvento(array $reunion)
    {
        $ubicacion = trim((string)($reunion['ubicacion'] ?? ''));
        $entidad = trim((string)($reunion['nombre_entidad'] ?? ''));

        if ($ubicacion !== '') {
            return $ubicacion;
        }

        return $entidad !== '' ? $entidad : 'Reunión de vinculación';
    }

    private function etiquetaModalidad($valor)
    {
        $modalidad = strtoupper(trim((string)$valor));

        $mapa = [
            'VIRTUAL' => 'Virtual',
            'PRESENCIAL' => 'Presencial',
            'HIBRIDA' => 'Híbrida'
        ];

        return $mapa[$modalidad] ?? ($modalidad !== '' ? ucfirst(strtolower($modalidad)) : 'Por confirmar');
    }

    private function crearImagen($ruta, array $meta, DateTime $fecha, $evento, $modalidad, $enlace)
    {
        $ancho = 900;
        $alto = 1050;
        $imagen = imagecreatetruecolor($ancho, $alto);

        if (!$imagen) {
            return $this->error('No fue posible crear el lienzo de la Ecard.');
        }

        $blanco = imagecolorallocate($imagen, 255, 255, 255);
        $navy = imagecolorallocate($imagen, 4, 42, 78);
        $navyProfundo = imagecolorallocate($imagen, 3, 30, 56);
        $azul = imagecolorallocate($imagen, 17, 75, 124);
        $teal = imagecolorallocate($imagen, 13, 153, 170);
        $tealOscuro = imagecolorallocate($imagen, 8, 119, 137);
        $grisFondo = imagecolorallocate($imagen, 239, 244, 248);
        $grisTexto = imagecolorallocate($imagen, 83, 99, 117);
        $grisBorde = imagecolorallocate($imagen, 210, 221, 231);

        imagefilledrectangle($imagen, 0, 0, $ancho, $alto, $blanco);
        imagefilledrectangle($imagen, 0, 0, $ancho, 425, $navyProfundo);

        imagefilledpolygon($imagen, [
            545, 0,
            900, 0,
            900, 425,
            690, 425
        ], 4, $tealOscuro);

        imagefilledpolygon($imagen, [
            670, 0,
            900, 0,
            900, 425,
            820, 425
        ], 4, $teal);

        for ($y = 35; $y < 410; $y += 55) {
            imageline($imagen, 35, $y, 855, $y + 80, $azul);
        }

        $fuenteNormal = $this->resolverFuente(false);
        $fuenteBold = $this->resolverFuente(true);
        $esSergio = strtolower((string)$meta['template']) === strtolower(self::TEMPLATE_SERGIO);

        $titulo = $esSergio ? 'Reunión Informativa' : 'Reunión';
        $this->texto($imagen, $titulo, 54, 54, 85, $blanco, $fuenteNormal);
        imageline($imagen, 55, 112, 845, 112, $blanco);

        if ($esSergio) {
            $this->textoCentrado($imagen, 'ACUERDO 286', 38, 155, $blanco, $fuenteBold);
            $this->textoCentrado($imagen, 'Te invitamos a nuestra reunión informativa', 22, 210, $blanco, $fuenteNormal);
        } else {
            $this->textoCentrado($imagen, 'Comprometidos con el', 22, 180, $blanco, $fuenteNormal);
            $this->textoCentrado($imagen, 'crecimiento profesional.', 26, 215, $blanco, $fuenteBold);
        }

        $this->textoCentrado($imagen, 'SEDE / EVENTO', 15, 290, $blanco, $fuenteBold);
        $lineasEvento = $this->envolverTexto($evento, 650, 30, $fuenteBold, 2);
        $yEvento = count($lineasEvento) > 1 ? 323 : 338;
        foreach ($lineasEvento as $linea) {
            $this->textoCentrado($imagen, $linea, 30, $yEvento, $blanco, $fuenteBold);
            $yEvento += 38;
        }

        imagefilledrectangle($imagen, 55, 450, 845, 725, $grisFondo);
        imagerectangle($imagen, 55, 450, 845, 725, $grisBorde);

        $this->texto($imagen, 'FECHA', 14, 85, 487, $grisTexto, $fuenteBold);
        $this->texto(
            $imagen,
            $this->fechaLarga($fecha),
            29,
            85,
            525,
            $navy,
            $fuenteBold
        );

        $this->texto($imagen, 'HORA', 14, 85, 584, $grisTexto, $fuenteBold);
        $this->texto($imagen, $this->hora12($fecha), 26, 85, 619, $navy, $fuenteNormal);

        $this->texto($imagen, 'MODALIDAD', 14, 520, 487, $grisTexto, $fuenteBold);
        $this->texto($imagen, $modalidad, 26, 520, 525, $navy, $fuenteBold);

        $this->texto($imagen, 'LINK / ACCESO', 14, 520, 584, $grisTexto, $fuenteBold);
        $acceso = $enlace !== ''
            ? $enlace
            : 'No aplica · reunión presencial';
        $lineasAcceso = $this->envolverTexto($acceso, 290, 15, $fuenteNormal, 4);
        $yAcceso = 616;
        foreach ($lineasAcceso as $linea) {
            $this->texto($imagen, $linea, 15, 520, $yAcceso, $navy, $fuenteNormal);
            $yAcceso += 23;
        }

        $this->dibujarPonente(
            $imagen,
            (string)$meta['template'],
            (string)$meta['ponente'],
            (string)$meta['cargo'],
            $navy,
            $grisTexto,
            $grisFondo,
            $fuenteNormal,
            $fuenteBold
        );

        imagefilledrectangle($imagen, 0, 925, 900, 1050, $navy);
        $this->texto($imagen, 'FUNDACIÓN', 16, 75, 965, $blanco, $fuenteBold);
        $this->texto($imagen, 'RED EDUCATIVA', 21, 75, 992, $blanco, $fuenteBold);
        $this->texto($imagen, 'MÉXICO', 23, 75, 1023, $blanco, $fuenteBold);

        if ($esSergio) {
            imageline($imagen, 345, 952, 345, 1025, $blanco);
            $this->texto($imagen, 'UNIVERSIDAD IMPE', 20, 385, 995, $blanco, $fuenteBold);
        }

        $this->texto($imagen, 'EDUCACIÓN', 22, 680, 995, $blanco, $fuenteBold);

        $guardado = imagejpeg($imagen, $ruta, 91);
        imagedestroy($imagen);

        if (!$guardado || !is_file($ruta)) {
            return $this->error('No fue posible guardar la Ecard de la reunión.');
        }

        return ['ok' => true];
    }

    private function dibujarPonente(
        $imagen,
        $template,
        $ponente,
        $cargo,
        $navy,
        $grisTexto,
        $grisFondo,
        $fuenteNormal,
        $fuenteBold
    ) {
        $foto = $this->buscarFotoPonente($template);
        $x = 92;
        $y = 758;
        $tam = 128;

        imagefilledellipse($imagen, $x + 64, $y + 64, 136, 136, $grisFondo);

        if ($foto !== '') {
            $origen = $this->cargarImagen($foto);
            if ($origen) {
                $w = imagesx($origen);
                $h = imagesy($origen);
                $lado = min($w, $h);
                $sx = (int)(($w - $lado) / 2);
                $sy = (int)(($h - $lado) / 2);
                imagecopyresampled(
                    $imagen,
                    $origen,
                    $x,
                    $y,
                    $sx,
                    $sy,
                    $tam,
                    $tam,
                    $lado,
                    $lado
                );
                imagedestroy($origen);
            }
        } else {
            imagefilledellipse($imagen, $x + 64, $y + 64, 118, 118, $navy);
            $iniciales = strtoupper(substr(trim($ponente), 0, 1) . 'P');
            $this->textoCentradoEnCaja(
                $imagen,
                $iniciales,
                29,
                $x,
                $y + 48,
                $tam,
                imagecolorallocate($imagen, 255, 255, 255),
                $fuenteBold
            );
        }

        $this->texto($imagen, $ponente, 31, 255, 798, $navy, $fuenteBold);
        $this->texto($imagen, $cargo, 22, 255, 838, $grisTexto, $fuenteNormal);
        $this->texto($imagen, 'Reunión institucional', 15, 255, 875, $grisTexto, $fuenteBold);
    }

    private function buscarFotoPonente($template)
    {
        if (!$this->connection) {
            return '';
        }

        $esSergio = strtoupper((string)$template) === self::TEMPLATE_SERGIO;
        $nombre = $esSergio ? '%sergio%' : '%manuel%';
        $apellido = '%porcayo%';

        try {
            $sql = "SELECT foto_perfil
                    FROM usuarios
                    WHERE estado = 1
                      AND LOWER(CONCAT(COALESCE(nombre,''),' ',COALESCE(apellidos,''))) LIKE ?
                      AND LOWER(CONCAT(COALESCE(nombre,''),' ',COALESCE(apellidos,''))) LIKE ?
                      AND foto_perfil IS NOT NULL
                      AND TRIM(foto_perfil) <> ''
                    ORDER BY id ASC
                    LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('ss', $nombre, $apellido);
            $stmt->execute();
            $fila = $stmt->get_result()->fetch_assoc();
            $relativa = trim((string)($fila['foto_perfil'] ?? ''));

            if ($relativa === '') {
                return '';
            }

            $ruta = $this->rootPath . DIRECTORY_SEPARATOR .
                ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativa), DIRECTORY_SEPARATOR);

            return is_file($ruta) ? $ruta : '';
        } catch (Throwable $error) {
            return '';
        }
    }

    private function cargarImagen($ruta)
    {
        $info = @getimagesize($ruta);
        $mime = strtolower((string)($info['mime'] ?? ''));

        if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
            return @imagecreatefromjpeg($ruta);
        }
        if ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
            return @imagecreatefrompng($ruta);
        }
        if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            return @imagecreatefromwebp($ruta);
        }

        return false;
    }

    private function fechaLarga(DateTime $fecha)
    {
        $dias = [
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
            7 => 'Domingo'
        ];
        $meses = [
            1 => 'enero',
            2 => 'febrero',
            3 => 'marzo',
            4 => 'abril',
            5 => 'mayo',
            6 => 'junio',
            7 => 'julio',
            8 => 'agosto',
            9 => 'septiembre',
            10 => 'octubre',
            11 => 'noviembre',
            12 => 'diciembre'
        ];

        return ($dias[(int)$fecha->format('N')] ?? '') . ' ' .
            $fecha->format('j') . ' de ' .
            ($meses[(int)$fecha->format('n')] ?? '') . ', ' .
            $fecha->format('Y');
    }

    private function hora12(DateTime $fecha)
    {
        $hora = (int)$fecha->format('G');
        $minuto = $fecha->format('i');
        $periodo = $hora < 12 ? 'A. M.' : 'P. M.';
        $hora12 = $hora % 12;
        if ($hora12 === 0) {
            $hora12 = 12;
        }

        return $hora12 . ':' . $minuto . ' ' . $periodo;
    }

    private function resolverFuente($bold)
    {
        $candidatas = $bold
            ? [
                'C:/Windows/Fonts/arialbd.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf'
            ]
            : [
                'C:/Windows/Fonts/arial.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/dejavu/DejaVuSans.ttf'
            ];

        foreach ($candidatas as $ruta) {
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        return '';
    }

    private function texto($imagen, $texto, $tamano, $x, $y, $color, $fuente)
    {
        $texto = (string)$texto;

        if ($fuente !== '' && function_exists('imagettftext')) {
            imagettftext($imagen, (float)$tamano, 0, (int)$x, (int)$y, $color, $fuente, $texto);
            return;
        }

        imagestring(
            $imagen,
            5,
            (int)$x,
            max(0, (int)$y - 16),
            $this->textoAscii($texto),
            $color
        );
    }

    private function textoCentrado($imagen, $texto, $tamano, $y, $color, $fuente)
    {
        $ancho = $this->medirTexto($texto, $tamano, $fuente);
        $x = max(20, (int)((imagesx($imagen) - $ancho) / 2));
        $this->texto($imagen, $texto, $tamano, $x, $y, $color, $fuente);
    }

    private function textoCentradoEnCaja($imagen, $texto, $tamano, $x, $y, $ancho, $color, $fuente)
    {
        $medida = $this->medirTexto($texto, $tamano, $fuente);
        $destinoX = $x + max(0, (int)(($ancho - $medida) / 2));
        $this->texto($imagen, $texto, $tamano, $destinoX, $y, $color, $fuente);
    }

    private function medirTexto($texto, $tamano, $fuente)
    {
        if ($fuente !== '' && function_exists('imagettfbbox')) {
            $box = imagettfbbox((float)$tamano, 0, $fuente, (string)$texto);
            if (is_array($box)) {
                return abs((int)$box[2] - (int)$box[0]);
            }
        }

        return strlen($this->textoAscii((string)$texto)) * 10;
    }

    private function envolverTexto($texto, $maxAncho, $tamano, $fuente, $maxLineas)
    {
        $texto = preg_replace('/\s+/u', ' ', trim((string)$texto));
        if ($texto === '') {
            return [''];
        }

        $palabras = preg_split('/\s+/u', $texto);
        $lineas = [];
        $actual = '';

        foreach ($palabras as $palabra) {
            if ($this->medirTexto($palabra, $tamano, $fuente) > $maxAncho) {
                if ($actual !== '') {
                    $lineas[] = $actual;
                    $actual = '';
                }

                $fragmentos = $this->fragmentarPalabra(
                    $palabra,
                    $maxAncho,
                    $tamano,
                    $fuente
                );

                foreach ($fragmentos as $fragmento) {
                    if (count($lineas) >= $maxLineas - 1) {
                        $actual = $fragmento;
                        break 2;
                    }

                    $lineas[] = $fragmento;
                }

                continue;
            }

            $candidata = $actual === '' ? $palabra : $actual . ' ' . $palabra;

            if ($actual !== '' && $this->medirTexto($candidata, $tamano, $fuente) > $maxAncho) {
                $lineas[] = $actual;
                $actual = $palabra;

                if (count($lineas) >= $maxLineas - 1) {
                    break;
                }
            } else {
                $actual = $candidata;
            }
        }

        if ($actual !== '' && count($lineas) < $maxLineas) {
            $lineas[] = $actual;
        }

        if (count($lineas) === $maxLineas) {
            $consumido = implode(' ', $lineas);
            if (mb_strlen($consumido, 'UTF-8') < mb_strlen($texto, 'UTF-8')) {
                $ultima = rtrim($lineas[$maxLineas - 1], " .");
                while (
                    $ultima !== '' &&
                    $this->medirTexto($ultima . '…', $tamano, $fuente) > $maxAncho
                ) {
                    $ultima = mb_substr($ultima, 0, -1, 'UTF-8');
                }
                $lineas[$maxLineas - 1] = $ultima . '…';
            }
        }

        return $lineas;
    }

    private function fragmentarPalabra($palabra, $maxAncho, $tamano, $fuente)
    {
        $caracteres = preg_split('//u', (string)$palabra, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($caracteres) || empty($caracteres)) {
            return [(string)$palabra];
        }

        $fragmentos = [];
        $actual = '';

        foreach ($caracteres as $caracter) {
            $candidata = $actual . $caracter;

            if (
                $actual !== '' &&
                $this->medirTexto($candidata, $tamano, $fuente) > $maxAncho
            ) {
                $fragmentos[] = $actual;
                $actual = $caracter;
            } else {
                $actual = $candidata;
            }
        }

        if ($actual !== '') {
            $fragmentos[] = $actual;
        }

        return $fragmentos;
    }

    private function normalizarTexto($texto)
    {
        $texto = mb_strtolower(trim((string)$texto), 'UTF-8');

        if (function_exists('iconv')) {
            $convertido = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
            if (is_string($convertido) && $convertido !== '') {
                $texto = $convertido;
            }
        }

        return preg_replace('/[^a-z0-9]+/', ' ', $texto);
    }

    private function textoAscii($texto)
    {
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$texto);
            if (is_string($ascii)) {
                return $ascii;
            }
        }

        return (string)$texto;
    }

    private function config($clave, $default = '')
    {
        if (defined($clave)) {
            return trim((string)constant($clave));
        }

        $valor = getenv($clave);
        return $valor !== false && trim((string)$valor) !== ''
            ? trim((string)$valor)
            : trim((string)$default);
    }

    private function error($mensaje)
    {
        return [
            'ok' => false,
            'mensaje' => (string)$mensaje
        ];
    }
}
