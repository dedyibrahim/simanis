# -*- mode: python ; coding: utf-8 -*-


a = Analysis(
    ['garis_editor.py'],
    pathex=[],
    binaries=[],
    datas=[('assets\\app_icon.png', 'assets'), ('scan_line_model_datatrain_v2.json', '.'), ('scan_line_model_2folder_allakta.json', '.')],
    hiddenimports=['tkinter', 'pyi_splash'],
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=[],
    noarchive=False,
    optimize=0,
)
pyz = PYZ(a.pure)

exe = EXE(
    pyz,
    a.scripts,
    a.binaries,
    a.datas,
    [],
    name='SIMANIS',
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=True,
    upx_exclude=[],
    runtime_tmpdir=None,
    console=False,
    disable_windowed_traceback=False,
    argv_emulation=False,
    target_arch=None,
    codesign_identity=None,
    entitlements_file=None,
    icon=['assets\\app_icon.ico'],
    version='assets\\windows_version.txt',
)
