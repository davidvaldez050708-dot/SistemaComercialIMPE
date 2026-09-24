<?php

class SeguimientoActividadPresentacionService
{
    public function presentar(array $interaccion)
    {
        $canal = strtoupper(trim((string)($interaccion['canal'] ?? '')));
        $resultado = strtoupper(trim((string)($interaccion['resultado'] ?? '')));
        $notas = trim((string)($interaccion['notas'] ?? ''));

        $presentacion = [
            'titulo' => $this->etiquetaCanal($canal),
            'tipo_visual' => $this->tipoVisualPorCanal($canal),
            'resultado_label' => $this->etiquetaResultado($resultado),
            'resumen' => '',
            'detalles' => [],
            'detalle_texto' => ''
        ];

        if ($notas !== '') {
            $presentacion = $this->presentarNotas(
                $notas,
                $canal,
                $resultado,
                $presentacion
            );
        }

        $presentacion = $this->completarDesdeCampos(
            $interaccion,
            $presentacion
        );

        if ($presentacion['resumen'] === '') {
            $presentacion['resumen'] = $this->resumenGenerico(
                $presentacion,
                $notas
            );
        }

        $presentacion['resumen'] = $this->limitar(
            $presentacion['resumen'],
            150
        );

        $presentacion['detalle_texto'] = $this->detallesComoTexto(
            $presentacion['detalles']
        );

        if (
            $presentacion['detalle_texto'] === '' &&
            $notas !== '' &&
            !$this->esNotaTecnicaCorreo($notas)
        ) {
            $presentacion['detalle_texto'] = $this->limpiarMarcadores(
                $notas
            );
        }

        return $presentacion;
    }

