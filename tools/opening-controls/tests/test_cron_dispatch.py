"""Fictional cron input: parsing must not merge distinct manager boundaries."""
import sys
from pathlib import Path
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from source_inventory import parse_cron,inventory
from source_fixtures import raw_capture,file_record,coverage_profile
from source_coverage import evaluate


class CronDispatchTest(unittest.TestCase):
    def job(self,command,path='/fixture/root'):
        return parse_cron(('* * * * * '+command+'\n').encode(),path)[0]['details']

    def test_cross_module_wrapper_preserves_both_boundaries(self):
        for wrapper,main in [('core','billmgr'),('billmgr','core')]:
            with self.subTest(wrapper=wrapper):
                d=self.job('/fixture/sbin/cron-'+wrapper+' sbin/mgrctl -m '+main+' task')
                self.assertIn('cron_dispatch',d)
                dispatch=d['cron_dispatch']
                self.assertEqual(d['module'],main)
                self.assertEqual(dispatch['wrapper_module_candidate'],wrapper)
                self.assertEqual(dispatch['forwarded_module_candidate'],main)
                self.assertEqual(dispatch['forwarded_executable_candidate'],'/fixture/sbin/mgrctl')
                self.assertEqual(dispatch['conditional_side_effect_module_candidates'],{'license_guard':wrapper,'error_registration':wrapper})
                self.assertFalse(dispatch['semantics_verified'])
                self.assertIn('cron-wrapper-semantics-unproved',dispatch['blockers'])

    def test_redirection_keeps_literal_prefix_candidate_without_acceptance(self):
        for wrapper,main in [('core','billmgr'),('billmgr','core')]:
            with self.subTest(wrapper=wrapper):
                d=self.job('/fixture/sbin/cron-'+wrapper+' sbin/mgrctl -m '+main+' task >/fixture/log 2>&1')
                self.assertEqual(d['cron_dispatch']['forwarded_module_candidate'],main)
                self.assertEqual(d['cron_dispatch']['wrapper_module_candidate'],wrapper)
                self.assertEqual(d['module'],'')
                self.assertIn('cron-shell-expansion-unproved',d['cron_dispatch']['blockers'])
                self.assertFalse(d['cron_dispatch']['semantics_verified'])
        for command in ['/fixture/sbin/cron-core $ROOT/sbin/mgrctl -m billmgr task >/fixture/log',
                        '/fixture/sbin/cron-core "/fixture/my bin/mgrctl" -m billmgr task',
                        '/fixture/$ROOT/sbin/cron-core sbin/mgrctl -m billmgr task']:
            with self.subTest(command=command):
                d=self.job(command)
                self.assertEqual(d['cron_dispatch']['forwarded_module_candidate'],'')
                self.assertEqual(d['cron_dispatch']['forwarded_executable_candidate'],'')
                self.assertEqual(d['module'],'')
                self.assertTrue(d['cron_dispatch']['blockers'])

    def test_wrapper_suffix_does_not_assign_forwarded_updater(self):
        d=self.job('/fixture/sbin/cron-billmgr sbin/pkgupdate.sh')
        self.assertEqual(d['module'],'')
        self.assertIn('cron_dispatch',d)
        self.assertEqual(d['cron_dispatch']['forwarded_command_tokens'],['sbin/pkgupdate.sh'])
        self.assertEqual(d['cron_dispatch']['forwarded_module_candidate'],'')
        self.assertIn('cron-forwarded-command-unproved',d['cron_dispatch']['blockers'])

    def test_later_option_and_argument_basenames_cannot_select_manager(self):
        for command in ['/fixture/notify cron-billmgr -m core',
                        '/fixture/sbin/cron-core sbin/mgrctl task -m billmgr']:
            with self.subTest(command=command):
                d=self.job(command)
                self.assertEqual(d['module'],'')
                self.assertIn('cron_dispatch',d)
                self.assertEqual(d['cron_dispatch']['forwarded_module_candidate'],'')

    def test_unresolved_wrappers_and_shell_forms_remain_blocked(self):
        for command in ['/fixture/sbin/cron-other sbin/mgrctl -m billmgr task',
                        '/fixture/sbin/cron-core',
                        '/fixture/sbin/cron-core sbin/mgrctl -m billmgr task; /fixture/other',
                        '/fixture/sbin/cron-core sbin/mgrctl -m $MANAGER task',
                        '/fixture/sbin/cron-core sbin/mgrctl -m core -m billmgr task',
                        '/fixture/sbin/cron-core ../mgrctl -m core task']:
            with self.subTest(command=command):
                d=self.job(command)
                self.assertIn('cron_dispatch',d)
                self.assertTrue(d['cron_dispatch']['blockers'])
                self.assertFalse(d['cron_dispatch']['semantics_verified'])
                self.assertEqual(d['module'],'')

    def test_system_cron_user_and_reboot_form_do_not_become_executable(self):
        for path in ['/etc/crontab','/etc/cron.d/fixture']:
            with self.subTest(path=path):
                d=self.job('root /fixture/sbin/cron-core sbin/mgrctl -m billmgr task',path)
                self.assertIn('cron_dispatch',d)
                self.assertEqual(d['user'],'root')
                self.assertEqual(d['cron_dispatch']['executable'],'/fixture/sbin/cron-core')
                self.assertEqual(d['module'],'billmgr')
        d=parse_cron(b'@reboot root /fixture/sbin/cron-core sbin/mgrctl -m core task\n','/etc/crontab')[0]['details']
        self.assertEqual(d['module'],'core')

    def test_identical_jobs_keep_distinct_identity_and_boundary_hashes(self):
        line=b'* * * * * /fixture/sbin/cron-core sbin/mgrctl -m billmgr task\n'
        jobs=parse_cron(line+line,'/fixture/root')
        self.assertNotEqual(jobs[0]['id'],jobs[1]['id'])
        self.assertNotEqual(jobs[0]['sha256'],jobs[1]['sha256'])
        self.assertIn('cron_dispatch',jobs[0]['details'])
        self.assertEqual(jobs[0]['details']['cron_dispatch'],jobs[1]['details']['cron_dispatch'])

    def test_unverified_dispatch_blocks_even_an_exact_coverage_assignment(self):
        raw=raw_capture();raw['files'][1]=file_record('/fixture/root','cron',b'* * * * * /fixture/sbin/cron-core sbin/mgrctl -m billmgr task\n')
        obs=inventory(raw,{'controller_boot_id':'fixture-controller-boot','capture_start_ns':0,'capture_end_ns':1_000_000_000})
        self.assertIn('cron-wrapper-semantics-unproved',{e['code'] for e in obs['errors']})
        report=evaluate(obs,coverage_profile(obs),'fixture-controller-boot',2_000_000_000)
        self.assertFalse(report['inventory_complete'])
        self.assertFalse(report['real_execution_ready'])

    def test_shell_and_unknown_direct_commands_never_complete_coverage(self):
        for command in ['/bin/sh -c "/fixture/sbin/mgrctl -m core task"',
                        '/fixture/unknown task',
                        '/fixture/bin/mgrctl -m core task']:
            with self.subTest(command=command):
                raw=raw_capture();raw['files'][1]=file_record('/fixture/root','cron',('* * * * * '+command+'\n').encode())
                obs=inventory(raw,{'controller_boot_id':'fixture-controller-boot','capture_start_ns':0,'capture_end_ns':1_000_000_000})
                self.assertIn('cron-command-semantics-unproved',{e['code'] for e in obs['errors']})
                report=evaluate(obs,coverage_profile(obs),'fixture-controller-boot',2_000_000_000)
                self.assertFalse(report['inventory_complete'])
                self.assertFalse(report['real_execution_ready'])


if __name__=='__main__':unittest.main()
