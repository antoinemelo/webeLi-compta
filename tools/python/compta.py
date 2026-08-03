#!/usr/bin/env python3
"""Administration sûre de WebeLi / Compta.

Les opérations qui modifient une base, Git ou un site distant exigent
explicitement ``--apply``. Le déploiement lit exclusivement le contenu du
commit Git ciblé : un fichier local non commité ne peut donc pas partir en
production par accident.
"""
from __future__ import annotations

import argparse
import ftplib
import getpass
import hashlib
import io
import json
import os
import posixpath
import shutil
import sqlite3
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_DEPLOY_CONFIG = ROOT / "ops" / "compta.deploy.json"
REMOTE_MANIFEST = "storage/deployments/current.json"
REMOTE_RELEASES = "storage/deployments/releases"
DEPLOY_MANIFEST_SCHEMA = 2
DIRECT_INSTALL_MANIFEST_SCHEMA = 1

RUNTIME_FILES = {
    ".htaccess",
    "index.php",
    "VERSION",
    "composer.json",
    "composer.lock",
}
RUNTIME_PREFIXES = (
    "bin/",
    "bootstrap/",
    "config/",
    "database/migrations/",
    "database/seeds/",
    "public/",
    "resources/",
    "src/",
    "templates/",
)
EXCLUDED_PREFIXES = (
    ".git/",
    ".github/",
    ".idea/",
    ".vscode/",
    "config/local.php",
    "frontend/",
    "livrables/",
    "node_modules/",
    "ops/compta.deploy.json",
    "storage/",
    "tests/",
    "tools/",
    "vendor/",
)
DIRECT_INSTALL_REQUIRED_FILES = {
    ".htaccess",
    "index.php",
    "VERSION",
    "composer.json",
    "composer.lock",
    "bootstrap/app.php",
    "config/app.php",
    "public/.htaccess",
    "public/index.php",
    "public/app/index.html",
    "public/app/.vite/manifest.json",
}
DIRECT_INSTALL_REQUIRED_PREFIXES = (
    "database/migrations/",
    "src/",
    "templates/",
)


class AdminError(RuntimeError):
    """Erreur attendue, présentée sans trace Python."""


def run(
    arguments: list[str],
    *,
    env: dict[str, str] | None = None,
    capture: bool = False,
    input_bytes: bytes | None = None,
) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(
        arguments,
        cwd=ROOT,
        env=env,
        input=input_bytes,
        stdout=subprocess.PIPE if capture else None,
        stderr=subprocess.PIPE if capture else None,
        check=True,
    )


def validate_admin_password(password: str) -> None:
    environment = os.environ.copy()
    environment["COMPTA_ADMIN_PASSWORD"] = password
    try:
        run(
            ["php", "bin/console", "security:password-check"],
            env=environment,
            capture=True,
        )
    except subprocess.CalledProcessError as error:
        detail = error.stderr.decode(
            "utf-8", errors="replace"
        ).strip().removeprefix("ERREUR:").strip()
        raise AdminError(
            detail or "Le mot de passe administrateur ne respecte pas la politique de sécurité."
        ) from error


def git(*arguments: str, capture: bool = True) -> str:
    result = run(["git", *arguments], capture=capture)
    return result.stdout.decode("utf-8", errors="replace").strip() if capture else ""


def ensure_apply(args: argparse.Namespace, message: str) -> None:
    if not args.apply:
        print(f"SIMULATION — {message}")
        print("Relancez avec --apply après vérification.")
        raise SystemExit(0)


def storage_for_database(path: Path) -> Path:
    return path.parent.parent if path.parent.name == "database" else path.parent


def database_environment(path: Path) -> dict[str, str]:
    environment = os.environ.copy()
    environment.update({
        "APP_DB_PATH": str(path),
        "APP_STORAGE_PATH": str(storage_for_database(path)),
        "APP_ENV": "dev",
        "APP_DEBUG": "0",
    })
    return environment


def check_database(path: Path, *, require_schema: bool = True) -> None:
    if not path.is_file():
        raise AdminError(f"Base SQLite introuvable : {path}")
    try:
        with sqlite3.connect(f"file:{path}?mode=ro", uri=True) as connection:
            integrity = connection.execute("PRAGMA integrity_check").fetchone()
            foreign_keys = connection.execute("PRAGMA foreign_key_check").fetchall()
            if integrity is None or integrity[0] != "ok" or foreign_keys:
                raise AdminError(f"Base SQLite incohérente : {path}")
            if require_schema:
                migrations = connection.execute(
                    "SELECT COUNT(*) FROM sqlite_master "
                    "WHERE type = 'table' AND name = 'schema_migrations'"
                ).fetchone()
                if migrations is None or migrations[0] != 1:
                    raise AdminError(
                        f"Le fichier n’est pas une base WebeLi / Compta : {path}"
                    )
    except sqlite3.DatabaseError as error:
        raise AdminError(f"Base SQLite illisible ({path}) : {error}") from error


def backup_path(target: Path, reason: str) -> Path:
    directory = storage_for_database(target) / "backups"
    stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
    candidate = directory / f"{target.stem}-{reason}-{stamp}.sqlite"
    if any(path.exists() for path in (
        candidate,
        Path(str(candidate) + "-wal"),
        Path(str(candidate) + "-shm"),
    )):
        candidate = directory / (
            f"{target.stem}-{reason}-{stamp}-{os.urandom(3).hex()}.sqlite"
        )
    return candidate


def backup_database(source: Path, destination: Path) -> Path:
    if any(path.exists() for path in (
        destination,
        Path(str(destination) + "-wal"),
        Path(str(destination) + "-shm"),
    )):
        raise AdminError(
            "La destination ou l’un de ses fichiers SQLite auxiliaires "
            "existe déjà."
        )
    destination.parent.mkdir(parents=True, exist_ok=True)
    try:
        with sqlite3.connect(f"file:{source}?mode=ro", uri=True) as original:
            with sqlite3.connect(destination) as backup:
                original.backup(backup)
        with sqlite3.connect(destination) as portable:
            journal_mode = str(
                portable.execute("PRAGMA journal_mode = DELETE").fetchone()[0]
            ).lower()
            if journal_mode != "delete":
                raise AdminError(
                    "La copie ne peut pas être normalisée en fichier SQLite "
                    "autonome."
                )
        check_database(destination)
        auxiliaries = (
            Path(str(destination) + "-wal"),
            Path(str(destination) + "-shm"),
        )
        if any(path.exists() for path in auxiliaries):
            raise AdminError(
                "La copie conserve des fichiers SQLite auxiliaires."
            )
    except (sqlite3.DatabaseError, AdminError) as error:
        remove_database_files(destination)
        if isinstance(error, AdminError):
            raise
        raise AdminError(f"Sauvegarde SQLite impossible : {error}") from error
    return destination


def remove_database_files(target: Path) -> None:
    for path in (target, Path(str(target) + "-wal"), Path(str(target) + "-shm")):
        if path.exists():
            path.unlink()


def database_summary(path: Path) -> dict[str, int]:
    with sqlite3.connect(f"file:{path}?mode=ro", uri=True) as connection:
        page_size = int(connection.execute("PRAGMA page_size").fetchone()[0])
        page_count = int(connection.execute("PRAGMA page_count").fetchone()[0])
        freelist_pages = int(
            connection.execute("PRAGMA freelist_count").fetchone()[0]
        )
        summary = {
            "size": path.stat().st_size,
            "page_size": page_size,
            "page_count": page_count,
            "freelist_pages": freelist_pages,
            "used_size": (page_count - freelist_pages) * page_size,
        }
        tables = {
            "migrations": "schema_migrations",
            "utilisateurs": "utilisateurs",
            "organisations": "organisations",
            "dossiers": "dossiers",
            "exercices": "exercices",
            "comptes": "comptes",
            "ecritures": "ecritures",
            "contacts": "contacts",
            "modeles_pedagogiques": "modeles_exercice",
            "versions_pedagogiques": "versions_modeles_exercice",
            "etapes_pedagogiques": "etapes_exercice",
            "indices_pedagogiques": "indices_exercice",
            "assignations_pedagogiques": "assignations_exercice",
            "tentatives_pedagogiques": "tentatives_pedagogiques",
            "contributions_pedagogiques": "contributions_pedagogiques",
            "groupes_pedagogiques": "groupes_pedagogiques",
        }
        for key, table in tables.items():
            exists = connection.execute(
                "SELECT COUNT(*) FROM sqlite_master "
                "WHERE type = 'table' AND name = ?",
                (table,),
            ).fetchone()
            summary[key] = 0 if not exists or exists[0] != 1 else int(
                connection.execute(f'SELECT COUNT(*) FROM "{table}"').fetchone()[0]
            )
        return summary


