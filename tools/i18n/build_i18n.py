"""Writes the .pot, .po, .mo and JS .json translation files of the plugin."""
import hashlib, json, re, struct, sys
sys.path.insert(0, '.')
from translations import T

plugin = sys.argv[1]
strings = json.load(open('strings.json'))
LOCALES = [('it_IT', 0, 'nplurals=2; plural=(n != 1);'), ('fr_FR', 1, 'nplurals=2; plural=(n > 1);'),
           ('de_DE', 2, 'nplurals=2; plural=(n != 1);'), ('es_ES', 3, 'nplurals=2; plural=(n != 1);')]
DATE = '2026-11-24 10:00+0000'
problems = []

def placeholders(s):
    return sorted(re.findall(r'%(?:\d\$)?[sd]', s))

def po_str(s):
    return '"' + s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n') + '"'

def header(lang, plural):
    h = {'Project-Id-Version': 'Camille - AI Personal Shopper 0.1.0', 'Report-Msgid-Bugs-To': 'support@pinci.ai',
         'POT-Creation-Date': DATE, 'PO-Revision-Date': DATE, 'Last-Translator': 'Instamark S.r.l.',
         'Language-Team': '', 'Language': lang, 'MIME-Version': '1.0', 'Content-Type': 'text/plain; charset=UTF-8',
         'Content-Transfer-Encoding': '8bit', 'Plural-Forms': plural, 'X-Domain': 'camille-personal-shopper'}
    return ''.join(f'{k}: {v}\n' for k, v in h.items())

def write_po(path, lang, plural, idx):
    out = ['msgid ""', 'msgstr ""'] + [po_str(line + '\n') for line in header(lang, plural).rstrip('\n').split('\n')] + ['']
    catalog = {}
    for s in strings:
        for c in s['comments']:
            out.append('#. ' + c)
        out.append('#: ' + ' '.join(s['refs']))
        if '%' in s['msgid']:
            out.append('#, php-format')
        out.append('msgid ' + po_str(s['msgid']))
        tr = T.get(s['msgid'])
        if tr is None:
            problems.append('missing: ' + s['msgid'])
        if s['plural']:
            out.append('msgid_plural ' + po_str(s['plural']))
            forms = tr[idx] if (tr and idx is not None) else ('', '')
            if idx is None:
                forms = ('', '')
            for n, f in enumerate(forms):
                out.append(f'msgstr[{n}] ' + po_str(f))
            if idx is not None and tr:
                for f in forms:
                    if placeholders(f) != placeholders(s['msgid']):
                        problems.append(f'{lang} placeholders: {f}')
                catalog[s['msgid'] + '\x00' + s['plural']] = '\x00'.join(forms)
        else:
            value = tr[idx] if (tr and idx is not None) else ''
            out.append('msgstr ' + po_str(value))
            if idx is not None and tr:
                if placeholders(value) != placeholders(s['msgid']):
                    problems.append(f'{lang} placeholders: {value}')
                catalog[s['msgid']] = value
        out.append('')
    open(path, 'w', encoding='utf-8').write('\n'.join(out))
    return catalog

def write_mo(path, catalog, lang, plural):
    catalog = dict(catalog)
    catalog[''] = header(lang, plural)
    keys = sorted(catalog.keys(), key=lambda k: k.encode('utf-8'))
    ids = b''; strs = b''; offsets = []
    for k in keys:
        kb, vb = k.encode('utf-8'), catalog[k].encode('utf-8')
        offsets.append((len(ids), len(kb), len(strs), len(vb)))
        ids += kb + b'\x00'; strs += vb + b'\x00'
    n = len(keys)
    keystart = 7 * 4 + 16 * n
    valuestart = keystart + len(ids)
    koffsets = []; voffsets = []
    for o1, l1, o2, l2 in offsets:
        koffsets += [l1, o1 + keystart]; voffsets += [l2, o2 + valuestart]
    data = struct.pack('Iiiiiii', 0x950412de, 0, n, 7 * 4, 7 * 4 + n * 8, 0, 0)
    data += struct.pack(f'{len(koffsets)}i', *koffsets) + struct.pack(f'{len(voffsets)}i', *voffsets) + ids + strs
    open(path, 'wb').write(data)

base = f'{plugin}/languages/camille-personal-shopper'
write_po(base + '.pot', '', 'nplurals=INTEGER; plural=EXPRESSION;', None)
js_files = sorted({f for s in strings for f in s['js']})
for lang, idx, plural in LOCALES:
    catalog = write_po(f'{base}-{lang}.po', lang, plural, idx)
    write_mo(f'{base}-{lang}.mo', catalog, lang, plural)
    for js in js_files:
        messages = {'': {'domain': 'messages', 'lang': lang, 'plural-forms': plural}}
        for s in strings:
            if js in s['js'] and s['msgid'] in catalog:
                messages[s['msgid']] = [catalog[s['msgid']]]
        doc = {'translation-revision-date': DATE, 'generator': 'camille-build-i18n', 'domain': 'messages',
               'locale_data': {'messages': messages}}
        name = f'{base}-{lang}-{hashlib.md5(js.encode()).hexdigest()}.json'
        json.dump(doc, open(name, 'w', encoding='utf-8'), ensure_ascii=False)
extra = set(T) - {s['msgid'] for s in strings}
print('problems:', problems or 'none', '| unused translations:', extra or 'none', '| js files:', js_files)
