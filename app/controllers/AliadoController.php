<?php

require_once __DIR__ . '/../models/AliadoModel.php';
require_once __DIR__ . '/../models/SeguimientoVinculacionModel.php';
require_once __DIR__ . '/../services/AliadoDifusionService.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../models/AliadoInternacionalModel.php';
require_once __DIR__ . '/../services/AliadoInternacionalService.php';

class AliadoController
{
    public function index()
    {
        if (!AliadoInternacionalService::puedeConsultar()) { $this->validarPermiso('aliados.ver'); }

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $modeloAliado = new AliadoModel();
        $modeloSeguimiento = new SeguimientoVinculacionModel();

        $estructuraAliadosDisponible = $modeloAliado->estructuraDisponible();
        $territorios = !tienePermiso('aliados.ver') ? [] : ($esAdministrador
            ? $modeloSeguimiento->obtenerEstadosAdministrador()
            : $modeloSeguimiento->obtenerEstadosSupervisadosCuentaClave($usuarioId));

        $resumenPorEstado = [];
        if ($estructuraAliadosDisponible && tienePermiso('aliados.ver')) {
            foreach (
                $modeloAliado->obtenerResumenTerritorial(
                    $usuarioId,
                    $esAdministrador
                ) as $resumenEstado
            ) {
                $resumenPorEstado[(int)$resumenEstado['estado_id']] = $resumenEstado;
            }
        }

        foreach ($territorios as $indice => $territorio) {
            $estadoId = (int)($territorio['id'] ?? 0);
            $resumen = $resumenPorEstado[$estadoId] ?? [];

            $territorios[$indice]['total_aliados'] =
                (int)($resumen['total_aliados'] ?? 0);
            $territorios[$indice]['total_municipios_aliados'] =
                (int)($resumen['total_municipios_aliados'] ?? 0);
        }

        $mensajeError = $_SESSION['error_aliados'] ?? '';
        unset($_SESSION['error_aliados']);

        $tituloPagina = 'Aliados';
        $subtituloPagina = $esAdministrador
            ? 'Consulta la red institucional formalizada por territorio'
            : 'Selecciona uno de tus territorios asignados';
        $opcionActiva = 'aliados';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/aliados/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    /**
     * Consulta administrativa de aliados externos vinculados a formularios.
     * No inventa un territorio mexicano ni una formalización institucional.
     */
    public function internacionales()
    {
        $this->validarAccesoInternacional();
        header('Cache-Control: no-store, private');
        $externos = [];
        $respuestas = [];
        $errorExternos = '';
        $estructuraGestion = false; $convocatoriasExternas = []; $programasCatalogo = []; $aliadoConvocatoria = null; $aliadoEditar = null;
        $_SESSION['csrf_aliados_internacionales'] = $_SESSION['csrf_aliados_internacionales'] ?? bin2hex(random_bytes(32));
        try {
            $modelo = new AliadoInternacionalModel((new Database())->connect());
            $externos = $modelo->obtenerAliados();
            $respuestas = (int)($_SESSION['rol_id'] ?? 0) === 1 ? $modelo->obtenerRespuestas() : [];
            $estructuraGestion = $modelo->estructuraGestion();
            if ($estructuraGestion) {
                $db=(new Database())->connect();$svc=new AliadoInternacionalService($db);
                $convocatoriasExternas=$svc->convocatorias();
                $programasCatalogo=$db->query('SELECT codigo,nombre FROM formulario_programas WHERE activo=1 ORDER BY nombre')->fetch_all(MYSQLI_ASSOC);
                if (!empty($_GET['editar']) && tienePermiso('aliados.internacionales.configurar')) { $aliadoEditar=$svc->obtener((int)$_GET['editar']); }
                if (!empty($_GET['crear_convocatoria']) && tienePermiso('convocatorias.crear')) {
                    $aliadoConvocatoria=$svc->obtener((int)$_GET['crear_convocatoria']);
                    if (!(int)$aliadoConvocatoria['activo']) { throw new DomainException('El aliado está inactivo.'); }
                    $ofertasInternacionales=$svc->ofertas((int)$aliadoConvocatoria['id']);
                    $referenciaInternacional=bin2hex(random_bytes(16));
                    $_SESSION['convocatoria_refs']=array_slice($_SESSION['convocatoria_refs']??[], -20, null, true);
                    $_SESSION['convocatoria_refs'][$referenciaInternacional]=(int)$aliadoConvocatoria['id'];
                }
            }
        } catch (Throwable $e) {
            error_log('[aliados_externos] ' . $e->getMessage());
            $externos = [];
            $respuestas = [];
            $aliadoConvocatoria = null; $aliadoEditar = null; $estructuraGestion = false;
            http_response_code($e instanceof DomainException ? 404 : 503);
            $errorExternos = 'No fue posible cargar la información internacional. Verifica la configuración de formularios.';
        }

        $tituloPagina = 'Aliados internacionales';
        $subtituloPagina = 'Enlaces exclusivos y respuestas de instituciones externas';
        $opcionActiva = 'aliados';
        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/aliados/internacionales.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function guardarInternacional()
    {
        $this->validarAccesoInternacional();
        $this->validarPermiso('aliados.internacionales.configurar');
        $this->validarMetodoPost();
        $this->validarCsrfInternacional();
        try {
            $id=(new AliadoInternacionalService((new Database())->connect()))->guardar($_POST,(int)$_SESSION['usuario_id']);
            $_SESSION['mensaje_internacional']='Datos del aliado guardados correctamente.';
        } catch (Throwable $e) {
            error_log('[aliado_guardar] '.$e->getMessage());
            $_SESSION['error_internacional']=$e instanceof DomainException?$e->getMessage():'No fue posible guardar el aliado.';
        }
        header('Location: '.BASE_URL.'index.php?controller=aliado&action=internacionales');exit;
    }

    public function convenioInternacional()
    {
        $this->validarAccesoInternacional();
        $this->validarPermiso('aliados.internacionales.configurar');
        $this->validarPermiso('convenios.gestionar');
        $this->validarMetodoPost();$this->validarCsrfInternacional();
        try {
            (new AliadoInternacionalService((new Database())->connect()))->convenio((int)($_POST['id']??0),$_POST,(int)$_SESSION['usuario_id']);
            $_SESSION['mensaje_internacional']='Convenio relacionado con la institución original.';
        } catch (Throwable $e) {
            error_log('[aliado_convenio] '.$e->getMessage());
            $_SESSION['error_internacional']=$e instanceof DomainException?$e->getMessage():'No fue posible guardar el convenio.';
        }
        header('Location: '.BASE_URL.'index.php?controller=aliado&action=internacionales');exit;
    }

    public function qrInternacional()
    {
        $this->validarAccesoInternacional();$this->validarPermiso('convocatorias.ver');
        if (!AliadoInternacionalService::puedeConsultar()) { http_response_code(403);exit; }
        try {
            $db=(new Database())->connect();$id=(int)($_GET['id']??0);
            $s=$db->prepare('SELECT c.enlace_registro,x.referencia,x.formulario,a.token FROM formulario_aliado_convocatorias x INNER JOIN convocatorias c ON c.id=x.convocatoria_id INNER JOIN formulario_aliados a ON a.id=x.aliado_id WHERE c.id=?');
            $s->bind_param('i',$id);$s->execute();$c=$s->get_result()->fetch_assoc();
            if (!$c) { http_response_code(404);exit; }
            $esperado=BASE_URL.'index.php?controller=formularioPublico&action='.$c['formulario'].'&aliado='.$c['token'].'&convocatoria_ref='.$c['referencia'];
            if (!hash_equals($esperado,$c['enlace_registro'])) { throw new RuntimeException('Liga fuera del formulario institucional esperado.'); }
            $url=$c['enlace_registro'];$params=[];parse_str((string)parse_url($url,PHP_URL_QUERY),$params);
            if (($params['aliado']??'')!==$c['token'] || ($params['convocatoria_ref']??'')!==$c['referencia']) { throw new RuntimeException('Enlace de convocatoria inconsistente.'); }
            // Mismo proveedor y parámetros del QR de Crear convocatoria, sin rutas temporales.
            $qr='https://quickchart.io/qr?'.http_build_query(['text'=>$url,'size'=>512,'margin'=>2,'format'=>'png']);
            $ch=curl_init($qr);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false]);
            $png=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
            if ($status!==200 || !is_string($png) || strlen($png)>2000000 || substr($png,0,8)!=="\x89PNG\r\n\x1a\n") { throw new RuntimeException('El proveedor QR no está disponible.'); }
            header('Content-Type: image/png');header('Cache-Control: private, max-age=300');header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: '.(!empty($_GET['descargar'])?'attachment':'inline').'; filename="convocatoria-'.$id.'.png"');
            echo $png;exit;
        } catch (Throwable $e) { error_log('[qr_internacional] '.$e->getMessage());http_response_code(503);echo 'No se pudo generar el QR. Intenta nuevamente.';exit; }
    }

