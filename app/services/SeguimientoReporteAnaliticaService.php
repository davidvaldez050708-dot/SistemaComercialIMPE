<?php
require_once __DIR__ . '/../helpers/SeguimientoTerritorialScope.php';

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/SeguimientoAtencionOperativaService.php';
require_once __DIR__ . '/../helpers/TelefoniaFechaHoraHelper.php';

class SeguimientoReporteAnaliticaService
{
    private $connection;
    private $fuenteActividad;
    private array $contextoFuente=[],$idsAutorizadosCache=[];

    public function __construct($connection = null)
    {
        $this->connection = $connection ?: (new Database())->connect();
    }

    private function fuente(array $ids,string $desde,string $hasta,string $canal,array $actores,int $usuario=0,string $modo='administrador'): array
    {
        $contexto=$this->contextoFuente;
        return ($this->fuenteActividad??=new SeguimientoReporteActividadFuente($this->connection))->obtener(
            $ids,$desde,$hasta,$canal,$actores,(int)($contexto['usuario']??$usuario),(string)($contexto['modo']??$modo),(bool)($contexto['sin_expediente']??false));
    }

    public function obtenerIdsConActividad(array $ids,int $usuario,string $modo,string $desde,string $hasta,string $canal,array $actores,bool $sinExpediente=false): array
    {
        if($usuario<=0)return [];
        if($modo==='analista')$actores=[$usuario];
        $this->contextoFuente=['usuario'=>$usuario,'modo'=>$modo,'sin_expediente'=>$sinExpediente];
        $ids=$this->filtrarIdsAutorizados($this->normalizarIds($ids),$usuario,$modo);
        return $this->fuente($ids,$desde,$hasta,$canal,$this->normalizarIds($actores))['ids_actividad'];
    }

    public function construir(
        array $seguimientoIds,
        $usuarioId,
        $modoAcceso,
        $fechaInicial = '',
        $fechaFinal = '',
        $canal = '',
        $limiteActividadReciente = 60,
        array $actorIds = [],
        bool $incluirNoVinculadas = false
    ) {
        $seguimientoIds = $this->normalizarIds($seguimientoIds);
        $usuarioId = (int)$usuarioId;
        $modoAcceso = (string)$modoAcceso;
        if(!in_array($modoAcceso,['administrador','supervisor','analista'],true))return $this->estructuraVacia();
        $this->contextoFuente=['usuario'=>$usuarioId,'modo'=>$modoAcceso,'sin_expediente'=>$incluirNoVinculadas];
        $actorIds = $this->normalizarIds($actorIds);

        if ($modoAcceso === 'analista') {
            $actorIds = [$usuarioId];
        }

        if ((empty($seguimientoIds) && !$incluirNoVinculadas) || $usuarioId <= 0) {
            return $this->estructuraVacia();
        }

        $autorizados = $this->filtrarIdsAutorizados(
            $seguimientoIds,
            $usuarioId,
            $modoAcceso
        );

        if (empty($autorizados) && !$incluirNoVinculadas) {
            return $this->estructuraVacia();
        }

        $fechaInicial = $this->normalizarFecha($fechaInicial);
        $fechaFinal = $this->normalizarFecha($fechaFinal);
        $canal = strtoupper(trim((string)$canal));
        $resumenInteracciones = $this->obtenerResumenInteracciones(
            $autorizados,
            $fechaInicial,
            $fechaFinal,
            $usuarioId,
            $modoAcceso,
            $canal,
            $actorIds
        );

        $canales = [
            'llamadas' => (int)($resumenInteracciones['llamadas'] ?? 0),
            'correos' => (int)($resumenInteracciones['correos'] ?? 0),
            'whatsapp' => (int)($resumenInteracciones['whatsapp'] ?? 0),
            'otros' => (int)($resumenInteracciones['otros'] ?? 0)
        ];
        $llamadas = [
            'total' => (int)($resumenInteracciones['llamadas'] ?? 0),
            'contactadas' => (int)($resumenInteracciones['contactadas'] ?? 0),
            'sin_respuesta' => (int)($resumenInteracciones['sin_respuesta'] ?? 0),
            'numero_incorrecto' => (int)($resumenInteracciones['numero_incorrecto'] ?? 0),
            'volver_llamar' => (int)($resumenInteracciones['volver_llamar'] ?? 0),
            'otros' => (int)($resumenInteracciones['llamadas_otros'] ?? 0),
            'verificaciones_efectivas' => (int)($resumenInteracciones['verificaciones_efectivas'] ?? 0),
            'tasa_contacto' => 0.0
        ];

        if ($llamadas['total'] > 0) {
            $llamadas['tasa_contacto'] = round(
                ($llamadas['contactadas'] / $llamadas['total']) * 100,
                1
            );
        }

        $totalSeguimientos = count($autorizados);
        $totalInteracciones = (int)($resumenInteracciones['total_interacciones'] ?? 0);
        $totalConActividad = (int)($resumenInteracciones['seguimientos_con_actividad'] ?? 0);
        $atenciones = (new SeguimientoAtencionOperativaService($this->connection))->obtenerPorIds($autorizados);
        $totalAtencion = count($atenciones);
        $actividadReciente = $this->obtenerActividadReciente(
            $autorizados,
            $fechaInicial,
            $fechaFinal,
            $usuarioId,
            $modoAcceso,
            $canal,
            max(1, min(500, (int)$limiteActividadReciente)),
            $actorIds
        );
        $rendimientoTelefonicoDiario = $this->obtenerRendimientoTelefonicoDiario(
            $autorizados,
            $fechaInicial,
            $fechaFinal,
            $usuarioId,
            $modoAcceso,
            $canal,
            $actorIds
        );
        $cantidadActoresMeta = $modoAcceso === 'supervisor'
            ? max(1, count($actorIds))
            : 1;
        $metaDiariaEquipo = 25 * $cantidadActoresMeta;
        $rendimientoTelefonico = $this->resumirRendimientoTelefonico(
            $rendimientoTelefonicoDiario,
            $fechaInicial,
            $fechaFinal,
            $metaDiariaEquipo
        );
        $cumplimientoEfectivas = $this->calcularCumplimientoEfectivas(
            $rendimientoTelefonicoDiario,
            $fechaInicial,
            $fechaFinal,
            $cantidadActoresMeta
        );
        $rendimientoTelefonicoHoy = $this->rendimientoTelefonicoHoy(
            $rendimientoTelefonicoDiario
        );
        $institucionesActividad = $this->obtenerInstitucionesActividad(
            $autorizados,
            $fechaInicial,
            $fechaFinal,
            $usuarioId,
            $modoAcceso,
            $canal,
            6,
            $actorIds
        );
        $actividadPorActor = $this->obtenerActividadPorActor(
            $autorizados,
            $fechaInicial,
            $fechaFinal,
            $canal,
            $actorIds
        );

        return [
            'seguimientos_considerados' => $totalSeguimientos,
            'interacciones' => $totalInteracciones,
            'llamadas_sin_expediente' => $this->fuente($autorizados,$fechaInicial,$fechaFinal,$canal,$actorIds)['llamadas_sin_expediente'],
            'seguimientos_con_actividad' => $totalConActividad,
            'cobertura_actividad' => $totalSeguimientos > 0
                ? round(($totalConActividad / $totalSeguimientos) * 100, 1)
                : 0.0,
            'promedio_por_seguimiento' => $totalSeguimientos > 0
                ? round($totalInteracciones / $totalSeguimientos, 1)
                : 0.0,
            'canales' => $canales,
            'llamadas' => $llamadas,
            'actividad_reciente' => $actividadReciente,
            'rendimiento_telefonico_diario' => $rendimientoTelefonicoDiario,
            'rendimiento_telefonico' => $rendimientoTelefonico,
            'rendimiento_telefonico_hoy' => $rendimientoTelefonicoHoy,
            'instituciones_actividad' => $institucionesActividad,
            'actividad_por_actor' => $actividadPorActor,
            'cumplimiento_efectivas' => $cumplimientoEfectivas,
            'meta_diaria_efectivas' => 25,
            'atencion' => [
                'total' => $totalAtencion,
                'porcentaje' => $totalSeguimientos > 0
                    ? round(($totalAtencion / $totalSeguimientos) * 100, 1)
                    : 0.0,
                'casos' => array_map(static function ($item) {
                    return [
                        'id' => (int)($item['id'] ?? 0),
                        'nombre_entidad' => (string)($item['nombre_entidad'] ?? 'Institución'),
                        'municipio' => (string)($item['municipio'] ?? ''),
                        'estado_nombre' => (string)($item['estado_nombre'] ?? ''),
                        'estado_seguimiento' => (string)($item['estado_seguimiento'] ?? ''),
                        'tipo' => (string)($item['tipo_atencion'] ?? ''),
                        'motivo' => (string)($item['motivo_atencion'] ?? ''),
                        'prioridad' => (int)($item['prioridad'] ?? 0),
                        'fecha_referencia' => (string)($item['fecha_referencia'] ?? '')
                    ];
                }, $atenciones)
            ],
            'periodo' => [
                'fecha_inicial' => $fechaInicial,
                'fecha_final' => $fechaFinal,
                'canal' => $canal
            ]
        ];
    }

