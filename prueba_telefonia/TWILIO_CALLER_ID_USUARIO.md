# Caller ID por usuario en Twilio

El Sistema Comercial IMPE toma el número de salida desde `usuarios.telefono` del Analista autenticado.

Antes de permitir la llamada, `prueba_telefonia/api/token.php` comprueba que ese número sea:

- un número comprado en la misma cuenta de Twilio, o
- un **Outgoing Caller ID** verificado en Twilio.

El navegador envía ese número a la TwiML App como parámetro `CallerId` en la llamada de `Twilio.Device.connect()`.

## Cambio necesario en la Function de llamada

La Function/TwiML App que actualmente usa un `CALLER_ID` fijo debe dejar de usar el valor fijo y tomar `event.CallerId`.

Ejemplo compatible con la grabación actual:

```javascript
exports.handler = async function (context, event, callback) {
  const response = new Twilio.twiml.VoiceResponse();
  const to = String(event.To || '').trim();
  const callerId = String(event.CallerId || '').trim();

  if (!to || !callerId) {
    return callback(new Error('Faltan To o CallerId para realizar la llamada.'));
  }

  try {
    const client = context.getTwilioClient();
    let autorizado = false;

    const callerIds = await client.outgoingCallerIds.list({
      phoneNumber: callerId,
      limit: 1
    });

    if (callerIds.length > 0) {
      autorizado = true;
    }

    if (!autorizado) {
      const numerosTwilio = await client.incomingPhoneNumbers.list({
        phoneNumber: callerId,
        limit: 1
      });
      autorizado = numerosTwilio.length > 0;
    }

    if (!autorizado) {
      return callback(new Error('El Caller ID del usuario no está autorizado en Twilio.'));
    }

    const dial = response.dial({
      callerId: callerId,
      answerOnBridge: true,
      record: 'record-from-answer-dual'
    });

    dial.number(to);
    return callback(null, response);
  } catch (error) {
    return callback(error);
  }
};
```

Después de guardar y desplegar la Function, la institución llamada verá el número registrado del Analista, siempre que ese número esté autorizado en Twilio.

## Alta de un nuevo Analista

Para que un nuevo Analista pueda usar telefonía integrada:

1. Registrar su teléfono real en el módulo de usuarios del Sistema Comercial IMPE.
2. Guardarlo preferentemente con lada y número; el sistema normaliza números mexicanos a formato `+52XXXXXXXXXX`.
3. En Twilio, agregar ese teléfono como **Verified Caller ID** si no es un número comprado en Twilio.
4. Completar la llamada de verificación de Twilio.
5. Iniciar sesión nuevamente o recargar el sistema.
6. Al abrir una llamada institucional debe aparecer `Desde: +52... · Verificado`.

Si el teléfono no está registrado o no está autorizado en Twilio, el sistema impide iniciar la llamada para evitar que se utilice silenciosamente un número fijo distinto al del Analista.

## Nota de seguridad

La validación se realiza tanto en el backend del sistema como en la Function de Twilio. La Function no debe aceptar un `CallerId` sin comprobar que pertenece a los números autorizados de la cuenta.
