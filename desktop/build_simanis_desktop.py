"""Build standalone SIMANIS desktop + optional Word package, without activation secrets."""
from pathlib import Path
import hashlib
import json
import shutil
import subprocess
import sys
import zipfile

ROOT = Path(__file__).resolve().parent
VERSION = "1.5.1"
def main():
    subprocess.run([sys.executable, "-m", "PyInstaller", "--noconfirm",
        "--distpath", "dist/SIMANIS-v" + VERSION, "--workpath", "tmp/desktop-build",
        "GarisAktaEditor.spec"], cwd=ROOT, check=True)
    subprocess.run([sys.executable, str(ROOT / "build_simanis_word.py")], cwd=ROOT, check=True)
    package = ROOT / "output" / ("SIMANIS-Desktop-" + VERSION + "-portable")
    package.mkdir(parents=True, exist_ok=True)
    shutil.copy2(ROOT / ("dist/SIMANIS-v" + VERSION) / "SIMANIS.exe", package / "SIMANIS.exe")
    shutil.copy2(ROOT / "word-addin/PANDUAN.txt", package / "BACA-DULU.txt")
    shutil.copytree(ROOT / "output/SIMANIS-Word-0.5.1-preview", package / "Word", dirs_exist_ok=True)
    manifest = {"version": VERSION, "activation_required": False, "license_server": None,
        "simanis_login_required_for_data_only": True, "production_server_deployed": False, "files": {}}
    for source in sorted(package.rglob("*")):
        if source.is_file():
            manifest["files"][source.relative_to(package).as_posix()] = {
                "bytes": source.stat().st_size, "sha256": hashlib.sha256(source.read_bytes()).hexdigest()}
    (package / "manifest.json").write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    archive = package.parent / (package.name + ".zip")
    with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as output:
        for source in sorted(package.rglob("*")):
            if source.is_file(): output.write(source, package.name + "/" + source.relative_to(package).as_posix())
    print(archive)
    print("SHA256:", hashlib.sha256(archive.read_bytes()).hexdigest())
if __name__ == "__main__": main()
