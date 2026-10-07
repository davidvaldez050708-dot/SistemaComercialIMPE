<?php

require_once __DIR__ . '/../models/ConvocatoriaModel.php';

class ReporteConvocatoriaDataService
{
    private $modelo;

    public function __construct()
    {
        $this->modelo = new ConvocatoriaModel();
    }

    public function prepararDatos()
    {
        $this->modelo->desactivarConvocatoriasVencidas();

        $resumen = $this->modelo->obtenerResumenDashboard();
        $cobertura = $this->modelo->obtenerCoberturaTerritorialDashboard(32);
        $porTipo = $this->modelo->obtenerPublicacionesPorTipoDashboard(30);
        $publicacionesPorUsuario =
            $this->modelo->obtenerPublicacionesPorUsuarioReporte();
        $detalle = $this->modelo->obtenerDetalleReporteConvocatorias();

        $estadosCubiertos = (int)($cobertura['estados_cubiertos'] ?? 0);
        $totalEstados = (int)($cobertura['total_estados'] ?? 0);
        $porcentajeCobertura = $totalEstados > 0
            ? (int)round(($estadosCubiertos / $totalEstados) * 100)
            : 0;

        $totalBachillerato = (int)($porTipo['bachillerato']['total'] ?? 0);
        $totalTitulacion = (int)($porTipo['titulacion']['total'] ?? 0);

        $hoy = new DateTimeImmutable('today');
        $claveHoy = $hoy->format('Y-m-d');
        $claveMesActual = $hoy->format('Y-m');

        $publicacionesHoy = 0;
        $publicacionesMesActual = 0;
        $vencenHoy = 0;
        $vencenProximosDosDias = 0;
        $alertasVencimiento = [];

        foreach ($detalle as $convocatoria) {
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

            if ((int)($convocatoria['estado'] ?? 0) !== 1) {
                continue;
            }

            $fechaTermino = $this->crearFecha(
                (string)($convocatoria['fecha_termino'] ?? '')
            );

            if (!$fechaTermino) {
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

        $proximas = (int)($resumen['proximas_finalizar'] ?? 0);
        $activas = (int)($resumen['activas'] ?? 0);
        $inactivas = (int)($resumen['inactivas'] ?? 0);

        $territorios = is_array($cobertura['territorios'] ?? null)
            ? $cobertura['territorios']
            : [];
        $territorioPrincipal = $territorios[0] ?? null;

        $hallazgos = [];

        $hallazgos[] = $vencenHoy > 0
            ? 'Hay ' . $vencenHoy . ' convocatoria(s) que vencen hoy y requieren seguimiento.'
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
            $hallazgos[] = 'El territorio con mayor concentración activa es ' .
                (string)($territorioPrincipal['nombre'] ?? '') . ' con ' .
                (int)($territorioPrincipal['convocatorias_activas'] ?? 0) .
                ' convocatoria(s).';
        }

        $hallazgos[] = 'En los últimos 30 días se registraron ' .
            $totalBachillerato . ' publicaciones de Bachillerato y ' .
            $totalTitulacion . ' de Titulación.';

        return [
            'resumen' => [
                'total' => (int)($resumen['total'] ?? 0),
                'activas' => $activas,
                'inactivas' => $inactivas,
                'vigentes' => (int)($resumen['vigentes'] ?? 0),
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
