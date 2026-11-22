import importlib.util
from contextlib import redirect_stderr
from io import StringIO
from pathlib import Path
import sys
import unittest
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[1] / ".github/scripts/alfahosting_preflight.py"
sys.path.insert(0, str(SCRIPT.parent))
SPEC = importlib.util.spec_from_file_location("alfahosting_preflight", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class DocumentRootTests(unittest.TestCase):
    def test_valid(self):
        self.assertEqual(MODULE.validated_root("/website/public/"), "/website/public")

    def test_invalid(self):
        for path in ("/", ".", "../public", "/foo/../bar", "relative", ""):
            with self.subTest(path=path), self.assertRaises(ValueError):
                MODULE.validated_root(path)


class DiagnosticCategoryTests(unittest.TestCase):
    def test_captures_fixed_stderr_categories(self):
        from alfahosting_auth import (
            AlfahostingAuthError,
            ConfigurationError,
            HostKeyError,
            NetworkError,
        )

        cases = (
            (ConfigurationError("SECRET"), "configuration"),
            (HostKeyError("ssh-ed25519 AAAA-PRIVATE-HOST-KEY"), "host-key"),
            (AlfahostingAuthError("password=TOPSECRET"), "authentication"),
            (NetworkError("server response"), "network"),
            (MODULE.SftpError("/var/www/private"), "sftp"),
            (MODULE.DocumentRootError("/var/www/private"), "document-root"),
            (RuntimeError("TOPSECRET"), "unexpected"),
        )

        for exc, category in cases:
            with self.subTest(category=category):
                stderr = StringIO()
                with patch.object(MODULE, "verify", side_effect=exc):
                    with redirect_stderr(stderr):
                        with self.assertRaises(SystemExit) as exit_result:
                            MODULE.main()
                self.assertEqual(exit_result.exception.code, 1)
                output = stderr.getvalue()
                self.assertIn("SFTP preflight failed: " + category, output)
                self.assertNotIn("TOPSECRET", output)
                self.assertNotIn("SECRET", output)
                self.assertNotIn("ssh-ed25519", output)
                self.assertNotIn("AAA", output)
                self.assertNotIn("password=", output)
                self.assertNotIn("/var/www", output)


if __name__ == "__main__":
    unittest.main()
