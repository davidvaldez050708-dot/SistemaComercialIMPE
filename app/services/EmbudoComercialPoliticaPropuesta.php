<?php
/**
 * CONTRATO PROPUESTO para la evolución comercial (NO conectado a producción).
 *
 * Funciones puras: no consulta BD, no acredita llamadas, no guarda resultados,
 * no cambia el teléfono ni las etapas actuales de Hostinger.
 *
 * Los parámetros de evidencia y contadores DEBERÁN proceder de un servicio
 * de servidor que valide propiedad, número completo y llamadas PBX distintas;
 * nunca confiar en un valor recibido directamente del navegador.
 */
final class EmbudoComercialPoliticaPropuesta
{
    public const ETAPAS_ACTIVAS = [
        'NUEVO' => 'Nuevos',
        'CONTACTADO' => 'Contactados',
        'POTENCIAL' => 'Potenciales',
        'SEGUIMIENTO' => 'Seguimiento',
        'PROCESO_INSCRIPCION' => 'Proceso Inscripción'
    ];
    public const BANDEJAS = [
        'RECUPERACION' => 'Recuperación',
        'DESCARTADO' => 'Descartados'
    ];
    public const SUBETAPAS = [
        'NUEVO' => [
            'SIN_GESTION' => 'Pendiente de primera llamada',
            'INTENTO_1' => 'Primer intento sin contacto',
            'INTENTO_2' => 'Segundo intento sin contacto'
        ],
        'CONTACTADO' => [
            'PRIMER_CONTACTO' => 'Primer contacto realizado',
            'INFORMACION_COMPARTIDA' => 'Información compartida',
            'PENDIENTE_CALIFICACION' => 'Pendiente de calificación',
            'EVALUANDO_OPCIONES' => 'Evaluando opciones'
        ],
        'POTENCIAL' => [
            'CALIFICADO' => 'Intención de inscripción confirmada',
            'REUNIENDO_DOCUMENTOS' => 'Reuniendo documentos',
            'DOCUMENTACION_LISTA' => 'Documentación lista',
            'PRESUPUESTO_PENDIENTE' => 'Presupuesto por resolver',
            'LISTO_PARA_TRAMITE' => 'Listo para iniciar inscripción'
        ],
        'SEGUIMIENTO' => [
            'PENDIENTE_DECISION' => 'Pendiente de decisión',
            'POR_PRESUPUESTO' => 'Alternativa de presupuesto',
            'SIGUIENTE_APERTURA' => 'Próxima apertura',
            'CONTACTO_ACORDADO' => 'Contacto acordado',
            'ESPERANDO_RESPUESTA' => 'Esperando respuesta'
        ],
        'PROCESO_INSCRIPCION' => [
            'SOLICITUD_INICIADA' => 'Solicitud iniciada',
            'DOCUMENTACION_INCOMPLETA' => 'Documentación incompleta',
            'DOCUMENTOS_EN_REVISION' => 'Documentos en revisión',
            'PAGO_PENDIENTE' => 'Pendiente de pago',
            'PAGO_VERIFICADO' => 'Pago verificado',
            'VALIDACION_INSCRIPCION' => 'Inscripción en validación'
        ],
        'RECUPERACION' => [
            'PENDIENTE_REVISION' => 'Pendiente de revisión',
            'ELEGIBLE_REMARKETING' => 'Elegible para remarketing',
            'EN_REMARKETING' => 'En campaña de reactivación',
            'RECONTACTO_AUTORIZADO' => 'Recontacto autorizado'
        ],
        'DESCARTADO' => [
            'NO_INTERESADO' => 'No interesado',
            'NUMERO_INVALIDO' => 'Número inválido confirmado',
            'NO_CUMPLE_REQUISITOS' => 'No cumple requisitos',
            'RECHAZO_DEFINITIVO' => 'Rechazo definitivo',
            'NO_CONTACTAR' => 'Solicitó no volver a contactar'
        ]
    ];

    private const SIN_CONTACTO = [
        'SIN_RESPUESTA', 'BUZON_VOZ', 'OCUPADO',
        'MENSAJE_AUTOMATICO', 'OTRO_SIN_CONTACTO'
    ];
    private const RESULTADOS = [
        'SIN_RESPUESTA', 'BUZON_VOZ', 'OCUPADO',
        'MENSAJE_AUTOMATICO', 'OTRO_SIN_CONTACTO',
        'NUMERO_INEXISTENTE', 'NO_INTERESADO',
        'CONVERSACION_PERSONA', 'PROSPECTO_POTENCIAL',
        'REQUIERE_SEGUIMIENTO', 'FUERA_PRESUPUESTO',
        'INSCRITO', 'NO_CUMPLE_REQUISITOS',
        'NO_SOLICITO_INFORMACION', 'SIGUIENTE_APERTURA',
        'COMUNICACION_INTERRUMPIDA',
        'WHATSAPP', 'SMS', 'ENVIO_WHATSAPP',
        'ENVIO_SMS', 'ENVIO_WHATSAPP_SMS'
    ];

