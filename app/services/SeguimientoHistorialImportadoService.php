<?php

class SeguimientoHistorialImportadoService
{
    public function resolverEtapa(array $seguimiento)
    {
        if (!$this->esHistorialImportado($seguimiento)) {
            return null;
        }

        $texto = $this->normalizarTexto(
            (string)($seguimiento['observaciones'] ?? '')
        );

        if (
            strpos($texto, 'estado(s) excel: convenio') !== false ||
            strpos($texto, 'firma de convenio') !== false ||
            strpos($texto, 'validar el convenio') !== false
        ) {
            return [
                'paso' => 13,
                'clave' => 'CONVENIO_HISTORICO',
                'etapa' => 'Convenio en proceso',
                'titulo' => 'Continuar seguimiento de convenio',
                'descripcion' =>
                    'El historial migrado desde Excel indica que este expediente ya se encontraba en etapa de convenio o seguimiento de firma. No se considera convenio formalizado ni Aliado hasta registrar la formalización real en el CRM.',
                'evidencia' => 'Etapa histórica de convenio / firma'
            ];
        }

        if (strpos($texto, 'se agenda reunion') !== false) {
            return [
                'paso' => 11,
                'clave' => 'REUNION_HISTORICA',
                'etapa' => 'Reunión en coordinación',
                'titulo' => 'Revisar coordinación de reunión',
                'descripcion' =>
                    'El historial migrado desde Excel indica que la reunión ya estaba siendo agendada. La fuente no incluye una fecha confirmada, por lo que no se registra una reunión ficticia ni se contabiliza como realizada.',
                'evidencia' => 'Se agenda reunión'
            ];
        }

        if (strpos($texto, 'coordinando una fecha') !== false) {
            return [
                'paso' => 11,
                'clave' => 'REUNION_HISTORICA_COORDINACION',
                'etapa' => 'Reunión en coordinación',
                'titulo' => 'Continuar coordinación de reunión',
                'descripcion' =>
                    'El historial migrado desde Excel indica que ya se estaba coordinando una fecha de reunión. No se inventa una fecha ni se registra una reunión realizada.',
                'evidencia' => 'Coordinación de reunión registrada en Excel'
            ];
        }

        if (strpos($texto, 'reunion excel:') !== false) {
            return [
                'paso' => 11,
                'clave' => 'REUNION_HISTORICA_FECHA',
                'etapa' => 'Reunión histórica',
                'titulo' => 'Revisar seguimiento de reunión',
                'descripcion' =>
                    'El historial migrado desde Excel contiene una fecha de reunión. Se reconoce como hito histórico sin inventar que la reunión fue realizada ni contabilizarla como actividad productiva del CRM.',
                'evidencia' => 'Fecha de reunión registrada en Excel'
            ];
        }

        if (strpos($texto, 'publican nuestras convocatorias') !== false) {
            return [
                'paso' => 10,
                'clave' => 'DIFUSION_HISTORICA_ACTIVA',
                'etapa' => 'Difusión activa',
                'titulo' => 'Continuar colaboración institucional',
                'descripcion' =>
                    'El historial migrado desde Excel indica que la institución ya publicaba convocatorias. Se reconoce como colaboración histórica activa, sin asumir convenio formalizado ni convertir el expediente en Aliado.',
                'evidencia' => 'Publicación histórica de convocatorias'
            ];
        }

        if (
            strpos($texto, 'se envia correo') !== false ||
            strpos($texto, 'se envio correo') !== false
        ) {
            return [
                'paso' => 8,
                'clave' => 'CORREO_HISTORICO_ENVIADO',
                'etapa' => 'Esperando respuesta',
                'titulo' => 'Esperar respuesta',
                'descripcion' =>
                    'El historial migrado desde Excel acredita que ya se envió correo. El envío se conserva como evidencia histórica y no se contabiliza como actividad productiva realizada dentro del CRM.',
                'evidencia' => 'Correo histórico enviado'
            ];
        }

        if (
            strpos($texto, 'folio excel: redmex/') !== false ||
            strpos($texto, 'folio(s) excel: redmex/') !== false
        ) {
            return [
                'paso' => 6,
                'clave' => 'OFICIO_HISTORICO',
                'etapa' => 'Generación de PDF',
                'titulo' => 'Revisar oficio histórico',
                'descripcion' =>
                    'El historial migrado desde Excel conserva un folio institucional REDMEX. No se asume que el oficio fue enviado si la fuente no lo indica.',
                'evidencia' => 'Folio histórico REDMEX'
            ];
        }

        if (strpos($texto, 'esperar a que se nombre un nuevo titular') !== false) {
            return [
                'paso' => 3,
                'clave' => 'CONTACTO_HISTORICO_EN_ESPERA',
                'etapa' => 'Contacto en espera',
                'titulo' => 'Esperar nuevo titular',
                'descripcion' =>
                    'El historial migrado desde Excel indica que el seguimiento quedó en espera de que la institución nombre un nuevo titular. No se registra una llamada o interacción ficticia.',
                'evidencia' => 'Seguimiento en espera de nuevo titular'
            ];
        }

        if (strpos($texto, 'sede presencial') !== false) {
            return [
                'paso' => 3,
                'clave' => 'CONTACTO_HISTORICO_CONDICIONADO',
                'etapa' => 'Contacto condicionado',
                'titulo' => 'Revisar condición de colaboración',
                'descripcion' =>
                    'El historial migrado desde Excel registra una condición previa para colaborar. Se conserva como contexto operativo sin asumir rechazo ni avance adicional.',
                'evidencia' => 'Condición de colaboración registrada en Excel'
            ];
        }

        $intentoContacto =
            strpos($texto, 'no responden') !== false ||
            strpos($texto, 'no hay respuesta') !== false ||
            strpos($texto, 'red ocupada') !== false ||
            strpos($texto, 'se transfiere llamada sin respuesta') !== false ||
            strpos($texto, 'da tono') !== false ||
            strpos($texto, 'numero ha cambiado') !== false ||
            strpos($texto, 'temporalmente suspendido') !== false ||
            strpos($texto, 'se confirma numero') !== false ||
            strpos($texto, 'enviar correo') !== false ||
            strpos($texto, 'enviar informacion') !== false ||
            strpos($texto, 'se envie informacion') !== false;

        if ($intentoContacto) {
            $correoPendiente =
                strpos($texto, 'enviar correo') !== false ||
                strpos($texto, 'enviar informacion') !== false ||
                strpos($texto, 'se envie informacion') !== false;

            return [
                'paso' => 3,
                'clave' => 'CONTACTO_HISTORICO',
                'etapa' => 'Contacto y validación',
                'titulo' => $correoPendiente
                    ? 'Continuar seguimiento de contacto'
                    : 'Continuar contacto',
                'descripcion' => $correoPendiente
                    ? 'El historial migrado desde Excel registra contacto previo y una acción de seguimiento pendiente. No se contabiliza como llamada o correo realizado dentro del CRM.'
                    : 'El historial migrado desde Excel registra un intento de contacto previo. Continúa la validación sin contabilizar esa actividad histórica como llamada realizada dentro del CRM.',
                'evidencia' => 'Contacto previo registrado en Excel'
            ];
        }

        return null;
    }

    public function esConvenioHistorico(array $seguimiento)
    {
        $etapa = $this->resolverEtapa($seguimiento);

        return is_array($etapa) && (int)($etapa['paso'] ?? 0) === 13;
    }

    public function esHistorialImportado(array $seguimiento)
    {
        $clave = strtoupper(trim((string)($seguimiento['clave_origen'] ?? '')));
        $observaciones = $this->normalizarTexto(
            (string)($seguimiento['observaciones'] ?? '')
        );

        return strpos($clave, 'XLSX:') === 0 ||
            strpos($observaciones, 'migrado desde excel') !== false;
    }

    private function normalizarTexto($texto)
    {
        $texto = trim((string)$texto);
        $texto = function_exists('mb_strtolower')
            ? mb_strtolower($texto, 'UTF-8')
            : strtolower($texto);

        return strtr($texto, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n'
        ]);
    }
}
