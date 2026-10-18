"""Fictional native catalog shapes; no source action is executed."""
import dataclasses
import hashlib
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from _schema import AdmissionError
try:
    import native_metadata as api
except ModuleNotFoundError as exc:
    if exc.name != 'native_metadata':
        raise
    api = None


def catalog(names=('payment.info', 'payment.setpaid', 'payment.setpaid.one')):
    entries = ''.join('<elem><action>' + name + '</action></elem>' for name in names)
    return ('<?xml version="1.0" encoding="UTF-8"?>\n'
            '<doc func="actionlist" lang="en" binary="fictional" host="fictional.invalid" '
            'themename="fictional" features="" notify="" theme="fictional">' + entries +
            '<saved_filters/><tparams><out>xml</out><func>actionlist</func></tparams></doc>').encode()


class NativeMetadataTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(api, 'bounded native catalog parser not implemented')

    def test_presence_and_companion_come_from_distinct_original_entries(self):
        raw = catalog()
        result = api.parse_action_catalog(raw)
        self.assertEqual(frozenset(('payment.info', 'payment.setpaid', 'payment.setpaid.one')),
                         result.actions)
        self.assertEqual(hashlib.sha256(raw).hexdigest(), result.sha256)
        self.assertEqual(len(raw), result.bytes)
        self.assertNotIn('paymenter.qa.transaction', result.actions)
        self.assertFalse(any(hasattr(result, name) for name in
                             ('ready', 'admitted', 'execute', 'framework_atomicity', 'manual_atomicity')))
        with self.assertRaises(dataclasses.FrozenInstanceError):
            result.actions = frozenset()

    def test_catalog_bigger_than_profile_document_is_supported_with_fixed_own_ceiling(self):
        names = tuple('fictional.action' + str(n) for n in range(3000))
        raw = catalog(names)
        self.assertGreater(len(raw), 65536)
        self.assertEqual(len(names), len(api.parse_action_catalog(raw).actions))

    def test_exit_zero_empty_and_human_descriptions_cannot_prove_presence(self):
        for raw in (b'', b'Function is not available under administrator. Try using su\n',
                    b'Payment successful\n', b'{"exit":0,"present":true}'):
            with self.subTest(raw=raw), self.assertRaises(AdmissionError):
                api.parse_action_catalog(raw)

    def test_wrong_route_error_and_financial_receipt_refuse(self):
        for raw in (catalog().replace(b'func="actionlist"', b'func="payment.setpaid"'),
                    catalog().replace(b'<func>actionlist</func>', b'<func>payment.setpaid</func>'),
                    b'<doc func="actionlist"><error type="missed"/></doc>',
                    b'<receipt purpose="legacy-payment"><paid>true</paid></receipt>'):
            with self.subTest(raw=raw[:100]), self.assertRaises(AdmissionError):
                api.parse_action_catalog(raw)

    def test_missing_empty_or_duplicate_entries_refuse(self):
        for raw in (catalog(()), catalog(('',)), catalog(('payment.setpaid', 'payment.setpaid')),
                    catalog().replace(b'<action>', b'<name>'),
                    catalog().replace(b'</action>', b'</name>')):
            with self.subTest(raw=raw[:100]), self.assertRaises(AdmissionError):
                api.parse_action_catalog(raw)

    def test_child_and_attribute_overrides_refuse(self):
        for old, new in ((b'<doc ', b'<doc ready="true" '),
                         (b'<elem>', b'<elem accepted="true">'),
                         (b'<action>', b'<action commit="true">'),
                         (b'<saved_filters/>', b'<saved_filters><accepted/></saved_filters>'),
                         (b'<out>xml</out>', b'<out>xml</out><out>xml</out>'),
                         (b'<out>xml</out>', b'<out>devel</out>'),
                         (b'<saved_filters/>', b'<success>true</success>'),
                         (b'payment.info</action>', b'payment.info<accepted/></action>')):
            with self.subTest(new=new), self.assertRaises(AdmissionError):
                api.parse_action_catalog(catalog().replace(old, new))

    def test_namespace_spoofing_and_mixed_text_refuse(self):
        for old, new in ((b'<doc ', b'<doc xmlns="https://fictional.invalid/schema" '),
                         (b'<elem>', b'<elem>accepted'),
                         (b'</elem>', b'</elem>accepted'),
                         (b'<tparams>', b'<tparams>accepted'),
                         (b'payment.info', b'payment.&#x0;info')):
            with self.subTest(new=new), self.assertRaises(AdmissionError):
                api.parse_action_catalog(catalog().replace(old, new))

    def test_names_remain_exact_and_are_never_selectors_or_wildcard_hooks(self):
        names = ('*', 'fictional.action', 'fictional.action.one')
        self.assertEqual(frozenset(names), api.parse_action_catalog(catalog(names)).actions)
        for name in ('../action', 'action, other', 'action;command', ' action', 'action ',
                     'action=other', 'a' * 193, 'action\u0430'):
            with self.subTest(name=name), self.assertRaises(AdmissionError):
                api.parse_action_catalog(catalog((name,)))

    def test_entities_doctype_processing_instructions_and_bad_encodings_refuse(self):
        for raw in (b'<!DOCTYPE doc [<!ENTITY n "payment.setpaid">]>' + catalog(),
                    catalog().replace(b'<saved_filters/>', b'<?override ready="true"?>'),
                    catalog().replace(b'<saved_filters/>', b'<!--hidden-->'),
                    catalog().decode().encode('utf-16'), b'\xff' + catalog(),
                    catalog().replace(b'UTF-8', b'ISO-8859-1')):
            with self.subTest(raw=raw[:60]), self.assertRaises(AdmissionError):
                api.parse_action_catalog(raw)

    def test_fixed_size_and_entry_limits_refuse_before_readiness(self):
        for raw in (b' ' * (1048576 + 1), catalog(tuple('a' + str(n) for n in range(8193))),
                    b'<doc func="actionlist">' + b'<a>' * 2000):
            with self.subTest(size=len(raw)), self.assertRaises(AdmissionError):
                api.parse_action_catalog(raw)

    def test_parsing_does_not_read_files_or_execute_actions(self):
        import unittest.mock as mock
        with mock.patch('builtins.open', side_effect=AssertionError('file I/O')), \
             mock.patch('subprocess.Popen', side_effect=AssertionError('process I/O')):
            self.assertIn('payment.setpaid', api.parse_action_catalog(catalog()).actions)

    def test_malformed_xml_refuses_on_python_without_sys_exception_helper(self):
        import unittest.mock as mock
        # sys.exception() was introduced after the plan's Python 3.10 minimum.
        with mock.patch.object(sys, 'exception', None, create=True):
            with self.assertRaises(AdmissionError):
                api.parse_action_catalog(b'<doc func="actionlist"><elem></doc>')


if __name__ == '__main__':
    unittest.main()
