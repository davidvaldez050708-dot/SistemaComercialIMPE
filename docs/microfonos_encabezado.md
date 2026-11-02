# Dispositivos de audio en el encabezado

El usuario confirmó que el piloto instalado funciona y autorizó habilitar el control para **todas las cuentas con extensión activa**, manteniendo los permisos telefónicos existentes. Esta actualización está preparada en el PR #190; no se ejecutó desde esta herramienta en Hostinger.

## Cambios

- Se retira el botón flotante que tapaba el perfil del sidebar. Aparece un botón de micrófono junto al auricular de la barra superior, con el mismo tamaño, bordes y tipografía. Si sólo existe barra de acciones y la extensión es de salientes, se coloca allí.
- Panel compacto anclado al encabezado y limitado al viewport. Cierra con clic fuera tanto en el CRM como en el shell, Escape o su botón de cierre. Al entrar o iniciar una llamada se cierra para dejar libres Contestar/Colgar; el usuario puede reabrirlo para consultar. El selector y los botones del panel no lo cierran accidentalmente.
- Aviso **Antes de llamar, revisa que estés usando el micrófono correcto** junto a la marcación del CRM, el marcador flotante y la tarjeta telefónica. **Revisar micrófono** abre el panel. No cancela, retrasa ni repite la marcación; no se añade una confirmación obligatoria al flujo de llamadas.
- **Ya revisé mi micrófono** retira el aviso durante esta sesión del teléfono. No equivale a verificar el sender ni certifica calidad de sonido. Una nueva sesión/recarga, selección, cambio de dispositivos o permiso, o un sender diferente del preferido vuelve a solicitar revisión. Durante llamadas el aviso de marcación se oculta y no se permite una captura temporal.
- La navegación entre módulos reconstruye sólo el botón del nuevo encabezado. El gestor, la preferencia, el panel y el host WebRTC continúan en la misma instancia. Las escuchas del documento anterior se retiran.
- La selección/captura y las funciones de llamadas siguen siendo las de la primera entrega. `telefonia_host.js`, el shell, las transferencias, los resultados, las grabaciones, los webhooks y las notificaciones no cambian en esta actualización.

## Activación para extensiones asignadas

El controlador del host ya resuelve la extensión activa del usuario autenticado. La vista exige además `telefonia.usar` y al menos una capacidad de entrada/salida. No se ofrece el control a personas sin extensión, a extensiones sin capacidades ni a usuarios sin permiso.

El archivo privado `impe-private/telefonia_audio.local.php` debe contener:

```php
<?php
return [
    'enabled' => true,
    'scope' => 'assigned',
];
```

`scope => 'pilot'`, o la ausencia de scope en la configuración ya instalada, conserva el piloto Prueba.llamadas / 155 / rol Ventas. `enabled => false` desactiva todo el gestor. El ejemplo versionado continúa apagado. No se amplía por GET ni localStorage. La preferencia permanece separada por usuario, extensión y navegador.

El paquete no modifica este archivo privado. Cambiar el scope es el último paso del operador después de instalar y comprobar la actualización. Recargar las cuentas únicamente sin llamadas, transferencias ni gestión pendiente. No hace falta modificar roles o asignar permisos nuevos.

## Entrega y reversión

Los recursos públicos JS/CSS se volvieron a comparar con la primera entrega instalada y coincidieron exactamente. Se añade al manifiesto la variante de e511b05c, además de las variantes originales conciliadas de Hostinger.

Se utiliza el mismo publicador `tools/publicar_microfonos_piloto.py` y el manifiesto actualizado `tools/microfonos_piloto_release.json`. Tras descargar ambos desde el mismo commit inmutable, fuera de public_html, ejecutar primero:

```bash
python3 publicar_microfonos_piloto.py --site "$HOME/domains/fundacionrededumex.org/public_html" --backup-root "$HOME/respaldos-impe-microfonos" --check
```

Desde e511b05c se esperan **cuatro archivos compatibles** (helper, CSS, JS del gestor y vista host) y **host.js ya actualizado**. Una diferencia no identificada bloquea todas las escrituras. `--apply` crea respaldo privado y aplica sólo esos cuatro cambios; no escribe en host.js, configuración privada ni base de datos. No ejecutar un git pull ni sustituir otros archivos del teléfono.

Para desactivar la ampliación y mantener el piloto, volver a `scope => 'pilot'`; para desactivar el gestor, usar `enabled => false`. Aplicar los cambios de configuración con el teléfono en reposo y recargar. Para retirar el código, usar el mismo publicador/manifiesto y `--restore /ruta/exacta/del/respaldo-de-esta-actualizacion`. No usar el respaldo de la instalación inicial si sólo se quiere volver al panel anterior. El restore exige que no haya cambios posteriores.

## Verificaciones

- Pruebas unitarias del gestor: preferencias, límites de captura, silencio, aislamiento y fallback conservados.
- PHP: bandera apagada, piloto compatible, modo assigned para Recepción/Analista/Ventas, extensión ausente/inválida, sin capacidades, sin permiso y configuración inválida.
- Navegador: seis combinaciones de roles/extensiones y escritorio/móvil/pantalla baja; botón contiguo al auricular, panel sin recorte, aviso y revisión, clic fuera en ambos documentos, Escape, navegación sin duplicados, submit del CRM y **marcador flotante real** sin bloqueo ni doble inicio. La revisión manual no inventa un sender. Cambio activo no crea capturas ni detiene la pista.
- Publicador: instalación inicial y actualización desde e511b05c, check sin escrituras, LF/CRLF, conflictos, configuración privada preservada, host.js intacto y restore exacto.
- Regresiones previas de transferencias, resultados y rechazo, más sintaxis. Las dos pruebas heredadas descritas en el documento del piloto siguen siendo incompatibles con su propio baseline; no se presentan como aprobadas.

Estas pruebas simulan hardware y SDK; no sustituyen la validación física. Después de instalar, comprobar con Recepción, un Analista y una Asesora: botón junto al auricular, aviso antes de marcar, preferencia propia, sender correcto al conversar, silencio y transferencias. El usuario ya confirmó funcionamiento del piloto, pero no se considera validado automáticamente todo el parque de equipos ni el sonido hueco.
