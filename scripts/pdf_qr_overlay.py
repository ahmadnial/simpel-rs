"""Place QR ink at Office-exported link markers without changing page geometry.

The placeholder stays beneath the opaque image to retain the original text metrics.
Only our marker annotations are removed; clone the complete PDF document rather
than importing bare page streams (which would discard links and outlines).
"""

import base64
import json
import math
import re
import subprocess
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

from pypdf import PdfReader, PdfWriter, Transformation
from pypdf.generic import ArrayObject, NameObject

SIZE = 65.0
GAP = 2.0
NS = {"h": "http://www.w3.org/1999/xhtml"}


class LayoutError(Exception):
    def __init__(self, page):
        self.page = page


def intersects(a, b):
    return a[0] < b[2] and a[2] > b[0] and a[1] < b[3] and a[3] > b[1]


def check_background(source, page, target, marker_box, pdftoppm):
    """Reject ink from images, table borders and drawings as well as text."""
    scale = 2  # 144 DPI; one point is two pixels.
    left, top = math.floor(target[0] * scale), math.floor(target[1] * scale)
    width = math.ceil(target[2] * scale) - left
    height = math.ceil(target[3] * scale) - top
    result = subprocess.run([
        pdftoppm, "-f", str(page), "-l", str(page), "-r", "144", "-gray", "-singlefile",
        "-x", str(left), "-y", str(top), "-W", str(width), "-H", str(height), source,
    ], check=True, capture_output=True, timeout=30)
    # Poppler emits binary PGM. No image library or lossy image conversion is needed.
    match = re.match(rb"P5\s+(\d+)\s+(\d+)\s+255[\r\n]", result.stdout)
    if not match:
        raise ValueError("Invalid background raster")
    columns, rows = map(int, match.groups())
    pixels = result.stdout[match.end():]
    if columns != width or rows != height or len(pixels) != columns * rows:
        raise LayoutError(page)
    for row in range(rows):
        y = (top + row + 0.5) / scale
        for col in range(columns):
            x = (left + col + 0.5) / scale
            if marker_box[0] <= x <= marker_box[2] and marker_box[1] <= y <= marker_box[3]:
                continue
            if pixels[row * columns + col] < 252:
                raise LayoutError(page)


def apply(source, stamp, destination, markers, pdftotext, pdftoppm):
    expected = set(json.loads(Path(markers).read_text()))
    if not expected:
        raise ValueError("Empty QR markers")
    # Poppler supplies actual glyph bounds; PDF text baselines alone are not
    # sufficient to detect a QR covering the following signer line.
    result = subprocess.run(
        [pdftotext, "-bbox", source, "-"], check=True, capture_output=True, timeout=30
    )
    boxes = ET.fromstring(result.stdout).findall(".//h:page", NS)
    reader = PdfReader(source)
    writer = PdfWriter(clone_from=reader)
    overlay = PdfReader(stamp).pages[0]
    if len(boxes) != len(writer.pages) or tuple(overlay.mediabox) != (0, 0, 65, 65):
        raise ValueError("Invalid PDF geometry")
    seen = set()
    placements = []
    warning_pages = set()
    for number, (page, words) in enumerate(zip(writer.pages, boxes), 1):
        kept = ArrayObject()
        locations = {}
        for reference in page.get("/Annots", []):
            annotation = reference.get_object()
            action = annotation.get("/A")
            uri = action.get_object().get("/URI") if action is not None else None
            if uri in expected:
                # A wrapped marker can export several rectangles. Do not stamp
                # an ambiguous location or silently omit a QR.
                if uri in locations:
                    raise LayoutError(number)
                locations[uri] = tuple(float(v) for v in annotation["/Rect"])
            else:
                kept.append(reference)
        if not locations:
            continue
        width, height = float(page.mediabox.width), float(page.mediabox.height)
        if page.rotation or tuple(page.mediabox[:2]) != (0, 0) or tuple(page.cropbox) != tuple(page.mediabox):
            raise LayoutError(number)
        occupied = []
        word_boxes = [(word.text, tuple(float(word.get(k)) for k in ("xMin", "yMin", "xMax", "yMax"))) for word in words]
        for uri, rect in locations.items():
            left, bottom, right, top = rect
            marker_box = (left, height - top, right, height - bottom)
            # Link rectangles represent the line box, while glyph bounds vary
            # by font. Match the actual QR word and center the stamp on it.
            tokens = [bounds for text, bounds in word_boxes if text in ("${qr_code}", "${barcode_tte}") and intersects(marker_box, bounds)]
            if len(tokens) != 1:
                raise LayoutError(number)
            token_box = tokens[0]
            center_x = (token_box[0] + token_box[2]) / 2
            center_y = height - (token_box[1] + token_box[3]) / 2
            x, y = center_x - SIZE / 2, center_y - SIZE / 2
            target = (x, height - y - SIZE, x + SIZE, height - y)
            padded = (target[0] - GAP, target[1] - GAP, target[2] + GAP, target[3] + GAP)
            if right <= left or top <= bottom or width < SIZE or height < SIZE:
                raise LayoutError(number)
            if top - bottom > SIZE:
                warning_pages.add(number)
            if x < 0 or y < 0 or x + SIZE > width or y + SIZE > height:
                warning_pages.add(number)
                x, y = max(0, min(x, width - SIZE)), max(0, min(y, height - SIZE))
                target = (x, height - y - SIZE, x + SIZE, height - y)
                padded = (target[0] - GAP, target[1] - GAP, target[2] + GAP, target[3] + GAP)
            # Only ignore our placeholder's glyphs, not the entire annotation.
            glyph_box = (token_box[0] - 1, token_box[1] - 1, token_box[2] + 1, token_box[3] + 1)
            for text, bounds in word_boxes:
                if not intersects(padded, bounds):
                    continue
                if bounds == token_box and text in ("${qr_code}", "${barcode_tte}"):
                    continue
                warning_pages.add(number)
            if any(intersects(padded, other) for other in occupied):
                warning_pages.add(number)
            try:
                check_background(source, number, target, glyph_box, pdftoppm)
            except LayoutError:
                warning_pages.add(number)
            page.merge_transformed_page(overlay, Transformation().translate(x, y), over=True, expand=False)
            occupied.append(target)
            seen.add(uri)
            placements.append({"page": number, "x": x, "y": y, "size": SIZE})
        page[NameObject("/Annots")] = kept
    if seen != expected:
        raise ValueError("QR marker missing from Office output")
    # ASCII metadata travels with reviewed/official copies and is covered by
    # the PDF hash. No database column or warning sidecar can become detached.
    warning_data = json.dumps(sorted(warning_pages)[:30], separators=(",", ":")).encode()
    writer.add_metadata({"/SimpelQrWarnings": base64.b64encode(warning_data).decode("ascii")})
    writer.write(destination)
    return placements


if __name__ == "__main__":
    try:
        placed = apply(*sys.argv[1:])
        print(json.dumps({"ok": True, "placements": placed}))
    except LayoutError as error:
        print(json.dumps({"ok": False, "error": "layout", "page": error.page}))
        sys.exit(2)
    except Exception as error:
        # Do not print document content, verification URLs, or filesystem paths.
        trace = error.__traceback__
        script_line = None
        while trace is not None:
            if trace.tb_frame.f_code.co_filename == __file__:
                script_line = trace.tb_lineno
            trace = trace.tb_next
        print(json.dumps({"ok": False, "error": "processing", "exception_type": type(error).__name__, "script_line": script_line}))
        sys.exit(1)
