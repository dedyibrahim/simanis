"""Compatibility entry point: SIMANIS edition has no activation server."""
from pathlib import Path
import subprocess
import sys
root = Path(__file__).resolve().parents[1]
raise SystemExit(subprocess.call([sys.executable, "-m", "unittest", "discover", "-s", str(root / "tests"), "-p", "test_word_license_bridge.py", "-q"], cwd=root))
