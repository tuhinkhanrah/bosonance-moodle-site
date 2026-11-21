from html.parser import HTMLParser
from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parent.parent
SHARED = ROOT / "styles" / "shared"
COURSE_MARKER = re.compile(
    r"bosonance-(?:jee-advanced|jee-main|neet|neet-ug|wbjee)-general-section"
)


class FragmentParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.root_classes = None
        self.classes = set()

    def handle_starttag(self, tag, attrs):
        classes = set((dict(attrs).get("class") or "").split())
        if self.root_classes is None:
            self.root_classes = classes
        self.classes.update(classes)

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)


class BrandingFragmentTests(unittest.TestCase):
    def test_all_themes_define_every_shared_brand_variable(self):
        css = "\n".join(path.read_text(encoding="utf-8") for path in SHARED.glob("*.scss"))
        used = set(re.findall(r"var\((--bosonance-[\w-]+)", css))
        themes = sorted(path for path in (ROOT / "styles" / "themes").iterdir() if path.is_dir())
        self.assertTrue(themes)
        for theme in themes:
            text = (theme / "tokens.scss").read_text(encoding="utf-8")
            tokens = set(re.findall(r"(--bosonance-[\w-]+)\s*:", text))
            self.assertFalse(used - tokens, f"{theme.name} is missing: {used - tokens}")

    def test_fragments_use_defined_bosonance_styles(self):
        css = "\n".join(path.read_text(encoding="utf-8") for path in SHARED.glob("*.scss"))
        selectors = set(re.findall(r"\.(bosonance-[\w-]+)", css))
        paths = sorted(ROOT.rglob("*.html"))
        self.assertTrue(paths)
        for path in paths:
            with self.subTest(path=path.relative_to(ROOT)):
                parser = FragmentParser()
                parser.feed(path.read_text(encoding="utf-8-sig"))
                self.assertIsNotNone(parser.root_classes)
                self.assertIn("bosonance-fragment", parser.root_classes)
                legacy = {name for name in parser.classes if name.startswith(("bsp-", "ots-"))}
                self.assertFalse(legacy, f"Legacy styling classes: {legacy}")
                undefined = {
                    name for name in parser.classes
                    if name.startswith("bosonance-")
                    and name not in selectors
                    and not COURSE_MARKER.fullmatch(name)
                }
                self.assertFalse(undefined, f"Undefined branding classes: {undefined}")


if __name__ == "__main__":
    unittest.main()
