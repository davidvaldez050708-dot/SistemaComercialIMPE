<?php

require_once __DIR__ . '/../models/ConvocatoriaModel.php';

class ReporteConvocatoriaDataService
{
    private $modelo;

    public function __construct()
    {
        $this->modelo = new ConvocatoriaModel();
    }

    public function prepararDatos(array $filtrosEntrada = [])
    {
        $this->modelo->desactivarConvocatoriasVencidas();

        $filtros = $this->normalizarFiltros($filtrosEntrada);
        $detalleCompleto = $this->modelo->obtenerDetalleReporteConvocatorias();
        $detalle = $this->filtrarDetalle($detalleCompleto, $filtros);

        $coberturaBase = $this->modelo->obtenerCoberturaTerritorialDashboard(32);
        $totalEstados = (int)($coberturaBase['total_estados'] ?? 0);
        $cobertura = $this->construirCobertura($detalle, $totalEstados);
        $porTipo = $this->construirPublicacionesPorTipo($detalle);
        $publicacionesPorUsuario =
            $this->construirPublicacionesPorUsuario($detalle);

        $hoy = new DateTimeImmutable('today');
        $limiteSieteDias = $hoy->modify('+7 days');
        $claveHoy = $hoy->format('Y-m-d');
        $claveMesActual = $hoy->format('Y-m');

        $total = count($detalle);
        $activas = 0;
        $inactivas = 0;
        $vigentes = 0;
        $proximas = 0;
        $publicacionesHoy = 0;
        $publicacionesMesActual = 0;
        $vencenHoy = 0;
        $vencenProximosDosDias = 0;
        $alertasVencimiento = [];

        foreach ($detalle as $convocatoria) {
            $estado = (int)($convocatoria['estado'] ?? 0);

            if ($estado === 1) {
                $activas++;
            } else {
                $inactivas++;
            }

            $creada = trim((string)($convocatoria['created_at'] ?? ''));

            if ($creada !== '') {
                $fechaCreacion = substr($creada, 0, 10);

                if ($fechaCreacion === $claveHoy) {
                    $publicacionesHoy++;
                }

                if (substr($fechaCreacion, 0, 7) === $claveMesActual) {
                    $publicacionesMesActual++;
                }
            }

            $fechaInicio = $this->crearFecha(
                (string)($convocatoria['fecha_inicio'] ?? '')
            );
            $fechaTermino = $this->crearFecha(
                (string)($convocatoria['fecha_termino'] ?? '')
            );

            if (
                $estado === 1 &&
                $fechaInicio &&
                $fechaTermino &&
                $hoy >= $fechaInicio &&
                $hoy <= $fechaTermino
            ) {
                $vigentes++;
            }

            if (
                $estado === 1 &&
                $fechaTermino &&
                $fechaTermino >= $hoy &&
                $fechaTermino <= $limiteSieteDias
            ) {
                $proximas++;
            }

            if ($estado !== 1 || !$fechaTermino) {
                continue;
            }

            $diasRestantes = (int)$hoy->diff($fechaTermino)->format('%r%a');

            if ($diasRestantes === 0) {
                $vencenHoy++;
            }

            if ($diasRestantes >= 1 && $diasRestantes <= 2) {
                $vencenProximosDosDias++;
            }

            if ($diasRestantes >= 0 && $diasRestantes <= 2) {
                $alertasVencimiento[] = [
                    'id' => (int)($convocatoria['id'] ?? 0),
                    'titulo' => (string)($convocatoria['titulo'] ?? ''),
                    'tipo_convocatoria' => (string)(
                        $convocatoria['tipo_convocatoria'] ?? ''
                    ),
                    'subtipo_convocatoria' => (string)(
                        $convocatoria['subtipo_convocatoria'] ?? ''
                    ),
                    'fecha_termino' => $fechaTermino->format('Y-m-d'),
                    'estados' => (string)($convocatoria['estados'] ?? ''),
                    'dias_restantes' => $diasRestantes
                ];
            }
        }

        usort(
            $alertasVencimiento,
            static function ($a, $b) {
                $comparacionDias =
                    (int)($a['dias_restantes'] ?? 0) <=>
                    (int)($b['dias_restantes'] ?? 0);

                if ($comparacionDias !== 0) {
                    return $comparacionDias;
                }

                return strcmp(
                    (string)($a['titulo'] ?? ''),
                    (string)($b['titulo'] ?? '')
                );
            }
        );

        $estadosCubiertos = (int)($cobertura['estados_cubiertos'] ?? 0);
        $porcentajeCobertura = $totalEstados > 0
            ? (int)round(($estadosCubiertos / $totalEstados) * 100)
            : 0;

        $totalBachillerato =
            (int)($porTipo['bachillerato']['total'] ?? 0);
        $totalTitulacion =
            (int)($porTipo['titulacion']['total'] ?? 0);

        $territorios = is_array($cobertura['territorios'] ?? null)
            ? $cobertura['territorios']
            : [];
        $territorioPrincipal = $territorios[0] ?? null;

        $hallazgos = [];

        $hallazgos[] = $vencenHoy > 0
            ? 'Hay ' . $vencenHoy .
                ' convocatoria(s) que vencen hoy y requieren seguimiento.'
            : 'No hay convocatorias activas con vencimiento programado para hoy.';

        $hallazgos[] = $vencenProximosDosDias > 0
            ? 'Hay ' . $vencenProximosDosDias .
                ' convocatoria(s) que vencen en los próximos 1 a 2 días.'
            : 'No hay convocatorias activas con vencimiento en los próximos 1 a 2 días.';

        $hallazgos[] = 'Actualmente hay ' . $activas .
            ' convocatorias activas y ' . $inactivas . ' inactivas.';

        $hallazgos[] = 'Durante el mes actual se han registrado ' .
            $publicacionesMesActual . ' publicación(es), de las cuales ' .
            $publicacionesHoy . ' corresponden al día de hoy.';

        $hallazgos[] = 'La cobertura territorial activa alcanza ' .
            $estadosCubiertos . ' de ' . $totalEstados . ' estados (' .
            $porcentajeCobertura . '%).';

        if (is_array($territorioPrincipal)) {
            $hallazgos[] =
                'El territorio con mayor concentración activa es ' .
                (string)($territorioPrincipal['nombre'] ?? '') . ' con ' .
                (int)($territorioPrincipal['convocatorias_activas'] ?? 0) .
                ' convocatoria(s).';
        }

        $hallazgos[] = 'En los últimos 30 días se registraron ' .
            $totalBachillerato . ' publicaciones de Bachillerato y ' .
            $totalTitulacion . ' de Titulación.';

        return [
            'filtros' => $filtros,
            'opciones_filtro' => $this->opcionesFiltro(),
            'resumen' => [
                'total' => $total,
                'activas' => $activas,
                'inactivas' => $inactivas,
                'vigentes' => $vigentes,
                'proximas_finalizar' => $proximas,
                'vencen_hoy' => $vencenHoy,
                'vencen_2_dias' => $vencenProximosDosDias,
                'publicaciones_hoy' => $publicacionesHoy,
                'publicaciones_mes_actual' => $publicacionesMesActual,
                'estados_cubiertos' => $estadosCubiertos,
                'total_estados' => $totalEstados,
                'porcentaje_cobertura' => $porcentajeCobertura,
                'bachillerato_30' => $totalBachillerato,
                'titulacion_30' => $totalTitulacion
            ],
            'cobertura' => $cobertura,
            'por_tipo' => $porTipo,
            'publicaciones_por_usuario' => $publicacionesPorUsuario,
            'detalle' => $detalle,
            'alertas_vencimiento' => $alertasVencimiento,
            'hallazgos' => $hallazgos
        ];
    }

