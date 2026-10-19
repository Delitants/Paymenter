"""Portable candidate tests are never Linux seccomp/launcher acceptance."""
from pathlib import Path
import platform
import shutil
import signal
import struct
import sys
import time
import subprocess
import tempfile
import unittest

HERE=Path(__file__).resolve().parent
NATIVE=HERE.parent/'native'
CONFIG=b'paymenter-fixture-offline-v0\npmtune=off\npmvalidate=off\npmusercreate=off\nfinancial=off\n'
CLANG=shutil.which('clang')
LINKER=shutil.which('ld.lld')
IS_TARGET=platform.system()=='Linux' and platform.machine()=='x86_64'


def elf_build(output,extra_sources=(),defines=()):
    command=[CLANG,'--target=x86_64-linux-gnu','-ffreestanding','-fno-builtin',
             '-fno-stack-protector','-fno-asynchronous-unwind-tables','-fno-unwind-tables',
             '-fno-pie','-nostdlib','-static','-Wl,-e,_start','-Wl,--build-id=none',
             '-fuse-ld='+LINKER, *defines, str(NATIVE/'fixture_child_x86_64.S'),
             str(NATIVE/'fixture_child.c'), *map(str,extra_sources), '-o',str(output)]
    subprocess.run(command,check=True,capture_output=True,timeout=30)


def run_open_stdin(command,timeout=2):
    """Test utility: keep stdin open until child exit (not native evidence)."""
    proc=subprocess.Popen(command,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    try:
        # Keep the stdin writer open while waiting; communicate() would close it.
        proc.wait(timeout=timeout)
        out,err=proc.communicate(timeout=timeout)
        return subprocess.CompletedProcess(command,proc.returncode,out,err)
    finally:
        # Never use Popen.__exit__: its unbounded wait defeats the test deadline.
        try:
            if proc.poll() is None:
                proc.kill()
                proc.wait(timeout=2)
        finally:
            for stream in (proc.stdin,proc.stdout,proc.stderr):
                stream.close()


class FixtureChildPortableTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='fixture-child-',dir=HERE)
        cls.addClassCleanup(cls.temp.cleanup)
        cls.binary=Path(cls.temp.name)/'portable'
        cls.compiled=None
        if (NATIVE/'fixture_child_policy.h').exists() and (NATIVE/'fixture_child.c').exists():
            cls.compiled=subprocess.run(['/usr/bin/cc','-std=c11','-Wall','-Wextra','-Werror',
                str(HERE/'fixture_child_policy_driver.c'),str(NATIVE/'fixture_child.c'),
                '-o',str(cls.binary)],capture_output=True,timeout=30)

    def setUp(self):
        self.assertIsNotNone(self.compiled,'fixture child is not implemented')
        self.assertEqual(0,self.compiled.returncode,self.compiled.stderr.decode(errors='replace'))

    def test_exact_authored_config_and_refusals(self):
        result=subprocess.run([str(self.binary),'run','--offline-config-v0'],capture_output=True,timeout=2)
        self.assertEqual((0,CONFIG,b''),(result.returncode,result.stdout,result.stderr))
        for args in ([],['--offline-config-v0','extra'],['tune'],['validate'],['createuser'],
                     ['pay'],['refund'],['async'],['unknown'],['--offline-config-v00']):
            with self.subTest(args=args):
                result=subprocess.run([str(self.binary),'run',*args],capture_output=True,timeout=2)
                self.assertEqual((64,b'',b'fixture invocation refused\n'),
                                 (result.returncode,result.stdout,result.stderr))

    def test_policy_decisions(self):
        allow=0x7fff0000;kill=0x80000000;arch=0xc000003e
        cases=[(1,arch,1,4096,allow),(1,arch,2,256,allow),(60,arch,0,0,allow),
               (231,arch,0,0,allow),(1,arch,1,4097,kill),(1,arch,2,257,kill),
               (1,arch,3,1,kill),(1,arch,1+(1<<32),1,kill),
               (1,arch,2,1<<32,kill),(1,0x40000003,1,1,kill),
               (0x40000001,arch,1,0,kill)]
        cases += [(nr,arch,0,0,kill) for nr in (0,2,41,56,57,59,157,165,257,272,308,317,322,101,105,106)]
        for nr,a,fd,count,want in cases:
            with self.subTest(nr=nr,arch=a,fd=fd,count=count):
                result=subprocess.run([str(self.binary),'policy',str(nr),str(a),str(fd),str(count)],
                                      check=True,capture_output=True,timeout=2)
                self.assertEqual(want,int(result.stdout))

    def test_stalled_child_supervisor_is_bounded(self):
        begin=time.monotonic()
        with self.assertRaises(subprocess.TimeoutExpired):
            run_open_stdin([sys.executable,'-c','import time;time.sleep(1.5)'],timeout=0.05)
        self.assertLess(time.monotonic()-begin,1.0)

    def test_setup_failure_portable_boundary(self):
        result=subprocess.run([str(self.binary),'setup-fail','--offline-config-v0'],capture_output=True,timeout=2)
        self.assertEqual((126,b'',b''),(result.returncode,result.stdout,result.stderr))

    def test_target_objects_compile(self):
        self.assertIsNotNone(CLANG, 'existing clang required; no automatic tool download')
        for source in ('fixture_child.c','fixture_child_x86_64.S'):
            target=Path(self.temp.name)/(source+'.o')
            subprocess.run([CLANG,'--target=x86_64-linux-gnu','-ffreestanding','-fno-builtin',
                '-fno-stack-protector','-fno-pie','-Wall','-Wextra','-Werror','-c',
                str(NATIVE/source),'-o',str(target)],check=True,capture_output=True,timeout=30)
            data=target.read_bytes()
            self.assertEqual(b'\x7fELF\x02\x01',data[:6])
            self.assertEqual((1,62),struct.unpack_from('<HH',data,16))
        # Relocatable objects are compiler/assembler coverage, never O2 acceptance.

    @unittest.skipUnless(CLANG and LINKER,'BLOCKED-O2: compatible ELF linker absent; no target ELF built')
    def test_elf_has_no_loader_or_initializers(self):
        output=Path(self.temp.name)/'candidate.elf'
        elf_build(output)
        data=output.read_bytes()
        self.assertEqual(b'\x7fELF\x02\x01',data[:6])
        self.assertEqual((2,62),struct.unpack_from('<HH',data,16))
        phoff,shoff=struct.unpack_from('<QQ',data,32)
        phsize,phnum,shsize,shnum,shstr=struct.unpack_from('<HHHHH',data,54)
        for i in range(phnum):
            self.assertNotIn(struct.unpack_from('<I',data,phoff+i*phsize)[0],(2,3,7))
        shstrings=shoff+shstr*shsize
        off,size=struct.unpack_from('<QQ',data,shstrings+24)
        names=data[off:off+size]
        for i in range(shnum):
            n=struct.unpack_from('<I',data,shoff+i*shsize)[0]
            name=names[n:].split(b'\0')[0]
            self.assertNotIn(name,(b'.init_array',b'.fini_array',b'.tdata',b'.tbss'))


