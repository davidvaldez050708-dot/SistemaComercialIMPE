"""Fragmentos de Hostinger revisados con el usuario. Sólo construcción/pruebas."""

TAREAS_PERMISO = """        $puedeTareasAliado = (int)($_SESSION['rol_id'] ?? 0) === 6 &&
            tienePermiso('aliados.ver') &&
            (tienePermiso('aliados.gestionar_programas') ||
             (function_exists('puedeCrearConvocatoriaDesdeConvenio') && puedeCrearConvocatoriaDesdeConvenio()));"""

TAREAS_BLOQUE = """        // Las tareas de Cuenta Clave provienen de la base de datos, no de los
        // permisos de Marketing ni de marcar como leída una notificación.
        if ($puedeTareasAliado) {
            try {
                require_once __DIR__ . '/../services/AliadoConvocatoriaPendienteService.php';
                $tareas = (new AliadoConvocatoriaPendienteService((new Database())->connect()))
                    ->obtener($usuarioId, false, 100);
                // Un permiso de Aliados no concede acceso al editor de Convocatorias.
                $puedePublicar = puedeCrearConvocatoriaDesdeConvenio();
                $tareas = array_values(array_filter($tareas, static function ($tarea) use ($puedePublicar) {
                    return $puedePublicar || ($tarea['etiqueta'] ?? '') === 'Configurar programas';
                }));
                $recordatorios = array_merge($tareas, $recordatorios);
            } catch (Throwable $e) {
                error_log('[campana_aliados_convocatorias] ' . get_class($e));
            }
        }

"""

ORDEN_HOSTINGER = """        // La campana conserva todas las obligaciones telefónicas actuales,
        // además de los 12 recordatorios habituales de los demás módulos.
        $ordenados = $this->ordenarRecordatorios($recordatorios);
        $telefoniaActiva = array_values(array_filter($ordenados,
            static fn($item) => (int)($item['telefonia_id'] ?? 0) > 0 && !empty($item['pendiente_operativo'])));
        $aliadosActivos = array_values(array_filter($ordenados,
            static fn($item) => ($item['tipo'] ?? '') === 'ALIADO_CONVOCATORIA_PENDIENTE'));
        $otros = array_values(array_filter($ordenados,
            static fn($item) => !((int)($item['telefonia_id'] ?? 0) > 0 && !empty($item['pendiente_operativo']))
                && ($item['tipo'] ?? '') !== 'ALIADO_CONVOCATORIA_PENDIENTE'));
        $recordatorios = array_merge($telefoniaActiva, $aliadosActivos, array_slice($otros, 0, 12));

"""

def replace_once(text, old, new):
    if text.count(old) != 1:
        raise RuntimeError('El fragmento revisado no es único: '+old[:90])
    return text.replace(old, new)

def between(text, start, end):
    first,last='\n'+start,'\n'+end
    if text.count(first) != 1 or text.count(last) != 1:
        raise RuntimeError('Separadores no únicos para el bloque revisado.')
    return text[text.index(first)+1:text.index(last)+1]

def reminder_hostinger(base, integrated):
    """Devuelve la variante previa revisada y su integración aditiva."""
    before=replace_once(base,"require_once __DIR__ . '/../services/AliadoNuevoNotificationService.php';\n",'')
    before=replace_once(before,"        $puedeNovedadesAliados = tienePermiso('aliados.ver');",TAREAS_PERMISO)
    before=replace_once(before,'!$puedeNovedadesAliados','!$puedeTareasAliado')
    legacy=between(before,'        if ($puedeNovedadesAliados) {','        $telefoniaOk=false;')
    before=replace_once(before,legacy,TAREAS_BLOQUE)
    legacy_order=between(before,'        $recordatorios = array_slice(\n','        echo json_encode([\n')
    before=replace_once(before,legacy_order,ORDEN_HOSTINGER)

    # Añadir únicamente las seis conexiones de comentarios; conservar las
    # tareas, su autorización y el orden de obligaciones facilitados por SSH.
    after=replace_once(before,"require_once __DIR__ . '/../services/AdminNotificationService.php';\n",
        "require_once __DIR__ . '/../services/AdminNotificationService.php';\nrequire_once __DIR__ . '/../services/AgendaComentarioService.php';\n")
    after=replace_once(after,TAREAS_PERMISO+'\n',TAREAS_PERMISO+"\n        $puedeComentariosAgenda = tienePermiso('reuniones.ver');\n")
    after=replace_once(after,'!$puedeTareasAliado && !$puedeTelefonia\n','!$puedeTareasAliado && !$puedeTelefonia && !$puedeComentariosAgenda\n')
    admin_start='            $resultado = (new AdminNotificationService())->obtener();\n'
    admin_end='            echo json_encode(\n'
    admin_new=between(integrated,admin_start,admin_end)
    after=replace_once(after,admin_start+'\n',admin_new)
    normal=between(integrated,'        if ($puedeComentariosAgenda) {\n','        $telefoniaOk=false;')
    after=replace_once(after,'        $telefoniaOk=false;',normal+'        $telefoniaOk=false;')
    delivered=between(integrated,'        if (isset($comentariosAgenda)) {\n','        echo json_encode([\n')
    after=replace_once(after,'\n        echo json_encode([\n','\n'+delivered+'        echo json_encode([\n')
    return before,after

def recordatorios_hostinger(base, integrated):
    old="                tipo: telefono ? 'TELEFONIA_PERDIDA' : 'VENCIDA',"
    new="                tipo: esRecordatorioVencidoRecurrente(recordatorio) ? 'VENCIDA' : (telefono ? 'TELEFONIA_PERDIDA' : 'VENCIDA'),"
    return replace_once(base,old,new),replace_once(integrated,old,new)

def recordatorios_hostinger_telefono(base, integrated):
    """Variante SSH: conserva la clase is-unread de los avisos telefónicos."""
    before,after=recordatorios_hostinger(base,integrated)
    old="                        '<a class=\"topbar-reminder-item\" '+"
    new="""                        '<a class="topbar-reminder-item' +
                            (Number(recordatorio.telefonia_id)>0 && Number(recordatorio.leida||0)===0 ? ' is-unread' : '') +
                            '" '+"""
    return replace_once(before,old,new),replace_once(after,old,new)

def recordatorios_hostinger_actual(base, integrated):
    """Variante comprobada por HTTP: duración, ISO y obligaciones leídas."""
    before,after=recordatorios_hostinger_telefono(base,integrated)
    changes=[
        ('const DURACION_TOAST = 7000;', 'const DURACION_TOAST = 8000; // Paridad visual con la campana de Marketing.'),
        ("const fechaTexto = String(recordatorio.fecha || '').trim();", "const fechaTexto = String(recordatorio.fecha_iso || recordatorio.fecha || '').trim();"),
        ('if (claves.has(clave) || Number(r.leida || 0) !== 0) return false;',
         'if (claves.has(clave) || (Number(r.leida || 0) !== 0 && r.reiterar_hasta_atender !== true)) return false;')
    ]
    for old,new in changes:
        before=replace_once(before,old,new);after=replace_once(after,old,new)
    return before,after
