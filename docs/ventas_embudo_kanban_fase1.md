# Embudo comercial de Ventas — Fase 1

## Alcance implementado
- Controlador GET independiente: embudoComercial, acciones index y detalle.
- Menús: Embudo comercial, Recuperación y Descartados, protegidos por permisos.
- Inscritos permanece en su módulo previo: no se duplica ni modifica.
- Kanban institucional de seis columnas con tarjetas compactas, filtros y 20 registros por página y columna.
- Ficha propia y vista de supervisión para el Administrador; un asesor jamás consulta otra asignación.
- Las acciones de contacto telefónico apuntan a Mis llamadas; no marcan automáticamente ni deducen conversación.
- Ninguna acción del tablero modifica resultados, estado de asignación, pagos o datos de usuarios.

## Separación de estados
- La fase 1 crea las tablas prospecto_embudo_etapas y prospecto_embudo_movimientos.
- Una asignación sin etapa explícita se visualiza como NUEVO, sin actualizar asignaciones ni su estado administrativo.
- El embudo activo tiene seis columnas: Nuevo, En contacto, Contactado, Interesado, Seguimiento y Pago confirmado / Inscripción pendiente.
- Recuperación y Descartados son vistas separadas y estarán vacías hasta que se integren las transiciones reales.
- La columna Pago confirmado se reserva para una futura integración con verificación de pago. La fase 1 no certifica pagos.

## Permisos disponibles en Roles y permisos (módulo Ventas)
- ventas.embudo.ver: consulta propia, asignado inicialmente al Asesor de Ventas.
- ventas.recuperacion.ver: consulta propia, asignado inicialmente al Asesor de Ventas.
- ventas.descartados.ver: consulta propia, asignado inicialmente al Asesor de Ventas.
- ventas.embudo.supervisar: consulta global, restringida al Administrador.
- Todos se comprueban en el servidor y se reflejan en el menú. Una revocación de asesor no se restaura al repetir SQL.

## Publicación controlada, aún NO realizada
1. Confirmar pruebas de GitHub Actions, validar la migración aditiva sobre copia de BD.
2. Conciliar los archivos index.php y app/views/layout/sidebar.php con las personalizaciones reales de Hostinger, NO reemplazarlos sin comparación.
3. Generar respaldo privado antes de desplegar código o migración.
4. Instalar la migración 2026_11_08_ventas_embudo_kanban_fase1.sql después de verificar compatibilidad.
5. Publicar exclusivamente los PHP, vistas y CSS probados con ruta y menú conciliados.
6. Comprobar sintaxis, permisos, filtrado por propietario, paginación, vista admin y que no aparezcan Inscritos ni otros datos de asesores.
7. Verificar que las banderas meta_habilitado y aliados_habilitado sigan en 0.

## Próximas fases
- Permisos separados para registrar resultado, mover etapa, recuperar, descartar y gestionar seguimiento.
- Transiciones con CSRF, historial inmutable y evidencia de llamadas y pagos.
- Salida automática a Inscritos solamente al confirmarse recepción válida del formulario institucional.
- No habilitar motores de Meta ni Aliados antes de validar el embudo y el proceso completo.
