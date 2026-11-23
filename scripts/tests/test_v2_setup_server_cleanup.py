"""Exercise actual acceptance server cleanup on Linux, not mocked green results."""
from __future__ import annotations

import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[2]
SH = ROOT / 'scripts/validate-fresh-setup.sh'


@unittest.skipUnless(sys.platform.startswith('linux') and Path('/proc/self/task').is_dir(),
                     'Acceptance process-tree cleanup requires Linux /proc')
class WebInstallerServerCleanupTests(unittest.TestCase):
    def get_stop_functions(self) -> str:
        source = SH.read_text(encoding='utf-8')
        start = source.index('stop_server_descendants() {')
        end = source.index('\n\nwait_for_http() {', start)
        return source[start:end]

    def test_terminates_only_owned_artisan_parent_and_listener_child(self):
        script = self.get_stop_functions()
        with tempfile.TemporaryDirectory() as d:
            folder = Path(d)
            pidfile = folder / 'server.pid'
            parent = subprocess.Popen(['bash', '-c', 'sleep 60 & wait'],
                                      stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            try:
                pidfile.write_text(str(parent.pid), encoding='utf-8')
                children_file = Path(f'/proc/{parent.pid}/task/{parent.pid}/children')
                children = []
                for _ in range(100):
                    if children_file.exists():
                        children = children_file.read_text().split()
                    if children:
                        break
                    time.sleep(0.02)
                self.assertTrue(children, 'Fixture must have a genuine child process')
                completed = subprocess.run(['bash', '-e', '-c', script + '\nstop_server "$1"',
                                            'acceptance-test', str(pidfile)],
                                           capture_output=True, text=True, timeout=15)
                self.assertEqual(0, completed.returncode, completed.stderr)
                self.assertFalse(pidfile.exists(), 'Only successful cleanup removes PID file')
                parent.wait(timeout=3)
                self.assertIsNotNone(parent.returncode)
                for child in children:
                    if Path(f'/proc/{child}/status').exists():
                        status = Path(f'/proc/{child}/status').read_text()
                        self.assertRegex(status, r'^State:\s+Z',
                                         f'Owned listener PID {child} must not remain running',
                                         flags=re.MULTILINE)
            finally:
                if parent.poll() is None:
                    parent.kill()
                    parent.wait(timeout=5)

    def test_rejects_unsafe_pid_without_signalling(self):
        script = self.get_stop_functions()
        for value in ('0', '-1', 'abc', '1'):
            with self.subTest(pid=value), tempfile.TemporaryDirectory() as d:
                pidfile = Path(d) / 'server.pid'
                pidfile.write_text(value, encoding='utf-8')
                completed = subprocess.run(['bash', '-e', '-c', script + '\nstop_server "$1"',
                                            'acceptance-test', str(pidfile)],
                                           capture_output=True, text=True, timeout=5)
                self.assertNotEqual(0, completed.returncode)
                self.assertIn('unsafe acceptance server PID', completed.stderr)
                self.assertTrue(pidfile.exists())


if __name__ == '__main__':
    unittest.main()
