<?php

require_once __DIR__ . '/../models/SeguimientoVinculacionModel.php';
require_once __DIR__ . '/../models/DataTerritorialModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../services/DenueService.php';

class SeguimientoVinculacionController
{
    public function index()
    {
        $this->validarPermiso('seguimientos_vinculacion.ver');

        $modelo = new SeguimientoVinculacionModel();
        $usuarioId = $this->obtenerUsuarioActualId();
        $modoSeguimiento = $this->resolverModoSeguimiento();
        $territorios = $this->obtenerTerritoriosPorModo(
            $modelo,
            $usuarioId,
            $modoSeguimiento
        );
        $mensajeError = $_SESSION['error_seguimiento_vinculacion'] ?? '';
        $mensajeExito = $_SESSION['mensaje_seguimiento_vinculacion'] ?? '';

        unset(
            $_SESSION['error_seguimiento_vinculacion'],
            $_SESSION['mensaje_seguimiento_vinculacion']
        );

        $tituloPagina = 'Seguimiento de vinculación';
        $subtituloPagina = $modoSeguimiento === 'administrador'
            ? 'Consulta el seguimiento institucional general'
            : 'Selecciona uno de tus territorios asignados';
        $opcionActiva = 'seguimiento_vinculacion';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/seguimiento_vinculacion/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function estado()
    {
        $this->validarPermiso('seguimientos_vinculacion.ver');

        $modelo = new SeguimientoVinculacionModel();
        $usuarioId = $this->obtenerUsuarioActualId();
        $modoSeguimiento = $this->resolverModoSeguimiento();
        $estadoId = (int)($_GET['estado_id'] ?? 0);
        $estado = $this->obtenerEstadoPorModo(
            $modelo,
            $usuarioId,
            $estadoId,
            $modoSeguimiento
        );

        if (!$estado) {
            $_SESSION['error_seguimiento_vinculacion'] =
                'No tienes acceso a este territorio.';
            $this->redirigirASeguimiento();
        }

        $filtrosSeguimiento = $this->obtenerFiltrosSeguimiento();
        $analistasFiltro = $this->obtenerAnalistasFiltro(
            $modelo,
            $usuarioId,
            $estadoId,
            $modoSeguimiento
        );
        $municipiosCandidatos = $modelo->obtenerMunicipiosActivosEstado($estadoId);
        $puedeCrearSeguimiento = tienePermiso('seguimientos_vinculacion.crear');
        $resumen = $this->obtenerResumenPorModo(
            $modelo,
            $usuarioId,
            $estadoId,
            $modoSeguimiento,
            $filtrosSeguimiento
        );
        $resumenTotalSeguimientos = $this->obtenerResumenPorModo(
            $modelo,
            $usuarioId,
            $estadoId,
            $modoSeguimiento,
            []
        );
        $seguimientos = $this->obtenerSeguimientosPorModo(
            $modelo,
            $usuarioId,
            $estadoId,
            $modoSeguimiento,
            $filtrosSeguimiento
        );
        $totalSeguimientosReales = (int)($resumenTotalSeguimientos['en_seguimiento'] ?? 0);
        $totalResultadosFiltrados = count($seguimientos);

        $tituloPagina = 'Seguimiento de vinculación';
        $subtituloPagina = (string)$estado['nombre'];
        $opcionActiva = 'seguimiento_vinculacion';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/seguimiento_vinculacion/estado.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function buscarCandidatos()
    {
        $this->validarPermisoJson('seguimientos_vinculacion.ver');

        $modelo = new SeguimientoVinculacionModel();
        $usuarioId = $this->obtenerUsuarioActualId();
        $modoSeguimiento = $this->resolverModoSeguimiento();
        $estadoId = (int)($_GET['estado_id'] ?? 0);
        $estado = $this->obtenerEstadoPorModo(
            $modelo,
            $usuarioId,
            $estadoId,
            $modoSeguimiento
        );

        if (!$estado) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este territorio.'
            ], 403);
        }

        $termino = trim((string)($_GET['buscar'] ?? ''));
        $busquedaAutomatica = (int)($_GET['automatico'] ?? 0) === 1;
        $tipoCandidato = strtoupper(trim((string)($_GET['tipo_candidato'] ?? 'TODOS')));
        $tiposValidos = ['TODOS', 'EMPRESAS', 'INSTITUCIONES', 'SECRETARIA'];

        if (!in_array($tipoCandidato, $tiposValidos, true)) {
            $tipoCandidato = 'TODOS';
        }

        if (
            !$busquedaAutomatica &&
            $tipoCandidato !== 'SECRETARIA' &&
            strlen($termino) < 3
        ) {
            $this->responderJson([
                'ok' => true,
                'candidatos' => [],
                'mensaje' => 'Escribe al menos 3 caracteres o usa la búsqueda automática.',
                'requiere_busqueda' => true,
                'pagina' => 1,
                'hay_mas' => false,
                'total_resultados' => 0,
                'resultados_por_pagina' => 10
            ]);
        }

        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $limite = 10;
        $offset = ($pagina - 1) * $limite;
        $municipioId = (int)($_GET['municipio_id'] ?? 0);
        $municipio = null;

