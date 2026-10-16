"""Pure byte/preflight tests only; these never qualify Cronie or spool mutation."""
from pathlib import Path
import importlib
import sys
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from containment_schema import ContainmentError, digest
from containment_runtime import OwnedContext


class SchedulerPreflightTests(unittest.TestCase):
    def setUp(self):
        try: self.a = importlib.import_module('source_scheduler_adapter')
        except ModuleNotFoundError: self.fail('scheduler byte projection is not implemented')
        self.job = b'*/5 * * * * /fixture/selected --once\r\n'
        self.prefix = b'# untouched\r\nSHELL=/bin/sh\r\n\r\n'
        self.suffix = b'2 * * * * /fixture/unrelated\n# missing DNSSEC target retained\n'
        self.raw = self.prefix+self.job+self.suffix
        self.span = (len(self.prefix), len(self.prefix+self.job), digest(self.job))

    def entry(self, **changes):
        return self.a.ReviewedEntry(*self.span, 'selected-job', 'selected-writer',
                                    **{'shared': False, 'unresolved': (), **changes})

    def compile(self, entries=None, raw=None, **changes):
        return self.a.compile_suppression(self.raw if raw is None else raw,
            digest(self.raw), (self.entry(),) if entries is None else entries,
            **{'exclusive_maintenance': True, 'review_sha256': 'e'*64, **changes})

    def test_unrelated_bytes_newlines_environment_preserved(self):
        self.assertEqual(self.a.suppress_entries(self.raw, ()), self.raw)
        transformed = self.compile()
        self.assertEqual(transformed[:self.span[0]], self.raw[:self.span[0]])
        self.assertEqual(transformed[self.span[0]:], self.raw[self.span[1]:])
        self.assertEqual(transformed, self.prefix+self.suffix)

    def test_shared_and_unresolved_wrapper_block(self):
        for entry in (self.entry(shared=True), self.entry(unresolved=('wrapper-semantics',))):
            with self.subTest(entry=entry):
                with self.assertRaisesRegex(ContainmentError, 'scheduler-scope-unproved'):
                    self.compile((entry,))

    def test_changed_bytes_and_missing_maintenance_block(self):
        with self.assertRaisesRegex(ContainmentError, 'scheduler-original-drift'):
            self.compile(raw=self.raw+b'# competing edit\n')
        with self.assertRaisesRegex(ContainmentError, 'maintenance-unaccepted'):
            self.compile(exclusive_maintenance=False)
        with self.assertRaises(ContainmentError): self.compile(review_sha256='')

    def test_invalid_overlap_hash_boundary_and_boolean_rejected(self):
        bad = [((self.span, self.span)), ((self.span[0]+1, self.span[1], self.span[2]),),
               ((self.span[0], self.span[1], 'a'*64),), ((True, self.span[1], self.span[2]),),
               ((self.span[0], self.span[1]-1, digest(self.job[:-1])),), []]
        for spans in bad:
            with self.subTest(spans=spans):
                with self.assertRaises(ContainmentError): self.a.suppress_entries(self.raw, spans)

    def test_environment_comment_shell_and_continuation_rejected(self):
        for line in (b'SHELL=/bin/sh\n', b'# comment\n', b'\n',
                     b'* * * * * /fixture/selected; /fixture/shared\n',
                     b'* * * * * /fixture/selected \\\n',
                     b'* * * * * /fixture/selected $DYNAMIC\n',
                     b'* * * * * /fixture/selected%stdin\n',
                     b'* * * * * /fixture/selected\x00\n'):
            with self.subTest(line=line):
                with self.assertRaisesRegex(ContainmentError, 'scheduler-entry-syntax'):
                    self.a.suppress_entries(line, ((0, len(line), digest(line)),))

    def test_multifile_partial_and_competing_classification_is_read_only(self):
        before = (self.raw, self.raw)
        installed = (self.compile(), self.compile())
        current = (installed[0], before[1])
        self.assertEqual(self.a.classify_scheduler_files(before, installed, current),
                         ('suppressed', 'original'))
        competing = (installed[0], self.raw+b'# editor\n')
        self.assertEqual(self.a.classify_scheduler_files(before, installed, competing),
                         ('suppressed', 'competing'))
        self.assertEqual(current[1], self.raw)

    def test_unqualified_physical_apis_refuse_without_resource_access(self):
        # Deliberately uninitialized, so ANY resource access fails this test.
        ctx = object.__new__(OwnedContext)
        for call in (lambda: self.a.establish_scheduler(ctx, ()),
                     lambda: self.a.recover_scheduler(ctx),
                     lambda: self.a.release_scheduler(ctx, None)):
            with self.assertRaisesRegex(ContainmentError, '^scheduler-native-runtime-unqualified$'):
                call()

    def test_empty_review_and_duplicate_object_rejected(self):
        self.assertEqual(self.compile(()), self.raw)
        with self.assertRaises(ContainmentError): self.compile((self.entry(), self.entry()))

    def test_preceding_continuation_cannot_assign_new_entry(self):
        for ending in (b'\\\n', b'\\\r\n'):
            prefix = b'* * * * * /fixture/shared '+ending
            raw = prefix+self.job
            with self.subTest(ending=ending):
                with self.assertRaisesRegex(ContainmentError, 'scheduler-entry-boundary'):
                    self.a.suppress_entries(raw, ((len(prefix), len(raw), digest(self.job)),))

    def test_combined_file_entry_budget_rejects(self):
        with self.assertRaisesRegex(ContainmentError, 'scheduler-graph-size'):
            self.a.classify_scheduler_files((b'',)*4000, (b'',)*4000, (b'',)*4000)


if __name__ == '__main__': unittest.main()
