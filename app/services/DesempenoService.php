<?php

require_once __DIR__ . '/../models/DesempenoModel.php';
require_once __DIR__ . '/../models/SeguimientoVinculacionModel.php';

class DesempenoService
{
    private $modelo;
    private $seguimientos;

    public function __construct()
    {
        $this->modelo = new DesempenoModel();
        $this->seguimientos = new SeguimientoVinculacionModel();
    }

    public function preparar(
        $usuarioId,
        $rolNombre,
        $puedeGlobal,
        $puedeEquipo,
        $puedePropio,
        array $filtros = []
    ) {
        $usuarioId = (int)$usuarioId;
        $rolNombre = trim((string)$rolNombre);

        $vista = $this->resolverVista(
            $rolNombre,
            (bool)$puedeGlobal,
            (bool)$puedeEquipo,
            (bool)$puedePropio,
            (string)($filtros['vista'] ?? '')
        );

        $area = $this->resolverArea(
            $vista,
            $rolNombre,
            (string)($filtros['area'] ?? '')
        );

        $periodo = $this->resolverPeriodo($filtros);
        $territorios = $this->obtenerTerritorios(
            $vista,
            $usuarioId,
            $rolNombre
        );

        $estadoId = max(0, (int)($filtros['estado_id'] ?? 0));
        if (
            $estadoId > 0 &&
            !$this->territorioPermitido($territorios, $estadoId)
        ) {
            $estadoId = 0;
        }

        $personas = $this->obtenerPersonas(
            $vista,
            $area,
            $usuarioId
        );

        $personaId = max(0, (int)($filtros['persona_id'] ?? 0));
        if (
            $personaId > 0 &&
            !$this->personaPermitida($personas, $personaId)
        ) {
            $personaId = 0;
        }

        if ($personaId > 0) {
            $personasAnalizadas = array_values(array_filter(
                $personas,
                static function ($persona) use ($personaId) {
                    return (int)($persona['id'] ?? 0) === $personaId;
                }
            ));
        } else {
            $personasAnalizadas = $personas;
        }

        $desdeSql =
            (string)$periodo['fecha_desde'] . ' 00:00:00';
        $hastaSql =
            (string)$periodo['fecha_hasta'] . ' 23:59:59';

        if ($area === 'cuenta_clave') {
            $metricas = $this->modelo->obtenerMetricasCuentaClave(
                $personasAnalizadas,
                $desdeSql,
                $hastaSql,
                $estadoId
            );
            $tendenciaRaw = $this->modelo->obtenerTendenciaCuentaClave(
                array_column($personasAnalizadas, 'id'),
                $desdeSql,
                $hastaSql,
                $estadoId
            );
        } else {
            $metricas = $this->modelo->obtenerMetricasAnalistas(
                $personasAnalizadas,
                $desdeSql,
                $hastaSql,
                $estadoId
            );
            $tendenciaRaw = $this->modelo->obtenerTendenciaAnalistas(
                array_column($personasAnalizadas, 'id'),
                $desdeSql,
                $hastaSql,
                $estadoId
            );
        }

        $ranking = $this->construirRanking(
            $metricas,
            $area
        );

        $resumen = $this->construirResumen(
            $ranking,
            $area
        );

        $reconocimientos = count($ranking) > 1
            ? $this->construirReconocimientos(
                $ranking,
                $area
            )
            : [];

        $tendencia = $this->normalizarTendencia(
            $periodo,
            $tendenciaRaw,
            $area
        );

        return [
            'vista' => $vista,
            'area' => $area,
            'area_label' => $area === 'cuenta_clave'
                ? 'Cuenta Clave'
                : 'Analistas',
            'periodo' => $periodo,
            'territorios' => $territorios,
            'personas' => $personas,
            'persona_id' => $personaId,
            'estado_id' => $estadoId,
            'ranking' => $ranking,
            'resumen' => $resumen,
            'reconocimientos' => $reconocimientos,
            'tendencia' => $tendencia,
            'criterios' => $this->criterios($area),
            'actualizado_at' => date('d/m/Y H:i:s'),
            'puede_cambiar_area' => $vista === 'global',
            'puede_cambiar_persona' =>
                $vista !== 'propio' && count($personas) > 1
        ];
    }

