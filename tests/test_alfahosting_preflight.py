import importlib.util
from pathlib import Path
import unittest


SCRIPT = Path(__file__).resolve().parents[1] / ".github/scripts/alfahosting_preflight.py"
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


if __name__ == "__main__":
    unittest.main()