    private function presentarNotas(
        $notas,
        $canal,
        $resultado,
        array $presentacion
    ) {
        if (preg_match(
            '/^Nueva revisión programada para\s+(.+?)\.\s*Pendiente:\s*(.+?)\.\s*Acción prevista:\s*(.+?)\.\s*Pendiente de:\s*(.+?)\.?$/ui',
            $notas,
            $m
        )) {
            $presentacion['titulo'] = 'Seguimiento programado';
            $presentacion['tipo_visual'] = 'seguimiento';
            $presentacion['resultado_label'] = 'Seguimiento programado';
            $presentacion['resumen'] =
                trim($m[3]) . ' · ' . trim($m[4]);
            $presentacion['detalles'] = [
                $this->detalle('Pendiente', $m[2]),
                $this->detalle('Acción prevista', $m[3]),
                $this->detalle('Pendiente de', $m[4]),
                $this->detalle('Próxima revisión', $m[1])
            ];
            return $presentacion;
        }

        if (preg_match(
            '/^Seguimiento de acuerdos programado para\s+(.+?)(?:\.\s*Pendiente:\s*(.+?))?(?:\.\s*Acción prevista:\s*(.+?))?(?:\.\s*Pendiente de:\s*(.+?))?\.?$/ui',
            $notas,
            $m
        )) {
            $accion = trim((string)($m[3] ?? ''));
            $pendienteDe = trim((string)($m[4] ?? ''));
            $presentacion['titulo'] = 'Seguimiento de acuerdos programado';
            $presentacion['tipo_visual'] = 'seguimiento';
            $presentacion['resultado_label'] = 'Seguimiento programado';
            $partes = [];
            if ($accion !== '') {
                $partes[] = $accion;
            }
            if ($pendienteDe !== '') {
                $partes[] = $pendienteDe;
            }
            $presentacion['resumen'] = !empty($partes)
                ? implode(' · ', $partes)
                : 'Revisión de acuerdos pendiente';
            $presentacion['detalles'] = array_values(array_filter([
                $this->detalle('Pendiente', $m[2] ?? ''),
                $this->detalle('Acción prevista', $accion),
                $this->detalle('Pendiente de', $pendienteDe),
                $this->detalle('Próxima revisión', $m[1])
            ]));
            return $presentacion;
        }

        if (preg_match(
            '/^Seguimiento posterior a reunión\s*\[([^\]]+)\]\s*:\s*(.*)$/ui',
            $notas,
            $m
        )) {
            $codigo = strtoupper(trim($m[1]));
            $resto = trim($m[2]);
            $pendiente = '';
            $resultadoTexto = $resto;

            if (preg_match(
                '/^Pendiente atendido:\s*(.+?)\.\s*Resultado:\s*(.*)$/ui',
                $resto,
                $partes
            )) {
                $pendiente = trim($partes[1]);
                $resultadoTexto = trim($partes[2]);
            }

            $etiqueta = $this->etiquetaResultadoReunion($codigo);
            $presentacion['titulo'] = 'Seguimiento de acuerdos';
            $presentacion['tipo_visual'] = 'seguimiento';
            $presentacion['resultado_label'] = $etiqueta;
            $presentacion['resumen'] = $etiqueta !== ''
                ? $etiqueta
                : 'Seguimiento registrado';
            $presentacion['detalles'] = array_values(array_filter([
                $this->detalle('Pendiente atendido', $pendiente),
                $this->detalle('Resultado', $resultadoTexto)
            ]));
            return $presentacion;
        }

        if (preg_match(
            '/^Reunión realizada\s*[·:-]\s*(.+)$/ui',
            $notas,
            $m
        )) {
            $detalle = trim($m[1]);
            $presentacion['titulo'] = 'Reunión realizada';
            $presentacion['tipo_visual'] = 'reunion';
            $presentacion['resultado_label'] = $this->resultadoReunionDesdeTexto(
                $detalle
            );
            $presentacion['resumen'] =
                'Reunión realizada' .
                ($presentacion['resultado_label'] !== ''
                    ? ' · ' . $presentacion['resultado_label']
                    : '');
            $presentacion['detalles'] = [
                $this->detalle('Resultado y acuerdos', $detalle)
            ];
            return $presentacion;
        }

        if (preg_match(
            '/^Reunión realizada\s*\[([^\]]+)\]\s*:\s*(.*)$/ui',
            $notas,
            $m
        )) {
            $etiqueta = $this->etiquetaResultadoReunion(
                strtoupper(trim($m[1]))
            );
            $presentacion['titulo'] = 'Reunión realizada';
            $presentacion['tipo_visual'] = 'reunion';
            $presentacion['resultado_label'] = $etiqueta;
            $presentacion['resumen'] = $etiqueta !== ''
                ? $etiqueta
                : 'Resultado registrado';
            $presentacion['detalles'] = array_values(array_filter([
                $this->detalle('Resultado', $etiqueta),
                $this->detalle('Acuerdos', $m[2])
            ]));
            return $presentacion;
        }

        if (preg_match(
            '/^Reprogramación solicitada\.\s*Fecha anterior:\s*(.+?)\s*\|\s*Nueva fecha:\s*(.+?)\s*\|\s*Motivo:\s*(.+)$/ui',
            $notas,
            $m
        )) {
            $presentacion['titulo'] = 'Reprogramación solicitada';
            $presentacion['tipo_visual'] = 'reunion';
            $presentacion['resultado_label'] = 'Reprogramación';
            $presentacion['resumen'] = 'Nueva fecha: ' . trim($m[2]);
            $presentacion['detalles'] = [
                $this->detalle('Fecha anterior', $m[1]),
                $this->detalle('Nueva fecha', $m[2]),
                $this->detalle('Motivo', $m[3])
            ];
            return $presentacion;
        }

        if (preg_match(
            '/^Cuenta Clave solicitó reprogramar la reunión del\s+(.+?)\.\s*Motivo:\s*(.+)$/ui',
            $notas,
            $m
        )) {
            $presentacion['titulo'] = 'Reprogramación solicitada';
            $presentacion['tipo_visual'] = 'reunion';
            $presentacion['resultado_label'] = 'Cambio solicitado';
            $presentacion['resumen'] = 'Cuenta Clave solicitó una nueva fecha';
            $presentacion['detalles'] = [
                $this->detalle('Fecha anterior', $m[1]),
                $this->detalle('Motivo', $m[2])
            ];
            return $presentacion;
        }

        if (preg_match(
            '/^Cuenta Clave solicitó modificar la propuesta de reunión\.\s*Motivo:\s*(.+)$/ui',
            $notas,
            $m
        )) {
            $presentacion['titulo'] = 'Cambio de reunión solicitado';
            $presentacion['tipo_visual'] = 'reunion';
            $presentacion['resultado_label'] = 'Cambio solicitado';
            $presentacion['resumen'] = 'Cuenta Clave solicitó modificar la propuesta';
            $presentacion['detalles'] = [
                $this->detalle('Motivo', $m[1])
            ];
            return $presentacion;
        }

        if (preg_match(
            '/^Reunión cancelada\.\s*Motivo:\s*(.+)$/ui',
            $notas,
            $m
        )) {
            $presentacion['titulo'] = 'Reunión cancelada';
            $presentacion['tipo_visual'] = 'reunion';
            $presentacion['resultado_label'] = 'Cancelada';
            $presentacion['resumen'] = 'Motivo: ' . trim($m[1]);
            $presentacion['detalles'] = [
                $this->detalle('Motivo', $m[1])
            ];
            return $presentacion;
        }

        if (preg_match(
            '/^La fecha propuesta venció sin confirmación de Cuenta Clave\.\s*Nueva propuesta enviada para\s+(.+?)\.?$/ui',
            $notas,
            $m
        )) {
            $presentacion['titulo'] = 'Nueva propuesta de reunión';
            $presentacion['tipo_visual'] = 'reunion';
            $presentacion['resultado_label'] = 'Pendiente de confirmación';
            $presentacion['resumen'] = 'Nueva fecha: ' . trim($m[1]);
            $presentacion['detalles'] = [
                $this->detalle('Nueva fecha', $m[1]),
                $this->detalle(
                    'Motivo',
                    'La propuesta anterior venció sin confirmación'
                )
            ];
            return $presentacion;
        }

        if (preg_match(
            '/^Solicitud de reunión enviada a Cuenta Clave para\s+(.+?)\s*\(([^\)]+)\)\.?$/ui',
            $notas,
            $m
        )) {
            $presentacion['titulo'] = 'Solicitud de reunión enviada';
            $presentacion['tipo_visual'] = 'reunion';
            $presentacion['resultado_label'] = 'Pendiente de confirmación';
            $presentacion['resumen'] =
                'Fecha propuesta: ' . trim($m[1]) . ' · ' .
                $this->humanizarCodigo($m[2]);
            $presentacion['detalles'] = [
                $this->detalle('Fecha propuesta', $m[1]),
                $this->detalle('Modalidad', $this->humanizarCodigo($m[2]))
            ];
            return $presentacion;
        }

        if (preg_match(
            '/^Nueva propuesta de reunión enviada a Cuenta Clave para\s+(.+?)\.?$/ui',
            $notas,
            $m
        )) {
            $presentacion['titulo'] = 'Nueva propuesta de reunión enviada';
            $presentacion['tipo_visual'] = 'reunion';
            $presentacion['resultado_label'] = 'Pendiente de confirmación';
            $presentacion['resumen'] = 'Fecha propuesta: ' . trim($m[1]);
            $presentacion['detalles'] = [
                $this->detalle('Fecha propuesta', $m[1])
            ];
            return $presentacion;
        }

        if (preg_match(
            '/^Cuenta Clave confirmó la reunión para\s+(.+?)\.?$/ui',
            $notas,
            $m
        )) {
            $presentacion['titulo'] = 'Reunión confirmada';
            $presentacion['tipo_visual'] = 'reunion';
            $presentacion['resultado_label'] = 'Confirmada';
            $presentacion['resumen'] = 'Fecha y hora: ' . trim($m[1]);
            $presentacion['detalles'] = [
                $this->detalle('Fecha y hora', $m[1])
            ];
            return $presentacion;
        }

        if (preg_match(
            '/^Reunión agendada:\s*(.+?)\s*\|\s*([^|]+)\s*\|\s*([^|]+)(?:\s*\|\s*(.+))?$/ui',
            $notas,
            $m
        )) {
            $presentacion['titulo'] = 'Reunión agendada';
            $presentacion['tipo_visual'] = 'reunion';
            $presentacion['resultado_label'] = 'Agendada';
            $presentacion['resumen'] = 'Reunión agendada';
            $presentacion['detalles'] = array_values(array_filter([
                $this->detalle('Fecha y hora', $m[1]),
                $this->detalle('Modalidad', $this->humanizarCodigo($m[2])),
                $this->detalle('Acceso / lugar', $m[3]),
                $this->detalle('Notas', $m[4] ?? '')
            ]));
            return $presentacion;
        }

        if (preg_match('/^Documentación de convenio enviada(?:\R|$)/ui', $notas)) {
            $campos = $this->extraerLineasClaveValor($notas);
            $presentacion['titulo'] = 'Documentación de convenio enviada';
            $presentacion['tipo_visual'] = 'convenio';
            $presentacion['resultado_label'] = 'Documentación enviada';
            $presentacion['resumen'] = 'Carta propuesta y convenio editable enviados';
            $presentacion['detalles'] = array_values(array_filter([
                $this->detalle('Para', $campos['Para'] ?? ''),
                $this->detalle('Carta propuesta', $campos['Carta propuesta'] ?? ''),
                $this->detalle('Convenio editable', $campos['Convenio editable'] ?? ''),
                $this->detalle('Fecha de carta', $campos['Fecha de carta'] ?? ''),
                $this->detalle('Asunto', $campos['Asunto'] ?? '')
            ]));
            return $presentacion;
        }

        if (preg_match('/^Convenio (?:requisitado|corregido) recibido/ui', $notas)) {
            $esCorregido = stripos($notas, 'Convenio corregido recibido') === 0;
            $presentacion['titulo'] = $esCorregido
                ? 'Convenio corregido recibido'
                : 'Convenio requisitado recibido';
            $presentacion['tipo_visual'] = 'convenio';
            $presentacion['resultado_label'] = 'Documento recibido';
            $presentacion['resumen'] = $esCorregido
                ? 'Nueva versión del convenio recibida'
                : 'Convenio requisitado recibido';
            $presentacion['detalles'] = $this->extraerDetallesConvenioRecibido($notas);
            return $presentacion;
        }

        if (preg_match(
            '/^Convenio formalizado\s*\|\s*Fecha:\s*([^|]+)(?:\s*\|\s*(.+))?$/ui',
            $notas,
            $m
        )) {
            $presentacion['titulo'] = 'Convenio formalizado';
            $presentacion['tipo_visual'] = 'convenio';
            $presentacion['resultado_label'] = 'Formalizado';
            $presentacion['resumen'] = 'Convenio formalizado';
            $presentacion['detalles'] = array_values(array_filter([
                $this->detalle('Fecha', $m[1]),
                $this->detalle('Notas', $m[2] ?? '')
            ]));
            return $presentacion;
        }

        if (preg_match(
            '/^Respuesta recibida\s*\[([^\]]+)\]\s*:\s*(.*)$/ui',
            $notas,
            $m
        )) {
            $tipo = trim($m[1]);
            $detalle = trim($m[2]);
            $presentacion['titulo'] = 'Respuesta recibida';
            $presentacion['tipo_visual'] = 'correo';
            $presentacion['resultado_label'] = 'Respuesta recibida';
            $presentacion['resumen'] = $tipo !== ''
                ? $tipo
                : 'Respuesta registrada';
            $presentacion['detalles'] = array_values(array_filter([
                $this->detalle('Respuesta', $tipo),
                $this->detalle('Detalle', $detalle)
            ]));
            return $presentacion;
        }

        if (preg_match('/^Seguimiento por correo enviado/i', $notas)) {
            $campos = $this->extraerLineasClaveValor($notas);
            $adjuntos = trim((string)($campos['Adjuntos'] ?? ''));
            $totalAdjuntos = $this->contarAdjuntos($adjuntos);
            $presentacion['titulo'] = 'Correo de seguimiento enviado';
            $presentacion['tipo_visual'] = 'correo';
            $presentacion['resultado_label'] = 'Correo enviado';
            $resumenCorreo = trim((string)($campos['Asunto'] ?? ''));
            $presentacion['resumen'] = $resumenCorreo !== ''
                ? 'Asunto: ' . $resumenCorreo
                : 'Correo enviado';

            if ($totalAdjuntos > 0) {
                $presentacion['resumen'] .= ' · ' .
                    $totalAdjuntos .
                    ($totalAdjuntos === 1 ? ' adjunto' : ' adjuntos');
            }

            $presentacion['detalles'] = array_values(array_filter([
                $this->detalle('Para', $campos['Para'] ?? ''),
                $this->detalle('Asunto', $campos['Asunto'] ?? ''),
                $this->detalle(
                    'Adjuntos',
                    $totalAdjuntos > 0
                        ? $totalAdjuntos .
                            ($totalAdjuntos === 1
                                ? ' archivo'
                                : ' archivos')
                        : ''
                )
            ]));
            return $presentacion;
        }

        if (stripos($notas, 'Correo de confirmación de reunión') !== false) {
            $presentacion['titulo'] = 'Confirmación de reunión enviada';
            $presentacion['tipo_visual'] = 'correo';
            $presentacion['resultado_label'] = 'Correo enviado';
            $presentacion['resumen'] = 'Confirmación de reunión enviada';
            $presentacion['detalles'] = $this->extraerDetallesConfirmacion(
                $notas
            );
            return $presentacion;
        }

        if (
            stripos($notas, 'confirmó la reunión') !== false ||
            stripos($notas, 'confirmo la reunion') !== false
        ) {
            $presentacion['titulo'] = 'Reunión confirmada';
            $presentacion['tipo_visual'] = 'reunion';
            $presentacion['resultado_label'] = 'Confirmada';
            $presentacion['resumen'] = 'Reunión confirmada';
            $presentacion['detalles'] = [
                $this->detalle('Detalle', $this->limpiarMarcadores($notas))
            ];
            return $presentacion;
        }

        if (
            stripos($notas, 'solicitud de reunión enviada') !== false ||
            stripos($notas, 'solicitud de reunion enviada') !== false ||
            stripos($notas, 'nueva propuesta de reunión enviada') !== false ||
            stripos($notas, 'nueva propuesta de reunion enviada') !== false
        ) {
            $presentacion['titulo'] = 'Propuesta de reunión enviada';
            $presentacion['tipo_visual'] = 'reunion';
            $presentacion['resultado_label'] = 'Propuesta enviada';
            $presentacion['resumen'] = 'Propuesta de reunión enviada';
            $presentacion['detalles'] = [
                $this->detalle('Detalle', $this->limpiarMarcadores($notas))
            ];
            return $presentacion;
        }

        if (stripos($notas, 'oficio preparado') !== false) {
            $presentacion['titulo'] = 'Oficio preparado';
            $presentacion['tipo_visual'] = 'oficio';
            $presentacion['resultado_label'] = 'Oficio preparado';
            $presentacion['resumen'] = 'Oficio preparado';
            $presentacion['detalles'] = [
                $this->detalle('Detalle', $this->limpiarMarcadores($notas))
            ];
            return $presentacion;
        }

        if (
            stripos($notas, 'oficio/correo enviado') !== false ||
            stripos($notas, 'oficio y correo') !== false
        ) {
            $presentacion['titulo'] = 'Oficio y correo enviados';
            $presentacion['tipo_visual'] = 'correo';
            $presentacion['resultado_label'] = 'Correo enviado';
            $presentacion['resumen'] = 'Oficio y correo enviados';
            $presentacion['detalles'] = [
                $this->detalle('Detalle', $this->limpiarMarcadores($notas))
            ];
            return $presentacion;
        }

        if (stripos($notas, 'convenio formalizado') !== false) {
            $presentacion['titulo'] = 'Convenio formalizado';
            $presentacion['tipo_visual'] = 'convenio';
            $presentacion['resultado_label'] = 'Formalizado';
            $presentacion['resumen'] = 'Convenio formalizado';
            $presentacion['detalles'] = [
                $this->detalle('Detalle', $this->limpiarMarcadores($notas))
            ];
            return $presentacion;
        }

        if (stripos($notas, 'seguimiento reactivado') !== false) {
            $presentacion['titulo'] = 'Seguimiento reactivado';
            $presentacion['tipo_visual'] = 'seguimiento';
            $presentacion['resultado_label'] = 'Reactivado';
            $presentacion['resumen'] = 'Seguimiento reactivado';
            $presentacion['detalles'] = [
                $this->detalle('Detalle', $this->limpiarMarcadores($notas))
            ];
            return $presentacion;
        }

        $presentacion['detalles'] = [
            $this->detalle('Detalle', $this->limpiarMarcadores($notas))
        ];

        return $presentacion;
    }

