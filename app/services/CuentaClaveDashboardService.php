<?php

require_once __DIR__ . '/../models/SeguimientoVinculacionModel.php';
require_once __DIR__ . '/../models/AliadoModel.php';
require_once __DIR__ . '/SeguimientoAtencionOperativaService.php';

class CuentaClaveDashboardService
{
    private $seguimientos;
    private $aliados;
    private $atencion;

    public function __construct()
    {
        $this->seguimientos = new SeguimientoVinculacionModel();
        $this->aliados = new AliadoModel();
        $this->atencion = new SeguimientoAtencionOperativaService();
    }

    public function obtener(
        $usuarioId,
        $puedeSeguimiento = true,
        $puedeAliados = true
    ) {
        $usuarioId = (int)$usuarioId;
        $puedeSeguimiento = (bool)$puedeSeguimiento;
        $puedeAliados = (bool)$puedeAliados;

        if ($usuarioId <= 0) {
            return $this->tableroVacio();
        }

        $territorios = $this->seguimientos
            ->obtenerEstadosSupervisadosCuentaClave($usuarioId);

        $analistas = $this->obtenerAnalistas(
            $usuarioId,
            $territorios
        );

        $aliados = [];
        if ($this->aliados->estructuraDisponible()) {
            /*
             * También se consulta cuando el bloque visual de Aliados está
             * oculto para no contar convenios formalizados como cartera de
             * vinculación activa.
             */
            $aliados = $this->aliados->obtenerListado(
                $usuarioId,
                false
            );
        }

        $aliadosPorSeguimiento = [];
        foreach ($aliados as $aliado) {
            $seguimientoId = (int)($aliado['seguimiento_id'] ?? 0);
            if ($seguimientoId > 0) {
                $aliadosPorSeguimiento[$seguimientoId] = true;
            }
        }

        $cartera = $puedeSeguimiento
            ? $this->obtenerCartera(
                $usuarioId,
                $territorios,
                $aliadosPorSeguimiento
            )
            : [];

        $idsCartera = array_map(
            static fn($fila) => (int)($fila['id'] ?? 0),
            $cartera
        );

        $atenciones = $puedeSeguimiento
            ? $this->atencion->obtenerPorIds($idsCartera)
            : [];

        $analistas = $this->enriquecerAnalistas(
            $analistas,
            $cartera,
            $atenciones
        );

        $resumenAliados = $this->resumirAliados(
            $aliados,
            $puedeAliados
        );

        $cobertura = $this->construirCobertura(
            $territorios,
            $cartera,
            $atenciones,
            $aliados
        );

        return [
            'resumen' => [
                'territorios' => count($territorios),
                'analistas' => count($analistas),
                'seguimientos_activos' => count($cartera),
                'requieren_atencion' => count($atenciones),
                'aliados' => $puedeAliados
                    ? (int)$resumenAliados['total']
                    : 0
            ],
            'atenciones' => array_slice(
                $this->enriquecerAtenciones(
                    $atenciones,
                    $analistas
                ),
                0,
                6
            ),
            'analistas' => array_values($analistas),
            'aliados' => $resumenAliados,
            'cobertura' => $cobertura,
            'permisos' => [
                'seguimiento' => $puedeSeguimiento,
                'aliados' => $puedeAliados
            ]
        ];
    }

    private function obtenerAnalistas($usuarioId, array $territorios)
    {
        $analistas = [];

        foreach ($territorios as $territorio) {
            $estadoId = (int)($territorio['id'] ?? 0);
            if ($estadoId <= 0) {
                continue;
            }

            foreach (
                $this->seguimientos->obtenerAnalistasCuentaClaveEstado(
                    $usuarioId,
                    $estadoId
                ) as $analista
            ) {
                $analistaId = (int)($analista['id'] ?? 0);
                if ($analistaId <= 0) {
                    continue;
                }

                if (!isset($analistas[$analistaId])) {
                    $analistas[$analistaId] = [
                        'id' => $analistaId,
                        'nombre' => trim(
                            (string)($analista['nombre'] ?? '') . ' ' .
                            (string)($analista['apellidos'] ?? '')
                        ),
                        'foto_perfil' => (string)(
                            $analista['foto_perfil'] ?? ''
                        ),
                        'usuario' => (string)(
                            $analista['usuario'] ?? ''
                        ),
                        'territorios' => [],
                        'seguimientos' => 0,
                        'requieren_atencion' => 0,
                        'para_hoy' => 0,
                        'ultima_actividad_at' => '',
                        'cartera_url' => '',
                        'cartera_url_label' => 'Ver cartera'
                    ];
                }

                $analistas[$analistaId]['territorios'][$estadoId] = [
                    'id' => $estadoId,
                    'nombre' => (string)(
                        $territorio['nombre_corto']
                            ?? $territorio['nombre']
                            ?? ''
                    )
                ];
            }
        }

        return $analistas;
    }