    private function resolverVista(
        $rolNombre,
        $puedeGlobal,
        $puedeEquipo,
        $puedePropio,
        $solicitada
    ) {
        $solicitada = strtolower(trim((string)$solicitada));
        $esCuentaClave =
            strcasecmp($rolNombre, 'Cuenta Clave') === 0;
        $esAnalista =
            strcasecmp($rolNombre, 'Analista de Datos') === 0;

        /*
         * Los perfiles con vista global organizacional (Administrador /
         * Dirección futura) no deben heredar artificialmente una vista
         * "Mi equipo" o "Mi desempeño" que no corresponde a su relación
         * territorial.
         */
        if (
            $puedeGlobal &&
            !$esCuentaClave &&
            !$esAnalista
        ) {
            return 'global';
        }

        if ($puedeGlobal) {
            if (
                in_array(
                    $solicitada,
                    ['global', 'equipo', 'propio'],
                    true
                )
            ) {
                if ($solicitada === 'equipo' && !$puedeEquipo) {
                    return 'global';
                }
                if ($solicitada === 'propio' && !$puedePropio) {
                    return 'global';
                }
                return $solicitada;
            }
            return 'global';
        }

        if ($puedeEquipo) {
            if ($solicitada === 'propio' && $puedePropio) {
                return 'propio';
            }
            return 'equipo';
        }

        if ($puedePropio) {
            return 'propio';
        }

        return 'sin_acceso';
    }

    private function resolverArea($vista, $rolNombre, $solicitada)
    {
        if ($vista === 'global') {
            $solicitada = strtolower(trim((string)$solicitada));
            return in_array(
                $solicitada,
                ['analistas', 'cuenta_clave'],
                true
            )
                ? $solicitada
                : 'analistas';
        }

        if ($vista === 'equipo') {
            return 'analistas';
        }

        return strcasecmp($rolNombre, 'Cuenta Clave') === 0
            ? 'cuenta_clave'
            : 'analistas';
    }

    private function obtenerTerritorios($vista, $usuarioId, $rolNombre)
    {
        if ($vista === 'global') {
            return $this->seguimientos->obtenerEstadosAdministrador();
        }

        if (
            $vista === 'equipo' ||
            strcasecmp($rolNombre, 'Cuenta Clave') === 0
        ) {
            return $this->seguimientos
                ->obtenerEstadosSupervisadosCuentaClave($usuarioId);
        }

        return $this->seguimientos
            ->obtenerEstadosAsignadosAnalista($usuarioId);
    }

    private function obtenerPersonas($vista, $area, $usuarioId)
    {
        if ($vista === 'global') {
            return $this->modelo->obtenerUsuariosPorRol(
                $area === 'cuenta_clave'
                    ? 'Cuenta Clave'
                    : 'Analista de Datos'
            );
        }

        if ($vista === 'equipo') {
            $equipo = $this->seguimientos
                ->obtenerAnalistasSupervisadosCuentaClave(
                    $usuarioId
                );

            $ids = array_map(
                static fn($fila) => (int)($fila['id'] ?? 0),
                $equipo
            );

            return $this->modelo->obtenerUsuariosPorIds($ids);
        }

        return $this->modelo->obtenerUsuariosPorIds([
            $usuarioId
        ]);
    }

    private function resolverPeriodo(array $filtros)
    {
        $clave = strtolower(trim(
            (string)($filtros['periodo'] ?? 'semana')
        ));

        $permitidos = [
            'hoy',
            'ayer',
            'semana',
            'ultimos_7',
            'mes',
            'mes_anterior',
            'personalizado'
        ];

        if (!in_array($clave, $permitidos, true)) {
            $clave = 'semana';
        }

        $hoy = new DateTimeImmutable('today');
        $desde = $hoy;
        $hasta = $hoy;
        $label = 'Hoy';

        if ($clave === 'ayer') {
            $desde = $hoy->modify('-1 day');
            $hasta = $desde;
            $label = 'Ayer';
        } elseif ($clave === 'semana') {
            $desde = $hoy->modify('monday this week');
            $hasta = $hoy;
            $label = 'Esta semana';
        } elseif ($clave === 'ultimos_7') {
            $desde = $hoy->modify('-6 days');
            $hasta = $hoy;
            $label = 'Últimos 7 días';
        } elseif ($clave === 'mes') {
            $desde = $hoy->modify('first day of this month');
            $hasta = $hoy;
            $label = 'Este mes';
        } elseif ($clave === 'mes_anterior') {
            $desde = $hoy->modify('first day of last month');
            $hasta = $hoy->modify('last day of last month');
            $label = 'Mes anterior';
        } elseif ($clave === 'personalizado') {
            $desdePersonalizado = $this->parseFecha(
                $filtros['fecha_desde'] ?? ''
            );
            $hastaPersonalizado = $this->parseFecha(
                $filtros['fecha_hasta'] ?? ''
            );

            if (
                $desdePersonalizado &&
                $hastaPersonalizado &&
                $desdePersonalizado <= $hastaPersonalizado &&
                $hastaPersonalizado <= $hoy
            ) {
                $desde = $desdePersonalizado;
                $hasta = $hastaPersonalizado;
                $label =
                    $desde->format('d/m/Y') .
                    ' al ' .
                    $hasta->format('d/m/Y');
            } else {
                $clave = 'semana';
                $desde = $hoy->modify('monday this week');
                $hasta = $hoy;
                $label = 'Esta semana';
            }
        }

        return [
            'clave' => $clave,
            'label' => $label,
            'fecha_desde' => $desde->format('Y-m-d'),
            'fecha_hasta' => $hasta->format('Y-m-d'),
            'dias' => ((int)$desde->diff($hasta)->days) + 1
        ];
    }

