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

        $intentoContacto =
            strpos($texto, 'no responden') !== false ||
            strpos($texto, 'no hay respuesta') !== false ||
            strpos($texto, 'red ocupada') !== false ||
            strpos($texto, 'se transfiere llamada sin respuesta') !== false ||
            strpos($texto, 'da tono') !== false ||
            strpos($texto, 'enviar correo') !== false ||
            strpos($texto, 'enviar informacion') !== false;

        if ($intentoContacto) {
            $correoPendiente =
                strpos($texto, 'enviar correo') !== false ||
                strpos($texto, 'enviar informacion') !== false;

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
