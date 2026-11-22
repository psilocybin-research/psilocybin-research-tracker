import importlib.util
from pathlib import Path
import unittest


SCRIPT = Path(__file__).resolve().parents[1] / ".github/scripts/alfahosting_release_command.py"
SPEC = importlib.util.spec_from_file_location("alfahosting_release_command", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)

SHA = "a" * 40


class ReleaseCommandTests(unittest.TestCase):
    def test_valid_verify_and_deploy(self):
        for mode in ("verify", "deploy"):
            with self.subTest(mode=mode):
                self.assertEqual(
                    MODULE.parse_command(f"/release {mode} {SHA}", SHA),
                    (mode, SHA),
                )

    def test_rejects_wrong_commit(self):
        with self.assertRaises(ValueError):
            MODULE.parse_command(f"/release deploy {'b' * 40}", SHA)

    def test_rejects_unknown_commands_or_text(self):
        for body in (
            "/release plan " + SHA,
            "/release deploy",
            "/release deploy " + SHA + "\nextra",
            "/release deploy " + SHA + "; echo unsafe",
            "please /release verify " + SHA,
            "/release deploy " + SHA.upper(),
            "",
        ):
            with self.subTest(body=body), self.assertRaises(ValueError):
                MODULE.parse_command(body, SHA)


if __name__ == "__main__":
    unittest.main()
