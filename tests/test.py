import importlib.util
from pathlib import Path


def load_tests(loader, tests, pattern):
    for path in sorted(Path(__file__).parent.glob("test-*.py")):
        spec = importlib.util.spec_from_file_location(path.stem, path)
        if spec is None or spec.loader is None:
            raise ImportError(f"Cannot load tests from {path}")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        tests.addTests(loader.loadTestsFromModule(module))
    return tests