    private function obtenerCartera(
        $usuarioId,
        array $territorios,
        array $aliadosPorSeguimiento
    ) {
        $cartera = [];

        foreach ($territorios as $territorio) {
            $estadoId = (int)($territorio['id'] ?? 0);
            if ($estadoId <= 0) {
                continue;
            }

            $estadoNombre = (string)(
                $territorio['nombre_corto']
                    ?? $territorio['nombre']
                    ?? ''
            );

            foreach (
                $this->seguimientos->obtenerSeguimientosSupervisorEstado(
                    $usuarioId,
                    $estadoId
                ) as $seguimiento
            ) {
                $seguimientoId = (int)($seguimiento['id'] ?? 0);
                $estadoSeguimiento = strtoupper(trim(
                    (string)($seguimiento['estado_seguimiento'] ?? '')
                ));

                if (
                    $seguimientoId <= 0 ||
                    isset($aliadosPorSeguimiento[$seguimientoId]) ||
                    $estadoSeguimiento === 'DESCARTADO'
                ) {
                    continue;
                }

                $seguimiento['estado_id'] = $estadoId;
                $seguimiento['estado_nombre'] = $estadoNombre;
                $cartera[$seguimientoId] = $seguimiento;
            }
        }

        return array_values($cartera);
    }

    private function enriquecerAnalistas(
        array $analistas,
        array $cartera,
        array $atenciones
    ) {
        $hoy = date('Y-m-d');

        foreach ($cartera as $seguimiento) {
            $analistaId = (int)($seguimiento['analista_id'] ?? 0);
            if (!isset($analistas[$analistaId])) {
                continue;
            }

            $analistas[$analistaId]['seguimientos']++;

            $proxima = trim(
                (string)($seguimiento['proxima_accion_at'] ?? '')
            );
            if ($proxima !== '' && substr($proxima, 0, 10) === $hoy) {
                $analistas[$analistaId]['para_hoy']++;
            }

            $ultima = trim((string)(
                $seguimiento['ultima_interaccion_at']
                    ?? $seguimiento['updated_at']
                    ?? ''
            ));

            if (
                $ultima !== '' &&
                (
                    $analistas[$analistaId]['ultima_actividad_at'] === '' ||
                    $ultima >
                        $analistas[$analistaId]['ultima_actividad_at']
                )
            ) {
                $analistas[$analistaId]['ultima_actividad_at'] = $ultima;
            }
        }

        foreach ($atenciones as $atencion) {
            $analistaId = (int)($atencion['analista_id'] ?? 0);
            if (isset($analistas[$analistaId])) {
                $analistas[$analistaId]['requieren_atencion']++;
            }
        }

        foreach ($analistas as &$analista) {
            $territorios = array_values($analista['territorios']);
            $analista['territorios'] = $territorios;
            $analista['territorios_total'] = count($territorios);

            $estadoPrincipal = $territorios[0] ?? null;

            if ($estadoPrincipal && count($territorios) === 1) {
                $analista['cartera_url'] =
                    BASE_URL .
                    'index.php?controller=seguimientoVinculacion&action=estado&estado_id=' .
                    (int)$estadoPrincipal['id'] .
                    '&analista_id=' .
                    (int)$analista['id'];
                $analista['cartera_url_label'] = 'Ver cartera';
            } else {
                $analista['cartera_url'] =
                    BASE_URL .
                    'index.php?controller=seguimientoVinculacion&action=index';
                $analista['cartera_url_label'] =
                    count($territorios) > 1
                        ? 'Ver territorios'
                        : 'Ver seguimiento';
            }
        }
        unset($analista);

        uasort($analistas, static function ($a, $b) {
            $atencion =
                (int)$b['requieren_atencion'] <=>
                (int)$a['requieren_atencion'];

            if ($atencion !== 0) {
                return $atencion;
            }

            $carga =
                (int)$b['seguimientos'] <=>
                (int)$a['seguimientos'];

            if ($carga !== 0) {
                return $carga;
            }

            return strcasecmp(
                (string)$a['nombre'],
                (string)$b['nombre']
            );
        });

        return $analistas;
    }

    private function enriquecerAtenciones(
        array $atenciones,
        array $analistas
    ) {
        foreach ($atenciones as &$atencion) {
            $analistaId = (int)($atencion['analista_id'] ?? 0);
            $atencion['analista_nombre'] = (string)(
                $analistas[$analistaId]['nombre']
                    ?? 'Analista'
            );
            $atencion['url'] =
                BASE_URL .
                'index.php?controller=seguimientoVinculacion&action=detalle&id=' .
                (int)($atencion['id'] ?? 0);
        }
        unset($atencion);

        return $atenciones;
    }