    private function filtrarIdsAutorizados(array $ids, $usuarioId, $modoAcceso)
    {
        if (empty($ids)) {
            return [];
        }

        $clave=json_encode([$ids,(int)$usuarioId,(string)$modoAcceso]);
        if(isset($this->idsAutorizadosCache[$clave]))return $this->idsAutorizadosCache[$clave];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $parametros = array_map('intval', $ids);
        $tipos = str_repeat('i', count($parametros));

        if ($modoAcceso === 'administrador') {
            $sql = "SELECT DISTINCT s.id
                    FROM seguimientos_vinculacion s
                    WHERE s.id IN ($placeholders)
                      AND s.activo = 1";
        } elseif ($modoAcceso === 'supervisor') {
            $sql = "SELECT DISTINCT s.id FROM seguimientos_vinculacion s
                    WHERE s.id IN ($placeholders) AND s.activo = 1 AND "
                . SeguimientoTerritorialScope::supervisor('s');
            $parametros[] = $usuarioId;
            $tipos .= 'i';
        } else {
            $sql = "SELECT DISTINCT s.id
                    FROM seguimientos_vinculacion s
                    WHERE s.id IN ($placeholders)
                      AND s.activo = 1
                      AND " . SeguimientoTerritorialScope::analista('s') . "";
            $parametros[] = $usuarioId;
            $tipos .= 'i';
        }

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $autorizados = [];

        while ($fila = $resultado->fetch_assoc()) {
            $id = (int)($fila['id'] ?? 0);
            if ($id > 0) {
                $autorizados[$id] = $id;
            }
        }

        return $this->idsAutorizadosCache[$clave]=array_values($autorizados);
    }

    private function obtenerResumenInteracciones(
        array $ids,
        $fechaInicial,
        $fechaFinal,
        $usuarioId,
        $modoAcceso,
        $canal,
        array $actorIds = []
    ) {
        return $this->fuente($ids,$fechaInicial,$fechaFinal,$canal,$actorIds,(int)$usuarioId,(string)$modoAcceso)['resumen'];
    }

    private function obtenerActividadReciente(
        array $ids,
        $fechaInicial,
        $fechaFinal,
        $usuarioId,
        $modoAcceso,
        $canal,
        $limite = 60,
        array $actorIds = []
    ) {
        return array_slice($this->fuente($ids,$fechaInicial,$fechaFinal,$canal,$actorIds,(int)$usuarioId,(string)$modoAcceso)['humanas'],0,max(1,min(100,(int)$limite)));
    }

