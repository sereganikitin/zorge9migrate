#!/usr/bin/env python3
"""Annotate landing pages with `data-cms-*-key` attributes.

The KEY for each editable element is a content hash: same text → same key
across all pages.  This avoids the dedup problem we used to have where the
homepage and 12 other landing pages all carried 250+ identical TextBlocks.

After annotation we emit a manifest JSON like:

    [
      {
        "type": "text",
        "key": "t-abc12345",
        "label": "Зорге 9 — Дом с привилегиями",
        "default_value": "<inner html>",
        "element": "h1",
        "page_paths": ["", "apartments", "location"]
      },
      ...
    ]

so that `app:seed-from-manifest` can UPSERT one block per unique key and
remember every page where it appears.

Annotation rules unchanged:
- <picture>, <img>: get `data-cms-img-key`.
- <h1>..<h6>, <p>: get `data-cms-text-key` if inner content is plain text
  (allowed inline tags: <br>, <a>, <strong>/<em>/<b>/<i>/<u>/<sup>/<sub>).

Idempotent: an element that already has a key keeps it.
"""

import argparse
import hashlib
import json
import os
import re
import sys


TEXT_TAGS = ('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p')

LANDING_PAGE_PATHS = {
    '',                # site/index.html (homepage)
    'apartments', 'improvement', 'infrastructure', 'investment',
    'location', 'management', 'parking', 'penthouses',
    'privacy-policy', 'request', 'services', 'style',
}


def text_key(plain: str) -> str:
    h = hashlib.sha256(plain.encode('utf-8')).hexdigest()[:10]
    return f't-{h}'


def img_key(src: str) -> str:
    # Strip query string so /assets/foo.webp?v=123 and /assets/foo.webp share a key.
    canonical = src.split('?')[0]
    h = hashlib.sha256(canonical.encode('utf-8')).hexdigest()[:10]
    return f'i-{h}'


def first_real_src_of_picture(picture_html: str) -> str | None:
    """Find the first <img src> inside a <picture>; skip inline-svg placeholders."""
    for m in re.finditer(r'<img[^>]+src="([^"]+)"', picture_html):
        src = m.group(1).split('?')[0]
        if not is_placeholder(src):
            return src
    for m in re.finditer(r'<source[^>]+srcset="([^,\s"]+)', picture_html):
        src = m.group(1).split('?')[0]
        if not is_placeholder(src):
            return src
    return None


def is_placeholder(src: str) -> bool:
    return (
        src.startswith('data:')
        or src.startswith('<svg')
        or 'http://www.w3.org' in src
        or src.endswith('px.gif')
        or src.endswith('px-2x1.gif')
    )


def page_for_html_file(rel_path: str) -> str | None:
    """Map site-relative .html file path to its landing slug.
    Returns None if the file isn't part of the landing we manage."""
    rel = rel_path.replace(os.sep, '/')
    if rel == 'index.html':
        page = ''
    elif rel.endswith('/index.html'):
        page = rel[:-len('/index.html')]
    elif rel.endswith('.html'):
        page = rel[:-len('.html')]
    else:
        return None
    return page if page in LANDING_PAGE_PATHS else None


SAFE_INNER_RE = re.compile(
    r'^(?:[^<]|<br\s*/?>|<\/?(?:strong|em|b|i|u|sup|sub|nobr|wbr)\b[^>]*>|<a\b[^>]*>|</a>)*$',
    re.IGNORECASE | re.DOTALL,
)

TEXT_TAG_RE = re.compile(
    r'(<(' + '|'.join(TEXT_TAGS) + r')\b[^>]*>)([\s\S]*?)(</\2>)',
    re.IGNORECASE,
)


