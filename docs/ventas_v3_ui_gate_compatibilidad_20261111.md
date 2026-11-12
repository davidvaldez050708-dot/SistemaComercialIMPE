# Gestión comercial — control de acceso y conciliación de PR #256

**Fecha:** 11 de noviembre de 2026. **Sin publicación en Hostinger ni cambio de registros.**

## Hallazgo

El PR #256 (operativo institucional, cinco columnas) pasó sus propios tests en PHP, MySQL 8 y MariaDB 10.11 y su publicador había pasado una simulación de reversión. Sin embargo, en el mismo HEAD hay workflows heredados rojos:
- **Gestión comercial — diseño compacto:** test anclado a hash de vista antigua, que difiere legítimamente tras añadir el enlace V3. El hash NO debe actualizarse a ciegas porque protege otra entrega.
- **Embudo comercial — cierre API Hostinger V4:** test anclado al hash original de `EmbudoComercialResultadoBridge.php`; el repo encadenado tiene una rama experimental de ese puente, que **no está instalada en Hostinger**. No publicar ni corregir el puente como efecto secundario.
- **Tarjetas V3 — integridad:** el test copiaba archivos del HEAD en vez de la versión exacta de la entrega inicial, produciendo falsos fallos después de evolucionar Controller/Repository. Se corrigió el test para que consulte por Git el commit original `d7038a903465e1126b3532af5d4d9e2e48aab465`, sin rebajar ningún SHA.
- **Otros workflows de Recepción, WebRTC, Marcador y Analistas:** contienen pruebas rojas que deben investigarse por separado; no se ha demostrado que sus fallos sean sólo hashes. **No modificar Telefonía para hacer pasar estas pruebas** ni declarar el repositorio entero en verde.

## Protección adicional para el asesor

Se introduce `app/services/EmbudoComercialV3AccesoControlado.php`: la vista operativa, el enlace optativo, la ficha V3 y el POST `moverEtapaV3` se activan **solo** con dos variables de entorno exactas de servidor:

- `IMPE_VENTAS_V3_UI=PERMITIR_SOLO_PANTALLA_V3_ASESORES_IMPE_20261111`
- `IMPE_VENTAS_V3_UI_ASESORES`: lista cerrada de máximo veinte IDs positivos; sin comodines ni espacios. **No incluir asesores productivos sin acordar la prueba**.

Sin ambas variables, **la V3 del asesor queda desactivada**. El tablero actual y sus movimientos V1 siguen disponibles. Aun con ambas variables, se exige rol Asesor (ID 3), sesión propia, permiso comercial y CSRF para el POST; no se aceptan parámetros GET para activar el control.

Esto se separa completamente de `IMPE_VENTAS_V3_PILOTO`: **activar una vista NO activa el motor PBX de tres intentos**, ni cambia la recuperación actual al primer resultado sin contacto. No habilitar ese piloto automáticamente.

## Publicación preparada pero NO autorizada todavía

`tools/publicar_ventas_v3_asesores_hostinger.py` se actualiza a **9 archivos**: cinco nuevos (incluyendo el guardia OFF) y cuatro existentes con respaldo/reversión, en lugar de los ocho de la versión anterior. Además, exige que diez dependencias de Telefonía y de la vista previa V3 sean exactas. No publica `EmbudoComercialResultadoBridge.php`, `EmbudoComercialMovimientoService.php`, WebRTC, V4, grabaciones, CSS/JS del teléfono ni SQL.

Antes de publicar, comprobar:
1. Todas las pruebas específicas de esta rama: control OFF, POST denegado sin BD, render del asesor, preflight de hashes históricos, MySQL 8, MariaDB 10.11, publicador de nueve archivos simulado con rollback.
2. **Hostinger --check solo lectura**, contrastando los cuatro archivos que sí se sustituirían (Repository, Controller, detalle.php e index.php); el diagnóstico anterior de ocho/catorce archivos **no sustituye esta comprobación**, especialmente para detalle.php.
3. Una prueba local de navegación/llamada V4 con cuenta ficticia, primero sin movimientos y luego con transiciones seguras verificadas, sin tocar carteras reales.
4. Una vez verificado todo, si se publican los nueve archivos con flag OFF, el sistema operativo de asesores no cambia por defecto. Una futura habilitación para una cuenta de prueba requeriría las dos variables explícitas y auditoría de resultados.
5. Conservar respaldo de archivos y copia integral de base de datos para despliegues operativos posteriores. Apagar la bandera no revierte movimientos que ya se hubieran realizado durante una prueba.

**Aún no está resuelta la transición a Potenciales/Proceso Inscripción ni la política completa de jornadas.** Mantener ambas funciones protegidas y no afirmar que el embudo V3 ya reemplazó el proceso comercial completo.
