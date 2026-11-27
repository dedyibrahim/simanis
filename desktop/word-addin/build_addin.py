"""Build a Word .dotm without changing Office macro/security settings.

Build dependency: pyopenvba==6.5.0 (MIT). End users need only desktop Word.
"""
from pathlib import Path
import hashlib
import json
import sys
import shutil
import zipfile
from xml.etree import ElementTree as ET
from io import BytesIO
from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parent.parent
HERE = ROOT / 'word-addin'
OUT = ROOT / 'output' / 'word-addin'
VERSION = '0.6.0-preview'


def icon(kind):
    im = Image.new('RGBA', (96, 96))
    draw = ImageDraw.Draw(im)
    green = '#11845b'
    if kind == 'pdf':
        draw.rounded_rectangle((20, 8, 75, 88), 6, outline=green, width=6)
        draw.line((56, 8, 56, 30, 75, 30), fill=green, width=5)
        for y in [47, 60, 73]: draw.line((32, y, 62, y), fill=green, width=5)
    elif kind == 'page':
        draw.rounded_rectangle((22, 8, 76, 88), 5, outline=green, width=5)
        draw.rectangle((33, 22, 65, 74), outline=green, width=3)
        for y in [34, 46, 58]: draw.line((40,y,58,y), fill=green, width=3)
    elif kind == 'key':
        draw.ellipse((10, 12, 49, 51), outline=green, width=6)
        draw.line((43,45,79,81), fill=green, width=8)
        draw.line((62,65,71,56), fill=green, width=6)
        draw.line((72,75,81,66), fill=green, width=6)
    elif kind == 'mark':
        for x, y, dx, dy in [(14,14,1,1),(82,14,-1,1),(14,82,1,-1),(82,82,-1,-1)]:
            draw.line((x+dx*20,y,x,y,x,y+dy*20),fill=green,width=6)
        for y in [36, 49, 62]: draw.line((31,y,65,y),fill=green,width=4)
    elif kind == 'help':
        draw.ellipse((10, 10, 86, 86), outline=green, width=6)
        draw.arc((33, 25, 63, 52), 180, 460, fill=green, width=6)
        draw.line((48, 50, 48, 60), fill=green, width=6)
        draw.ellipse((45,69,51,75), fill=green)
    elif kind == 'remove':
        draw.line((20,24,76,24),fill=green,width=6)
        draw.line((36,14,60,14),fill=green,width=6)
        draw.rounded_rectangle((27,27,69,83),5,outline=green,width=5)
        for x in [40,56]: draw.line((x,39,x,71),fill=green,width=4)
    elif kind == 'cancel':
        draw.rounded_rectangle((20,20,76,76),7,outline=green,width=7)
        draw.line((33,33,63,63),fill=green,width=6)
        draw.line((63,33,33,63),fill=green,width=6)
    elif kind == 'refresh':
        draw.arc((15,15,81,81),35,325,fill=green,width=7)
        draw.polygon([(77,14),(86,39),(61,34)],fill=green)
    else:
        if kind in ['all','vertical']: draw.line((19,12,19,84),fill=green,width=6)
        for y in [25,48,71]:
            draw.line((32,y,56,y),fill=green,width=4)
            if kind in ['all','fill']:
                for x in [61,72,83]: draw.line((x,y,x+5,y),fill=green,width=4)
    im = im.resize((32,32), Image.Resampling.LANCZOS)
    out = BytesIO()
    im.save(out, format='PNG')
    return out.getvalue()

try:
    from pyopenvba import WordFile
except ImportError:
    local = ROOT / 'tmp/word-addin-deps/extracted'
    if not local.is_dir():
        raise SystemExit('Build only: install pyopenvba==6.5.0 or put it on PYTHONPATH.')
    sys.path.insert(0, str(local))
    from pyopenvba import WordFile