    private function completarDesdeCampos(
        array $interaccion,
        array $presentacion
    ) {
        $canal = strtoupper(trim((string)($interaccion['canal'] ?? '')));

        if ($canal === 'LLAMADA_IP' || $canal === 'LLAMADA') {
            $presentacion['titulo'] = 'Llamada';
            $presentacion['tipo_visual'] = 'llamada';

            $persona = trim((string)(
                $interaccion['persona_atendio'] ??
                $interaccion['contacto_nombre'] ??
                ''
            ));
            $telefono = trim((string)(
                $interaccion['telefono_destino'] ?? ''
            ));
            $duracion = (int)($interaccion['duracion_segundos'] ?? 0);

            $extras = [];
            if ($persona !== '') {
                $extras[] = $this->detalle('Contacto', $persona);
            }
            if ($telefono !== '') {
                $extras[] = $this->detalle('Teléfono', $telefono);
            }
            if ($duracion > 0) {
                $extras[] = $this->detalle(
                    'Duración',
                    $this->duracionLegible($duracion)
                );
            }

            $presentacion['detalles'] = $this->fusionarDetalles(
                $extras,
                $presentacion['detalles']
            );

            if ($presentacion['resumen'] === '') {
                $partes = [
                    $presentacion['resultado_label'] !== ''
                        ? $presentacion['resultado_label']
                        : 'Llamada registrada'
                ];
                if ($persona !== '') {
                    $partes[] = $persona;
                }
                $presentacion['resumen'] = implode(' · ', $partes);
            }
        }

        if ($canal === 'WHATSAPP') {
            $presentacion['titulo'] = 'WhatsApp';
            $presentacion['tipo_visual'] = 'whatsapp';

            $telefono = trim((string)(
                $interaccion['telefono_destino'] ?? ''
            ));

            if ($telefono !== '') {
                $presentacion['detalles'] = $this->fusionarDetalles(
                    [$this->detalle('Teléfono', $telefono)],
                    $presentacion['detalles']
                );
            }
        }

        if ($canal === 'CORREO') {
            if ($presentacion['titulo'] === 'Correo') {
                $presentacion['tipo_visual'] = 'correo';
            }

            $correo = trim((string)(
                $interaccion['correo_destino'] ?? ''
            ));

            if ($correo !== '') {
                $presentacion['detalles'] = $this->fusionarDetalles(
                    [$this->detalle('Para', $correo)],
                    $presentacion['detalles']
                );
            }
        }

        return $presentacion;
    }

