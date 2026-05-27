#!/usr/bin/env python3
"""Annotate the static landing pages with `data-cms-*-key` attributes.

For each editable element we add a stable attribute so the PHP render
middleware can swap text/src from the CMS database at request time.

Annotation rules:
- <picture>: add `data-cms-img-key`. Render replaces ALL <source srcset>
  and <img src> inside it (the picture acts as a single editable image).
- <img>: add `data-cms-img-key`. Render replaces `src`.
- <h1>..<h6>, <p>: add `data-cms-text-key`. Render replaces innerHTML.
  We only annotate elements that contain text (no purely-structural tags).

Output:
- The HTML file is rewritten in place.
- A JSON manifest `manifest.json` listing all generated keys + defaults
  is written next to the HTML, used for DB seeding.

Idempotent: skips elements that already carry a key.
"""

import argparse
import hashlib
import json
import os
import re
import sys


TEXT_TAGS = ('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p')

# Pages we actually manage from the CMS. Wolf snapshots in site/main/
# and the legacy site/promo/* landings are NOT touched.
LANDING_PAGE_PATHS = {
    '',                # site/index.html (homepage)
    'apartments',
    'improvement',
    'infrastructure',
    'investment',
    'location',
    'management',
    'parking',
    'penthouses',
    'privacy-policy',
    'request',
    'services',
    'style',
}


def stable_key(prefix: str, content: str, counter: int) -> str:
    h = hashlib.md5(content.encode('utf-8')).hexdigest()[:8]
    return f'{prefix}-{counter:03d}-{h}'


def first_src_of_picture(picture_html: str) -> str | None:
    m = re.search(r'<img[^>]+src=["\']([^"\']+)["\']', picture_html)
    if m:
        return m.group(1).split('?')[0]
    m = re.search(r'<source[^>]+srcset=["\']([^"\',\s]+)', picture_html)
    if m:
        return m.group(1).split('?')[0]
    return None