    private function construirRanking(array $filas, $area)
    {
        if (empty($filas)) {
            return [];
        }

        if ($area === 'cuenta_clave') {
            $maxTrabajados = $this->maximo(
                $filas,
                'aliados_trabajados'
            );
            $maxDifusiones = $this->maximo(
                $filas,
                'difusiones'
            );
            $maxSeguimientos = $this->maximo(
                $filas,
                'seguimientos'
            );
            $maxConfirmaciones = $this->maximo(
                $filas,
                'confirmaciones'
            );

            foreach ($filas as &$fila) {
                $fila['indice'] = count($filas) > 1
                    ? round(
                        (
                            $this->normalizado(
                                $fila['aliados_trabajados'] ?? 0,
                                $maxTrabajados
                            ) * 25
                        ) +
                        (
                            $this->normalizado(
                                $fila['difusiones'] ?? 0,
                                $maxDifusiones
                            ) * 25
                        ) +
                        (
                            $this->normalizado(
                                $fila['seguimientos'] ?? 0,
                                $maxSeguimientos
                            ) * 25
                        ) +
                        (
                            $this->normalizado(
                                $fila['confirmaciones'] ?? 0,
                                $maxConfirmaciones
                            ) * 25
                        ),
                        1
                    )
                    : null;
                $fila['actividad_total'] =
                    (int)($fila['difusiones'] ?? 0) +
                    (int)($fila['seguimientos'] ?? 0);
            }
            unset($fila);
        } else {
            $maxEfectivas = $this->maximo(
                $filas,
                'llamadas_efectivas'
            );
            $maxInteracciones = $this->maximo(
                $filas,
                'interacciones'
            );
            $maxVerificaciones = $this->maximo(
                $filas,
                'verificaciones_efectivas'
            );

            foreach ($filas as &$fila) {
                $llamadas =
                    (int)($fila['llamadas_realizadas'] ?? 0);
                $efectivas =
                    (int)($fila['llamadas_efectivas'] ?? 0);
                $tasa = $llamadas > 0
                    ? ($efectivas / $llamadas) * 100
                    : 0.0;

                $fila['tasa_contacto'] = round($tasa, 1);
                $fila['indice'] = count($filas) > 1
                    ? round(
                        (
                            $this->normalizado(
                                $efectivas,
                                $maxEfectivas
                            ) * 30
                        ) +
                        (($tasa / 100) * 25) +
                        (
                            $this->normalizado(
                                $fila['interacciones'] ?? 0,
                                $maxInteracciones
                            ) * 25
                        ) +
                        (
                            $this->normalizado(
                                $fila['verificaciones_efectivas'] ?? 0,
                                $maxVerificaciones
                            ) * 20
                        ),
                        1
                    )
                    : null;
                $fila['actividad_total'] =
                    (int)($fila['interacciones'] ?? 0);
            }
            unset($fila);
        }

        usort($filas, static function ($a, $b) use ($area) {
            $indiceA = $a['indice'];
            $indiceB = $b['indice'];

            if ($indiceA !== null && $indiceB !== null) {
                $comparacion =
                    (float)$indiceB <=> (float)$indiceA;
                if ($comparacion !== 0) {
                    return $comparacion;
                }
            }

            $clave = $area === 'cuenta_clave'
                ? 'actividad_total'
                : 'llamadas_efectivas';

            $actividad =
                (int)($b[$clave] ?? 0) <=>
                (int)($a[$clave] ?? 0);

            if ($actividad !== 0) {
                return $actividad;
            }

            return strcasecmp(
                trim(
                    (string)($a['nombre'] ?? '') . ' ' .
                    (string)($a['apellidos'] ?? '')
                ),
                trim(
                    (string)($b['nombre'] ?? '') . ' ' .
                    (string)($b['apellidos'] ?? '')
                )
            );
        });

        foreach ($filas as $indice => &$fila) {
            $fila['posicion'] = $indice + 1;
            $fila['nombre_completo'] = trim(
                (string)($fila['nombre'] ?? '') . ' ' .
                (string)($fila['apellidos'] ?? '')
            );
        }
        unset($fila);

        return $filas;
    }

