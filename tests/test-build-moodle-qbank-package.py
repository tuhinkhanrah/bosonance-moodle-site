import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest import mock
import zipfile


path = Path(__file__).resolve().parent.parent / "scripts" / "build-moodle-qbank-package.py"
spec = importlib.util.spec_from_file_location("qbank_package", path)
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)


class PackageTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.source = self.root / "bosonanceqbank"
        files = {
            "version.php": "<?php\n$plugin->component = 'local_bosonanceqbank';\n"
                           "$plugin->version = 2026112502;\n$plugin->release = '0.6.1';\n",
            "db/install.xml": "<XMLDB/>",
            "db/services.php": "<?php\n",
            "lang/en/local_bosonanceqbank.php": "<?php\n",
            "classes/example.php": "<?php\nclass example {}\n",
        }
        for name, content in files.items():
            file = self.source / name
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_text(content)

    def test_reproducible_zip_matches_source_and_checksum_and_preserves_old_zip(self):
        old = self.root / "bosonanceqbank.zip"
        old.write_bytes(b"user-modified archive")
        output, checksum, digest = package.build(self.source)
        other, _, other_digest = package.build(self.source, self.root / "comparison.zip")
        self.assertEqual(old.read_bytes(), b"user-modified archive")
        self.assertEqual(output.read_bytes(), other.read_bytes())
        self.assertEqual(digest, other_digest)
        self.assertEqual(digest, hashlib.sha256(output.read_bytes()).hexdigest())
        self.assertEqual(checksum.read_text(), f"{digest}  {output.name}\n")
        with zipfile.ZipFile(output) as archive:
            expected = {f"bosonanceqbank/{file.relative_to(self.source).as_posix()}": file.read_bytes()
                        for file in self.source.rglob("*") if file.is_file()}
            self.assertEqual(set(archive.namelist()), set(expected))
            for name, content in expected.items():
                self.assertEqual(archive.read(name), content)

    def test_existing_archive_or_checksum_is_not_overwritten(self):
        for name in ("existing.zip", "existing.zip.sha256"):
            with self.subTest(name=name):
                existing = self.root / name
                existing.write_bytes(b"user data")
                with self.assertRaises(FileExistsError):
                    package.build(self.source, self.root / "existing.zip")
                self.assertEqual(existing.read_bytes(), b"user data")
                existing.unlink()
                self.assertFalse((self.root / "existing.zip").exists())

    def test_symlink_and_hidden_files_are_rejected(self):
        unsafe = self.source / "unsafe.php"
        unsafe.symlink_to(self.source / "version.php")
        with self.assertRaises(ValueError):
            package.build(self.source)
        unsafe.unlink()
        (self.source / ".env").write_text("not for deployment")
        with self.assertRaises(ValueError):
            package.build(self.source)
        self.assertFalse((self.root / "bosonanceqbank-0.6.1.zip").exists())

    def test_output_inside_source_and_wrong_component_are_rejected(self):
        with self.assertRaises(ValueError):
            package.build(self.source, self.source / "output.zip")
        (self.source / "version.php").write_text(
            "<?php\n$plugin->component = 'local_other';\n$plugin->release = '0.6.1';\n")
        with self.assertRaises(ValueError):
            package.build(self.source)

    def test_source_change_changes_package_digest(self):
        _, _, before = package.build(self.source, self.root / "before.zip")
        (self.source / "classes/example.php").write_text("<?php\nclass changed {}\n")
        _, _, after = package.build(self.source, self.root / "after.zip")
        self.assertNotEqual(before, after)

    def test_checksum_write_failure_removes_only_new_package_files(self):
        original_open = Path.open

        def open_file(path, *args, **kwargs):
            if path.name.endswith(".sha256"):
                raise OSError("Checksum storage failed")
            return original_open(path, *args, **kwargs)

        with mock.patch.object(Path, "open", open_file), self.assertRaises(OSError):
            package.build(self.source)
        self.assertFalse((self.root / "bosonanceqbank-0.6.1.zip").exists())
        self.assertFalse((self.root / "bosonanceqbank-0.6.1.zip.sha256").exists())


if __name__ == "__main__":
    unittest.main()