def print_database_summary(path: Path, title: str = "Base contrôlée") -> None:
    summary = database_summary(path)
    print(f"{title} : {path}")
    print(
        f"  Taille physique : {summary['size']} octets ; "
        f"contenu utilisé : {summary['used_size']} octets ; "
        f"pages libres : {summary['freelist_pages']}."
    )
    print(
        f"  Métier : {summary['utilisateurs']} utilisateur(s), "
        f"{summary['organisations']} organisation(s), "
        f"{summary['dossiers']} dossier(s), {summary['exercices']} exercice(s), "
        f"{summary['comptes']} compte(s), {summary['ecritures']} écriture(s), "
        f"{summary['contacts']} contact(s)."
    )
    print(
        f"  Pédagogie : {summary['modeles_pedagogiques']} parcours, "
        f"{summary['versions_pedagogiques']} version(s), "
        f"{summary['etapes_pedagogiques']} étape(s), "
        f"{summary['indices_pedagogiques']} indice(s), "
        f"{summary['assignations_pedagogiques']} assignation(s), "
        f"{summary['tentatives_pedagogiques']} tentative(s)."
    )


def assert_restored_content(
    source: dict[str, int],
    restored: dict[str, int],
) -> None:
    preserved = (
        "utilisateurs",
        "organisations",
        "dossiers",
        "exercices",
        "comptes",
        "ecritures",
        "contacts",
        "modeles_pedagogiques",
        "versions_pedagogiques",
        "etapes_pedagogiques",
        "indices_pedagogiques",
        "assignations_pedagogiques",
        "tentatives_pedagogiques",
        "contributions_pedagogiques",
        "groupes_pedagogiques",
    )
    differences = [
        f"{key}: {source[key]} → {restored[key]}"
        for key in preserved
        if source[key] != restored[key]
    ]
    if differences:
        raise AdminError(
            "La restauration ne conserve pas les volumes métier : "
            + ", ".join(differences)
        )


PRESERVED_TABLES = (
    "utilisateurs",
    "organisations",
    "dossiers",
    "exercices",
    "comptes",
    "ecritures",
    "lignes_ecriture",
    "contacts",
    "modeles_exercice",
    "versions_modeles_exercice",
    "etapes_exercice",
    "indices_exercice",
    "assignations_exercice",
    "tentatives_pedagogiques",
    "contributions_pedagogiques",
    "groupes_pedagogiques",
    "membres_groupes",
)


def database_fingerprints(
    path: Path,
    tables: tuple[str, ...] = PRESERVED_TABLES,
) -> dict[str, str]:
    fingerprints: dict[str, str] = {}
    with sqlite3.connect(f"file:{path}?mode=ro", uri=True) as connection:
        for table in tables:
            if not table.replace("_", "").isalnum():
                raise AdminError(f"Nom de table non sûr : {table}")
            exists = connection.execute(
                "SELECT COUNT(*) FROM sqlite_master "
                "WHERE type = 'table' AND name = ?",
                (table,),
            ).fetchone()
            if not exists or exists[0] != 1:
                continue
            quoted = '"' + table.replace('"', '""') + '"'
            columns = [
                str(row[1])
                for row in connection.execute(f"PRAGMA table_info({quoted})")
            ]
            order = ", ".join(
                '"' + column.replace('"', '""') + '"' for column in columns
            )
            digest = hashlib.sha256()
            digest.update(("\0".join(columns) + "\0").encode("utf-8"))
            for row in connection.execute(
                f"SELECT * FROM {quoted} ORDER BY {order}"
            ):
                for value in row:
                    if value is None:
                        payload = b"N"
                    elif isinstance(value, bytes):
                        payload = b"B" + value
                    else:
                        payload = (
                            type(value).__name__.encode("ascii")
                            + b":"
                            + str(value).encode("utf-8")
                        )
                    digest.update(len(payload).to_bytes(8, "big"))
                    digest.update(payload)
            fingerprints[table] = digest.hexdigest()
    return fingerprints


def assert_restored_fingerprints(
    source: dict[str, str],
    restored: dict[str, str],
) -> None:
    differences = [
        table
        for table, fingerprint in source.items()
        if restored.get(table) != fingerprint
    ]
    if differences:
        raise AdminError(
            "La restauration a modifié le contenu protégé des tables : "
            + ", ".join(differences)
        )


def database_inspect(args: argparse.Namespace) -> int:
    target = args.path.resolve()
    check_database(target)
    print_database_summary(target, "Audit en lecture seule")
    return 0


def database_backup(args: argparse.Namespace) -> int:
    source = args.source.resolve()
    check_database(source)
    output = args.output
    destination = (
        backup_path(source, "portable")
        if output is None
        else output.resolve()
    )
    if ROOT not in destination.parents and not args.allow_outside_project:
        raise AdminError(
            "La sauvegarde est hors du projet. Ajoutez "
            "--allow-outside-project pour confirmer ce périmètre."
        )
    if source == destination:
        raise AdminError("La base source et la sauvegarde sont identiques.")
    if any(path.exists() for path in (
        destination,
        Path(str(destination) + "-wal"),
        Path(str(destination) + "-shm"),
    )):
        raise AdminError(
            "La sauvegarde ou un fichier SQLite auxiliaire existe déjà. "
            "Choisissez un autre chemin de sortie."
        )
    source_summary = database_summary(source)
    source_fingerprints = database_fingerprints(source)
    print(f"Base source : {source}")
    print(f"Photographie portable : {destination}")
    print(
        "Étapes : copie SQLite cohérente incluant le WAL, normalisation en "
        "fichier unique, contrôle d’intégrité et comparaison du contenu."
    )
    ensure_apply(args, "aucune photographie n’a été créée")
    backup_database(source, destination)
    destination_summary = database_summary(destination)
    assert_restored_content(source_summary, destination_summary)
    assert_restored_fingerprints(
        source_fingerprints,
        database_fingerprints(destination),
    )
    print_database_summary(destination, "Photographie portable créée")
    print(
        "Fichier autonome vérifié : aucun fichier -wal ou -shm n’est requis."
    )
    return 0


def initialize_database(
    target: Path,
    args: argparse.Namespace,
    environment: dict[str, str],
) -> None:
    password = str(
        getattr(args, "admin_password", "")
        or environment.get("COMPTA_ADMIN_PASSWORD", "")
    )
    validate_admin_password(password)
    environment["COMPTA_ADMIN_PASSWORD"] = password
    year = str(getattr(args, "exercise", "") or datetime.now().year)
    start = str(getattr(args, "start", "") or f"{year}-01-01")
    end = str(getattr(args, "end", "") or f"{year}-12-31")
    command = [
        "php", "bin/console", "instance:init",
        f"--admin-email={args.admin_email}",
        f"--organisation={args.organisation}",
        f"--nature={args.nature}",
        f"--dossier={args.dossier}",
        f"--slug={args.slug}",
        f"--type={args.dossier_type}",
        f"--monnaie={args.currency}",
        f"--exercice={year}",
        f"--debut={start}",
        f"--fin={end}",
        f"--modules={args.modules}",
        f"--variante={args.plan_variant}",
    ]
    if getattr(args, "association", False):
        command.append("--association")
    if getattr(args, "with_pedagogy", True):
        command.extend([
            "--pedagogie",
            f"--organisation-pedagogique={args.pedagogy_organisation}",
            f"--dossier-pedagogique={args.pedagogy_dossier}",
            f"--slug-pedagogique={args.pedagogy_slug}",
        ])
    run(command, env=environment)


def pedagogy_enabled(args: argparse.Namespace, initialize: bool) -> bool:
    option = getattr(args, "with_pedagogy", None)
    if option is True and not initialize:
        raise AdminError(
            "--with-pedagogy exige --initialize afin de créer son organisation "
            "et son dossier."
        )
    return initialize and option is not False


