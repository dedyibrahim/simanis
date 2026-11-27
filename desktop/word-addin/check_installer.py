"""Exercise the real Inno installer in an isolated workspace installation."""
from pathlib import Path
import hashlib
import json
import shutil
import subprocess
import winreg

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'tmp/installer-word-test-1.4.8'
INSTALL = OUT / 'install'
STARTUP = OUT / 'startup'
STARTUP.mkdir(parents=True, exist_ok=True)
TEMPLATE = ROOT / 'output/word-addin/SIGANDI-Word-0.4.9-preview.dotm'
EXE = ROOT / 'dist/SIGANDI-v1.4.8/GarisAktaEditor.exe'
LEGACY = STARTUP / 'SIGANDI-Word-0.1.0-preview.dotm'
previous_backups = set((INSTALL / 'WordAddin/Backup').rglob(LEGACY.name))
shutil.copy2(ROOT / 'output/word-addin/SIGANDI-Word-0.1.0-preview.dotm', LEGACY)
legacy_hash = hashlib.sha256(LEGACY.read_bytes()).hexdigest()
sentinel = STARTUP / 'Unrelated-Template.dotm'
sentinel.write_bytes(b'Unrelated template: must remain unchanged')
sentinel_bytes = sentinel.read_bytes()


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def registry_present():
    key = r'Software\Microsoft\Windows\CurrentVersion\Uninstall\{F2706A57-50E1-42D3-A009-B140891704A1}_is1'
    try:
        with winreg.OpenKey(winreg.HKEY_CURRENT_USER, key):
            return True
    except FileNotFoundError:
        return False


assert not registry_present()
runs = []
for number in (1, 2):
    result = subprocess.run([
        str(OUT / 'SIGANDI_Installer_Integration_Test.exe'),
        '/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART', '/NOICONS',
        '/SP-', '/TASKS=', f'/DIR={INSTALL}', f'/WORDSTARTUPDIR={STARTUP}',
        f'/LOG={OUT / ("install-" + str(number) + ".log")}',
    ], timeout=90)
    assert result.returncode == 0, result.returncode
    assert sha(INSTALL / 'GarisAktaEditor.exe') == sha(EXE)
    assert sha(INSTALL / 'WordAddin/SIGANDI-Word.dotm') == sha(TEMPLATE)
    assert sha(STARTUP / 'SIGANDI-Word.dotm') == sha(TEMPLATE)
    assert (INSTALL / 'WordAddin/PANDUAN.txt').read_text(encoding='utf-8') == (ROOT / 'word-addin/PANDUAN.txt').read_text(encoding='utf-8')
    assert sha(INSTALL / 'Panduan SIGANDI.pdf') == sha(ROOT / 'output/pdf/Panduan SIGANDI v1.4.8.pdf')
    assert not LEGACY.exists()
    backups = list((INSTALL / 'WordAddin/Backup').rglob(LEGACY.name))
    fresh_backups = [p for p in backups if p not in previous_backups]
    assert len(fresh_backups) == 1 and sha(fresh_backups[0]) == legacy_hash
    assert sentinel.read_bytes() == sentinel_bytes
    assert len(list(STARTUP.glob('SIGANDI*.dotm'))) == 1
    assert sha(TEMPLATE).lower() in (INSTALL / 'WordAddin/installation.txt').read_text().lower()
    assert not registry_present()
    runs.append(dict(run=number, exit_code=0, desktop_hash_matches=True,
                     word_hash_matches=True, legacy_backed_up=True,
                     one_active_template=True, unrelated_template_preserved=True,
                     current_pdf_guide_included=True))
report = dict(status='PASS', isolated_test_build=True, production_word_guard_reviewed=True,
              real_word_startup_untouched=True, runs=runs)
(OUT / 'report.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
print(json.dumps(report, indent=2))
