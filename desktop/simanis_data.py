"""SIMANIS Word data picker. HTTP for private LAN IPs or verified TLS; credentials/token are never persisted."""
import argparse
import json
import ipaddress
import os
from pathlib import Path
import queue
import ssl
import tempfile
import threading
import tkinter as tk
from tkinter import ttk, filedialog
from urllib import request, error, parse

DEFAULT_API = "http://192.168.0.12:8000/api"

class NoRedirect(request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

class ApiError(Exception):
    pass

def api_base(value):
    value = value.strip().rstrip("/")
    parts = parse.urlsplit(value)
    if parts.scheme not in ("http", "https") or not parts.hostname or parts.username or parts.password or parts.query or parts.fragment:
        raise ValueError("Gunakan alamat API HTTP LAN atau HTTPS, tanpa user/password di URL.")
    if parts.scheme == "http":
        try:
            address = ipaddress.ip_address(parts.hostname)
            networks = ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "127.0.0.0/8", "::1/128")
            if not any(address in ipaddress.ip_network(network) for network in networks):
                raise ValueError()
        except ValueError:
            raise ValueError("HTTP hanya diperbolehkan untuk IP LAN privat atau loopback.") from None
    if parts.path.rstrip("/") != "/api":
        raise ValueError("Alamat server harus diakhiri /api.")
    return value

class SimanisApi:
    def __init__(self, base=DEFAULT_API, ca_file=None):
        self.base = api_base(base)
        self.token = ""
        context = ssl.create_default_context()
        if ca_file:
            context.load_verify_locations(cafile=str(ca_file))
        self.opener = request.build_opener(NoRedirect(), request.HTTPSHandler(context=context))

    def call(self, path, payload=None, method=None):
        headers = {"Accept": "application/json"}
        if self.token:
            headers["Authorization"] = "Bearer " + self.token
        body = None
        if payload is not None:
            body = json.dumps(payload).encode("utf-8")
            headers["Content-Type"] = "application/json"
        req = request.Request(self.base + path, data=body, headers=headers, method=method or ("POST" if body else "GET"))
        try:
            with self.opener.open(req, timeout=20) as response:
                raw = response.read(2_000_001)
                if len(raw) > 2_000_000:
                    raise ApiError("Respons terlalu besar. Persempit pencarian.")
                result = json.loads(raw)
        except error.HTTPError as exc:
            if exc.code == 401:
                self.token = ""
            try:
                message = json.loads(exc.read(65536)).get("message", "Permintaan ditolak server.")
            except (ValueError, AttributeError):
                message = "Permintaan ditolak server (HTTP %s)." % exc.code
            raise ApiError(message) from None
        except (error.URLError, TimeoutError, ValueError, OSError):
            raise ApiError("Tidak dapat terhubung. Periksa alamat API, koneksi LAN, dan sertifikat jika memakai HTTPS.") from None
        if not isinstance(result, dict) or result.get("status") is False:
            raise ApiError(result.get("message", "Permintaan gagal.") if isinstance(result, dict) else "Respons tidak valid.")
        return result.get("data", result)

    def accept_login(self, data):
        if data.get("otp_required"):
            return data
        token = data.get("access_token", "")
        if not token:
            raise ApiError("Sesi login tidak valid.")
        self.token = token
        return data

    def login(self, email, password):
        return self.accept_login(self.call("/auth/login", {"email": email, "password": password}))

    def verify(self, challenge, otp):
        return self.accept_login(self.call("/auth/login/verify-otp", {"challenge_id": challenge, "otp": otp}))

    def close(self):
        if self.token:
            try:
                self.call("/auth/word/session", method="DELETE")
            except ApiError:
                pass
            self.token = ""

def field_text(fields, labels=True):
    parts = []
    for field in fields:
        value = str(field.get("value", "")).replace("\x00", "").strip()
        label = str(field.get("label", "")).replace("\r", " ").replace("\n", " ").strip()
        if value:
            parts.append((label + ": " if labels else "") + value)
    return "\n".join(parts)

def write_selection(path, text):
    target = Path(path).resolve()
    if not target.parent.is_dir() or not text.strip() or len(text) > 100_000:
        raise ValueError("Hasil kosong, terlalu panjang, atau folder hasil tidak tersedia.")
    fd, stage = tempfile.mkstemp(prefix="simanis-word-", suffix=".tmp", dir=target.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-16", newline="") as stream:
            stream.write(text.replace("\r\n", "\n").replace("\n", "\r\n"))
        os.replace(stage, target)
    finally:
        if os.path.exists(stage):
            os.unlink(stage)

def settings_path():
    return Path(os.environ.get("LOCALAPPDATA", str(Path.home()))) / "SIMANIS" / "word-data.json"

def read_settings(path=None):
    try:
        path = Path(path) if path else settings_path()
        if path.stat().st_size > 8192: return {}
        data = json.loads(path.read_text(encoding="utf-8"))
        return {"api": api_base(data["api"]), "ca_file": str(data.get("ca_file", ""))}
    except (OSError, ValueError, KeyError, TypeError):
        return {}

def save_settings(base, ca_file, path=None):
    path = Path(path) if path else settings_path()
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps({"api": api_base(base), "ca_file": str(ca_file)}, indent=2), encoding="utf-8")

