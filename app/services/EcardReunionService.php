<?php

require_once __DIR__ . '/../../config/db_connection.php';

class EcardReunionService
{
    public const TEMPLATE_SERGIO = 'SERGIO';
    public const TEMPLATE_MANUEL = 'MANUEL';
    public const CID = 'ecard-reunion';
    public const VERSION = '20260919-04';

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
            'sede' => $this->resolverSede($reunion),
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
        $sede = trim((string)$meta['sede']);
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
            self::VERSION,
            $meta['template'],
            $evento,
            $sede,
            $fecha->format('Y-m-d H:i:s'),
            $modalidad,
            $enlace
        ]));
        $nombreArchivo = 'reunion_' . $reunionId . '_' . substr($firma, 0, 12) . '.jpg';
        $ruta = $directorio . DIRECTORY_SEPARATOR . $nombreArchivo;

        if (!is_file($ruta)) {
            $resultado = $this->crearImagen(
                $ruta,
                $meta,
                $fecha,
                $evento,
                $sede,
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
            'sede' => $sede,
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
        $objetivo = trim((string)($reunion['objetivo'] ?? ''));
        $entidad = trim((string)($reunion['nombre_entidad'] ?? ''));

        if ($objetivo !== '') {
            return $objetivo;
        }

        return $entidad !== '' ? $entidad : 'Reunión de vinculación';
    }

    private function resolverSede(array $reunion)
    {
        $ubicacion = trim((string)($reunion['ubicacion'] ?? ''));
        if ($ubicacion !== '') {
            return $ubicacion;
        }

        $modalidad = strtoupper(trim((string)($reunion['modalidad'] ?? '')));
        if ($modalidad === 'VIRTUAL') {
            return 'En línea';
        }

        return 'Por confirmar';
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

    private function crearImagen($ruta, array $meta, DateTime $fecha, $evento, $sede, $modalidad, $enlace)
    {
        $template = strtoupper((string)($meta['template'] ?? ''));

        if ($template === self::TEMPLATE_MANUEL) {
            return $this->crearImagenManuelPlantilla(
                $ruta,
                $fecha,
                $evento,
                $sede,
                $modalidad,
                $enlace
            );
        }

        return $this->crearImagenGenerica(
            $ruta,
            $meta,
            $fecha,
            $evento,
            $sede,
            $modalidad,
            $enlace
        );
    }

    /**
     * Ecard Manuel:
     * parte exactamente de la pieza gráfica aprobada y únicamente
     * sobreescribe los datos variables de la reunión.
     */
    private function crearImagenManuelPlantilla(
        $ruta,
        DateTime $fecha,
        $evento,
        $sede,
        $modalidad,
        $enlace
    ) {
        $imagen = $this->cargarPlantillaManuel();

        if (!$imagen) {
            return $this->error(
                'No fue posible cargar la plantilla aprobada de la Ecard de Manuel.'
            );
        }

        $blanco = imagecolorallocate($imagen, 255, 255, 255);
        $navy = imagecolorallocate($imagen, 3, 43, 78);
        $fuenteNormal = $this->resolverFuente(false);
        $fuenteBold = $this->resolverFuente(true);

        $evento = preg_replace('/\\s+/u', ' ', trim((string)$evento));
        $sede = preg_replace('/\\s+/u', ' ', trim((string)$sede));
        $modalidad = trim((string)$modalidad);

        if ($evento === '') {
            $evento = 'Reunión de vinculación';
        }

        /*
         * La plantilla aprobada mide 600 x 606 px.
         * Solo se escriben los datos variables en las dos áreas libres:
         * encabezado superior y recuadro de fecha/hora.
         */
        $eventoMayus = mb_strtoupper($evento, 'UTF-8');
        $eventoAjustado = $this->envolverTextoAjustado(
            $eventoMayus,
            520,
            19,
            13,
            $fuenteBold,
            2
        );

        $tamanoEvento = (int)($eventoAjustado['tamano'] ?? 16);
        $lineasEvento = $eventoAjustado['lineas'] ?? [$eventoMayus];

        if (count($lineasEvento) > 1) {
            $yEvento = 108;
            foreach ($lineasEvento as $linea) {
                $this->textoCentrado(
                    $imagen,
                    $linea,
                    $tamanoEvento,
                    $yEvento,
                    $blanco,
                    $fuenteBold
                );
                $yEvento += $tamanoEvento + 7;
            }
        } else {
            $this->textoCentrado(
                $imagen,
                $lineasEvento[0],
                $tamanoEvento,
                116,
                $blanco,
                $fuenteBold
            );

            $sedeNormalizada = $this->normalizarTexto($sede);
            if (
                $sede !== '' &&
                $sedeNormalizada !== 'en linea' &&
                $sedeNormalizada !== 'por confirmar'
            ) {
                $tamanoSede = $this->tamanoParaAncho(
                    $sede,
                    500,
                    12,
                    9,
                    $fuenteNormal
                );
                $this->textoCentrado(
                    $imagen,
                    $sede,
                    $tamanoSede,
                    142,
                    $blanco,
                    $fuenteNormal
                );
            }
        }

        $fechaTexto = $this->fechaPlantillaManuel($fecha);
        $tamanoFecha = $this->tamanoParaAncho(
            $fechaTexto,
            470,
            22,
            15,
            $fuenteBold
        );
        $this->textoCentrado(
            $imagen,
            $fechaTexto,
            $tamanoFecha,
            382,
            $navy,
            $fuenteBold
        );

        $horaTexto = $this->horaPlantillaManuel($fecha);
        if ($modalidad !== '' && strtolower($modalidad) !== 'por confirmar') {
            $horaTexto .= ' · ' . mb_strtoupper($modalidad, 'UTF-8');
        }

        $tamanoHora = $this->tamanoParaAncho(
            $horaTexto,
            440,
            17,
            12,
            $fuenteNormal
        );
        $this->textoCentrado(
            $imagen,
            $horaTexto,
            $tamanoHora,
            414,
            $navy,
            $fuenteNormal
        );

        imageinterlace($imagen, true);
        $guardado = imagejpeg($imagen, $ruta, 96);
        imagedestroy($imagen);

        if (!$guardado || !is_file($ruta)) {
            return $this->error(
                'No fue posible guardar la Ecard de Manuel.'
            );
        }

        return ['ok' => true];
    }

    private function cargarPlantillaManuel()
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }

        $patron = $this->rootPath . DIRECTORY_SEPARATOR .
            'public' . DIRECTORY_SEPARATOR .
            'img' . DIRECTORY_SEPARATOR .
            'ecards' . DIRECTORY_SEPARATOR .
            'templates' . DIRECTORY_SEPARATOR .
            'manuel-exact.part*.b64';

        $partes = glob($patron) ?: [];
        if (count($partes) !== 17) {
            return false;
        }

        natsort($partes);
        $base64 = '';

        foreach ($partes as $parte) {
            $contenido = @file_get_contents($parte);
            if (!is_string($contenido) || trim($contenido) === '') {
                return false;
            }
            $base64 .= trim($contenido);
        }

        $binario = base64_decode($base64, true);
        if ($binario === false || $binario === '') {
            return false;
        }

        $imagen = @imagecreatefromstring($binario);
        if (!$imagen) {
            return false;
        }

        if (imagesx($imagen) !== 600 || imagesy($imagen) !== 606) {
            imagedestroy($imagen);
            return false;
        }

        return $imagen;
    }

    private function fechaPlantillaManuel(DateTime $fecha)
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
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre'
        ];

        return ($dias[(int)$fecha->format('N')] ?? '') . ' ' .
            $fecha->format('j') . ' de ' .
            ($meses[(int)$fecha->format('n')] ?? '') . ', ' .
            $fecha->format('Y');
    }

    private function horaPlantillaManuel(DateTime $fecha)
    {
        $hora = (int)$fecha->format('G');
        $minuto = $fecha->format('i');
        $periodo = $hora < 12 ? 'AM' : 'PM';
        $hora12 = $hora % 12;

        if ($hora12 === 0) {
            $hora12 = 12;
        }

        return $hora12 . ':' . $minuto . ' ' . $periodo;
    }

    private function tamanoParaAncho($texto, $maxAncho, $inicial, $minimo, $fuente)
    {
        for ($tamano = (int)$inicial; $tamano >= (int)$minimo; $tamano--) {
            if ($this->medirTexto($texto, $tamano, $fuente) <= $maxAncho) {
                return $tamano;
            }
        }

        return (int)$minimo;
    }

    private function envolverTextoAjustado(
        $texto,
        $maxAncho,
        $tamanoInicial,
        $tamanoMinimo,
        $fuente,
        $maxLineas
    ) {
        for (
            $tamano = (int)$tamanoInicial;
            $tamano >= (int)$tamanoMinimo;
            $tamano--
        ) {
            $lineas = $this->envolverTexto(
                $texto,
                $maxAncho,
                $tamano,
                $fuente,
                $maxLineas
            );

            $truncada = false;
            foreach ($lineas as $linea) {
                if (mb_substr((string)$linea, -1, 1, 'UTF-8') === '…') {
                    $truncada = true;
                    break;
                }
            }

            if (!$truncada) {
                return [
                    'tamano' => $tamano,
                    'lineas' => $lineas
                ];
            }
        }

        return [
            'tamano' => (int)$tamanoMinimo,
            'lineas' => $this->envolverTexto(
                $texto,
                $maxAncho,
                $tamanoMinimo,
                $fuente,
                $maxLineas
            )
        ];
    }

    private function crearImagenGenerica($ruta, array $meta, DateTime $fecha, $evento, $sede, $modalidad, $enlace)
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

        $this->rellenarPoligono($imagen, [
            545, 0,
            900, 0,
            900, 425,
            690, 425
        ], $tealOscuro);

        $this->rellenarPoligono($imagen, [
            670, 0,
            900, 0,
            900, 425,
            820, 425
        ], $teal);

        for ($y = 35; $y < 410; $y += 55) {
            imageline($imagen, 35, $y, 855, $y + 80, $azul);
        }

        $fuenteNormal = $this->resolverFuente(false);
        $fuenteBold = $this->resolverFuente(true);
        $esSergio = strtolower((string)$meta['template']) === strtolower(self::TEMPLATE_SERGIO);

        $titulo = $esSergio ? 'Reunión Informativa' : 'Reunión';
        $this->texto($imagen, $titulo, 52, 54, 82, $blanco, $fuenteNormal);
        imageline($imagen, 55, 112, 845, 112, $blanco);

        $this->textoCentrado($imagen, 'NOMBRE DEL EVENTO', 14, 153, $blanco, $fuenteBold);
        $lineasEvento = $this->envolverTexto($evento, 720, 30, $fuenteBold, 2);
        $yEvento = count($lineasEvento) > 1 ? 190 : 207;
        foreach ($lineasEvento as $linea) {
            $this->textoCentrado($imagen, $linea, 30, $yEvento, $blanco, $fuenteBold);
            $yEvento += 37;
        }

        $this->textoCentrado($imagen, 'SEDE', 14, 292, $blanco, $fuenteBold);
        $lineasSede = $this->envolverTexto($sede, 700, 22, $fuenteNormal, 2);
        $ySede = count($lineasSede) > 1 ? 326 : 340;
        foreach ($lineasSede as $linea) {
            $this->textoCentrado($imagen, $linea, 22, $ySede, $blanco, $fuenteNormal);
            $ySede += 29;
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
            : (strtolower($modalidad) === 'presencial'
                ? 'No aplica · reunión presencial'
                : 'Por confirmar');
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

        imageinterlace($imagen, true);
        $guardado = imagejpeg($imagen, $ruta, 96);
        imagedestroy($imagen);

        if (!$guardado || !is_file($ruta)) {
            return $this->error('No fue posible guardar la Ecard de la reunión.');
        }

        return ['ok' => true];
    }

    private function rellenarPoligono($imagen, array $puntos, $color)
    {
        if (PHP_VERSION_ID >= 80000) {
            return imagefilledpolygon($imagen, $puntos, $color);
        }

        return imagefilledpolygon(
            $imagen,
            $puntos,
            (int)(count($puntos) / 2),
            $color
        );
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
        $x = 72;
        $y = 748;
        $maxAncho = 170;
        $maxAlto = 150;

        // El retrato se coloca completo, conservando su proporción original.
        // No se recorta el rostro ni se altera el archivo fuente.
        imagefilledrectangle(
            $imagen,
            $x - 5,
            $y - 5,
            $x + $maxAncho + 5,
            $y + $maxAlto + 5,
            $grisFondo
        );

        if ($foto !== '') {
            $origen = $this->cargarImagen($foto);
            if ($origen) {
                $w = imagesx($origen);
                $h = imagesy($origen);

                if ($w > 0 && $h > 0) {
                    $escala = min($maxAncho / $w, $maxAlto / $h);
                    $destinoAncho = max(1, (int)round($w * $escala));
                    $destinoAlto = max(1, (int)round($h * $escala));
                    $destinoX = $x + (int)(($maxAncho - $destinoAncho) / 2);
                    $destinoY = $y + (int)(($maxAlto - $destinoAlto) / 2);

                    imagecopyresampled(
                        $imagen,
                        $origen,
                        $destinoX,
                        $destinoY,
                        0,
                        0,
                        $destinoAncho,
                        $destinoAlto,
                        $w,
                        $h
                    );
                }

                imagedestroy($origen);
            }
        } else {
            imagefilledellipse(
                $imagen,
                $x + (int)($maxAncho / 2),
                $y + (int)($maxAlto / 2),
                118,
                118,
                $navy
            );
            $iniciales = strtoupper(substr(trim($ponente), 0, 1) . 'P');
            $this->textoCentradoEnCaja(
                $imagen,
                $iniciales,
                29,
                $x,
                $y + 64,
                $maxAncho,
                imagecolorallocate($imagen, 255, 255, 255),
                $fuenteBold
            );
        }

        $this->texto($imagen, $ponente, 31, 275, 798, $navy, $fuenteBold);
        $this->texto($imagen, $cargo, 22, 275, 838, $grisTexto, $fuenteNormal);
        $this->texto($imagen, 'Reunión institucional', 15, 275, 875, $grisTexto, $fuenteBold);
    }

    private function buscarFotoPonente($template)
    {
        $esSergio = strtoupper((string)$template) === self::TEMPLATE_SERGIO;
        $configFoto = $this->config(
            $esSergio
                ? 'ECARD_REUNION_SERGIO_FOTO'
                : 'ECARD_REUNION_MANUEL_FOTO',
            $esSergio
                ? 'public/img/ecards/sergio-lopez-porcayo.jpg'
                : 'public/img/ecards/manuel-porcayo.jpg'
        );

        if ($configFoto !== '') {
            $rutaConfig = $configFoto;

            if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $rutaConfig)) {
                $rutaConfig = $this->rootPath . DIRECTORY_SEPARATOR .
                    ltrim(
                        str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rutaConfig),
                        DIRECTORY_SEPARATOR
                    );
            }

            if (is_file($rutaConfig)) {
                return $rutaConfig;
            }
        }

        if (!$this->connection) {
            return '';
        }

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
