"""Run: .venv-pdf/bin/python -m unittest discover -s tests/Integration -p 'test_pdf_*.py'."""

import base64
import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from pypdf import PdfReader, PdfWriter
from pypdf.generic import (
    ArrayObject, DecodedStreamObject, DictionaryObject, FloatObject,
    NameObject, NumberObject, TextStringObject,
)

spec = importlib.util.spec_from_file_location("overlay", Path(__file__).resolve().parents[2] / "scripts/pdf_qr_overlay.py")
overlay = importlib.util.module_from_spec(spec)
spec.loader.exec_module(overlay)
MARKER = "https://simpel-preview.invalid/qr/" + "a" * 32 + "/0"
DATE = "https://simpel-preview.invalid/variable/tanggal_ttd"


class PdfQrOverlayTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="simpel_qr_test_")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.source, self.stamp, self.output, self.markers = (self.root / n for n in ["source.pdf", "stamp.pdf", "output.pdf", "markers.json"])
        self.markers.write_text(json.dumps([MARKER]))
        writer = PdfWriter()
        page = writer.add_blank_page(65, 65)
        stream = DecodedStreamObject()
        stream.set_data(b"1 1 1 rg 0 0 65 65 re f 0 0 0 rg 5 5 55 55 re f")
        page[NameObject("/Contents")] = writer._add_object(stream)
        writer.write(self.stamp)

    def fixture(self, *, text="", rect=(100, 397, 157, 413), repeat=False, duplicate=False, rotation=0, token_x=100):
        writer = PdfWriter()
        writer.add_metadata({"/Title": "QC document", "/Author": "Test"})
        for i in range(2 if repeat else 1):
            page = writer.add_blank_page(500 if i else 300, 500)
            font = DictionaryObject({NameObject("/Type"): NameObject("/Font"), NameObject("/Subtype"): NameObject("/Type1"), NameObject("/BaseFont"): NameObject("/Times-Roman")})
            page[NameObject("/Resources")] = DictionaryObject({NameObject("/Font"): DictionaryObject({NameObject("/F1"): writer._add_object(font)})})
            stream = DecodedStreamObject()
            stream.set_data((("1 1 1 rg BT /F1 12 Tf %s 400 Td (${qr_code}) Tj ET\n0 0 0 rg\n" % token_x) + text).encode())
            page[NameObject("/Contents")] = writer._add_object(stream)
            page[NameObject("/Rotate")] = NumberObject(rotation)
            annotations = ArrayObject()
            for uri in [MARKER, DATE, "https://example.test/reference"] + ([MARKER] if duplicate else []):
                annotation = DictionaryObject({
                    NameObject("/Type"): NameObject("/Annot"), NameObject("/Subtype"): NameObject("/Link"),
                    NameObject("/Rect"): ArrayObject([FloatObject(v) for v in rect]),
                    NameObject("/Border"): ArrayObject([NumberObject(0), NumberObject(0), NumberObject(0)]),
                    NameObject("/A"): DictionaryObject({NameObject("/S"): NameObject("/URI"), NameObject("/URI"): TextStringObject(uri)}),
                })
                annotations.append(writer._add_object(annotation))
            page[NameObject("/Annots")] = annotations
        writer.add_outline_item("Chapter", 0)
        writer.write(self.source)

    def run_overlay(self):
        return overlay.apply(str(self.source), str(self.stamp), str(self.output), str(self.markers), "pdftotext", "pdftoppm")

    def test_preserves_pages_text_links_metadata_and_outline(self):
        self.fixture(repeat=True)
        placements = self.run_overlay()
        before, after = PdfReader(self.source), PdfReader(self.output)
        self.assertEqual(2, len(placements))
        self.assertEqual(2, len(after.pages))
        self.assertEqual("QC document", after.metadata.title)
        self.assertEqual("Chapter", after.outline[0].title)
        for a, b in zip(before.pages, after.pages):
            self.assertEqual(a.mediabox, b.mediabox)
            self.assertEqual(a.extract_text(), b.extract_text())
            self.assertEqual([DATE, "https://example.test/reference"], [r.get_object()["/A"]["/URI"] for r in b["/Annots"]])
            self.assertEqual(65, placements[0]["size"])

    def warning_pages(self):
        return json.loads(base64.b64decode(PdfReader(self.output).metadata["/SimpelQrWarnings"]))

    def test_overlapping_signer_text_renders_with_a_persistent_warning(self):
        self.fixture(text="BT /F1 12 Tf 100 380 Td (Signer) Tj ET")
        self.run_overlay()
        self.assertEqual([1], self.warning_pages())

    def test_rejects_two_rectangles_for_a_wrapped_placeholder(self):
        self.fixture(duplicate=True)
        with self.assertRaises(overlay.LayoutError):
            self.run_overlay()

    def test_drawing_that_would_be_covered_adds_a_warning(self):
        self.fixture(text="0 0 0 rg 100 375 30 5 re f")
        self.run_overlay()
        self.assertEqual([1], self.warning_pages())

    def test_rejects_marker_that_does_not_match_the_placeholder(self):
        self.fixture(rect=(0, 397, 20, 413))
        with self.assertRaises(overlay.LayoutError):
            self.run_overlay()

    def test_oversized_marker_warns_instead_of_resizing_qr(self):
        self.fixture(rect=(100, 397, 155, 480))
        placed = self.run_overlay()
        self.assertEqual(65, placed[0]["size"])
        self.assertEqual([1], self.warning_pages())

    def test_qr_near_page_edge_is_kept_inside_page_with_warning(self):
        self.fixture(rect=(1, 397, 58, 413), token_x=1)
        placed = self.run_overlay()
        self.assertEqual(0, placed[0]["x"])
        self.assertEqual([1], self.warning_pages())

    def test_rejects_missing_marker(self):
        self.fixture()
        self.markers.write_text(json.dumps([MARKER + "missing"]))
        with self.assertRaises(ValueError):
            self.run_overlay()

    def test_rejects_unexpected_rotation(self):
        self.fixture(rotation=90)
        with self.assertRaises(overlay.LayoutError):
            self.run_overlay()

    def test_runtime_error_reports_type_without_paths_or_document_content(self):
        self.fixture()
        result = subprocess.run([
            sys.executable, str(spec.origin), str(self.source), str(self.stamp),
            str(self.output), str(self.markers), "/missing-private-path/pdftotext", "pdftoppm",
        ], capture_output=True, text=True)
        self.assertEqual(1, result.returncode)
        error = json.loads(result.stdout)
        self.assertEqual("FileNotFoundError", error["exception_type"])
        self.assertIsInstance(error["script_line"], int)
        self.assertNotIn("missing-private-path", result.stdout)
        self.assertNotIn(MARKER, result.stdout)
        self.assertFalse(self.output.exists())


if __name__ == "__main__":
    unittest.main()