def build(target=None):
    OUT.mkdir(parents=True, exist_ok=True)
    source = HERE.joinpath('SigandiWord.bas').read_text(encoding='utf-8')
    stage = ROOT / 'tmp' / 'sigandi-word-build.docm'
    with WordFile.create_new(stage) as doc:
        # The clean Word-created seed has empty ThisDocument and Module1.
        # Replace Module1; leave the host module empty (no auto-run handlers).
        doc.set_module('Module1', source)
        doc.save()
    with zipfile.ZipFile(stage) as archive:
        parts = {n: archive.read(n) for n in archive.namelist()}
    content = parts['[Content_Types].xml'].decode('utf-8')
    content = content.replace('application/vnd.ms-word.document.macroEnabled.main+xml',
                              'application/vnd.ms-word.template.macroEnabledTemplate.main+xml')
    parts['[Content_Types].xml'] = content.encode('utf-8')
    # Replace seed metadata; the vendor's empty Word template is not authorship.
    parts['docProps/core.xml'] = b'''<?xml version="1.0" encoding="UTF-8"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>SIMANIS Word Preview</dc:title><dc:creator>SIMANIS</dc:creator><cp:lastModifiedBy>SIMANIS</cp:lastModifiedBy></cp:coreProperties>'''
    ns = 'http://schemas.openxmlformats.org/package/2006/relationships'
    ET.register_namespace('', ns)
    rel = ET.fromstring(parts['_rels/.rels'])
    ET.SubElement(rel, f'{{{ns}}}Relationship', {
        'Id': 'rIdSigandiRibbon',
        'Type': 'http://schemas.microsoft.com/office/2006/relationships/ui/extensibility',
        'Target': 'customUI/customUI.xml',
    })
    parts['_rels/.rels'] = ET.tostring(rel, encoding='utf-8', xml_declaration=True)
    ribbon = ET.fromstring(HERE.joinpath('customUI.xml').read_bytes())
    icons = {'SimanisPickData': 'page', 'SigandiMark': 'mark', 'SigandiAll': 'all', 'SigandiVertical': 'vertical',
             'SigandiFill': 'fill', 'SigandiRefresh': 'refresh', 'SigandiPDF': 'pdf',
             'SigandiRemove': 'remove', 'SigandiHelp': 'help', 'SigandiCancel': 'cancel',
             'SigandiCapApply': 'refresh', 'SigandiPageMenu': 'page', 'SigandiPageSetup': 'page',
             'SigandiPageSave': 'page', 'SigandiPageApply': 'page', 'SigandiPageApplyAll': 'page',
             'SigandiPageHelp': 'help', 'SigandiLicenseMenu': 'key', 'SigandiLicenseInfo': 'key',
             'SigandiLicenseManage': 'key'}
    image_rel = ET.Element(f'{{{ns}}}Relationships')
    added = set()
    for button in ribbon.iter():
        if button.tag.rsplit('}', 1)[-1] not in ('button', 'menu'):
            continue
        kind = icons[button.attrib['id']]
        button.attrib.pop('imageMso', None)
        button.set('image', 'rId' + kind)
        if kind not in added:
            ET.SubElement(image_rel, f'{{{ns}}}Relationship', {
                'Id': 'rId' + kind,
                'Type': 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image',
                'Target': 'images/' + kind + '.png'})
            added.add(kind)
        parts['customUI/images/' + kind + '.png'] = icon(kind)
    ui_ns = 'http://schemas.microsoft.com/office/2006/01/customui'
    ET.register_namespace('', ui_ns)
    parts['customUI/customUI.xml'] = ET.tostring(ribbon, encoding='utf-8', xml_declaration=True)
    ET.register_namespace('', ns)
    parts['customUI/_rels/customUI.xml.rels'] = ET.tostring(image_rel, encoding='utf-8', xml_declaration=True)
    ct_ns = 'http://schemas.openxmlformats.org/package/2006/content-types'
    ct = ET.fromstring(parts['[Content_Types].xml'])
    ET.SubElement(ct, f'{{{ct_ns}}}Default', {'Extension': 'png', 'ContentType': 'image/png'})
    ET.register_namespace('', ct_ns)
    parts['[Content_Types].xml'] = ET.tostring(ct, encoding='utf-8', xml_declaration=True)
    # Native template shortcuts: no AutoExec, Normal.dotm edits or security changes.
    wne = 'http://schemas.microsoft.com/office/word/2006/wordml'
    ET.register_namespace('wne', wne)
    keys = ET.Element(f'{{{wne}}}tcg')
    keymaps = ET.SubElement(keys, f'{{{wne}}}keymaps')
    shortcuts = [(7, 'Mark'), (6, 'All'), (8, 'Vertical'),
                 (9, 'Fill'), (10, 'Refresh'), (11, 'PDF')]
    for function_key, action in shortcuts:
        keymap = ET.SubElement(keymaps, f'{{{wne}}}keymap', {
            f'{{{wne}}}kcmPrimary': f'{0x200 + 111 + function_key:04X}'})
        ET.SubElement(keymap, f'{{{wne}}}macro', {
            f'{{{wne}}}macroName': f'PROJECT.MODULE1.SIGANDISHORTCUT{action.upper()}'})
    parts['word/customizations.xml'] = ET.tostring(keys, encoding='utf-8', xml_declaration=True)
    import re
    supp = ET.Element(f'{{{wne}}}vbaSuppData')
    mcds = ET.SubElement(supp, f'{{{wne}}}mcds')
    for name in re.findall(r'^Public Sub (\w+)\(\)', source, re.MULTILINE):
        full = 'Project.Module1.' + name
        ET.SubElement(mcds, f'{{{wne}}}mcd', {
            f'{{{wne}}}macroName': full.upper(), f'{{{wne}}}name': full,
            f'{{{wne}}}bEncrypt': '00', f'{{{wne}}}cmg': '56'})
    parts['word/vbaData.xml'] = ET.tostring(supp, encoding='utf-8', xml_declaration=True)
    docrels = ET.fromstring(parts['word/_rels/document.xml.rels'])
    ET.SubElement(docrels, f'{{{ns}}}Relationship', {
        'Id': 'rIdSigandiKeys',
        'Type': 'http://schemas.microsoft.com/office/2006/relationships/keyMapCustomizations',
        'Target': 'customizations.xml'})
    parts['word/_rels/document.xml.rels'] = ET.tostring(docrels, encoding='utf-8', xml_declaration=True)
    vbarels = ET.Element(f'{{{ns}}}Relationships')
    ET.SubElement(vbarels, f'{{{ns}}}Relationship', {
        'Id': 'rIdSigandiVbaData',
        'Type': 'http://schemas.microsoft.com/office/2006/relationships/wordVbaData',
        'Target': 'vbaData.xml'})
    parts['word/_rels/vbaProject.bin.rels'] = ET.tostring(vbarels, encoding='utf-8', xml_declaration=True)
    for part, content_type in [
        ('customizations.xml', 'application/vnd.ms-word.keyMapCustomizations+xml'),
        ('vbaData.xml', 'application/vnd.ms-word.vbaData+xml'),
    ]:
        ET.SubElement(ct, f'{{{ct_ns}}}Override', {'PartName': '/word/' + part, 'ContentType': content_type})
    parts['[Content_Types].xml'] = ET.tostring(ct, encoding='utf-8', xml_declaration=True)
    target = Path(target).resolve() if target else OUT / f'SIMANIS-Word-{VERSION}.dotm'
    target.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as archive:
        for name, data in parts.items():
            archive.writestr(name, data)
    # Independently re-open our distribution, verify exact packaged VBA source.
    with WordFile(target) as doc:
        assert doc.get_module('Module1').replace('\r\n', '\n') == source.replace('\r\n', '\n')
        host = doc.get_module('ThisDocument')
        assert not any(token in host.lower() for token in ['sub ', 'function '])
    for name in ['PANDUAN.txt']:
        path = HERE / name
        if path.exists():
            OUT.joinpath(name).write_text(path.read_text(encoding='utf-8'), encoding='utf-8-sig')
    report = {'version': VERSION, 'file': target.name,
              'sha256': hashlib.sha256(target.read_bytes()).hexdigest(),
              'macro_source_verified': True, 'auto_run': False,
              'changes_office_security': False}
    OUT.joinpath('build-report.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
    # Keep the installer input separate from the template open during Word QA.
    payload = OUT / 'installer' / 'SIMANIS-Word.dotm'
    payload.parent.mkdir(parents=True, exist_ok=True)
    if target.resolve() != payload.resolve():
        shutil.copy2(target, payload)
    assert hashlib.sha256(payload.read_bytes()).hexdigest() == report['sha256']
    print(target)


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', type=Path)
    build(parser.parse_args().output)
