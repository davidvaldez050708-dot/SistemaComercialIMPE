<?php

require_once __DIR__ . '/../models/AliadoModel.php';

class ReporteAliadosPanoramaService
{
    private $modelo;

    private const ESTADOS_CERRADOS = [
        'DIFUSION_CONFIRMADA',
        'NO_PARTICIPARA'
    ];

    public function __construct()
    {
        $this->modelo = new AliadoModel();
    }

    public function prepararDatos(
        $usuarioId,
        $esAdministrador,
        array $filtros = []
    ) {
        $usuarioId = (int)$usuarioId;
        $esAdministrador = (bool)$esAdministrador;

        $estadoId = max(0, (int)($filtros['estado_id'] ?? 0));
        $municipioId = max(0, (int)($filtros['municipio_id'] ?? 0));
        $situacion = $this->normalizarSituacion(
            (string)($filtros['situacion'] ?? 'todos')
        );

        $aliados = $this->modelo->obtenerListado(
            $usuarioId,
            $esAdministrador,
            [
                'estado_id' => $estadoId,
                'municipio_id' => $municipioId
            ]
        );

        $aliados = array_values(array_filter(
            $aliados,
            function ($aliado) use ($situacion) {
                return $this->coincideSituacion($aliado, $situacion);
            }
        ));

        $ahora = new DateTimeImmutable();
        $resumen = [
            'total' => count($aliados),
            'estados' => 0,
            'municipios' => 0,
            'con_whatsapp' => 0,
            'con_correo' => 0,
            'con_difusion' => 0,
            'sin_difusion' => 0,
            'pendientes' => 0,
            'vencidos' => 0,
            'esperando_respuesta' => 0,
            'sin_respuesta' => 0,
            'solicita_informacion' => 0,
            'difusion_confirmada' => 0,
            'no_participara' => 0,
            'cobertura_difusion' => 0,
            'cobertura_whatsapp' => 0,
            'tasa_confirmacion' => 0
        ];

        $estadosVistos = [];
        $municipiosVistos = [];
        $porEstado = [];
        $porMunicipio = [];
        $detalle = [];
        $atencion = [];

        foreach ($aliados as $aliado) {
            $seguimientoId = (int)($aliado['seguimiento_id'] ?? 0);
            $estadoTerritorioId = (int)($aliado['estado_id'] ?? 0);
            $estadoTerritorioNombre = trim(
                (string)($aliado['estado_nombre'] ?? '')
            );
            $municipioActualId = (int)($aliado['municipio_id'] ?? 0);
            $municipioNombre = trim(
                (string)($aliado['municipio_nombre'] ?? '')
            );
            $estadoSeguimiento = strtoupper(trim(
                (string)($aliado['seguimiento_convocatoria_estado'] ?? '')
            ));
            $tieneDifusion =
                (int)($aliado['ultimo_envio_id'] ?? 0) > 0;
            $proximo = trim(
                (string)($aliado['proximo_seguimiento_at'] ?? '')
            );
            $pendiente = $this->esPendiente($estadoSeguimiento);
            $vencido = $pendiente && $this->esVencido($proximo, $ahora);
            $tieneWhatsapp =
                trim((string)($aliado['whatsapp_verificado'] ?? '')) !== '' ||
                (int)($aliado['tiene_whatsapp_confirmado_contacto'] ?? 0) === 1;
            $correo = trim((string)($aliado['correo_contacto'] ?? ''));
            $tieneCorreo =
                $correo !== '' &&
                filter_var($correo, FILTER_VALIDATE_EMAIL);

            if ($estadoTerritorioId > 0) {
                $estadosVistos[$estadoTerritorioId] = true;
            }

            if ($municipioActualId > 0) {
                $municipiosVistos[$municipioActualId] = true;
            }

            if ($tieneWhatsapp) {
                $resumen['con_whatsapp']++;
            }

            if ($tieneCorreo) {
                $resumen['con_correo']++;
            }

            if ($tieneDifusion) {
                $resumen['con_difusion']++;
            } else {
                $resumen['sin_difusion']++;
            }

            if ($pendiente) {
                $resumen['pendientes']++;
            }

            if ($vencido) {
                $resumen['vencidos']++;
            }

            if ($estadoSeguimiento !== '') {
                $claveResumen = $this->claveResumenEstado($estadoSeguimiento);
                if ($claveResumen !== '' && isset($resumen[$claveResumen])) {
                    $resumen[$claveResumen]++;
                }
            }

            if (!isset($porEstado[$estadoTerritorioId])) {
                $porEstado[$estadoTerritorioId] = [
                    'estado_id' => $estadoTerritorioId,
                    'estado' => $estadoTerritorioNombre !== ''
                        ? $estadoTerritorioNombre
                        : 'Sin estado',
                    'aliados' => 0,
                    'municipios' => [],
                    'con_difusion' => 0,
                    'pendientes' => 0,
                    'vencidos' => 0
                ];
            }

            $porEstado[$estadoTerritorioId]['aliados']++;
            if ($municipioActualId > 0) {
                $porEstado[$estadoTerritorioId]['municipios'][$municipioActualId] = true;
            }
            if ($tieneDifusion) {
                $porEstado[$estadoTerritorioId]['con_difusion']++;
            }
            if ($pendiente) {
                $porEstado[$estadoTerritorioId]['pendientes']++;
            }
            if ($vencido) {
                $porEstado[$estadoTerritorioId]['vencidos']++;
            }

            $claveMunicipio = $estadoTerritorioId . ':' . $municipioActualId;
            if (!isset($porMunicipio[$claveMunicipio])) {
                $porMunicipio[$claveMunicipio] = [
                    'estado_id' => $estadoTerritorioId,
                    'estado' => $estadoTerritorioNombre !== ''
                        ? $estadoTerritorioNombre
                        : 'Sin estado',
                    'municipio_id' => $municipioActualId,
                    'municipio' => $municipioNombre !== ''
                        ? $municipioNombre
                        : 'Sin municipio',
                    'aliados' => 0,
                    'con_difusion' => 0,
                    'pendientes' => 0,
                    'vencidos' => 0
                ];
            }

            $porMunicipio[$claveMunicipio]['aliados']++;
            if ($tieneDifusion) {
                $porMunicipio[$claveMunicipio]['con_difusion']++;
            }
            if ($pendiente) {
                $porMunicipio[$claveMunicipio]['pendientes']++;
            }
            if ($vencido) {
                $porMunicipio[$claveMunicipio]['vencidos']++;
            }

            $detalle[] = [
                'seguimiento_id' => $seguimientoId,
                'institucion' => (string)($aliado['nombre_entidad'] ?? ''),
                'estado' => $estadoTerritorioNombre,
                'municipio' => $municipioNombre !== ''
                    ? $municipioNombre
                    : 'Sin municipio',
                'analista' => trim(
                    (string)($aliado['analista_nombre'] ?? '')
                ),
                'formalizado_at' => (string)(
                    $aliado['convenio_formalizado_at'] ?? ''
                ),
                'tiene_whatsapp' => $tieneWhatsapp,
                'tiene_correo' => $tieneCorreo,
                'ultima_convocatoria' => (string)(
                    $aliado['ultima_convocatoria_titulo'] ?? ''
                ),
                'ultimo_canal' => (string)(
                    $aliado['ultimo_envio_canal'] ?? ''
                ),
                'ultimo_envio_at' => (string)(
                    $aliado['ultimo_envio_at'] ?? ''
                ),
                'estado_seguimiento' => $estadoSeguimiento,
                'estado_seguimiento_label' =>
                    $this->etiquetaEstado($estadoSeguimiento),
                'proximo_seguimiento_at' => $proximo,
                'pendiente' => $pendiente,
                'vencido' => $vencido
            ];

            $prioridad = $this->resolverPrioridadAtencion(
                $estadoSeguimiento,
                $tieneDifusion,
                $vencido
            );

            if ($prioridad !== null) {
                $atencion[] = [
                    'prioridad' => $prioridad['orden'],
                    'tipo' => $prioridad['tipo'],
                    'etiqueta' => $prioridad['etiqueta'],
                    'accion' => $prioridad['accion'],
                    'seguimiento_id' => $seguimientoId,
                    'institucion' => (string)($aliado['nombre_entidad'] ?? ''),
                    'estado' => $estadoTerritorioNombre,
                    'municipio' => $municipioNombre !== ''
                        ? $municipioNombre
                        : 'Sin municipio',
                    'convocatoria' => (string)(
                        $aliado['ultima_convocatoria_titulo'] ?? ''
                    ),
                    'proximo_seguimiento_at' => $proximo
                ];
            }
        }

        $resumen['estados'] = count($estadosVistos);
        $resumen['municipios'] = count($municipiosVistos);

        if ($resumen['total'] > 0) {
            $resumen['cobertura_difusion'] = round(
                ($resumen['con_difusion'] / $resumen['total']) * 100,
                1
            );
            $resumen['cobertura_whatsapp'] = round(
                ($resumen['con_whatsapp'] / $resumen['total']) * 100,
                1
            );
        }

        if ($resumen['con_difusion'] > 0) {
            $resumen['tasa_confirmacion'] = round(
                (
                    $resumen['difusion_confirmada'] /
                    $resumen['con_difusion']
                ) * 100,
                1
            );
        }

        foreach ($porEstado as &$filaEstado) {
            $filaEstado['municipios'] = count($filaEstado['municipios']);
            $filaEstado['cobertura_difusion'] =
                $filaEstado['aliados'] > 0
                    ? round(
                        (
                            $filaEstado['con_difusion'] /
                            $filaEstado['aliados']
                        ) * 100,
                        1
                    )
                    : 0;
        }
        unset($filaEstado);

        foreach ($porMunicipio as &$filaMunicipio) {
            $filaMunicipio['cobertura_difusion'] =
                $filaMunicipio['aliados'] > 0
                    ? round(
                        (
                            $filaMunicipio['con_difusion'] /
                            $filaMunicipio['aliados']
                        ) * 100,
                        1
                    )
                    : 0;
        }
        unset($filaMunicipio);

        $porEstado = array_values($porEstado);
        usort($porEstado, static function ($a, $b) {
            $comparacion = (int)$b['aliados'] <=> (int)$a['aliados'];
            return $comparacion !== 0
                ? $comparacion
                : strcasecmp((string)$a['estado'], (string)$b['estado']);
        });

        $porMunicipio = array_values($porMunicipio);
        usort($porMunicipio, static function ($a, $b) {
            $comparacion = (int)$b['aliados'] <=> (int)$a['aliados'];
            return $comparacion !== 0
                ? $comparacion
                : strcasecmp((string)$a['municipio'], (string)$b['municipio']);
        });

        usort($atencion, static function ($a, $b) {
            $comparacion = (int)$a['prioridad'] <=> (int)$b['prioridad'];
            if ($comparacion !== 0) {
                return $comparacion;
            }

            $fechaA = strtotime(
                (string)($a['proximo_seguimiento_at'] ?? '')
            ) ?: PHP_INT_MAX;
            $fechaB = strtotime(
                (string)($b['proximo_seguimiento_at'] ?? '')
            ) ?: PHP_INT_MAX;

            if ($fechaA !== $fechaB) {
                return $fechaA <=> $fechaB;
            }

            return strcasecmp(
                (string)$a['institucion'],
                (string)$b['institucion']
            );
        });

        $atencion = array_slice($atencion, 0, 15);

        usort($detalle, static function ($a, $b) {
            $comparacion = strcasecmp(
                (string)$a['estado'],
                (string)$b['estado']
            );
            if ($comparacion !== 0) {
                return $comparacion;
            }

            $comparacion = strcasecmp(
                (string)$a['municipio'],
                (string)$b['municipio']
            );
            if ($comparacion !== 0) {
                return $comparacion;
            }

            return strcasecmp(
                (string)$a['institucion'],
                (string)$b['institucion']
            );
        });

        return [
            'resumen' => $resumen,
            'por_estado' => $porEstado,
            'por_municipio' => $porMunicipio,
            'atencion' => $atencion,
            'detalle' => $detalle,
            'hallazgos' => $this->construirHallazgos(
                $resumen,
                $porMunicipio
            ),
            'filtros' => [
                'estado_id' => $estadoId,
                'municipio_id' => $municipioId,
                'situacion' => $situacion
            ]
        ];
    }

