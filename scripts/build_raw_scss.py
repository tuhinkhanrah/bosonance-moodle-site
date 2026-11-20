from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
CATEGORIES = Path("site-admin", "courses", "manage_courses_and_categories", "course_categories")
SOURCES = (
    CATEGORIES / "free-sample-pack" / "free-sample-pack.scss",
    CATEGORIES / "online-test-series" / "online-test-series.scss",
    Path("theme", "boost-union", "look", "scss", "scss.scss"),
)
OUTPUT = ROOT / "build" / "moodle-raw.scss"


def main() -> None:
    sections = []
    for source in SOURCES:
        content = (ROOT / source).read_bytes().decode("utf-8-sig")
        sections.append(f"/* Source: {source} */\n{content}\n")

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_bytes("\n".join(sections).encode("utf-8"))
    print(f"Generated {OUTPUT}")


if __name__ == "__main__":
    main()
