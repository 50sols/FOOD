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
                        self.assertTrue(re.search(r'^State:\s+Z', status, re.MULTILINE),
                                        f'Owned listener PID {child} must not remain running')
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



class WebInstallerMigrationTransportFailureTests(unittest.TestCase):
    """Do not allow a slow or dead PHP server to mask first-attempt MySQL DDL."""

    def test_global_and_readiness_failures_never_expose_raw_logs(self):
        source = SH.read_text(encoding="utf-8")
        failure = source.split("report_failure() {", 1)[1].split("\ntrap ", 1)[0]
        readiness = source.split("wait_for_http() {", 1)[1].split("\nwait_for_port_free() {", 1)[0]
        self.assertNotIn("cat /tmp/foodex-install-server.log", failure)
        self.assertNotIn("cat /tmp/foodex-installed-server.log", failure)
        self.assertNotIn("laravel.log", failure)
        self.assertNotIn('cat "$log_file"', readiness)
        self.assertNotIn("tail -n 200", readiness)
        self.assertIn('trap \'report_failure "$?" "$LINENO"\' ERR', source)
        self.assertIn("raw diagnostics suppressed", failure)
        self.assertIn("G004 first-attempt migration failure:", source)

    def test_http_failure_never_exposes_raw_laravel_logs(self):
        source = SH.read_text(encoding='utf-8')
        function = source.split('wizard_post() {', 1)[1].split('\n\nfor step in $(seq 1 15)', 1)[0]
        self.assertNotIn('tail -n 100', function)
        self.assertIn('G004 safe installer error category:', function)
        self.assertIn('G004 first-attempt migration failure:', function)

    def test_finish_http_500_is_a_single_post_in_real_bash(self):
        """Exercise the sourced wizard function; a failed Finish is never replayed."""
        source = SH.read_text(encoding='utf-8')
        function = source.split('wizard_post() {', 1)[1].split('\n\nfor step in $(seq 1 15)', 1)[0]
        with tempfile.TemporaryDirectory() as temporary:
            calls = Path(temporary) / 'curl-post-count'
            shell = (
                'wizard_post() {' + function + '\n'
                'curl() { echo request >> "$CURL_CALLS"; printf 500; }\n'
                'installer_token=fixture-token\n'
                'if wizard_post 15; then exit 42; fi\n'
                'test "$(wc -l < "$CURL_CALLS")" -eq 1\n'
            )
            completed = subprocess.run(
                ['bash', '-e', '-c', shell],
                capture_output=True, text=True, timeout=10,
                env={**os.environ, 'CURL_CALLS': str(calls)},
            )
            self.assertEqual(0, completed.returncode, completed.stderr + completed.stdout)
            self.assertEqual(['request'], calls.read_text(encoding='utf-8').splitlines())
            self.assertIn('refusing to replay the final installer write', completed.stdout)

    def test_finish_never_replays_indeterminate_http_response(self):
        source = SH.read_text(encoding='utf-8')
        function = source.split('wizard_post() {', 1)[1].split('\n\nfor step in $(seq 1 15)', 1)[0]
        success = function.index('[[ "$status" == "302" && "$transport_exit" == "0" ]] && return 0')
        finish = function.index('if [[ "$step" == "15" ]]; then')
        migration = function.index('if [[ "$step" == "11" && ( "$status" == "000" || "$transport_exit" -ne 0 ) ]]')
        self.assertLess(success, finish)
        self.assertLess(finish, migration)
        finish_block = function[finish:migration]
        self.assertIn('refusing to replay the final installer write', finish_block)
        self.assertIn('return 1', finish_block)
        self.assertIn('including 500/409', finish_block)
        self.assertNotIn('laravel.log', finish_block)
        self.assertNotIn('install-step-$step.html', finish_block)

    def test_step_11_captures_transport_result_without_replaying_nontransactional_ddl(self):
        source = SH.read_text(encoding='utf-8')
        start = source.index('wizard_post() {')
        end = source.index('\n\nfor step in $(seq 1 15)', start)
        function = source[start:end]
        self.assertIn('local transport_exit=0', function)
        self.assertIn(')" || transport_exit=$?', function)
        self.assertIn('"$step" == "11" && ( "$status" == "000" || "$transport_exit" -ne 0 )',
                      function)
        self.assertIn('transport_category="timeout"', function)
        self.assertIn('transport_category="empty_reply"', function)
        self.assertIn('transport_category="connection_refused"', function)
        self.assertIn('server_state="missing"', function)
        self.assertIn('server_state="alive"', function)
        self.assertIn('refusing to replay because MySQL DDL may have partially committed', function)
        self.assertLess(function.index('category=$transport_category'),
                        function.index('return 1', function.index('category=$transport_category')))
        self.assertNotIn('|| true)', function[function.index('local transport_exit=0'):])




if __name__ == '__main__':
    unittest.main()
