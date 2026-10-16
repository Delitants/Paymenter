"""Pure identity/domain comparisons. No native freezer/controller acceptance."""
from pathlib import Path
import copy
import importlib
import sys
import unittest
from dataclasses import asdict
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from containment_schema import (ControlContext, EvidenceRef, ContainmentError,
    canonical, digest)
from containment_runtime import OwnedContext


def process_fixture(ctx):
    def task(pid, tid, parent, group, role):
        return {'pid': pid, 'tid': tid, 'start_ticks': tid*10,
            'leader_start_ticks': pid*10, 'executable_sha256': 'e'*64,
            'uid': 1000, 'gid': 1000, 'parent_pid': parent,
            'parent_start_ticks': parent*10, 'cgroup': group, 'role': role}
    return {'schema_version': 1, 'purpose': 'source-containment-process-preflight',
        'context_sha256': digest(canonical(asdict(ctx))), 'boot_id': ctx.source_boot_id,
        'kernel_sha256': 'f'*64, 'controller': 'cgroup-v1-freezer',
        'domain': '/owned/selected', 'groups': [
            {'path': '/owned/selected', 'state': 'FROZEN'},
            {'path': '/owned/selected/child', 'state': 'FROZEN'}],
        'tasks': [task(1, 1, 0, '/other', 'unrelated'),
            task(20, 20, 1, '/owned/selected', 'selected'),
            task(20, 21, 1, '/owned/selected', 'selected'),
            task(30, 30, 20, '/owned/selected/child', 'selected'),
            task(40, 40, 1, '/other', 'unrelated')], 'unresolved': []}


class ProcessPreflightTests(unittest.TestCase):
    def setUp(self):
        try: self.a = importlib.import_module('source_process_adapter')
        except ModuleNotFoundError: self.fail('process identity preflight is not implemented')
        self.ctx = ControlContext('fictional', 'freeze', 1, 'fictional-boot', 'a'*64,
            'b'*64, EvidenceRef('/private/authority', 'c'*64, 1),
            EvidenceRef('/private/budget', 'd'*64, 1))
        self.before = process_fixture(self.ctx); self.after = copy.deepcopy(self.before)

    def inspect(self):
        return self.a.inspect_process_domain(canonical(self.before), canonical(self.after), self.ctx)

    def test_complete_claim_still_unqualified(self):
        result = self.inspect()
        self.assertEqual(result.blockers, ('process-native-runtime-unqualified',))
        self.assertFalse(result.real_execution_ready)
        self.assertEqual(result.observation_refs, ())

    def test_freezing_and_nested_thawed_not_held(self):
        for index, state in ((0, 'FREEZING'), (1, 'THAWED')):
            self.after = copy.deepcopy(self.before)
            self.after['groups'][index]['state'] = state
            with self.subTest(state=state):
                self.assertIn('process-effective-freeze-unproved', self.inspect().blockers)

    def test_thread_pid_reuse_and_escaped_child_detected(self):
        self.after['tasks'][2]['start_ticks'] += 1
        self.assertIn('process-identity-changed', self.inspect().blockers)
        self.after['tasks'][3]['cgroup'] = '/outside'
        self.assertIn('process-escaped-task', self.inspect().blockers)

    def test_new_child_or_thread_invalidates_prior_comparison(self):
        row = copy.deepcopy(self.after['tasks'][2]); row['tid'] = 22
        self.after['tasks'].append(row)
        self.assertIn('process-membership-changed', self.inspect().blockers)
        self.after['tasks'][-1]['role'] = 'unrelated'
        self.assertIn('process-shared-thread-domain', self.inspect().blockers)

    def test_unattributed_descendant_and_unknown_task_block(self):
        self.after['tasks'][3]['role'] = 'unrelated'
        self.assertIn('process-descendant-scope', self.inspect().blockers)
        self.after['tasks'][3]['role'] = 'unknown'
        self.assertIn('process-unattributed-task', self.inspect().blockers)

    def test_old_boot_or_context_never_reusable(self):
        self.after['boot_id'] = 'new-boot'
        self.assertIn('process-boot-mismatch', self.inspect().blockers)
        self.after['context_sha256'] = '0'*64
        with self.assertRaisesRegex(ContainmentError, 'process-context-drift'): self.inspect()

    def test_unrelated_identity_change_is_not_selected_work(self):
        self.after['tasks'][-1]['start_ticks'] += 1
        self.after['tasks'][-1]['leader_start_ticks'] += 1
        self.assertEqual(self.inspect().blockers, ('process-native-runtime-unqualified',))

    def test_missing_leader_ancestry_and_member_group_block(self):
        self.after['tasks'].pop(1)
        result = self.inspect()
        self.assertIn('process-thread-leader-missing', result.blockers)
        self.assertIn('process-ancestry-unproved', result.blockers)
        self.after = copy.deepcopy(self.before); self.after['groups'].pop()
        self.assertIn('process-group-coverage', self.inspect().blockers)

    def test_malformed_duplicate_root_and_wrong_controller_reject(self):
        for mutate in (lambda v: v['tasks'].append(v['tasks'][0]),
                       lambda v: v.update(domain='/'),
                       lambda v: v.update(controller='cgroup-v2'),
                       lambda v: v['tasks'][1].update(pid=True),
                       lambda v: v['groups'][0].update(state='stopped'),
                       lambda v: v.update(shell='echo FROZEN')):
            self.after = copy.deepcopy(self.before); mutate(self.after)
            with self.subTest(value=self.after):
                with self.assertRaises(ContainmentError): self.inspect()

    def test_physical_apis_refuse_without_inspecting_or_mutating_processes(self):
        ctx = object.__new__(OwnedContext)
        for call in (lambda: self.a.establish_process(ctx), lambda: self.a.read_process(ctx),
                     lambda: self.a.recover_process(ctx),
                     lambda: self.a.release_process(ctx, None)):
            with self.assertRaisesRegex(ContainmentError, '^process-native-runtime-unqualified$'):
                call()

    def test_global_tid_collision_rejects_malformed_census(self):
        self.after['tasks'][-1]['tid'] = 21
        with self.assertRaisesRegex(ContainmentError, 'process-duplicate-task'):
            self.inspect()

    def test_combined_census_entry_budget_rejects(self):
        self.before['unresolved'] = ['unproved']*6000
        self.after['unresolved'] = ['unproved']*6000
        with self.assertRaisesRegex(ContainmentError, 'reference-budget'): self.inspect()


if __name__ == '__main__': unittest.main()