    private function validarAccesoInternacional(): void
    {
        if (!isset($_SESSION['usuario_id'])) { http_response_code(401); echo 'Sesión no válida.'; exit; }
        if (!AliadoInternacionalService::puedeConsultar()) { http_response_code(403); echo 'No tienes autorización para consultar aliados internacionales.'; exit; }
    }

    private function validarCsrfInternacional()
    {
        $valor=$_POST['csrf_internacional']??null;
        if (!is_string($valor)||empty($_SESSION['csrf_aliados_internacionales'])||!hash_equals($_SESSION['csrf_aliados_internacionales'],$valor)) {
            http_response_code(403);echo 'Solicitud no válida.';exit;
        }
    }

    public function estado()
    {
        $this->validarPermiso('aliados.ver');

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $estadoId = (int)($_GET['estado_id'] ?? 0);
        $modeloAliado = new AliadoModel();
        $modeloSeguimiento = new SeguimientoVinculacionModel();

        $estado = $esAdministrador
            ? $modeloSeguimiento->obtenerEstadoAdministrador($estadoId)
            : $modeloSeguimiento->obtenerEstadoSupervisadoCuentaClave(
                $usuarioId,
                $estadoId
            );

        if (!$estado) {
            $_SESSION['error_aliados'] = 'No tienes acceso a este territorio.';
            header(
                'Location: ' . BASE_URL .
                'index.php?controller=aliado&action=index'
            );
            exit;
        }

        $estructuraAliadosDisponible = $modeloAliado->estructuraDisponible();
        $aliados = $estructuraAliadosDisponible
            ? $modeloAliado->obtenerListado(
                $usuarioId,
                $esAdministrador,
                ['estado_id' => $estadoId]
            )
            : [];

        $municipiosAliados = [];
        $analistasAliados = [];
        $aliadosPorMunicipio = [];
        $aliadosSinMunicipio = [];

        foreach ($aliados as $aliado) {
            $municipioId = (int)($aliado['municipio_id'] ?? 0);
            $municipioNombre = trim(
                (string)($aliado['municipio_nombre'] ?? '')
            );
            $analistaId = (int)($aliado['analista_id'] ?? 0);

            if ($analistaId > 0) {
                $analistasAliados[$analistaId] = [
                    'id' => $analistaId,
                    'nombre' => (string)($aliado['analista_nombre'] ?? '')
                ];
            }

            if ($municipioId <= 0 || $municipioNombre === '') {
                $aliadosSinMunicipio[] = $aliado;
                continue;
            }

            $municipiosAliados[$municipioId] = [
                'id' => $municipioId,
                'nombre' => $municipioNombre
            ];

            if (!isset($aliadosPorMunicipio[$municipioId])) {
                $aliadosPorMunicipio[$municipioId] = [
                    'id' => $municipioId,
                    'nombre' => $municipioNombre,
                    'aliados' => []
                ];
            }

            $aliadosPorMunicipio[$municipioId]['aliados'][] = $aliado;
        }

        uasort($municipiosAliados, static function ($a, $b) {
            return strnatcasecmp((string)$a['nombre'], (string)$b['nombre']);
        });
        uasort($analistasAliados, static function ($a, $b) {
            return strcasecmp((string)$a['nombre'], (string)$b['nombre']);
        });
        uasort($aliadosPorMunicipio, static function ($a, $b) {
            return strnatcasecmp((string)$a['nombre'], (string)$b['nombre']);
        });

        if (!empty($aliadosSinMunicipio)) {
            $aliadosPorMunicipio['sin_municipio'] = [
                'id' => 0,
                'nombre' => 'Sin municipio',
                'aliados' => $aliadosSinMunicipio
            ];
        }

        $resumenAliados = [
            'total' => count($aliados),
            'municipios' => count($municipiosAliados),
            'con_correo' => 0,
            'con_whatsapp' => 0,
            'convocatorias_vigentes' => 0,
            'seguimientos_pendientes' => 0,
            'seguimientos_vencidos' => 0
        ];

        foreach ($aliados as $aliado) {
            $correo = trim((string)($aliado['correo_contacto'] ?? ''));
            $whatsappVerificado = trim(
                (string)($aliado['whatsapp_verificado'] ?? '')
            );
            $whatsappDifusionConfirmado =
                (int)($aliado['tiene_whatsapp_confirmado_contacto'] ?? 0) === 1;

            if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $resumenAliados['con_correo']++;
            }

            if ($whatsappVerificado !== '' || $whatsappDifusionConfirmado) {
                $resumenAliados['con_whatsapp']++;
            }

            $estadoSeguimiento = strtoupper(trim((string)(
                $aliado['seguimiento_convocatoria_estado'] ?? ''
            )));
            $proximoSeguimientoAt = trim((string)(
                $aliado['proximo_seguimiento_at'] ?? ''
            ));
            $seguimientoPendiente =
                $estadoSeguimiento !== '' &&
                !in_array(
                    $estadoSeguimiento,
                    ['DIFUSION_CONFIRMADA', 'NO_PARTICIPARA'],
                    true
                );

            if ($seguimientoPendiente) {
                $resumenAliados['seguimientos_pendientes']++;

                $proximoTimestamp = $proximoSeguimientoAt !== ''
                    ? strtotime($proximoSeguimientoAt)
                    : false;

                if (
                    $proximoTimestamp !== false &&
                    $proximoTimestamp <= time()
                ) {
                    $resumenAliados['seguimientos_vencidos']++;
                }
            }
        }

