#!/usr/bin/env python3
"""Annotate landing pages: add data-cms-*-key on editable elements and
record which SECTION of the landing each block lives in.

The landing is logically one canvas split into named sections (intro,
about, location, infrastructure-hero, apartments-hero, penthouses-hero,
gallery, fitness, ...). Each `<section>` (or some sibling wrappers like
preloader / offers-modal / cookie-consent) maps to one logical section.

For every editable block we walk up the DOM looking for the nearest
ancestor that matches one of SECTION_HINTS (by class prefix). The
section id ends up in the manifest as `section` field; the admin then
groups its sidebar by that.

block_key is a content hash of the default value (text) or src (image),
so the same text used in multiple places maps to a single row in the DB.
"""

import argparse
import hashlib
import json
import os
import re
import sys

from bs4 import BeautifulSoup, NavigableString, Tag


TEXT_TAGS = {'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p'}

LANDING_PAGE_PATHS = {
    '', 'apartments', 'improvement', 'infrastructure', 'investment',
    'location', 'management', 'parking', 'penthouses',
    'privacy-policy', 'request', 'services', 'style',
}

# (class-prefix, (section-id, russian-label))
# Order matters slightly — first match wins. More-specific prefixes first.
SECTION_HINTS: list[tuple[str, tuple[str, str]]] = [
    ('apartments-hero', ('apartments',     'Апартаменты — hero')),
    ('penthouses-hero', ('penthouses',     'Пентхаусы — hero')),
    ('infrastructure-hero', ('infrastructure', 'Инфраструктура — hero')),
    ('intro',          ('intro',          'Главный экран')),
    ('about',          ('about',          'О проекте')),
    ('location',       ('location',       'Локация')),
    ('map',            ('map',            'Карта расположения')),
    ('panorama',       ('panorama',       'Панорама')),
    ('style',          ('style',          'Стиль / архитектура')),
    ('gallery',        ('gallery',        'Галерея')),
    ('time',           ('time',           'Хронология')),
    ('lobby',          ('lobby',          'Лобби')),
    ('advantages',     ('advantages',     'Преимущества')),
    ('fitness',        ('fitness',        'Фитнес-клуб')),
    ('improvement',    ('improvement',    'Благоустройство')),
    ('services',       ('services',       'Сервисы')),
    ('parking',        ('parking',        'Паркинг')),
    ('management',     ('management',     'Управление')),
    ('investment',     ('investment',     'Инвестиции')),
    ('request',        ('request',        'Форма заявки')),
    ('offers-modal',   ('offers',         'Акции (всплывающее)')),
    ('preloader',      ('preloader',      'Прелоадер')),
    ('cookie-consent', ('cookies',        'Cookie-уведомление')),
    ('turn-message',   ('turn-message',   'Сообщение «поверните устройство»')),
    ('header',         ('header',         'Шапка / навигация')),
    ('footer',         ('footer',         'Футер')),
]


def section_id_and_label(class_prefix: str) -> tuple[str, str]:
    for prefix, (sid, label) in SECTION_HINTS:
        if class_prefix == prefix:
            return sid, label
    return class_prefix, class_prefix


def find_section(element: Tag) -> str:
    """Walk up looking for the first class matching a SECTION_HINTS prefix."""
    cur: Tag | None = element
    while cur is not None:
        classes = (cur.get('class') or []) if cur.name else []
        for cls in classes:
            for prefix, (sid, _) in SECTION_HINTS:
                if cls == prefix or cls.startswith(prefix + '__') or cls.startswith(prefix + '-'):
                    return sid
        # Special-case: <header>/<footer> tags themselves map to section ids
        # even if their classes don't include 'header'/'footer'.
        if cur.name == 'header':
            return 'header'
        if cur.name == 'footer':
            return 'footer'
        cur = cur.parent
    return 'unknown'


def text_key(plain: str) -> str:
    return 't-' + hashlib.sha256(plain.encode('utf-8')).hexdigest()[:10]


def img_key(src: str) -> str:
    canonical = src.split('?')[0]
    return 'i-' + hashlib.sha256(canonical.encode('utf-8')).hexdigest()[:10]


def is_placeholder_src(src: str) -> bool:
    return (
        not src
        or src.startswith('data:')
        or src.startswith('<svg')
        or 'http://www.w3.org' in src
        or src.endswith('px.gif')
        or src.endswith('px-2x1.gif')
    )


def first_real_src_of_picture(pic: Tag) -> str | None:
    img = pic.find('img')
    if img is not None:
        src = img.get('src') or ''
        if not is_placeholder_src(src):
            return src.split('?')[0]
    for source in pic.find_all('source'):
        srcset = source.get('srcset') or ''
        first = srcset.split(',')[0].strip().split()[0] if srcset else ''
        if first and not is_placeholder_src(first):
            return first.split('?')[0]
    return None