    private function obtenerRendimientoTelefonicoDiario(
        array $ids,
        $fechaInicial,
        $fechaFinal,
        $usuarioId,
        $modoAcceso,
        $canal,
        array $actorIds = []
    ) {
        if($canal!==''&&!in_array($canal,['LLAMADA','LLAMADA_IP'],true))return [];
        $porFecha=[];
        foreach($this->fuente($ids,$fechaInicial,$fechaFinal,$canal,$actorIds,(int)$usuarioId,(string)$modoAcceso)['diario'] as $fila){
            $fecha = (string)($fila['fecha'] ?? '');
            if ($fecha === '') {
                continue;
            }

            $llamadas = max(0, (int)($fila['llamadas'] ?? 0));
            $contacto = max(0, (int)($fila['con_contacto'] ?? 0));
            $efectivas = max(0, (int)($fila['efectivas'] ?? 0));
            $porFecha[$fecha] = [
                'fecha' => $fecha,
                'llamadas' => $llamadas,
                'con_contacto' => $contacto,
                'efectivas' => $efectivas,
                'tasa_contacto' => $llamadas > 0
                    ? round(($contacto / $llamadas) * 100, 1)
                    : 0.0,
                'meta' => 25,
                'cumplimiento_pct' => round(min(100, ($efectivas / 25) * 100), 1)
            ];
        }

        if ($fechaInicial !== '' && $fechaFinal !== '') {
            try {
                $inicio = new DateTimeImmutable($fechaInicial);
                $fin = new DateTimeImmutable($fechaFinal);
                $dias = ((int)$inicio->diff($fin)->days) + 1;

                if ($inicio <= $fin && $dias <= 62) {
                    $completo = [];
                    for ($fecha = $inicio; $fecha <= $fin; $fecha = $fecha->modify('+1 day')) {
                        $clave = $fecha->format('Y-m-d');
                        $completo[] = $porFecha[$clave] ?? [
                            'fecha' => $clave,
                            'llamadas' => 0,
                            'con_contacto' => 0,
                            'efectivas' => 0,
                            'tasa_contacto' => 0.0,
                            'meta' => 25,
                            'cumplimiento_pct' => 0.0
                        ];
                    }

                    return $completo;
                }
            } catch (Throwable $error) {
                // Si el periodo no puede expandirse, se conservan únicamente los días con datos.
            }
        }

        return array_values($porFecha);
    }

    private function resumirRendimientoTelefonico(
        array $diasRegistrados,
        $fechaInicial,
        $fechaFinal,
        $metaDiaria = 25
    ) {
        $metaDiaria = max(1, (int)$metaDiaria);
        $fechaInicial = $this->normalizarFecha($fechaInicial);
        $fechaFinal = $this->normalizarFecha($fechaFinal);

        if ($fechaInicial === '' || $fechaFinal === '') {
            return [
                'granularidad' => 'dia',
                'periodos' => $diasRegistrados
            ];
        }

        try {
            $inicio = new DateTimeImmutable($fechaInicial);
            $fin = new DateTimeImmutable($fechaFinal);
        } catch (Throwable $error) {
            return [
                'granularidad' => 'dia',
                'periodos' => $diasRegistrados
            ];
        }

        if ($inicio > $fin) {
            return [
                'granularidad' => 'dia',
                'periodos' => []
            ];
        }

        $porFecha = [];
        foreach ($diasRegistrados as $dia) {
            $clave = trim((string)($dia['fecha'] ?? ''));
            if ($clave !== '') {
                $porFecha[$clave] = $dia;
            }
        }

        $diasPeriodo = ((int)$inicio->diff($fin)->days) + 1;
        $granularidad = $diasPeriodo <= 14
            ? 'dia'
            : ($diasPeriodo <= 90 ? 'semana' : 'mes');

        if ($granularidad === 'dia') {
            $periodos = [];
            for ($fecha = $inicio; $fecha <= $fin; $fecha = $fecha->modify('+1 day')) {
                $clave = $fecha->format('Y-m-d');
                $base = $porFecha[$clave] ?? [
                    'fecha' => $clave,
                    'llamadas' => 0,
                    'con_contacto' => 0,
                    'efectivas' => 0,
                    'tasa_contacto' => 0.0,
                    'meta' => 25,
                    'cumplimiento_pct' => 0.0
                ];
                $base['meta'] = $metaDiaria;
                $base['cumplimiento_pct'] = round(
                    min(100, ((int)($base['efectivas'] ?? 0) / $metaDiaria) * 100),
                    1
                );
                $base['clave'] = $clave;
                $base['etiqueta'] = $fecha->format('d/m/Y');
                $base['subetiqueta'] = '';
                $base['fecha_inicio'] = $clave;
                $base['fecha_fin'] = $clave;
                $periodos[] = $base;
            }

            return [
                'granularidad' => 'dia',
                'periodos' => $periodos
            ];
        }

        if ($granularidad === 'semana') {
            $periodos = [];
            $inicioBloque = $inicio;
            $numero = 1;

            while ($inicioBloque <= $fin) {
                $finBloque = $inicioBloque->modify('+6 days');
                if ($finBloque > $fin) {
                    $finBloque = $fin;
                }

                $totales = $this->sumarRendimientoEntre(
                    $porFecha,
                    $inicioBloque,
                    $finBloque
                );
                $periodos[] = array_merge($totales, [
                    'clave' => 'semana_' . $numero,
                    'etiqueta' => 'Semana ' . $numero,
                    'subetiqueta' =>
                        $inicioBloque->format('d/m') . ' - ' . $finBloque->format('d/m'),
                    'fecha_inicio' => $inicioBloque->format('Y-m-d'),
                    'fecha_fin' => $finBloque->format('Y-m-d'),
                    'meta' => null,
                    'cumplimiento_pct' => null
                ]);

                $numero++;
                $inicioBloque = $finBloque->modify('+1 day');
            }

            return [
                'granularidad' => 'semana',
                'periodos' => $periodos
            ];
        }

        $periodos = [];
        $mes = $inicio->modify('first day of this month');
        $ultimoMes = $fin->modify('first day of this month');

        while ($mes <= $ultimoMes) {
            $inicioBloque = $mes < $inicio ? $inicio : $mes;
            $finBloque = $mes->modify('last day of this month');
            if ($finBloque > $fin) {
                $finBloque = $fin;
            }

            $totales = $this->sumarRendimientoEntre(
                $porFecha,
                $inicioBloque,
                $finBloque
            );
            $periodos[] = array_merge($totales, [
                'clave' => $mes->format('Y-m'),
                'etiqueta' => $this->mesCortoReporte((int)$mes->format('n')) . ' ' . $mes->format('Y'),
                'subetiqueta' => '',
                'fecha_inicio' => $inicioBloque->format('Y-m-d'),
                'fecha_fin' => $finBloque->format('Y-m-d'),
                'meta' => null,
                'cumplimiento_pct' => null
            ]);

            $mes = $mes->modify('first day of next month');
        }

        return [
            'granularidad' => 'mes',
            'periodos' => $periodos
        ];
    }

