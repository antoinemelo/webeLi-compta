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
    if candidate.exists():
        candidate = directory / (
            f"{target.stem}-{reason}-{stamp}-{os.urandom(3).hex()}.sqlite"
        )
    return candidate


def backup_database(source: Path, destination: Path) -> Path:
    destination.parent.mkdir(parents=True, exist_ok=True)
    try:
        with sqlite3.connect(f"file:{source}?mode=ro", uri=True) as original:
            with sqlite3.connect(destination) as backup:
                original.backup(backup)
    except sqlite3.DatabaseError as error:
        if destination.exists():
            destination.unlink()
        raise AdminError(f"Sauvegarde SQLite impossible : {error}") from error
    check_database(destination)
    return destination


def remove_database_files(target: Path) -> None:
    for path in (target, Path(str(target) + "-wal"), Path(str(target) + "-shm")):
        if path.exists():
            path.unlink()


def database_summary(path: Path) -> dict[str, int]:
    with sqlite3.connect(f"file:{path}?mode=ro", uri=True) as connection:
        summary = {"size": path.stat().st_size}
        for table in (
            "utilisateurs", "organisations", "dossiers", "comptes", "ecritures"
        ):
            summary[table] = int(
                connection.execute(f'SELECT COUNT(*) FROM "{table}"').fetchone()[0]
            )
        return summary


