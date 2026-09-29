-- Alinea el ENUM de interacciones_vinculacion.resultado con todos los
-- resultados que el flujo de seguimiento puede registrar actualmente.
ALTER TABLE interacciones_vinculacion
    MODIFY COLUMN resultado ENUM(
        'CONTACTADO',
        'NO_CONTESTO',
        'OCUPADO',
        'NUMERO_INCORRECTO',
        'CONTACTO_INCORRECTO',
        'SOLICITO_LLAMAR_DESPUES',
        'SOLICITO_INFORMACION',
        'MENSAJE_ENVIADO',
        'CORREO_ENVIADO',
        'SIN_RESPUESTA',
        'BUZON_VOZ',
        'FUERA_SERVICIO',
        'NO_INTERESADO',
        'OTRO'
    ) DEFAULT NULL;