    private function normalizarFiltros(array $filtros)
    {
        $tipo = strtolower(trim((string)($filtros['tipo'] ?? '')));
        $subtipo = strtolower(trim((string)($filtros['subtipo'] ?? '')));

        $opciones = $this->opcionesFiltro();
        $tiposPermitidos = array_keys($opciones['tipos']);
        $subtiposPermitidos = [];

        foreach ($opciones['subtipos'] as $subtiposTipo) {
            foreach ($subtiposTipo as $slug => $_etiqueta) {
                $subtiposPermitidos[$slug] = true;
            }
        }

        if (!in_array($tipo, $tiposPermitidos, true)) {
            $tipo = '';
        }

        if ($subtipo !== '' && !isset($subtiposPermitidos[$subtipo])) {
            $subtipo = '';
        }

        if (
            $tipo !== '' &&
            $subtipo !== '' &&
            !isset($opciones['subtipos'][$tipo][$subtipo])
        ) {
            $subtipo = '';
        }

        return [
            'tipo' => $tipo,
            'subtipo' => $subtipo
        ];
    }

    private function opcionesFiltro()
    {
        return [
            'tipos' => [
                '' => 'Todos',
                'titulacion' => 'Titulación',
                'bachillerato' => 'Bachillerato'
            ],
            'subtipos' => [
                'titulacion' => [
                    'ejecutivas' => 'Ejecutivas',
                    'experiencia-laboral' =>
                        'Titulación por experiencia laboral',
                    'inscripciones-abiertas' =>
                        'Inscripciones Abiertas'
                ],
                'bachillerato' => [
                    'bachillerato-2-anos' =>
                        'Bachillerato en 2 años',
                    'bachillerato-286' =>
                        'Bachillerato 286',
                    'ingles' => 'Inglés',
                    'inscripciones-abiertas' =>
                        'Inscripciones Abiertas'
                ]
            ]
        ];
    }

