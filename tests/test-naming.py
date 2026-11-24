import os
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parent.parent
SERIES = (
    ROOT / "site-admin" / "courses" / "manage-courses-and-categories"
    / "course-categories" / "online-test-series"
)


class NamingTests(unittest.TestCase):
    def test_source_names_use_hyphens(self):
        for directory, dirs, files in os.walk(ROOT):
            dirs[:] = [name for name in dirs if name not in {".git", "__pycache__", "build"}]
            if Path(directory) == ROOT:
                # Moodle plugins must follow Moodle's own component and test file names.
                dirs[:] = [name for name in dirs if name != "moodle-plugins"]
            for name in dirs + files:
                with self.subTest(path=Path(directory, name).relative_to(ROOT)):
                    self.assertNotIn("_", name)

    def test_online_course_descriptions_have_consistent_names(self):
        course_dirs = [
            course
            for courses in SERIES.rglob("courses")
            for course in courses.iterdir()
            if course.is_dir()
        ]
        self.assertTrue(course_dirs)
        for course in course_dirs:
            with self.subTest(course=course.relative_to(ROOT)):
                self.assertEqual(
                    {path.name for path in course.glob("*.html")},
                    {"description.html", "general.html"},
                )