    private function construirResumen(array $ranking, $area)
    {
        if ($area === 'cuenta_clave') {
            return [
                'personas' => count($ranking),
                'aliados_trabajados' => array_sum(
                    array_column($ranking, 'aliados_trabajados')
                ),
                'difusiones' => array_sum(
                    array_column($ranking, 'difusiones')
                ),
                'seguimientos' => array_sum(
                    array_column($ranking, 'seguimientos')
                ),
                'confirmaciones' => array_sum(
                    array_column($ranking, 'confirmaciones')
                )
            ];
        }

        $llamadas = (int)array_sum(
            array_column($ranking, 'llamadas_realizadas')
        );
        $efectivas = (int)array_sum(
            array_column($ranking, 'llamadas_efectivas')
        );

        return [
            'personas' => count($ranking),
            'llamadas_realizadas' => $llamadas,
            'llamadas_efectivas' => $efectivas,
            'tasa_contacto' => $llamadas > 0
                ? round(($efectivas / $llamadas) * 100, 1)
                : 0.0,
            'interacciones' => array_sum(
                array_column($ranking, 'interacciones')
            ),
            'verificaciones_efectivas' => array_sum(
                array_column($ranking, 'verificaciones_efectivas')
            )
        ];
    }

    private function construirReconocimientos(
        array $ranking,
        $area
    ) {
        if ($area === 'cuenta_clave') {
            return array_values(array_filter([
                $this->reconocimiento(
                    $ranking,
                    'aliados_trabajados',
                    'Mayor gestión de aliados',
                    'bi-building-check',
                    'aliados trabajados'
                ),
                $this->reconocimiento(
                    $ranking,
                    'difusiones',
                    'Mayor difusión',
                    'bi-megaphone',
                    'difusiones'
                ),
                $this->reconocimiento(
                    $ranking,
                    'seguimientos',
                    'Mayor continuidad',
                    'bi-arrow-repeat',
                    'actualizaciones'
                ),
                $this->reconocimiento(
                    $ranking,
                    'confirmaciones',
                    'Más confirmaciones',
                    'bi-patch-check',
                    'confirmaciones'
                )
            ]));
        }

        $reconocimientos = [
            $this->reconocimiento(
                $ranking,
                'llamadas_efectivas',
                'Mayor contacto efectivo',
                'bi-telephone-check',
                'llamadas efectivas'
            ),
            $this->reconocimiento(
                $ranking,
                'interacciones',
                'Mayor actividad útil',
                'bi-activity',
                'interacciones'
            ),
            $this->reconocimiento(
                $ranking,
                'verificaciones_efectivas',
                'Mayor verificación efectiva',
                'bi-shield-check',
                'verificaciones'
            )
        ];

        $elegiblesTasa = array_values(array_filter(
            $ranking,
            static function ($fila) {
                return (int)($fila['llamadas_realizadas'] ?? 0) >= 3;
            }
        ));

        if (!empty($elegiblesTasa)) {
            $reconocimientos[] = $this->reconocimiento(
                $elegiblesTasa,
                'tasa_contacto',
                'Mejor efectividad',
                'bi-bullseye',
                '% de contacto',
                true
            );
        }

        return array_values(array_filter($reconocimientos));
    }