    private function normalizarSituacion($situacion)
    {
        $situacion = strtolower(trim((string)$situacion));
        $permitidas = [
            'todos',
            'con_difusion',
            'sin_difusion',
            'pendientes',
            'vencidos',
            'esperando_respuesta',
            'sin_respuesta',
            'solicita_informacion',
            'difusion_confirmada',
            'no_participara'
        ];

        return in_array($situacion, $permitidas, true)
            ? $situacion
            : 'todos';
    }

    private function coincideSituacion($aliado, $situacion)
    {
        if ($situacion === 'todos') {
            return true;
        }

        $estado = strtoupper(trim(
            (string)($aliado['seguimiento_convocatoria_estado'] ?? '')
        ));
        $tieneDifusion = (int)($aliado['ultimo_envio_id'] ?? 0) > 0;
        $pendiente = $this->esPendiente($estado);
        $proximo = trim(
            (string)($aliado['proximo_seguimiento_at'] ?? '')
        );

        if ($situacion === 'con_difusion') {
            return $tieneDifusion;
        }

        if ($situacion === 'sin_difusion') {
            return !$tieneDifusion;
        }

        if ($situacion === 'pendientes') {
            return $pendiente;
        }

        if ($situacion === 'vencidos') {
            return $pendiente &&
                $this->esVencido($proximo, new DateTimeImmutable());
        }

        $mapa = [
            'esperando_respuesta' => 'ESPERANDO_RESPUESTA',
            'sin_respuesta' => 'SIN_RESPUESTA',
            'solicita_informacion' => 'SOLICITA_INFORMACION',
            'difusion_confirmada' => 'DIFUSION_CONFIRMADA',
            'no_participara' => 'NO_PARTICIPARA'
        ];

        return $estado === ($mapa[$situacion] ?? '');
    }

