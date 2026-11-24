"""Small gettext extractor for the plugin: PHP and JS calls with the plugin text domain, block.json."""
import json, re, sys, os

DOMAIN = 'camille-personal-shopper'
root = sys.argv[1]
entries = {}  # (ctx, msgid, plural) -> {refs, comments, js}

FUNCS = r'(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e|_n)'
STR = r"'((?:[^'\\]|\\.)*)'"

def php_unescape(s):
    return s.replace("\\'", "'").replace('\\\\', '\\')

def add(msgid, plural, ref, comment, is_js):
    key = (msgid, plural)
    e = entries.setdefault(key, {'refs': [], 'comments': [], 'js': set()})
    e['refs'].append(ref)
    if comment and comment not in e['comments']:
        e['comments'].append(comment)
    if is_js:
        e['js'].add(ref.split(':')[0])

call_re = re.compile(r"(?:(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e)\(\s*" + STR + r"\s*,\s*'" + DOMAIN + r"'\s*\))|(?:_n\(\s*" + STR + r"\s*,\s*" + STR + r"\s*,[^;]*?,\s*'" + DOMAIN + r"'\s*\))", re.S)

for dirpath, _, files in os.walk(root):
    if '/languages' in dirpath:
        continue
    for f in files:
        path = os.path.join(dirpath, f)
        rel = os.path.relpath(path, root)
        if f.endswith('.php') or (f.endswith('.js') and 'blocks/' in rel):
            src = open(path, encoding='utf-8').read()
            for m in call_re.finditer(src):
                line = src.count('\n', 0, m.start()) + 1
                before = src[:m.start()]
                window = before[-600:]
                found = list(re.finditer(r"/\*\s*(translators:.*?)\*/|//\s*(translators:[^\n]*)", window, re.S))
                comment = ''
                if found:
                    last = found[-1]
                    gap = window[last.end():]
                    if gap.count('\n') <= 2 and 'translators' not in gap:
                        comment = re.sub(r'\s+', ' ', (last.group(1) or last.group(2)).strip())
                full = m.group(0)
                is_plural = m.group(1) is None
                msgid = php_unescape(m.group(2) if is_plural else m.group(1))
                plural = php_unescape(m.group(3)) if is_plural else None
                add(msgid, plural, f'{rel}:{line}', comment, f.endswith('.js'))
        if f == 'block.json':
            data = json.load(open(path))
            for field in ('title', 'description'):
                add(data[field], None, rel, f'block {field}', False)
            for kw in data.get('keywords', []):
                add(kw, None, rel, 'block keyword', False)
# plugin header
add('Camille – AI Personal Shopper for WooCommerce', None, 'camille-personal-shopper.php', 'Plugin Name of the plugin', False)
add('Camille is an AI personal shopper for fashion stores: she advises your customers in chat, suggests outfits from your catalog and shows them on a virtual model.', None, 'camille-personal-shopper.php', 'Description of the plugin', False)
add('Instamark S.r.l.', None, 'camille-personal-shopper.php', 'Author of the plugin', False)

out = [{'msgid': k[0], 'plural': k[1], **{kk: (sorted(v) if isinstance(v, set) else v) for kk, v in e.items()}} for k, e in entries.items()]
json.dump(out, open(sys.argv[2], 'w'), ensure_ascii=False, indent=1)
print(len(out), 'strings;', sum(1 for o in out if o['js']), 'in js;', sum(1 for o in out if o['plural']), 'plural')
