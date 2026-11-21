#!/usr/bin/env python3
"""Compare Poppler text geometry against an independent PDF reference.

No document text is written to the report. This is a text-layout gate, not a
visual/image or semantic-content certification. Exit 1 means the gate failed.
"""
import argparse
from collections import Counter
import difflib
import hashlib
import json
import math
from pathlib import Path
import subprocess
import sys
import xml.etree.ElementTree as ET


def geometry(path):
    result = subprocess.run(['pdftotext', '-bbox', str(path), '-'], capture_output=True, check=True, timeout=90)
    root = ET.fromstring(result.stdout)
    pages = []
    for page in root.iter('{http://www.w3.org/1999/xhtml}page'):
        words = [{'text': w.text or '', 'box': [float(w.attrib[a]) for a in ('xMin', 'yMin', 'xMax', 'yMax')]}
                 for w in page.iter('{http://www.w3.org/1999/xhtml}word')]
        pages.append({'size': [float(page.attrib[a]) for a in ('width', 'height')], 'words': words})
    if not pages:
        raise ValueError('PDF has no parsed pages')
    return pages


def line_locations(path):
    result = subprocess.run(['pdftotext', '-bbox-layout', str(path), '-'], capture_output=True, check=True, timeout=90)
    root = ET.fromstring(result.stdout)
    lines = []
    for page_no, page in enumerate(root.findall('.//{*}page'), 1):
        for line_no, line in enumerate(page.findall('.//{*}line'), 1):
            words = tuple(word.text or '' for word in line.findall('{*}word'))
            if words:
                lines.append((words, page_no, line_no, float(line.attrib['yMin'])))
    return lines


def locate_shift(reference, candidate, tolerance=1.0):
    """Match only unique complete lines. Rewrapped and repeated lines are excluded."""
    counts = Counter(line[0] for line in reference)
    other_counts = Counter(line[0] for line in candidate)
    positions = {line[0]: line for line in candidate if other_counts[line[0]] == 1}
    matched = 0
    per_page = {}
    for words, page, number, y in reference:
        if counts[words] != 1 or words not in positions:
            continue
        matched += 1
        _, other_page, other_number, other_y = positions[words]
        if page == other_page and abs(y - other_y) <= tolerance:
            continue
        per_page.setdefault(page, {'reference_page': page, 'reference_line': number, 'reference_y_pt': y,
                                   'candidate_page': other_page, 'candidate_line': other_number,
                                   'candidate_y_pt': other_y})
    return {'unique_lines_matched': matched, 'first_shifted_unique_line_per_reference_page': list(per_page.values())}


def compare(reference, candidate, tolerance=1.0):
    """Strict per-page acceptance plus diagnostic global word alignment."""
    page_details = []
    strict = len(reference) == len(candidate)
    for index in range(max(len(reference), len(candidate))):
        a = reference[index] if index < len(reference) else None
        b = candidate[index] if index < len(candidate) else None
        same = a is not None and b is not None and [w['text'] for w in a['words']] == [w['text'] for w in b['words']]
        delta = max((abs(x-y) for aw, bw in zip(a['words'], b['words']) for x,y in zip(aw['box'],bw['box'])), default=0) if same else None
        dimensions = max(abs(x-y) for x,y in zip(a['size'],b['size'])) if a is not None and b is not None else None
        passed = same and delta <= tolerance and dimensions <= 0.2
        strict = strict and passed
        page_details.append({'page': index+1, 'word_sequence_equal': same, 'max_box_delta_pt': delta,
                             'page_size_delta_pt': dimensions, 'passed': passed})
    a = [(i+1,w) for i,p in enumerate(reference) for w in p['words']]
    b = [(i+1,w) for i,p in enumerate(candidate) for w in p['words']]
    matcher = difflib.SequenceMatcher(None, [w['text'] for _,w in a], [w['text'] for _,w in b], autojunk=False)
    matched = moved = 0
    same_page_deltas = []
    for start_a,start_b,length in matcher.get_matching_blocks():
        matched += length
        for offset in range(length):
            pa,wa = a[start_a+offset]
            pb,wb = b[start_b+offset]
            if pa != pb:
                moved += 1
            else:
                same_page_deltas.append(max(abs(x-y) for x,y in zip(wa['box'],wb['box'])))
    same_page_deltas.sort()
    return {'passed': bool(strict and a and b), 'tolerance_pt': tolerance, 'reference_pages': len(reference), 'candidate_pages': len(candidate),
            'reference_words': len(a), 'candidate_words': len(b), 'aligned_word_pairs': matched, 'aligned_words_on_different_pages': moved,
            'same_page_aligned_box_delta_p95_pt': same_page_deltas[max(0,math.ceil(len(same_page_deltas)*0.95)-1)] if same_page_deltas else None,
            'pages': page_details,
            'limitations': 'Text extraction/alignment only. Unmatched tokens do not prove lost content. Images, shapes, overlap and visual appearance require independent inspection.'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('reference', type=Path)
    parser.add_argument('candidate', type=Path)
    parser.add_argument('--output', required=True, type=Path)
    args = parser.parse_args()
    paths = [('reference', args.reference), ('candidate', args.candidate)]
    if any(args.output.resolve() == path.resolve() or
           (args.output.exists() and path.exists() and args.output.samefile(path))
           for _, path in paths):
        raise ValueError('Output report must not overwrite either input PDF')
    before = {name: hashlib.sha256(path.read_bytes()).hexdigest() for name, path in paths}
    report = compare(geometry(args.reference), geometry(args.candidate))
    report['line_diagnostics'] = locate_shift(line_locations(args.reference), line_locations(args.candidate))
    after = {name: hashlib.sha256(path.read_bytes()).hexdigest() for name, path in paths}
    if before != after:
        raise ValueError('PDF changed during measurement; no audit report written')
    report['sha256'] = after
    report['pdftotext_version'] = subprocess.run(['pdftotext','-v'],capture_output=True,text=True,check=True,timeout=10).stderr.splitlines()[0]
    args.output.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k not in ('pages','limitations','line_diagnostics')},indent=2))
    return 0 if report['passed'] else 1


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (OSError, ValueError, ET.ParseError, subprocess.SubprocessError) as error:
        print('Layout audit could not run: ' + str(error), file=sys.stderr)
        raise SystemExit(2)
