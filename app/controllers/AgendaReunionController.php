<?php

require_once __DIR__ . '/../services/AgendaReunionService.php';

class AgendaReunionController
{
    private $service;

    public function __construct()
    {
        $this->service = new AgendaReunionService();
    }

    public function index()
    {
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);

        if ($usuarioId <= 0 || !$this->service->puedeAcceder($rolId)) {
            http_response_code(403);
            die('No tienes acceso a la agenda de reuniones.');
        }

        $agendaSeguimientoInicial = (int)($_GET['seguimiento_id'] ?? 0);
        $agendaReunionInicial = (int)($_GET['reunion_id'] ?? 0);
        $mesSolicitado = trim((string)($_GET['mes'] ?? ''));

        if ($mesSolicitado === '' && ($agendaSeguimientoInicial > 0 || $agendaReunionInicial > 0)) {
            $mesSolicitado = $this->service->resolverMesContexto(
                $usuarioId,
                $rolId,
                $agendaReunionInicial,
                $agendaSeguimientoInicial
            );
        }

        $agenda = $this->service->obtenerAgenda($usuarioId, $rolId, $mesSolicitado);

        if (!($agenda['ok'] ?? false)) {
            http_response_code((int)($agenda['codigo_http'] ?? 500));
            die(htmlspecialchars((string)($agenda['mensaje'] ?? 'No fue posible abrir la agenda.')));
        }

        $mesAgenda = (string)($agenda['mes'] ?? date('Y-m'));
        $agendaReuniones = $agenda['reuniones'] ?? [];
        $seguimientosElegibles = $agenda['seguimientos_elegibles'] ?? [];
        $agendaRequiereMigracion = (bool)($agenda['requiere_migracion'] ?? false);
        $agendaRolId = $rolId;

        $primerDia = new DateTime($mesAgenda . '-01');
        $offset = (int)$primerDia->format('N') - 1;
        $inicioCalendario = (clone $primerDia)->modify('-' . $offset . ' days');
        $celdasAgenda = [];
        $reunionesPorDia = [];

        foreach ($agendaReuniones as $reunion) {
            $fecha = trim((string)($reunion['fecha_propuesta'] ?? ''));
            if ($fecha === '') {
                continue;
            }

            try {
                $claveDia = (new DateTime($fecha))->format('Y-m-d');
                $reunionesPorDia[$claveDia][] = $reunion;
            } catch (Throwable $error) {
                continue;
            }
        }

        for ($i = 0; $i < 42; $i++) {
            $dia = (clone $inicioCalendario)->modify('+' . $i . ' days');
            $clave = $dia->format('Y-m-d');
            $celdasAgenda[] = [
                'fecha' => $clave,
                'numero' => $dia->format('j'),
                'es_mes' => $dia->format('Y-m') === $mesAgenda,
                'es_hoy' => $clave === date('Y-m-d'),
                'reuniones' => $reunionesPorDia[$clave] ?? []
            ];
        }

        $mesAnterior = (clone $primerDia)->modify('-1 month')->format('Y-m');
        $mesSiguiente = (clone $primerDia)->modify('+1 month')->format('Y-m');
        $tituloMesAgenda = $this->nombreMes((int)$primerDia->format('n')) . ' ' . $primerDia->format('Y');

        $tituloPagina = 'Agenda de reuniones';
        $subtituloPagina = $rolId === AgendaReunionService::ROL_CUENTA_CLAVE
            ? 'Confirma solicitudes y agrega los datos de Zoom para el Analista'
            : 'Coordina reuniones con Cuenta Clave y da seguimiento a sus confirmaciones';
        $opcionActiva = 'seguimiento_vinculacion';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/seguimiento_vinculacion/agenda.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function solicitar()
    {
        $this->procesarAccion('solicitar');
    }

    public function reprogramar()
    {
        $this->procesarAccion('reprogramar');
    }

    public function confirmar()
    {
        $this->procesarAccion('confirmar');
    }

    public function solicitarCambio()
    {
        $this->procesarAccion('solicitarCambio');
    }

    public function marcarCorreoEnviado()
    {
        $this->procesarAccion('marcarCorreoEnviado');
    }

    private function procesarAccion($metodo)
    {
        header('Content-Type: application/json; charset=utf-8');

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);

        if ($usuarioId <= 0 || !$this->service->puedeAcceder($rolId)) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a esta acción.'
            ], 403);
        }

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $resultado = $this->service->$metodo($usuarioId, $rolId, $_POST);
        $codigoHttp = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);

        $this->responder($resultado, $codigoHttp);
    }

    private function responder($datos, $codigoHttp = 200)
    {
        http_response_code((int)$codigoHttp);
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }

    private function nombreMes($mes)
    {
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

        return $meses[$mes] ?? 'Agenda';
    }
}
