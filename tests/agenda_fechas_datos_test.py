"""Fechas y categorías contrastadas con calendario.mx, sin red ni BD."""
import collections
import datetime as dt
import json
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
d=json.loads((ROOT/'app/data/agenda_fechas_2026_2027.json').read_text(encoding='utf8'))
assert d['fuentes']==['https://calendario.mx/','https://calendario.mx/2027/']
expected_official={2026:['01-01','02-02','03-16','05-01','09-16','11-16','12-25'],2027:['01-01','02-01','03-15','05-01','06-06','09-16','11-15','12-25']}
expected_santa={2026:['02-17','02-18','03-29','04-02','04-03','04-04','04-05'],2027:['02-09','02-10','03-21','03-25','03-26','03-27','03-28']}
fixed_traditional=['01-06','02-02','02-05','02-14','02-19','02-24','03-08','03-18','03-21','04-30','05-03','05-05','05-10','05-15','06-01','09-13','09-15','09-27','10-12','11-01','11-02','11-20','12-12','12-16','12-24','12-28','12-31']
for year in [2026,2027]:
    counts=collections.Counter(e['categoria'] for date,events in d['eventos'].items() if date.startswith(str(year)) for e in events)
    assert counts==dict(oficial=len(expected_official[year]),santa=7,tradicional=28)
    for kind,expected in [('oficial',expected_official[year]),('santa',expected_santa[year]),('tradicional',sorted(fixed_traditional+(['06-21']if year==2026 else ['06-20'])))]:
        assert sorted(date[5:] for date,values in d['eventos'].items() if date.startswith(str(year)) and any(e['categoria']==kind for e in values))==expected
for date,values in d['eventos'].items():
    assert dt.date.fromisoformat(date).year in [2026,2027]
    assert len({(v['categoria'],v['nombre'])for v in values})==len(values)
assert d['eventos']['2027-06-06'][0]['origen']=='electoral'
ranges=[(x['desde'],x['hasta']) for x in d['fines_semana_largos']]
assert ranges==[('2026-01-31','2026-02-02'),('2026-03-14','2026-03-16'),('2026-05-01','2026-05-03'),('2026-11-14','2026-11-16'),('2026-12-25','2026-12-27'),('2027-01-01','2027-01-03'),('2027-01-30','2027-02-01'),('2027-03-13','2027-03-15'),('2027-11-13','2027-11-15')]
assert all((dt.date.fromisoformat(b)-dt.date.fromisoformat(a)).days==2 for a,b in ranges)
assert {v['categoria']for v in d['eventos']['2027-03-21']}=={'santa','tradicional'}
assert {v['categoria']for v in d['eventos']['2026-02-02']}=={'oficial','tradicional'}
print('OK: ambas anualidades completas; oficiales y electoral, 28 tradicionales/año, Semana Santa variable, 9 puentes y categorías coincidentes.')