def annotate_html(html: str, page_path: str) -> tuple[str, list[dict]]:
    manifest: list[dict] = []
    img_counter = 0
    text_counter = 0

    # 1) <picture> blocks (treat each whole <picture> as one image).
    def picture_sub(m: re.Match) -> str:
        nonlocal img_counter
        whole = m.group(0)
        opening = m.group(1)
        # already annotated?
        if 'data-cms-img-key=' in opening:
            return whole
        default_src = first_src_of_picture(whole)
        if not default_src:
            return whole
        img_counter += 1
        # Build text label from the source filename
        label = os.path.basename(default_src.split('?')[0])
        key = stable_key('img', default_src, img_counter)
        new_opening = opening.rstrip('>') + f' data-cms-img-key="{key}">'
        manifest.append({
            'type': 'image',
            'page': page_path,
            'key': key,
            'label': label,
            'default_src': default_src,
            'element': 'picture',
        })
        return whole.replace(opening, new_opening, 1)

    html = re.sub(
        r'(<picture\b[^>]*>)[\s\S]*?</picture>',
        lambda m: picture_sub(m),
        html,
    )

    # 2) <img> tags not inside <picture> (heuristic: regex over the whole doc;
    #    any img inside <picture> already got the parent attribute, we still
    #    annotate the inner <img> too for standalone fallback).
    def img_sub(m: re.Match) -> str:
        nonlocal img_counter
        opening = m.group(0)
        if 'data-cms-img-key=' in opening:
            return opening
        src_m = re.search(r'\bsrc=["\']([^"\']+)["\']', opening)
        if not src_m:
            return opening
        # Skip data: URIs and inline svgs
        src = src_m.group(1)
        if src.startswith('data:') or src.startswith('<svg') or src.startswith('http://www.w3.org'):
            return opening
        img_counter += 1
        label = os.path.basename(src.split('?')[0])
        key = stable_key('img', src, img_counter)
        manifest.append({
            'type': 'image',
            'page': page_path,
            'key': key,
            'label': label,
            'default_src': src.split('?')[0],
            'element': 'img',
        })
        return opening.rstrip('>') + f' data-cms-img-key="{key}">'

    html = re.sub(r'<img\b[^>]*>', img_sub, html)

    # 3) Text-bearing tags. Only annotate elements where the inner content
    #    is plain text (or text + <br> + <a> + <strong>/<em>) — skip if it
    #    contains nested headings, scripts, complex layouts.
    safe_inner_re = re.compile(
        r'^(?:[^<]|<br\s*/?>|<\/?(?:strong|em|b|i|u|sup|sub|nobr|wbr)\b[^>]*>|<a\b[^>]*>|</a>)*$',
        re.IGNORECASE | re.DOTALL,
    )

    def text_sub(m: re.Match) -> str:
        nonlocal text_counter
        tag = m.group(1)
        opening = m.group(2)
        inner = m.group(3)
        closing = m.group(4)
        if 'data-cms-text-key=' in opening:
            return m.group(0)
        # Skip empty / whitespace-only
        stripped = inner.strip()
        if not stripped:
            return m.group(0)
        # Skip overly long (likely complex)
        if len(stripped) > 1500:
            return m.group(0)
        # Skip if inner contains complex tags
        if not safe_inner_re.match(stripped):
            return m.group(0)
        text_counter += 1
        # Build a short label from the first 60 chars of plain text
        plain = re.sub(r'<[^>]+>', '', stripped)[:60]
        key = stable_key(f't-{tag}', plain, text_counter)
        manifest.append({
            'type': 'text',
            'page': page_path,
            'key': key,
            'label': plain,
            'default_value': inner,
            'element': tag,
        })
        new_opening = opening.rstrip('>') + f' data-cms-text-key="{key}">'
        return f'{new_opening}{inner}{closing}'

    pattern = re.compile(
        r'<((?:' + '|'.join(TEXT_TAGS) + r'))\b'           # tag name
        r'((?:[^<>]|<[^>]*>)*?)>'                          # opening attrs
        r'([\s\S]*?)'                                      # inner
        r'(</\1>)',                                        # closing
        re.IGNORECASE,
    )
    # Tweak: re-compose because we need 4 groups
    pattern2 = re.compile(
        r'(<(' + '|'.join(TEXT_TAGS) + r')\b[^>]*>)([\s\S]*?)(</\2>)',
        re.IGNORECASE,
    )
    # The above already gives 4 groups (open, tag, inner, close).
    def text_sub_v2(m: re.Match) -> str:
        nonlocal text_counter
        opening = m.group(1)
        tag = m.group(2).lower()
        inner = m.group(3)
        closing = m.group(4)
        if 'data-cms-text-key=' in opening:
            return m.group(0)
        stripped = inner.strip()
        if not stripped or len(stripped) > 1500:
            return m.group(0)
        if not safe_inner_re.match(stripped):
            return m.group(0)
        text_counter += 1
        plain = re.sub(r'<[^>]+>', '', stripped)[:60].strip()
        if not plain:
            return m.group(0)
        key = stable_key(f't-{tag}', plain, text_counter)
        manifest.append({
            'type': 'text',
            'page': page_path,
            'key': key,
            'label': plain,
            'default_value': inner,
            'element': tag,
        })
        new_opening = opening.rstrip('>') + f' data-cms-text-key="{key}">'
        return f'{new_opening}{inner}{closing}'

    html = pattern2.sub(text_sub_v2, html)

    return html, manifest


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--site-root', required=True, help='Path to the site/ directory')
    parser.add_argument('--pages', nargs='*', default=None,
                        help='Page paths to annotate (defaults to all .html below site root)')
    parser.add_argument('--manifest', default=None, help='Where to write the JSON manifest')
    args = parser.parse_args()

    root = os.path.abspath(args.site_root)
    if args.pages:
        files = []
        for p in args.pages:
            files.append((p, os.path.join(root, p.lstrip('/').lstrip(os.sep))))
    else:
        files = []
        for dirpath, _, filenames in os.walk(root):
            for fn in filenames:
                if fn.endswith('.html'):
                    full = os.path.join(dirpath, fn)
                    rel = os.path.relpath(full, root).replace(os.sep, '/')
                    # Convert to URL path: index.html -> "" (homepage); apartments/index.html -> "apartments"
                    if rel == 'index.html':
                        page = ''
                    elif rel.endswith('/index.html'):
                        page = rel[:-len('/index.html')]
                    else:
                        page = rel[:-len('.html')]
                    files.append((page, full))

    full_manifest: list[dict] = []
    for page, full in files:
        if not os.path.exists(full):
            print(f'  ! missing: {full}', file=sys.stderr)
            continue
        with open(full, encoding='utf-8') as f:
            html = f.read()
        new_html, manifest = annotate_html(html, page)
        if new_html != html:
            with open(full, 'w', encoding='utf-8') as f:
                f.write(new_html)
            print(f'  {page or "/":<25} -> {len(manifest)} keys')
        else:
            print(f'  {page or "/":<25} (unchanged)')
        full_manifest.extend(manifest)

    if args.manifest:
        with open(args.manifest, 'w', encoding='utf-8') as f:
            json.dump(full_manifest, f, ensure_ascii=False, indent=2)
        print(f'Manifest: {args.manifest} ({len(full_manifest)} entries)')

    return 0


if __name__ == '__main__':
    sys.exit(main())