def initialize_database(
    target: Path,
    args: argparse.Namespace,
    environment: dict[str, str],
) -> None:
    password = str(
        getattr(args, "admin_password", "")
        or environment.get("COMPTA_ADMIN_PASSWORD", "")
    )
    if len(password) < 12:
        raise AdminError(
            "L’initialisation exige COMPTA_ADMIN_PASSWORD "
            "(12 caractères minimum)."
        )
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
    run(command, env=environment)


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
    if initialize:
        password = str(
            getattr(args, "admin_password", "")
            or os.environ.get("COMPTA_ADMIN_PASSWORD", "")
        )
        if len(password) < 12:
            raise AdminError(
                "Le mot de passe administrateur doit contenir au moins 12 caractères."
            )
    print("Étapes : migrations SQL, catalogue des plans comptables"
          + (", initialisation de l’instance" if initialize else "")
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
            f"{summary['comptes']} compte(s)."
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
    print(f"Sauvegarde à restaurer : {source}")
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
    except Exception:
        if temporary.exists():
            temporary.unlink()
        remove_database_files(target)
        if previous is not None:
            shutil.copy2(previous, target)
            print(f"Base précédente restaurée après échec : {previous}")
        raise
    summary = database_summary(target)
    print(
        f"Restauration terminée : {summary['organisations']} organisation(s), "
        f"{summary['dossiers']} dossier(s), {summary['comptes']} compte(s), "
        f"{summary['ecritures']} écriture(s)."
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


def changed_runtime_files(baseline: str | None, target: str) -> tuple[list[str], list[str]]:
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
    else:
        command = [
            "git", "diff-tree", "--root", "--no-commit-id", "--name-status",
            "--no-renames", "-r", "-z", target,
        ]
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
    return sorted(set(uploads)), sorted(set(deletions))


def git_bytes(commit: str, path: str) -> bytes:
    return run(["git", "show", f"{commit}:{path}"], capture=True).stdout


def release_manifest(
    target: str,
    baseline: str | None,
    uploads: list[str],
    deletions: list[str],
) -> dict[str, Any]:
    files = []
    for path in uploads:
        content = git_bytes(target, path)
        files.append({
            "path": path,
            "size": len(content),
            "sha256": hashlib.sha256(content).hexdigest(),
        })
    version = git_bytes(target, "VERSION").decode("utf-8").strip() if "VERSION" in git(
        "ls-tree", "-r", "--name-only", target
    ).splitlines() else ""
    return {
        "schema": 1,
        "application": "webeli-compta",
        "commit": target,
        "previous_commit": baseline,
        "version": version,
        "deployed_at": datetime.now(timezone.utc).isoformat(),
        "files": files,
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
    return json.loads(path.read_text(encoding="utf-8")) if path.is_file() else None


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
    chunks: list[bytes] = []
    try:
        client.retrbinary(f"RETR {path}", chunks.append)
    except ftplib.all_errors:
        return None
    return json.loads(b"".join(chunks).decode("utf-8"))


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
        destination.write_bytes(git_bytes(target, path))
    if delete:
        for path in deletions:
            destination = root / path
            if destination.is_file():
                destination.unlink()
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
        for path in uploads:
            remote = posixpath.join(root, path)
            ftp_mkdirs(client, posixpath.dirname(remote))
            client.storbinary(f"STOR {remote}", io.BytesIO(git_bytes(target, path)))
        if delete:
            for path in deletions:
                try:
                    client.delete(posixpath.join(root, path))
                except ftplib.error_perm as error:
                    if not str(error).startswith("550"):
                        raise
        payload = json.dumps(manifest, ensure_ascii=False, indent=2).encode("utf-8") + b"\n"
        release_path = posixpath.join(root, REMOTE_RELEASES, f"{target}.json")
        ftp_mkdirs(client, posixpath.dirname(release_path))
        client.storbinary(f"STOR {release_path}", io.BytesIO(payload))
        ftp_mkdirs(client, posixpath.dirname(posixpath.join(root, REMOTE_MANIFEST)))
        client.storbinary(
            f"STOR {posixpath.join(root, REMOTE_MANIFEST)}",
            io.BytesIO(payload),
        )


def deploy(args: argparse.Namespace) -> int:
    config = load_config(args.config.resolve())
    target = git("rev-parse", args.commit)
    baseline = args.from_commit
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
    finally:
        if client is not None:
            client.quit()
    uploads, deletions = changed_runtime_files(baseline, target)
    manifest = release_manifest(target, baseline, uploads, deletions)
    print(f"Déploiement : {baseline or 'installation initiale'} -> {target}")
    print(f"Fichiers applicatifs à envoyer : {len(uploads)}")
    for path in uploads:
        print(f"  + {path}")
    print(f"Fichiers devenus obsolètes : {len(deletions)}")
    for path in deletions:
        print(f"  - {path}{'' if args.delete else ' (conservé sans --delete)'}")
    if not uploads and not (args.delete and deletions):
        print("Le site est déjà aligné sur les fichiers applicatifs de ce commit.")
    ensure_apply(args, "aucun fichier distant n’a été modifié")
    if config["transport"] == "local":
        deploy_local(config, target, uploads, deletions, manifest, args.delete)
    else:
        deploy_ftp(config, target, uploads, deletions, manifest, args.delete)
    print(f"Déploiement terminé. Marqueur distant : {REMOTE_MANIFEST}")
    return 0


def ask(prompt: str, default: str = "") -> str:
    suffix = f" [{default}]" if default else ""
    value = input(f"{prompt}{suffix} : ").strip()
    return value or default


def confirm(prompt: str) -> bool:
    return input(f"{prompt} [o/N] : ").strip().lower() in {"o", "oui", "y", "yes"}


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
    }
    if initialize:
        print()
        print("Première instance")
        print("-" * 42)
        values["admin_email"] = ask(
            "Adresse e-mail de l’administrateur",
            values["admin_email"],
        )
        password = getpass.getpass("Mot de passe administrateur (12 caractères minimum) : ")
        confirmation = getpass.getpass("Confirmez le mot de passe : ")
        if password != confirmation or len(password) < 12:
            print("Opération annulée : mots de passe différents ou trop courts.")
            return 0
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
            "Ajouter l’overlay du plan comptable pour associations"
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


def interactive_database() -> int:
    while True:
        print()
        print("Bases de données")
        print("-" * 42)
        print(" 1. Créer une instance utilisable (recommandé)")
        print(" 2. Créer uniquement une base technique vierge")
        print(" 3. Restaurer une sauvegarde existante")
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
    if not confirm("Déployer maintenant le delta applicatif"):
        print("Opération annulée.")
        return 0
    return deploy(argparse.Namespace(
        config=config,
        commit=commit,
        from_commit=from_commit or None,
        delete=delete,
        apply=True,
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
        "4": ("Créer un commit Git puis le pousser", interactive_publish),
        "5": ("Déployer le delta applicatif versionné", interactive_deploy),
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