def database_create(args: argparse.Namespace) -> int:
    target = args.path.resolve()
    if ROOT not in target.parents and not args.allow_outside_project:
        raise AdminError(
            "La base est hors du projet. Ajoutez --allow-outside-project pour confirmer ce périmètre."
        )
    print(f"Base cible : {target}")
    if target.exists():
        if not args.replace:
            raise AdminError("La base existe déjà. Utilisez --replace pour créer une sauvegarde puis la remplacer.")
        check_database(target)
        backup = backup_path(target, "before-init")
        print(f"Sauvegarde préalable : {backup}")
    else:
        backup = None
    initialize = bool(getattr(args, "initialize", False))
    with_pedagogy = pedagogy_enabled(args, initialize)
    args.with_pedagogy = with_pedagogy
    if initialize:
        password = str(
            getattr(args, "admin_password", "")
            or os.environ.get("COMPTA_ADMIN_PASSWORD", "")
        )
        validate_admin_password(password)
    print("Étapes : migrations SQL, catalogue des plans comptables"
          + (", initialisation de l’instance" if initialize else "")
          + (
              ", installation des parcours pédagogiques"
              if with_pedagogy
              else ""
          )
          + ", contrôle d’intégrité.")
    ensure_apply(args, "aucune base n’a été créée")

    target.parent.mkdir(parents=True, exist_ok=True)
    if backup is not None:
        backup_database(target, backup)
        print(f"Sauvegarde vérifiée : {backup}")
    remove_database_files(target)
    environment = database_environment(target)
    try:
        run(["php", "bin/console", "db:migrate", "--apply", "--backup"], env=environment)
        if not args.without_seeds:
            run(["php", "bin/console", "compta:plan-seed"], env=environment)
        if initialize:
            initialize_database(target, args, environment)
        run(["php", "bin/console", "db:integrity"], env=environment)
    except Exception:
        remove_database_files(target)
        if backup is not None:
            shutil.copy2(backup, target)
            print(f"Base précédente restaurée après échec : {backup}")
        raise
    summary = database_summary(target)
    print(f"Base créée et contrôlée : {target} ({summary['size']} octets)")
    if initialize:
        print(
            "Instance utilisable : "
            f"{summary['utilisateurs']} utilisateur(s), "
            f"{summary['organisations']} organisation(s), "
            f"{summary['dossiers']} dossier(s), "
            f"{summary['comptes']} compte(s), "
            f"{summary['modeles_pedagogiques']} parcours pédagogique(s)."
        )
    else:
        print(
            "Base technique vierge : aucun utilisateur, organisation ou dossier "
            "n’a été créé."
        )
    return 0


def database_restore(args: argparse.Namespace) -> int:
    source = args.source.resolve()
    target = args.path.resolve()
    if ROOT not in target.parents and not args.allow_outside_project:
        raise AdminError(
            "La base cible est hors du projet. Ajoutez "
            "--allow-outside-project pour confirmer ce périmètre."
        )
    check_database(source)
    if source == target:
        raise AdminError("La sauvegarde source et la base cible sont identiques.")
    previous = None
    if target.exists():
        check_database(target)
        previous = backup_path(target, "before-restore")
    source_summary = database_summary(source)
    source_fingerprints = database_fingerprints(source)
    print(f"Sauvegarde à restaurer : {source}")
    print_database_summary(source, "Contenu de la sauvegarde")
    print(f"Base cible : {target}")
    if previous is not None:
        print(f"Sauvegarde préalable de la cible : {previous}")
    ensure_apply(args, "aucune base n’a été restaurée")

    target.parent.mkdir(parents=True, exist_ok=True)
    if previous is not None:
        backup_database(target, previous)
        print(f"Sauvegarde vérifiée : {previous}")
    temporary = target.with_name(
        f".{target.name}.restore-{os.urandom(4).hex()}.sqlite"
    )
    try:
        backup_database(source, temporary)
        remove_database_files(target)
        os.replace(temporary, target)
        environment = database_environment(target)
        run(["php", "bin/console", "db:migrate", "--apply", "--backup"], env=environment)
        run(["php", "bin/console", "db:integrity"], env=environment)
        summary = database_summary(target)
        assert_restored_content(source_summary, summary)
        assert_restored_fingerprints(
            source_fingerprints,
            database_fingerprints(target),
        )
    except Exception:
        if temporary.exists():
            temporary.unlink()
        remove_database_files(target)
        if previous is not None:
            shutil.copy2(previous, target)
            print(f"Base précédente restaurée après échec : {previous}")
        raise
    print(
        f"Restauration terminée : {summary['organisations']} organisation(s), "
        f"{summary['dossiers']} dossier(s), {summary['comptes']} compte(s), "
        f"{summary['ecritures']} écriture(s), "
        f"{summary['modeles_pedagogiques']} parcours pédagogique(s)."
    )
    return 0


def status_paths() -> list[str]:
    raw = run(
        ["git", "status", "--porcelain=v1", "-z", "--untracked-files=all"],
        capture=True,
    ).stdout
    entries = raw.decode("utf-8", errors="surrogateescape").split("\0")
    paths: list[str] = []
    index = 0
    while index < len(entries):
        entry = entries[index]
        index += 1
        if not entry:
            continue
        status = entry[:2]
        path = entry[3:]
        paths.append(path)
        if ("R" in status or "C" in status) and index < len(entries):
            index += 1
    return sorted(set(paths))


def unsafe_git_path(path: str) -> bool:
    return (
        path == "config/local.php"
        or path.startswith("storage/")
        or path.endswith((".sqlite", ".sqlite-wal", ".sqlite-shm", ".log"))
        or Path(path).name in {".env", "compta.deploy.json"}
    )


def git_publish(args: argparse.Namespace) -> int:
    paths = status_paths()
    unsafe = [path for path in paths if unsafe_git_path(path)]
    if unsafe:
        raise AdminError("Fichiers locaux sensibles refusés par Git : " + ", ".join(unsafe))
    if not paths:
        print("Aucun changement à publier.")
        return 0
    print("Changements qui seront indexés :")
    for path in paths:
        print(f"  - {path}")
    print(f"Commit : {args.message}")
    print(f"Push : {args.remote}/{args.branch}")
    ensure_apply(args, "aucun commit ni push n’a été effectué")

    run(["git", "add", "-A", "--", *paths])
    run(["git", "diff", "--cached", "--check"])
    run(["git", "commit", "-m", args.message])
    run(["git", "push", args.remote, f"HEAD:{args.branch}"])
    print(f"Publication Git terminée : {git('rev-parse', '--short', 'HEAD')}")
    return 0


def is_runtime_path(path: str) -> bool:
    normalized = str(PurePosixPath(path))
    if normalized.startswith(EXCLUDED_PREFIXES):
        return False
    return normalized in RUNTIME_FILES or normalized.startswith(RUNTIME_PREFIXES)