    public static function subetapaValida(string $etapa, ?string $subetapa): bool
    {
        return $subetapa !== null && isset(self::SUBETAPAS[$etapa][$subetapa]);
    }

    private static function salida(
        string $actual, string $destino, ?string $subetapa,
        string $accion, bool $revision=false
    ): array {
        if (!self::subetapaValida($destino, $subetapa)) {
            $subetapa = null;
        }
        return [
            'etapa_anterior' => $actual,
            'etapa_propuesta' => $destino,
            'subetapa_propuesta' => $subetapa,
            'mover' => $actual !== $destino,
            'accion' => $accion,
            'requiere_revision' => $revision
        ];
    }

    private static function conservar(
        string $etapa, ?string $subetapa, string $accion, bool $revision=false
    ): array {
        return self::salida($etapa, $etapa, $subetapa, $accion, $revision);
    }

    /**
     * Evalúa una llamada ya atribuida y guardada, sin producir escrituras.
     *
     * $intentosConfirmadosHoy es el TOTAL de llamadas distintas sin contacto
     * de la misma jornada local, INCLUYENDO la llamada recién registrada,
     * obtenido de la BD de telefonía/embudo tras su commit.
     *
     * Contexto: contactoAntesCorte ('SI'|'NO'), contactoPersona ('SI'|'NO'),
     * calificacionConfirmada, fechaAccionVerificada, pendienteDecision,
     * aperturaConfirmada, aceptaSeguimiento, rechazoDefinitivo,
     * numeroInvalidoConfirmado. Cada evidencia positiva deberá ser validada
     * por el servidor; una casilla en el navegador nunca basta.
     */
    public static function evaluarLlamada(
        string $etapa, string $resultado, int $intentosConfirmadosHoy,
        array $contexto=[]
    ): array {
        $catalogo=self::ETAPAS_ACTIVAS + self::BANDEJAS;
        if (!isset($catalogo[$etapa])) {
            // Prohibido reinterpretar automáticamente datos legacy como
            // INTERESADO o PAGO_CONFIRMADO: primero conciliar su significado.
            throw new DomainException('Etapa fuera del nuevo catálogo: requiere conciliación.');
        }
        if (!in_array($resultado, self::RESULTADOS, true)) {
            throw new DomainException('Resultado telefónico no contemplado en la matriz.');
        }
        if ($intentosConfirmadosHoy < 0) {
            throw new InvalidArgumentException('Contador comercial inválido.');
        }
        $subetapa = isset($contexto['subetapa']) && is_string($contexto['subetapa'])
            && self::subetapaValida($etapa, $contexto['subetapa'])
            ? $contexto['subetapa'] : null;

        // Descartado y trámite en curso no pueden ser reactivados/degradados
        // automáticamente por una llamada ordinaria.
        if ($etapa === 'DESCARTADO') {
            return self::conservar($etapa, $subetapa, 'REVISAR_REACTIVACION_AUTORIZADA', true);
        }
        if ($resultado === 'INSCRITO') {
            return self::conservar($etapa, $subetapa, 'VALIDAR_INSCRIPCION_INSTITUCIONAL', true);
        }
        if ($resultado === 'NUMERO_INEXISTENTE') {
            if (!empty($contexto['numeroInvalidoConfirmado']) && $etapa !== 'PROCESO_INSCRIPCION') {
                return self::salida($etapa, 'DESCARTADO', 'NUMERO_INVALIDO', 'EXCLUIR_NUMERO_INVALIDO');
            }
            return self::conservar($etapa, $subetapa, 'VERIFICAR_OTRO_NUMERO', true);
        }

        if (in_array($resultado, ['WHATSAPP', 'SMS', 'ENVIO_WHATSAPP',
                                   'ENVIO_SMS', 'ENVIO_WHATSAPP_SMS'], true)) {
            $hablo = $contexto['contactoPersona'] ?? null;
            if ($hablo === 'SI') {
                $resultado = 'CONVERSACION_PERSONA';
            } elseif ($hablo === 'NO') {
                $resultado = 'SIN_RESPUESTA';
            } else {
                return self::conservar($etapa, $subetapa, 'PREGUNTAR_SI_HUBO_CONVERSACION', true);
            }
        }
        if ($resultado === 'COMUNICACION_INTERRUMPIDA') {
            $hablo = $contexto['contactoAntesCorte'] ?? null;
            if ($hablo === 'SI') {
                $resultado = 'CONVERSACION_PERSONA';
            } elseif ($hablo === 'NO') {
                $resultado = 'SIN_RESPUESTA';
            } else {
                return self::conservar($etapa, $subetapa, 'ACLARAR_CONTACTO_ANTES_CORTE', true);
            }
        }

        if (in_array($resultado, self::SIN_CONTACTO, true)) {
            if ($etapa === 'RECUPERACION') {
                return self::conservar($etapa, $subetapa, 'MANTENER_RECUPERACION');
            }
            if ($intentosConfirmadosHoy >= 3) {
                if ($etapa === 'NUEVO') {
                    return self::salida(
                        $etapa, 'RECUPERACION', 'PENDIENTE_REVISION',
                        'REVISAR_REMARKETING_DIA_SIGUIENTE'
                    );
                }
                return self::conservar(
                    $etapa, $subetapa, 'REVISAR_TRES_INTENTOS_SIN_DEGRADAR', true
                );
            }
            if ($etapa === 'NUEVO') {
                return self::conservar(
                    $etapa, 'INTENTO_'.max(1, $intentosConfirmadosHoy),
                    'PROGRAMAR_SIGUIENTE_INTENTO'
                );
            }
            return self::conservar($etapa, $subetapa, 'PROGRAMAR_INTENTO_SIN_DEGRADAR');
        }

        if ($resultado === 'CONVERSACION_PERSONA') {
            if (in_array($etapa, ['NUEVO', 'RECUPERACION'], true)) {
                return self::salida($etapa, 'CONTACTADO', 'PRIMER_CONTACTO', 'CALIFICAR_CONTACTO');
            }
            return self::conservar($etapa, $subetapa, 'ACTUALIZAR_GESTION_SIN_RETROCEDER');
        }
        if ($resultado === 'PROSPECTO_POTENCIAL') {
            if ($etapa === 'PROCESO_INSCRIPCION') {
                return self::conservar($etapa, $subetapa, 'CONSERVAR_TRAMITE_INICIADO');
            }
            if (!empty($contexto['calificacionConfirmada'])) {
                return self::salida($etapa, 'POTENCIAL', 'CALIFICADO', 'REVISAR_PROXIMOS_REQUISITOS');
            }
            if (in_array($etapa, ['NUEVO','RECUPERACION'], true)) {
                return self::salida(
                    $etapa, 'CONTACTADO', 'PENDIENTE_CALIFICACION',
                    'COMPLETAR_CALIFICACION_POTENCIAL', true
                );
            }
            return self::conservar($etapa, $subetapa, 'COMPLETAR_CALIFICACION_POTENCIAL', true);
        }
        if ($resultado === 'REQUIERE_SEGUIMIENTO') {
            if (empty($contexto['fechaAccionVerificada'])) {
                return self::conservar($etapa, $subetapa, 'PROGRAMAR_FECHA_FUTURA', true);
            }
            // Una próxima llamada es una TAREA transversal, no necesariamente
            // un retroceso de Potenciales o Proceso Inscripción.
            if (!empty($contexto['pendienteDecision']) &&
                in_array($etapa, ['NUEVO','CONTACTADO','RECUPERACION'], true)) {
                return self::salida(
                    $etapa, 'SEGUIMIENTO', 'CONTACTO_ACORDADO', 'CREAR_TAREA_SEGUIMIENTO'
                );
            }
            return self::conservar($etapa, $subetapa, 'CREAR_TAREA_SEGUIMIENTO');
        }
        if ($resultado === 'FUERA_PRESUPUESTO') {
            if (!empty($contexto['rechazoDefinitivo']) && $etapa !== 'PROCESO_INSCRIPCION') {
                return self::salida($etapa, 'DESCARTADO', 'RECHAZO_DEFINITIVO', 'CERRAR_RECHAZO');
            }
            if (!empty($contexto['aceptaSeguimiento']) && !empty($contexto['fechaAccionVerificada'])) {
                if ($etapa === 'POTENCIAL') {
                    return self::conservar(
                        $etapa, 'PRESUPUESTO_PENDIENTE', 'CREAR_TAREA_PRESUPUESTO'
                    );
                }
                if ($etapa !== 'PROCESO_INSCRIPCION') {
                    return self::salida($etapa, 'SEGUIMIENTO', 'POR_PRESUPUESTO', 'CREAR_TAREA_PRESUPUESTO');
                }
            }
            return self::conservar($etapa, $subetapa, 'REVISAR_ALTERNATIVA_PRESUPUESTO', true);
        }
        if ($resultado === 'SIGUIENTE_APERTURA') {
            if ($etapa === 'PROCESO_INSCRIPCION') {
                return self::conservar($etapa, $subetapa, 'REVISAR_APERTURA_Y_TRAMITE', true);
            }
            if (!empty($contexto['aperturaConfirmada']) && !empty($contexto['fechaAccionVerificada'])) {
                return self::salida($etapa, 'SEGUIMIENTO', 'SIGUIENTE_APERTURA', 'PROGRAMAR_APERTURA_REAL');
            }
            return self::conservar($etapa, $subetapa, 'CONFIRMAR_APERTURA_Y_FECHA', true);
        }
        if ($resultado === 'NO_INTERESADO') {
            if ($etapa === 'PROCESO_INSCRIPCION') {
                return self::conservar($etapa, $subetapa, 'REVISAR_DESISTIMIENTO_TRAMITE', true);
            }
            return self::salida($etapa, 'DESCARTADO', 'NO_INTERESADO', 'CERRAR_RECHAZO_EXPLICITO');
        }
        if ($resultado === 'NO_CUMPLE_REQUISITOS') {
            return self::conservar($etapa, $subetapa, 'REVISAR_REQUISITOS_Y_ALTERNATIVAS', true);
        }
        if ($resultado === 'NO_SOLICITO_INFORMACION') {
            return self::conservar($etapa, $subetapa, 'ACLARAR_ORIGEN_Y_CONSENTIMIENTO', true);
        }
        return self::conservar($etapa, $subetapa, 'CLASIFICACION_PENDIENTE', true);
    }