    private function filtrarDetalle(array $detalle, array $filtros)
    {
        $tipo = (string)($filtros['tipo'] ?? '');
        $subtipo = (string)($filtros['subtipo'] ?? '');

        return array_values(array_filter(
            $detalle,
            static function ($fila) use ($tipo, $subtipo) {
                $tipoFila = strtolower(trim((string)(
                    $fila['tipo_convocatoria'] ?? ''
                )));
                $subtipoFila = strtolower(trim((string)(
                    $fila['subtipo_convocatoria'] ?? ''
                )));

                if ($tipo !== '' && $tipoFila !== $tipo) {
                    return false;
                }

                if ($subtipo !== '' && $subtipoFila !== $subtipo) {
                    return false;
                }

                return true;
            }
        ));
    }

    private function construirCobertura(array $detalle, $totalEstados)
    {
        $conteo = [];

        foreach ($detalle as $convocatoria) {
            if ((int)($convocatoria['estado'] ?? 0) !== 1) {
                continue;
            }

            $estados = array_filter(array_map(
                'trim',
                explode(',', (string)($convocatoria['estados'] ?? ''))
            ));

            foreach (array_unique($estados) as $estado) {
                if ($estado === '') {
                    continue;
                }

                $conteo[$estado] = ($conteo[$estado] ?? 0) + 1;
            }
        }

        uksort(
            $conteo,
            static function ($a, $b) use ($conteo) {
                $comparacion =
                    (int)$conteo[$b] <=> (int)$conteo[$a];

                return $comparacion !== 0
                    ? $comparacion
                    : strcasecmp((string)$a, (string)$b);
            }
        );

        $territorios = [];
        foreach ($conteo as $nombre => $cantidad) {
            $territorios[] = [
                'id' => 0,
                'nombre' => (string)$nombre,
                'convocatorias_activas' => (int)$cantidad
            ];
        }

        return [
            'total_estados' => (int)$totalEstados,
            'estados_cubiertos' => count($conteo),
            'territorios' => $territorios
        ];
    }