def annotate_html(html: str, page: str, blocks: dict) -> str:
    """Mutate `blocks` (key → record) as we walk the HTML; return annotated HTML."""
    # Always strip any existing data-cms-*-key first — keys are content-derived,
    # so we want them to be fully regenerated whenever the script runs.
    html = re.sub(r'\s*data-cms-(?:text|img)-key="[^"]*"', '', html)

    def record_image(key: str, default_src: str, label: str, element: str) -> None:
        if key not in blocks:
            blocks[key] = {
                'type': 'image',
                'key': key,
                'label': label,
                'default_src': default_src,
                'element': element,
                'page_paths': [],
            }
        if page not in blocks[key]['page_paths']:
            blocks[key]['page_paths'].append(page)

    def record_text(key: str, default_value: str, label: str, element: str) -> None:
        if key not in blocks:
            blocks[key] = {
                'type': 'text',
                'key': key,
                'label': label,
                'default_value': default_value,
                'element': element,
                'page_paths': [],
            }
        if page not in blocks[key]['page_paths']:
            blocks[key]['page_paths'].append(page)

    def picture_sub(m: re.Match) -> str:
        whole = m.group(0)
        opening = m.group(1)
        if 'data-cms-img-key=' in opening:
            return whole
        default_src = first_real_src_of_picture(whole)
        if not default_src:
            return whole
        label = os.path.basename(default_src.split('?')[0])
        key = img_key(default_src)
        record_image(key, default_src, label, 'picture')
        return whole.replace(opening, opening.rstrip('>') + f' data-cms-img-key="{key}">', 1)

    html = re.sub(r'(<picture\b[^>]*>)[\s\S]*?</picture>', picture_sub, html)

    def img_sub(m: re.Match) -> str:
        opening = m.group(0)
        if 'data-cms-img-key=' in opening:
            return opening
        src_m = re.search(r'\bsrc="([^"]+)"', opening)
        if not src_m:
            return opening
        src = src_m.group(1)
        if is_placeholder(src):
            return opening
        canonical = src.split('?')[0]
        label = os.path.basename(canonical)
        key = img_key(canonical)
        record_image(key, canonical, label, 'img')
        return opening.rstrip('>') + f' data-cms-img-key="{key}">'

    html = re.sub(r'<img\b[^>]*>', img_sub, html)

    def text_sub(m: re.Match) -> str:
        opening = m.group(1)
        tag = m.group(2).lower()
        inner = m.group(3)
        closing = m.group(4)
        if 'data-cms-text-key=' in opening:
            return m.group(0)
        stripped = inner.strip()
        if not stripped or len(stripped) > 1500:
            return m.group(0)
        if not SAFE_INNER_RE.match(stripped):
            return m.group(0)
        plain = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', stripped)).strip()
        if not plain:
            return m.group(0)
        key = text_key(plain)
        label = plain[:120]
        record_text(key, inner, label, tag)
        new_opening = opening.rstrip('>') + f' data-cms-text-key="{key}">'
        return f'{new_opening}{inner}{closing}'

    return TEXT_TAG_RE.sub(text_sub, html)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--site-root', required=True)
    parser.add_argument('--manifest', default=None)
    args = parser.parse_args()

    root = os.path.abspath(args.site_root)
    blocks: dict[str, dict] = {}
    touched = 0

    strip_only = 0
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
                # Non-landing file (Wolf snapshot, legacy promo). Just strip
                # any stale annotations — never add new ones.
                stripped = re.sub(r'\s*data-cms-(?:text|img)-key="[^"]*"', '', html)
                if stripped != html:
                    with open(full, 'w', encoding='utf-8') as f:
                        f.write(stripped)
                    strip_only += 1
                continue

            before = len(blocks)
            new_html = annotate_html(html, page, blocks)
            if new_html != html:
                with open(full, 'w', encoding='utf-8') as f:
                    f.write(new_html)
                touched += 1
            print(f'  {page or "/":<25} +{len(blocks) - before:<3} unique  (total unique: {len(blocks)})', flush=True)

    if strip_only:
        print(f'Stripped stale annotations from {strip_only} non-landing files.')

    print(f'\nUnique blocks: {len(blocks)} ({touched} files touched)')
    if args.manifest:
        with open(args.manifest, 'w', encoding='utf-8') as f:
            json.dump(list(blocks.values()), f, ensure_ascii=False, indent=2)
        print(f'Manifest: {args.manifest}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
