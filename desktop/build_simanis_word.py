"""Build the standalone SIMANIS Word preview; no license secrets are bundled."""
from pathlib import Path
import hashlib
import json
import shutil
import subprocess
import sys
import zipfile

ROOT = Path(__file__).resolve().parent
VERSION = "0.6.1-preview"
def main():
    subprocess.run([sys.executable, str(ROOT / "word-addin/build_addin.py")], cwd=ROOT, check=True)
    subprocess.run([sys.executable, "-m", "PyInstaller", "--noconfirm", "--onefile", "--windowed",
        "--name", "SIMANIS-WordData", "--distpath", "output/word-addin/installer",
        "--workpath", "tmp/data-build", "--specpath", "tmp", "simanis_data.py"], cwd=ROOT, check=True)
    subprocess.run([sys.executable, str(ROOT / "word-pane/build.py")], cwd=ROOT, check=True)
    package = ROOT / "output" / ("SIMANIS-Word-" + VERSION)
    package.mkdir(parents=True, exist_ok=True)
    inputs = {
        "Simanis.Word.dll": ROOT / "output/word-addin/installer/Simanis.Word.dll",
        "Register-SIMANIS-WordPane.ps1": ROOT / "word-pane/Register-SIMANIS-WordPane.ps1",
        "SIMANIS-Word.dotm": ROOT / "output/word-addin/installer/SIMANIS-Word.dotm",
        "SIMANIS-WordData.exe": ROOT / "output/word-addin/installer/SIMANIS-WordData.exe",
        "Install-SIMANIS-Word.ps1": ROOT / "word-addin/Install-SIMANIS-Word.ps1",
        "Install-SIMANIS-Word.cmd": ROOT / "word-addin/Install-SIMANIS-Word.cmd",
        "PANDUAN.md": ROOT / "word-addin/SIMANIS-DATA.md",
    }
    manifest = {"version": VERSION, "server_setup_included": False,
        "helper_source_sha256": hashlib.sha256((ROOT / "simanis_data.py").read_bytes()).hexdigest(), "files": {}}
    for name, source in inputs.items():
        shutil.copy2(source, package / name)
        manifest["files"][name] = {"bytes": source.stat().st_size, "sha256": hashlib.sha256(source.read_bytes()).hexdigest()}
    (package / "manifest.json").write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    archive = package.parent / (package.name + ".zip")
    with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as output:
        for source in sorted(package.iterdir()):
            output.write(source, package.name + "/" + source.name)
    print(archive)
    print("SHA256:", hashlib.sha256(archive.read_bytes()).hexdigest())
if __name__ == "__main__": main()