def commit_exists(commit: str) -> bool:
    result = subprocess.run(
        ["git", "cat-file", "-e", f"{commit}^{{commit}}"],
        cwd=ROOT,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    return result.returncode == 0


def runtime_files_at(commit: str) -> list[str]:
    fields = run(
        ["git", "ls-tree", "-r", "--name-only", "-z", commit],
        capture=True,
    ).stdout.decode("utf-8", errors="surrogateescape").split("\0")
    return sorted({
        path
        for path in fields
        if path and is_runtime_path(path)
    })


def vendor_directory(root: Path) -> Path:
    project = root.expanduser().resolve()
    candidates = (project / "vendor", project.parent / "vendor")
    for candidate in candidates:
        if (
            (candidate / "autoload.php").is_file()
            and (candidate / "composer/installed.json").is_file()
        ):
            return candidate
    raise AdminError(
        "Dépendances PHP absentes. vendor a été recherché dans "
        f"{candidates[0]} puis {candidates[1]}."
    )


def ensure_vendor_ready(commit: str) -> None:
    lock = git_bytes(commit, "composer.lock")
    local_lock = ROOT / "composer.lock"
    vendor = vendor_directory(ROOT)
    installed = vendor / "composer" / "installed.json"
    autoload = vendor / "autoload.php"
    if not local_lock.is_file() or local_lock.read_bytes() != lock:
        raise AdminError(
            "Le composer.lock local ne correspond pas au commit ciblé."
        )
    if not installed.is_file() or not autoload.is_file():
        raise AdminError(
            "Dépendances PHP absentes. Exécutez composer install avant le "
            "déploiement."
        )
    locked_data = json.loads(lock.decode("utf-8"))
    installed_data = json.loads(installed.read_text(encoding="utf-8"))
    installed_packages = installed_data.get("packages", installed_data)
    locked_versions = {
        str(package["name"]): str(package["version"])
        for package in locked_data.get("packages", [])
    }
    installed_versions = {
        str(package["name"]): str(package["version"])
        for package in installed_packages
        if isinstance(package, dict)
        and package.get("name")
        and package.get("version")
    }
    mismatches = [
        name
        for name, version in locked_versions.items()
        if installed_versions.get(name) != version
    ]
    if mismatches:
        raise AdminError(
            "Le dossier vendor ne correspond pas à composer.lock : "
            + ", ".join(mismatches)
        )


def vendor_files() -> list[str]:
    root = vendor_directory(ROOT)
    return sorted(
        f"vendor/{path.relative_to(root).as_posix()}"
        for path in root.rglob("*")
        if path.is_file()
    )


def deployment_files_at(commit: str) -> list[str]:
    return sorted(set(runtime_files_at(commit) + vendor_files()))


def changed_runtime_files(baseline: str | None, target: str) -> tuple[list[str], list[str]]:
    if baseline is None:
        return deployment_files_at(target), []
    if baseline:
        if not commit_exists(baseline):
            raise AdminError(f"Commit de référence absent localement : {baseline}")
        ancestor = subprocess.run(
            ["git", "merge-base", "--is-ancestor", baseline, target],
            cwd=ROOT,
        )
        if ancestor.returncode != 0:
            raise AdminError("Le commit distant n’est pas un ancêtre du commit à déployer.")
        command = ["git", "diff", "--name-status", "--no-renames", "-z", baseline, target]
    fields = run(command, capture=True).stdout.decode(
        "utf-8", errors="surrogateescape"
    ).split("\0")
    uploads: list[str] = []
    deletions: list[str] = []
    index = 0
    while index + 1 < len(fields):
        status, path = fields[index], fields[index + 1]
        index += 2
        if not status or not path or not is_runtime_path(path):
            continue
        (deletions if status.startswith("D") else uploads).append(path)
    if "composer.lock" in uploads:
        uploads.extend(vendor_files())
    return sorted(set(uploads)), sorted(set(deletions))


def git_bytes(commit: str, path: str) -> bytes:
    return run(["git", "show", f"{commit}:{path}"], capture=True).stdout


def deployment_bytes(commit: str, path: str) -> bytes:
    if path.startswith("vendor/"):
        source = vendor_directory(ROOT) / path.removeprefix("vendor/")
        if not source.is_file():
            raise AdminError(f"Dépendance PHP locale introuvable : {path}")
        return source.read_bytes()
    return git_bytes(commit, path)


def is_direct_install_path(path: str) -> bool:
    normalized = str(PurePosixPath(path))
    return (
        normalized.startswith("vendor/")
        or is_runtime_path(normalized)
    )


def resolved_vendor_mode(source: Path, requested: str) -> tuple[str, Path]:
    if requested not in {"auto", "local", "shared", "skip"}:
        raise AdminError("Le mode vendor doit valoir auto, local, shared ou skip.")
    root = source.expanduser().resolve()
    vendor = vendor_directory(root)
    if requested == "auto":
        requested = "local" if vendor.parent == root else "shared"
    return requested, vendor


def direct_source_path(source: Path, path: str, vendor: Path) -> Path:
    if path.startswith("vendor/"):
        return vendor / path.removeprefix("vendor/")
    return source.expanduser().resolve() / path


def direct_remote_path(path: str, vendor_mode: str) -> str:
    if path.startswith("vendor/") and vendor_mode == "shared":
        return f"../vendor/{path.removeprefix('vendor/')}"
    return path


def direct_install_files(
    source: Path,
    *,
    include_vendor: bool = True,
) -> list[str]:
    root = source.expanduser().resolve()
    if not root.is_dir():
        raise AdminError(f"Répertoire source introuvable : {root}")
    vendor = vendor_directory(root)
    files: list[str] = []
    for candidate in root.rglob("*"):
        if candidate.is_symlink():
            relative = candidate.relative_to(root).as_posix()
            if is_direct_install_path(relative):
                raise AdminError(
                    f"Lien symbolique refusé dans la livraison : {relative}"
                )
            continue
        if not candidate.is_file():
            continue
        relative = candidate.relative_to(root).as_posix()
        if relative.startswith("vendor/"):
            continue
        if is_direct_install_path(relative):
            files.append(relative)
    if include_vendor:
        for candidate in vendor.rglob("*"):
            if candidate.is_symlink():
                relative = candidate.relative_to(vendor).as_posix()
                raise AdminError(
                    f"Lien symbolique refusé dans vendor : {relative}"
                )
            if candidate.is_file():
                files.append(
                    f"vendor/{candidate.relative_to(vendor).as_posix()}"
                )
    inventory = sorted(set(files))
    missing = sorted(DIRECT_INSTALL_REQUIRED_FILES - set(inventory))
    if include_vendor and "vendor/autoload.php" not in inventory:
        missing.append("vendor/autoload.php")
    for prefix in DIRECT_INSTALL_REQUIRED_PREFIXES:
        if not any(path.startswith(prefix) for path in inventory):
            missing.append(f"{prefix}*")
    if missing:
        raise AdminError(
            "Le dossier source n’est pas une livraison Compta exécutable. "
            "Éléments manquants : " + ", ".join(missing)
        )
    try:
        vite = json.loads(
            (root / "public/app/.vite/manifest.json").read_text(encoding="utf-8")
        )
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise AdminError("Le manifeste du build Vue est invalide.") from error
    assets: set[str] = set()
    for entry in vite.values():
        if not isinstance(entry, dict):
            continue
        if isinstance(entry.get("file"), str):
            assets.add(f"public/app/{entry['file']}")
        for key in ("css", "assets"):
            values = entry.get(key, [])
            if isinstance(values, list):
                assets.update(
                    f"public/app/{path}"
                    for path in values
                    if isinstance(path, str)
                )
    missing_assets = sorted(assets - set(inventory))
    if missing_assets:
        raise AdminError(
            "Le build Vue est incomplet. Assets absents : "
            + ", ".join(missing_assets)
        )
    inventory = [
        path
        for path in inventory
        if not path.startswith("public/app/assets/") or path in assets
    ]
    return inventory


def direct_install_manifest(
    source: Path,
    inventory: list[str],
    *,
    vendor_mode: str = "local",
    vendor: Path | None = None,
) -> dict[str, Any]:
    root = source.expanduser().resolve()
    resolved_vendor = vendor or vendor_directory(root)
    fingerprint = hashlib.sha256()
    files: list[dict[str, Any]] = []
    for path in inventory:
        source_path = direct_source_path(root, path, resolved_vendor)
        content = source_path.read_bytes()
        digest = hashlib.sha256(content).hexdigest()
        remote_path = direct_remote_path(path, vendor_mode)
        fingerprint.update(path.encode("utf-8"))
        fingerprint.update(b"\0")
        fingerprint.update(remote_path.encode("utf-8"))
        fingerprint.update(b"\0")
        fingerprint.update(digest.encode("ascii"))
        fingerprint.update(b"\0")
        files.append({
            "path": path,
            "size": len(content),
            "sha256": digest,
            "source": "directory",
            "remote_path": remote_path,
        })
    version = (root / "VERSION").read_text(encoding="utf-8").strip()
    return {
        "schema": DIRECT_INSTALL_MANIFEST_SCHEMA,
        "application": "webeli-compta",
        "deployment_kind": "complete-runtime-install",
        "vendor_mode": vendor_mode,
        "vendor_source": (
            "none"
            if vendor_mode == "skip"
            else "parent"
            if resolved_vendor.parent != root
            else "local"
        ),
        "source_fingerprint": fingerprint.hexdigest(),
        "version": version,
        "deployed_at": datetime.now(timezone.utc).isoformat(),
        "files": files,
        "uploads": inventory,
        "deletions": [],
    }


def normalize_remote_root(value: str) -> str:
    candidate = value.strip()
    if not candidate.startswith("/"):
        raise AdminError("Le répertoire FTP d’arrivée doit être un chemin absolu.")
    if any(part in {".", ".."} for part in candidate.split("/")):
        raise AdminError("Le répertoire FTP d’arrivée contient un segment interdit.")
    if "\r" in candidate or "\n" in candidate:
        raise AdminError("Le répertoire FTP d’arrivée est invalide.")
    normalized = "/" + "/".join(part for part in candidate.split("/") if part)
    return normalized if normalized != "" else "/"


def direct_manifest_is_valid(
    manifest: dict[str, Any] | None,
    expected_fingerprint: str,
    expected_paths: list[str],
) -> bool:
    if not manifest:
        return False
    files = manifest.get("files")
    return (
        manifest.get("schema") == DIRECT_INSTALL_MANIFEST_SCHEMA
        and manifest.get("application") == "webeli-compta"
        and manifest.get("deployment_kind") == "complete-runtime-install"
        and manifest.get("source_fingerprint") == expected_fingerprint
        and isinstance(files, list)
        and sorted(
            str(item.get("path"))
            for item in files
            if isinstance(item, dict)
        ) == sorted(expected_paths)
    )


def install_directory_ftp(
    config: dict[str, Any],
    source: Path,
    remote_root: str,
    inventory: list[str],
    manifest: dict[str, Any],
    replace_runtime: bool = False,
    replace_shared_vendor: bool = False,
) -> None:
    root = source.expanduser().resolve()
    vendor = vendor_directory(root)
    entries = {
        str(item["path"]): item
        for item in manifest["files"]
    }
    expected = {
        path: str(item["sha256"])
        for path, item in entries.items()
    }
    vendor_mode = str(manifest.get("vendor_mode", "local"))

    def remote_path(path: str) -> str:
        relative = str(entries[path].get("remote_path", path))
        return posixpath.normpath(posixpath.join(remote_root, relative))

    with ftp_connect(config) as client:
        if not replace_runtime:
            existing_marker = ftp_read_bytes(
                client,
                posixpath.join(remote_root, REMOTE_MANIFEST),
                missing_ok=True,
            )
            existing_entry = ftp_read_bytes(
                client,
                posixpath.join(remote_root, "index.php"),
                missing_ok=True,
            )
            if existing_marker is not None or existing_entry is not None:
                raise AdminError(
                    "Le répertoire FTP d’arrivée contient déjà une installation. "
                    "Utilisez deploy pour une mise à jour, ou --replace-runtime "
                    "pour remplacer explicitement ses fichiers applicatifs."
                )
        skipped_vendor: set[str] = set()
        local_installed = (vendor / "composer/installed.json").read_bytes()
        if vendor_mode == "skip":
            dependency_roots = (
                posixpath.join(remote_root, "vendor"),
                posixpath.join(posixpath.dirname(remote_root), "vendor"),
            )
            selected = ""
            for dependency_root in dependency_roots:
                autoload = ftp_read_bytes(
                    client,
                    posixpath.join(dependency_root, "autoload.php"),
                    missing_ok=True,
                )
                if autoload is None:
                    continue
                installed = ftp_read_bytes(
                    client,
                    posixpath.join(dependency_root, "composer/installed.json"),
                    missing_ok=True,
                )
                if installed != local_installed:
                    raise AdminError(
                        "Le vendor distant existe mais ne correspond pas au "
                        "composer.lock de cette instance."
                    )
                selected = dependency_root
                break
            if not selected:
                raise AdminError(
                    "Transfert sans vendor impossible : aucun vendor compatible "
                    "dans ./vendor ou ../vendor sur la destination."
                )
            print(f"Vendor distant réutilisé : {selected}")
        elif vendor_mode == "shared":
            shared_root = posixpath.join(posixpath.dirname(remote_root), "vendor")
            remote_autoload = ftp_read_bytes(
                client,
                posixpath.join(shared_root, "autoload.php"),
                missing_ok=True,
            )
            if remote_autoload is not None:
                remote_installed = ftp_read_bytes(
                    client,
                    posixpath.join(shared_root, "composer/installed.json"),
                    missing_ok=True,
                )
                if remote_installed == local_installed:
                    skipped_vendor = {
                        path for path in inventory if path.startswith("vendor/")
                    }
                    print(
                        "Vendor mutualisé déjà compatible : transfert des "
                        "dépendances ignoré."
                    )
                elif not replace_shared_vendor:
                    raise AdminError(
                        "Le vendor mutualisé distant utilise d’autres versions. "
                        "Refus de l’écraser car plusieurs instances peuvent en "
                        "dépendre. Utilisez --replace-shared-vendor uniquement "
                        "après contrôle de toutes les instances."
                    )
        created_directories: set[str] = set()
        uploads = [path for path in inventory if path not in skipped_vendor]
        total = len(uploads)
        for index, path in enumerate(uploads, start=1):
            remote = remote_path(path)
            directory = posixpath.dirname(remote)
            if directory not in created_directories:
                ftp_mkdirs(client, directory)
                created_directories.add(directory)
            with direct_source_path(root, path, vendor).open("rb") as stream:
                client.storbinary(f"STOR {remote}", stream)
            if index == 1 or index % 50 == 0 or index == total:
                print(f"Envoi FTP : {index}/{total} fichiers")
        for index, path in enumerate(uploads, start=1):
            deployed = ftp_read_bytes(client, remote_path(path))
            if deployed is None or hashlib.sha256(deployed).hexdigest() != expected[path]:
                raise AdminError(
                    f"Le contrôle après transfert a échoué pour {path}."
                )
            if index == 1 or index % 50 == 0 or index == total:
                print(f"Vérification FTP : {index}/{total} fichiers")
        payload = (
            json.dumps(manifest, ensure_ascii=False, indent=2).encode("utf-8")
            + b"\n"
        )
        fingerprint = str(manifest["source_fingerprint"])
        release_path = posixpath.join(
            remote_root,
            REMOTE_RELEASES,
            f"install-{fingerprint[:16]}.json",
        )
        ftp_mkdirs(client, posixpath.dirname(release_path))
        client.storbinary(f"STOR {release_path}", io.BytesIO(payload))
        current_path = posixpath.join(remote_root, REMOTE_MANIFEST)
        ftp_mkdirs(client, posixpath.dirname(current_path))
        client.storbinary(f"STOR {current_path}", io.BytesIO(payload))
        stored = ftp_read_json(client, current_path)
        if not direct_manifest_is_valid(stored, fingerprint, inventory):
            raise AdminError("Le marqueur de l’installation FTP n’a pas pu être vérifié.")


def ftp_install(args: argparse.Namespace) -> int:
    config = (
        dict(args.connection_config)
        if getattr(args, "connection_config", None) is not None
        else load_config(args.config.resolve())
    )
    if config.get("transport") not in {"ftp", "ftps"}:
        raise AdminError("L’installation directe exige un transport ftp ou ftps.")
    source = args.source.expanduser().resolve()
    remote_root = normalize_remote_root(
        args.remote_root or str(config.get("remote_root", ""))
    )
    requested_vendor_mode = str(
        getattr(args, "vendor_mode", None)
        or config.get("vendor_mode")
        or "auto"
    )
    vendor_mode, vendor = resolved_vendor_mode(
        source,
        requested_vendor_mode,
    )
    inventory = direct_install_files(
        source,
        include_vendor=vendor_mode != "skip",
    )
    manifest = direct_install_manifest(
        source,
        inventory,
        vendor_mode=vendor_mode,
        vendor=vendor,
    )
    total_bytes = sum(int(item["size"]) for item in manifest["files"])
    print(f"Répertoire source : {source}")
    print(f"Répertoire FTP d’arrivée : {remote_root}")
    print(f"Vendor détecté : {vendor}")
    print(
        "Gestion de vendor : "
        + {
            "local": "transféré dans ./vendor",
            "shared": "mutualisé dans ../vendor",
            "skip": "non transféré, vendor distant existant requis",
        }[vendor_mode]
    )
    print(
        f"Livraison runtime : {len(inventory)} fichier(s), "
        f"{total_bytes / (1024 * 1024):.1f} Mio"
    )
    print(
        "Exclus : sources frontend, tests, outils, Git, node_modules, "
        "secrets, bases SQLite, journaux et stockage persistant."
    )
    if config.get("transport") == "ftp":
        print(
            "ATTENTION : FTP transmet les identifiants et les fichiers sans "
            "chiffrement. FTPS est recommandé."
        )
    if args.list_files:
        for path in inventory:
            print(f"  + {path}")
    print(
        "IMPORTANT : cette commande installe le moteur applicatif d’un nouveau "
        "site. La base et la configuration locale restent à provisionner "
        "séparément."
    )
    if getattr(args, "interactive_confirmation", False):
        if not confirm("Envoyer cette livraison complète par FTP"):
            print("Opération annulée.")
            return 0
        args.apply = True
    ensure_apply(args, "aucun fichier FTP n’a été modifié")
    install_directory_ftp(
        config,
        source,
        remote_root,
        inventory,
        manifest,
        bool(getattr(args, "replace_runtime", False)),
        bool(getattr(args, "replace_shared_vendor", False)),
    )
    print(
        "Installation applicative FTP terminée et vérifiée. "
        f"Marqueur distant : {REMOTE_MANIFEST}"
    )
    return 0


def release_manifest(
    target: str,
    baseline: str | None,
    uploads: list[str],
    deletions: list[str],
) -> dict[str, Any]:
    inventory = deployment_files_at(target)
    files = []
    for path in inventory:
        content = deployment_bytes(target, path)
        files.append({
            "path": path,
            "size": len(content),
            "sha256": hashlib.sha256(content).hexdigest(),
            "source": "composer" if path.startswith("vendor/") else "git",
        })
    version = (
        git_bytes(target, "VERSION").decode("utf-8").strip()
        if "VERSION" in inventory
        else ""
    )
    return {
        "schema": DEPLOY_MANIFEST_SCHEMA,
        "application": "webeli-compta",
        "commit": target,
        "previous_commit": baseline,
        "version": version,
        "deployed_at": datetime.now(timezone.utc).isoformat(),
        "files": files,
        "uploads": uploads,
        "deletions": deletions,
    }


def load_config(path: Path) -> dict[str, Any]:
    if not path.is_file():
        raise AdminError(
            f"Configuration absente : {path}. Copiez ops/compta.deploy.example.json."
        )
    data = json.loads(path.read_text(encoding="utf-8"))
    if data.get("transport") not in {"local", "ftp", "ftps"}:
        raise AdminError("transport doit valoir local, ftp ou ftps.")
    return data


def local_remote_manifest(config: dict[str, Any]) -> dict[str, Any] | None:
    path = Path(config["target"]).expanduser().resolve() / REMOTE_MANIFEST
    if not path.is_file():
        return None
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as error:
        raise AdminError(f"Marqueur de déploiement local invalide : {path}") from error


def ftp_connect(config: dict[str, Any]) -> ftplib.FTP:
    secure = config["transport"] == "ftps"
    client: ftplib.FTP = ftplib.FTP_TLS() if secure else ftplib.FTP()
    client.connect(config["host"], int(config.get("port", 21)), int(config.get("timeout", 30)))
    client.login(config["username"], config["password"])
    if secure and isinstance(client, ftplib.FTP_TLS):
        client.prot_p()
    client.set_pasv(bool(config.get("passive", True)))
    return client


def ftp_read_json(client: ftplib.FTP, path: str) -> dict[str, Any] | None:
    content = ftp_read_bytes(client, path, missing_ok=True)
    if content is None:
        return None
    try:
        return json.loads(content.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise AdminError(f"Marqueur de déploiement distant invalide : {path}") from error


def ftp_read_bytes(
    client: ftplib.FTP,
    path: str,
    *,
    missing_ok: bool = False,
) -> bytes | None:
    chunks: list[bytes] = []
    try:
        client.retrbinary(f"RETR {path}", chunks.append)
    except ftplib.error_perm as error:
        if missing_ok and str(error).startswith("550"):
            return None
        raise
    return b"".join(chunks)


def ftp_mkdirs(client: ftplib.FTP, directory: str) -> None:
    current = ""
    for part in directory.split("/"):
        if not part:
            continue
        current += "/" + part
        try:
            client.mkd(current)
        except ftplib.error_perm as error:
            if not str(error).startswith("550"):
                raise


def manifest_has_complete_inventory(manifest: dict[str, Any] | None) -> bool:
    if not manifest:
        return False
    files = manifest.get("files")
    commit = manifest.get("commit")
    structurally_valid = (
        manifest.get("application") == "webeli-compta"
        and manifest.get("schema") == DEPLOY_MANIFEST_SCHEMA
        and isinstance(commit, str)
        and commit_exists(commit)
        and isinstance(files, list)
        and bool(files)
        and all(
            isinstance(item, dict)
            and isinstance(item.get("path"), str)
            and isinstance(item.get("sha256"), str)
            for item in files
        )
    )
    if not structurally_valid:
        return False
    recorded_paths = sorted({
        str(item["path"])
        for item in files
    })
    return recorded_paths == deployment_files_at(commit)


def verify_local_uploads(root: Path, target: str, uploads: list[str]) -> None:
    for path in uploads:
        destination = root / path
        if not destination.is_file():
            raise AdminError(f"Fichier déployé introuvable : {destination}")
        expected = deployment_bytes(target, path)
        if destination.read_bytes() != expected:
            raise AdminError(f"Fichier déployé altéré : {destination}")


def deploy_local(
    config: dict[str, Any],
    target: str,
    uploads: list[str],
    deletions: list[str],
    manifest: dict[str, Any],
    delete: bool,
) -> None:
    root = Path(config["target"]).expanduser().resolve()
    root.mkdir(parents=True, exist_ok=True)
    for path in uploads:
        destination = root / path
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(deployment_bytes(target, path))
    if delete:
        for path in deletions:
            destination = root / path
            if destination.is_file():
                destination.unlink()
    verify_local_uploads(root, target, uploads)
    current = root / REMOTE_MANIFEST
    current.parent.mkdir(parents=True, exist_ok=True)
    current.write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    release = root / REMOTE_RELEASES / f"{target}.json"
    release.parent.mkdir(parents=True, exist_ok=True)
    release.write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    stored = local_remote_manifest(config)
    if (
        not manifest_has_complete_inventory(stored)
        or stored.get("commit") != target
    ):
        raise AdminError("Le marqueur local n’a pas pu être vérifié.")


def deploy_ftp(
    config: dict[str, Any],
    target: str,
    uploads: list[str],
    deletions: list[str],
    manifest: dict[str, Any],
    delete: bool,
) -> None:
    root = str(config["remote_root"]).rstrip("/")
    with ftp_connect(config) as client:
        expected_uploads: dict[str, bytes] = {}
        for path in uploads:
            remote = posixpath.join(root, path)
            ftp_mkdirs(client, posixpath.dirname(remote))
            content = deployment_bytes(target, path)
            expected_uploads[path] = content
            client.storbinary(f"STOR {remote}", io.BytesIO(content))
        if delete:
            for path in deletions:
                try:
                    client.delete(posixpath.join(root, path))
                except ftplib.error_perm as error:
                    if not str(error).startswith("550"):
                        raise
        for path, expected in expected_uploads.items():
            remote = posixpath.join(root, path)
            deployed = ftp_read_bytes(client, remote)
            if deployed != expected:
                raise AdminError(
                    f"Le contrôle après transfert a échoué pour {path}."
                )
        payload = json.dumps(manifest, ensure_ascii=False, indent=2).encode("utf-8") + b"\n"
        release_path = posixpath.join(root, REMOTE_RELEASES, f"{target}.json")
        ftp_mkdirs(client, posixpath.dirname(release_path))
        client.storbinary(f"STOR {release_path}", io.BytesIO(payload))
        ftp_mkdirs(client, posixpath.dirname(posixpath.join(root, REMOTE_MANIFEST)))
        client.storbinary(
            f"STOR {posixpath.join(root, REMOTE_MANIFEST)}",
            io.BytesIO(payload),
        )
        stored = ftp_read_json(client, posixpath.join(root, REMOTE_MANIFEST))
        if (
            not manifest_has_complete_inventory(stored)
            or stored.get("commit") != target
        ):
            raise AdminError("Le marqueur distant n’a pas pu être vérifié.")


def deploy(args: argparse.Namespace) -> int:
    config = load_config(args.config.resolve())
    target = git("rev-parse", args.commit)
    ensure_vendor_ready(target)
    baseline = args.from_commit
    complete_resync = False
    resync_reason = ""
    current: dict[str, Any] | None = None
    client: ftplib.FTP | None = None
    try:
        if baseline is None:
            if config["transport"] == "local":
                current = local_remote_manifest(config)
            else:
                client = ftp_connect(config)
                root = str(config["remote_root"]).rstrip("/")
                current = ftp_read_json(client, posixpath.join(root, REMOTE_MANIFEST))
            baseline = str(current["commit"]) if current and current.get("commit") else None
            complete_resync = not manifest_has_complete_inventory(current)
            if current is None:
                resync_reason = "aucun inventaire distant fiable"
            elif complete_resync:
                resync_reason = "ancien marqueur incomplet"
    finally:
        if client is not None:
            client.quit()
    if baseline and not commit_exists(baseline):
        complete_resync = True
        resync_reason = "commit distant absent du dépôt local"
        baseline = None
    uploads, deletions = changed_runtime_files(baseline, target)
    if complete_resync:
        uploads = deployment_files_at(target)
    if (
        args.delete
        and current
        and isinstance(current.get("files"), list)
        and "composer.lock" in uploads
    ):
        current_vendor = {
            str(item["path"])
            for item in current["files"]
            if isinstance(item, dict)
            and str(item.get("path", "")).startswith("vendor/")
        }
        deletions = sorted(set(deletions) | (
            current_vendor - set(vendor_files())
        ))
    manifest = release_manifest(target, baseline, uploads, deletions)
    print(f"Déploiement : {baseline or 'installation initiale'} -> {target}")
    if complete_resync:
        print(
            "Resynchronisation complète : "
            f"{resync_reason}; tous les fichiers applicatifs seront envoyés."
        )
    if baseline is None:
        print(
            "IMPORTANT : ce transfert installe le code et ses dépendances, "
            "mais n’écrase ni ne crée la base SQLite ou les secrets persistants."
        )
    print(f"Fichiers applicatifs à envoyer : {len(uploads)}")
    for path in uploads:
        print(f"  + {path}")
    print(f"Fichiers devenus obsolètes : {len(deletions)}")
    for path in deletions:
        print(f"  - {path}{'' if args.delete else ' (conservé sans --delete)'}")
    if not uploads and not (args.delete and deletions):
        print("Le site est déjà aligné sur les fichiers applicatifs de ce commit.")
    if getattr(args, "interactive_confirmation", False):
        if not confirm("Déployer maintenant les fichiers affichés ci-dessus"):
            print("Opération annulée.")
            return 0
        args.apply = True
    ensure_apply(args, "aucun fichier distant n’a été modifié")
    if config["transport"] == "local":
        deploy_local(config, target, uploads, deletions, manifest, args.delete)
    else:
        deploy_ftp(config, target, uploads, deletions, manifest, args.delete)
    print(
        "Transfert applicatif terminé et vérifié. "
        f"Marqueur distant : {REMOTE_MANIFEST}"
    )
    return 0


def ask(prompt: str, default: str = "") -> str:
    suffix = f" [{default}]" if default else ""
    value = input(f"{prompt}{suffix} : ").strip()
    return value or default


def confirm(prompt: str, *, default: bool = False) -> bool:
    suffix = "[O/n]" if default else "[o/N]"
    answer = input(f"{prompt} {suffix} : ").strip().lower()
    if answer == "":
        return default
    return answer in {"o", "oui", "y", "yes"}


def interactive_create_database(initialize: bool) -> int:
    target = Path(ask(
        "Chemin de la base",
        str(ROOT / "storage" / "database" / "app.sqlite"),
    ))
    if not target.is_absolute():
        target = ROOT / target
    replace = target.exists() and confirm(
        "La base existe. La sauvegarder puis la remplacer"
    )
    if target.exists() and not replace:
        print("Opération annulée : la base existante est conservée.")
        return 0
    without_seeds = False if initialize else not confirm(
        "Charger le catalogue des plans comptables"
    )
    values: dict[str, Any] = {
        "initialize": initialize,
        "admin_password": "",
        "admin_email": "admin@example.test",
        "organisation": "Mon organisation",
        "nature": "reelle",
        "dossier": "Comptabilité",
        "slug": "comptabilite",
        "dossier_type": "reel",
        "currency": "CHF",
        "exercise": str(datetime.now().year),
        "start": "",
        "end": "",
        "modules": "liquidites,facturation,comptabilite,salaires",
        "plan_variant": "personne_morale",
        "association": False,
        "with_pedagogy": initialize,
        "pedagogy_organisation": "École WebeLi",
        "pedagogy_dossier": "Démonstration guidée",
        "pedagogy_slug": "demonstration-guidee",
    }
    if initialize:
        print()
        print("Première instance")
        print("-" * 42)
        values["admin_email"] = ask(
            "Adresse e-mail de l’administrateur",
            values["admin_email"],
        )
        while True:
            password = getpass.getpass(
                "Mot de passe administrateur "
                "(12 caractères minimum, non prévisible ; vide pour annuler) : "
            )
            if password == "":
                print("Opération annulée.")
                return 0
            confirmation = getpass.getpass("Confirmez le mot de passe : ")
            if password != confirmation:
                print("Mot de passe refusé : les deux saisies sont différentes.")
                continue
            try:
                validate_admin_password(password)
            except AdminError as error:
                print(f"Mot de passe refusé : {error}")
                continue
            break
        values["admin_password"] = password
        values["organisation"] = ask(
            "Nom de l’organisation",
            values["organisation"],
        )
        values["dossier"] = ask("Nom du dossier", values["dossier"])
        values["slug"] = ask("Identifiant URL du dossier", values["slug"])
        values["currency"] = ask("Devise de base", values["currency"]).upper()
        values["exercise"] = ask("Exercice", values["exercise"])
        values["start"] = ask(
            "Début de l’exercice",
            f"{values['exercise']}-01-01",
        )
        values["end"] = ask(
            "Fin de l’exercice",
            f"{values['exercise']}-12-31",
        )
        values["association"] = confirm(
            "Ajouter l’overlay du plan comptable"
        )
    if not confirm("Créer maintenant cette base"):
        print("Opération annulée.")
        return 0
    return database_create(argparse.Namespace(
        path=target,
        replace=replace,
        without_seeds=without_seeds,
        allow_outside_project=False,
        apply=True,
        **values,
    ))


def interactive_restore_database() -> int:
    directory = ROOT / "storage" / "backups"
    candidates = sorted(
        directory.glob("*.sqlite"),
        key=lambda path: path.stat().st_mtime,
        reverse=True,
    )[:10] if directory.is_dir() else []
    if candidates:
        print()
        print("Sauvegardes récentes")
        for path in candidates:
            print(
                f"  - {path} "
                f"({path.stat().st_size / (1024 * 1024):.2f} Mio)"
            )
    source_value = ask("Chemin exact de la sauvegarde à restaurer")
    if not source_value:
        print("Opération annulée : choisissez une sauvegarde.")
        return 0
    source = Path(source_value)
    if not source.is_absolute():
        source = ROOT / source
    target = Path(ask(
        "Base cible",
        str(ROOT / "storage" / "database" / "app.sqlite"),
    ))
    if not target.is_absolute():
        target = ROOT / target
    if not confirm(
        "Restaurer cette sauvegarde, après sauvegarde de la base cible"
    ):
        print("Opération annulée.")
        return 0
    return database_restore(argparse.Namespace(
        source=source,
        path=target,
        allow_outside_project=False,
        apply=True,
    ))


def interactive_backup_database() -> int:
    source = Path(ask(
        "Base source",
        str(ROOT / "storage" / "database" / "app.sqlite"),
    ))
    if not source.is_absolute():
        source = ROOT / source
    suggested = backup_path(source, "portable")
    output = Path(ask("Fichier autonome à créer", str(suggested)))
    if not output.is_absolute():
        output = ROOT / output
    if not confirm("Créer et contrôler cette photographie SQLite"):
        print("Opération annulée.")
        return 0
    return database_backup(argparse.Namespace(
        source=source,
        output=output,
        allow_outside_project=False,
        apply=True,
    ))


def interactive_inspect_database() -> int:
    target = Path(ask(
        "Base à auditer en lecture seule",
        str(ROOT / "storage" / "database" / "app.sqlite"),
    ))
    if not target.is_absolute():
        target = ROOT / target
    return database_inspect(argparse.Namespace(path=target))


def interactive_database() -> int:
    while True:
        print()
        print("Bases de données")
        print("-" * 42)
        print(" 1. Créer une instance utilisable (recommandé)")
        print(" 2. Créer uniquement une base technique vierge")
        print(" 3. Restaurer une sauvegarde existante")
        print(" 4. Créer une photographie SQLite autonome")
        print(" 5. Auditer une base sans la modifier")
        print(" 0. Retour")
        try:
            choice = input("Votre choix : ").strip()
        except (EOFError, KeyboardInterrupt):
            print()
            return 0
        if choice == "0":
            return 0
        if choice == "1":
            return interactive_create_database(True)
        if choice == "2":
            print(
                "ATTENTION : ce mode ne crée ni utilisateur, ni organisation, "
                "ni dossier."
            )
            return interactive_create_database(False)
        if choice == "3":
            return interactive_restore_database()
        if choice == "4":
            return interactive_backup_database()
        if choice == "5":
            return interactive_inspect_database()
        print("Choix invalide.")


def interactive_publish() -> int:
    message = ask("Message du commit")
    if not message:
        print("Opération annulée : le message du commit est obligatoire.")
        return 0
    remote = ask("Dépôt distant", "origin")
    branch = ask("Branche distante", "main")
    if not confirm(f"Créer le commit et le pousser vers {remote}/{branch}"):
        print("Opération annulée.")
        return 0
    return git_publish(argparse.Namespace(
        message=message,
        remote=remote,
        branch=branch,
        apply=True,
    ))


def interactive_deploy() -> int:
    config = Path(ask("Fichier de configuration", str(DEFAULT_DEPLOY_CONFIG)))
    if not config.is_absolute():
        config = ROOT / config
    commit = ask("Commit à déployer", "HEAD")
    from_commit = ask("Commit distant de départ (vide = détection automatique)")
    delete = confirm("Supprimer aussi les fichiers applicatifs devenus obsolètes")
    return deploy(argparse.Namespace(
        config=config,
        commit=commit,
        from_commit=from_commit or None,
        delete=delete,
        apply=False,
        interactive_confirmation=True,
    ))


def interactive_ftp_install() -> int:
    source = Path(ask("Répertoire local de départ", str(ROOT)))
    if not source.is_absolute():
        source = ROOT / source
    detected_vendor = vendor_directory(source)
    print(f"Vendor détecté : {detected_vendor}")
    transfer_vendor = confirm("Transférer le répertoire vendor", default=True)
    if transfer_vendor:
        shared_default = detected_vendor.parent != source.expanduser().resolve()
        vendor_mode = (
            "shared"
            if confirm(
                "Mutualiser vendor dans le répertoire parent distant",
                default=shared_default,
            )
            else "local"
        )
    else:
        vendor_mode = "skip"
    config_path = Path(ask(
        "Fichier de connexion FTP/FTPS",
        str(DEFAULT_DEPLOY_CONFIG),
    ))
    if not config_path.is_absolute():
        config_path = ROOT / config_path
    config = load_config(config_path.resolve())
    if config.get("transport") not in {"ftp", "ftps"}:
        raise AdminError("Le fichier choisi ne configure pas une connexion FTP/FTPS.")
    remote_root = ask(
        "Répertoire FTP d’arrivée",
        str(config.get("remote_root", "")),
    )
    list_files = confirm("Afficher la liste détaillée des fichiers avant l’envoi")
    replace_runtime = confirm(
        "Autoriser le remplacement si ce répertoire contient déjà une installation"
    )
    replace_shared_vendor = (
        vendor_mode == "shared"
        and confirm(
            "Autoriser le remplacement d’un vendor mutualisé incompatible"
        )
    )
    return ftp_install(argparse.Namespace(
        config=config_path,
        connection_config=config,
        source=source,
        remote_root=remote_root,
        vendor_mode=vendor_mode,
        list_files=list_files,
        replace_runtime=replace_runtime,
        replace_shared_vendor=replace_shared_vendor,
        apply=False,
        interactive_confirmation=True,
    ))


def interactive_menu() -> int:
    actions = {
        "1": ("Vérifier l’environnement et les extensions PHP", lambda: run(
            ["php", "bin/console", "app:doctor"]
        ).returncode),
        "2": ("Lancer la qualification complète", lambda: run(
            ["php", "bin/console", "qualify"]
        ).returncode),
        "3": ("Créer ou restaurer une base de données", interactive_database),
        "4": (
            "Créer une photographie SQLite autonome",
            interactive_backup_database,
        ),
        "5": ("Créer un commit Git puis le pousser", interactive_publish),
        "6": ("Déployer le delta applicatif versionné", interactive_deploy),
        "7": (
            "Installer un nouveau site depuis un dossier par FTP/FTPS",
            interactive_ftp_install,
        ),
    }
    while True:
        print()
        print("WebeLi / Compta — administration")
        print("=" * 42)
        for key, (title, _) in actions.items():
            print(f" {key}. {title}")
        print(" 0. Quitter")
        try:
            choice = input("Votre choix : ").strip()
        except (EOFError, KeyboardInterrupt):
            print()
            return 0
        if choice == "0":
            return 0
        action = actions.get(choice)
        if action is None:
            print("Choix invalide.")
            continue
        print()
        print(action[0])
        result = int(action[1]())
        if result != 0:
            return result


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser(description="Administration WebeLi / Compta")
    commands = root.add_subparsers(dest="command")

    database = commands.add_parser(
        "db-create",
        help="Créer une base technique ou une instance initialisée",
    )
    database.add_argument(
        "--path",
        type=Path,
        default=ROOT / "storage" / "database" / "app.sqlite",
    )
    database.add_argument("--replace", action="store_true")
    database.add_argument("--without-seeds", action="store_true")
    database.add_argument("--initialize", action="store_true")
    database.add_argument("--admin-email", default="admin@example.test")
    database.add_argument("--organisation", default="Mon organisation")
    database.add_argument("--nature", choices=["reelle", "pedagogique"], default="reelle")
    database.add_argument("--dossier", default="Comptabilité")
    database.add_argument("--slug", default="comptabilite")
    database.add_argument("--dossier-type", choices=["reel", "demo", "exercice"], default="reel")
    database.add_argument("--currency", default="CHF")
    database.add_argument("--exercise", default=str(datetime.now().year))
    database.add_argument("--start", default="")
    database.add_argument("--end", default="")
    database.add_argument(
        "--modules",
        default="liquidites,facturation,comptabilite,salaires",
    )
    database.add_argument(
        "--plan-variant",
        choices=["personne_morale", "raison_individuelle", "societe_personnes"],
        default="personne_morale",
    )
    database.add_argument("--association", action="store_true")
    pedagogy = database.add_mutually_exclusive_group()
    pedagogy.add_argument(
        "--with-pedagogy",
        dest="with_pedagogy",
        action="store_true",
        default=None,
        help=(
            "Créer une organisation pédagogique, son dossier et les sept "
            "parcours WebeLi (comportement par défaut avec --initialize)"
        ),
    )
    pedagogy.add_argument(
        "--without-pedagogy",
        dest="with_pedagogy",
        action="store_false",
        help="Créer exceptionnellement l’instance sans parcours pédagogiques",
    )
    database.add_argument(
        "--pedagogy-organisation",
        default="École WebeLi",
    )
    database.add_argument(
        "--pedagogy-dossier",
        default="Démonstration guidée",
    )
    database.add_argument(
        "--pedagogy-slug",
        default="demonstration-guidee",
    )
    database.add_argument("--allow-outside-project", action="store_true")
    database.add_argument("--apply", action="store_true")
    database.set_defaults(handler=database_create)

    restoration = commands.add_parser(
        "db-restore",
        help="Restaurer une sauvegarde SQLite puis appliquer les migrations",
    )
    restoration.add_argument("--source", type=Path, required=True)
    restoration.add_argument(
        "--path",
        type=Path,
        default=ROOT / "storage" / "database" / "app.sqlite",
    )
    restoration.add_argument("--allow-outside-project", action="store_true")
    restoration.add_argument("--apply", action="store_true")
    restoration.set_defaults(handler=database_restore)

    backup = commands.add_parser(
        "db-backup",
        help="Créer une photographie SQLite autonome incluant le WAL",
    )
    backup.add_argument(
        "--source",
        type=Path,
        default=ROOT / "storage" / "database" / "app.sqlite",
    )
    backup.add_argument(
        "--output",
        type=Path,
        help=(
            "Fichier à créer (par défaut : copie horodatée dans "
            "storage/backups)"
        ),
    )
    backup.add_argument("--allow-outside-project", action="store_true")
    backup.add_argument("--apply", action="store_true")
    backup.set_defaults(handler=database_backup)

    inspection = commands.add_parser(
        "db-inspect",
        help="Auditer en lecture seule le contenu et l’espace d’une base",
    )
    inspection.add_argument(
        "--path",
        type=Path,
        default=ROOT / "storage" / "database" / "app.sqlite",
    )
    inspection.set_defaults(handler=database_inspect)

    publish = commands.add_parser("git-publish", help="Commit contrôlé puis push")
    publish.add_argument("--message", required=True)
    publish.add_argument("--remote", default="origin")
    publish.add_argument("--branch", default="main")
    publish.add_argument("--apply", action="store_true")
    publish.set_defaults(handler=git_publish)

    delivery = commands.add_parser("deploy", help="Déployer le delta entre deux commits")
    delivery.add_argument("--config", type=Path, default=DEFAULT_DEPLOY_CONFIG)
    delivery.add_argument("--commit", default="HEAD")
    delivery.add_argument("--from", dest="from_commit")
    delivery.add_argument("--delete", action="store_true")
    delivery.add_argument("--apply", action="store_true")
    delivery.set_defaults(handler=deploy)

    direct_delivery = commands.add_parser(
        "ftp-install",
        help="Installer le runtime d’un nouveau site depuis un dossier",
    )
    direct_delivery.add_argument(
        "--config",
        type=Path,
        default=DEFAULT_DEPLOY_CONFIG,
        help="Fichier de connexion FTP/FTPS",
    )
    direct_delivery.add_argument(
        "--source",
        type=Path,
        default=ROOT,
        help="Répertoire local contenant la livraison complète",
    )
    direct_delivery.add_argument(
        "--remote-root",
        help="Répertoire FTP absolu d’arrivée (remplace la valeur du fichier)",
    )
    direct_delivery.add_argument(
        "--vendor-mode",
        choices=("auto", "local", "shared", "skip"),
        default=None,
        help=(
            "auto : reproduire l’emplacement local ; local : envoyer dans "
            "./vendor ; shared : mutualiser dans ../vendor ; skip : ne pas "
            "transférer vendor"
        ),
    )
    direct_delivery.add_argument(
        "--list-files",
        action="store_true",
        help="Afficher chaque fichier retenu",
    )
    direct_delivery.add_argument(
        "--replace-runtime",
        action="store_true",
        help="Autoriser explicitement une destination contenant déjà Compta",
    )
    direct_delivery.add_argument(
        "--replace-shared-vendor",
        action="store_true",
        help="Autoriser le remplacement risqué d’un ../vendor incompatible",
    )
    direct_delivery.add_argument(
        "--apply",
        action="store_true",
        help="Effectuer réellement le transfert (sinon simulation)",
    )
    direct_delivery.set_defaults(handler=ftp_install)
    return root


def main(argv: list[str] | None = None) -> int:
    try:
        args = parser().parse_args(argv)
        if args.command is None:
            return interactive_menu()
        return int(args.handler(args))
    except subprocess.CalledProcessError as error:
        detail = ""
        if error.stderr:
            detail = error.stderr.decode("utf-8", errors="replace").strip()
        print(f"ERREUR: commande en échec{': ' + detail if detail else ''}", file=sys.stderr)
        return error.returncode or 1
    except (AdminError, KeyError, json.JSONDecodeError, OSError, *ftplib.all_errors) as error:
        print(f"ERREUR: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