    /**
     * Sólo una fuente institucional acreditada puede iniciar el trámite
     * o confirmar la inscripción. INSCRITOS es una salida, no la sexta etapa.
     */
    public static function evaluarEventoInstitucional(
        string $etapa, string $evento, bool $evidenciaInstitucional
    ): array {
        $catalogo=self::ETAPAS_ACTIVAS + self::BANDEJAS;
        if (!isset($catalogo[$etapa])) {
            throw new DomainException('Etapa no disponible.');
        }
        if (!in_array($evento, ['TRAMITE_INICIADO', 'PAGO_VERIFICADO', 'INSCRIPCION_CONFIRMADA'], true)) {
            throw new DomainException('Evento institucional no reconocido.');
        }
        if (!$evidenciaInstitucional || $etapa === 'DESCARTADO') {
            return self::conservar($etapa, null, 'EXIGIR_EVIDENCIA_INSTITUCIONAL', true);
        }
        if ($evento === 'TRAMITE_INICIADO') {
            return self::salida($etapa, 'PROCESO_INSCRIPCION', 'SOLICITUD_INICIADA', 'CONSULTAR_TRAMITE');
        }
        if ($evento === 'PAGO_VERIFICADO') {
            if ($etapa !== 'PROCESO_INSCRIPCION') {
                return self::conservar($etapa, null, 'CONCILIAR_PAGO_CON_TRAMITE', true);
            }
            return self::conservar($etapa, 'PAGO_VERIFICADO', 'VERIFICAR_INSCRIPCION');
        }
        if ($etapa !== 'PROCESO_INSCRIPCION') {
            return self::conservar($etapa, null, 'CONCILIAR_INSCRIPCION_CON_TRAMITE', true);
        }
        // El cierre real irá al módulo Inscritos; no inventar una etapa SQL.
        return self::conservar($etapa, 'VALIDACION_INSCRIPCION', 'CERRAR_CON_INSCRIPCION_REAL');
    }

