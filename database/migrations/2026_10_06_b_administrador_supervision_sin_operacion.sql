-- El Administrador conserva gobierno, configuración, supervisión y reportes,
-- pero deja de ejecutar acciones operativas propias de cada área.
DELETE rp
FROM rol_permisos rp
INNER JOIN permisos p
    ON p.id = rp.permiso_id
WHERE rp.rol_id = 1
  AND p.codigo IN (
    'prospectos.editar',
    'prospectos.asignar',
    'seguimientos_comerciales.crear',
    'seguimientos_comerciales.editar',
    'seguimientos_comerciales.editar_propios',
    'pagos.validar',
    'oficios.generar',
    'oficios.enviar',
    'reuniones.solicitar',
    'reuniones.gestionar',
    'convenios.gestionar',
    'seguimientos_vinculacion.crear',
    'seguimientos_vinculacion.editar',
    'seguimientos_vinculacion.operar_propios',
    'seguimientos_vinculacion.supervisar',
    'seguimientos_vinculacion.comentar',
    'aliados.gestionar_contactos',
    'aliados.compartir_correo',
    'aliados.preparar_whatsapp',
    'aliados.seguimiento_convocatorias',
    'whatsapp.enviar',
    'convocatorias.crear',
    'convocatorias.editar',
    'convocatorias.gestionar',
    'convocatorias.cambiar_estado',
    'difusion.crear',
    'difusion.enviar',
    'difusion.gestionar'
  );