        $puedeConsultarConvocatorias = tienePermiso('convocatorias.ver');
        if ($estructuraAliadosDisponible && $puedeConsultarConvocatorias) {
            $resumenAliados['convocatorias_vigentes'] =
                $modeloAliado->contarConvocatoriasVigentesPorEstados([$estadoId]);
        }

        $puedeCompartirCorreo =
            tienePermiso('aliados.compartir_correo') &&
            $puedeConsultarConvocatorias;
        $puedeVerHistorial = tienePermiso('aliados.ver_historial');
        $estructuraContactosDisponible = $modeloAliado->contactosDisponibles();
        $puedeGestionarContactos =
            tienePermiso('aliados.gestionar_contactos') &&
            $estructuraContactosDisponible;
        $puedeAbrirExpediente =
            tienePermiso('seguimientos_vinculacion.ver');
        $puedeUsarWhatsapp = tienePermiso('whatsapp.ver');
        $puedePrepararWhatsapp =
            tienePermiso('aliados.preparar_whatsapp') &&
            $puedeConsultarConvocatorias;
        $seguimientoConvocatoriasDisponible =
            $modeloAliado->seguimientoConvocatoriasDisponible();
        $puedeSeguimientoConvocatorias =
            $seguimientoConvocatoriasDisponible &&
            (
                $esAdministrador ||
                tienePermiso('aliados.seguimiento_convocatorias')
            );
        $puedeOperarSeguimientoConvocatorias =
            !$esAdministrador &&
            $seguimientoConvocatoriasDisponible &&
            tienePermiso('aliados.seguimiento_convocatorias');