@unittest.skipUnless(IS_TARGET and CLANG and LINKER,
    'BLOCKED-O2: actual x86-64 Linux kernel and target ELF build required')
class LinuxFixtureChildTests(unittest.TestCase):
    def test_setup_failure_x32_and_open_stdin(self):
        release=platform.release().split('-')[0].split('.')
        self.assertGreaterEqual(tuple(map(int,release[:2])),(4,14))
        with tempfile.TemporaryDirectory(prefix='fixture-linux-',dir=HERE) as tmp:
            candidate=Path(tmp)/'candidate'
            elf_build(candidate)
            proc=run_open_stdin([str(candidate),'--offline-config-v0'])
            self.assertEqual((0,CONFIG,b''),(proc.returncode,proc.stdout,proc.stderr))
            probe=Path(tmp)/'probe.c'
            probe.write_text('extern long fixture_syscall6(long,long,long,long,long,long,long);\n'
                'int fixture_child_main(long n,const char *const *v) {(void)n;(void)v;'
                'fixture_syscall6(0x40000001,1,0,0,0,0,0);return 9;}\n')
            # Rename only the candidate main in its own object, not the test probe.
            obj=Path(tmp)/'child.o'
            subprocess.run([CLANG,'--target=x86_64-linux-gnu','-ffreestanding','-fno-builtin',
                '-fno-stack-protector','-fno-pie','-Dfixture_child_main=candidate_unused_main',
                '-c',str(NATIVE/'fixture_child.c'),'-o',str(obj)],check=True,capture_output=True,timeout=30)
            result=Path(tmp)/'probe'
            subprocess.run([CLANG,'--target=x86_64-linux-gnu','-nostdlib','-static','-fno-pie',
                '-fuse-ld='+LINKER,'-Wl,-e,_start',str(NATIVE/'fixture_child_x86_64.S'),str(obj),
                str(probe),'-o',str(result)],check=True,capture_output=True,timeout=30)
            p=subprocess.run([str(result)],capture_output=True,timeout=2)
            self.assertEqual((-signal.SIGSYS,b'',b''),(p.returncode,p.stdout,p.stderr))
            # Separate entry-test image forces setup failure, without a production flag.
            probe.write_text('int fixture_install_filter(void){return -1;}\n'
                             'int fixture_child_main(long n,const char *const *v){(void)n;(void)v;return 9;}\n')
            failure=Path(tmp)/'failure'
            subprocess.run([CLANG,'--target=x86_64-linux-gnu','-nostdlib','-static','-fno-pie',
                '-fuse-ld='+LINKER,'-Wl,-e,_start',str(NATIVE/'fixture_child_x86_64.S'),
                str(probe),'-o',str(failure)],check=True,capture_output=True,timeout=30)
            p=subprocess.run([str(failure),'--offline-config-v0'],capture_output=True,timeout=2)
            self.assertEqual((126,b'',b''),(p.returncode,p.stdout,p.stderr))
