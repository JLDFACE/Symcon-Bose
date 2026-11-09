#!/usr/bin/env python3
"""Parser für Bose ControlSpace-Designer-Projekte (.csp = XML).
Liest die per ControlSpace-Serial-Protokoll steuerbaren Module aus
(Gain, Source-Selector) samt exaktem Modulnamen – genau das, was die
Symcon-Bose-Module als 'modulename' brauchen.

Aufruf:  python3 -I bose_parse.py projekt.csp [out.json]
"""
import sys, json, xml.etree.ElementTree as ET

STEUERBAR = {'Gain': 'Gain', 'SSelector': 'SourceSelector'}

def props(n):
    d = {}
    for ps in n.findall('Properties'):
        for p in ps.findall('Property'):
            d.setdefault(p.get('name'), p.get('value'))
    return d

def parse(path):
    root = ET.parse(path).getroot()
    out = {'project': root.get('createdBy'), 'device': None, 'modules': []}
    for n in root.iter('Node'):
        cn = n.get('className', '').split('.')[-1]
        d = props(n)
        if cn == 'ESP':
            out['device'] = {'name': d.get('label') or d.get('label1'),
                             'deviceType': d.get('deviceType'),
                             'dspType': d.get('dspType'),
                             'address': d.get('address')}
        elif cn in STEUERBAR:
            out['modules'].append({
                'type': STEUERBAR[cn],
                'name': d.get('label') or d.get('label1'),
                'channelCount': d.get('channelCount'),
                'sourceType': d.get('sourceType'),
            })
    return out

if __name__ == '__main__':
    res = parse(sys.argv[1])
    if len(sys.argv) > 2:
        json.dump(res, open(sys.argv[2], 'w'), ensure_ascii=False, indent=2)
    print(json.dumps(res, ensure_ascii=False, indent=2))