    private function sumarRendimientoEntre(
        array $porFecha,
        DateTimeImmutable $inicio,
        DateTimeImmutable $fin
    ) {
        $llamadas = 0;
        $contacto = 0;
        $efectivas = 0;

        for ($fecha = $inicio; $fecha <= $fin; $fecha = $fecha->modify('+1 day')) {
            $fila = $porFecha[$fecha->format('Y-m-d')] ?? [];
            $llamadas += (int)($fila['llamadas'] ?? 0);
            $contacto += (int)($fila['con_contacto'] ?? 0);
            $efectivas += (int)($fila['efectivas'] ?? 0);
        }

        return [
            'llamadas' => $llamadas,
            'con_contacto' => $contacto,
            'efectivas' => $efectivas,
            'tasa_contacto' => $llamadas > 0
                ? round(($contacto / $llamadas) * 100, 1)
                : 0.0
        ];
    }

    private function rendimientoTelefonicoHoy(array $diasRegistrados)
    {
        $hoy = date('Y-m-d');

        foreach ($diasRegistrados as $dia) {
            if ((string)($dia['fecha'] ?? '') === $hoy) {
                return [
                    'fecha' => $hoy,
                    'llamadas' => (int)($dia['llamadas'] ?? 0),
                    'con_contacto' => (int)($dia['con_contacto'] ?? 0),
                    'efectivas' => (int)($dia['efectivas'] ?? 0)
                ];
            }
        }

        return [
            'fecha' => $hoy,
            'llamadas' => 0,
            'con_contacto' => 0,
            'efectivas' => 0
        ];
    }

    private function mesCortoReporte($mes)
    {
        $meses = [
            1 => 'Ene',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Abr',
            5 => 'May',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Ago',
            9 => 'Sep',
            10 => 'Oct',
            11 => 'Nov',
            12 => 'Dic'
        ];

        return $meses[(int)$mes] ?? '';
    }

    private function obtenerInstitucionesActividad(
        array $ids,
        $fechaInicial,
        $fechaFinal,
        $usuarioId,
        $modoAcceso,
        $canal,
        $limite = 6,
        array $actorIds = []
    ) {
        return array_slice($this->fuente($ids,$fechaInicial,$fechaFinal,$canal,$actorIds,(int)$usuarioId,(string)$modoAcceso)['instituciones'],0,max(1,min(12,(int)$limite)));
    }

    private function calcularCumplimientoEfectivas(
        array $diasRegistrados,
        $fechaInicial,
        $fechaFinal,
        $cantidadActores
    ) {
        $metaPorAnalista = 25;
        $cantidadActores = max(1, (int)$cantidadActores);
        $metaDiariaEquipo = $metaPorAnalista * $cantidadActores;
        $fechaInicial = $this->normalizarFecha($fechaInicial);
        $fechaFinal = $this->normalizarFecha($fechaFinal);
        $diasEvaluados = 0;

        if ($fechaInicial !== '' && $fechaFinal !== '') {
            try {
                $inicio = new DateTimeImmutable($fechaInicial);
                $fin = new DateTimeImmutable($fechaFinal);
                if ($inicio <= $fin) {
                    $diasEvaluados = ((int)$inicio->diff($fin)->days) + 1;
                }
            } catch (Throwable $error) {
                $diasEvaluados = 0;
            }
        }

        if ($diasEvaluados <= 0) {
            $diasEvaluados = max(1, count($diasRegistrados));
        }

        $efectivas = 0;
        $diasCumplidos = 0;
        foreach ($diasRegistrados as $dia) {
            $efectivasDia = max(0, (int)($dia['efectivas'] ?? 0));
            $efectivas += $efectivasDia;
            if ($efectivasDia >= $metaDiariaEquipo) {
                $diasCumplidos++;
            }
        }

        $metaPeriodo = $metaDiariaEquipo * $diasEvaluados;
        $cumplimiento = $metaPeriodo > 0
            ? round(($efectivas / $metaPeriodo) * 100, 1)
            : 0.0;
        $promedioPorAnalistaDia = ($diasEvaluados * $cantidadActores) > 0
            ? round($efectivas / ($diasEvaluados * $cantidadActores), 1)
            : 0.0;

        return [
            'meta_diaria_por_analista' => $metaPorAnalista,
            'analistas_evaluados' => $cantidadActores,
            'meta_diaria_equipo' => $metaDiariaEquipo,
            'dias_evaluados' => $diasEvaluados,
            'meta_periodo' => $metaPeriodo,
            'efectivas' => $efectivas,
            'cumplimiento_pct' => $cumplimiento,
            'promedio_diario_por_analista' => $promedioPorAnalistaDia,
            'dias_cumplidos' => $diasCumplidos
        ];
    }