        $filtros = [
            'buscar' => trim((string)($_GET['buscar'] ?? '')),
            'municipio_id' => (int)($_GET['municipio_id'] ?? 0),
            'analista_id' => (int)($_GET['analista_id'] ?? 0),
            'difusion' => trim((string)($_GET['difusion'] ?? 'todos')),
            'formalizacion' => trim(
                (string)($_GET['formalizacion'] ?? 'todas')
            )
        ];

        $tituloPagina = 'Aliados';
        $subtituloPagina = (string)($estado['nombre'] ?? '');
        $opcionActiva = 'aliados';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/aliados/estado.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function prepararEnvio()
    {
        $this->validarPermiso('aliados.ver');
        $this->validarPermiso('convocatorias.ver');
        $this->validarAccesoCompartirConvocatoria();

        $modelo = new AliadoModel();
        $servicio = new AliadoDifusionService();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $seguimientoId = (int)($_GET['id'] ?? 0);

        $aliado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        if (!$aliado) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este aliado.'
            ], 403);
        }

        $convocatorias = $modelo->obtenerConvocatoriasDisponibles(
            (int)$aliado['estado_id']
        );

        $correo = trim((string)($aliado['correo_contacto'] ?? ''));
        $puedeCorreo =
            tienePermiso('aliados.compartir_correo') &&
            $correo !== '' &&
            filter_var($correo, FILTER_VALIDATE_EMAIL);

        $whatsappManual = tienePermiso('aliados.preparar_whatsapp')
            ? $servicio->prepararWhatsappManual($aliado)
            : [
                'disponible' => false,
                'motivo' =>
                    'Tu perfil no tiene permiso para preparar difusión por WhatsApp.'
            ];

        $this->responder([
            'ok' => true,
            'aliado' => $aliado,
            'convocatorias' => $convocatorias,
            'canales' => [
                'correo' => [
                    'disponible' => (bool)$puedeCorreo,
                    'destinatario' => $correo,
                    'motivo' => $puedeCorreo
                        ? ''
                        : (
                            tienePermiso('aliados.compartir_correo')
                                ? 'El aliado no tiene un correo válido.'
                                : 'Tu perfil no tiene permiso para enviar por correo.'
                        )
                ],
                'whatsapp_manual' => $whatsappManual
            ]
        ]);
    }

    public function borradorEnvio()
    {
        $this->validarPermiso('aliados.ver');
        $this->validarPermiso('convocatorias.ver');
        $this->validarAccesoCompartirConvocatoria();

        $modelo = new AliadoModel();
        $servicio = new AliadoDifusionService();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $seguimientoId = (int)($_GET['id'] ?? 0);
        $convocatoriaId = (int)($_GET['convocatoria_id'] ?? 0);

        $aliado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        if (!$aliado) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este aliado.'
            ], 403);
        }

        $convocatoria = $modelo->obtenerConvocatoriaAplicable(
            $convocatoriaId,
            (int)$aliado['estado_id']
        );

        if (!$convocatoria) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'La convocatoria no está vigente para este territorio.'
            ], 409);
        }

        $this->responder([
            'ok' => true,
            'borrador' => $servicio->construirBorrador($aliado, $convocatoria),
            'borrador_whatsapp' =>
                $servicio->construirBorradorWhatsapp($aliado, $convocatoria),
            'convocatoria' => $convocatoria
        ]);
    }

    public function historial()
    {
        $this->validarPermiso('aliados.ver_historial');

        $modelo = new AliadoModel();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $seguimientoId = (int)($_GET['id'] ?? 0);

        $aliado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        if (!$aliado) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este aliado.'
            ], 403);
        }

        $this->responder([
            'ok' => true,
            'aliado' => $aliado,
            'historial' => $modelo->obtenerHistorial(
                $seguimientoId,
                $usuarioId,
                $esAdministrador
            )
        ]);
    }

    public function seguimientoConvocatoria()
    {
        $this->validarPermiso('aliados.ver');

        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        if (!$esAdministrador) {
            $this->validarPermiso('aliados.seguimiento_convocatorias');
        }

        $modelo = new AliadoModel();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $seguimientoId = (int)($_GET['id'] ?? 0);
        $seguimientoConvocatoriaId =
            (int)($_GET['seguimiento_convocatoria_id'] ?? 0);

        if (!$modelo->seguimientoConvocatoriasDisponible()) {
            $this->responder([
                'ok' => false,
                'mensaje' =>
                    'Falta aplicar la migración de seguimiento de convocatorias.'
            ], 409);
        }

        $aliado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        if (!$aliado) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este aliado.'
            ], 403);
        }

        $seguimiento = $seguimientoConvocatoriaId > 0
            ? $modelo->obtenerSeguimientoConvocatoriaPorId(
                $seguimientoConvocatoriaId,
                $seguimientoId,
                $usuarioId,
                $esAdministrador
            )
            : $modelo->obtenerSeguimientoConvocatoriaActual(
                $seguimientoId,
                $usuarioId,
                $esAdministrador
            );

        $this->responder([
            'ok' => true,
            'aliado' => $aliado,
            'seguimiento' => $seguimiento
        ]);
    }

    public function guardarSeguimientoConvocatoria()
    {
        $this->validarPermiso('aliados.ver');
        $this->validarMetodoPost();

        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        if ($esAdministrador) {
            $this->responder([
                'ok' => false,
                'mensaje' =>
                    'El Administrador puede supervisar este seguimiento, pero no modificarlo.'
            ], 403);
        }

        $this->validarPermiso('aliados.seguimiento_convocatorias');

        $modelo = new AliadoModel();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
        $seguimientoConvocatoriaId =
            (int)($_POST['seguimiento_convocatoria_id'] ?? 0);

        if (!$modelo->seguimientoConvocatoriasDisponible()) {
            $this->responder([
                'ok' => false,
                'mensaje' =>
                    'Falta aplicar la migración de seguimiento de convocatorias.'
            ], 409);
        }

        $aliado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        if (!$aliado) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este aliado.'
            ], 403);
        }

        $actual = $seguimientoConvocatoriaId > 0
            ? $modelo->obtenerSeguimientoConvocatoriaPorId(
                $seguimientoConvocatoriaId,
                $seguimientoId,
                $usuarioId,
                $esAdministrador
            )
            : null;

        if (!$actual) {
            $this->responder([
                'ok' => false,
                'mensaje' =>
                    'Este seguimiento ya no está disponible o no pertenece al aliado seleccionado.'
            ], 409);
        }

        $estado = strtoupper(trim((string)($_POST['estado'] ?? '')));
        $estadosPermitidos = [
            'ESPERANDO_RESPUESTA',
            'DIFUSION_CONFIRMADA',
            'SOLICITA_INFORMACION',
            'NO_PARTICIPARA',
            'SIN_RESPUESTA'
        ];

        if (!in_array($estado, $estadosPermitidos, true)) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Selecciona un estado de seguimiento válido.'
            ], 422);
        }

        $nota = trim((string)($_POST['nota'] ?? ''));
        if (mb_strlen($nota) > 1000) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'La nota no puede superar los 1000 caracteres.'
            ], 422);
        }

        $proximoRaw = trim(
            (string)($_POST['proximo_seguimiento_at'] ?? '')
        );
        $proximoSeguimientoAt = null;
        $estadosCerrados = [
            'DIFUSION_CONFIRMADA',
            'NO_PARTICIPARA'
        ];

        if (
            $proximoRaw !== '' &&
            !in_array($estado, $estadosCerrados, true)
        ) {
            $timestamp = strtotime($proximoRaw);

            if ($timestamp === false) {
                $this->responder([
                    'ok' => false,
                    'mensaje' => 'La fecha del próximo seguimiento no es válida.'
                ], 422);
            }

            if ($timestamp <= time()) {
                $this->responder([
                    'ok' => false,
                    'mensaje' =>
                        'Programa el próximo seguimiento en una fecha y hora futuras.'
                ], 422);
            }

            $proximoSeguimientoAt = date('Y-m-d H:i:s', $timestamp);
        }

        $guardado = $modelo->guardarSeguimientoConvocatoria(
            $seguimientoConvocatoriaId,
            $seguimientoId,
            $usuarioId,
            [
                'estado' => $estado,
                'nota' => $nota,
                'proximo_seguimiento_at' => $proximoSeguimientoAt
            ]
        );

        if (!$guardado) {
            $this->responder([
                'ok' => false,
                'mensaje' =>
                    'No fue posible guardar el seguimiento de la convocatoria.'
            ], 500);
        }

        $seguimientoActualizado =
            $modelo->obtenerSeguimientoConvocatoriaPorId(
                $seguimientoConvocatoriaId,
                $seguimientoId,
                $usuarioId,
                $esAdministrador
            );

        $this->responder([
            'ok' => true,
            'mensaje' => 'Seguimiento actualizado correctamente.',
            'seguimiento' => $seguimientoActualizado
        ]);
    }

    public function contactos()
    {
        $this->validarPermiso('aliados.ver');

        $modelo = new AliadoModel();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $seguimientoId = (int)($_GET['id'] ?? 0);

        $aliado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        if (!$aliado) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este aliado.'
            ], 403);
        }

        if (!$modelo->contactosDisponibles()) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Falta aplicar la migración de contactos de difusión.'
            ], 409);
        }

        $this->responder([
            'ok' => true,
            'aliado' => $aliado,
            'contactos' => $modelo->obtenerContactosDifusion($aliado),
            'puede_gestionar' => tienePermiso('aliados.gestionar_contactos')
        ]);
    }

    public function guardarContacto()
    {
        $this->validarPermiso('aliados.gestionar_contactos');
        $this->validarMetodoPost();

        $modelo = new AliadoModel();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);

        $aliado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        if (!$aliado) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este aliado.'
            ], 403);
        }

        if (!$modelo->contactosDisponibles()) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Falta aplicar la migración de contactos de difusión.'
            ], 409);
        }

        $numero = trim((string)($_POST['numero'] ?? ''));
        $digitos = preg_replace('/[^0-9]+/', '', $numero);

        if (strlen($digitos) === 13 && strpos($digitos, '521') === 0) {
            $digitos = '52' . substr($digitos, 3);
        }

        if (strlen($digitos) < 7 || strlen($digitos) > 15) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Ingresa un número válido de entre 7 y 15 dígitos.'
            ], 422);
        }

        $etiqueta = trim((string)($_POST['etiqueta'] ?? 'Difusión'));
        if ($etiqueta === '') {
            $etiqueta = 'Difusión';
        }
        if (mb_strlen($etiqueta) > 80) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'La etiqueta del contacto es demasiado larga.'
            ], 422);
        }

        $origen = strtoupper(trim((string)($_POST['origen'] ?? 'CUENTA_CLAVE')));
        $origenesPermitidos = [
            'CUENTA_CLAVE',
            'WHATSAPP_VERIFICADO',
            'TELEFONO_VERIFICADO',
            'TELEFONO_FUENTE'
        ];

        if (!in_array($origen, $origenesPermitidos, true)) {
            $origen = 'CUENTA_CLAVE';
        }

        $campoOrigen = [
            'WHATSAPP_VERIFICADO' => 'whatsapp_verificado',
            'TELEFONO_VERIFICADO' => 'telefono_verificado',
            'TELEFONO_FUENTE' => 'telefono_fuente'
        ];

        if (isset($campoOrigen[$origen])) {
            $numeroOrigen = preg_replace(
                '/[^0-9]+/',
                '',
                (string)($aliado[$campoOrigen[$origen]] ?? '')
            );

            if (
                strlen($numeroOrigen) === 13 &&
                strpos($numeroOrigen, '521') === 0
            ) {
                $numeroOrigen = '52' . substr($numeroOrigen, 3);
            }

            if ($numeroOrigen === '' || $numeroOrigen !== $digitos) {
                $origen = 'CUENTA_CLAVE';
            }
        }

        $autorizadoWhatsapp =
            (int)($_POST['autorizado_whatsapp'] ?? 0) === 1;

        if (
            $autorizadoWhatsapp &&
            !$modelo->consentimientoWhatsappDisponible()
        ) {
            $this->responder([
                'ok' => false,
                'mensaje' =>
                    'Falta aplicar la migración de consentimiento de WhatsApp antes de registrar esta autorización.'
            ], 409);
        }

        try {
            $modelo->guardarContactoDifusion(
                $seguimientoId,
                $usuarioId,
                [
                    'id' => (int)($_POST['contacto_id'] ?? 0),
                    'numero' => $numero,
                    'etiqueta' => $etiqueta,
                    'origen' => $origen,
                    'confirmado_whatsapp' =>
                        (int)($_POST['confirmado_whatsapp'] ?? 0) === 1,
                    'autorizado_whatsapp' => $autorizadoWhatsapp,
                    'preferido_difusion' =>
                        (int)($_POST['preferido_difusion'] ?? 0) === 1
                ]
            );
        } catch (Throwable $error) {
            $this->responder([
                'ok' => false,
                'mensaje' => $error->getMessage()
            ], 409);
        }

        $aliadoActualizado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        $this->responder([
            'ok' => true,
            'mensaje' => 'Contacto de difusión guardado correctamente.',
            'contactos' => $modelo->obtenerContactosDifusion($aliadoActualizado)
        ]);
    }

    public function eliminarContacto()
    {
        $this->validarPermiso('aliados.gestionar_contactos');
        $this->validarMetodoPost();

        $modelo = new AliadoModel();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
        $contactoId = (int)($_POST['contacto_id'] ?? 0);

        $aliado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        if (!$aliado) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este aliado.'
            ], 403);
        }

        if ($contactoId <= 0 || !$modelo->desactivarContactoDifusion(
            $contactoId,
            $seguimientoId,
            $usuarioId
        )) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No fue posible retirar el contacto de difusión.'
            ], 422);
        }

        $aliadoActualizado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        $this->responder([
            'ok' => true,
            'mensaje' => 'Contacto retirado del directorio de difusión.',
            'contactos' => $modelo->obtenerContactosDifusion($aliadoActualizado)
        ]);
    }

    public function registrarConvocatoriaWhatsappManual()
    {
        $this->validarPermiso('aliados.ver');
        $this->validarPermiso('convocatorias.ver');
        $this->validarPermiso('aliados.preparar_whatsapp');
        $this->validarMetodoPost();

        $servicio = new AliadoDifusionService();
        $resultado = $servicio->registrarWhatsappManual(
            (int)($_SESSION['usuario_id'] ?? 0),
            (int)($_POST['seguimiento_id'] ?? 0),
            (int)($_POST['convocatoria_id'] ?? 0),
            $_POST['mensaje'] ?? '',
            (int)($_POST['confirmar_reenvio'] ?? 0) === 1,
            (int)($_SESSION['rol_id'] ?? 0) === 1
        );

        $codigoHttp = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);

        $this->responder($resultado, $codigoHttp);
    }

    public function enviarConvocatoriaWhatsapp()
    {
        $this->validarPermiso('aliados.ver');
        $this->validarPermiso('convocatorias.ver');
        $this->validarPermiso('whatsapp.enviar');
        $this->validarMetodoPost();

        $servicio = new AliadoDifusionService();
        $resultado = $servicio->enviarWhatsapp(
            (int)($_SESSION['usuario_id'] ?? 0),
            (int)($_POST['seguimiento_id'] ?? 0),
            (int)($_POST['convocatoria_id'] ?? 0),
            $_POST['mensaje'] ?? '',
            (int)($_POST['confirmar_reenvio'] ?? 0) === 1,
            (int)($_SESSION['rol_id'] ?? 0) === 1
        );

        $codigoHttp = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);

        $this->responder($resultado, $codigoHttp);
    }

    public function enviarConvocatoria()
    {
        $this->validarPermiso('aliados.compartir_correo');
        $this->validarPermiso('convocatorias.ver');
        $this->validarMetodoPost();

        $servicio = new AliadoDifusionService();
        $resultado = $servicio->enviar(
            (int)($_SESSION['usuario_id'] ?? 0),
            (int)($_POST['seguimiento_id'] ?? 0),
            (int)($_POST['convocatoria_id'] ?? 0),
            $_POST['asunto'] ?? '',
            $_POST['mensaje'] ?? '',
            (int)($_POST['confirmar_reenvio'] ?? 0) === 1,
            (int)($_SESSION['rol_id'] ?? 0) === 1
        );

        $codigoHttp = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);

        $this->responder($resultado, $codigoHttp);
    }

    private function validarAccesoCompartirConvocatoria()
    {
        if (
            tienePermiso('aliados.compartir_correo') ||
            tienePermiso('aliados.preparar_whatsapp')
        ) {
            return;
        }

        $this->responder([
            'ok' => false,
            'mensaje' =>
                'Tu perfil no tiene un canal autorizado para compartir convocatorias.'
        ], 403);
    }

    private function validarPermiso($codigo)
    {
        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401);
            echo 'Sesión no válida.';
            exit;
        }

        if (!tienePermiso($codigo)) {
            http_response_code(403);
            echo 'No tienes permiso para realizar esta operación.';
            exit;
        }
    }

    private function validarMetodoPost()
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }
    }

    private function responder($datos, $codigoHttp = 200)
    {
        http_response_code((int)$codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}