class DataPicker:
    def __init__(self, root, output=None):
        self.root, self.output = root, output
        self.api, self.challenge = None, ""
        self.results, self.fields = [], []
        self.busy = False
        self.pending = queue.Queue()
        root.title("SIMANIS | Pekerjaan operasional untuk draf Word")
        root.geometry("1080x780"); root.minsize(860, 620)
        ttk.Style(root).configure("TButton", padding=(10, 7))
        container = ttk.Frame(root, padding=18); container.pack(fill="both", expand=True)
        ttk.Label(container, text="SIMANIS untuk Word", font=("Segoe UI", 18, "bold")).pack(anchor="w")
        ttk.Label(container, text="Pilih data tersimpan, tinjau field, lalu masukkan ke posisi kursor Word.").pack(anchor="w", pady=(4, 14))
        self.status = tk.StringVar(value="Login SIMANIS. HTTP LAN tidak terenkripsi; gunakan jaringan kantor tepercaya.")
        self.login_frame = ttk.LabelFrame(container, text="Login SIMANIS", padding=12); self.login_frame.pack(fill="x")
        connection = read_settings()
        self.server = tk.StringVar(value=connection.get("api", DEFAULT_API))
        self.ca_file = tk.StringVar(value=connection.get("ca_file", ""))
        self.email, self.password, self.otp = tk.StringVar(), tk.StringVar(), tk.StringVar()
        for row, (label, variable, secret) in enumerate([
            ("Alamat API (HTTP LAN / HTTPS)", self.server, False), ("Email", self.email, False),
            ("Password", self.password, True), ("Kode OTP (jika diminta)", self.otp, False),
        ]):
            ttk.Label(self.login_frame, text=label).grid(row=row, column=0, sticky="w", padx=(0, 12), pady=3)
            ttk.Entry(self.login_frame, textvariable=variable, show="*" if secret else "").grid(row=row, column=1, sticky="ew", pady=3)
        ttk.Label(self.login_frame, text="Sertifikat CA internal (opsional)").grid(row=4, column=0, sticky="w")
        ttk.Entry(self.login_frame, textvariable=self.ca_file).grid(row=4, column=1, sticky="ew")
        ttk.Button(self.login_frame, text="Pilih PEM", command=lambda: self.ca_file.set(filedialog.askopenfilename(filetypes=[("Sertifikat publik", "*.pem *.crt")]))).grid(row=4, column=2)
        self.login_frame.columnconfigure(1, weight=1)
        ttk.Button(self.login_frame, text="Login / Verifikasi OTP", command=self.login).grid(row=5, column=1, sticky="e", pady=6)
        self.content = ttk.Frame(container)
        search = ttk.Frame(self.content); search.pack(fill="x", pady=(8, 10))
        self.mode, self.query = tk.StringVar(value="Pekerjaan Saya"), tk.StringVar()
        self.mode_box = ttk.Combobox(search, textvariable=self.mode, values=["Pekerjaan Saya", "Penghadap", "Transaksi PPAT"], state="readonly", width=19)
        self.mode_box.pack(side="left", padx=(0, 8)); self.mode_box.bind("<<ComboboxSelected>>", lambda event: self.search())
        query_entry = ttk.Entry(search, textvariable=self.query); query_entry.pack(side="left", fill="x", expand=True)
        query_entry.bind("<Return>", lambda event: self.search())
        ttk.Button(search, text="Cari", command=self.search).pack(side="left", padx=(8, 0))
        panes = ttk.Panedwindow(self.content, orient="horizontal"); panes.pack(fill="both", expand=True)
        left = ttk.LabelFrame(panes, text="Hasil pencarian", padding=8)
        right = ttk.LabelFrame(panes, text="Pilih field untuk dokumen (Ctrl+klik / Shift+klik)", padding=8)
        panes.add(left, weight=2); panes.add(right, weight=3)
        self.list = tk.Listbox(left, exportselection=False, width=34); self.list.pack(fill="both", expand=True)
        self.list.bind("<<ListboxSelect>>", lambda event: self.detail())
        self.tree = ttk.Treeview(right, columns=("label", "value"), show="headings", selectmode="extended", height=10)
        self.tree.heading("label", text="Field"); self.tree.heading("value", text="Nilai")
        self.tree.column("label", width=180); self.tree.column("value", width=220); self.tree.pack(fill="both", expand=True)
        self.tree.bind("<<TreeviewSelect>>", lambda event: self.preview())
        choices = ttk.Frame(right); choices.pack(fill="x", pady=6)
        ttk.Button(choices, text="Pilih semua", command=lambda: self.tree.selection_set(self.tree.get_children())).pack(side="left")
        ttk.Button(choices, text="Kosongkan", command=lambda: self.tree.selection_remove(self.tree.selection())).pack(side="left", padx=6)
        self.labels = tk.BooleanVar(value=True)
        ttk.Checkbutton(choices, text="Dengan label", variable=self.labels, command=self.preview).pack(side="left")
        self.text = tk.Text(self.content, height=6, wrap="word", state="disabled"); self.text.pack(fill="x", pady=10)
        ttk.Button(self.content, text="Masukkan pilihan ke Word" if output else "Salin pilihan", command=self.commit).pack(anchor="e")
        ttk.Label(container, textvariable=self.status, wraplength=950).pack(fill="x", pady=(12, 0))
        root.after(80, self.poll)

    def task(self, action, done):
        if self.busy: return
        self.busy = True
        self.mode_box.configure(state="disabled"); self.list.configure(state="disabled")
        self.status.set("Memproses...")
        def work():
            try: self.pending.put((True, action(), done))
            except Exception as exc: self.pending.put((False, str(exc), done))
        threading.Thread(target=work, daemon=True).start()

    def poll(self):
        try:
            ok, result, done = self.pending.get_nowait()
            self.busy = False
            self.mode_box.configure(state="readonly"); self.list.configure(state="normal")
            if ok:
                self.status.set(""); done(result)
            else:
                self.status.set(result)
                if self.api and not self.api.token and self.content.winfo_manager():
                    self.content.pack_forget(); self.login_frame.pack(fill="x")
        except queue.Empty: pass
        self.root.after(80, self.poll)

    def login(self):
        if self.busy: return
        if not self.challenge:
            try: self.api = SimanisApi(self.server.get(), self.ca_file.get() or None)
            except (ValueError, OSError, ssl.SSLError) as exc:
                self.status.set(str(exc)); return
            email, password = self.email.get(), self.password.get()
            self.task(lambda: self.api.login(email, password), self.logged_in)
        else:
            otp = self.otp.get().strip()
            self.task(lambda: self.api.verify(self.challenge, otp), self.logged_in)

    def logged_in(self, data):
        self.password.set("")
        if data.get("otp_required"):
            self.challenge = data["challenge_id"]
            self.status.set("OTP dikirim ke WhatsApp. Masukkan kode lalu klik Verifikasi."); return
        self.challenge = ""; self.otp.set("")
        try: save_settings(self.api.base, self.ca_file.get())
        except OSError: pass
        self.login_frame.pack_forget(); self.content.pack(fill="both", expand=True)
        self.search()

    def search(self):
        if self.busy or not self.api or not self.api.token: return
        self.loaded_mode = self.mode.get()
        self.fields = []; self.tree.delete(*self.tree.get_children()); self.preview()
        path = {"Pekerjaan Saya": "/auth/word/jobs", "Penghadap": "/auth/word/clients", "Transaksi PPAT": "/auth/word/ppat"}[self.loaded_mode]
        query = self.query.get().strip()[:100]
        def done(rows):
            self.results = rows; self.list.delete(0, "end")
            for row in rows: self.list.insert("end", row.get("nama_client", row.get("title", "")))
            self.status.set("Pilih hasil. Asisten hanya melihat data pekerjaan/nomor miliknya." if rows else "Tidak ada data yang sesuai atau belum ada pekerjaan milik Anda.")
        self.task(lambda: self.api.call(path + "?" + parse.urlencode({"q": query})), done)

    def detail(self):
        if self.busy or not self.list.curselection(): return
        row = self.results[self.list.curselection()[0]]
        self.fields = []; self.tree.delete(*self.tree.get_children()); self.preview()
        if self.loaded_mode == "Pekerjaan Saya":
            path = "/auth/word/jobs/" + parse.quote(str(row["id"]), safe="")
        elif self.loaded_mode == "Penghadap":
            path = "/auth/word/clients/" + parse.quote(str(row["id_client"]), safe="")
        else:
            path = "/auth/word/ppat/" + row["source"] + "/" + parse.quote(str(row["id"]), safe="")
        def done(data):
            self.fields = data["fields"]
            for i, field in enumerate(self.fields): self.tree.insert("", "end", iid=str(i), values=(field["label"], field["value"]))
            self.tree.selection_set(self.tree.get_children()); self.preview()
            self.status.set(data["title"] + " — tinjau data sebelum dimasukkan.")
        self.task(lambda: self.api.call(path), done)

    def selected_text(self):
        chosen = set(self.tree.selection())
        return field_text([field for i, field in enumerate(self.fields) if str(i) in chosen], self.labels.get())

    def preview(self):
        self.text.configure(state="normal"); self.text.delete("1.0", "end")
        self.text.insert("1.0", self.selected_text()); self.text.configure(state="disabled")

    def commit(self):
        if self.busy: return
        text = self.selected_text()
        if not text:
            self.status.set("Pilih sedikitnya satu field terlebih dahulu."); return
        if self.output:
            write_selection(self.output, text); self.root.destroy()
        else:
            self.root.clipboard_clear(); self.root.clipboard_append(text)
            self.status.set("Pilihan disalin. Tempelkan ke dokumen yang diinginkan.")

def main():
    parser = argparse.ArgumentParser(description="SIMANIS - pemilih data penghadap dan PPAT")
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    root = tk.Tk(); picker = DataPicker(root, args.output)
    try: root.mainloop()
    finally:
        if picker.api: picker.api.close()

if __name__ == "__main__":
    main()
