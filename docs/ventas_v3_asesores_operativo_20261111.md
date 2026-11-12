# Gestión comercial V3 — Embudo institucional operativo para asesores

Fecha de preparación: 11 de noviembre de 2026. Entrega gradual y reversible; no sustituye la operación productiva sin verificación Hostinger.

## Punto de partida confirmado

Hostinger ya tiene la vista previa de cinco columnas para Administrador/Supervisor y las dos tablas vacías de subetapas. La conciliación encontró INTERESADO:1 (3 llamadas históricas, potencial declarado pero sin programa codificado) y SEGUIMIENTO:1 (próxima acción). Ambos permanecen intactos.

## Nueva ruta de trabajo, optativa

URL: index.php?controller=embudoComercial&action=index&vista=embudo&operativo_v3=1.
Visible únicamente para sesión rol Asesor de Ventas (rol_id=3) con permiso ventas.embudo.ver. El supervisor conserva su vista previa; el asesor conserva la ruta anterior como predeterminada. El tablero antiguo muestra únicamente a asesores un enlace **Nuevo embudo V3**. No se cambia el menú general ni se redirige automáticamente a usuarios activos.

El embudo V3 ofrece cinco columnas Nuevos, Contactados, Potenciales, Seguimiento y Proceso Inscripción, más un bloque compacto Clasificación pendiente para códigos INTERESADO, PAGO_CONFIRMADO y otros. Las bandejas Recuperación y Descartados conservan sus vistas originales. Ninguna asignación legacy desaparece ni se convierte en Potencial o Inscrito.

Cada tarjeta incluye identificación, origen Meta/Aliado, programa, horario de contacto si consta, subetapa real, próxima acción y contador de intentos PBX sin conversación del día local. Hay **paginación real**, 20 por grupo, con total y navegación; las consultas se restringen a a.asesor_id=usuario de sesión y a.vigente=1. Los IDs no vienen del navegador para consultar la colección.

## Operaciones disponibles sin modificar Telefonía

- **Llamar**: usa exactamente window.IMPE_TELEPHONY_PERSISTENT.openDialer y el sessionStorage de asignación/numero/usuario ya utilizado por el Bridge del tablero anterior. No abre otra instancia WebRTC ni inicia llamada en el backend del embudo. El modal V4 de registro sigue en Telefonía.
- **Mis llamadas**: enlace a Telefonía con prospecto_asignacion_id y búsqueda normalizada; conserva la consulta de resultados y grabaciones del sistema vigente.
- **Ficha**: misma ficha comercial y bitácora de movimientos autorizada al propietario, con enlace de retorno a V3; incorpora códigos Potencial y Proceso Inscripción en la ficha sin alterar su significado.
- **Mover**: se permite solo NUEVO -> CONTACTADO (conversación PBX humana acreditada en esa asignación), CONTACTADO -> SEGUIMIENTO (requiere próxima fecha futura) o SEGUIMIENTO -> CONTACTADO (con motivo registrado). Se reutiliza EmbudoComercialMovimientoService::moverManual, con sus bloqueos de propiedad, etapa esperada, fecha, permisos, transacción, agenda y auditoría. La nueva ruta añade comprobación efectiva de CSRF, roles y lista blanca del movimiento.
- **NO se permite** mover desde V3 manualmente hacia POTENCIAL, PROCESO_INSCRIPCION, RECUPERACION o DESCARTADO, ni cambiar el caso legacy INTERESADO sin conciliación. Esto evita falsificar calificación, saltarse la regla de tres intentos o afirmar pago. Las vías operativas antiguas siguen funcionando hasta conmutación coordinada.
- La interfaz no usa drag-and-drop: las transiciones compatibles se realizan mediante botón y confirmación de motivo/fecha. Así no se puede arrastrar una tarjeta a una columna cuya etapa el backend todavía no sabe acreditar.

## Límites necesarios antes de declarar reemplazo total

El cambio de cinco etapas es visual y operativo para llamadas y movimientos seguros, **no** una reescritura integral del motor de etapas. El Bridge productivo actual sigue calculando Recuperación después del primer SIN_RESPUESTA; el contador V3 muestra 1/3...3/3 **informativo** y no activa Recuperación tras tres intentos. El piloto PBX opt-in del PR #254 continúa completamente fuera de esta publicación.

Falta terminar y probar: horarios/turnos para 3 intentos separados, reglas backend transaccionales para Potenciales/Proceso Inscripción, calificación de programa y requisitos, permisos de arrastre, modal V4 contextual, conciliación de legacy y cambio coordinado del Bridge V1. No activar remarketing ni inscripciones automáticas.

## Publicación protegida

Script CLI: tools/publicar_ventas_v3_asesores_hostinger.py. Fuente de los ocho archivos: commit bdbe444726cdcf0009488de67f127199541a6219. Instala cuatro archivos nuevos (servicio de whitelist, vista asesor, CSS y JS optativos) y respalda/sustituye cuatro existentes (Repository, detalle, Controller, index del Kanban). No cambia TelefoniaResultadoVentasService/Catalogo, Bridge, MovimientoService, JS V1, vista previa Supervisor ni tablas SQL.

El script valida SHA Git de ocho archivos y de diez dependencias intocables antes de publicar, y aborta si hay personalizaciones. Exige token de autorización de instalación y backup privado; cada sustitución es atómica por archivo y la reversión verifica hashes para no pisar modificaciones ajenas. No hay transacción conjunta de ocho archivos; si falla electricidad durante publicación debe inspeccionarse manifest y estado antes de reintentar. Nunca modificar archivos manualmente sobre versiones distintas.

## Pruebas

- tests/embudo_comercial_v3_previsualizacion_db.php en MySQL 8 y MariaDB 10.11 desechables: permisos por asesor, 23 tarjetas paginadas (20+3), filtro, legacy visible, PBX real, propiedad y whitelist.
- tests/ventas_v3_asesor_operativo_render_unit.php: UI institucional, cinco etapas, Mis llamadas, marcador existente, paginación, CSRF, escape HTML y sin drag.
- tests/ventas_v3_publicador_asesores_unit.py: sitio temporal construido con archivos previos de PR #255, backup de cuatro modificados, publicación de ocho y diez dependencias inalteradas, idempotencia y reversión completa. Si alguien personaliza un archivo tras publicar, la reversión debe bloquearlo.
- Regresiones previas de EmbudoComercial, resultados PBX y política V3.

**No ejecutar este despliegue mientras exista una prueba roja o sin comparar la salida del --check con la estructura real de Hostinger.**
