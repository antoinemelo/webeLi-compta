from __future__ import annotations

import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE_PATH = Path(__file__).resolve().parents[1] / "compta.py"
SPEC = importlib.util.spec_from_file_location("webeli_compta_admin", MODULE_PATH)
assert SPEC is not None and SPEC.loader is not None
ADMIN = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ADMIN)


class ComptaAdminTests(unittest.TestCase):
    def test_no_argument_opens_interactive_menu(self) -> None:
        self.assertIsNone(ADMIN.parser().parse_args([]).command)
        with patch("builtins.input", return_value="0"):
            self.assertEqual(0, ADMIN.main([]))

    def test_runtime_filter_excludes_sources_and_private_data(self) -> None:
        self.assertTrue(ADMIN.is_runtime_path("src/Core/App.php"))
        self.assertTrue(ADMIN.is_runtime_path("public/app/index.html"))
        self.assertTrue(ADMIN.is_runtime_path("database/migrations/001_initial.sql"))
        self.assertFalse(ADMIN.is_runtime_path("frontend/admin-vue/src/App.vue"))
        self.assertFalse(ADMIN.is_runtime_path("storage/database/app.sqlite"))
        self.assertFalse(ADMIN.is_runtime_path("config/local.php"))
        self.assertFalse(ADMIN.is_runtime_path("livrables/SPECS_V02/README.md"))

    def test_local_delivery_reads_the_committed_blob(self) -> None:
        commit = ADMIN.git("rev-parse", "HEAD")
        with tempfile.TemporaryDirectory() as directory:
            config = {"transport": "local", "target": directory}
            manifest = ADMIN.release_manifest(commit, None, ["VERSION"], [])
            ADMIN.deploy_local(config, commit, ["VERSION"], [], manifest, False)
            target = Path(directory)
            self.assertEqual(
                ADMIN.git_bytes(commit, "VERSION"),
                (target / "VERSION").read_bytes(),
            )
            stored = json.loads(
                (target / ADMIN.REMOTE_MANIFEST).read_text(encoding="utf-8")
            )
            self.assertEqual(commit, stored["commit"])
            self.assertEqual("webeli-compta", stored["application"])


if __name__ == "__main__":
    unittest.main()