        if ($municipioId > 0) {
            $municipio = $modelo->obtenerMunicipioActivoPorId($estadoId, $municipioId);

            if (!$municipio) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' => 'El municipio seleccionado no es válido.'
                ], 422);
            }
        }

        $candidatos = [];
        $advertencias = [];
        $denueFallido = false;
        $claveMunicipio = '0';
        $cacheDenue = [];
        $buscarDenuePorNombre = !$busquedaAutomatica &&
            strlen($termino) >= 3 &&
            in_array($tipoCandidato, ['TODOS', 'EMPRESAS', 'INSTITUCIONES'], true);
        $denueSolicitado = $buscarDenuePorNombre ||
            (
                $busquedaAutomatica &&
                in_array($tipoCandidato, ['TODOS', 'EMPRESAS', 'INSTITUCIONES'], true)
            );
        $limiteConsultaInterna = 80;

        if (
            $tipoCandidato === 'SECRETARIA' ||
            ($tipoCandidato === 'TODOS' && $municipioId === 0)
        ) {
            $secretarias = $modelo->buscarCandidatosSecretarias(
                $estadoId,
                $termino,
                $limiteConsultaInterna,
                0
            );
            $candidatos = array_merge($candidatos, $secretarias);
        }

        if ($municipioId > 0 && $municipio) {
            $claveMunicipio = $this->normalizarClaveMunicipalDenue(
                $municipio['clave_inegi'] ?? ''
            );
        }

        if ($buscarDenuePorNombre) {
            $cacheDenue = $this->buscarCandidatosDenueEnCache(
                $estadoId,
                $municipioId,
                $tipoCandidato,
                $termino
            );

            if (!empty($cacheDenue)) {
                $candidatos = array_merge($candidatos, $cacheDenue);
            }
        }

        if ($denueSolicitado) {
            $servicioDenue = new DenueService();

            if ($busquedaAutomatica) {
                $resultadoDenue = $servicioDenue->buscarCandidatosRecomendados(
                    str_pad((string)($estado['clave_inegi'] ?? ''), 2, '0', STR_PAD_LEFT),
                    $claveMunicipio,
                    250
                );
            } else {
                $resultadoDenue = $servicioDenue->buscarDenuePorNombre(
                    str_pad((string)($estado['clave_inegi'] ?? ''), 2, '0', STR_PAD_LEFT),
                    $claveMunicipio,
                    $termino,
                    160
                );
            }

            if ($resultadoDenue['ok']) {
                $denue = $this->completarMunicipiosDenue(
                    $modelo,
                    $estadoId,
                    $resultadoDenue['resultados'] ?? []
                );

                if ($municipioId > 0) {
                    $denue = $this->filtrarDenuePorMunicipio(
                        $denue,
                        $municipioId,
                        $claveMunicipio
                    );
                }

                if ($busquedaAutomatica) {
                    $this->guardarCacheDenueCandidatos($estadoId, $municipioId, $denue);
                }

                $denue = $this->filtrarDenuePorTipoCandidato($denue, $tipoCandidato);
                $candidatos = array_merge($candidatos, $denue);
            } else {
                $denueFallido = true;
            }
        }

        if ($denueFallido) {
            if (!empty($cacheDenue)) {
                $advertencias[] = 'No se pudieron obtener resultados adicionales de DENUE.';
            } elseif (!empty($candidatos)) {
                $advertencias[] = 'No pudimos consultar DENUE. Se muestran los candidatos disponibles en el sistema.';
            }
        }

        $prioridadesMunicipales = $this->obtenerPrioridadesMunicipales($estadoId);
        $candidatos = $this->agregarOportunidadCandidatos($candidatos, $prioridadesMunicipales);
        $candidatos = $this->agregarEstadoSeguimientoCandidatos($modelo, $estadoId, $candidatos);
        $candidatosCompletos = $candidatos;
        $totalCandidatos = count($candidatos);
        $candidatos = array_slice($candidatos, $offset, $limite);
        $mensajeVacio = 'No existen resultados de este tipo.';

        if ($denueFallido && $totalCandidatos === 0) {
            $mensajeVacio = 'No pudimos consultar DENUE en este momento.';
        } elseif ($denueSolicitado && !$denueFallido) {
            $mensajeVacio = $busquedaAutomatica
                ? 'No se encontraron candidatos DENUE recomendados.'
                : 'DENUE no encontró candidatos con ese nombre.';
        } elseif ($tipoCandidato === 'TODOS') {
            $mensajeVacio = 'No se encontraron candidatos con los criterios seleccionados.';
        }

        $this->responderJson([
            'ok' => true,
            'candidatos' => $candidatos,
            'pagina' => $pagina,
            'hay_mas' => $totalCandidatos > ($offset + $limite),
            'total_resultados' => $totalCandidatos,
            'resultados_por_pagina' => $limite,
            'cache_candidatos' => $busquedaAutomatica ? $candidatosCompletos : [],
            'advertencias' => $advertencias,
            'denue_solicitado' => $denueSolicitado,
            'mensaje_vacio' => $mensajeVacio,
            'puede_crear' => tienePermiso('seguimientos_vinculacion.crear')
        ]);
    }

    public function crearSeguimientoDesdeCandidato()
    {
        $this->validarMetodoPostJson();
        $this->validarPermisoJson('seguimientos_vinculacion.crear');

        $modelo = new SeguimientoVinculacionModel();
        $usuarioId = $this->obtenerUsuarioActualId();
        $modoSeguimiento = $this->resolverModoSeguimiento();
        $estadoId = (int)($_POST['estado_id'] ?? 0);
        $estado = $this->obtenerEstadoPorModo(
            $modelo,
            $usuarioId,
            $estadoId,
            $modoSeguimiento
        );

        if (!$estado) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este territorio.'
            ], 403);
        }

        $analistaId = $this->resolverAnalistaResponsable(
            $modelo,
            $usuarioId,
            $estadoId,
            $modoSeguimiento
        );

        if ($analistaId <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Selecciona un Analista responsable válido.'
            ], 422);
        }

        $origen = strtoupper(trim((string)($_POST['origen'] ?? '')));
        $claveOrigen = trim((string)($_POST['clave_origen'] ?? ''));
        $candidato = $this->resolverCandidatoParaCrear(
            $modelo,
            $estado,
            $origen,
            $claveOrigen
        );

        if (!$candidato) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El candidato seleccionado no es válido.'
            ], 422);
        }

        $seguimientoExistente = $modelo->obtenerSeguimientoPorClaveOrigen(
            $estadoId,
            $candidato['clave_origen']
        );

        if ($seguimientoExistente) {
            $this->responderJson([
                'ok' => false,
                'duplicado' => true,
                'activo' => (int)$seguimientoExistente['activo'] === 1,
                'mensaje' => 'Este candidato ya tiene un seguimiento registrado.',
                'url' => (int)$seguimientoExistente['activo'] === 1
                    ? BASE_URL .
                        'index.php?controller=seguimientoVinculacion&action=detalle&id=' .
                        (int)$seguimientoExistente['id']
                    : ''
            ], 409);
        }

        $candidato['estado_id'] = $estadoId;
        $candidato['analista_id'] = $analistaId;
        $seguimientoId = $modelo->crearSeguimientoDesdeCandidato($candidato);

        if ($seguimientoId <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible iniciar el seguimiento.'
            ], 500);
        }

        $this->responderJson([
            'ok' => true,
            'mensaje' => 'Seguimiento iniciado correctamente.',
            'seguimiento_id' => $seguimientoId,
            'url' => BASE_URL .
                'index.php?controller=seguimientoVinculacion&action=detalle&id=' .
            $seguimientoId
        ]);
    }

    public function obtenerPanelTrabajo()
    {
        $this->validarPermisoJson('seguimientos_vinculacion.ver');

        $modelo = new SeguimientoVinculacionModel();
        $usuarioId = $this->obtenerUsuarioActualId();
        $modoSeguimiento = $this->resolverModoSeguimiento();
        $seguimientoId = (int)($_GET['id'] ?? 0);
        $seguimiento = $this->obtenerSeguimientoPorModo(
            $modelo,
            $usuarioId,
            $seguimientoId,
            $modoSeguimiento
        );

        if (!$seguimiento) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes acceso al seguimiento solicitado.'
            ], 403);
        }

        $this->responderJson([
            'ok' => true,
            'seguimiento' => $this->serializarSeguimientoTrabajoConPermisos(
                $seguimiento,
                $usuarioId,
                $modoSeguimiento
            ),
            'interacciones' => $this->serializarInteraccionesTrabajo(
                $modelo->obtenerUltimasInteraccionesSeguimiento($seguimientoId, 3)
            ),
            'observaciones' => $this->serializarObservacionesTrabajo(
                $modelo->obtenerUltimasObservacionesSeguimiento($seguimientoId, 2)
            ),
            'observaciones_nuevas' => $modoSeguimiento === 'analista'
                ? $modelo->contarObservacionesNoLeidas($seguimientoId, $usuarioId)
                : 0,
            'puede_operar' => $this->puedeOperarSeguimiento($seguimiento, $usuarioId, $modoSeguimiento),
            'expediente_url' => BASE_URL .
                'index.php?controller=seguimientoVinculacion&action=detalle&id=' .
                $seguimientoId
        ]);
    }

    public function actualizarContactoTrabajo()
    {
        $this->validarMetodoPostJson();
        $this->validarPermisoJson('seguimientos_vinculacion.ver');

        $modelo = new SeguimientoVinculacionModel();
        $usuarioId = $this->obtenerUsuarioActualId();
        $modoSeguimiento = $this->resolverModoSeguimiento();
        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
        $seguimiento = $this->obtenerSeguimientoPorModo(
            $modelo,
            $usuarioId,
            $seguimientoId,
            $modoSeguimiento
        );

        if (!$seguimiento || !$this->puedeOperarSeguimiento($seguimiento, $usuarioId, $modoSeguimiento)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permiso para actualizar este seguimiento.'
            ], 403);
        }

        if ($this->seguimientoEstaDescartado($seguimiento)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El seguimiento está descartado y solo puede consultarse.'
            ], 422);
        }

        $datos = [
            'telefono_verificado' => trim((string)($_POST['telefono_verificado'] ?? '')),
            'whatsapp_verificado' => trim((string)($_POST['whatsapp_verificado'] ?? '')),
            'correo_verificado' => trim((string)($_POST['correo_verificado'] ?? '')),
            'contacto_nombre' => trim((string)($_POST['contacto_nombre'] ?? '')),
            'contacto_cargo' => trim((string)($_POST['contacto_cargo'] ?? '')),
            'observaciones' => trim((string)($_POST['observaciones'] ?? ''))
        ];

        if (!$modelo->actualizarContactoSeguimiento($seguimientoId, $usuarioId, $datos, false)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible actualizar los datos de contacto.'
            ], 500);
        }

        $seguimientoActualizado = $this->obtenerSeguimientoPorModo(
            $modelo,
            $usuarioId,
            $seguimientoId,
            $modoSeguimiento
        );

        $this->responderJson([
            'ok' => true,
            'mensaje' => 'Datos de contacto actualizados correctamente.',
            'seguimiento' => $this->serializarSeguimientoTrabajoConPermisos(
                $seguimientoActualizado,
                $usuarioId,
                $modoSeguimiento
            ),
            'resumen' => $this->obtenerResumenTrabajoActualizado(
                $modelo,
                $usuarioId,
                $seguimientoActualizado,
                $modoSeguimiento
            )
        ]);
    }

    public function marcarContactoVerificadoTrabajo()
    {
        $this->validarMetodoPostJson();
        $this->validarPermisoJson('seguimientos_vinculacion.ver');

        $modelo = new SeguimientoVinculacionModel();
        $usuarioId = $this->obtenerUsuarioActualId();
        $modoSeguimiento = $this->resolverModoSeguimiento();
        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
        $seguimiento = $this->obtenerSeguimientoPorModo(
            $modelo,
            $usuarioId,
            $seguimientoId,
            $modoSeguimiento
        );

        if (!$seguimiento || !$this->puedeOperarSeguimiento($seguimiento, $usuarioId, $modoSeguimiento)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permiso para verificar este seguimiento.'
            ], 403);
        }

        if ($this->seguimientoEstaDescartado($seguimiento)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El seguimiento está descartado y solo puede consultarse.'
            ], 422);
        }

        if (!$modelo->marcarDatosVerificadosSeguimiento($seguimientoId, $usuarioId)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible marcar la información como verificada.'
            ], 500);
        }

        $seguimientoActualizado = $this->obtenerSeguimientoPorModo(
            $modelo,
            $usuarioId,
            $seguimientoId,
            $modoSeguimiento
        );

        $this->responderJson([
            'ok' => true,
            'mensaje' => 'Información marcada como verificada correctamente.',
            'seguimiento' => $this->serializarSeguimientoTrabajoConPermisos(
                $seguimientoActualizado,
                $usuarioId,
                $modoSeguimiento
            ),
            'resumen' => $this->obtenerResumenTrabajoActualizado(
                $modelo,
                $usuarioId,
                $seguimientoActualizado,
                $modoSeguimiento
            )
        ]);
    }

    public function registrarInteraccionTrabajo()
    {
        $this->validarMetodoPostJson();
        $this->validarPermisoJson('seguimientos_vinculacion.ver');

        $modelo = new SeguimientoVinculacionModel();
        $usuarioId = $this->obtenerUsuarioActualId();
        $modoSeguimiento = $this->resolverModoSeguimiento();
        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
        $seguimiento = $this->obtenerSeguimientoPorModo(
            $modelo,
            $usuarioId,
            $seguimientoId,
            $modoSeguimiento
        );

        if (!$seguimiento || !$this->puedeOperarSeguimiento($seguimiento, $usuarioId, $modoSeguimiento)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permiso para registrar interacción en este seguimiento.'
            ], 403);
        }

        if ($this->seguimientoEstaDescartado($seguimiento)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El seguimiento está descartado y solo puede consultarse.'
            ], 422);
        }

        $canalFormulario = strtoupper(trim((string)($_POST['canal'] ?? '')));
        $canales = [
            'LLAMADA' => 'LLAMADA_IP',
            'WHATSAPP' => 'WHATSAPP',
            'CORREO' => 'CORREO',
            'OTRO' => 'NOTA'
        ];
        $canal = $canales[$canalFormulario] ?? '';
        $resultadoFormulario = strtoupper(trim((string)($_POST['resultado'] ?? '')));
        $mapaResultados = [
            'SIN_RESPUESTA' => 'SIN_RESPUESTA',
            'NUMERO_INCORRECTO' => 'NUMERO_INCORRECTO',
            'CONTACTO_INCORRECTO' => 'OCUPADO',
            'CONTACTO_CORRECTO' => 'CONTACTADO',
            'SOLICITO_INFORMACION' => 'MENSAJE_ENVIADO',
            'SOLICITO_LLAMAR_DESPUES' => 'SOLICITO_LLAMAR_DESPUES',
            'NO_INTERESADO' => 'OTRO',
            'OTRO' => 'OTRO'
        ];
        $resultado = $mapaResultados[$resultadoFormulario] ?? '';
        $resultadosValidos = [
            'CONTACTADO',
            'NO_CONTESTO',
            'OCUPADO',
            'NUMERO_INCORRECTO',
            'SOLICITO_LLAMAR_DESPUES',
            'MENSAJE_ENVIADO',
            'CORREO_ENVIADO',
            'SIN_RESPUESTA',
            'OTRO'
        ];

        if ($canal === '' || !in_array($resultado, $resultadosValidos, true)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Selecciona canal y resultado válidos.'
            ], 422);
        }

        $fechaInicio = $this->normalizarFechaFormulario($_POST['fecha_inicio'] ?? '');
        $proximaAccionAt = $this->normalizarFechaFormulario($_POST['proxima_accion_at'] ?? '', true);

        if ($fechaInicio === '') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La fecha de interacción es obligatoria.'
            ], 422);
        }

        $personaAtendio = trim((string)($_POST['persona_atendio'] ?? ''));
        $proximaAccion = trim((string)($_POST['proxima_accion'] ?? ''));
        $observacion = trim((string)($_POST['observacion'] ?? ''));
        $descartar = $resultadoFormulario === 'NO_INTERESADO' &&
            (int)($_POST['descartar'] ?? 0) === 1;
        $motivoDescarte = trim((string)($_POST['motivo_descarte'] ?? ''));

        if ($descartar && $motivoDescarte === '') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El motivo de descarte es obligatorio.'
            ], 422);
        }

        $notas = trim(implode("\n", array_filter([
            $personaAtendio !== '' ? 'Persona atendió: ' . $personaAtendio : '',
            $resultadoFormulario === 'NO_INTERESADO' ? 'Resultado registrado: No interesado' : '',
            $observacion,
            $proximaAccion !== '' ? 'Próxima acción: ' . $proximaAccion : '',
            $descartar ? 'Motivo de descarte: ' . $motivoDescarte : ''
        ])));

        $datosInteraccion = [
            'canal' => $canal,
            'resultado' => $resultado,
            'fecha_inicio' => $fechaInicio,
            'notas' => $notas,
            'telefono_destino' => $seguimiento['telefono_verificado'] ?: $seguimiento['telefono_fuente'] ?: '',
            'correo_destino' => $seguimiento['correo_verificado'] ?: $seguimiento['correo_fuente'] ?: '',
            'proxima_accion_at' => $proximaAccionAt,
            'datos_verificados' => (int)($seguimiento['datos_verificados'] ?? 0),
            'descartar' => $descartar ? 1 : 0,
            'motivo_descarte' => $motivoDescarte
        ];

        if (!$modelo->registrarInteraccionManual($seguimientoId, $usuarioId, $datosInteraccion)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible registrar la interacción.'
            ], 500);
        }

        $seguimientoActualizado = $this->obtenerSeguimientoPorModo(
            $modelo,
            $usuarioId,
            $seguimientoId,
            $modoSeguimiento
        );

        $this->responderJson([
            'ok' => true,
            'mensaje' => 'Interacción registrada correctamente.',
            'seguimiento' => $this->serializarSeguimientoTrabajoConPermisos(
                $seguimientoActualizado,
                $usuarioId,
                $modoSeguimiento
            ),
            'interacciones' => $this->serializarInteraccionesTrabajo(
                $modelo->obtenerUltimasInteraccionesSeguimiento($seguimientoId, 3)
            ),
            'resumen' => $this->obtenerResumenTrabajoActualizado(
                $modelo,
                $usuarioId,
                $seguimientoActualizado,
                $modoSeguimiento
            )
        ]);
    }

    public function descartarSeguimientoTrabajo()
    {
        $this->validarMetodoPostJson();
        $this->validarPermisoJson('seguimientos_vinculacion.ver');

        $modelo = new SeguimientoVinculacionModel();
        $usuarioId = $this->obtenerUsuarioActualId();
        $modoSeguimiento = $this->resolverModoSeguimiento();
        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
        $seguimiento = $this->obtenerSeguimientoPorModo(
            $modelo,
            $usuarioId,
            $seguimientoId,
            $modoSeguimiento
        );

        if (!$seguimiento || !$this->puedeOperarSeguimiento($seguimiento, $usuarioId, $modoSeguimiento)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permiso para descartar este seguimiento.'
            ], 403);
        }

        if ((string)($seguimiento['estado_seguimiento'] ?? '') === 'DESCARTADO') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Este seguimiento ya está descartado.'
            ], 422);
        }

        $motivoDescarte = trim((string)($_POST['motivo_descarte'] ?? ''));

        if ($motivoDescarte === '') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El motivo de descarte es obligatorio.'
            ], 422);
        }

        if (strlen($motivoDescarte) > 255) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El motivo de descarte no debe superar 255 caracteres.'
            ], 422);
        }

        if (!$modelo->descartarSeguimientoTrabajo($seguimientoId, $motivoDescarte)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible descartar el seguimiento.'
            ], 500);
        }

        $seguimientoActualizado = $this->obtenerSeguimientoPorModo(
            $modelo,
            $usuarioId,
            $seguimientoId,
            $modoSeguimiento
        );

        $this->responderJson([
            'ok' => true,
            'mensaje' => 'Seguimiento descartado correctamente.',
            'puede_operar' => $this->puedeOperarSeguimiento(
                $seguimientoActualizado,
                $usuarioId,
                $modoSeguimiento
            ),
            'seguimiento' => $this->serializarSeguimientoTrabajoConPermisos(
                $seguimientoActualizado,
                $usuarioId,
                $modoSeguimiento
            ),
            'interacciones' => $this->serializarInteraccionesTrabajo(
                $modelo->obtenerUltimasInteraccionesSeguimiento($seguimientoId, 3)
            ),
            'resumen' => $this->obtenerResumenTrabajoActualizado(
                $modelo,
                $usuarioId,
                $seguimientoActualizado,
                $modoSeguimiento
            )
        ]);
    }

    public function reactivarSeguimientoTrabajo()
    {
        $this->validarMetodoPostJson();
        $this->validarPermisoJson('seguimientos_vinculacion.ver');

        $modelo = new SeguimientoVinculacionModel();
        $usuarioId = $this->obtenerUsuarioActualId();
        $modoSeguimiento = $this->resolverModoSeguimiento();
        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
        $seguimiento = $this->obtenerSeguimientoPorModo(
            $modelo,
            $usuarioId,
            $seguimientoId,
            $modoSeguimiento
        );

        if (!$seguimiento || !$this->puedeReactivarSeguimiento($seguimiento, $usuarioId, $modoSeguimiento)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permiso para reactivar este seguimiento.'
            ], 403);
        }

        if (!$this->seguimientoEstaDescartado($seguimiento)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Solo se pueden reactivar seguimientos descartados.'
            ], 422);
        }

        $motivoReactivacion = trim((string)($_POST['motivo_reactivacion'] ?? ''));
        $observacion = trim((string)($_POST['observacion'] ?? ''));
        $motivosValidos = [
            'La institución volvió a contactar',
            'Ahora muestra interés',
            'Solicitó retomar la vinculación',
            'Indicación del Cuenta Clave',
            'Otro'
        ];

        if (!in_array($motivoReactivacion, $motivosValidos, true)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Selecciona un motivo de reactivación válido.'
            ], 422);
        }

        if (strlen($observacion) > 1000) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La observación no debe superar 1000 caracteres.'
            ], 422);
        }

        if (!$modelo->reactivarSeguimientoTrabajo($seguimientoId, $usuarioId, $motivoReactivacion, $observacion)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible reactivar el seguimiento.'
            ], 500);
        }

        $seguimientoActualizado = $this->obtenerSeguimientoPorModo(
            $modelo,
            $usuarioId,
            $seguimientoId,
            $modoSeguimiento
        );

        $this->responderJson([
            'ok' => true,
            'mensaje' => 'Seguimiento reactivado correctamente.',
            'puede_operar' => $this->puedeOperarSeguimiento(
                $seguimientoActualizado,
                $usuarioId,
                $modoSeguimiento
            ),
            'seguimiento' => $this->serializarSeguimientoTrabajoConPermisos(
                $seguimientoActualizado,
                $usuarioId,
                $modoSeguimiento
            ),
            'interacciones' => $this->serializarInteraccionesTrabajo(
                $modelo->obtenerUltimasInteraccionesSeguimiento($seguimientoId, 3)
            ),
            'resumen' => $this->obtenerResumenTrabajoActualizado(
                $modelo,
                $usuarioId,
                $seguimientoActualizado,
                $modoSeguimiento
            )
        ]);
    }

    public function detalle()
    {
        $this->validarPermiso('seguimientos_vinculacion.ver');

        $modelo = new SeguimientoVinculacionModel();
        $usuarioId = $this->obtenerUsuarioActualId();
        $modoSeguimiento = $this->resolverModoSeguimiento();
        $seguimientoId = (int)($_GET['id'] ?? 0);
        $seguimiento = $this->obtenerSeguimientoPorModo(
            $modelo,
            $usuarioId,
            $seguimientoId,
            $modoSeguimiento
        );

        if (!$seguimiento) {
            $_SESSION['error_seguimiento_vinculacion'] =
                'No tienes acceso al seguimiento solicitado.';
            $this->redirigirASeguimiento();
        }

        $nuevasObservaciones = 0;

        if ($modoSeguimiento === 'analista') {
            $nuevasObservaciones = $modelo->contarObservacionesNoLeidas(
                $seguimientoId,
                $usuarioId
            );
            $modelo->marcarObservacionesLeidas($seguimientoId, $usuarioId);
        }

        $interacciones = $modelo->obtenerInteraccionesSeguimiento($seguimientoId);
        $oficios = $modelo->obtenerOficiosSeguimiento($seguimientoId);
        $observaciones = $modelo->obtenerObservacionesSeguimiento($seguimientoId);
        $puedeComentar = $modoSeguimiento === 'supervisor' &&
            tienePermiso('seguimientos_vinculacion.comentar');
        $mensajeError = $_SESSION['error_seguimiento_vinculacion'] ?? '';
        $mensajeExito = $_SESSION['mensaje_seguimiento_vinculacion'] ?? '';

        unset(
            $_SESSION['error_seguimiento_vinculacion'],
            $_SESSION['mensaje_seguimiento_vinculacion']
        );

        $tituloPagina = 'Expediente completo';
        $subtituloPagina = (string)$seguimiento['nombre_entidad'];
        $opcionActiva = 'seguimiento_vinculacion';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/seguimiento_vinculacion/detalle.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function guardarObservacion()
    {
        $this->validarPermiso('seguimientos_vinculacion.ver');
        $this->validarPermiso('seguimientos_vinculacion.comentar');
        $this->validarMetodoPost();

        $modelo = new SeguimientoVinculacionModel();
        $usuarioId = $this->obtenerUsuarioActualId();
        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
        $observacion = trim((string)($_POST['observacion'] ?? ''));
        $seguimiento = $modelo->obtenerSeguimientoSupervisor($usuarioId, $seguimientoId);

        if (!$seguimiento) {
            $_SESSION['error_seguimiento_vinculacion'] =
                'No tienes acceso al seguimiento solicitado.';
            $this->redirigirASeguimiento();
        }

        if ($observacion === '') {
            $_SESSION['error_seguimiento_vinculacion'] =
                'La observación es obligatoria.';
            $this->redirigirADetalle($seguimientoId);
        }

        if (strlen($observacion) > 2000) {
            $_SESSION['error_seguimiento_vinculacion'] =
                'La observación no debe superar 2000 caracteres.';
            $this->redirigirADetalle($seguimientoId);
        }

        $resultado = $modelo->crearObservacion(
            $seguimientoId,
            $usuarioId,
            (int)$seguimiento['analista_id'],
            $observacion
        );

        if ($resultado) {
            $_SESSION['mensaje_seguimiento_vinculacion'] =
                'Observación enviada correctamente.';
        } else {
            $_SESSION['error_seguimiento_vinculacion'] =
                'No fue posible enviar la observación.';
        }

        $this->redirigirADetalle($seguimientoId);
    }

    private function obtenerPrioridadesMunicipales($estadoId)
    {
        $modeloTerritorial = new DataTerritorialModel();
        $priorizacion = $modeloTerritorial->obtenerPriorizacionMunicipal($estadoId, 5);

        return is_array($priorizacion['por_municipio'] ?? null)
            ? $priorizacion['por_municipio']
            : [];
    }

    private function agregarOportunidadCandidatos($candidatos, $prioridadesMunicipales)
    {
        $candidatos = $this->deduplicarCandidatos($candidatos);

        foreach ($candidatos as $indice => $candidato) {
            $municipioId = (int)($candidato['municipio_id'] ?? 0);
            $prioridad = $municipioId > 0
                ? ($prioridadesMunicipales[$municipioId] ?? null)
                : null;
            $accionMunicipal = strtoupper((string)($prioridad['accion'] ?? ''));

            if ($accionMunicipal !== '') {
                $candidatos[$indice]['prioridad_municipal'] = $accionMunicipal;
            }

            $candidatos[$indice] = array_merge(
                $candidatos[$indice],
                $this->calcularOportunidadCandidato($candidato, $accionMunicipal)
            );
        }

        usort($candidatos, function ($candidatoA, $candidatoB) {
            $comparacionOportunidad =
                (int)($candidatoB['oportunidad_puntaje'] ?? 0) <=>
                (int)($candidatoA['oportunidad_puntaje'] ?? 0);

            if ($comparacionOportunidad !== 0) {
                return $comparacionOportunidad;
            }

            $comparacionTamano =
                (int)($candidatoB['estrato_valor'] ?? 0) <=>
                (int)($candidatoA['estrato_valor'] ?? 0);

            if ($comparacionTamano !== 0) {
                return $comparacionTamano;
            }

            return strcasecmp(
                (string)($candidatoA['nombre'] ?? ''),
                (string)($candidatoB['nombre'] ?? '')
            );
        });

        return $candidatos;
    }

    private function deduplicarCandidatos($candidatos)
    {
        $unicos = [];

        foreach ($candidatos as $candidato) {
            $clave = trim((string)($candidato['clave_origen'] ?? ''));

            if ($clave === '' || isset($unicos[$clave])) {
                continue;
            }

            $unicos[$clave] = $candidato;
        }

        return array_values($unicos);
    }

    private function guardarCacheDenueCandidatos($estadoId, $municipioId, $candidatos)
    {
        if (!isset($_SESSION['seguimiento_denue_cache']) || !is_array($_SESSION['seguimiento_denue_cache'])) {
            $_SESSION['seguimiento_denue_cache'] = [];
        }

        $_SESSION['seguimiento_denue_cache'][$this->obtenerClaveCacheDenue($estadoId, $municipioId)] = [
            'creado_at' => time(),
            'candidatos' => array_values($candidatos)
        ];
    }

    private function buscarCandidatosDenueEnCache($estadoId, $municipioId, $tipoCandidato, $termino)
    {
        $claveCache = $this->obtenerClaveCacheDenue($estadoId, $municipioId);
        $cache = $_SESSION['seguimiento_denue_cache'][$claveCache] ?? null;

        if (!$cache || !is_array($cache)) {
            return [];
        }

        if ((time() - (int)($cache['creado_at'] ?? 0)) > 1800) {
            unset($_SESSION['seguimiento_denue_cache'][$claveCache]);
            return [];
        }

        $terminoNormalizado = $this->normalizarTexto($termino);
        $candidatos = $this->filtrarDenuePorTipoCandidato(
            $cache['candidatos'] ?? [],
            $tipoCandidato
        );

        return array_values(array_filter(
            $candidatos,
            function ($candidato) use ($terminoNormalizado) {
                $textoBusqueda = $this->normalizarTexto(implode(' ', [
                    $candidato['nombre'] ?? '',
                    $candidato['razon_social'] ?? '',
                    $candidato['actividad'] ?? ''
                ]));

                return $terminoNormalizado !== '' &&
                    strpos($textoBusqueda, $terminoNormalizado) !== false;
            }
        ));
    }

    private function obtenerClaveCacheDenue($estadoId, $municipioId)
    {
        return (int)$estadoId . ':' . (int)$municipioId;
    }

    private function filtrarDenuePorTipoCandidato($candidatos, $tipoCandidato)
    {
        if ($tipoCandidato === 'TODOS') {
            return array_values($candidatos);
        }

        return array_values(array_filter(
            $candidatos,
            function ($candidato) use ($tipoCandidato) {
                $esInstitucion = $this->esCandidatoDenueInstitucional($candidato);

                if ($tipoCandidato === 'INSTITUCIONES') {
                    return $esInstitucion;
                }

                if ($tipoCandidato === 'EMPRESAS') {
                    return !$esInstitucion;
                }

                return true;
            }
        ));
    }

    private function esCandidatoDenueInstitucional($candidato)
    {
        $tipo = strtoupper((string)($candidato['tipo_entidad'] ?? ''));
        $sector = (string)($candidato['sector'] ?? '');
        $actividad = $this->normalizarTexto((string)($candidato['actividad'] ?? ''));
        $nombre = $this->normalizarTexto((string)($candidato['nombre'] ?? ''));

        if ($tipo === 'INSTITUCION' || in_array($sector, ['61', '62', '81', '93'], true)) {
            return true;
        }

        $texto = $nombre . ' ' . $actividad;
        $palabrasInstitucionales = [
            'institut',
            'universidad',
            'colegio',
            'escuela',
            'educacion',
            'hospital',
            'clinica',
            'salud',
            'asistencia',
            'gobierno',
            'publica',
            'secretaria',
            'asociacion',
            'camara',
            'fundacion'
        ];

        foreach ($palabrasInstitucionales as $palabra) {
            if (strpos($texto, $palabra) !== false) {
                return true;
            }
        }

        return false;
    }

    private function calcularOportunidadCandidato($candidato, $accionMunicipal)
    {
        $origen = strtoupper((string)($candidato['origen'] ?? ''));
        $puntosTamano = $this->puntuarTamanoCandidato((int)($candidato['estrato_valor'] ?? 0));
        $puntosRelevancia = $this->puntuarRelevanciaCandidato($candidato);
        $puntosContacto = $this->puntuarContactoCandidato($candidato);
        $puntosMunicipio = $this->puntuarPrioridadMunicipal($accionMunicipal);
        $puntaje = $puntosTamano + $puntosRelevancia + $puntosContacto + $puntosMunicipio;
        $nivel = 'REVISAR';
        $etiqueta = $origen === 'DENUE' ? 'Revisar' : null;

        if ($puntaje >= 75) {
            $nivel = 'ALTA';
            $etiqueta = $origen === 'DENUE' ? 'Alta oportunidad' : null;
        } elseif ($puntaje >= 50) {
            $nivel = 'MEDIA';
            $etiqueta = $origen === 'DENUE' ? 'Media oportunidad' : null;
        }

        return [
            'oportunidad_puntaje' => $puntaje,
            'oportunidad_nivel' => $nivel,
            'oportunidad_etiqueta' => $etiqueta,
            'oportunidad_componentes' => [
                'tamano' => $puntosTamano,
                'relevancia' => $puntosRelevancia,
                'contacto' => $puntosContacto,
                'municipio' => $puntosMunicipio
            ],
            'recomendacion' => $origen === 'DENUE'
                ? $this->construirMotivoRecomendacion($candidato, $accionMunicipal, $puntosContacto)
                : null
        ];
    }

    private function puntuarTamanoCandidato($estrato)
    {
        $puntosPorEstrato = [
            1 => 3,
            2 => 8,
            3 => 17,
            4 => 23,
            5 => 28,
            6 => 32,
            7 => 35
        ];

        if (isset($puntosPorEstrato[$estrato])) {
            return $puntosPorEstrato[$estrato];
        }

        return $estrato === 0 ? 0 : 10;
    }

    private function puntuarRelevanciaCandidato($candidato)
    {
        $tipo = strtoupper((string)($candidato['tipo_entidad'] ?? ''));
        $actividad = $this->normalizarTexto((string)($candidato['actividad'] ?? ''));
        $sector = (string)($candidato['sector'] ?? '');

        if (in_array($tipo, ['SECRETARIA', 'MUNICIPIO'], true)) {
            return 30;
        }

        if (in_array($sector, ['31', '32', '33', '52', '54', '55', '61', '62', '93'], true)) {
            return 30;
        }

        if (in_array($sector, ['48', '49'], true)) {
            return 26;
        }

        $palabrasClaveAltas = [
            'administracion publica',
            'gubernamental',
            'educacion',
            'educativo',
            'salud',
            'asistencia social',
            'asociacion',
            'camara',
            'manufactur',
            'profesional',
            'cientifico',
            'corporativ',
            'financier',
            'seguro'
        ];

        foreach ($palabrasClaveAltas as $palabraClave) {
            if ($actividad !== '' && strpos($actividad, $palabraClave) !== false) {
                return 30;
            }
        }

        if (
            strpos($actividad, 'transporte') !== false ||
            strpos($actividad, 'almacenamiento') !== false ||
            strpos($actividad, 'logistica') !== false
        ) {
            return 26;
        }

        return 12;
    }

    private function puntuarContactoCandidato($candidato)
    {
        $telefono = trim((string)($candidato['telefono'] ?? '')) !== '';
        $correo = trim((string)($candidato['correo'] ?? '')) !== '';
        $sitioWeb = trim((string)($candidato['sitio_web'] ?? '')) !== '';

        if ($telefono && $correo) {
            return 20;
        }

        if ($telefono || $correo) {
            return 12 + ($sitioWeb ? 3 : 0);
        }

        return $sitioWeb ? 6 : 0;
    }

    private function puntuarPrioridadMunicipal($accionMunicipal)
    {
        if ($accionMunicipal === 'ATACAR') {
            return 15;
        }

        if ($accionMunicipal === 'OFRECER') {
            return 10;
        }

        if ($accionMunicipal === 'OBSERVAR') {
            return 5;
        }

        return 0;
    }

    private function construirMotivoRecomendacion($candidato, $accionMunicipal, $puntosContacto)
    {
        $motivos = [];
        $estrato = trim((string)($candidato['estrato_etiqueta'] ?? ''));

        if ($estrato !== '') {
            $motivos[] = $estrato;
        }

        if ($puntosContacto >= 20) {
            $motivos[] = 'Contacto completo';
        } elseif ($puntosContacto > 0) {
            $motivos[] = 'Contacto disponible';
        }

        if (empty($motivos)) {
            $motivos[] = 'Información disponible para revisión';
        }

        return implode(' · ', $motivos);
    }

    private function normalizarClaveMunicipalDenue($claveInegi)
    {
        $claveInegi = preg_replace('/\D+/', '', (string)$claveInegi);

        if ($claveInegi === '') {
            return '0';
        }

        return str_pad(substr($claveInegi, -3), 3, '0', STR_PAD_LEFT);
    }

    private function completarMunicipiosDenue($modelo, $estadoId, $candidatos)
    {
        foreach ($candidatos as $indice => $candidato) {
            $claveArea = trim((string)($candidato['clave_area'] ?? ''));

            if (strlen($claveArea) >= 5 && ctype_digit(substr($claveArea, 0, 5))) {
                $municipio = $modelo->obtenerMunicipioActivoPorClave(
                    $estadoId,
                    substr($claveArea, 2, 3)
                );

                if ($municipio) {
                    $candidatos[$indice]['municipio_id'] = (int)$municipio['id'];
                    $candidatos[$indice]['municipio_nombre'] = $municipio['nombre'];
                }
            }
        }

        return $candidatos;
    }

    private function filtrarDenuePorMunicipio($candidatos, $municipioId, $claveMunicipio)
    {
        return array_values(array_filter(
            $candidatos,
            function ($candidato) use ($municipioId, $claveMunicipio) {
                if ((int)($candidato['municipio_id'] ?? 0) === (int)$municipioId) {
                    return true;
                }

                $claveArea = trim((string)($candidato['clave_area'] ?? ''));

                return strlen($claveArea) >= 5 &&
                    substr($claveArea, -3) === str_pad((string)$claveMunicipio, 3, '0', STR_PAD_LEFT);
            }
        ));
    }

    private function agregarEstadoSeguimientoCandidatos($modelo, $estadoId, $candidatos)
    {
        foreach ($candidatos as $indice => $candidato) {
            $seguimiento = $modelo->obtenerSeguimientoPorClaveOrigen(
                $estadoId,
                $candidato['clave_origen'] ?? ''
            );

            $candidatos[$indice]['seguimiento_existente'] = $seguimiento !== null;
            $candidatos[$indice]['seguimiento_activo'] =
                $seguimiento && (int)$seguimiento['activo'] === 1;
            $candidatos[$indice]['seguimiento_id'] = $seguimiento
                ? (int)$seguimiento['id']
                : 0;
            $candidatos[$indice]['seguimiento_url'] =
                $seguimiento && (int)$seguimiento['activo'] === 1
                    ? BASE_URL .
                        'index.php?controller=seguimientoVinculacion&action=detalle&id=' .
                        (int)$seguimiento['id']
                    : '';
        }

        return $candidatos;
    }

    private function resolverAnalistaResponsable($modelo, $usuarioId, $estadoId, $modo)
    {
        if ($modo === 'analista') {
            return (int)$usuarioId;
        }

        $analistaId = (int)($_POST['analista_id'] ?? 0);

        if ($analistaId <= 0) {
            return 0;
        }

        if (
            $modo === 'administrador' &&
            $modelo->analistaValidoParaAdministradorEstado($estadoId, $analistaId)
        ) {
            return $analistaId;
        }

        if (
            $modo === 'supervisor' &&
            $modelo->analistaValidoParaCuentaClaveEstado($usuarioId, $estadoId, $analistaId)
        ) {
            return $analistaId;
        }

        return 0;
    }

    private function resolverCandidatoParaCrear($modelo, $estado, $origen, $claveOrigen)
    {
        $estadoId = (int)($estado['id'] ?? 0);
        $partes = explode(':', $claveOrigen, 2);

        if (count($partes) !== 2 || $partes[0] !== $origen || trim($partes[1]) === '') {
            return null;
        }

        if ($origen === 'SECRETARIA') {
            return ctype_digit($partes[1])
                ? $modelo->obtenerSecretariaActivaPorId($estadoId, (int)$partes[1])
                : null;
        }

        if ($origen === 'MUNICIPIO') {
            return ctype_digit($partes[1])
                ? $modelo->obtenerMunicipioActivoPorId($estadoId, (int)$partes[1])
                : null;
        }

        if ($origen !== 'DENUE' || !ctype_digit($partes[1])) {
            return null;
        }

        $servicioDenue = new DenueService();
        $resultado = $servicioDenue->obtenerEstablecimientoPorId($partes[1]);

        if (!$resultado['ok']) {
            return null;
        }

        $candidato = $resultado['candidato'];

        if (!$this->candidatoDenuePerteneceEstado($candidato, $estado)) {
            return null;
        }

        $claveArea = trim((string)($candidato['clave_area'] ?? ''));

        if (strlen($claveArea) >= 5 && ctype_digit(substr($claveArea, 0, 5))) {
            $municipio = $modelo->obtenerMunicipioActivoPorClave(
                $estadoId,
                substr($claveArea, 2, 3)
            );

            if ($municipio) {
                $candidato['municipio_id'] = (int)$municipio['id'];
                $candidato['municipio_nombre'] = $municipio['nombre'];
            }
        }

        return $candidato;
    }

    private function candidatoDenuePerteneceEstado($candidato, $estado)
    {
        $claveArea = trim((string)($candidato['clave_area'] ?? ''));
        $claveEstado = str_pad((string)($estado['clave_inegi'] ?? ''), 2, '0', STR_PAD_LEFT);

        if (strlen($claveArea) >= 2 && substr($claveArea, 0, 2) === $claveEstado) {
            return true;
        }

        $ubicacion = $this->normalizarTexto((string)($candidato['ubicacion'] ?? ''));
        $entidad = $this->normalizarTexto((string)($candidato['entidad_nombre'] ?? ''));
        $nombreEstado = $this->normalizarTexto((string)($estado['nombre'] ?? ''));

        return (
                $ubicacion !== '' &&
                $nombreEstado !== '' &&
                strpos($ubicacion, $nombreEstado) !== false
            ) ||
            (
                $entidad !== '' &&
                $nombreEstado !== '' &&
                strpos($entidad, $nombreEstado) !== false
            );
    }

    private function normalizarTexto($valor)
    {
        $valor = strtolower(trim((string)$valor));
        $normalizado = iconv('UTF-8', 'ASCII//TRANSLIT', $valor);

        return $normalizado === false ? $valor : $normalizado;
    }

    private function obtenerTerritoriosPorModo($modelo, $usuarioId, $modo)
    {
        if ($modo === 'administrador') {
            return $modelo->obtenerEstadosAdministrador();
        }

        if ($modo === 'supervisor') {
            return $modelo->obtenerEstadosSupervisadosCuentaClave($usuarioId);
        }

        return $modelo->obtenerEstadosAsignadosAnalista($usuarioId);
    }

    private function obtenerEstadoPorModo($modelo, $usuarioId, $estadoId, $modo)
    {
        if ($modo === 'administrador') {
            return $modelo->obtenerEstadoAdministrador($estadoId);
        }

        if ($modo === 'supervisor') {
            return $modelo->obtenerEstadoSupervisadoCuentaClave($usuarioId, $estadoId);
        }

        return $modelo->obtenerEstadoAsignadoAnalista($usuarioId, $estadoId);
    }

    private function obtenerAnalistasFiltro($modelo, $usuarioId, $estadoId, $modo)
    {
        if ($modo === 'administrador') {
            return $modelo->obtenerAnalistasAdministradorEstado($estadoId);
        }

        if ($modo === 'supervisor') {
            return $modelo->obtenerAnalistasCuentaClaveEstado($usuarioId, $estadoId);
        }

        return [];
    }

    private function obtenerResumenPorModo($modelo, $usuarioId, $estadoId, $modo, $filtros)
    {
        if ($modo === 'administrador') {
            return $modelo->obtenerResumenSeguimientosAdministradorEstado($estadoId, $filtros);
        }

        if ($modo === 'supervisor') {
            return $modelo->obtenerResumenSeguimientosSupervisorEstado(
                $usuarioId,
                $estadoId,
                $filtros
            );
        }

        return $modelo->obtenerResumenSeguimientosAnalistaEstado(
            $usuarioId,
            $estadoId,
            $filtros
        );
    }

    private function obtenerSeguimientosPorModo($modelo, $usuarioId, $estadoId, $modo, $filtros)
    {
        if ($modo === 'administrador') {
            return $modelo->obtenerSeguimientosAdministradorEstado($estadoId, $filtros);
        }

        if ($modo === 'supervisor') {
            return $modelo->obtenerSeguimientosSupervisorEstado(
                $usuarioId,
                $estadoId,
                $filtros
            );
        }

        return $modelo->obtenerSeguimientosAnalistaEstado($usuarioId, $estadoId, $filtros);
    }

    private function obtenerSeguimientoPorModo($modelo, $usuarioId, $seguimientoId, $modo)
    {
        if ($modo === 'administrador') {
            return $modelo->obtenerSeguimientoAdministrador($seguimientoId);
        }

        if ($modo === 'supervisor') {
            return $modelo->obtenerSeguimientoSupervisor($usuarioId, $seguimientoId);
        }

        return $modelo->obtenerSeguimientoAnalista($usuarioId, $seguimientoId);
    }

    private function puedeOperarSeguimiento($seguimiento, $usuarioId, $modo)
    {
        $esAnalistaResponsable = $modo === 'analista' &&
            (int)($seguimiento['analista_id'] ?? 0) === (int)$usuarioId;

        return $esAnalistaResponsable || tienePermiso('seguimientos_vinculacion.editar');
    }

    private function puedeReactivarSeguimiento($seguimiento, $usuarioId, $modo)
    {
        if (!$this->seguimientoEstaDescartado($seguimiento)) {
            return false;
        }

        $esAnalistaResponsable = $modo === 'analista' &&
            (int)($seguimiento['analista_id'] ?? 0) === (int)$usuarioId;

        $puedeSupervisar = $modo === 'supervisor' &&
            tienePermiso('seguimientos_vinculacion.supervisar');

        return $esAnalistaResponsable ||
            $puedeSupervisar ||
            tienePermiso('seguimientos_vinculacion.editar');
    }

    private function seguimientoEstaDescartado($seguimiento)
    {
        return (string)($seguimiento['estado_seguimiento'] ?? '') === 'DESCARTADO';
    }

    private function serializarSeguimientoTrabajoConPermisos($seguimiento, $usuarioId, $modo)
    {
        $seguimientoSerializado = $this->serializarSeguimientoTrabajo($seguimiento);
        $seguimientoSerializado['puede_reactivar'] = $this->puedeReactivarSeguimiento(
            $seguimiento,
            $usuarioId,
            $modo
        );

        return $seguimientoSerializado;
    }

    private function serializarSeguimientoTrabajo($seguimiento)
    {
        $estado = (string)($seguimiento['estado_seguimiento'] ?? '');
        $proximaAccion = trim((string)($seguimiento['proxima_accion_at'] ?? ''));
        $proximaAccionTexto = trim((string)($seguimiento['proxima_accion_texto'] ?? ''));
        $seguimientoDescartado = $estado === 'DESCARTADO';

        if ($seguimientoDescartado) {
            $proximaAccion = '';
            $proximaAccionTexto = '';
        }

        return [
            'id' => (int)($seguimiento['id'] ?? 0),
            'estado_id' => (int)($seguimiento['estado_id'] ?? 0),
            'nombre_entidad' => (string)($seguimiento['nombre_entidad'] ?? ''),
            'tipo_entidad' => (string)($seguimiento['tipo_entidad'] ?? ''),
            'tipo_entidad_label' => $this->etiquetarTipoEntidad((string)($seguimiento['tipo_entidad'] ?? '')),
            'municipio' => (string)($seguimiento['municipio'] ?? ''),
            'estado_seguimiento' => $estado,
            'estado_label' => $this->etiquetarEstadoSeguimiento($estado),
            'accion_principal' => $this->resolverAccionPrincipalTrabajo($estado),
            'proxima_accion_texto' => $proximaAccionTexto,
            'proxima_accion_at' => $proximaAccion,
            'proxima_accion_fecha_label' => $this->formatearFechaTrabajo($proximaAccion),
            'proxima_accion_label' => $seguimientoDescartado
                ? '—'
                : ($proximaAccionTexto !== ''
                ? $proximaAccionTexto
                : ($proximaAccion !== ''
                    ? $this->formatearFechaTrabajo($proximaAccion)
                    : $this->resolverAccionPrincipalTrabajo($estado))),
            'ultima_interaccion_at' => (string)($seguimiento['ultima_interaccion_at'] ?? ''),
            'ultima_interaccion_label' => $this->formatearFechaTrabajo($seguimiento['ultima_interaccion_at'] ?? ''),
            'origen' => (string)($seguimiento['origen'] ?? ''),
            'folio' => (string)($seguimiento['folio'] ?? ''),
            'actividad_giro' => (string)($seguimiento['actividad_giro'] ?? ''),
            'direccion_fuente' => (string)($seguimiento['direccion_fuente'] ?? ''),
            'telefono_fuente' => (string)($seguimiento['telefono_fuente'] ?? ''),
            'correo_fuente' => (string)($seguimiento['correo_fuente'] ?? ''),
            'sitio_web_fuente' => (string)($seguimiento['sitio_web_fuente'] ?? ''),
            'telefono_verificado' => (string)($seguimiento['telefono_verificado'] ?? ''),
            'whatsapp_verificado' => (string)($seguimiento['whatsapp_verificado'] ?? ''),
            'correo_verificado' => (string)($seguimiento['correo_verificado'] ?? ''),
            'contacto_nombre' => (string)($seguimiento['contacto_nombre'] ?? ''),
            'contacto_cargo' => (string)($seguimiento['contacto_cargo'] ?? ''),
            'datos_verificados' => (int)($seguimiento['datos_verificados'] ?? 0),
            'datos_verificados_label' => (int)($seguimiento['datos_verificados'] ?? 0) === 1
                ? 'Datos verificados'
                : 'Datos sin verificar',
            'motivo_descarte' => (string)($seguimiento['motivo_descarte'] ?? ''),
            'fecha_descarte_label' => $seguimientoDescartado
                ? $this->formatearFechaTrabajo($seguimiento['ultima_interaccion_at'] ?? '')
                : '—',
            'observaciones' => (string)($seguimiento['observaciones'] ?? ''),
            'analista_nombre' => trim(
                (string)($seguimiento['analista_nombre'] ?? '') . ' ' .
                (string)($seguimiento['analista_apellidos'] ?? '')
            )
        ];
    }

    private function serializarInteraccionesTrabajo($interacciones)
    {
        return array_map(function ($interaccion) {
            $resultado = (string)($interaccion['resultado'] ?? '');
            $notas = (string)($interaccion['notas'] ?? '');

            return [
                'id' => (int)($interaccion['id'] ?? 0),
                'canal' => (string)($interaccion['canal'] ?? ''),
                'canal_label' => $this->etiquetarCanal((string)($interaccion['canal'] ?? '')),
                'resultado' => $resultado,
                'resultado_label' => $this->etiquetarResultadoInteraccion($resultado, $notas),
                'fecha_inicio' => (string)($interaccion['fecha_inicio'] ?? ''),
                'fecha_label' => $this->formatearFechaTrabajo($interaccion['fecha_inicio'] ?? ''),
                'notas' => $notas
            ];
        }, $interacciones);
    }

    private function serializarObservacionesTrabajo($observaciones)
    {
        return array_map(function ($observacion) {
            return [
                'id' => (int)($observacion['id'] ?? 0),
                'autor' => trim(
                    (string)($observacion['nombre'] ?? '') . ' ' .
                    (string)($observacion['apellidos'] ?? '')
                ),
                'fecha_label' => $this->formatearFechaTrabajo($observacion['created_at'] ?? ''),
                'observacion' => (string)($observacion['observacion'] ?? '')
            ];
        }, $observaciones);
    }

    private function normalizarFechaFormulario($valor, $permitirVacio = false)
    {
        $valor = trim((string)$valor);

        if ($valor === '') {
            return $permitirVacio ? null : '';
        }

        $fecha = DateTime::createFromFormat('Y-m-d\TH:i', $valor);

        if (!$fecha) {
            return $permitirVacio ? null : '';
        }

        return $fecha->format('Y-m-d H:i:s');
    }

    private function formatearFechaTrabajo($fecha)
    {
        $fecha = trim((string)$fecha);

        if ($fecha === '') {
            return '—';
        }

        try {
            $meses = [
                'Jan' => 'ene',
                'Feb' => 'feb',
                'Mar' => 'mar',
                'Apr' => 'abr',
                'May' => 'may',
                'Jun' => 'jun',
                'Jul' => 'jul',
                'Aug' => 'ago',
                'Sep' => 'sep',
                'Oct' => 'oct',
                'Nov' => 'nov',
                'Dec' => 'dic'
            ];

            return strtr((new DateTime($fecha))->format('d M Y · H:i'), $meses);
        } catch (Exception $error) {
            return '—';
        }
    }

    private function etiquetarEstadoSeguimiento($estado)
    {
        $etiquetas = [
            'NUEVO' => 'Nuevo',
            'CONTACTANDO' => 'Contactando',
            'DATOS_VERIFICADOS' => 'Datos verificados',
            'NO_LOCALIZADO' => 'No localizado',
            'DESCARTADO' => 'Descartado',
            'OFICIO_PREPARADO' => 'Oficio preparado',
            'ESPERANDO_RESPUESTA' => 'Esperando respuesta'
        ];

        return $etiquetas[$estado] ?? 'Sin estado';
    }

    private function etiquetarTipoEntidad($tipo)
    {
        $etiquetas = [
            'EMPRESA' => 'Empresa',
            'ORGANIZACION' => 'Organización',
            'INSTITUCION' => 'Institución',
            'SECRETARIA' => 'Secretaría',
            'MUNICIPIO' => 'Municipio',
            'OTRO' => 'Otro'
        ];

        return $etiquetas[$tipo] ?? '—';
    }

    private function etiquetarCanal($canal)
    {
        $etiquetas = [
            'LLAMADA_IP' => 'Llamada',
            'WHATSAPP' => 'WhatsApp',
            'CORREO' => 'Correo',
            'NOTA' => 'Otro',
            'SISTEMA' => 'Sistema'
        ];

        return $etiquetas[$canal] ?? '—';
    }

    private function etiquetarResultado($resultado)
    {
        $etiquetas = [
            'CONTACTADO' => 'Contacto correcto',
            'NO_CONTESTO' => 'No contestó',
            'OCUPADO' => 'Contacto incorrecto',
            'NUMERO_INCORRECTO' => 'Número incorrecto',
            'SOLICITO_LLAMAR_DESPUES' => 'Solicitó volver a llamar',
            'MENSAJE_ENVIADO' => 'Solicitó información',
            'CORREO_ENVIADO' => 'Correo enviado',
            'SIN_RESPUESTA' => 'Sin respuesta',
            'OTRO' => 'Otro'
        ];

        return $etiquetas[$resultado] ?? '—';
    }

    private function etiquetarResultadoInteraccion($resultado, $notas)
    {
        if ($resultado === 'OTRO') {
            if (strpos($notas, 'Seguimiento reactivado') !== false) {
                return 'Seguimiento reactivado';
            }

            if (
                strpos($notas, 'Resultado registrado: No interesado') !== false ||
                strpos($notas, 'Motivo de descarte:') !== false
            ) {
                return 'No interesado';
            }
        }

        return $this->etiquetarResultado($resultado);
    }

    private function resolverAccionPrincipalTrabajo($estado)
    {
        $acciones = [
            'NUEVO' => 'Completar investigación',
            'CONTACTANDO' => 'Continuar contacto / verificar datos',
            'DATOS_VERIFICADOS' => 'Preparar oficio',
            'OFICIO_PREPARADO' => 'Enviar correo',
            'ESPERANDO_RESPUESTA' => 'Registrar respuesta / seguimiento'
        ];

        return $acciones[$estado] ?? 'Revisar seguimiento';
    }

    private function obtenerResumenTrabajoActualizado($modelo, $usuarioId, $seguimiento, $modo)
    {
        return $this->obtenerResumenPorModo(
            $modelo,
            $usuarioId,
            (int)($seguimiento['estado_id'] ?? 0),
            $modo,
            []
        );
    }

    private function obtenerFiltrosSeguimiento()
    {
        $estadoSeguimiento = trim((string)($_GET['estado_seguimiento'] ?? ''));
        $estadosValidos = [
            'NUEVO',
            'CONTACTANDO',
            'DATOS_VERIFICADOS',
            'NO_LOCALIZADO',
            'DESCARTADO',
            'OFICIO_PREPARADO',
            'ESPERANDO_RESPUESTA'
        ];

        return [
            'analista_id' => ctype_digit((string)($_GET['analista_id'] ?? ''))
                ? (int)$_GET['analista_id']
                : 0,
            'estado_seguimiento' => in_array($estadoSeguimiento, $estadosValidos, true)
                ? $estadoSeguimiento
                : '',
            'buscar' => trim((string)($_GET['buscar'] ?? ''))
        ];
    }

    private function resolverModoSeguimiento()
    {
        if ((int)($_SESSION['rol_id'] ?? 0) === 1) {
            return 'administrador';
        }

        if (tienePermiso('seguimientos_vinculacion.supervisar')) {
            return 'supervisor';
        }

        return 'analista';
    }

    private function obtenerUsuarioActualId()
    {
        return (int)($_SESSION['usuario_id'] ?? 0);
    }

    private function validarPermiso($codigo)
    {
        if (!isset($_SESSION['usuario_id'])) {
            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );
            exit;
        }

        if (!tienePermiso($codigo)) {
            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=home&action=index'
            );
            exit;
        }
    }

    private function validarMetodoPost()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirigirASeguimiento();
        }
    }

    private function validarMetodoPostJson()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }
    }

    private function validarPermisoJson($codigo)
    {
        if (!isset($_SESSION['usuario_id'])) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La sesión no está activa.'
            ], 401);
        }

        if (!tienePermiso($codigo)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permiso para realizar esta acción.'
            ], 403);
        }
    }

    private function responderJson($datos, $codigoHttp = 200)
    {
        http_response_code($codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private function redirigirASeguimiento()
    {
        header(
            'Location: ' .
            BASE_URL .
            'index.php?controller=seguimientoVinculacion&action=index'
        );
        exit;
    }

    private function redirigirADetalle($seguimientoId)
    {
        header(
            'Location: ' .
            BASE_URL .
            'index.php?controller=seguimientoVinculacion&action=detalle&id=' .
            (int)$seguimientoId
        );
        exit;
    }
}