    private function esPendiente($estado)
    {
        $estado = strtoupper(trim((string)$estado));

        return $estado !== '' &&
            !in_array($estado, self::ESTADOS_CERRADOS, true);
    }

    private function esVencido($fecha, DateTimeImmutable $ahora)
    {
        $fecha = trim((string)$fecha);

        if ($fecha === '') {
            return false;
        }

        try {
            $momento = new DateTimeImmutable($fecha);
            return $momento <= $ahora;
        } catch (Throwable $error) {
            return false;
        }
    }

    private function claveResumenEstado($estado)
    {
        $mapa = [
            'ESPERANDO_RESPUESTA' => 'esperando_respuesta',
            'SIN_RESPUESTA' => 'sin_respuesta',
            'SOLICITA_INFORMACION' => 'solicita_informacion',
            'DIFUSION_CONFIRMADA' => 'difusion_confirmada',
            'NO_PARTICIPARA' => 'no_participara'
        ];

        return $mapa[$estado] ?? '';
    }

    private function etiquetaEstado($estado)
    {
        $mapa = [
            'ESPERANDO_RESPUESTA' => 'Esperando respuesta',
            'SIN_RESPUESTA' => 'Sin respuesta',
            'SOLICITA_INFORMACION' => 'Solicita información',
            'DIFUSION_CONFIRMADA' => 'Difusión confirmada',
            'NO_PARTICIPARA' => 'No participará'
        ];

        return $mapa[$estado] ?? (
            $estado === '' ? 'Sin seguimiento' : $estado
        );
    }

