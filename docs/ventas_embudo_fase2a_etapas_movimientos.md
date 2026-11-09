# Gestión comercial · Fase 2A (etapas, movimientos y resultados PBX)

## Decisión de negocio
- **Cinco etapas activas**: Nuevos, Contactados, Interesados, Seguimiento, Pago confirmado / Inscripción pendiente.
- **Fuera del embudo**: Recuperación, Descartados; Inscritos continúa en su módulo histórico.
- «En contacto» se elimina del tablero; si hay un registro antiguo en esa etapa se visualiza en Recuperación para revisión, sin reescribirlo ni atribuir una llamada.
- Un intento registrado como SIN_RESPUESTA, BUZON_VOZ, OCUPADO, MENSAJE_AUTOMATICO u OTRO_SIN_CONTACTO puede colocar al prospecto en Recuperación. Se agenda **revisión para el día siguiente (fecha local de Ciudad de México)**, pero NO se envía mensaje, campaña ni llamada automáticamente.
- CONVERSACION_PERSONA → Contactados; INTERESADO o SOLICITA_INFORMACION → Interesados; REQUIERE_SEGUIMIENTO → Seguimiento con fecha/hora obligatorias.
- NO_INTERESADO no descarta automáticamente: el asesor debe definir si es rechazo definitivo o temporal; NUMERO_INEXISTENTE, FUERA_HORARIO y COMUNICACION_INTERRUMPIDA también exigen criterio humano.
- PAGO_CONFIRMADO queda reservado para evidencia verificable: ni arrastre ni resultado telefónico certifican el pago.
- Inscritos solo se actualiza cuando se reciba el formulario de inscripción real, mediante la integración futura con el módulo existente.

## Implementación
- Las tarjetas pueden arrastrarse a otras etapas activas. Existe botón alternativo para dispositivos sin arrastre.
- Para cambios manuales, el servidor confirma **asignación vigente, propietario, etapa esperada, permisos, motivo y fecha**. La bitácora existente guarda cada movimiento.
- Para recuperación y descartes hay permisos propios: ventas.embudo.mover, ventas.recuperacion.gestionar y ventas.descartados.gestionar; el Administrador sigue siendo solo lector.
- `prospecto_embudo_agenda` almacena la siguiente acción y la fecha de recuperación, sin reescribir la agenda ni los motores de distribución.
- `prospecto_embudo_llamadas` guarda una referencia PBX comprobada vinculada con asignación inequívoca.
- En la ficha del prospecto se accede a Mis llamadas **con referencia explícita a la asignación**, no por heurística de nombre o últimos 10 dígitos.
- El número exacto de la llamada PBX y el teléfono normalizado de la asignación deben corresponder (solo se quitan separadores); si no corresponden, no se mueve la etapa.
- El resultado de la llamada se guarda primero en Telefonía. Si falla la vinculación con el embudo, la llamada permanece registrada y se devuelve advertencia; el proveedor no se altera.
- Cuando no existe referencia de asignación, la telefonía continúa exactamente con su comportamiento previo.
- Ni marketing, ni vinculación, ni motores de Meta/Aliados se modifican.

## Migración
`database/migrations/2026_11_09_ventas_etapas_movimientos_fase2a.sql` crea dos tablas nuevas y tres permisos independientes. No altera `prospecto_asignaciones`, `telefonia_ventas_resultados` ni el módulo Inscritos.

## Publicación en Hostinger
**Sin publicar.** Antes de usar esta rama, comparar los hashes y las personalizaciones reales de:
- `app/controllers/EmbudoComercialController.php`
- `app/services/EmbudoComercialRepository.php`
- `app/services/TelefoniaResultadoVentasService.php`
- `app/views/telefonia/ventas_llamadas.php`
- `app/views/telefonia/resultado_ventas_form.php`
- `public/javascript/telefonia_ventas_resultados.js`
- `app/helpers/AdminRolePolicy.php`

Nunca sobrescribir personalizaciones de Hostinger ni aplicar la migración sin respaldo y preflight. En particular, la integración con Telefonía exige comparar el historial de resultados real para evitar regresiones.

## Pruebas de aceptación posteriores al despliegue
1. Asesor puede arrastrar propio; no puede mover otro asesor ni un descartado, y un Administrador no puede operar.
2. Una llamada PBX finalizada, asociada explícitamente y sin respuesta mueve a Recuperación y muestra revisión para mañana, sin enviar mensajes.
3. Un buzón, locución o answered de Zadarma nunca se convierten en conversación.
4. Una conversación real pasa a Contactados; interés confirmado pasa a Interesados.
5. Fecha de seguimiento válida y futura, sin afectar asignaciones de Meta.
6. Un resultado PBX de número distinto se guarda en Telefonía sin cambiar etapas.
7. Revisar historiales, grabaciones, transferencias y permisos previamente existentes.
8. Inscritos se mantiene totalmente independiente.
