# SIMANIS mandiri — Desktop 1.5.2 / Word 0.5.2

Desktop dan fitur garis Word berjalan langsung tanpa aktivasi/lisensi, tanpa file .env atau pemeriksaan server lisensi. Login SIMANIS hanya untuk mengambil data penghadap/PPAT sesuai hak akun. ID macro/shape tetap kompatibel dengan dokumen lama.

Build: `python build_simanis_desktop.py` menghasilkan paket desktop portable lengkap dengan Word opsional. `python build_simanis_word.py` membuat paket Word terpisah. API produksi: http://192.168.0.12:8000/api (HTTP LAN). Lihat `word-addin/SIMANIS-DATA.md`.

## Riwayat SIGANDI sebelum edisi mandiri

# SIMANIS Desktop 1.5.0 / Word 0.5.0

Versioned desktop, Word add-in, tests and guide generator for the public release
at `/sigandi`. This source directory is excluded from production web deployment.
The actual installer, PDF and SHA-256 release manifest are published under
`public/downloads/sigandi/` and included in the existing CI/CD upload.

## Release changes

- Update official support to +62 877 2417 0145 across desktop, Word and guide.

- Fill both sides of internal centered headings without touching their words.
- Horizontal leaders remain black in preview, color changes, PDF and print.
- Rail colors still apply to vertical lines and their caps.
- Preserve source text, document title, header/footer and signing exclusions.
- Retain mandatory server authorization introduced in 1.4.5 / 0.4.5.

## Local checks

On a Windows build workstation with Python and the dependencies in
`requirements-dev.txt`:

```powershell
python -m unittest discover -s tests -q
python word-addin/build_addin.py
python word-addin/check_center_headings.py
```

Native Word integration tests require Microsoft Word and an existing legitimate
activation. They use isolated documents, do not create licenses, and restore
user preferences. They are not intended for the Linux hosting server.

Build the executable using `GarisAktaEditor.spec`, generate the PDF with
`docs/generate_sigandi_guide.py`, then compile `GarisAktaEditor.iss` with Inno Setup.
The Word builder requires pyopenvba 6.5.0. The PDF generator uses Windows Segoe UI.
Provide the vendor's private release `.env` only on the build workstation;
activation keys, API tokens, signing secrets and cached activations must never
be committed here. Do not use a placeholder signing secret for a release.

After rebuilding, replace both downloads and regenerate their byte counts and
SHA-256 values in `public/downloads/sigandi/release.json`. The backend feature
test verifies every downloadable file against this manifest. Updating the
website alone does not upgrade copies already installed on customers' PCs.

## Release 1.4.8 / Word 0.4.9

- Menu labels show Ctrl+F6 (All), Ctrl+F7 (Mark), Ctrl+F8 (Vertical), Ctrl+F9 (Fill), Ctrl+F10 (Refresh), Ctrl+F11 (PDF).
- Skip all horizontal fillers inside tables before visual-line measurement.
- Draw table rails 6 pt outside the original left edge; preserve existing borders and migrate legacy border bookmarks.
- Validate merged and multipage tables, table-only documents, native shortcuts and legacy border restoration in isolated Word tests.
- Updated 20-page PDF and TXT guide, including the Word shortcut table and table refresh workflow.