    /**
     * Agregador de registros YA verificados por el backend, para una jornada.
     * La prueba aquí no verifica la PBX: un integrador futuro debe rechazar
     * entrada directa del navegador y enlazar cada ref a asesor/asignación.
     */
    public static function contarIntentosJornada(array $llamadas, string $diaLocal): int
    {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $diaLocal)) {
            throw new InvalidArgumentException('Día de jornada inválido.');
        }
        $vistos=[];
        foreach ($llamadas as $r) {
            if (!is_array($r) ||
                ($r['fecha_local'] ?? null) !== $diaLocal ||
                ($r['direccion'] ?? null) !== 'Saliente' ||
                empty($r['pbx_confirmada']) || empty($r['resultado_guardado']) ||
                empty($r['asignacion_verificada'])) {
                continue;
            }
            $ext=(string)($r['extension'] ?? '');
            $pbx=(string)($r['pbx_call_id'] ?? '');
            $resultado=(string)($r['resultado'] ?? '');
            if (!preg_match('/^[0-9]{3,6}$/D', $ext) ||
                !preg_match('/^[A-Za-z0-9_-]{6,100}$/D', $pbx)) {
                continue;
            }
            if ($resultado === 'COMUNICACION_INTERRUMPIDA' &&
                ($r['contacto_antes_corte'] ?? null) === 'NO') {
                $resultado='SIN_RESPUESTA';
            }
            if (!in_array($resultado, self::SIN_CONTACTO, true)) {
                continue;
            }
            $vistos[$ext.'|'.$pbx]=true;
        }
        return count($vistos);
    }
}
