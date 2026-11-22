import importlib.util
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / ".github/scripts"))
SPEC = importlib.util.spec_from_file_location("alfahosting_release", ROOT / ".github/scripts/alfahosting_release.py")
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class AllowlistTests(unittest.TestCase):
    def test_application_files_allowed(self):
        for path in ("index.php", "publication.php", ".htaccess", "src/helpers.php",
                     "assets/app.min.js", ".well-known/assetlinks.json",
                     "config/.htaccess", "config/fungi-gallery.json",
                     "docs/research-api.openapi.json", "bin/update.php"):
            with self.subTest(path=path):
                self.assertTrue(MODULE.allowed_path(path))

    def test_private_and_internal_files_denied(self):
        for path in ("data/publications.sqlite", ".env", ".env.production",
                     ".git/config", ".github/workflows/ci.yml",
                     "README.md", "docs/deployment.md", "config/private.json",
                     "bin/deploy_alfahosting.py", "bin/alfahosting-release.py",
                     "tests/run.php", "manuscript/private.txt", "msca-impact/index.php",
                     "assets/msca-impact/style.css", "vendor/autoload.php",
                     "node_modules/foo.js", "../../private", "/tmp/file.php",
                     "src/.env", "src/../../secrets", "src\\private.php"):
            with self.subTest(path=path):
                self.assertFalse(MODULE.allowed_path(path))

    def test_release_paths_must_not_overlap(self):
        for site, private in (
            ("/site", "/site"),
            ("/site", "/site/releases"),
            ("/site/public", "/site"),
            ("/", "/releases"),
            ("site", "/releases"),
            ("/site", "../releases"),
        ):
            with self.subTest(site=site, private=private):
                with self.assertRaises(ValueError):
                    MODULE.release_paths(site, private)

    def test_release_paths_separate(self):
        self.assertEqual(
            MODULE.release_paths("/hosting/www", "/hosting/private-releases"),
            ("/hosting/www", "/hosting/private-releases"),
        )

    def test_manifest_excludes_runtime_and_requires_expected_files(self):
        found = MODULE.manifest(ROOT)
        names = [name for name, _ in found]
        self.assertIn("index.php", names)
        self.assertIn("config/.htaccess", names)
        self.assertNotIn("README.md", names)
        self.assertTrue(all(MODULE.allowed_path(name) for name in names))


if __name__ == "__main__":
    unittest.main()
