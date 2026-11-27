import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock
import simanis_data as data

class SimanisDataTests(unittest.TestCase):
    def test_http_is_limited_to_private_lan_ips_and_url_credentials_are_rejected(self):
        for url in ["http://8.8.8.8/api", "http://example.com/api", "http://169.254.169.254/api", "https://user:pass@server/api", "https://server/api?token=x", "file:///api"]:
            with self.assertRaises(ValueError): data.api_base(url)
        self.assertEqual(data.api_base("https://192.168.0.12:8443/api/"), "https://192.168.0.12:8443/api")

    def test_private_http_api_is_supported(self):
        for host in ["192.168.0.12", "10.0.0.5", "172.16.0.5", "127.0.0.1"]:
            url = "http://" + host + ":8000/api"
            self.assertEqual(data.api_base(url), url)
        self.assertEqual(data.SimanisApi().base, "http://192.168.0.12:8000/api")

    def test_token_stays_in_header_and_only_current_session_is_closed(self):
        api = data.SimanisApi("https://example.test/api")
        responses = [
            {"status": True, "data": {"access_token": "TEST-TOKEN"}},
            {"status": True, "data": []},
            {"status": True, "data": {"closed": True}},
        ]
        seen = []
        def open_request(req, timeout):
            seen.append(req)
            response = Mock()
            response.__enter__ = Mock(return_value=response)
            response.__exit__ = Mock(return_value=False)
            response.read.return_value = json.dumps(responses.pop(0)).encode()
            return response
        api.opener.open = open_request
        api.login("user@example.test", "TEST-PASSWORD")
        api.call("/auth/word/clients")
        api.close()
        self.assertNotIn("TEST-TOKEN", seen[1].full_url)
        self.assertEqual(seen[1].get_header("Authorization"), "Bearer TEST-TOKEN")
        self.assertEqual(seen[2].method, "DELETE")
        self.assertTrue(seen[2].full_url.endswith("/auth/word/session"))
        self.assertEqual(api.token, "")

    def test_only_non_secret_connection_settings_are_saved(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "settings.json"
            data.save_settings("https://192.168.0.12:8443/api", "office.pem", path)
            self.assertEqual(set(json.loads(path.read_text())), {"api", "ca_file"})
            self.assertEqual(data.read_settings(path)["api"], "https://192.168.0.12:8443/api")
            path.write_text('{"api":"http://server/api"}')
            self.assertEqual(data.read_settings(path), {})

    def test_otp_does_not_establish_a_session_until_verified(self):
        api = data.SimanisApi("https://example.test/api")
        api.accept_login({"otp_required": True, "challenge_id": "test"})
        self.assertEqual(api.token, "")
        with self.assertRaises(data.ApiError): api.accept_login({})

    def test_formatting_preserves_unicode_and_only_chosen_fields(self):
        fields = [{"label": "Nama", "value": "Núria"}, {"label": "Harga", "value": "125000000"}]
        self.assertEqual(data.field_text(fields[:1], False), "Núria")
        self.assertEqual(data.field_text(fields), "Nama: Núria\nHarga: 125000000")
        with tempfile.TemporaryDirectory() as folder:
            target = Path(folder) / "selection.txt"
            data.write_selection(target, data.field_text(fields))
            self.assertEqual(target.read_text(encoding="utf-16"), "Nama: Núria\nHarga: 125000000")
            with self.assertRaises(ValueError): data.write_selection(target, "")
            with self.assertRaises(ValueError): data.write_selection(target, "x" * 100001)
        self.assertIsNone(data.NoRedirect().redirect_request(None, None, 302, "", {}, "https://other.test"))

if __name__ == "__main__": unittest.main()
