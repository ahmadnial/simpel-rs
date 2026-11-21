import importlib.util
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('layout_audit', Path(__file__).resolve().parents[2] / 'scripts/compare_pdf_layout.py')
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)


def page(*words, y=10, width=595):
    return {'size': [width, 842], 'words': [{'text': word, 'box': [10+i*10, y, 18+i*10, y+12]} for i,word in enumerate(words)]}


class LayoutAuditTest(unittest.TestCase):
    def test_line_diagnostics_exclude_ambiguous_lines_and_locate_page_moves(self):
        a = [(('header',), 1, 1, 10), (('header',), 2, 1, 10), (('private',), 2, 2, 40)]
        b = [(('header',), 1, 1, 10), (('private',), 3, 1, 30)]
        result = audit.locate_shift(a, b)
        self.assertEqual(1, result['unique_lines_matched'])
        first = result['first_shifted_unique_line_per_reference_page'][0]
        self.assertEqual((2, 2, 3, 1), tuple(first[k] for k in ('reference_page', 'reference_line', 'candidate_page', 'candidate_line')))
        self.assertNotIn('private', str(result))

    def test_identical_text_geometry_passes(self):
        self.assertTrue(audit.compare([page('Alpha', 'Beta')], [page('Alpha', 'Beta')])['passed'])

    def test_same_page_count_does_not_hide_words_moving_pages(self):
        report = audit.compare([page('Alpha', 'Beta'), page('Gamma')], [page('Alpha'), page('Beta', 'Gamma')])
        self.assertFalse(report['passed'])
        self.assertEqual(1, report['aligned_words_on_different_pages'])

    def test_shift_above_tolerance_fails_even_with_identical_words(self):
        report = audit.compare([page('Alpha')], [page('Alpha', y=12)])
        self.assertFalse(report['passed'])
        self.assertEqual(2, report['pages'][0]['max_box_delta_pt'])

    def test_missing_page_or_word_fails(self):
        self.assertFalse(audit.compare([page('Alpha'), page('Beta')], [page('Alpha')])['passed'])
        self.assertFalse(audit.compare([page('Alpha', 'Beta')], [page('Alpha')])['passed'])

    def test_page_size_changes_fail(self):
        self.assertFalse(audit.compare([page('Alpha')], [page('Alpha', width=612)])['passed'])

    def test_scanned_or_empty_text_is_not_certified(self):
        self.assertFalse(audit.compare([page()], [page()])['passed'])

    def test_report_does_not_include_document_text(self):
        report = audit.compare([page('CONFIDENTIAL')], [page('CONFIDENTIAL')])
        self.assertNotIn('CONFIDENTIAL', str(report))

    def test_cli_refuses_to_overwrite_input_pdf(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / 'source.pdf'
            original = b'%PDF-1.4 protected source'
            source.write_bytes(original)
            result = subprocess.run([sys.executable, str(Path(audit.__file__)), str(source), str(source),
                                     '--output', str(source)], capture_output=True, check=False)
            self.assertEqual(2, result.returncode)
            self.assertEqual(original, source.read_bytes())


if __name__ == '__main__':
    unittest.main()