def text_is_safe(inner_text: str, element: Tag) -> bool:
    """Allow only elements whose content is plain text + simple inline markup."""
    # Disallow nested headings / paragraphs / scripts / etc.
    for child in element.children:
        if isinstance(child, NavigableString):
            continue
        if isinstance(child, Tag) and child.name in {
            'br', 'strong', 'em', 'b', 'i', 'u', 'sup', 'sub', 'a', 'span', 'nobr', 'wbr',
        }:
            continue
        return False
    return True


def page_for_html_file(rel: str) -> str | None:
    rel = rel.replace(os.sep, '/')
    if rel == 'index.html':
        page = ''
    elif rel.endswith('/index.html'):
        page = rel[:-len('/index.html')]
    elif rel.endswith('.html'):
        page = rel[:-len('.html')]
    else:
        return None
    return page if page in LANDING_PAGE_PATHS else None


def annotate_one_file(html: str, page: str, blocks: dict) -> tuple[str, int]:
    """Return (modified_html, blocks_added)."""
    # Strip stale annotations first — keys are content-derived now.
    html = re.sub(r'\s*data-cms-(?:text|img)-key="[^"]*"', '', html)

    soup = BeautifulSoup(html, 'html.parser')
    added = 0

    def record(kind: str, key: str, **fields) -> None:
        nonlocal added
        if key not in blocks:
            blocks[key] = {'type': kind, 'key': key, 'sections': [], 'page_paths': [], **fields}
            added += 1
        if page not in blocks[key]['page_paths']:
            blocks[key]['page_paths'].append(page)
        section = fields.get('section')
        if section and section not in blocks[key]['sections']:
            blocks[key]['sections'].append(section)

    # 1) <picture> blocks
    for pic in soup.find_all('picture'):
        src = first_real_src_of_picture(pic)
        if not src:
            continue
        key = img_key(src)
        pic['data-cms-img-key'] = key
        section = find_section(pic)
        label = os.path.basename(src)
        record(
            'image', key,
            section=section,
            label=label,
            default_src=src,
            element='picture',
        )

    # 2) <img> not inside <picture>
    for img in soup.find_all('img'):
        if img.find_parent('picture'):
            continue
        src = (img.get('src') or '').split('?')[0]
        if is_placeholder_src(src):
            continue
        key = img_key(src)
        img['data-cms-img-key'] = key
        section = find_section(img)
        label = os.path.basename(src)
        record(
            'image', key,
            section=section,
            label=label,
            default_src=src,
            element='img',
        )

    # 3) text-bearing tags
    for el in soup.find_all(TEXT_TAGS):
        if el.find_parent('script') or el.find_parent('style'):
            continue
        inner_html = el.decode_contents()
        inner_text = el.get_text(strip=True)
        if not inner_text or len(inner_text) > 1500:
            continue
        if not text_is_safe(inner_html, el):
            continue
        key = text_key(re.sub(r'\s+', ' ', inner_text))
        el['data-cms-text-key'] = key
        section = find_section(el)
        label = inner_text[:120]
        record(
            'text', key,
            section=section,
            label=label,
            default_value=inner_html,
            element=el.name,
        )

    return str(soup), added


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--site-root', required=True)
    parser.add_argument('--manifest', default=None)
    args = parser.parse_args()

    root = os.path.abspath(args.site_root)
    blocks: dict[str, dict] = {}
    touched = 0
    stripped_only = 0

    for dirpath, _, files in os.walk(root):
        for fn in files:
            if not fn.endswith('.html'):
                continue
            full = os.path.join(dirpath, fn)
            rel = os.path.relpath(full, root)
            page = page_for_html_file(rel)
            with open(full, encoding='utf-8') as f:
                html = f.read()

            if page is None:
                # Wolf snapshot / legacy promo: just strip stale annotations.
                cleaned = re.sub(r'\s*data-cms-(?:text|img)-key="[^"]*"', '', html)
                if cleaned != html:
                    with open(full, 'w', encoding='utf-8') as f:
                        f.write(cleaned)
                    stripped_only += 1
                continue

            new_html, added = annotate_one_file(html, page, blocks)
            if new_html != html:
                with open(full, 'w', encoding='utf-8') as f:
                    f.write(new_html)
                touched += 1
            print(f'  {page or "/":<25} +{added:<3} unique  (total: {len(blocks)})', flush=True)

    print(f'\nUnique blocks: {len(blocks)} ({touched} files annotated, {stripped_only} stripped)')
    if args.manifest:
        with open(args.manifest, 'w', encoding='utf-8') as f:
            json.dump(list(blocks.values()), f, ensure_ascii=False, indent=2)
        print(f'Manifest: {args.manifest}')

    # Distribution summary
    from collections import Counter
    by_section = Counter()
    for b in blocks.values():
        for s in b['sections']:
            by_section[s] += 1
    print('\nDistribution by section:')
    for s, c in by_section.most_common():
        sid, label = section_id_and_label(s)
        print(f'  {c:>4}  {s:<18}  ({label})')

    return 0


if __name__ == '__main__':
    sys.exit(main())
