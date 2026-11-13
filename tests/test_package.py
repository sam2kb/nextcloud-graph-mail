# SPDX-FileCopyrightText: 2026 sam2kb
# SPDX-License-Identifier: AGPL-3.0-or-later
import importlib.util
from pathlib import Path
import tarfile
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('package', Path(__file__).resolve().parent.parent / 'tools/package.py')
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)

class PackageTest(unittest.TestCase):
    def test_runtime_only_and_reproducible(self):
        with tempfile.TemporaryDirectory() as directory:
            path = package.build(Path(directory))
            first = path.read_bytes()
            self.assertEqual(first, package.build(Path(directory)).read_bytes())
            with tarfile.open(path) as archive:
                names = archive.getnames()
                self.assertTrue(all(name.startswith('graph_mail/') for name in names))
                self.assertFalse(any(part in name for name in names for part in ('tools/', 'tests/', 'vendor/', '.codex/', 'composer.')))
                self.assertEqual(0o755, archive.getmember('graph_mail/bin/sendmail').mode)
                self.assertIn('graph_mail/img/app.svg', names)
                self.assertIn('graph_mail/docs/operator-recovery.md', names)

if __name__ == '__main__':
    unittest.main()
