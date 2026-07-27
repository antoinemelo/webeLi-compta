from __future__ import annotations

import importlib.util
import json
import sqlite3
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
        self.assertFalse(ADMIN.is_runtime_path("vendor/autoload.php"))
        self.assertFalse(ADMIN.is_runtime_path("livrables/SPECS_V02/README.md"))

    def test_initial_deployment_contains_the_complete_runtime_tree(self) -> None:
        commit = ADMIN.git("rev-parse", "HEAD")
        ADMIN.ensure_vendor_ready(commit)
        inventory = ADMIN.deployment_files_at(commit)
        uploads, deletions = ADMIN.changed_runtime_files(None, commit)
        self.assertEqual(inventory, uploads)
        self.assertEqual([], deletions)
        self.assertIn(".htaccess", uploads)
        self.assertIn("index.php", uploads)
        self.assertIn("public/index.php", uploads)
        self.assertIn("src/Core/Http/WebApplication.php", uploads)
        self.assertIn("public/app/index.html", uploads)
        self.assertIn("vendor/autoload.php", uploads)
        self.assertGreater(len(uploads), 500)

    def test_only_complete_v2_manifests_are_trusted_for_deltas(self) -> None:
        commit = ADMIN.git("rev-parse", "HEAD")
        legacy = {
            "schema": 1,
            "application": "webeli-compta",
            "commit": commit,
            "files": [{"path": "index.php", "sha256": "digest"}],
        }
        complete = {
            **legacy,
            "schema": ADMIN.DEPLOY_MANIFEST_SCHEMA,
            "files": [
                {"path": path, "sha256": "digest"}
                for path in ADMIN.deployment_files_at(commit)
            ],
        }
        self.assertFalse(ADMIN.manifest_has_complete_inventory(None))
        self.assertFalse(ADMIN.manifest_has_complete_inventory(legacy))
        self.assertFalse(ADMIN.manifest_has_complete_inventory({
            **complete,
            "files": complete["files"][:-1],
        }))
        self.assertTrue(ADMIN.manifest_has_complete_inventory(complete))

    def test_database_modes_are_explicit(self) -> None:
        technical = ADMIN.parser().parse_args(["db-create"])
        initialized = ADMIN.parser().parse_args(["db-create", "--initialize"])
        without_pedagogy = ADMIN.parser().parse_args([
            "db-create", "--initialize", "--without-pedagogy",
        ])
        restoration = ADMIN.parser().parse_args([
            "db-restore", "--source", "backup.sqlite",
        ])
        inspection = ADMIN.parser().parse_args([
            "db-inspect", "--path", "version-zero.sqlite",
        ])
        self.assertFalse(technical.initialize)
        self.assertTrue(initialized.initialize)
        self.assertTrue(ADMIN.pedagogy_enabled(initialized, True))
        self.assertFalse(ADMIN.pedagogy_enabled(without_pedagogy, True))
        self.assertFalse(ADMIN.pedagogy_enabled(technical, False))
        self.assertEqual(Path("backup.sqlite"), restoration.source)
        self.assertEqual(Path("version-zero.sqlite"), inspection.path)

    def test_sqlite_backup_is_consistent_and_keeps_source(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "source.sqlite"
            destination = root / "backups" / "source.sqlite"
            with sqlite3.connect(source) as connection:
                connection.execute(
                    "CREATE TABLE schema_migrations "
                    "(version TEXT PRIMARY KEY, checksum TEXT NOT NULL)"
                )
                connection.execute(
                    "INSERT INTO schema_migrations VALUES ('001', 'test')"
                )
                connection.execute("CREATE TABLE sample (value TEXT NOT NULL)")
                connection.execute("INSERT INTO sample VALUES ('conservé')")
            ADMIN.backup_database(source, destination)
            self.assertTrue(source.exists())
            summary = ADMIN.database_summary(destination)
            self.assertEqual(1, summary["migrations"])
            self.assertEqual(0, summary["modeles_pedagogiques"])
            self.assertEqual(summary["size"], summary["used_size"])
            restored = dict(summary)
            ADMIN.assert_restored_content(summary, restored)
            restored["modeles_pedagogiques"] = 1
            with self.assertRaises(ADMIN.AdminError):
                ADMIN.assert_restored_content(summary, restored)
            source_fingerprints = ADMIN.database_fingerprints(
                source, ("sample",)
            )
            backup_fingerprints = ADMIN.database_fingerprints(
                destination, ("sample",)
            )
            ADMIN.assert_restored_fingerprints(
                source_fingerprints,
                backup_fingerprints,
            )
            with sqlite3.connect(destination) as connection:
                connection.execute("UPDATE sample SET value = 'altéré'")
            with self.assertRaises(ADMIN.AdminError):
                ADMIN.assert_restored_fingerprints(
                    source_fingerprints,
                    ADMIN.database_fingerprints(destination, ("sample",)),
                )
            with sqlite3.connect(destination) as connection:
                connection.execute("UPDATE sample SET value = 'conservé'")
                self.assertEqual(
                    "conservé",
                    connection.execute("SELECT value FROM sample").fetchone()[0],
                )

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
            self.assertEqual(ADMIN.DEPLOY_MANIFEST_SCHEMA, stored["schema"])
            self.assertGreater(len(stored["files"]), 500)

    def test_legacy_marker_forces_a_complete_repair_deployment(self) -> None:
        commit = ADMIN.git("rev-parse", "HEAD")
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = root / "site"
            marker = target / ADMIN.REMOTE_MANIFEST
            marker.parent.mkdir(parents=True)
            marker.write_text(json.dumps({
                "schema": 1,
                "application": "webeli-compta",
                "commit": commit,
                "files": [{"path": "VERSION", "sha256": "legacy"}],
            }), encoding="utf-8")
            config_path = root / "deploy.json"
            config_path.write_text(json.dumps({
                "transport": "local",
                "target": str(target),
            }), encoding="utf-8")
            arguments = ADMIN.argparse.Namespace(
                config=config_path,
                commit="HEAD",
                from_commit=None,
                delete=False,
                apply=True,
            )
            with patch("builtins.print"):
                self.assertEqual(0, ADMIN.deploy(arguments))
            self.assertTrue((target / "public/index.php").is_file())
            self.assertTrue(
                (target / "src/Core/Http/WebApplication.php").is_file()
            )
            self.assertTrue((target / "vendor/autoload.php").is_file())
            stored = json.loads(marker.read_text(encoding="utf-8"))
            self.assertEqual(ADMIN.DEPLOY_MANIFEST_SCHEMA, stored["schema"])
            self.assertGreater(len(stored["uploads"]), 500)


if __name__ == "__main__":
    unittest.main()
