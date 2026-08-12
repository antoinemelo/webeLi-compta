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
    @staticmethod
    def make_runtime_source(root: Path) -> None:
        files = {
            ".htaccess": "Options -Indexes\n",
            "index.php": "<?php\n",
            "VERSION": "1.2.3\n",
            "composer.json": "{}\n",
            "composer.lock": '{"packages": []}\n',
            "bootstrap/app.php": "<?php\n",
            "config/app.php": "<?php return [];\n",
            "config/local.php": "<?php return ['secret' => 'refusé'];\n",
            "database/migrations/001_initial.sql": "SELECT 1;\n",
            "public/.htaccess": "Options -Indexes\n",
            "public/index.php": "<?php\n",
            "public/app/index.html": "<main></main>\n",
            "public/app/.vite/manifest.json": (
                '{"entry":{"file":"assets/app.js","isEntry":true}}\n'
            ),
            "public/app/assets/app.js": "console.log('runtime');\n",
            "public/app/assets/ancienne.js": "console.log('obsolète');\n",
            "src/Core/App.php": "<?php\n",
            "templates/layout.php": "<?php\n",
            "vendor/autoload.php": "<?php\n",
            "vendor/composer/installed.json": '{"packages": []}\n',
            "frontend/admin-vue/src/App.vue": "<template />\n",
            "tests/run.php": "<?php\n",
            "tools/private.py": "print('outil')\n",
            "storage/database/app.sqlite": "données privées",
        }
        for path, content in files.items():
            target = root / path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(content, encoding="utf-8")

    def test_no_argument_opens_interactive_menu(self) -> None:
        self.assertIsNone(ADMIN.parser().parse_args([]).command)
        with (
            patch("builtins.input", return_value="0"),
            patch("builtins.print") as output,
        ):
            self.assertEqual(0, ADMIN.main([]))
        rendered = "\n".join(
            " ".join(str(value) for value in call.args)
            for call in output.call_args_list
        )
        self.assertIn("4. Créer une photographie SQLite autonome", rendered)
        self.assertIn("6. Étape 1 — Préparer la copie locale dev → main", rendered)
        self.assertIn("7. Étape 2 — Installer main", rendered)
        self.assertIn("8. Mettre à jour un site existant", rendered)
        self.assertIn("option 6, puis option 7", rendered)

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

    def test_direct_ftp_install_selects_only_executable_runtime_files(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.make_runtime_source(root)
            inventory = ADMIN.direct_install_files(root)
            self.assertIn("public/app/assets/app.js", inventory)
            self.assertNotIn("public/app/assets/ancienne.js", inventory)
            self.assertIn("vendor/autoload.php", inventory)
            self.assertIn("database/migrations/001_initial.sql", inventory)
            self.assertNotIn("config/local.php", inventory)
            self.assertNotIn("storage/database/app.sqlite", inventory)
            self.assertNotIn("frontend/admin-vue/src/App.vue", inventory)
            self.assertNotIn("tests/run.php", inventory)
            self.assertNotIn("tools/private.py", inventory)
            manifest = ADMIN.direct_install_manifest(root, inventory)
            self.assertEqual(
                "complete-runtime-install",
                manifest["deployment_kind"],
            )
            self.assertTrue(ADMIN.direct_manifest_is_valid(
                manifest,
                manifest["source_fingerprint"],
                inventory,
            ))
            self.assertNotIn(str(root), json.dumps(manifest))

    def test_runtime_copy_creates_a_self_contained_delivery_without_data(
        self,
    ) -> None:
        with tempfile.TemporaryDirectory() as directory:
            parent = Path(directory)
            source = parent / "dev"
            destination = parent / "main"
            self.make_runtime_source(source)
            inventory = ADMIN.direct_install_files(source)
            vendor = ADMIN.vendor_directory(source)
            backup = ADMIN.copy_runtime_directory(
                source,
                destination,
                inventory,
                vendor,
            )
            self.assertIsNone(backup)
            self.assertEqual(inventory, ADMIN.direct_install_files(destination))
            self.assertTrue((destination / "vendor/autoload.php").is_file())
            self.assertTrue((destination / "public/app/index.html").is_file())
            self.assertFalse((destination / "config/local.php").exists())
            self.assertFalse((destination / "storage/database/app.sqlite").exists())
            self.assertFalse((destination / "frontend").exists())
            self.assertFalse((destination / "tools").exists())

            (destination / "index.php").write_text(
                "ancienne copie\n",
                encoding="utf-8",
            )
            backup = ADMIN.copy_runtime_directory(
                source,
                destination,
                inventory,
                vendor,
                replace=True,
            )
            self.assertIsNotNone(backup)
            assert backup is not None
            self.assertEqual(
                "ancienne copie\n",
                (backup / "index.php").read_text(encoding="utf-8"),
            )
            self.assertEqual(
                (source / "index.php").read_text(encoding="utf-8"),
                (destination / "index.php").read_text(encoding="utf-8"),
            )

    def test_runtime_copy_refuses_overlapping_or_existing_targets(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "dev"
            self.make_runtime_source(source)
            with self.assertRaisesRegex(
                ADMIN.AdminError,
                "à l’intérieur de la source",
            ):
                ADMIN.validate_runtime_copy_target(source, source / "main")
            destination = Path(directory) / "main"
            destination.mkdir()
            inventory = ADMIN.direct_install_files(source)
            with self.assertRaisesRegex(ADMIN.AdminError, "existe déjà"):
                ADMIN.copy_runtime_directory(
                    source,
                    destination,
                    inventory,
                    ADMIN.vendor_directory(source),
                )

    def test_direct_ftp_install_rejects_incomplete_build_and_unsafe_target(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.make_runtime_source(root)
            (root / "public/app/assets/app.js").unlink()
            with self.assertRaisesRegex(ADMIN.AdminError, "build Vue est incomplet"):
                ADMIN.direct_install_files(root)
        self.assertEqual(
            "/www/example/compta",
            ADMIN.normalize_remote_root("/www//example/compta/"),
        )
        with self.assertRaises(ADMIN.AdminError):
            ADMIN.normalize_remote_root("www/example/compta")
        with self.assertRaises(ADMIN.AdminError):
            ADMIN.normalize_remote_root("/www/../compta")

    def test_vendor_is_found_in_parent_and_can_be_shared_or_skipped(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            parent = Path(directory)
            root = parent / "instance"
            self.make_runtime_source(root)
            local_vendor = root / "vendor"
            shared_vendor = parent / "vendor"
            local_vendor.rename(shared_vendor)
            mode, detected = ADMIN.resolved_vendor_mode(root, "auto")
            self.assertEqual("shared", mode)
            self.assertEqual(shared_vendor, detected)
            with_vendor = ADMIN.direct_install_files(root)
            self.assertIn("vendor/autoload.php", with_vendor)
            manifest = ADMIN.direct_install_manifest(
                root,
                with_vendor,
                vendor_mode=mode,
                vendor=detected,
            )
            vendor_entry = next(
                item
                for item in manifest["files"]
                if item["path"] == "vendor/autoload.php"
            )
            self.assertEqual(
                "../vendor/autoload.php",
                vendor_entry["remote_path"],
            )
            without_vendor = ADMIN.direct_install_files(
                root,
                include_vendor=False,
            )
            self.assertFalse(any(
                path.startswith("vendor/") for path in without_vendor
            ))

    def test_direct_ftp_install_uploads_and_verifies_every_file(self) -> None:
        class FakeFtp:
            def __init__(self) -> None:
                self.files: dict[str, bytes] = {}

            def __enter__(self) -> "FakeFtp":
                return self

            def __exit__(self, *_: object) -> None:
                return None

            def mkd(self, _path: str) -> None:
                return None

            def storbinary(self, command: str, stream: object) -> None:
                path = command.removeprefix("STOR ")
                self.files[path] = stream.read()

            def retrbinary(self, command: str, callback: object) -> None:
                path = command.removeprefix("RETR ")
                if path not in self.files:
                    raise ADMIN.ftplib.error_perm("550 absent")
                callback(self.files[path])

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.make_runtime_source(root)
            inventory = ADMIN.direct_install_files(root)
            manifest = ADMIN.direct_install_manifest(root, inventory)
            ftp = FakeFtp()
            with patch.object(ADMIN, "ftp_connect", return_value=ftp):
                ADMIN.install_directory_ftp(
                    {"transport": "ftps"},
                    root,
                    "/www/compta",
                    inventory,
                    manifest,
                )
            self.assertEqual(
                (root / "index.php").read_bytes(),
                ftp.files["/www/compta/index.php"],
            )
            marker = json.loads(
                ftp.files[
                    f"/www/compta/{ADMIN.REMOTE_MANIFEST}"
                ].decode("utf-8")
            )
            self.assertEqual(
                manifest["source_fingerprint"],
                marker["source_fingerprint"],
            )

    def test_direct_ftp_install_protects_an_existing_destination(self) -> None:
        class ExistingFtp:
            def __enter__(self) -> "ExistingFtp":
                return self

            def __exit__(self, *_: object) -> None:
                return None

            def retrbinary(self, command: str, callback: object) -> None:
                if command == "RETR /www/compta/index.php":
                    callback(b"<?php existing")
                    return
                raise ADMIN.ftplib.error_perm("550 absent")

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.make_runtime_source(root)
            inventory = ADMIN.direct_install_files(root)
            manifest = ADMIN.direct_install_manifest(root, inventory)
            with patch.object(ADMIN, "ftp_connect", return_value=ExistingFtp()):
                with self.assertRaisesRegex(
                    ADMIN.AdminError,
                    "contient déjà une installation",
                ):
                    ADMIN.install_directory_ftp(
                        {"transport": "ftps"},
                        root,
                        "/www/compta",
                        inventory,
                        manifest,
                    )

    def test_direct_ftp_install_can_reuse_parent_vendor_without_uploading_it(
        self,
    ) -> None:
        class SharedVendorFtp:
            def __init__(self, installed: bytes) -> None:
                self.files = {
                    "/www/instances/vendor/autoload.php": b"<?php shared",
                    "/www/instances/vendor/composer/installed.json": installed,
                }
                self.uploaded: list[str] = []

            def __enter__(self) -> "SharedVendorFtp":
                return self

            def __exit__(self, *_: object) -> None:
                return None

            def mkd(self, _path: str) -> None:
                return None

            def storbinary(self, command: str, stream: object) -> None:
                path = command.removeprefix("STOR ")
                self.uploaded.append(path)
                self.files[path] = stream.read()

            def retrbinary(self, command: str, callback: object) -> None:
                path = command.removeprefix("RETR ")
                if path not in self.files:
                    raise ADMIN.ftplib.error_perm("550 absent")
                callback(self.files[path])

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.make_runtime_source(root)
            inventory = ADMIN.direct_install_files(
                root,
                include_vendor=False,
            )
            vendor = ADMIN.vendor_directory(root)
            manifest = ADMIN.direct_install_manifest(
                root,
                inventory,
                vendor_mode="skip",
                vendor=vendor,
            )
            ftp = SharedVendorFtp(
                (vendor / "composer/installed.json").read_bytes()
            )
            with patch.object(ADMIN, "ftp_connect", return_value=ftp):
                ADMIN.install_directory_ftp(
                    {"transport": "ftps"},
                    root,
                    "/www/instances/compta",
                    inventory,
                    manifest,
                )
            self.assertTrue(
                "/www/instances/compta/index.php" in ftp.uploaded
            )
            self.assertFalse(any(
                "/vendor/" in path for path in ftp.uploaded
            ))

    def test_ftp_install_command_exposes_source_and_remote_directories(self) -> None:
        arguments = ADMIN.parser().parse_args([
            "ftp-install",
            "--source",
            "/tmp/release",
            "--remote-root",
            "/www/compta",
        ])
        self.assertEqual(Path("/tmp/release"), arguments.source)
        self.assertEqual("/www/compta", arguments.remote_root)
        self.assertFalse(arguments.apply)
        self.assertFalse(arguments.replace_runtime)
        self.assertIsNone(arguments.vendor_mode)
        self.assertFalse(arguments.replace_shared_vendor)

    def test_runtime_copy_command_exposes_dev_to_main_workflow(self) -> None:
        arguments = ADMIN.parser().parse_args([
            "runtime-copy",
            "--source",
            "/tmp/dev",
            "--destination",
            "/tmp/main",
        ])
        self.assertEqual(Path("/tmp/dev"), arguments.source)
        self.assertEqual(Path("/tmp/main"), arguments.destination)
        self.assertFalse(arguments.apply)
        self.assertFalse(arguments.replace)
        self.assertFalse(arguments.list_files)

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
        backup = ADMIN.parser().parse_args([
            "db-backup", "--source", "active.sqlite", "--output", "clean.sqlite",
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
        self.assertEqual(Path("active.sqlite"), backup.source)
        self.assertEqual(Path("clean.sqlite"), backup.output)
        self.assertFalse(backup.apply)
        self.assertEqual(Path("version-zero.sqlite"), inspection.path)

    def test_admin_password_uses_the_canonical_policy_before_creation(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "must-not-be-created.sqlite"
            with patch.dict(
                ADMIN.os.environ,
                {"APP_DB_PATH": str(target)},
                clear=False,
            ):
                with self.assertRaisesRegex(ADMIN.AdminError, "trop prévisible"):
                    ADMIN.validate_admin_password("ChangeMe123!")
            self.assertFalse(target.exists())
        ADMIN.validate_admin_password("Initiale!2026Unique")

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
            self.assertFalse(Path(str(destination) + "-wal").exists())
            self.assertFalse(Path(str(destination) + "-shm").exists())
            with sqlite3.connect(destination) as portable:
                self.assertEqual(
                    "delete",
                    portable.execute("PRAGMA journal_mode").fetchone()[0],
                )
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

    def test_db_backup_creates_one_portable_file_including_wal(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "active.sqlite"
            destination = root / "configured.sqlite"
            active = sqlite3.connect(source)
            try:
                self.assertEqual(
                    "wal",
                    active.execute("PRAGMA journal_mode = WAL").fetchone()[0],
                )
                active.execute(
                    "CREATE TABLE schema_migrations "
                    "(version TEXT PRIMARY KEY, checksum TEXT NOT NULL)"
                )
                active.execute(
                    "INSERT INTO schema_migrations VALUES ('001', 'test')"
                )
                active.execute("CREATE TABLE sample (value TEXT NOT NULL)")
                active.execute("INSERT INTO sample VALUES ('présent dans le WAL')")
                active.commit()
                self.assertTrue(Path(str(source) + "-wal").exists())

                simulated = ADMIN.parser().parse_args([
                    "db-backup",
                    "--source", str(source),
                    "--output", str(destination),
                    "--allow-outside-project",
                ])
                with self.assertRaises(SystemExit) as simulation:
                    ADMIN.database_backup(simulated)
                self.assertEqual(0, simulation.exception.code)
                self.assertFalse(destination.exists())

                applied = ADMIN.parser().parse_args([
                    "db-backup",
                    "--source", str(source),
                    "--output", str(destination),
                    "--allow-outside-project",
                    "--apply",
                ])
                self.assertEqual(0, ADMIN.database_backup(applied))
            finally:
                active.close()

            self.assertTrue(destination.is_file())
            self.assertFalse(Path(str(destination) + "-wal").exists())
            self.assertFalse(Path(str(destination) + "-shm").exists())
            with sqlite3.connect(f"file:{destination}?mode=ro", uri=True) as copy:
                self.assertEqual("delete", copy.execute(
                    "PRAGMA journal_mode"
                ).fetchone()[0])
                self.assertEqual(
                    "présent dans le WAL",
                    copy.execute("SELECT value FROM sample").fetchone()[0],
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