    private function extraerDetallesConvenioRecibido($notas)
    {
        $detalles = [];
        $partes = array_map('trim', explode('|', (string)$notas));
        $cabecera = array_shift($partes);

        if (preg_match('/versión\s+(\d+)\s*:\s*(.+)$/ui', (string)$cabecera, $m)) {
            $detalles[] = $this->detalle('Versión', $m[1]);
            $detalles[] = $this->detalle('Archivo', $m[2]);
        }

        foreach ($partes as $parte) {
            if (preg_match('/^([^:]+):\s*(.+)$/u', $parte, $m)) {
                $detalles[] = $this->detalle(trim($m[1]), trim($m[2]));
            } elseif ($parte !== '') {
                $detalles[] = $this->detalle('Observaciones', $parte);
            }
        }

        return array_values(array_filter($detalles));
    }

    private function extraerDetallesConfirmacion($notas)
    {
        $detalles = [];

        if (preg_match('/enviado a\s+([^\.]+)\./ui', $notas, $m)) {
            $detalles[] = $this->detalle('Para', trim($m[1]));
        }
        if (preg_match('/Asunto:\s*(.+?)(?:\.\s*Ecard:|$)/ui', $notas, $m)) {
            $detalles[] = $this->detalle('Asunto', trim($m[1]));
        }
        if (preg_match('/Ecard:\s*(.+)$/ui', $notas, $m)) {
            $detalles[] = $this->detalle('Ecard', trim($m[1]));
        }

        return array_values(array_filter($detalles));
    }

