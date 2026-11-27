"""Build desktop, Word and one per-user EXE installer using Inno Setup 6.4+."""
from pathlib import Path
import argparse
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parent

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--iscc", type=Path, help="Path to Inno Setup ISCC.exe")
    parser.add_argument("--skip-build", action="store_true", help="Package already-built desktop and Word")
    args = parser.parse_args()
    compiler = str(args.iscc.resolve()) if args.iscc else shutil.which("ISCC")
    if not compiler or not Path(compiler).is_file():
        parser.error("Install Inno Setup and supply --iscc path/to/ISCC.exe")
    if not args.skip_build:
        subprocess.run([sys.executable, str(ROOT / "build_simanis_desktop.py")], cwd=ROOT, check=True)
    subprocess.run([compiler, str(ROOT / "GarisAktaEditor.iss")], cwd=ROOT, check=True)

if __name__ == "__main__":
    main()
