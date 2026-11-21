import argparse
from pathlib import Path
import re
from typing import List, Optional


ROOT = Path(__file__).resolve().parent.parent
STYLES = Path("styles")
THEMES = STYLES / "themes"
OUTPUT = Path("build", "styles")
BOOST_UNION = STYLES / "shared" / "boost-union.scss"
SHARED = tuple(
    STYLES / "shared" / name
    for name in (
        "base.scss",
        "hero.scss",
        "buttons.scss",
        "cards.scss",
        "banner.scss",
        "content.scss",
        "responsive.scss",
    )
)
THEME_NAME = re.compile(r"[a-z0-9]+(?:-[a-z0-9]+)*\Z")


def build(theme: Optional[str] = None) -> None:
    themes_dir = ROOT / THEMES
    if not themes_dir.is_dir():
        raise ValueError(f"Theme directory not found: {themes_dir}")
    names = sorted(path.name for path in themes_dir.iterdir() if path.is_dir())
    if not names:
        raise ValueError(f"No themes found in {themes_dir}")
    invalid = [name for name in names if not THEME_NAME.fullmatch(name)]
    if invalid:
        raise ValueError(f"Invalid theme directory name(s): {', '.join(invalid)}")
    if theme is not None:
        if theme not in names:
            raise ValueError(f"Unknown theme {theme!r}; available themes: {', '.join(names)}")
        names = [theme]

    bundles = []
    for name in names:
        theme_dir = THEMES / name
        overrides = theme_dir / "overrides.scss"
        sources = [BOOST_UNION, theme_dir / "tokens.scss"]
        if (ROOT / overrides).exists():
            sources.append(overrides)
        sources.extend(SHARED)
        sections = []
        for source in sources:
            content = (ROOT / source).read_bytes().decode("utf-8-sig")
            sections.append(f"/* Source: {source.as_posix()} */\n{content}\n")
        bundles.append((ROOT / OUTPUT / f"{name}.scss", "\n".join(sections).encode("utf-8")))

    for output, content in bundles:
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_bytes(content)
        print(f"Generated {output}")


def main(argv: Optional[List[str]] = None) -> None:
    parser = argparse.ArgumentParser(description="Build site-wide Moodle Raw SCSS themes.")
    parser.add_argument("--theme", help="Build one theme directory by name (default: all themes).")
    args = parser.parse_args(argv)
    try:
        build(args.theme)
    except (OSError, UnicodeError, ValueError) as exc:
        parser.exit(1, f"Error: {exc}\n")


if __name__ == "__main__":
    main()
