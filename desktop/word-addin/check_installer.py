"""Run the real combined installer twice with isolated application and Word Startup paths."""
from pathlib import Path
import argparse
from datetime import datetime
import hashlib
import json
import shutil
import subprocess
import winreg

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "tmp/installer-word-test-1.6.1" / datetime.now().strftime("run-%Y%m%d-%H%M%S")
INSTALL = OUT / "install"
STARTUP = OUT / "startup"
TEMPLATE = ROOT / "output/word-addin/installer/SIMANIS-Word.dotm"
EXE = ROOT / "dist/SIMANIS-v1.6.1/SIMANIS.exe"
HELPER = ROOT / "output/word-addin/installer/SIMANIS-WordData.exe"

def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def helper_registration():
    try:
        with winreg.OpenKey(winreg.HKEY_CURRENT_USER, r"Software\SIMANIS") as key:
            return winreg.QueryValueEx(key, "WordDataExe")
    except FileNotFoundError:
        return None

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--iscc", required=True, type=Path)
    args = parser.parse_args()
    assert not INSTALL.exists(), "Use a fresh isolated test directory"
    subprocess.run([str(args.iscc.resolve()), "/DIntegrationTest", "/O" + str(OUT), str(ROOT / "GarisAktaEditor.iss")], cwd=ROOT, check=True)
    STARTUP.mkdir(parents=True, exist_ok=True)
    legacy = STARTUP / "SIGANDI-Word-old.dotm"
    shutil.copy2(TEMPLATE, legacy)
    legacy_hash = sha(legacy)
    sentinel = STARTUP / "Unrelated-Template.dotm"
    sentinel.write_bytes(b"Unrelated template: preserve")
    registry_before = helper_registration()
    reports = []
    for run in (1, 2):
        result = subprocess.run([str(OUT / "SIMANIS_Installer_Integration_Test.exe"),
            "/VERYSILENT", "/SUPPRESSMSGBOXES", "/NORESTART", "/NOICONS", "/SP-", "/TASKS=",
            f"/DIR={INSTALL}", f"/WORDSTARTUPDIR={STARTUP}", f"/LOG={OUT / ('install-' + str(run) + '.log')}"], timeout=120)
        assert result.returncode == 0, result.returncode
        assert sha(INSTALL / "SIMANIS.exe") == sha(EXE)
        assert sha(INSTALL / "SIMANIS-WordData.exe") == sha(HELPER)
        assert sha(INSTALL / "WordAddin/Simanis.Word.dll") == sha(ROOT / "output/word-addin/installer/Simanis.Word.dll")
        assert sha(STARTUP / "SIMANIS-Word.dotm") == sha(TEMPLATE)
        assert sha(INSTALL / "WordAddin/SIMANIS-Word.dotm") == sha(TEMPLATE)
        assert not legacy.exists()
        assert any(sha(p) == legacy_hash for p in (INSTALL / "WordAddin/Backup").rglob(legacy.name))
        if run == 2:
            assert list((INSTALL / "WordAddin/Backup").rglob("SIMANIS-Word.dotm")), "Upgrade must back up previous SIMANIS template"
        assert sentinel.read_bytes() == b"Unrelated template: preserve"
        assert helper_registration() == registry_before
        reports.append({"run": run, "all_hashes_match": True, "unrelated_template_preserved": True})
    report = {"status": "PASS", "real_word_startup_untouched": True, "registry_unchanged": True, "runs": reports}
    (OUT / "report.json").write_text(json.dumps(report, indent=2))
    print(json.dumps(report))

if __name__ == "__main__":
    main()
