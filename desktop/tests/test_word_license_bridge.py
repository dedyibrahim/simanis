"""Standalone SIMANIS contract, replacing legacy server-activation tests by user request."""
import argparse
import ast
from pathlib import Path
import sys
import tkinter as tk
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET
import garis_editor as app

ROOT = Path(__file__).resolve().parents[1]

class StandaloneEditionTests(unittest.TestCase):
    def test_desktop_has_no_activation_functions_or_server_configuration(self):
        source = (ROOT / "garis_editor.py").read_text(encoding="utf-8")
        tree = ast.parse(source)
        names = [node.name for node in ast.walk(tree) if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef))]
        self.assertFalse(any("license" in name.lower() for name in names))
        for text in ["GARIS_LICENSE", "DEFAULT_LICENSE", "require_current_license", "word_license_bridge"]:
            self.assertNotIn(text, source)
        with patch.object(sys, "argv", ["SIMANIS"]):
            args = vars(app.parse_args())
        self.assertFalse(any("license" in name for name in args))

    def test_word_has_no_activation_gate_or_license_menu(self):
        source = (ROOT / "word-addin/SigandiWord.bas").read_text(encoding="utf-8")
        for text in ["RequireLicense", "SigandiLicenseStatus", "--word-license", "Software\\RadinaNet"]:
            self.assertNotIn(text, source)
        ribbon = ET.parse(ROOT / "word-addin/customUI.xml")
        self.assertFalse(any("Lisensi" in element.attrib.get("label", "") for element in ribbon.iter()))
        self.assertTrue(any(element.attrib.get("onAction") == "RibbonSimanisData" for element in ribbon.iter()))

    def test_build_does_not_package_license_secrets(self):
        for name in ["GarisAktaEditor.spec", "build_garis_exe.bat"]:
            source = (ROOT / name).read_text(encoding="utf-8")
            self.assertNotIn(".env", source)
            self.assertNotIn("embedded_license", source)

    def test_desktop_starts_without_license_cache_or_network(self):
        original_tk = tk.Tk
        def hidden_root():
            root = original_tk()
            root.withdraw()
            return root
        def conversion_ready(self, *args):
            self.word_engine_ready = True
            return True
        with patch("garis_editor.tk.Tk", side_effect=hidden_root), \
             patch.object(app.GarisEditorApp, "ensure_desktop_shortcut_if_missing"), \
             patch.object(app.GarisEditorApp, "_show_loading_dialog"), \
             patch.object(app.GarisEditorApp, "_update_loading_dialog"), \
             patch.object(app.GarisEditorApp, "_hide_loading_dialog"), \
             patch.object(app.GarisEditorApp, "_ensure_word_conversion_ready", conversion_ready), \
             patch("urllib.request.urlopen", side_effect=AssertionError("Desktop must not need network")):
            desktop = app.GarisEditorApp(auto_detect=False)
            try:
                self.assertIn("SIMANIS", desktop.root.title())
                self.assertTrue(desktop.word_engine_ready)
                self.assertFalse(hasattr(desktop, "license_api_url"))
            finally:
                desktop.root.destroy()

if __name__ == "__main__": unittest.main()