    private function obtenerActividadPorActor(
        array $ids,
        $fechaInicial,
        $fechaFinal,
        $canal,
        array $actorIds
    ) {
        $actorIds=$this->normalizarIds($actorIds);
        if(!$actorIds)return [];
        $permitidos=array_fill_keys($actorIds,true);
        $filas=array_values(array_filter($this->fuente($ids,$fechaInicial,$fechaFinal,$canal,$actorIds)['actores'],static fn($fila)=>isset($permitidos[(int)$fila['usuario_id']])));
        $diasEvaluados = 1;
        if ($fechaInicial !== '' && $fechaFinal !== '') {
            try {
                $inicioMeta = new DateTimeImmutable($fechaInicial);
                $finMeta = new DateTimeImmutable($fechaFinal);
                if ($inicioMeta <= $finMeta) {
                    $diasEvaluados = ((int)$inicioMeta->diff($finMeta)->days) + 1;
                }
            } catch (Throwable $error) {
                $diasEvaluados = 1;
            }
        }
        $metaPeriodoAnalista = 25 * max(1, $diasEvaluados);

        foreach ($filas as &$fila) {
            $llamadas = max(0, (int)($fila['llamadas'] ?? 0));
            $contactos = max(0, (int)($fila['con_contacto'] ?? 0));
            $fila['usuario_id'] = (int)($fila['usuario_id'] ?? 0);
            $fila['interacciones'] = max(0, (int)($fila['interacciones'] ?? 0));
            $fila['instituciones'] = max(0, (int)($fila['instituciones'] ?? 0));
            $fila['llamadas'] = $llamadas;
            $fila['correos'] = max(0, (int)($fila['correos'] ?? 0));
            $fila['con_contacto'] = $contactos;
            $fila['efectivas'] = max(0, (int)($fila['efectivas'] ?? 0));
            $fila['meta_efectivas_periodo'] = $metaPeriodoAnalista;
            $fila['cumplimiento_efectivas_pct'] = $metaPeriodoAnalista > 0
                ? round(($fila['efectivas'] / $metaPeriodoAnalista) * 100, 1)
                : 0.0;
            $fila['tasa_contacto'] = $llamadas > 0
                ? round(($contactos / $llamadas) * 100, 1)
                : 0.0;
        }
        unset($fila);

        return $filas;
    }

    private function agregarFiltroActores(
        string &$sql,
        string &$tipos,
        array &$parametros,
        array $actorIds,
        $prefijo = ''
    ) {
        $actorIds = $this->normalizarIds($actorIds);
        if (empty($actorIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($actorIds), '?'));
        $sql .= " AND " . $prefijo . "usuario_id IN (" . $placeholders . ")";

        foreach ($actorIds as $actorId) {
            $parametros[] = (int)$actorId;
            $tipos .= 'i';
        }
    }

    private function agregarFiltroCanal(
        string &$sql,
        string &$tipos,
        array &$parametros,
        $canal,
        $prefijo = ''
    ) {
        $canal = strtoupper(trim((string)$canal));
        if ($canal === '') {
            return;
        }

        $campo = $prefijo . 'canal';

        if (in_array($canal, ['LLAMADA', 'LLAMADA_IP'], true)) {
            $sql .= " AND UPPER(TRIM(COALESCE(" . $campo . ", ''))) IN ('LLAMADA', 'LLAMADA_IP')";
            return;
        }

        if ($canal === 'NOTA') {
            $sql .= " AND UPPER(TRIM(COALESCE(" . $campo . ", ''))) NOT IN ('SISTEMA', 'LLAMADA', 'LLAMADA_IP', 'CORREO', 'WHATSAPP')";
            return;
        }

        $sql .= " AND UPPER(TRIM(COALESCE(" . $campo . ", ''))) = ?";
        $parametros[] = $canal;
        $tipos .= 's';
    }

    private function normalizarIds(array $ids)
    {
        $normalizados = [];

        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $normalizados[$id] = $id;
            }

        }

        return array_values($normalizados);
    }

    private function normalizarFecha($valor)
    {
        $valor = trim((string)$valor);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return '';
        }

        try {
            $fecha = new DateTimeImmutable($valor);
        } catch (Throwable $error) {
            return '';
        }

        return $fecha->format('Y-m-d') === $valor ? $valor : '';
    }

    private function vincularParametros($stmt, $tipos, array $parametros)
    {
        if ($tipos === '') {
            return;
        }

        $referencias = [];
        $referencias[] = &$tipos;

        foreach ($parametros as $indice => $valor) {
            $referencias[] = &$parametros[$indice];
        }

        call_user_func_array([$stmt, 'bind_param'], $referencias);
    }

    private function estructuraVacia()
    {
        return [
            'seguimientos_considerados' => 0,
            'interacciones' => 0,
            'seguimientos_con_actividad' => 0,
            'cobertura_actividad' => 0.0,
            'promedio_por_seguimiento' => 0.0,
            'canales' => [
                'llamadas' => 0,
                'correos' => 0,
                'whatsapp' => 0,
                'otros' => 0
            ],
            'llamadas' => [
                'total' => 0,
                'contactadas' => 0,
                'sin_respuesta' => 0,
                'numero_incorrecto' => 0,
                'volver_llamar' => 0,
                'otros' => 0,
                'verificaciones_efectivas' => 0,
                'tasa_contacto' => 0.0
            ],
            'actividad_reciente' => [],
            'rendimiento_telefonico_diario' => [],
            'rendimiento_telefonico' => [
                'granularidad' => 'dia',
                'periodos' => []
            ],
            'rendimiento_telefonico_hoy' => [
                'fecha' => date('Y-m-d'),
                'llamadas' => 0,
                'con_contacto' => 0,
                'efectivas' => 0
            ],
            'instituciones_actividad' => [],
            'actividad_por_actor' => [],
            'cumplimiento_efectivas' => [
                'meta_diaria_por_analista' => 25,
                'analistas_evaluados' => 1,
                'meta_diaria_equipo' => 25,
                'dias_evaluados' => 0,
                'meta_periodo' => 0,
                'efectivas' => 0,
                'cumplimiento_pct' => 0.0,
                'promedio_diario_por_analista' => 0.0,
                'dias_cumplidos' => 0
            ],
            'meta_diaria_efectivas' => 25,
            'atencion' => [
                'total' => 0,
                'porcentaje' => 0.0,
                'casos' => []
            ],
            'periodo' => [
                'fecha_inicial' => '',
                'fecha_final' => '',
                'canal' => ''
            ]
        ];
    }
}

