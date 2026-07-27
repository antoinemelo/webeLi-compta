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
import hashlib
import io
import json
import os
import posixpath
import shutil
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
        backup = target.with_name(
            f"{target.stem}.before-init-{datetime.now():%Y%m%d-%H%M%S}{target.suffix}"
        )
        print(f"Sauvegarde préalable : {backup}")
    else:
        backup = None
    print("Étapes : migrations SQL, catalogue de seeds comptables, contrôle d’intégrité.")
    ensure_apply(args, "aucune base n’a été créée")

    target.parent.mkdir(parents=True, exist_ok=True)
    if backup is not None:
        shutil.move(target, backup)
        for suffix in ("-wal", "-shm"):
            companion = Path(str(target) + suffix)
            if companion.exists():
                shutil.move(companion, Path(str(backup) + suffix))

    environment = os.environ.copy()
    environment.update({
        "APP_DB_PATH": str(target),
        "APP_STORAGE_PATH": str(storage_for_database(target)),
        "APP_ENV": "dev",
        "APP_DEBUG": "0",
    })
    try:
        run(["php", "bin/console", "db:migrate", "--apply", "--backup"], env=environment)
        if not args.without_seeds:
            run(["php", "bin/console", "compta:plan-seed"], env=environment)
        run(["php", "bin/console", "db:integrity"], env=environment)
    except Exception:
        if target.exists():
            target.unlink()
        if backup is not None and backup.exists():
            shutil.move(backup, target)
        raise
    print(f"Base créée et contrôlée : {target}")
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


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser(description="Administration WebeLi / Compta")
    commands = root.add_subparsers(dest="command", required=True)

    database = commands.add_parser("db-create", help="Créer une base depuis migrations et seeds")
    database.add_argument(
        "--path",
        type=Path,
        default=ROOT / "storage" / "database" / "app.sqlite",
    )
    database.add_argument("--replace", action="store_true")
    database.add_argument("--without-seeds", action="store_true")
    database.add_argument("--allow-outside-project", action="store_true")
    database.add_argument("--apply", action="store_true")
    database.set_defaults(handler=database_create)

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


def main() -> int:
    try:
        args = parser().parse_args()
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