    private function reconocimiento(
        array $filas,
        $clave,
        $titulo,
        $icono,
        $unidad,
        $decimal = false
    ) {
        if (empty($filas)) {
            return null;
        }

        usort($filas, static function ($a, $b) use ($clave) {
            return (float)($b[$clave] ?? 0) <=>
                (float)($a[$clave] ?? 0);
        });

        $ganador = $filas[0];
        $valor = (float)($ganador[$clave] ?? 0);

        if ($valor <= 0) {
            return null;
        }

        return [
            'titulo' => $titulo,
            'icono' => $icono,
            'usuario_id' => (int)($ganador['id'] ?? 0),
            'nombre' => (string)(
                $ganador['nombre_completo']
                    ?? trim(
                        (string)($ganador['nombre'] ?? '') . ' ' .
                        (string)($ganador['apellidos'] ?? '')
                    )
            ),
            'foto_perfil' => (string)(
                $ganador['foto_perfil'] ?? ''
            ),
            'valor' => $decimal
                ? number_format($valor, 1)
                : number_format($valor, 0),
            'unidad' => $unidad
        ];
    }

    private function normalizarTendencia(
        array $periodo,
        array $filas,
        $area
    ) {
        $porFecha = [];
        foreach ($filas as $fila) {
            $fecha = (string)($fila['fecha'] ?? '');
            if ($fecha !== '') {
                $porFecha[$fecha] = $fila;
            }
        }

        $inicio = new DateTimeImmutable($periodo['fecha_desde']);
        $fin = new DateTimeImmutable($periodo['fecha_hasta']);
        $salida = [];

        for (
            $fecha = $inicio;
            $fecha <= $fin;
            $fecha = $fecha->modify('+1 day')
        ) {
            $clave = $fecha->format('Y-m-d');
            $fila = $porFecha[$clave] ?? [];

            $salida[] = $area === 'cuenta_clave'
                ? [
                    'fecha' => $clave,
                    'label' => $fecha->format('d/m'),
                    'principal' => (int)($fila['difusiones'] ?? 0),
                    'secundario' => (int)($fila['seguimientos'] ?? 0),
                    'terciario' => (int)($fila['confirmaciones'] ?? 0)
                ]
                : [
                    'fecha' => $clave,
                    'label' => $fecha->format('d/m'),
                    'principal' => (int)($fila['interacciones'] ?? 0),
                    'secundario' => (int)($fila['efectivas'] ?? 0),
                    'terciario' => 0
                ];
        }

        return $salida;
    }

    private function criterios($area)
    {
        if ($area === 'cuenta_clave') {
            return [
                'El índice operativo, cuando existe más de una persona, pondera por igual aliados trabajados, difusiones, actualizaciones de seguimiento y confirmaciones.',
                'La vista es informativa y no asigna automáticamente bonos o incentivos.',
                'Los datos se calculan con los movimientos registrados dentro del periodo seleccionado.'
            ];
        }

        return [
            'Llamada realizada: llamada IP vinculada a proveedor, identificador externo y duración mayor a cero.',
            'Llamada efectiva: llamada válida con contacto real registrado; las llamadas de prueba se excluyen.',
            'Verificación efectiva: evidencia de verificación asociada a una llamada telefónica válida.',
            'El índice operativo pondera llamadas efectivas (30%), tasa de contacto (25%), interacciones útiles (25%) y verificaciones efectivas (20%).',
            'El ranking es un apoyo de gestión; no decide automáticamente bonos, sanciones ni incentivos.'
        ];
    }

    private function maximo(array $filas, $clave)
    {
        $valores = array_map(
            static fn($fila) => (float)($fila[$clave] ?? 0),
            $filas
        );

        return empty($valores) ? 0.0 : max($valores);
    }

    private function normalizado($valor, $maximo)
    {
        $maximo = (float)$maximo;
        return $maximo > 0
            ? min(1, max(0, (float)$valor / $maximo))
            : 0.0;
    }

    private function parseFecha($valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return null;
        }

        $fecha = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $valor
        );
        $errores = DateTimeImmutable::getLastErrors();

        if (
            !$fecha ||
            (
                is_array($errores) &&
                (
                    (int)($errores['warning_count'] ?? 0) > 0 ||
                    (int)($errores['error_count'] ?? 0) > 0
                )
            )
        ) {
            return null;
        }

        return $fecha;
    }

    private function territorioPermitido(
        array $territorios,
        $estadoId
    ) {
        foreach ($territorios as $territorio) {
            if ((int)($territorio['id'] ?? 0) === (int)$estadoId) {
                return true;
            }
        }

        return false;
    }

    private function personaPermitida(array $personas, $personaId)
    {
        foreach ($personas as $persona) {
            if ((int)($persona['id'] ?? 0) === (int)$personaId) {
                return true;
            }
        }

        return false;
    }
}