    private function extraerLineasClaveValor($notas)
    {
        $campos = [];
        $lineas = preg_split('/\R/u', (string)$notas);

        foreach (is_array($lineas) ? $lineas : [] as $linea) {
            if (preg_match('/^([^:]{2,30}):\s*(.*)$/u', trim($linea), $m)) {
                $campos[trim($m[1])] = trim($m[2]);
            }
        }

        return $campos;
    }

    private function contarAdjuntos($valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return 0;
        }

        return count(array_filter(array_map(
            'trim',
            explode(',', $valor)
        )));
    }

    private function detalle($etiqueta, $valor)
    {
        $valor = trim((string)$valor);

        if ($valor === '') {
            return null;
        }

        return [
            'etiqueta' => trim((string)$etiqueta),
            'valor' => $valor
        ];
    }

    private function fusionarDetalles(array $primeros, array $segundos)
    {
        $resultado = [];
        $vistos = [];

        foreach (array_merge($primeros, $segundos) as $detalle) {
            if (!is_array($detalle)) {
                continue;
            }

            $etiqueta = trim((string)($detalle['etiqueta'] ?? ''));
            $valor = trim((string)($detalle['valor'] ?? ''));

            if ($etiqueta === '' || $valor === '') {
                continue;
            }

            $clave = mb_strtolower($etiqueta, 'UTF-8');
            if (isset($vistos[$clave])) {
                continue;
            }

            $vistos[$clave] = true;
            $resultado[] = [
                'etiqueta' => $etiqueta,
                'valor' => $valor
            ];
        }

        return $resultado;
    }

    private function detallesComoTexto(array $detalles)
    {
        $lineas = [];

        foreach ($detalles as $detalle) {
            if (!is_array($detalle)) {
                continue;
            }

            $etiqueta = trim((string)($detalle['etiqueta'] ?? ''));
            $valor = trim((string)($detalle['valor'] ?? ''));

            if ($etiqueta !== '' && $valor !== '') {
                $lineas[] = $etiqueta . ': ' . $valor;
            }
        }

        return implode("\n", $lineas);
    }

    private function resumenGenerico(array $presentacion, $notas)
    {
        $resultado = trim((string)(
            $presentacion['resultado_label'] ?? ''
        ));

        if ($resultado !== '' && $resultado !== 'Otro') {
            return trim((string)$presentacion['titulo']) .
                ' · ' . $resultado;
        }

        $nota = $this->limpiarMarcadores($notas);
        if ($nota !== '') {
            return $nota;
        }

        return trim((string)($presentacion['titulo'] ?? 'Actividad'));
    }

    private function limpiarMarcadores($valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return '';
        }

        $mapa = [
            'CONTACTO_EFECTIVO' => 'Contacto efectivo',
            'SIN_CONTACTO_EFECTIVO' => 'Sin contacto',
            'AVANZAR_CONVENIO' => 'Avanzar a convenio',
            'REQUIERE_SEGUIMIENTO' => 'Requiere seguimiento',
            'NO_INTERESADO' => 'No interesado',
            'BUZON_VOZ' => 'Buzón de voz',
            'FUERA_SERVICIO' => 'Fuera de servicio'
        ];

        $valor = preg_replace_callback(
            '/\[([A-Z0-9_-]+)\]/u',
            function ($m) use ($mapa) {
                $codigo = strtoupper((string)$m[1]);
                return isset($mapa[$codigo])
                    ? ' · ' . $mapa[$codigo] . ' · '
                    : ' ';
            },
            $valor
        );

        $valor = preg_replace('/\s+/u', ' ', (string)$valor);
        $valor = preg_replace('/(?:\s*·\s*){2,}/u', ' · ', (string)$valor);

        return trim((string)$valor, " \t\n\r\0\x0B·");
    }

    private function resultadoReunionDesdeTexto($texto)
    {
        $normalizado = mb_strtolower(
            $this->sinAcentos((string)$texto),
            'UTF-8'
        );

        if (strpos($normalizado, 'requiere seguimiento') !== false) {
            return 'Requiere seguimiento';
        }
        if (
            strpos($normalizado, 'avanzar') !== false &&
            strpos($normalizado, 'convenio') !== false
        ) {
            return 'Avanzar a convenio';
        }
        if (strpos($normalizado, 'no interesado') !== false) {
            return 'No interesado';
        }

        return '';
    }

    private function etiquetaCanal($codigo)
    {
        $mapa = [
            'LLAMADA_IP' => 'Llamada',
            'LLAMADA' => 'Llamada',
            'WHATSAPP' => 'WhatsApp',
            'CORREO' => 'Correo',
            'NOTA' => 'Nota',
            'SISTEMA' => 'Actividad del sistema'
        ];

        return $mapa[$codigo] ?? 'Actividad';
    }

    private function etiquetaResultado($codigo)
    {
        $mapa = [
            'CONTACTADO' => 'Contacto correcto',
            'NO_CONTESTO' => 'No contestó',
            'OCUPADO' => 'Ocupado',
            'NUMERO_INCORRECTO' => 'Número incorrecto',
            'CONTACTO_INCORRECTO' => 'Contacto incorrecto',
            'SOLICITO_LLAMAR_DESPUES' => 'Solicitó volver a llamar',
            'SOLICITO_INFORMACION' => 'Solicitó información',
            'MENSAJE_ENVIADO' => 'Mensaje enviado',
            'CORREO_ENVIADO' => 'Correo enviado',
            'SIN_RESPUESTA' => 'Sin respuesta',
            'NO_INTERESADO' => 'No interesado',
            'OTRO' => 'Otro'
        ];

        return $mapa[$codigo] ?? '';
    }

    private function etiquetaResultadoReunion($codigo)
    {
        $mapa = [
            'AVANZAR_CONVENIO' => 'Avanzar a convenio',
            'REQUIERE_SEGUIMIENTO' => 'Requiere seguimiento',
            'NO_INTERESADO' => 'No interesado'
        ];

        return $mapa[$codigo] ?? $this->humanizarCodigo($codigo);
    }

    private function tipoVisualPorCanal($canal)
    {
        $mapa = [
            'LLAMADA_IP' => 'llamada',
            'LLAMADA' => 'llamada',
            'WHATSAPP' => 'whatsapp',
            'CORREO' => 'correo',
            'NOTA' => 'nota',
            'SISTEMA' => 'sistema'
        ];

        return $mapa[$canal] ?? 'actividad';
    }

    private function humanizarCodigo($codigo)
    {
        $texto = mb_strtolower(
            str_replace(['_', '-'], ' ', trim((string)$codigo)),
            'UTF-8'
        );

        return $texto !== ''
            ? mb_strtoupper(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8') .
                mb_substr($texto, 1, null, 'UTF-8')
            : '';
    }

    private function duracionLegible($segundos)
    {
        $segundos = max(0, (int)$segundos);

        if ($segundos < 60) {
            return $segundos . ' s';
        }

        $minutos = (int)floor($segundos / 60);
        $resto = $segundos % 60;

        return $resto > 0
            ? $minutos . ' min ' . $resto . ' s'
            : $minutos . ' min';
    }

    private function limitar($valor, $limite)
    {
        $valor = trim((string)$valor);
        $limite = max(20, (int)$limite);

        if (mb_strlen($valor, 'UTF-8') <= $limite) {
            return $valor;
        }

        return rtrim(
            mb_substr($valor, 0, $limite - 1, 'UTF-8')
        ) . '…';
    }

    private function esNotaTecnicaCorreo($notas)
    {
        return stripos((string)$notas, 'Seguimiento por correo enviado') === 0;
    }

    private function sinAcentos($valor)
    {
        return strtr((string)$valor, [
            'á' => 'a', 'é' => 'e', 'í' => 'i',
            'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I',
            'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U',
            'ñ' => 'n', 'Ñ' => 'N'
        ]);
    }
}