/** Lectura compartida de actividad humana y telefonía acreditada. No escribe ni crea interacciones. */
final class SeguimientoReporteActividadFuente
{
    private array $cache=[],$tables=[];
    public function __construct(private $db) {}
    private function rows(string $sql,array $args=[]): array
    {
        $s=$this->db->prepare($sql);if($args)$s->bind_param(str_repeat('s',count($args)),...$args);
        $s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
    }
    private function table(string $name): bool
    {
        return $this->tables[$name]??=$this->rows('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$name])!==[];
    }
    private static function inPeriod(string $date,string $from,string $until): bool
    {
        return $date!==''&&($from===''||$date>=$from.' 00:00:00')&&($until===''||$date<=$until.' 23:59:59');
    }
    private static function phone(array $row): bool {return in_array(strtoupper(trim((string)($row['canal']??''))),['LLAMADA','LLAMADA_IP'],true);}
    public static function criteria(array $row): array
    {
        $notes=(string)($row['notas']??'');$result=strtoupper(trim((string)($row['resultado']??'')));
        $contact=(str_contains($notes,'[CONTACTO_EFECTIVO]')||in_array($result,['CONTACTADO','CONTACTO_CORRECTO','CONTACTO_REFERIDO','SOLICITO_INFORMACION','SOLICITO_LLAMAR_DESPUES','NO_INTERESADO'],true))&&!str_contains($notes,'[SIN_CONTACTO_EFECTIVO]');
        // Mantener la regla comercial instalada: marca explícita y vínculo de telefonía confirmado.
        $effective=str_contains($notes,'[VERIFICACION_EFECTIVA]')&&trim((string)($row['proveedor_externo']??''))!==''&&trim((string)($row['id_externo']??''))!==''&&(int)($row['duracion_segundos']??0)>0;
        return ['contacto'=>$contact,'efectiva'=>$effective];
    }
    private static function callKey(array $row): string
    {
        $owner=(int)$row['usuario_id'];$external=trim((string)($row['id_externo']??''));$provider=strtoupper(trim((string)($row['proveedor_externo']??'')));
        return $external!==''&&$provider!==''?$owner.'|'.$provider.'|'.$external:$owner.'|interaccion|'.(int)$row['id'];
    }
    private function owners(array $ids,array $actors,int $user,string $mode): array
    {
        if($mode==='analista')$actors=[$user];
        $sql="SELECT DISTINCT u.id,TRIM(CONCAT_WS(' ',u.nombre,u.apellidos)) nombre FROM usuarios u WHERE u.rol_id=4";$args=[];
        if($mode==='supervisor'){
            if(!$ids)return [];
            $sql.=' AND EXISTS(SELECT 1 FROM seguimientos_vinculacion s WHERE s.analista_id=u.id AND s.id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND '.SeguimientoTerritorialScope::supervisor('s').')';
            $args=array_merge($ids,[$user]);
        }
        if($actors){$sql.=' AND u.id IN ('.implode(',',array_fill(0,count($actors),'?')).')';$args=array_merge($args,$actors);}
        return array_column($this->rows($sql,$args),null,'id');
    }
    public function obtener(array $ids,string $from,string $until,string $channel,array $actors,int $user,string $mode,bool $unlinked=false): array
    {
        $key=json_encode([$ids,$from,$until,$channel,$actors,$user,$mode,$unlinked]);
        if(isset($this->cache[$key]))return $this->cache[$key];
        $allowed=array_fill_keys($ids,true);$human=[];
        if($ids){
            $sql="SELECT i.*,s.nombre_entidad,COALESCE(m.nombre,'') municipio,COALESCE(e.nombre,'') estado_nombre,TRIM(CONCAT_WS(' ',u.nombre,u.apellidos)) responsable_nombre
                FROM interacciones_vinculacion i JOIN seguimientos_vinculacion s ON s.id=i.seguimiento_id
                LEFT JOIN municipios m ON m.id=s.municipio_id LEFT JOIN estados e ON e.id=s.estado_id LEFT JOIN usuarios u ON u.id=i.usuario_id
                WHERE i.seguimiento_id IN (".implode(',',array_fill(0,count($ids),'?')).") AND UPPER(TRIM(COALESCE(i.canal,'')))<>'SISTEMA'";
            $args=$ids;
            if($actors){$sql.=' AND i.usuario_id IN ('.implode(',',array_fill(0,count($actors),'?')).')';$args=array_merge($args,$actors);}
            if($from!==''){$sql.=' AND i.fecha_inicio>=?';$args[]=$from.' 00:00:00';}
            if($until!==''){$sql.=' AND i.fecha_inicio<=?';$args[]=(new DateTimeImmutable($until.' 23:59:59',new DateTimeZone('America/Mexico_City')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
            if($channel!==''){
                if(in_array($channel,['LLAMADA','LLAMADA_IP'],true))$sql.=" AND UPPER(TRIM(COALESCE(i.canal,''))) IN ('LLAMADA','LLAMADA_IP')";
                elseif($channel==='NOTA')$sql.=" AND UPPER(TRIM(COALESCE(i.canal,''))) NOT IN ('SISTEMA','LLAMADA','LLAMADA_IP','CORREO','WHATSAPP')";
                else{$sql.=" AND UPPER(TRIM(COALESCE(i.canal,'')))=?";$args[]=$channel;}
            }
            foreach($this->rows($sql,$args) as $row){
                $row=TelefoniaFechaHoraHelper::normalizarInteraccion($row);
                if(self::inPeriod((string)$row['fecha_inicio'],$from,$until))$human[]=$row;
            }
        }
        // Una transferencia puede guardar un ID SDK en la interacción y otro PBX en
        // Telefonía. Sólo la operación entregada al mismo destinatario acredita ese alias.
        $sdkRows=[];$aliases=[];$unresolvedTransfers=[];
        foreach($human as $row)if(self::phone($row)&&strtoupper(trim((string)($row['proveedor_externo']??'')))==='ZADARMA_WEBRTC'&&str_starts_with((string)($row['id_externo']??''),'WEBRTC-')){
            $sdk=substr((string)$row['id_externo'],7);$base=str_ends_with($sdk,'-external')?substr($sdk,0,-9):$sdk;
            $sdkRows[(int)$row['usuario_id'].'|'.$base][]=self::callKey($row);
        }
        if($sdkRows&&$this->table('telefonia_transferencia_operaciones')){
            $uids=[];$sdks=[];
            foreach(array_keys($sdkRows) as $identity){[$uid,$sdk]=explode('|',$identity,2);$uids[(int)$uid]=true;$sdks[$sdk]=true;}
            $groups=[];
            foreach(array_chunk(array_keys($sdks),500) as $chunk){
                $args=array_merge(array_keys($uids),$chunk);
                foreach($this->rows("SELECT destino_usuario,destino_extension,sdk_consulta,referencia FROM telefonia_transferencia_operaciones WHERE estado='ENTREGADA' AND destino_usuario IN (".implode(',',array_fill(0,count($uids),'?')).') AND sdk_consulta IN ('.implode(',',array_fill(0,count($chunk),'?')).')',$args) as $op){
                    $identity=(int)$op['destino_usuario'].'|'.(string)$op['sdk_consulta'];
                    if(isset($sdkRows[$identity])&&preg_match('/^[0-9]{3,6}$/D',(string)$op['destino_extension'])&&trim((string)$op['referencia'])!=='')$groups[$identity][]=$op;
                }
            }
            foreach($groups as $identity=>$ops){
                if(count($ops)===1){$op=$ops[0];foreach($sdkRows[$identity] as $aliasKey)$aliases[$aliasKey]=(int)$op['destino_usuario'].'|ZADARMA|'.(string)$op['referencia'];}
                else foreach($ops as $op)$unresolvedTransfers[(int)$op['destino_usuario'].'|ZADARMA|'.(string)$op['referencia']]=true;
            }
        }
        $calls=[];$interactionCalls=[];
        foreach($human as $row)if(self::phone($row)){
            $recordKey=self::callKey($row);$callKey=$aliases[$recordKey]??$recordKey;$interactionCalls[(int)$row['usuario_id'].'|'.(int)$row['id']]=$callKey;
            if(!isset($calls[$callKey]))$calls[$callKey]=$row+self::criteria($row);
            else{
                $criteria=self::criteria($row);$calls[$callKey]['contacto']=$calls[$callKey]['contacto']||$criteria['contacto'];$calls[$callKey]['efectiva']=$calls[$callKey]['efectiva']||$criteria['efectiva'];
            }
        }
        $owners=$this->owners($ids,$actors,$user,$mode);
        $phoneChannel=$channel===''||in_array($channel,['LLAMADA','LLAMADA_IP'],true);
        if($phoneChannel&&$owners&&$this->table('telefonia_analista_entrantes')&&($ids||$unlinked)){
            $ownerIds=array_map('intval',array_keys($owners));
            $sql="SELECT c.*,s.nombre_entidad,COALESCE(m.nombre,'') municipio,COALESCE(e.nombre,'') estado_nombre FROM telefonia_analista_entrantes c
                LEFT JOIN seguimientos_vinculacion s ON s.id=c.seguimiento_id LEFT JOIN municipios m ON m.id=s.municipio_id LEFT JOIN estados e ON e.id=s.estado_id
                WHERE c.usuario_id IN (".implode(',',array_fill(0,count($ownerIds),'?')).')';$args=$ownerIds;
            if(!$unlinked){$sql.=' AND c.seguimiento_id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$args=array_merge($args,$ids);}
            if($from!==''){$sql.=' AND c.created_at>=?';$args[]=(new DateTimeImmutable($from.' 00:00:00',new DateTimeZone('America/Mexico_City')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
            if($until!==''){$sql.=' AND c.created_at<=?';$args[]=(new DateTimeImmutable($until.' 23:59:59',new DateTimeZone('America/Mexico_City')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
            $classifications=$this->rows($sql,$args);$refs=[];
            foreach($classifications as $row){
                $uid=(int)$row['usuario_id'];$iv=(int)($row['interaccion_id']??0);$ref=(string)($row['pbx_call_id']??'');
                if($iv>0&&isset($interactionCalls[$uid.'|'.$iv]))continue;
                if(isset($calls[$uid.'|ZADARMA|'.$ref])||isset($unresolvedTransfers[$uid.'|ZADARMA|'.$ref]))continue;
                if($iv>0)continue; // Un vínculo que no pertenece al periodo/alcance no se reasocia.
                if($ref!==''&&!str_starts_with($ref,'sdk-'))$refs[$ref]=true;
            }
            $events=[];
            if($refs&&$this->table('telefonia_zadarma_eventos'))foreach(array_chunk(array_keys($refs),500) as $chunk){
                foreach($this->rows('SELECT * FROM telefonia_zadarma_eventos WHERE pbx_call_id IN ('.implode(',',array_fill(0,count($chunk),'?')).') ORDER BY id',$chunk) as $event)$events[(string)$event['pbx_call_id']][]=$event;
            }
            if($events)require_once __DIR__.'/TelefoniaVentasLlamadasService.php';
            foreach($classifications as $row){
                $uid=(int)$row['usuario_id'];$iv=(int)($row['interaccion_id']??0);$sid=(int)($row['seguimiento_id']??0);$ref=(string)($row['pbx_call_id']??'');$ext=(string)($row['extension']??'');$callKey=$uid.'|ZADARMA|'.$ref;
                if($iv>0||isset($calls[$callKey])||isset($unresolvedTransfers[$callKey])||!isset($owners[$uid])||($sid>0&&!isset($allowed[$sid]))||($sid<=0&&!$unlinked)||!preg_match('/^[0-9]{3,6}$/D',$ext)||empty($events[$ref]))continue;
                $facts=TelefoniaVentasLlamadasService::hechos($events[$ref],$ext);
                if(empty($facts['finalizada']))continue;
                $date=self::fechaPbx($events[$ref],$ext,(string)$row['created_at']);
                if(!self::inPeriod($date,$from,$until))continue;
                $calls[$callKey]=['id'=>0,'usuario_id'=>$uid,'seguimiento_id'=>$sid,'canal'=>'LLAMADA_IP','resultado'=>'','notas'=>'','id_externo'=>$ref,'proveedor_externo'=>'ZADARMA','fecha_inicio'=>$date,
                    'contacto'=>false,'efectiva'=>false,'sin_expediente'=>$sid<=0,'nombre_entidad'=>$row['nombre_entidad']??'','municipio'=>$row['municipio']??'','estado_nombre'=>$row['estado_nombre']??'','responsable_nombre'=>$owners[$uid]['nombre']];
            }
        }
        return $this->cache[$key]=self::reducir($human,array_values($calls),$actors,$owners);
    }
    private static function fechaPbx(array $events,string $ext,string $utc): string
    {
        foreach(['NOTIFY_OUT_START','NOTIFY_START','NOTIFY_INTERNAL','NOTIFY_ANSWER'] as $type){
            $dates=[];foreach($events as $e)if(($e['evento']??'')===$type&&(string)($e['internal']??'')===$ext&&preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',(string)($e['received_at']??'')))$dates[$e['received_at']]=true;
            if(count($dates)===1)return (string)array_key_first($dates);if(count($dates)>1)break;
        }
        return TelefoniaFechaHoraHelper::aMexico($utc);
    }
    public static function reducir(array $human,array $calls,array $actors=[],array $owners=[]): array
    {
        $summary=array_fill_keys(['total_interacciones','seguimientos_con_actividad','llamadas','correos','whatsapp','otros','contactadas','sin_respuesta','numero_incorrecto','volver_llamar','llamadas_otros','verificaciones_efectivas'],0);
        $institutions=[];$byActor=[];$days=[];$effective=[];$worked=[];$unlinked=0;
        $actorEmpty=static fn($uid,$name)=>['usuario_id'=>$uid,'analista_nombre'=>$name,'interacciones'=>0,'instituciones'=>0,'llamadas'=>0,'correos'=>0,'con_contacto'=>0,'efectivas'=>0];
        foreach($actors as $uid)$byActor[$uid]=$actorEmpty($uid,(string)($owners[$uid]['nombre']??''));
        foreach($human as $r){
            $uid=(int)$r['usuario_id'];$sid=(int)$r['seguimiento_id'];$channel=strtoupper(trim((string)$r['canal']));$summary['total_interacciones']++;$worked[$sid]=true;
            if(!isset($institutions[$sid]))$institutions[$sid]=['seguimiento_id'=>$sid,'nombre_entidad'=>$r['nombre_entidad']??'','municipio'=>$r['municipio']??'','estado_nombre'=>$r['estado_nombre']??'','interacciones'=>0,'llamadas'=>0,'correos'=>0,'con_contacto'=>0,'efectivas'=>0,'ultima_actividad'=>''];
            $institutions[$sid]['interacciones']++;$institutions[$sid]['ultima_actividad']=max($institutions[$sid]['ultima_actividad'],(string)$r['fecha_inicio']);
            $byActor[$uid]??=$actorEmpty($uid,(string)($r['responsable_nombre']??''));$byActor[$uid]['interacciones']++;$byActor[$uid]['_institutions'][$sid]=true;
            if($channel==='CORREO'){$summary['correos']++;$institutions[$sid]['correos']++;$byActor[$uid]['correos']++;}
            elseif($channel==='WHATSAPP')$summary['whatsapp']++;
            elseif(!self::phone($r))$summary['otros']++;
        }
        foreach($calls as $r){
            $uid=(int)$r['usuario_id'];$sid=(int)$r['seguimiento_id'];$day=substr((string)$r['fecha_inicio'],0,10);$contact=!empty($r['contacto']);
            $days[$day]??=['fecha'=>$day,'llamadas'=>0,'con_contacto'=>0,'efectivas'=>0];$days[$day]['llamadas']++;$summary['llamadas']++;
            $byActor[$uid]??=$actorEmpty($uid,(string)($r['responsable_nombre']??''));$byActor[$uid]['llamadas']++;
            if($sid>0&&!isset($institutions[$sid]))$institutions[$sid]=['seguimiento_id'=>$sid,'nombre_entidad'=>$r['nombre_entidad']??'','municipio'=>$r['municipio']??'','estado_nombre'=>$r['estado_nombre']??'','interacciones'=>0,'llamadas'=>0,'correos'=>0,'con_contacto'=>0,'efectivas'=>0,'ultima_actividad'=>(string)$r['fecha_inicio']];
            if($sid>0){$institutions[$sid]['llamadas']++;$worked[$sid]=true;$byActor[$uid]['_institutions'][$sid]=true;}else $unlinked++;
            if($contact){$summary['contactadas']++;$days[$day]['con_contacto']++;$byActor[$uid]['con_contacto']++;if($sid>0)$institutions[$sid]['con_contacto']++;}
            $result=strtoupper(trim((string)($r['resultado']??'')));
            if($result==='SIN_RESPUESTA')$summary['sin_respuesta']++;
            elseif($result==='NUMERO_INCORRECTO')$summary['numero_incorrecto']++;
            elseif($result==='SOLICITO_LLAMAR_DESPUES')$summary['volver_llamar']++;
            elseif($result!=='CONTACTADO')$summary['llamadas_otros']++;
            $credit=$uid.'|'.$sid.'|'.$day;
            if($sid>0&&!empty($r['efectiva'])&&!isset($effective[$credit])){
                $effective[$credit]=true;$summary['verificaciones_efectivas']++;$days[$day]['efectivas']++;$byActor[$uid]['efectivas']++;$institutions[$sid]['efectivas']++;
            }
        }
        $summary['seguimientos_con_actividad']=count($worked);
        foreach($byActor as &$r){$r['instituciones']=count($r['_institutions']??[]);unset($r['_institutions']);}unset($r);
        usort($human,static fn($a,$b)=>strcmp($b['fecha_inicio'],$a['fecha_inicio'])?:((int)$b['id']<=>(int)$a['id']));
        usort($institutions,static fn($a,$b)=>($b['interacciones']<=>$a['interacciones'])?:strcmp($b['ultima_actividad'],$a['ultima_actividad']));
        ksort($days);
        return ['resumen'=>$summary,'humanas'=>$human,'llamadas'=>array_values($calls),'diario'=>array_values($days),'instituciones'=>array_values($institutions),'actores'=>array_values($byActor),'llamadas_sin_expediente'=>$unlinked,'ids_actividad'=>array_map('intval',array_keys($worked))];
    }
}