    private function resolverPrioridadAtencion(
        $estado,
        $tieneDifusion,
        $vencido
    ) {
        if ($vencido) {
            return [
                'orden' => 1,
                'tipo' => 'vencido',
                'etiqueta' => 'Seguimiento vencido',
                'accion' => 'Contactar al aliado y actualizar el seguimiento.'
            ];
        }

        if ($estado === 'SOLICITA_INFORMACION') {
            return [
                'orden' => 2,
                'tipo' => 'informacion',
                'etiqueta' => 'Solicita información',
                'accion' => 'Responder la información pendiente.'
            ];
        }

        if ($estado === 'SIN_RESPUESTA') {
            return [
                'orden' => 3,
                'tipo' => 'sin-respuesta',
                'etiqueta' => 'Sin respuesta',
                'accion' => 'Definir o ejecutar un nuevo contacto.'
            ];
        }

        if (!$tieneDifusion) {
            return [
                'orden' => 4,
                'tipo' => 'sin-difusion',
                'etiqueta' => 'Sin difusión registrada',
                'accion' => 'Revisar si existe una convocatoria aplicable.'
            ];
        }

        return null;
    }

    private function construirHallazgos(array $resumen, array $municipios)
    {
        if ((int)($resumen['total'] ?? 0) <= 0) {
            return [
                'No hay aliados que coincidan con los filtros seleccionados.'
            ];
        }

        $hallazgos = [];
        $hallazgos[] =
            'La red analizada reúne ' .
            (int)$resumen['total'] . ' aliado(s) en ' .
            (int)$resumen['municipios'] . ' municipio(s) y ' .
            (int)$resumen['estados'] . ' estado(s).';

        $hallazgos[] =
            number_format((float)$resumen['cobertura_difusion'], 1) .
            '% de los aliados ya registra al menos una difusión de convocatoria.';

        if ((int)$resumen['vencidos'] > 0) {
            $hallazgos[] =
                'Hay ' . (int)$resumen['vencidos'] .
                ' seguimiento(s) vencido(s) que requieren atención prioritaria.';
        } elseif ((int)$resumen['pendientes'] > 0) {
            $hallazgos[] =
                'Hay ' . (int)$resumen['pendientes'] .
                ' seguimiento(s) abierto(s), sin acciones vencidas al momento del reporte.';
        } else {
            $hallazgos[] =
                'No hay seguimientos abiertos que requieran atención inmediata.';
        }

        if ((int)$resumen['sin_respuesta'] > 0) {
            $hallazgos[] =
                (int)$resumen['sin_respuesta'] .
                ' aliado(s) se encuentran actualmente en estado Sin respuesta.';
        }

        if ((int)$resumen['solicita_informacion'] > 0) {
            $hallazgos[] =
                (int)$resumen['solicita_informacion'] .
                ' aliado(s) solicitaron información y requieren respuesta.';
        }

        $hallazgos[] =
            number_format((float)$resumen['cobertura_whatsapp'], 1) .
            '% de la red analizada cuenta con WhatsApp confirmado o verificado.';

        if (!empty($municipios)) {
            $principal = $municipios[0];
            $hallazgos[] =
                'El municipio con mayor concentración es ' .
                (string)$principal['municipio'] . ' (' .
                (string)$principal['estado'] . ') con ' .
                (int)$principal['aliados'] . ' aliado(s).';
        }

        return $hallazgos;
    }
}