    private function construirPublicacionesPorTipo(array $detalle)
    {
        $mesesNombres = [
            '01' => 'Ene',
            '02' => 'Feb',
            '03' => 'Mar',
            '04' => 'Abr',
            '05' => 'May',
            '06' => 'Jun',
            '07' => 'Jul',
            '08' => 'Ago',
            '09' => 'Sep',
            '10' => 'Oct',
            '11' => 'Nov',
            '12' => 'Dic'
        ];

        $mesActual = new DateTimeImmutable('first day of this month');
        $mesesBase = [];

        for ($i = 3; $i >= 0; $i--) {
            $fechaMes = $mesActual->modify('-' . $i . ' months');
            $clave = $fechaMes->format('Y-m');
            $numeroMes = $fechaMes->format('m');

            $mesesBase[$clave] = [
                'periodo' => $clave,
                'label' => $mesesNombres[$numeroMes] ?? $numeroMes,
                'total' => 0
            ];
        }

        $salida = [
            'bachillerato' => [
                'total' => 0,
                'meses' => array_values($mesesBase)
            ],
            'titulacion' => [
                'total' => 0,
                'meses' => array_values($mesesBase)
            ]
        ];

        $indicesMeses = [];
        foreach (array_keys($mesesBase) as $indice => $periodo) {
            $indicesMeses[$periodo] = $indice;
        }

        $limiteTreintaDias = new DateTimeImmutable('-30 days');

        foreach ($detalle as $convocatoria) {
            $tipo = strtolower(trim((string)(
                $convocatoria['tipo_convocatoria'] ?? ''
            )));

            if (!isset($salida[$tipo])) {
                continue;
            }

            $creada = trim((string)($convocatoria['created_at'] ?? ''));

            if ($creada === '') {
                continue;
            }

            try {
                $fechaCreacion = new DateTimeImmutable($creada);
            } catch (Exception $error) {
                continue;
            }

            if ($fechaCreacion >= $limiteTreintaDias) {
                $salida[$tipo]['total']++;
            }

            $periodo = $fechaCreacion->format('Y-m');

            if (isset($indicesMeses[$periodo])) {
                $indice = $indicesMeses[$periodo];
                $salida[$tipo]['meses'][$indice]['total']++;
            }
        }

        return $salida;
    }

    private function construirPublicacionesPorUsuario(array $detalle)
    {
        $usuarios = [];

        foreach ($detalle as $convocatoria) {
            $usuarioId = (int)($convocatoria['creado_por'] ?? 0);
            $usuario = trim((string)(
                $convocatoria['creador_usuario'] ?? ''
            ));
            $nombre = trim((string)(
                $convocatoria['creador_nombre'] ?? ''
            ));
            $apellidos = trim((string)(
                $convocatoria['creador_apellidos'] ?? ''
            ));
            $rol = trim((string)(
                $convocatoria['creador_rol'] ?? ''
            ));

            $clave = $usuarioId > 0
                ? 'id:' . $usuarioId
                : 'usuario:' . strtolower(
                    $usuario !== ''
                        ? $usuario
                        : trim($nombre . ' ' . $apellidos)
                );

            if (!isset($usuarios[$clave])) {
                $usuarios[$clave] = [
                    'usuario_id' => $usuarioId,
                    'nombre' => $nombre,
                    'apellidos' => $apellidos,
                    'usuario' => $usuario,
                    'rol' => $rol,
                    'total_publicaciones' => 0,
                    'activas' => 0,
                    'inactivas' => 0,
                    'ultima_publicacion' => ''
                ];
            }

            $usuarios[$clave]['total_publicaciones']++;

            if ((int)($convocatoria['estado'] ?? 0) === 1) {
                $usuarios[$clave]['activas']++;
            } else {
                $usuarios[$clave]['inactivas']++;
            }

            $creada = trim((string)($convocatoria['created_at'] ?? ''));

            if (
                $creada !== '' &&
                (
                    $usuarios[$clave]['ultima_publicacion'] === '' ||
                    $creada > $usuarios[$clave]['ultima_publicacion']
                )
            ) {
                $usuarios[$clave]['ultima_publicacion'] = $creada;
            }
        }

        $salida = array_values($usuarios);

        usort(
            $salida,
            static function ($a, $b) {
                $comparacion =
                    (int)($b['total_publicaciones'] ?? 0) <=>
                    (int)($a['total_publicaciones'] ?? 0);

                if ($comparacion !== 0) {
                    return $comparacion;
                }

                $nombreA = trim(
                    (string)($a['nombre'] ?? '') . ' ' .
                    (string)($a['apellidos'] ?? '')
                );
                $nombreB = trim(
                    (string)($b['nombre'] ?? '') . ' ' .
                    (string)($b['apellidos'] ?? '')
                );

                return strcasecmp($nombreA, $nombreB);
            }
        );

        return $salida;
    }

    private function crearFecha($fecha)
    {
        $fecha = trim((string)$fecha);

        if ($fecha === '') {
            return null;
        }

        $fecha = substr($fecha, 0, 10);
        $objeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);

        if (!$objeto || $objeto->format('Y-m-d') !== $fecha) {
            return null;
        }

        return $objeto;
    }
}
