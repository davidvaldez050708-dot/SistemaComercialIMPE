# Aliados → Convenios → Convocatorias por programa: despliegue V2

## Alcance
PR #124, rama feat/aliados-programas-registro. La ampliación V2 **todavía no está desplegada en Hostinger**. Respeta los aliados y datos previos. No cambia Telefonía, Inscripción ni Handly.

Cuenta Clave puede gestionar contactos e historial manual sin expedientes ficticios. Marketing selecciona Estado → Convenios → Aliado y crea una convocatoria **por programa e imagen**, en los apartados existentes de Bachillerato y Titulación. Todas esas imágenes comparten **el mismo enlace único y QR local** de Registro del aliado. Los interesados eligen su programa en el formulario y se almacenan en Prospectos.

## Dependencias
Se agregan de forma explícita las migraciones:
- database/migrations/2026_10_22_aliados_manuales_contactos_difusion.sql
- database/migrations/2026_10_22_marketing_convocatorias_aliados_manuales.sql

La relación a convocatorias usa el tipo de llave `INT UNSIGNED`. No reinstalar la migración de 2026-10-21. Conservar los respaldos de primera entrega.

## Publicador selectivo
El publicador verifica SHA de **18 archivos** fijados al commit `1d9c1b9961403a7c21bac1b1270dd61ad5fb3506`, reconcilia con el código de la primera entrega y las personalizaciones de Hostinger, valida PHP/JS, genera respaldo privado y permite restauración. Nunca ejecuta SQL.

**Ejecutar --check primero; no aplicar con conflictos.**

En Hostinger compartido, `node` puede no estar disponible en `PATH`. Esta versión permite `--check` sin Node y confirma los SHA de los scripts exactos. Un script JavaScript conciliado con personalizaciones ajenas bloquea `--apply` mientras no sea validado con Node.js fuera de Hostinger; no forzar su instalación ni suprimir el control.

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/entregas-impe/aliados-convenios-v2"
mkdir -p "$PAQUETE" "$HOME/respaldos-impe-aliados" "$HOME/respaldos-impe-bd"
chmod 700 "$PAQUETE" "$HOME/respaldos-impe-aliados" "$HOME/respaldos-impe-bd"
curl -fL --proto '=https' --tlsv1.2 \
"https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/f924b25bf8b2b1a3d33d715bb370ccaa8e58ba9b/tools/publicar_aliados_convenios_v2.py" \
-o "$PAQUETE/publicar_aliados_convenios_v2.py"
python3 "$PAQUETE/publicar_aliados_convenios_v2.py" --check --site "$SITIO"
```

Sólo después de que la CI de MySQL 8 y MariaDB 10.11 esté aprobada, que el --check sea compatible y que se haya exportado/verificado el respaldo **completo** de producción, ejecutar manualmente:

```bash
python3 "$PAQUETE/publicar_aliados_convenios_v2.py" --apply --site "$SITIO"
php "$SITIO/tools/migrar_extensiones_aliados_20261022.php" --apply --backup-dir="$HOME/respaldos-impe-bd"
php "$SITIO/tools/migrar_extensiones_aliados_20261022.php" --check
python3 "$PAQUETE/publicar_aliados_convenios_v2.py" --check --site "$SITIO"
```

Para recuperar los archivos, utilizar la **ruta exacta** anunciada como Respaldo privado:
```bash
python3 "$PAQUETE/publicar_aliados_convenios_v2.py" --restore "RUTA_EXACTA" --site "$SITIO"
```

La restauración de código no revierte SQL ni debe eliminar nuevas respuestas. Una recuperación de base de datos exige revisión separada y copia completa, nunca importar a ciegas el SQL parcial.

## Pruebas de aceptación en CRM
1. Aliados: resumen de origen, acciones compactas, menús, contactos, historial y toast al guardar/error.
2. Convenios: Estado → Guanajuato → directorio por municipio, filtros, modal sin generador redundante.
3. Crear convocatoria para Bachillerato 286, subir imagen y guardar; repetir para Titulación por experiencia laboral con otra imagen. Verificar separación de categorías.
4. Abrir ambas convocatorias y verificar un **mismo enlace/QR**; al registrarse desde ese enlace poder elegir ambos programas.
5. Comprobar Prospectos con aliado de origen, evitar duplicados; regresión de Inscripción, Handly y aliados formales.
