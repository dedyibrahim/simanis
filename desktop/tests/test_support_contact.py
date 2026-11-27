"""Support contact displayed by the desktop must match the public release."""
import unittest
from unittest.mock import patch
from garis_editor import GarisEditorApp


class SupportContactTests(unittest.TestCase):
    def test_help_uses_the_current_whatsapp_number(self):
        app = object.__new__(GarisEditorApp)
        app.root = None
        with patch('garis_editor.messagebox.showinfo') as show:
            app.show_help_terms()
        message = show.call_args.args[1]
        self.assertIn('+62 877 2417 0145', message)
        self.assertIn('https://wa.me/6287724170145', message)
        self.assertNotIn('081289903664', message)
