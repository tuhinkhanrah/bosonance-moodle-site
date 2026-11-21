import contextlib
import importlib.util
import io
from pathlib import Path
import re
import shutil
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parent.parent / "scripts" / "build-raw-scss.py"
SPEC = importlib.util.spec_from_file_location("build_raw_scss", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
builder = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(builder)


class BuildRawScssTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.original_root = builder.ROOT
        builder.ROOT = self.root
        self.addCleanup(setattr, builder, "ROOT", self.original_root)
        for source in (builder.BOOST_UNION, *builder.SHARED):
            self.write(source, f"/* content: {source.as_posix()} */\n")
        self.write(builder.THEMES / "classic-blue" / "tokens.scss", "classic tokens\n")
        self.write(builder.THEMES / "blue-teal" / "tokens.scss", "teal tokens\n")
        self.write(builder.THEMES / "blue-teal" / "overrides.scss", "teal overrides\n")

    def write(self, path, text):
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text, encoding="utf-8")

    def run_builder(self, args=None):
        with contextlib.redirect_stdout(io.StringIO()):
            builder.main(args or [])

    def test_discovers_themes_in_order_and_is_repeatable(self):
        self.write(builder.THEMES / "new-theme" / "tokens.scss", "new tokens\n")
        self.run_builder()
        outputs = {}
        for name in ("blue-teal", "classic-blue", "new-theme"):
            output = self.root / builder.OUTPUT / f"{name}.scss"
            content = output.read_text(encoding="utf-8")
            sources = [builder.BOOST_UNION, builder.THEMES / name / "tokens.scss"]
            if name == "blue-teal":
                sources.append(builder.THEMES / name / "overrides.scss")
            sources.extend(builder.SHARED)
            positions = [content.index(f"/* Source: {source.as_posix()} */") for source in sources]
            self.assertEqual(positions, sorted(positions))
            for other in {"blue-teal", "classic-blue", "new-theme"} - {name}:
                self.assertNotIn(f"styles/themes/{other}/", content)
            outputs[output] = output.read_bytes()
        self.run_builder()
        self.assertEqual(outputs, {path: path.read_bytes() for path in outputs})

    def test_selecting_one_theme_leaves_other_output_untouched(self):
        self.write(builder.OUTPUT / "classic-blue.scss", "existing")
        self.run_builder(["--theme", "blue-teal"])
        self.assertEqual((self.root / builder.OUTPUT / "classic-blue.scss").read_text(), "existing")
        self.assertTrue((self.root / builder.OUTPUT / "blue-teal.scss").is_file())

    def test_missing_source_preserves_existing_outputs(self):
        for name in ("classic-blue", "blue-teal"):
            self.write(builder.OUTPUT / f"{name}.scss", "existing")
        (self.root / builder.THEMES / "classic-blue" / "tokens.scss").unlink()
        with self.assertRaises(FileNotFoundError):
            builder.build()
        for name in ("classic-blue", "blue-teal"):
            self.assertEqual((self.root / builder.OUTPUT / f"{name}.scss").read_text(), "existing")

    def test_unknown_or_invalid_theme_is_an_explicit_error(self):
        with self.assertRaisesRegex(ValueError, "Unknown theme"):
            builder.build("../unknown")
        (self.root / builder.THEMES / "bad_name").mkdir()
        with self.assertRaisesRegex(ValueError, "Invalid theme directory"):
            builder.build()

    def test_real_bundles_use_shared_branding_and_primary_button_colors(self):
        shutil.rmtree(self.root / "styles")
        shutil.copytree(self.original_root / "styles", self.root / "styles")
        self.run_builder()
        classic = (self.root / builder.OUTPUT / "classic-blue.scss").read_text(encoding="utf-8")
        teal = (self.root / builder.OUTPUT / "blue-teal.scss").read_text(encoding="utf-8")
        for content in (classic, teal):
            self.assertIsNone(re.search(r"\b(?:bsp|ots)-", content))
            self.assertIn(".bosonance-hero", content)
            self.assertIn(".categorydescription", content)
            self.assertIn(".courses .coursebox .summary", content)
            self.assertRegex(
                content,
                r"\.bosonance-btn-primary,\s*\.bosonance-card-cta,\s*"
                r"\.bosonance-banner-cta\s*\{[^}]*"
                r"background:\s*var\(--bosonance-primary\);[^}]*"
                r"color:\s*#ffffff\s*!important;",
            )
            self.assertRegex(
                content,
                r"\.btn-primary\s*\{[^}]*--bs-btn-bg:\s*var\(--bosonance-primary\);",
            )
            self.assertRegex(
                content,
                r"\.bosonance-secondary-link a\s*\{[^}]*"
                r"border:\s*1px solid var\(--bosonance-primary\);",
            )
        self.assertIn(".bosonance-fragment .bosonance-btn-primary", teal)
        self.assertNotIn(".bosonance-fragment .bosonance-btn-primary", classic)
        overrides = (self.root / builder.THEMES / "blue-teal" / "overrides.scss").read_text(
            encoding="utf-8"
        )
        self.assertNotRegex(
            overrides,
            r"\.bosonance-fragment \.bosonance-(?:btn-primary|card-cta|banner-cta)"
            r"(?::hover)?\s*\{[^}]*(?:background(?:-color)?|color)\s*:",
        )


if __name__ == "__main__":
    unittest.main()