    private function resumirAliados(
        array $aliados,
        $puedeAliados
    ) {
        $resumen = [
            'visible' => (bool)$puedeAliados,
            'total' => count($aliados),
            'con_difusion' => 0,
            'pendientes' => 0,
            'vencidos' => 0,
            'confirmados' => 0,
            'requieren_atencion' => 0,
            'atencion' => []
        ];

        if (!$puedeAliados) {
            return $resumen;
        }

        $ahora = time();

        foreach ($aliados as $aliado) {
            if ((int)($aliado['ultimo_envio_id'] ?? 0) > 0) {
                $resumen['con_difusion']++;
            }

            $estado = strtoupper(trim((string)(
                $aliado['seguimiento_convocatoria_estado'] ?? ''
            )));
            $proximo = trim((string)(
                $aliado['proximo_seguimiento_at'] ?? ''
            ));

            if ($estado === 'DIFUSION_CONFIRMADA') {
                $resumen['confirmados']++;
            }

            $pendiente =
                $estado !== '' &&
                !in_array(
                    $estado,
                    ['DIFUSION_CONFIRMADA', 'NO_PARTICIPARA'],
                    true
                );

            if (!$pendiente) {
                continue;
            }

            $resumen['pendientes']++;

            $vencido = false;
            if ($proximo !== '') {
                $timestamp = strtotime($proximo);
                $vencido =
                    $timestamp !== false &&
                    $timestamp <= $ahora;
            }

            if ($vencido) {
                $resumen['vencidos']++;
            }

            $prioridad = $vencido
                ? 100
                : (
                    $estado === 'SOLICITA_INFORMACION'
                        ? 80
                        : (
                            $estado === 'SIN_RESPUESTA'
                                ? 70
                                : 50
                        )
                );

            if (
                $vencido ||
                in_array(
                    $estado,
                    ['SOLICITA_INFORMACION', 'SIN_RESPUESTA'],
                    true
                )
            ) {
                $resumen['requieren_atencion']++;
            }

            $resumen['atencion'][] = [
                'seguimiento_id' => (int)(
                    $aliado['seguimiento_id'] ?? 0
                ),
                'seguimiento_convocatoria_id' => (int)(
                    $aliado['seguimiento_convocatoria_id'] ?? 0
                ),
                'estado_id' => (int)($aliado['estado_id'] ?? 0),
                'institucion' => (string)(
                    $aliado['nombre_entidad'] ?? 'Aliado'
                ),
                'municipio' => (string)(
                    $aliado['municipio_nombre'] ?? ''
                ),
                'estado' => $estado,
                'estado_label' => $this->etiquetaSeguimientoAliado(
                    $estado,
                    $vencido
                ),
                'proximo_seguimiento_at' => $proximo,
                'prioridad' => $prioridad,
                'vencido' => $vencido,
                'url' => $this->urlAliado($aliado)
            ];
        }

        usort(
            $resumen['atencion'],
            static function ($a, $b) {
                $prioridad =
                    (int)$b['prioridad'] <=>
                    (int)$a['prioridad'];

                if ($prioridad !== 0) {
                    return $prioridad;
                }

                return strcmp(
                    (string)($a['proximo_seguimiento_at'] ?? ''),
                    (string)($b['proximo_seguimiento_at'] ?? '')
                );
            }
        );

        $resumen['atencion'] = array_slice(
            $resumen['atencion'],
            0,
            4
        );

        return $resumen;
    }

