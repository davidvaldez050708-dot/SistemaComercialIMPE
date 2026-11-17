#!/usr/bin/env python3
"""Accesos contextuales en tarjetas V3 y WhatsApp reservado; solo vista y CSS."""
import sys
import publicar_supervision_comercial_v3_hostinger as supervision

ARCHIVOS = [('public/css/embudo_v3_operativo.css', '47245d519bad1252a42af5cec0395c598aca5388', 'b5d90da2694e4864d565922a623c742f000b9817'), ('app/views/embudo_comercial/operativo_v3.php', '7a808c745d9d2c96e569d166165a72902d0f528d', 'a1ea555e4da2659ac50019ac88802d02ed4eebb2')]
DEPENDENCIAS = supervision.DEPENDENCIAS.copy()
for relativo,_,_ in ARCHIVOS:DEPENDENCIAS.pop(relativo,None)
DEPENDENCIAS.update({'app/controllers/EmbudoComercialController.php': '20299073d4d263c3f37a641f6830e98efd497e97', 'app/services/EmbudoComercialRepository.php': '0ee88c0f7ff62a49d1e7dc20506fa51f1c243e9a', 'app/views/embudo_comercial/bandejas_v3.php': 'd8a90af671220d465c871b7e70ca40dcb36a0395', 'app/views/embudo_comercial/expedientes.php': 'c57d33326c46f610e12b70b80ab6f9427cad2365', 'app/views/embudo_comercial/detalle.php': 'd49d30db98ea861834e9c214246ac0fc1ea78b2c'})

publicador = supervision.publicador
TELEFONIA_REL = supervision.TELEFONIA_REL
TELEFONIA_HOSTINGER = supervision.TELEFONIA_HOSTINGER
comprobar_esquema = supervision.comprobar_esquema

def configurar_dependencias(sitio):
    supervision.DEPENDENCIAS=DEPENDENCIAS
    supervision.configurar_dependencias(sitio)

def main():
    supervision.ARCHIVOS=ARCHIVOS
    supervision.DEPENDENCIAS=DEPENDENCIAS
    supervision.main()

if __name__=='__main__':
    try:
        main()
    except (publicador.Bloqueo,OSError,ValueError) as error:
        print('BLOQUEADO: '+str(error),file=sys.stderr)
        sys.exit(2)
