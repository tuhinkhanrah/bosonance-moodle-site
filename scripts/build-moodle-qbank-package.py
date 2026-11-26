"""Build and verify a reproducible Moodle question-bank plugin ZIP and SHA-256."""

import argparse
import hashlib
import io
from pathlib import Path
import re
import stat
import sys
import zipfile


SOURCE = Path(__file__).resolve().parent.parent / "moodle-plugins" / "local" / "bosonanceqbank"
REQUIRED = ("version.php", "db/install.xml", "db/services.php", "lang/en/local_bosonanceqbank.php")
EXCLUDED = {".git", "__pycache__", ".DS_Store"}


def build(source=SOURCE, output=None):
    source = Path(source).resolve()
    if source.name != "bosonanceqbank":
        raise ValueError("Plugin directory must be named bosonanceqbank")
    for name in REQUIRED:
        if not (source / name).is_file():
            raise ValueError(f"Missing required plugin file: {name}")
    version = (source / "version.php").read_text(encoding="utf-8")
    if not re.search(r"\$plugin->component\s*=\s*'local_bosonanceqbank'\s*;", version):
        raise ValueError("Unexpected plugin component")
    if not re.search(r"\$plugin->version\s*=\s*[1-9][0-9]{9}\s*;", version):
        raise ValueError("Expected a ten-digit Moodle plugin version")
    release = re.search(r"\$plugin->release\s*=\s*'([0-9]+(?:\.[0-9]+){2})'\s*;", version)
    if not release:
        raise ValueError("Expected a numeric three-part plugin release")
    output = Path(output) if output else source.parent / f"bosonanceqbank-{release[1]}.zip"
    output = output.absolute()
    if output.suffix != ".zip" or not output.name.isascii() or any(ord(char) < 32 for char in output.name):
        raise ValueError("Output must be an ASCII ZIP filename without control characters")
    if output.parent.resolve() == source or source in output.parent.resolve().parents:
        raise ValueError("Output must be outside the plugin source directory")
    checksum = output.with_name(output.name + ".sha256")
    if output.exists() or checksum.exists():
        raise FileExistsError("Package or checksum already exists; choose a different --output")

    files = {}
    for path in sorted(source.rglob("*")):
        relative = path.relative_to(source)
        if any(part in EXCLUDED for part in relative.parts) or path.suffix == ".pyc":
            continue
        if path.is_symlink():
            raise ValueError(f"Symlinks are not allowed in the plugin package: {relative}")
        if any(part.startswith(".") for part in relative.parts):
            raise ValueError(f"Unexpected hidden plugin path: {relative}")
        if path.is_file():
            files[f"bosonanceqbank/{relative.as_posix()}"] = path.read_bytes()
        elif not path.is_dir():
            raise ValueError(f"Unsupported plugin path: {relative}")

    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w") as archive:
        for name, content in files.items():
            entry = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = (stat.S_IFREG | 0o644) << 16
            archive.writestr(entry, content, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    payload = buffer.getvalue()
    with zipfile.ZipFile(io.BytesIO(payload)) as archive:
        if archive.namelist() != list(files) or archive.testzip() is not None:
            raise ValueError("ZIP layout or CRC verification failed")
        for name, content in files.items():
            if archive.read(name) != content:
                raise ValueError(f"ZIP content verification failed: {name}")
    digest = hashlib.sha256(payload).hexdigest()

    created = []
    try:
        with output.open("xb") as archive:
            created.append(output)
            archive.write(payload)
        with checksum.open("x", encoding="ascii") as manifest:
            created.append(checksum)
            manifest.write(f"{digest}  {output.name}\n")
        if output.read_bytes() != payload:
            raise ValueError("Written package differs from the verified ZIP")
    except (OSError, ValueError):
        for path in reversed(created):
            path.unlink()
        raise
    return output, checksum, digest


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, help="New output ZIP path; existing files are never overwritten")
    args = parser.parse_args(argv)
    try:
        package, checksum, digest = build(output=args.output)
    except (OSError, ValueError, zipfile.BadZipFile) as error:
        print(f"[FAIL] {error}", file=sys.stderr)
        return 1
    print(f"Verified package: {package}")
    print(f"SHA-256: {digest}")
    print(f"Checksum file: {checksum}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
