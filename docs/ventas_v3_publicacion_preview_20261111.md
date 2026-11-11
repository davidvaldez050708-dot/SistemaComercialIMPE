# Publicación protegida de la vista previa comercial V3

Fecha de preparación: 11 de noviembre de 2026. NO activa el motor de tres intentos ni modifica el teléfono de los asesores.

## Hostinger verificado

El operador confirmó: BASE_V1=4, V3_PREPARADO=7, AUSENTE_NUEVO=7, PERSONALIZADO=0 y AUSENTE_REQUERIDO=0. El nuevo publicador utiliza solamente seis archivos; el resto permanece intacto.

## Dos archivos existentes respaldados y sustituidos

- app/services/EmbudoComercialRepository.php: 0f562e8bab496a2049c70e49ab64e0a3d7398c1a -> f9f8c7b43a8c12e9a02ece1be1132431fa2fea5b
- app/controllers/EmbudoComercialController.php: fbfc356787390bce06edb8678057c71c95c8a6e0 -> a4342e88288321567487b20f0fa80c79564ad465

## Cuatro archivos nuevos

- app/services/EmbudoComercialPoliticaPropuesta.php
- app/services/EmbudoComercialV3IndicadoresVista.php
- app/views/embudo_comercial/previsualizacion_v3.php
- public/css/embudo_v3_indicadores.css

El publicador NO reemplaza EmbudoComercialResultadoBridge, EmbudoComercialMovimientoService, TelefoniaResultadoVentasService, TelefoniaResultadoVentasCatalogo, el index del Kanban, ni el JS de movimientos. Tampoco instala PlanificadorV3 ni PilotoControladoV3, SQL ni cron.

## Revisión exacta y comandos

Paquete de contenido: commit d7038a903465e1126b3532af5d4d9e2e48aab465. Publicador: commit 099933a50b1147fefc7595d5204dbb60dbe25317. SHA Git blob del publicador: 40dea0e6ade93f5d93db73ec2cb49b63a7f181e2.

Descargar únicamente esos archivos en carpeta privada fuera de public_html, comprobar SHA del publicador, y realizar --check de la etapa y seis hashes. La operación --publish exige --confirm PUBLICAR_SOLO_VISTA_PREVIA_V3_IMPE y --backup-root privado. Antes de reemplazar, se verifican siete dependencias no modificables. El orden publica las cuatro dependencias nuevas, luego Repository y finalmente Controller.

Se crea un respaldo privado verificable de los dos archivos anteriores, y se guarda un manifest. os.replace realiza sustitución atómica de cada archivo. La publicación NO es transacción global de seis archivos: si hay error, se intenta revertir y en fallo externo se necesitará revisión manual. No se escribe BD ni resultados de telefonía.

Verificar posteriormente con --check: YA PUBLICADA. Un supervisor con permiso puede abrir index.php?controller=embudoComercial&action=index&vista=embudo&previsualizacion_v3=1. El tablero habitual permanece intacto y los asesores sin permisos de supervisión no reciben la vista previa.

Para revertir con backup exacto usar --rollback --backup-dir RUTA --confirm REVERTIR_SOLO_VISTA_PREVIA_V3_IMPE. No pisa modificaciones realizadas posteriormente por terceros.

## Pruebas

tests/ventas_v3_publicador_preview_unit.py construye un sitio temporal con dos PHP anteriores, siete archivos no modificables y cuatro archivos nuevos. Comprueba: --check, fuente alterada bloqueada, autorización, publicación de 6, idempotencia, respaldo privado, bloqueo de rollback sobre personalización posterior, reversión completa y siete archivos operativos intactos. Las regresiones de UI y el conteo PBX se prueban en MySQL 8 y MariaDB 10.11 desechables.

## Limitación operativa

El contador 3/3 de la vista es informativo: no cambia aún la regla antigua del puente telefónico de producción. No activar el piloto de asesores ni calificar automáticamente las etapas legadas.