    private function construirCobertura(
        array $territorios,
        array $cartera,
        array $atenciones,
        array $aliados
    ) {
        $porEstado = [];

        foreach ($territorios as $territorio) {
            $estadoId = (int)($territorio['id'] ?? 0);
            if ($estadoId <= 0) {
                continue;
            }

            $porEstado[$estadoId] = [
                'id' => $estadoId,
                'nombre' => (string)(
                    $territorio['nombre_corto']
                        ?? $territorio['nombre']
                        ?? ''
                ),
                'analistas' => (int)(
                    $territorio['total_analistas'] ?? 0
                ),
                'seguimientos' => 0,
                'aliados' => 0,
                'requieren_atencion' => 0
            ];
        }

        $porMunicipio = [];

        foreach ($cartera as $seguimiento) {
            $estadoId = (int)($seguimiento['estado_id'] ?? 0);
            if (isset($porEstado[$estadoId])) {
                $porEstado[$estadoId]['seguimientos']++;
            }

            $municipio = trim(
                (string)($seguimiento['municipio'] ?? '')
            );
            if ($municipio === '') {
                continue;
            }

            $clave = $estadoId . ':' . $municipio;
            if (!isset($porMunicipio[$clave])) {
                $porMunicipio[$clave] = [
                    'nombre' => $municipio,
                    'estado_id' => $estadoId,
                    'seguimientos' => 0,
                    'aliados' => 0,
                    'requieren_atencion' => 0
                ];
            }
            $porMunicipio[$clave]['seguimientos']++;
        }

        foreach ($aliados as $aliado) {
            $estadoId = (int)($aliado['estado_id'] ?? 0);
            if (isset($porEstado[$estadoId])) {
                $porEstado[$estadoId]['aliados']++;
            }

            $municipio = trim(
                (string)($aliado['municipio_nombre'] ?? '')
            );
            if ($municipio === '') {
                continue;
            }

            $clave = $estadoId . ':' . $municipio;
            if (!isset($porMunicipio[$clave])) {
                $porMunicipio[$clave] = [
                    'nombre' => $municipio,
                    'estado_id' => $estadoId,
                    'seguimientos' => 0,
                    'aliados' => 0,
                    'requieren_atencion' => 0
                ];
            }
            $porMunicipio[$clave]['aliados']++;
        }

        foreach ($atenciones as $atencion) {
            $estadoId = (int)($atencion['estado_id'] ?? 0);
            if (isset($porEstado[$estadoId])) {
                $porEstado[$estadoId]['requieren_atencion']++;
            }

            $municipio = trim(
                (string)($atencion['municipio'] ?? '')
            );
            if ($municipio === '') {
                continue;
            }

            $clave = $estadoId . ':' . $municipio;
            if (isset($porMunicipio[$clave])) {
                $porMunicipio[$clave]['requieren_atencion']++;
            }
        }

        if (count($porEstado) > 1) {
            $items = array_values($porEstado);
            usort($items, static function ($a, $b) {
                $atencion =
                    (int)$b['requieren_atencion'] <=>
                    (int)$a['requieren_atencion'];
                return $atencion !== 0
                    ? $atencion
                    : (
                        (int)$b['seguimientos'] <=>
                        (int)$a['seguimientos']
                    );
            });

            return [
                'modo' => 'estados',
                'titulo' => 'Cobertura por territorio',
                'items' => array_slice($items, 0, 6)
            ];
        }

        $items = array_values($porMunicipio);
        usort($items, static function ($a, $b) {
            $cargaA =
                (int)$a['seguimientos'] +
                (int)$a['aliados'];
            $cargaB =
                (int)$b['seguimientos'] +
                (int)$b['aliados'];

            return $cargaB <=> $cargaA;
        });

        return [
            'modo' => 'municipios',
            'titulo' => 'Cobertura municipal',
            'items' => array_slice($items, 0, 6)
        ];
    }

    private function etiquetaSeguimientoAliado(
        $estado,
        $vencido
    ) {
        if ($vencido) {
            return 'Seguimiento vencido';
        }

        $mapa = [
            'ESPERANDO_RESPUESTA' => 'Esperando respuesta',
            'SIN_RESPUESTA' => 'Sin respuesta',
            'SOLICITA_INFORMACION' => 'Solicita información'
        ];

        return $mapa[$estado] ?? 'Seguimiento pendiente';
    }

    private function urlAliado(array $aliado)
    {
        $estadoId = (int)($aliado['estado_id'] ?? 0);
        $seguimientoId = (int)($aliado['seguimiento_id'] ?? 0);
        $seguimientoConvocatoriaId = (int)(
            $aliado['seguimiento_convocatoria_id'] ?? 0
        );

        if ($estadoId <= 0) {
            return BASE_URL .
                'index.php?controller=aliado&action=index';
        }

        $url =
            BASE_URL .
            'index.php?controller=aliado&action=estado&estado_id=' .
            $estadoId;

        if ($seguimientoId > 0) {
            $url .= '&abrir_seguimiento=' . $seguimientoId;
        }

        if ($seguimientoConvocatoriaId > 0) {
            $url .=
                '&seguimiento_convocatoria_id=' .
                $seguimientoConvocatoriaId;
        }

        return $url;
    }

    private function tableroVacio()
    {
        return [
            'resumen' => [
                'territorios' => 0,
                'analistas' => 0,
                'seguimientos_activos' => 0,
                'requieren_atencion' => 0,
                'aliados' => 0
            ],
            'atenciones' => [],
            'analistas' => [],
            'aliados' => [
                'visible' => false,
                'total' => 0,
                'con_difusion' => 0,
                'pendientes' => 0,
                'vencidos' => 0,
                'confirmados' => 0,
                'requieren_atencion' => 0,
                'atencion' => []
            ],
            'cobertura' => [
                'modo' => 'estados',
                'titulo' => 'Cobertura territorial',
                'items' => []
            ],
            'permisos' => [
                'seguimiento' => false,
                'aliados' => false
            ]
        ];
    }
}
