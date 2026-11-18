#!/usr/bin/env python3
"""Recordatorios comerciales V3: integración de pendientes, sin migración SQL."""
import sys
import publicar_tarjetas_compactas_v3_hostinger as anterior

ARCHIVOS = [('app/services/EmbudoComercialV3RecordatoriosService.php', None, 'f52350eeae50a90c6f4655a5670b102ad8c37ac4'), ('app/services/TelefoniaProgramacionVentasService.php', '871baf3097d497e44620601cc4250a26749a4b2e', 'abdfcd8fdc8cee69390377eccd3b8c3338761a90'), ('app/services/EmbudoComercialRepository.php', 'c562e2032939aceaad8b2e9c60f7765176d2eae6', 'b0dae33b915edbecf7dffe44762fec92c4606244'), ('app/controllers/ReminderController.php', '4466b7b114bee198e8f28ffd67a3c59890b55a19', '4c541171c72266ebca47510c49cb3e254a1c5d9e'), ('app/views/layout/topbar.php', '9e308dbc8b19e538f9f58342845e372d63fcb24f', 'dde537d7ad4927458391afaafdcac06d2a7603b1'), ('public/javascript/recordatorios.js', '75561c2c28faaeaf78088baa285c672cf89ad7af', 'fab16da71e563df3bb613fe993232ab93227481c'), ('public/javascript/embudo_comercial_v3_operativo.js', '435c9e2432ddb510221cf8fe2c077a073bb4d3cd', '963186bccf04ba9245a68362670b464f75b63f1b')]
DEPENDENCIAS=anterior.DEPENDENCIAS.copy()
DEPENDENCIAS.update({rel:new for rel,_,new in anterior.ARCHIVOS})
DEPENDENCIAS.update({'app/helpers/ReminderHelper.php': '81b86e4e14eb6c2eef253770f4054d7832a4937a', 'app/services/AgendaReunionService.php': 'bc4b3d263e036eb8a96849718f0f206003b77549', 'public/javascript/telefonia_notificaciones.js': 'a522c9eac8ad82d3d9c6d7d1623a0da9d778b5f9', 'app/services/TelefoniaNotificacionVentasService.php': '3fa7fa037bb02486696d1b8177bdb696b7d548fc', 'app/views/layout/dashboard_footer.php': '772b8d339ae8a749e37c969024245ba65b1444d3'})
for rel,_,_ in ARCHIVOS:DEPENDENCIAS.pop(rel,None)
publicador=anterior.publicador
TELEFONIA_REL=anterior.TELEFONIA_REL
TELEFONIA_HOSTINGER=anterior.TELEFONIA_HOSTINGER
comprobar_esquema=anterior.comprobar_esquema

def configurar_dependencias(sitio):
    anterior.DEPENDENCIAS=DEPENDENCIAS
    anterior.configurar_dependencias(sitio)

def main():
    supervision=anterior.tipos.anterior.supervision
    supervision.ARCHIVOS=ARCHIVOS
    supervision.DEPENDENCIAS=DEPENDENCIAS
    supervision.comprobar_esquema=comprobar_esquema
    supervision.main()

if __name__=='__main__':
    try:main()
    except (publicador.Bloqueo,OSError,ValueError) as error:
        print('BLOQUEADO: '+str(error),file=sys.stderr)
        sys.exit(2)
