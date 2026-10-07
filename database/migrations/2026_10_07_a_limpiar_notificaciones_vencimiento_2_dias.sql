-- Limpieza puntual de alertas existentes de "Convocatoria vence en 2 días".
-- Esta migración se ejecuta una sola vez.
-- No modifica la generación futura de este tipo de notificación.
-- Las nuevas alertas de 2 días seguirán creándose y sólo se ocultarán
-- del modal cuando el usuario las marque como leídas.

DELETE FROM notificaciones_convocatorias
WHERE tipo_evento = 'vencimiento_2_dias';
