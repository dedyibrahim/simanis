import argparse
import base64
import hashlib
import hmac
import json
import math
import os
import platform
import shutil
import subprocess
import sys
import tempfile
import tkinter as tk
import urllib.error
import urllib.request
import uuid
import time
from datetime import date, datetime, timedelta
from tkinter import filedialog, messagebox, simpledialog, ttk
from xml.sax.saxutils import escape as xml_escape

import fitz

import garis_akta as ga
import scan_line_apply as sla
import end_line_fill as elf

try:
    import pyi_splash  # type: ignore
except Exception:
    pyi_splash = None


def _runtime_base_dir():
    if getattr(sys, "frozen", False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


APP_VERSION = "1.5.3"
APP_SHORTCUT_NAME = "SIMANIS.lnk"

COLOR_RED = (1.0, 0.0, 0.0)
COLOR_BLACK = (0.0, 0.0, 0.0)
COLOR_MAP = {
    "Merah": COLOR_RED,
    "Hitam": COLOR_BLACK,
}


def app_base_dir():
    if getattr(sys, "frozen", False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


def resolve_asset_path(rel_path):
    candidates = [os.path.join(app_base_dir(), rel_path)]
    meipass = getattr(sys, "_MEIPASS", None)
    if meipass:
        candidates.append(os.path.join(meipass, rel_path))
    for p in candidates:
        if os.path.exists(p):
            return p
    return None


def resolve_model_path(path):
    if not path:
        return path

    candidates = [path]
    if not os.path.isabs(path):
        candidates.append(os.path.join(os.getcwd(), path))
        candidates.append(os.path.join(os.path.dirname(os.path.abspath(__file__)), path))
        if getattr(sys, "frozen", False):
            candidates.append(os.path.join(os.path.dirname(sys.executable), path))
        meipass = getattr(sys, "_MEIPASS", None)
        if meipass:
            candidates.append(os.path.join(meipass, path))

    seen = set()
    for c in candidates:
        if not c:
            continue
        c_abs = os.path.abspath(c)
        if c_abs in seen:
            continue
        seen.add(c_abs)
        if os.path.exists(c_abs):
            return c_abs
    return path


def close_boot_splash_if_any():
    mod = pyi_splash
    if mod is None:
        return
    try:
        is_alive = getattr(mod, "is_alive", None)
        if callable(is_alive) and not bool(is_alive()):
            return
    except Exception:
        pass
    try:
        mod.close()
    except Exception:
        pass


def _safe_piece(value):
    return str(value or "").strip().lower()


class GarisEditorApp:
    def __init__(
        self,
        pdf_path=None,
        model_file="garis_local_model_bodyx_v9.json",
        scan_model_file="scan_line_model_datatrain_v2.json",
        scan_zoom=2.0,
        outside_shift=4.0,
        legacy_auto_detect=False,
        threshold=0.4,
        auto_detect=True,
        line_color="Hitam",
    ):
        close_boot_splash_if_any()
        self.root = tk.Tk()
        self.root.title("SIMANIS · Administrasi Notaris dan PPAT")
        self.root.geometry(f"{min(1360, self.root.winfo_screenwidth() - 60)}x{min(900, self.root.winfo_screenheight() - 100)}")
        self.is_packaged_build = bool(getattr(sys, "frozen", False))
        self.header_title = "SIMANIS - Notaris dan PPAT"
        self.header_subtitle = (
            "SIMANIS Desktop" if self.is_packaged_build else "Developer Workspace"
        )
        self.header_brand_text = f"SIMANIS · v{APP_VERSION}"

        self.model_file = resolve_model_path(model_file)
        self.scan_model_file = resolve_model_path(scan_model_file)
        self.scan_zoom = float(scan_zoom)
        self.outside_shift = float(outside_shift)
        self.legacy_auto_detect = bool(legacy_auto_detect)
        self.threshold = threshold
        self.auto_detect = auto_detect
        self.icon_image = None
        self.header_logo_image = None
        self.line_color_name = line_color if line_color in COLOR_MAP else "Hitam"
        self.line_color_var = tk.StringVar(value=self.line_color_name)
        mode = str(os.environ.get("GARIS_WORD_CONVERTER_MODE", "external_only")).strip().lower()
        # Backward compatibility: map legacy values to external mode.
        if mode in ("aspose_only", "aspose_first", "open_source_only", "open_source_first"):
            mode = "external_only"
        if mode != "external_only":
            mode = "external_only"
        self.word_converter_mode = mode
        self.word_engine_checked = False
        self.word_engine_ready = False
        self.word_engine_error = ""

        self.pdf_path = None
        self.doc = None
        self.document_base_name = None
        self.page_index = 0
        self.zoom = 1.4
        self.page_offset_x = 0.0
        self.page_offset_y = 0.0
        self.page_image = None
        self.segments_by_page = {}
        self.end_fill_settings = elf.normalize_settings()
        self._end_fill_cache_key = None
        self._end_fill_cache = {}
        self.line_end_overhang_pt = 2.2
        self.cap_cross_back_ratio = 0.16
        self.cap_cross_enabled = True
        self.cap_cross_vertical_overshoot_pt = 0.0
        self.cap_cross_vertical_ratio = 0.10
        self.current_source_word_path = None

        self.selected_idx = None
        self.drag_mode = None
        self.last_canvas_x = 0.0
        self.last_canvas_y = 0.0
        self.create_mode = False
        self.color_toggle_widgets = {}
        self.undo_stack = []
        self.redo_stack = []
        self.max_history = 80
        self._drag_start_snapshot = None
        self._drag_changed = False
        self.loading_dialog = None
        self.loading_message_var = None
        self.loading_detail_var = None
        self.loading_logo_image = None
        self.loading_logo_source_image = None
        self.thumb_images = []
        self.thumb_items = []
        self.thumb_window_id = None
        self.thumb_drag_start_page = None
        self.thumb_drag_start_y = 0
        self.thumb_dragging = False
        self.print_temp_dirs = []
        self.booklet_button = None
        self.booklet_mode_active = False
        self.booklet_source_pdf_path = None
        self.booklet_preview_pdf_path = None
        self.booklet_editor_snapshot = None
        self.booklet_editor_snapshot_source = None
        self.booklet_layout_pairs = []
        self.booklet_source_page_count = 0
        self.booklet_source_page_w = 0.0
        self.booklet_source_page_h = 0.0

        # Tampilkan loading awal agar startup terasa responsif.
        self._show_loading_dialog(
            message="Initialize aplikasi...",
            detail="Menyiapkan antarmuka, mohon tunggu sebentar.",
        )
        try:
            self._init_ttk_styles()
            self._update_loading_dialog(detail="Membangun antarmuka...")
            self._build_ui()
            self._bind_events()
            self.apply_line_color(self.line_color_name)
            self.apply_app_icon()
            self._update_loading_dialog(detail="Menyiapkan shortcut desktop...")
            self.ensure_desktop_shortcut_if_missing()
        finally:
            self._hide_loading_dialog()
            close_boot_splash_if_any()

        # Validasi engine DOC/DOCX sejak awal agar error ketahuan sebelum dipakai.
        self._run_with_loading("Cek engine DOCX...", self._ensure_word_conversion_ready, True)
        if not bool(getattr(self, "word_engine_ready", False)):
            warn = str(getattr(self, "word_engine_error", "") or "").strip()
            if not warn:
                warn = "Engine konversi DOC/DOCX belum siap."
            messagebox.showwarning(
                "Peringatan DOCX",
                warn + "\n\nFitur Load DOCX mungkin tidak bisa digunakan.",
            )

        if pdf_path:
            self._run_with_loading("Membuka dokumen awal...", self.load_pdf, pdf_path, run_auto_detect=False)
            self.set_status("Dokumen berhasil dimuat. Klik tombol 'Buat Garis' untuk memproses garis.")
        else:
            self.set_status("Buka PDF atau Load DOCX untuk mulai.")

    def _init_ttk_styles(self):
        self.ttk_style = ttk.Style(self.root)
        try:
            self.ttk_style.theme_use("clam")
        except Exception:
            pass

        self.ttk_style.configure(
            "RibbonAction.TButton",
            font=("Segoe UI", 9, "bold"),
            padding=(12, 6),
            foreground="#0f2a1d",
            background="#ffffff",
            bordercolor="#e2f0e9",
            lightcolor="#ffffff",
            darkcolor="#ffffff",
            relief="flat",
        )
        self.ttk_style.map(
            "RibbonAction.TButton",
            foreground=[("disabled", "#94b8a7"), ("!disabled", "#0f2a1d")],
            background=[
                ("pressed", "#d5eee2"),
                ("active", "#e6f5ee"),
                ("!disabled", "#ffffff"),
            ],
            bordercolor=[
                ("pressed", "#16845b"),
                ("active", "#65b58f"),
                ("!disabled", "#e2f0e9"),
            ],
        )
        self.ttk_style.configure(
            "Dialog.TButton",
            font=("Segoe UI", 9, "bold"),
            padding=(10, 5),
            foreground="#0f2a1d",
            background="#ffffff",
            bordercolor="#cbe1d6",
            lightcolor="#ffffff",
            darkcolor="#ffffff",
        )
        self.ttk_style.configure(
            "DialogAccent.TButton",
            font=("Segoe UI", 9, "bold"),
            padding=(10, 5),
            foreground="#ffffff",
            background="#16845b",
            bordercolor="#16845b",
            lightcolor="#16845b",
            darkcolor="#16845b",
        )
        self.ttk_style.map(
            "DialogAccent.TButton",
            foreground=[("disabled", "#cbe1d6"), ("!disabled", "#ffffff")],
            background=[
                ("pressed", "#116b49"),
                ("active", "#116b49"),
                ("!disabled", "#16845b"),
            ],
            bordercolor=[
                ("pressed", "#116b49"),
                ("active", "#116b49"),
                ("!disabled", "#16845b"),
            ],
        )
        self.ttk_style.configure("Modern.Vertical.TScrollbar", arrowsize=11, width=10,
                                 background="#cbe1d6", troughcolor="#f8fcfa", borderwidth=0)
        self.ttk_style.map("Modern.Vertical.TScrollbar", background=[("active", "#94b8a7")])
        self.ttk_style.configure(
            "DialogSmall.TButton",
            font=("Segoe UI", 8, "bold"),
            padding=(7, 2),
        )

    def _ps_quote(self, text):
        return "'" + str(text or "").replace("'", "''") + "'"

    def ensure_desktop_shortcut_if_missing(self):
        if not sys.platform.startswith("win"):
            return

        target_path = os.path.abspath(sys.executable if getattr(sys, "frozen", False) else __file__)
        if not target_path.lower().endswith(".exe"):
            return

        work_dir = os.path.dirname(target_path)
        shortcut_name = APP_SHORTCUT_NAME
        script = (
            "$ErrorActionPreference='Stop';"
            "$desktop=[Environment]::GetFolderPath('Desktop');"
            + f"$lnk=Join-Path $desktop {self._ps_quote(shortcut_name)};"
            "if(-not (Test-Path -LiteralPath $lnk)){"
            "$ws=New-Object -ComObject WScript.Shell;"
            "$sc=$ws.CreateShortcut($lnk);"
            + f"$sc.TargetPath={self._ps_quote(target_path)};"
            + f"$sc.WorkingDirectory={self._ps_quote(work_dir)};"
            + f"$sc.IconLocation={self._ps_quote(target_path + ',0')};"
            + f"$sc.Description={self._ps_quote("SIMANIS Desktop")};"
            "$sc.Save();"
            "}"
        )

        startupinfo = None
        if hasattr(subprocess, "STARTUPINFO"):
            try:
                startupinfo = subprocess.STARTUPINFO()
                startupinfo.dwFlags |= subprocess.STARTF_USESHOWWINDOW
                startupinfo.wShowWindow = 0
            except Exception:
                startupinfo = None

        try:
            subprocess.run(
                ["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-Command", script],
                check=True,
                capture_output=True,
                text=True,
                timeout=20,
                startupinfo=startupinfo,
            )
        except Exception:
            # Non-fatal: aplikasi tetap jalan walau gagal membuat shortcut.
            pass

    def _build_ui(self):
        self.root.configure(bg="#f5fbf8")
        self.root.minsize(1050, 700)
        self.root.option_add("*Font", ("Segoe UI", 10))

        self.header_canvas = tk.Canvas(self.root, height=44, bg="#123b2e", highlightthickness=0)
        self.header_canvas.pack(fill=tk.X)
        self.header_canvas.bind("<Configure>", self._draw_header_gradient)

        nav = tk.Frame(self.root, bg="#ffffff", height=38)
        nav.pack(fill=tk.X)
        nav.pack_propagate(False)
        tabs = tk.Frame(nav, bg="#ffffff")
        tabs.pack(side=tk.LEFT, padx=22, fill=tk.Y)
        self.ribbon_tab_buttons = {}
        self.ribbon_tab_frames = {}
        self.active_ribbon_tab = None
        self._create_ribbon_tab(tabs, "dokumen", "Dokumen")
        self._create_ribbon_tab(tabs, "halaman", "Kelola Halaman")
        self._create_ribbon_tab(tabs, "tools", "Pengaturan Garis")
        actions = tk.Frame(nav, bg="#ffffff")
        actions.pack(side=tk.RIGHT, padx=18, pady=3)
        self._make_tab_action_button(actions, "Data SIMANIS", self.open_simanis_data).pack(side=tk.LEFT, padx=3)
        self._make_tab_action_button(actions, "↶  Undo", self.undo_action).pack(side=tk.LEFT, padx=3)
        self._make_tab_action_button(actions, "↷  Redo", self.redo_action).pack(side=tk.LEFT, padx=3)
        self._make_tab_action_button(actions, "Cetak", self.print_document).pack(side=tk.LEFT, padx=(14, 6))
        self._make_button(actions, "Simpan PDF", self.export_pdf, width=13, accent=True).pack(side=tk.LEFT)
        tk.Frame(self.root, bg="#e5f0eb", height=1).pack(fill=tk.X)

        ribbon = tk.Frame(self.root, bg="#ffffff", height=56)
        ribbon.pack(fill=tk.X)
        ribbon.pack_propagate(False)
        self.ribbon_global_actions = tk.Frame(ribbon, bg="#ffffff")
        self.ribbon_global_actions.pack(side=tk.RIGHT, padx=(10, 18), pady=10)
        self._make_ribbon_tool(self.ribbon_global_actions, "spark", "Buat Garis", self.trigger_auto_generate_lines,
                               width=11, bg_color="#16845b", hover_bg_color="#116b49",
                               icon_fg="#ffffff", text_fg="#ffffff").pack(side=tk.RIGHT)
        self._make_ribbon_tool(self.ribbon_global_actions, "compress", "Kompres PDF", self.open_compress_pdf_dialog,
                               width=10, icon_fg="#648b78").pack(side=tk.RIGHT, padx=(0, 8))
        self.ribbon_tab_content = tk.Frame(ribbon, bg="#ffffff")
        self.ribbon_tab_content.pack(side=tk.LEFT, padx=(14, 0), pady=10, fill=tk.X, expand=True)
        for key in ("dokumen", "halaman", "tools"):
            self.ribbon_tab_frames[key] = tk.Frame(self.ribbon_tab_content, bg="#ffffff")

        doc = self._make_ribbon_group(self.ribbon_tab_frames["dokumen"], "BUKA DOKUMEN")
        self._make_ribbon_tool(doc, "file", "Buka PDF", self.open_pdf_dialog, accent=True).pack(side=tk.LEFT, padx=3)
        self._make_ribbon_tool(doc, "word", "Buka Word", self.open_docx_dialog).pack(side=tk.LEFT, padx=3)
        support = self._make_ribbon_group(self.ribbon_tab_frames["dokumen"], "PUSAT BANTUAN", False)
        self._make_ribbon_tool(support, "help", "Panduan", self.show_help_terms).pack(side=tk.LEFT, padx=3)

        pages = self._make_ribbon_group(self.ribbon_tab_frames["halaman"], "SUSUN DOKUMEN")
        self._make_ribbon_tool(pages, "insert", "Sisip PDF", self.insert_pdf_after_current_dialog).pack(side=tk.LEFT, padx=3)
        self._make_ribbon_tool(pages, "merge", "Gabung PDF", self.append_pdf_dialog).pack(side=tk.LEFT, padx=3)
        self._make_ribbon_tool(pages, "delete", "Hapus", self.delete_current_page_dialog, icon_fg="#dc5c65").pack(side=tk.LEFT, padx=3)
        booklet = self._make_ribbon_group(self.ribbon_tab_frames["halaman"], "FORMAT CETAK", False)
        self.booklet_button = self._make_ribbon_tool(booklet, "book", "Buat Booklet", self.export_booklet_pdf, width=10)
        self.booklet_button.pack(side=tk.LEFT, padx=3)
        self._make_ribbon_tool(booklet, "split", "A3 ke A4", self.export_debooklet_scan_pdf).pack(side=tk.LEFT, padx=3)

        colors = self._make_ribbon_group(self.ribbon_tab_frames["tools"], "WARNA GARIS")
        self._make_color_toggle(colors, "Hitam", selected_bg="#e6f5ee", selected_border="#16845b").pack(side=tk.LEFT, padx=3)
        self._make_color_toggle(colors, "Merah", icon_fg="#dc2626", text_fg="#b91c1c",
                                selected_bg="#fef2f2", selected_border="#fca5a5").pack(side=tk.LEFT, padx=3)
        lines = self._make_ribbon_group(self.ribbon_tab_frames["tools"], "EDIT GARIS", False)
        self._make_ribbon_tool(lines, "angle", "Kemiringan", self.open_cap_settings_dialog, width=10).pack(side=tk.LEFT, padx=3)
        self._make_ribbon_tool(lines, "add", "Tambah Garis", self.enable_create_mode, width=10).pack(side=tk.LEFT, padx=3)
        self._make_ribbon_tool(lines, "leaders", "Isi ----", self.open_end_fill_dialog).pack(side=tk.LEFT, padx=3)
        self._set_active_ribbon_tab("dokumen")
        tk.Frame(self.root, bg="#e5f0eb", height=1).pack(fill=tk.X)

        status = tk.Frame(self.root, bg="#ffffff", height=34)
        status.pack(side=tk.BOTTOM, fill=tk.X)
        status.pack_propagate(False)
        tk.Label(status, text="●", fg="#16845b", bg="#ffffff", font=("Segoe UI", 9)).pack(side=tk.LEFT, padx=(20, 8))
        self.status_var = tk.StringVar(value="Siap. Buka dokumen untuk mulai.")
        tk.Label(status, textvariable=self.status_var, fg="#527464", bg="#ffffff", font=("Segoe UI", 9), anchor="w").pack(side=tk.LEFT, fill=tk.X, expand=True)
        tk.Label(status, text="Ctrl+O  Buka     Ctrl+G  Buat garis     Ctrl+S  Simpan", fg="#84a394", bg="#ffffff", font=("Segoe UI", 8)).pack(side=tk.RIGHT, padx=20)

        middle = tk.Frame(self.root, bg="#f5fbf8")
        middle.pack(fill=tk.BOTH, expand=True, padx=18, pady=10)
        sidebar = tk.Frame(middle, bg="#ffffff", width=204, highlightthickness=1, highlightbackground="#e2f0e9")
        sidebar.pack(side=tk.LEFT, fill=tk.Y, padx=(0, 14))
        sidebar.pack_propagate(False)
        side_title = tk.Frame(sidebar, bg="#ffffff")
        side_title.pack(fill=tk.X, padx=16, pady=(16, 4))
        tk.Label(side_title, text="Halaman", bg="#ffffff", fg="#1e3b2d", font=("Segoe UI", 11, "bold")).pack(side=tk.LEFT)
        self.page_count_var = tk.StringVar(value="0")
        tk.Label(side_title, textvariable=self.page_count_var, bg="#edf6f2", fg="#527866", padx=8, font=("Segoe UI", 9, "bold")).pack(side=tk.RIGHT)
        tk.Label(sidebar, text="Geser thumbnail untuk menyusun", bg="#ffffff", fg="#84a394", font=("Segoe UI", 8)).pack(anchor="w", padx=16, pady=(0, 12))
        tk.Frame(sidebar, bg="#edf5f1", height=1).pack(fill=tk.X)
        thumb_body = tk.Frame(sidebar, bg="#f8fcfa")
        thumb_body.pack(fill=tk.BOTH, expand=True)
        thumb_body.grid_rowconfigure(0, weight=1)
        thumb_body.grid_columnconfigure(0, weight=1)
        self.thumb_canvas = tk.Canvas(thumb_body, bg="#f8fcfa", highlightthickness=0, bd=0)
        self.thumb_canvas.grid(row=0, column=0, sticky="nsew")
        self.thumb_scroll = ttk.Scrollbar(thumb_body, orient=tk.VERTICAL, command=self.thumb_canvas.yview, style="Modern.Vertical.TScrollbar")
        self.thumb_scroll.grid(row=0, column=1, sticky="ns")
        self.thumb_canvas.configure(yscrollcommand=self.thumb_scroll.set)
        self.thumb_inner = tk.Frame(self.thumb_canvas, bg="#f8fcfa")
        self.thumb_window_id = self.thumb_canvas.create_window((0, 0), window=self.thumb_inner, anchor="nw")
        self.thumb_inner.bind("<Configure>", self.on_thumb_inner_configure)
        self.thumb_canvas.bind("<Configure>", self.on_thumb_canvas_configure)
        self.thumb_empty = tk.Label(self.thumb_inner, text="Belum ada halaman\n\nBuka PDF atau dokumen Word\nuntuk melihat halaman di sini.", bg="#f8fcfa", fg="#94b8a7", font=("Segoe UI", 9), justify=tk.CENTER)
        self.thumb_empty.pack(pady=45)

        workspace = tk.Frame(middle, bg="#e9f4ef", highlightthickness=1, highlightbackground="#e2f0e9")
        workspace.pack(side=tk.LEFT, fill=tk.BOTH, expand=True)
        document_bar = tk.Frame(workspace, bg="#ffffff", height=48)
        document_bar.pack(fill=tk.X)
        document_bar.pack_propagate(False)
        self.document_title_var = tk.StringVar(value="Ruang kerja dokumen")
        tk.Label(document_bar, textvariable=self.document_title_var, bg="#ffffff", fg="#335545", font=("Segoe UI", 9, "bold"), anchor="w", width=28).pack(side=tk.LEFT, padx=16, fill=tk.X, expand=True)
        zoom = tk.Frame(document_bar, bg="#ffffff")
        zoom.pack(side=tk.RIGHT, padx=10, pady=6)
        for text, command in (("−", lambda: self.adjust_zoom(-0.1)), ("+", lambda: self.adjust_zoom(0.1)), ("Sesuaikan", self.fit_page)):
            ttk.Button(zoom, text=text, command=command, width=9 if text == "Sesuaikan" else 3, style="RibbonAction.TButton").pack(side=tk.RIGHT, padx=2)
        self.zoom_top_var = tk.StringVar(value=f"{self.zoom:.2f}x")
        tk.Label(zoom, textvariable=self.zoom_top_var, bg="#ffffff", fg="#648b78", width=6, font=("Segoe UI", 9)).pack(side=tk.RIGHT, padx=4)
        pager = tk.Frame(document_bar, bg="#ffffff")
        pager.pack(side=tk.RIGHT, padx=6)
        ttk.Button(pager, text="‹", width=3, command=self.prev_page, style="RibbonAction.TButton").pack(side=tk.LEFT)
        self.page_label = tk.Label(pager, text="0 / 0", bg="#ffffff", fg="#648b78", font=("Segoe UI", 9))
        self.page_label.pack(side=tk.LEFT, padx=10)
        ttk.Button(pager, text="›", width=3, command=self.next_page, style="RibbonAction.TButton").pack(side=tk.LEFT)
        tk.Frame(workspace, bg="#e2f0e9", height=1).pack(fill=tk.X)
        self.canvas = tk.Canvas(workspace, bg="#e9f4ef", highlightthickness=0)
        self.canvas.pack(fill=tk.BOTH, expand=True)
        self._build_empty_state()
        self._set_create_mode(False)

    def open_simanis_data(self):
        if getattr(sys, "frozen", False):
            subprocess.Popen([sys.executable, "--simanis-data"], creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        else:
            subprocess.Popen([sys.executable, os.path.join(app_base_dir(), "simanis_data.py")], creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))

    def _build_empty_state(self):
        self.empty_state = tk.Frame(self.canvas, bg="#e9f4ef")
        self.empty_state.place(relx=0.5, rely=0.48, anchor="center")
        badge = tk.Canvas(self.empty_state, width=86, height=88, bg="#e9f4ef", highlightthickness=0)
        badge.pack(pady=(0, 12))
        self._draw_round_shape(badge, 4, 4, 82, 82, 20, "#d7e9e0", ("badge",))
        badge.create_line(28, 59, 28, 24, 49, 24, 60, 35, 60, 59, 28, 59, fill="#16845b", width=2)
        badge.create_line(49, 24, 49, 35, 60, 35, fill="#16845b", width=2)
        for y in (42, 49):
            badge.create_line(36, y, 52, y, fill="#16845b", width=2)
        tk.Label(self.empty_state, text="Dokumen rapi. Kerja lebih mudah.", bg="#e9f4ef", fg="#244b38", font=("Segoe UI", 21, "bold")).pack()
        tk.Label(self.empty_state, text="Buka akta Anda, buat garis otomatis, lalu sempurnakan\nhasilnya dalam satu ruang kerja.", bg="#e9f4ef", fg="#739685", font=("Segoe UI", 11), justify=tk.CENTER).pack(pady=(8, 18))
        buttons = tk.Frame(self.empty_state, bg="#e9f4ef")
        buttons.pack()
        self._make_button(buttons, "Buka PDF", self.open_pdf_dialog, width=17, accent=True).pack(side=tk.LEFT, padx=5)
        self._make_button(buttons, "Buka Word", self.open_docx_dialog, width=17).pack(side=tk.LEFT, padx=5)
        tk.Label(self.empty_state, text="PDF, DOC, DOCX    ·    Ctrl+O untuk membuka PDF", bg="#e9f4ef", fg="#84a394", font=("Segoe UI", 9)).pack(pady=(16, 18))
        tk.Frame(self.empty_state, bg="#d8e9e1", height=1, width=420).pack()
        tk.Label(self.empty_state, text="01  Buka dokumen     →     02  Buat garis     →     03  Simpan PDF", bg="#e9f4ef", fg="#739685", font=("Segoe UI", 9)).pack(pady=(18, 0))

    def _create_ribbon_tab(self, parent, tab_name, title):
        btn = tk.Label(parent, text=title, bg="#ffffff", fg="#739685", font=("Segoe UI", 10, "bold"),
                       padx=14, pady=6, cursor="hand2", bd=0, highlightthickness=0, takefocus=True)
        btn.pack(side=tk.LEFT, padx=(0, 8), fill=tk.Y)
        def select(_event):
            self._set_active_ribbon_tab(tab_name)
            return "break"
        btn.bind("<Button-1>", select)
        btn.bind("<Return>", select)
        btn.bind("<space>", select)
        btn.bind("<FocusIn>", lambda _e: btn.configure(relief="solid", bd=1))
        btn.bind("<FocusOut>", lambda _e: btn.configure(relief="flat", bd=0))
        self.ribbon_tab_buttons[tab_name] = btn
        return btn

    def _make_tab_action_button(self, parent, text, command):
        return ttk.Button(
            parent,
            text=text,
            command=command,
            style="RibbonAction.TButton",
            cursor="hand2",
        )

    def _set_active_ribbon_tab(self, tab_name):
        if tab_name not in self.ribbon_tab_frames:
            return

        for name, frame in self.ribbon_tab_frames.items():
            if name == tab_name:
                frame.pack(side=tk.LEFT, anchor="w")
            else:
                frame.pack_forget()

        for name, btn in self.ribbon_tab_buttons.items():
            active = name == tab_name
            btn.configure(
                bg="#edf8f3" if active else "#ffffff",
                fg="#116b49" if active else "#739685",
            )
        self.active_ribbon_tab = tab_name

    def _make_button(self, parent, text, command, width=12, accent=False):
        style_name = "DialogAccent.TButton" if accent else "Dialog.TButton"
        return ttk.Button(
            parent,
            text=text,
            command=command,
            width=width,
            style=style_name,
            cursor="hand2",
        )

    def _draw_round_shape(self, canvas, x1, y1, x2, y2, radius, fill, tags):
        radius = int(max(0, min(radius, (x2 - x1) // 2, (y2 - y1) // 2)))
        if radius <= 0:
            canvas.create_rectangle(x1, y1, x2, y2, fill=fill, outline=fill, tags=tags)
            return

        canvas.create_rectangle(x1 + radius, y1, x2 - radius, y2, fill=fill, outline=fill, tags=tags)
        canvas.create_rectangle(x1, y1 + radius, x2, y2 - radius, fill=fill, outline=fill, tags=tags)
        canvas.create_arc(
            x1,
            y1,
            x1 + 2 * radius,
            y1 + 2 * radius,
            start=90,
            extent=90,
            style=tk.PIESLICE,
            fill=fill,
            outline=fill,
            tags=tags,
        )
        canvas.create_arc(
            x2 - 2 * radius,
            y1,
            x2,
            y1 + 2 * radius,
            start=0,
            extent=90,
            style=tk.PIESLICE,
            fill=fill,
            outline=fill,
            tags=tags,
        )
        canvas.create_arc(
            x2 - 2 * radius,
            y2 - 2 * radius,
            x2,
            y2,
            start=270,
            extent=90,
            style=tk.PIESLICE,
            fill=fill,
            outline=fill,
            tags=tags,
        )
        canvas.create_arc(
            x1,
            y2 - 2 * radius,
            x1 + 2 * radius,
            y2,
            start=180,
            extent=90,
            style=tk.PIESLICE,
            fill=fill,
            outline=fill,
            tags=tags,
        )

    def _make_ribbon_group(self, parent, title, add_separator=True):
        outer = tk.Frame(parent, bg="#ffffff", padx=3)
        outer.pack(side=tk.LEFT, fill=tk.Y)
        tools = tk.Frame(outer, bg="#ffffff")
        tools.pack(side=tk.TOP)
        if add_separator:
            tk.Frame(parent, bg="#edf5f1", width=1).pack(side=tk.LEFT, fill=tk.Y, pady=4, padx=10)
        return tools

    def _make_ribbon_tool(
        self,
        parent,
        icon,
        text,
        command,
        accent=False,
        width=8,
        icon_fg="#52826b",
        compact=False,
        bg_color=None,
        hover_bg_color=None,
        text_fg="#0f2a1d",
    ):
        bg = str(bg_color) if bg_color else ("#e6f5ee" if accent else "#ffffff")
        hover_bg = str(hover_bg_color) if hover_bg_color else ("#d5eee2" if accent else "#e7f7ef")
        from tkinter import font as tkfont
        text_font = ("Segoe UI", 9)
        label_width = tkfont.Font(self.root, font=text_font).measure(text)
        card_width = max(96, int(width) * 10 + 24, label_width + 48)
        card_height = 36

        parent_bg = str(parent.cget("bg") or "#eefbf5")
        card = tk.Canvas(
            parent,
            width=card_width,
            height=card_height,
            bg=parent_bg,
            highlightthickness=0,
            bd=0,
            cursor="hand2",
            takefocus=True,
        )
        radius = 9 if compact else 10
        self._draw_round_shape(card, 1, 1, card_width - 1, card_height - 1, radius, "#e2f0e9", ("outer",))
        self._draw_round_shape(card, 2, 2, card_width - 2, card_height - 2, max(2, radius - 1), bg, ("inner",))
        icon_item = self._draw_tool_icon(card, icon, 18, 18, icon_fg)
        text_item = card.create_text(
            38,
            18,
            text=text,
            fill=str(text_fg or "#0f2a1d"),
            font=text_font,
            anchor="w",
            tags=("content",),
        )

        def _set_bg(color):
            card.itemconfigure("inner", fill=color, outline=color)

        def _on_enter(_event):
            _set_bg(hover_bg)

        def _on_leave(_event):
            _set_bg(bg)

        card.bind("<Enter>", _on_enter)
        card.bind("<Leave>", _on_leave)
        def invoke(_event):
            card.focus_set()
            command()
            return "break"

        card.bind("<Button-1>", invoke)
        card.bind("<Return>", invoke)
        card.bind("<space>", invoke)
        card.bind("<FocusIn>", lambda _e: card.itemconfigure("outer", fill="#16845b", outline="#16845b"))
        card.bind("<FocusOut>", lambda _e: card.itemconfigure("outer", fill="#e2f0e9", outline="#e2f0e9"))
        card._tool_icon_item = icon_item
        card._tool_text_item = text_item

        return card

    def _draw_tool_icon(self, canvas, name, x, y, color):
        """Draw crisp, consistent icons without platform-dependent emoji fonts."""
        def line(*points):
            coords = [v + (x if i % 2 == 0 else y) for i, v in enumerate(points)]
            return canvas.create_line(*coords, fill=color, width=1.7, capstyle=tk.ROUND, joinstyle=tk.ROUND, tags=("content",))

        def rect(a, b, c, d):
            return canvas.create_rectangle(x+a, y+b, x+c, y+d, outline=color, width=1.6, tags=("content",))

        if name in ("file", "word", "insert"):
            item = line(-8, 11, -8, -11, 3, -11, 9, -5, 9, 11, -8, 11)
            line(3, -11, 3, -5, 9, -5)
            if name == "word":
                line(-4, 0, -2, 6, 0, 2, 2, 6, 4, 0)
            else:
                line(-4, 1, 4, 1)
                line(-4, 5, 4, 5)
            return item
        if name == "spark":
            item = line(0, -11, 3, -3, 11, 0, 3, 3, 0, 11, -3, 3, -11, 0, -3, -3, 0, -11)
            line(10, -12, 10, -7)
            line(8, -10, 12, -10)
            return item
        if name == "compress":
            item = line(-10, -10, -3, -3, -8, -3)
            line(-3, -3, -3, -8)
            line(10, 10, 3, 3, 8, 3)
            line(3, 3, 3, 8)
            line(10, -10, 3, -3, 3, -8)
            line(3, -3, 8, -3)
            line(-10, 10, -3, 3, -8, 3)
            line(-3, 3, -3, 8)
            return item
        if name == "book":
            item = line(0, 10, -9, 7, -9, -10, 0, -7, 9, -10, 9, 7, 0, 10, 0, -7)
            return item
        if name in ("merge", "split"):
            item = rect(-10, -10, 3, 5)
            rect(-3, -5, 10, 10)
            if name == "split":
                line(0, -12, 0, 12)
            return item
        if name == "delete":
            item = line(-7, -5, -6, 10, 6, 10, 7, -5)
            line(-10, -7, 10, -7)
            line(-3, -7, -3, -11, 3, -11, 3, -7)
            line(-2, -2, -2, 6)
            line(2, -2, 2, 6)
            return item
        if name == "angle":
            item = line(-10, -10, -10, 9, 10, 9, -10, -10)
            return item
        if name == "leaders":
            item = line(-10, -6, -2, -6)
            line(-10, 1, -5, 1)
            line(-10, 8, 0, 8)
            for yy, xx in ((-6, 1), (1, -2), (8, 3)):
                for start in range(xx, 10, 4):
                    line(start, yy, start + 2, yy)
            return item
        if name == "add":
            item = line(-10, 0, 10, 0)
            line(0, -10, 0, 10)
            return item
        if name == "key":
            item = canvas.create_oval(x-11, y-10, x+1, y+2, outline=color, width=1.7)
            line(-1, 0, 10, 11, 13, 8)
            line(6, 7, 9, 4)
            return item
        return canvas.create_text(x, y, text="?" if name == "help" else name, fill=color,
                                  font=("Segoe UI", 17), tags=("content",))

    def _set_ribbon_tool_text(self, tool_canvas, text):
        if tool_canvas is None:
            return
        text_item = getattr(tool_canvas, "_tool_text_item", None)
        if text_item is None:
            return
        try:
            tool_canvas.itemconfigure(text_item, text=str(text or ""))
        except Exception:
            pass

    def _refresh_booklet_button_text(self):
        label = "Unbooklet" if bool(getattr(self, "booklet_mode_active", False)) else "Buat Booklet"
        self._set_ribbon_tool_text(getattr(self, "booklet_button", None), label)

    def _build_booklet_layout_pairs(self, total_pages):
        total = max(0, int(total_pages))
        if total <= 0:
            return []
        padded = ((total + 3) // 4) * 4
        sheets = padded // 4
        pairs = []
        for i in range(sheets):
            front_left = padded - 1 - (2 * i)
            front_right = 2 * i
            back_left = (2 * i) + 1
            back_right = padded - 2 - (2 * i)
            pairs.append((front_left, front_right))
            pairs.append((back_left, back_right))
        return pairs

    def _project_source_segments_to_booklet(self, source_segments_by_page):
        if not isinstance(source_segments_by_page, dict):
            return None
        pairs = list(getattr(self, "booklet_layout_pairs", []) or [])
        page_w = float(getattr(self, "booklet_source_page_w", 0.0) or 0.0)
        total_pages = int(getattr(self, "booklet_source_page_count", 0) or 0)
        if not pairs or page_w <= 0.0 or total_pages <= 0:
            return None

        projected = {}
        for booklet_page_no, pair in enumerate(pairs):
            if not isinstance(pair, (list, tuple)) or len(pair) != 2:
                continue
            left_idx = int(pair[0])
            right_idx = int(pair[1])
            rows = []
            for source_idx, side in ((left_idx, "left"), (right_idx, "right")):
                if source_idx < 0 or source_idx >= total_pages:
                    continue
                segs = source_segments_by_page.get(source_idx, [])
                if not isinstance(segs, list):
                    continue
                for seg in segs:
                    row = self._normalize_segment(seg, booklet_page_no)
                    x = float(row.get("x", 0.0))
                    if side == "right":
                        x += page_w
                    row["x"] = x
                    row["page"] = int(booklet_page_no)
                    rows.append(row)
            if rows:
                projected[int(booklet_page_no)] = rows
        return projected

    def _project_booklet_segments_to_source(self, booklet_segments_by_page):
        if not isinstance(booklet_segments_by_page, dict):
            return None
        pairs = list(getattr(self, "booklet_layout_pairs", []) or [])
        page_w = float(getattr(self, "booklet_source_page_w", 0.0) or 0.0)
        page_h = float(getattr(self, "booklet_source_page_h", 0.0) or 0.0)
        total_pages = int(getattr(self, "booklet_source_page_count", 0) or 0)
        if not pairs or page_w <= 0.0 or total_pages <= 0:
            return None
        if page_h <= 0.0:
            page_h = 1e9

        source = {}
        for booklet_page_no, segs in booklet_segments_by_page.items():
            try:
                bpage = int(booklet_page_no)
            except Exception:
                continue
            if bpage < 0 or bpage >= len(pairs):
                continue
            pair = pairs[bpage]
            left_idx = int(pair[0])
            right_idx = int(pair[1])
            if not isinstance(segs, list):
                continue

            for seg in segs:
                row_in = self._normalize_segment(seg, bpage)
                x = float(row_in.get("x", 0.0))
                if x < page_w:
                    source_idx = left_idx
                    source_x = x
                else:
                    source_idx = right_idx
                    source_x = x - page_w
                if source_idx < 0 or source_idx >= total_pages:
                    continue

                row = self._normalize_segment(row_in, source_idx)
                row["page"] = int(source_idx)
                row["x"] = max(0.0, min(page_w, float(source_x)))
                y0 = max(0.0, min(page_h, float(row.get("y0", 0.0))))
                y1 = max(0.0, min(page_h, float(row.get("y1", 0.0))))
                if y1 <= y0 + 1.0:
                    y1 = min(page_h, y0 + 2.0)
                row["y0"] = y0
                row["y1"] = y1
                source.setdefault(source_idx, []).append(row)

        return source

    def _make_color_toggle(
        self,
        parent,
        value,
        icon="■",
        icon_fg="#11271c",
        text_fg="#0f2a1d",
        selected_bg="#e2f0e9",
        selected_border="#94b8a7",
        hover_bg="#f1f9f5",
    ):
        base_bg = "#f8fcfa"
        base_border = "#cbe1d6"
        parent_bg = str(parent.cget("bg") or "#e5ebe8")
        card_width = 78
        card_height = 36

        card = tk.Canvas(
            parent,
            width=card_width,
            height=card_height,
            bg=parent_bg,
            highlightthickness=0,
            bd=0,
            cursor="hand2",
            takefocus=True,
        )

        self._draw_round_shape(card, 1, 1, card_width - 1, card_height - 1, 10, base_border, ("outer",))
        self._draw_round_shape(card, 2, 2, card_width - 2, card_height - 2, 9, base_bg, ("inner",))
        icon_id = card.create_text(
            15,
            18,
            text=icon,
            fill=icon_fg,
            font=("Segoe UI Symbol", 11, "bold"),
            anchor="center",
            tags=("icon",),
        )
        text_id = card.create_text(
            30,
            18,
            text=value,
            fill=text_fg,
            font=("Segoe UI", 9, "bold"),
            anchor="w",
            tags=("text",),
        )

        self.color_toggle_widgets[value] = {
            "canvas": card,
            "icon_id": icon_id,
            "text_id": text_id,
            "base_bg": base_bg,
            "base_border": base_border,
            "selected_bg": selected_bg,
            "selected_border": selected_border,
            "hover_bg": hover_bg,
            "icon_fg": icon_fg,
            "text_fg": text_fg,
        }

        def _on_enter(_event, selected_value=value):
            if self.line_color_var.get() == selected_value:
                return
            self._set_color_toggle_state(selected_value, hovered=True)

        def _on_leave(_event):
            self._refresh_color_toggle_styles()

        def _on_click(_event, selected_value=value):
            card.focus_set()
            self.line_color_var.set(selected_value)
            self.on_color_change()
            return "break"

        card.bind("<Enter>", _on_enter)
        card.bind("<Leave>", _on_leave)
        card.bind("<Button-1>", _on_click)
        card.bind("<Return>", _on_click)
        card.bind("<space>", _on_click)
        card.bind("<FocusIn>", lambda _e: card.itemconfigure("outer", fill="#16845b", outline="#16845b"))
        card.bind("<FocusOut>", lambda _e: self._refresh_color_toggle_styles())
        return card

    def _set_color_toggle_state(self, value, hovered=False):
        info = self.color_toggle_widgets.get(value)
        if not info:
            return

        selected = self.line_color_var.get() == value
        if selected:
            inner_bg = info["selected_bg"]
            outer_bg = info["selected_border"]
            icon_fill = info["icon_fg"]
            text_fill = info["text_fg"]
        else:
            inner_bg = info["hover_bg"] if hovered else info["base_bg"]
            outer_bg = info["base_border"]
            icon_fill = info["icon_fg"]
            text_fill = info["text_fg"]

        canvas = info["canvas"]
        canvas.itemconfigure("outer", fill=outer_bg, outline=outer_bg)
        canvas.itemconfigure("inner", fill=inner_bg, outline=inner_bg)
        canvas.itemconfigure(info["icon_id"], fill=icon_fill)
        canvas.itemconfigure(info["text_id"], fill=text_fill)

    def _refresh_color_toggle_styles(self):
        for value in list(self.color_toggle_widgets.keys()):
            self._set_color_toggle_state(value, hovered=False)

    def _draw_header_gradient(self, event=None):
        c = self.header_canvas
        w = max(1, c.winfo_width())
        c.delete("all")
        if self.header_logo_image is None:
            icon_path = resolve_asset_path(os.path.join("assets", "app_icon.png"))
            if icon_path:
                self.header_logo_image = tk.PhotoImage(file=icon_path).subsample(16, 16)
        if self.header_logo_image is not None:
            c.create_image(16, 7, image=self.header_logo_image, anchor="nw")
        c.create_text(58, 22, text="SIMANIS", fill="#ffffff", font=("Segoe UI", 14, "bold"), anchor="w")
        c.create_text(163, 23, text="Sistem Garis Akta Notaris Digital", fill="#a6cbb9", font=("Segoe UI", 9), anchor="w")
        c.create_text(w - 18, 22, text=f"SIMANIS   /   v{APP_VERSION}", fill="#94bda9", font=("Segoe UI", 9), anchor="e")

    @staticmethod
    def _hex_to_rgb(hex_color):
        value = str(hex_color or "").strip().lstrip("#")
        if len(value) != 6:
            return 0, 0, 0
        try:
            return int(value[0:2], 16), int(value[2:4], 16), int(value[4:6], 16)
        except Exception:
            return 0, 0, 0

    @staticmethod
    def _rgb_to_hex(rgb):
        r, g, b = rgb
        return f"#{max(0, min(255, int(r))):02x}{max(0, min(255, int(g))):02x}{max(0, min(255, int(b))):02x}"

    def _lerp_color(self, start_hex, end_hex, t):
        t = max(0.0, min(1.0, float(t)))
        s = self._hex_to_rgb(start_hex)
        e = self._hex_to_rgb(end_hex)
        r = s[0] + ((e[0] - s[0]) * t)
        g = s[1] + ((e[1] - s[1]) * t)
        b = s[2] + ((e[2] - s[2]) * t)
        return self._rgb_to_hex((r, g, b))

    def _build_header_gradient_image(self, width, height):
        img = tk.PhotoImage(width=width, height=height)

        # Diagonal green gradient: brighter on top-left, darker on bottom-right.
        start_color = "#1ec876"
        end_color = "#0a673a"
        denom_x = max(1, width - 1)
        denom_y = max(1, height - 1)

        for y in range(height):
            y_ratio = float(y) / float(denom_y)
            row = []
            for x in range(width):
                x_ratio = float(x) / float(denom_x)
                ratio = (x_ratio * 0.78) + (y_ratio * 0.22)
                row.append(self._lerp_color(start_color, end_color, ratio))
            img.put("{" + " ".join(row) + "}", to=(0, y))

        return img

    def _draw_zoom_bubble(self):
        if not hasattr(self, "zoom_canvas"):
            return

        c = self.zoom_canvas
        c.delete("all")

        # pill background
        c.create_oval(12, 8, 80, 76, fill="#ffffff", outline="#cbe1d6")
        c.create_rectangle(12, 42, 80, 172, fill="#ffffff", outline="#cbe1d6")
        c.create_oval(12, 138, 80, 206, fill="#ffffff", outline="#cbe1d6")

        c.create_text(46, 28, text="Zoom", fill="#335545", font=("Segoe UI", 9, "bold"))
        c.create_text(46, 106, text=f"{self.zoom:.2f}x", fill="#0f2a1d", font=("Segoe UI", 10, "bold"))

        c.create_oval(24, 52, 68, 96, fill="#1dd87e", outline="", tags=("zoom_plus",))
        c.create_text(46, 74, text="+", fill="#ffffff", font=("Segoe UI", 16, "bold"), tags=("zoom_plus",))

        c.create_oval(24, 118, 68, 162, fill="#1dd87e", outline="", tags=("zoom_minus",))
        c.create_text(46, 140, text="-", fill="#ffffff", font=("Segoe UI", 16, "bold"), tags=("zoom_minus",))

        c.tag_bind("zoom_plus", "<Button-1>", lambda _e: self.adjust_zoom(0.1))
        c.tag_bind("zoom_minus", "<Button-1>", lambda _e: self.adjust_zoom(-0.1))

    def _bind_events(self):
        self.canvas.bind("<Button-1>", self.on_left_down)
        self.canvas.bind("<B1-Motion>", self.on_left_drag)
        self.canvas.bind("<ButtonRelease-1>", self.on_left_up)
        self.canvas.bind("<Button-3>", self.on_right_click_add)
        self.canvas.bind("<Configure>", self.on_canvas_configure)

        self.canvas.bind("<MouseWheel>", self.on_mousewheel)
        self.canvas.bind("<Shift-MouseWheel>", self.on_shift_mousewheel)
        self.canvas.bind("<Button-4>", self.on_wheel_up_linux)
        self.canvas.bind("<Button-5>", self.on_wheel_down_linux)
        if hasattr(self, "thumb_canvas"):
            self.thumb_canvas.bind("<MouseWheel>", self.on_thumb_mousewheel)
            self.thumb_canvas.bind("<Button-4>", self.on_thumb_wheel_up_linux)
            self.thumb_canvas.bind("<Button-5>", self.on_thumb_wheel_down_linux)
        if hasattr(self, "thumb_inner"):
            self.thumb_inner.bind("<MouseWheel>", self.on_thumb_mousewheel)
            self.thumb_inner.bind("<Button-4>", self.on_thumb_wheel_up_linux)
            self.thumb_inner.bind("<Button-5>", self.on_thumb_wheel_down_linux)

        self.root.bind("<Delete>", lambda e: self.delete_selected())
        self.root.bind("<Left>", lambda e: self.nudge_selected(-1.0, 0.0))
        self.root.bind("<Right>", lambda e: self.nudge_selected(1.0, 0.0))
        self.root.bind("<Up>", lambda e: self.nudge_selected(0.0, -1.0))
        self.root.bind("<Down>", lambda e: self.nudge_selected(0.0, 1.0))
        self.root.bind("<Shift-Left>", lambda e: self.nudge_selected(-3.0, 0.0))
        self.root.bind("<Shift-Right>", lambda e: self.nudge_selected(3.0, 0.0))
        self.root.bind("<Shift-Up>", lambda e: self.nudge_selected(0.0, -3.0))
        self.root.bind("<Shift-Down>", lambda e: self.nudge_selected(0.0, 3.0))
        self.root.bind("<Prior>", lambda e: self.prev_page())  # PageUp
        self.root.bind("<Next>", lambda e: self.next_page())   # PageDown
        self.root.bind("<Control-o>", lambda e: self.open_pdf_dialog())
        self.root.bind("<Control-s>", lambda e: self.export_pdf())
        self.root.bind("<Control-S>", lambda e: self.save_project())
        self.root.bind("<Control-0>", lambda e: self.fit_page())
        self.root.bind("<Control-e>", lambda e: self.export_pdf())
        self.root.bind("<Control-p>", lambda e: self.print_document())
        self.root.bind("<Control-z>", lambda e: self.undo_action())
        self.root.bind("<Control-y>", lambda e: self.redo_action())
        self.root.bind("<Control-Shift-Z>", lambda e: self.redo_action())
        self.root.bind("<Control-g>", lambda e: self.trigger_auto_generate_lines())
        self.root.bind("<Control-Shift-C>", lambda e: self.open_compress_pdf_dialog())
        self.root.bind("<Control-Shift-c>", lambda e: self.open_compress_pdf_dialog())
        self.root.bind("<F1>", lambda e: self.show_help_terms())
        self.root.protocol("WM_DELETE_WINDOW", self.on_close)

    def _snapshot_segments(self):
        snapshot = {}
        for page_no, segs in self.segments_by_page.items():
            p = int(page_no)
            snapshot[p] = [self._normalize_segment(seg, p) for seg in segs]
        return snapshot

    def _snapshot_editor_state(self):
        return {
            "segments_by_page": self._snapshot_segments(),
            "end_fill_settings": elf.normalize_settings(getattr(self, "end_fill_settings", None)),
            "page_index": int(self.page_index),
            "selected_idx": int(self.selected_idx) if isinstance(self.selected_idx, int) else None,
        }

    def _push_undo_snapshot(self, snapshot):
        if self.doc is None:
            return
        if not isinstance(snapshot, dict):
            return
        self.undo_stack.append(snapshot)
        if len(self.undo_stack) > self.max_history:
            self.undo_stack.pop(0)
        self.redo_stack.clear()

    def _push_undo_state(self):
        self._push_undo_snapshot(self._snapshot_editor_state())

    def _restore_editor_state(self, snapshot):
        if self.doc is None:
            return False
        if not isinstance(snapshot, dict):
            return False

        restored = {}
        raw = snapshot.get("segments_by_page", {})
        if isinstance(raw, dict):
            for key, segs in raw.items():
                try:
                    p = int(key)
                except Exception:
                    continue
                if not isinstance(segs, list):
                    continue
                restored[p] = [self._normalize_segment(seg, p) for seg in segs]

        self.segments_by_page = restored
        self.end_fill_settings = elf.normalize_settings(snapshot.get("end_fill_settings"))

        try:
            page_index = int(snapshot.get("page_index", self.page_index))
        except Exception:
            page_index = int(self.page_index)
        max_page = max(0, int(self.doc.page_count) - 1)
        self.page_index = max(0, min(page_index, max_page))

        self.selected_idx = None
        selected_idx = snapshot.get("selected_idx")
        if isinstance(selected_idx, int):
            segs = self._get_page_segments(self.page_index)
            if 0 <= selected_idx < len(segs):
                self.selected_idx = selected_idx

        self.drag_mode = None
        self._set_create_mode(False)
        self._drag_start_snapshot = None
        self._drag_changed = False
        self.render_page()
        self._refresh_all_thumbnail_overlays()
        return True

    def _reset_history(self):
        self.undo_stack = []
        self.redo_stack = []
        self._drag_start_snapshot = None
        self._drag_changed = False

    def undo_action(self):
        if self.doc is None:
            return
        if not self.undo_stack:
            self.set_status("Undo kosong.")
            return

        current_state = self._snapshot_editor_state()
        previous_state = self.undo_stack.pop()
        if not self._restore_editor_state(previous_state):
            self.set_status("Undo gagal.")
            return

        self.redo_stack.append(current_state)
        if len(self.redo_stack) > self.max_history:
            self.redo_stack.pop(0)
        self.set_status("Undo berhasil.")

    def redo_action(self):
        if self.doc is None:
            return
        if not self.redo_stack:
            self.set_status("Redo kosong.")
            return

        current_state = self._snapshot_editor_state()
        next_state = self.redo_stack.pop()
        if not self._restore_editor_state(next_state):
            self.set_status("Redo gagal.")
            return

        self.undo_stack.append(current_state)
        if len(self.undo_stack) > self.max_history:
            self.undo_stack.pop(0)
        self.set_status("Redo berhasil.")

    def _get_loading_logo_image(self):
        cached = getattr(self, "loading_logo_image", None)
        if cached is not None:
            return cached

        try:
            logo_path = resolve_asset_path(os.path.join("assets", "app_icon.png"))
            if not logo_path:
                return None
            src = tk.PhotoImage(file=logo_path)
            w = max(1, int(src.width()))
            h = max(1, int(src.height()))
            max_side = max(w, h)
            # Target logo loading sekitar 34px agar proporsional.
            factor = max(1, int(round(max_side / 34.0)))
            logo = src.subsample(factor, factor) if factor > 1 else src
            self.loading_logo_source_image = src
            self.loading_logo_image = logo
            return logo
        except Exception:
            return None

    def _show_loading_dialog(self, message="Memuat dokumen...", detail="Mohon tunggu sebentar"):
        self._hide_loading_dialog()
        self.root.update_idletasks()

        dlg = tk.Toplevel(self.root)
        dlg.overrideredirect(True)
        dlg.transient(self.root)
        dlg.configure(bg="#0f2a1d")

        box = tk.Frame(
            dlg,
            bg="#ffffff",
            highlightthickness=1,
            highlightbackground="#cbe1d6",
            bd=0,
            padx=18,
            pady=14,
        )
        box.pack(fill=tk.BOTH, expand=True)

        logo = self._get_loading_logo_image()
        if logo is not None:
            tk.Label(
                box,
                image=logo,
                bg="#ffffff",
                bd=0,
                highlightthickness=0,
            ).pack(pady=(0, 6))

        tk.Label(
            box,
            text="Loading",
            bg="#ffffff",
            fg="#0f2a1d",
            font=("Segoe UI", 11, "bold"),
            anchor="center",
        ).pack(pady=(2, 4))

        self.loading_message_var = tk.StringVar(value=str(message or "Memuat dokumen..."))
        tk.Label(
            box,
            textvariable=self.loading_message_var,
            bg="#ffffff",
            fg="#1e3b2d",
            font=("Segoe UI", 10, "bold"),
            anchor="center",
            justify=tk.CENTER,
        ).pack(pady=(0, 2))

        self.loading_detail_var = tk.StringVar(value=str(detail or "Mohon tunggu sebentar"))
        tk.Label(
            box,
            textvariable=self.loading_detail_var,
            bg="#ffffff",
            fg="#648b78",
            font=("Segoe UI", 9),
            anchor="center",
            justify=tk.CENTER,
        ).pack(pady=(0, 4))

        w = 360
        h = 130
        rx = int(self.root.winfo_rootx())
        ry = int(self.root.winfo_rooty())
        rw = max(1, int(self.root.winfo_width()))
        rh = max(1, int(self.root.winfo_height()))
        x = rx + max(0, (rw - w) // 2)
        y = ry + max(0, (rh - h) // 2)
        dlg.geometry(f"{w}x{h}+{x}+{y}")

        try:
            dlg.attributes("-topmost", True)
        except Exception:
            pass

        dlg.lift(self.root)
        dlg.update_idletasks()
        self.loading_dialog = dlg

    def _hide_loading_dialog(self):
        dlg = getattr(self, "loading_dialog", None)
        self.loading_dialog = None
        self.loading_message_var = None
        self.loading_detail_var = None
        if dlg is None:
            return
        try:
            if dlg.winfo_exists():
                dlg.destroy()
        except Exception:
            pass

    def _update_loading_dialog(self, message=None, detail=None):
        if getattr(self, "loading_dialog", None) is None:
            return
        try:
            if message is not None and self.loading_message_var is not None:
                self.loading_message_var.set(str(message))
            if detail is not None and self.loading_detail_var is not None:
                self.loading_detail_var.set(str(detail))
            self.root.update()
        except Exception:
            pass

    def _run_with_loading(self, message, func, *args, **kwargs):
        had_dialog = getattr(self, "loading_dialog", None) is not None
        if had_dialog:
            self._update_loading_dialog(message=message)
        else:
            self._show_loading_dialog(message=message)
        try:
            self.root.update()
            return func(*args, **kwargs)
        finally:
            if not had_dialog:
                self._hide_loading_dialog()
                self.root.update_idletasks()

    def _set_create_mode(self, enabled):
        self.create_mode = bool(enabled)
        if hasattr(self, "canvas"):
            try:
                self.canvas.configure(cursor="crosshair" if self.create_mode else "arrow")
            except Exception:
                pass

    def set_status(self, text):
        msg = str(text or "")
        if hasattr(self, "status_var"):
            self.status_var.set(msg)
        if self.loading_detail_var is not None:
            self.loading_detail_var.set(msg)
        self.root.update_idletasks()

    def on_thumb_inner_configure(self, _event=None):
        if hasattr(self, "thumb_canvas"):
            self.thumb_canvas.configure(scrollregion=self.thumb_canvas.bbox("all"))

    def on_thumb_canvas_configure(self, event):
        if self.thumb_window_id is not None:
            self.thumb_canvas.itemconfigure(self.thumb_window_id, width=max(1, int(event.width)))

    def on_thumb_mousewheel(self, event):
        if not hasattr(self, "thumb_canvas"):
            return "break"
        step = -2 if event.delta > 0 else 2
        self.thumb_canvas.yview_scroll(step, "units")
        return "break"

    def on_thumb_wheel_up_linux(self, _event):
        if hasattr(self, "thumb_canvas"):
            self.thumb_canvas.yview_scroll(-2, "units")
        return "break"

    def on_thumb_wheel_down_linux(self, _event):
        if hasattr(self, "thumb_canvas"):
            self.thumb_canvas.yview_scroll(2, "units")
        return "break"

    def clear_page_thumbnails(self):
        self.thumb_images = []
        self.thumb_items = []
        if not hasattr(self, "thumb_inner"):
            return
        for child in self.thumb_inner.winfo_children():
            child.destroy()
        self.on_thumb_inner_configure()
        if hasattr(self, "thumb_canvas"):
            self.thumb_canvas.yview_moveto(0.0)

    def _scroll_thumb_to_active(self):
        if not self.thumb_items or not hasattr(self, "thumb_canvas"):
            return
        idx = max(0, min(self.page_index, len(self.thumb_items) - 1))
        frame = self.thumb_items[idx]["frame"]
        self.root.update_idletasks()
        y0 = frame.winfo_y()
        y1 = y0 + frame.winfo_height()

        canvas_top = self.thumb_canvas.canvasy(0)
        canvas_bottom = canvas_top + self.thumb_canvas.winfo_height()
        total_h = max(1, int(self.thumb_inner.winfo_height()))
        canvas_h = max(1, int(self.thumb_canvas.winfo_height()))

        if y0 < canvas_top + 6:
            target = max(0.0, float(y0 - 6) / float(total_h))
            self.thumb_canvas.yview_moveto(min(1.0, target))
        elif y1 > canvas_bottom - 6:
            target = max(0.0, float(y1 - canvas_h + 6) / float(total_h))
            self.thumb_canvas.yview_moveto(min(1.0, target))

    def _update_thumbnail_selection(self):
        if not self.thumb_items:
            return
        for idx, item in enumerate(self.thumb_items):
            active = idx == self.page_index
            bg = "#e6f5ee" if active else "#ffffff"
            border = "#16845b" if active else "#cfeadd"
            fg = "#16845b" if active else "#476959"
            frame = item["frame"]
            text = item["text"]
            frame.configure(
                bg=bg,
                highlightbackground=border,
                highlightcolor=border,
                highlightthickness=2 if active else 1,
            )
            text.configure(bg=bg, fg=fg)
        self._scroll_thumb_to_active()

    def _draw_thumbnail_overlay(self, page_no):
        if self.doc is None:
            return
        if not self.thumb_items:
            return
        page_no = int(page_no)
        if page_no < 0 or page_no >= len(self.thumb_items):
            return

        item = self.thumb_items[page_no]
        c = item.get("canvas")
        scale = float(item.get("scale", 1.0))
        if c is None or scale <= 0:
            return

        c.delete("seg")
        self._draw_end_fill_overlay(c, page_no, scale)
        segs = self.segments_by_page.get(page_no, [])
        if not segs:
            return

        color = "#ff0000" if self.line_color_name == "Merah" else "#111111"
        for seg in segs:
            x = float(seg.get("x", 0.0)) * scale
            y0_line_pdf, y1_line_pdf, y0_cap_pdf, y1_cap_pdf = self._segment_line_and_cap_anchors(seg, page_no)
            y0_line = y0_line_pdf * scale
            y1_line = y1_line_pdf * scale
            y0_cap = y0_cap_pdf * scale
            y1_cap = y1_cap_pdf * scale
            c.create_line(x, y0_line, x, y1_line, fill=color, width=1, tags=("seg",))

            if bool(seg.get("draw_start_cap", False)):
                sx0, sy0, sx1, sy1 = self._cap_cross_line_points(
                    x,
                    y0_cap,
                    ga.CAP_SIDE * ga.CAP_TOP_DX * scale,
                    ga.CAP_TOP_DY * scale,
                )
                c.create_line(sx0, sy0, sx1, sy1, fill=color, width=1, tags=("seg",))

            if bool(seg.get("draw_end_cap", False)):
                ex0, ey0, ex1, ey1 = self._cap_cross_line_points(
                    x,
                    y1_cap,
                    ga.CAP_SIDE * ga.CAP_BOTTOM_DX * scale,
                    -ga.CAP_BOTTOM_DY * scale,
                )
                c.create_line(ex0, ey0, ex1, ey1, fill=color, width=1, tags=("seg",))

    def _refresh_all_thumbnail_overlays(self):
        if not self.thumb_items:
            return
        for idx in range(len(self.thumb_items)):
            self._draw_thumbnail_overlay(idx)

    def _copy_segments_with_page(self, segs, page_no):
        if not isinstance(segs, list):
            return []
        return [self._normalize_segment(seg, int(page_no)) for seg in segs]

    def _shift_segments_for_insert(self, insert_at, inserted_count):
        insert_at = int(insert_at)
        inserted_count = int(inserted_count)
        shifted = {}
        for page_no, segs in self.segments_by_page.items():
            try:
                old_page = int(page_no)
            except Exception:
                continue
            new_page = old_page + inserted_count if old_page >= insert_at else old_page
            shifted[new_page] = self._copy_segments_with_page(segs, new_page)
        return shifted

    def _shift_segments_for_delete(self, deleted_page):
        deleted_page = int(deleted_page)
        shifted = {}
        for page_no, segs in self.segments_by_page.items():
            try:
                old_page = int(page_no)
            except Exception:
                continue
            if old_page == deleted_page:
                continue
            new_page = old_page - 1 if old_page > deleted_page else old_page
            shifted[new_page] = self._copy_segments_with_page(segs, new_page)
        return shifted

    def _persist_page_structure_edit(self, new_segments_by_page, new_page_index, status_text):
        if self.doc is None:
            return

        base = self._get_loaded_document_base_name()
        temp_dir = tempfile.mkdtemp(prefix="garis_pages_")
        self._register_temp_dir(temp_dir)
        out_path = os.path.join(temp_dir, f"{base}_susun_halaman.pdf")

        out_doc = fitz.open()
        try:
            out_doc.insert_pdf(self.doc)
            out_doc.save(out_path, garbage=4, deflate=True)
        finally:
            out_doc.close()

        self.doc.close()
        self.doc = fitz.open(out_path)
        self.pdf_path = os.path.abspath(out_path)
        self.segments_by_page = new_segments_by_page if isinstance(new_segments_by_page, dict) else {}
        self.page_index = max(0, min(int(new_page_index), int(self.doc.page_count) - 1))
        self.selected_idx = None
        self.drag_mode = None
        self._set_create_mode(False)
        self._reset_history()
        self.build_page_thumbnails()
        self.render_page()
        self.set_status(status_text)

    def insert_pdf_after_current_dialog(self):
        self._insert_pdf_pages_dialog(append=False)

    def append_pdf_dialog(self):
        self._insert_pdf_pages_dialog(append=True, ask_position=True)

    def delete_current_page_dialog(self):
        if self.doc is None:
            messagebox.showinfo("Hapus Halaman", "Buka dokumen terlebih dahulu.")
            return
        if self.booklet_mode_active:
            messagebox.showinfo("Hapus Halaman", "Mode booklet aktif. Klik Unbooklet dulu sebelum menghapus halaman.")
            return

        total_pages = int(self.doc.page_count)
        if total_pages <= 1:
            messagebox.showwarning("Hapus Halaman", "Dokumen minimal harus memiliki 1 halaman.")
            return

        page_no = int(self.page_index)
        ok = messagebox.askyesno(
            "Hapus Halaman",
            f"Hapus halaman {page_no + 1} dari {total_pages}?\n\nPerubahan ini akan diterapkan ke dokumen kerja saat ini.",
        )
        if not ok:
            return

        try:
            new_segments = self._shift_segments_for_delete(page_no)
            self.doc.delete_page(page_no)
        except Exception as exc:
            messagebox.showerror("Hapus Halaman gagal", str(exc))
            self.set_status(f"Gagal menghapus halaman: {exc}")
            return

        new_page_index = min(page_no, total_pages - 2)
        self._persist_page_structure_edit(
            new_segments,
            new_page_index,
            f"Halaman {page_no + 1} berhasil dihapus.",
        )

    def _ask_pdf_insert_position(self):
        if self.doc is None:
            return None

        total_pages = int(self.doc.page_count)
        dlg = tk.Toplevel(self.root)
        dlg.title("Posisi Gabung PDF")
        dlg.configure(bg="#f8fcfa")
        dlg.resizable(False, False)
        dlg.transient(self.root)
        dlg.grab_set()

        result = {"insert_at": None}
        mode_var = tk.StringVar(value="end")
        page_var = tk.StringVar(value=str(min(max(1, self.page_index + 1), total_pages + 1)))

        outer = tk.Frame(dlg, bg="#f8fcfa", padx=18, pady=16)
        outer.pack(fill=tk.BOTH, expand=True)

        tk.Label(
            outer,
            text="Letakkan PDF tambahan di:",
            bg="#f8fcfa",
            fg="#0f2a1d",
            font=("Segoe UI", 11, "bold"),
            anchor="w",
        ).pack(fill=tk.X, pady=(0, 10))

        for value, label in (
            ("start", "Awal dokumen"),
            ("end", "Akhir dokumen"),
            ("custom", "Mulai di halaman tertentu"),
        ):
            tk.Radiobutton(
                outer,
                text=label,
                variable=mode_var,
                value=value,
                bg="#f8fcfa",
                fg="#0f2a1d",
                activebackground="#f8fcfa",
                anchor="w",
            ).pack(fill=tk.X, anchor="w", pady=1)

        page_row = tk.Frame(outer, bg="#f8fcfa")
        page_row.pack(fill=tk.X, pady=(10, 12))
        tk.Label(
            page_row,
            text=f"Halaman mulai (1-{total_pages + 1}):",
            bg="#f8fcfa",
            fg="#335545",
            font=("Segoe UI", 9),
        ).pack(side=tk.LEFT)
        page_spin = tk.Spinbox(
            page_row,
            from_=1,
            to=total_pages + 1,
            width=6,
            textvariable=page_var,
            command=lambda: mode_var.set("custom"),
        )
        page_spin.pack(side=tk.LEFT, padx=(8, 0))
        page_spin.bind("<FocusIn>", lambda _e: mode_var.set("custom"))
        page_spin.bind("<Button-1>", lambda _e: mode_var.set("custom"))

        tk.Label(
            outer,
            text=f"Halaman {total_pages + 1} berarti setelah halaman terakhir.",
            bg="#f8fcfa",
            fg="#648b78",
            font=("Segoe UI", 8),
            anchor="w",
        ).pack(fill=tk.X, pady=(0, 12))

        buttons = tk.Frame(outer, bg="#f8fcfa")
        buttons.pack(fill=tk.X)

        def _cancel():
            result["insert_at"] = None
            dlg.destroy()

        def _ok():
            mode = mode_var.get()
            if mode == "start":
                insert_at = 0
            elif mode == "end":
                insert_at = total_pages
            else:
                try:
                    page_no = int(str(page_var.get()).strip())
                except Exception:
                    messagebox.showerror("Posisi Gabung PDF", "Nomor halaman harus berupa angka.")
                    return
                page_no = max(1, min(total_pages + 1, page_no))
                insert_at = page_no - 1
            result["insert_at"] = int(insert_at)
            dlg.destroy()

        ttk.Button(buttons, text="Batal", command=_cancel, style="Dialog.TButton").pack(side=tk.RIGHT, padx=(8, 0))
        ttk.Button(buttons, text="Gabungkan", command=_ok, style="DialogAccent.TButton").pack(side=tk.RIGHT)

        dlg.protocol("WM_DELETE_WINDOW", _cancel)
        dlg.update_idletasks()
        try:
            x = self.root.winfo_rootx() + max(0, (self.root.winfo_width() - dlg.winfo_width()) // 2)
            y = self.root.winfo_rooty() + max(0, (self.root.winfo_height() - dlg.winfo_height()) // 2)
            dlg.geometry(f"+{x}+{y}")
        except Exception:
            pass
        self.root.wait_window(dlg)
        return result["insert_at"]

    def _insert_pdf_pages_dialog(self, append=False, ask_position=False):
        if self.doc is None:
            messagebox.showinfo("Susun PDF", "Buka dokumen utama terlebih dahulu.")
            return
        if self.booklet_mode_active:
            messagebox.showinfo("Susun PDF", "Mode booklet aktif. Klik Unbooklet dulu sebelum menyusun halaman.")
            return

        path = filedialog.askopenfilename(
            title="Pilih PDF yang akan digabung",
            filetypes=[("PDF files", "*.pdf"), ("All files", "*.*")],
        )
        if not path:
            return

        if bool(ask_position):
            insert_at = self._ask_pdf_insert_position()
            if insert_at is None:
                return
        else:
            insert_at = int(self.doc.page_count) if bool(append) else min(int(self.page_index) + 1, int(self.doc.page_count))
        src_doc = None
        try:
            src_doc = fitz.open(path)
            inserted_count = int(src_doc.page_count)
            if inserted_count <= 0:
                raise RuntimeError("PDF yang dipilih tidak memiliki halaman.")
            new_segments = self._shift_segments_for_insert(insert_at, inserted_count)
            self.doc.insert_pdf(src_doc, start_at=insert_at)
        except Exception as exc:
            messagebox.showerror("Susun PDF gagal", str(exc))
            self.set_status(f"Gagal menyisipkan PDF: {exc}")
            return
        finally:
            if src_doc is not None:
                src_doc.close()

        target_page = insert_at
        if bool(ask_position):
            if insert_at <= 0:
                action = "digabung di awal"
            elif insert_at >= int(self.doc.page_count) - inserted_count:
                action = "digabung di akhir"
            else:
                action = f"digabung mulai halaman {insert_at + 1}"
        else:
            action = "digabung di akhir" if bool(append) else f"disisipkan setelah halaman {self.page_index + 1}"
        self._persist_page_structure_edit(
            new_segments,
            target_page,
            f"{inserted_count} halaman PDF berhasil {action}.",
        )

    def _thumbnail_drop_insert_index(self, y_root):
        if not self.thumb_items:
            return 0
        for idx, item in enumerate(self.thumb_items):
            frame = item.get("frame")
            if frame is None:
                continue
            top = int(frame.winfo_rooty())
            height = max(1, int(frame.winfo_height()))
            if int(y_root) < top + (height // 2):
                return idx
            if int(y_root) < top + height:
                return idx + 1
        return len(self.thumb_items)

    def _set_thumbnail_drag_cursor(self, active):
        cursor = "fleur" if bool(active) else "hand2"
        for item in getattr(self, "thumb_items", []) or []:
            for key in ("frame", "canvas", "text"):
                widget = item.get(key)
                if widget is None:
                    continue
                try:
                    widget.configure(cursor=cursor)
                except Exception:
                    pass
        try:
            if hasattr(self, "thumb_canvas"):
                self.thumb_canvas.configure(cursor=cursor if bool(active) else "")
            if hasattr(self, "thumb_inner"):
                self.thumb_inner.configure(cursor=cursor if bool(active) else "")
            self.root.configure(cursor=cursor if bool(active) else "")
        except Exception:
            pass

    def on_thumb_press(self, event, page_no):
        self.go_to_page(page_no)
        self.thumb_drag_start_page = int(page_no)
        self.thumb_drag_start_y = int(getattr(event, "y_root", 0) or 0)
        self.thumb_dragging = False
        self._set_thumbnail_drag_cursor(True)
        return "break"

    def on_thumb_drag_motion(self, event):
        if self.thumb_drag_start_page is None:
            return "break"
        delta = abs(int(getattr(event, "y_root", 0) or 0) - int(self.thumb_drag_start_y))
        if delta >= 8:
            self.thumb_dragging = True
            self._set_thumbnail_drag_cursor(True)
            self.set_status("Lepas thumbnail untuk memindahkan halaman.")
        return "break"

    def on_thumb_release(self, event):
        start_page = self.thumb_drag_start_page
        was_dragging = bool(self.thumb_dragging)
        self.thumb_drag_start_page = None
        self.thumb_dragging = False
        self._set_thumbnail_drag_cursor(False)

        if start_page is None or not was_dragging:
            return "break"
        insert_index = self._thumbnail_drop_insert_index(getattr(event, "y_root", 0) or 0)
        target_page = int(insert_index)
        if int(start_page) < target_page:
            target_page -= 1
        self.move_page_to_index(int(start_page), int(target_page))
        return "break"

    def move_page_to_index(self, from_page, to_page):
        if self.doc is None:
            return
        if self.booklet_mode_active:
            messagebox.showinfo("Susun PDF", "Mode booklet aktif. Klik Unbooklet dulu sebelum menyusun halaman.")
            return

        total = int(self.doc.page_count)
        from_page = max(0, min(int(from_page), total - 1))
        to_page = max(0, min(int(to_page), total - 1))
        if from_page == to_page:
            return

        order = list(range(total))
        moved = order.pop(from_page)
        order.insert(to_page, moved)

        new_doc = fitz.open()
        try:
            for old_page in order:
                new_doc.insert_pdf(self.doc, from_page=int(old_page), to_page=int(old_page))

            temp_dir = tempfile.mkdtemp(prefix="garis_pages_")
            self._register_temp_dir(temp_dir)
            out_path = os.path.join(temp_dir, f"{self._get_loaded_document_base_name()}_susun_halaman.pdf")
            new_doc.save(out_path, garbage=4, deflate=True)
        finally:
            new_doc.close()

        new_segments = {}
        for new_page, old_page in enumerate(order):
            segs = self.segments_by_page.get(int(old_page), [])
            if segs:
                new_segments[int(new_page)] = self._copy_segments_with_page(segs, int(new_page))

        self.doc.close()
        self.doc = fitz.open(out_path)
        self.pdf_path = os.path.abspath(out_path)
        self.segments_by_page = new_segments
        self.page_index = int(to_page)
        self.selected_idx = None
        self.drag_mode = None
        self._set_create_mode(False)
        self._reset_history()
        self.build_page_thumbnails()
        self.render_page()
        self.set_status(f"Halaman {from_page + 1} dipindah ke posisi {to_page + 1}.")

    def go_to_page(self, page_no):
        if self.doc is None:
            return
        page_no = max(0, min(int(page_no), self.doc.page_count - 1))
        if page_no == self.page_index and self.page_image is not None:
            self._update_thumbnail_selection()
            return
        self.page_index = page_no
        self.selected_idx = None
        self.drag_mode = None
        self.render_page()

    def build_page_thumbnails(self):
        self.clear_page_thumbnails()
        if self.doc is None:
            return

        max_thumb_width = 150.0
        total_pages = int(self.doc.page_count)
        for idx in range(total_pages):
            page = self.doc[idx]
            page_w = max(1.0, float(page.rect.width))
            scale = min(1.0, max_thumb_width / page_w)
            pix = page.get_pixmap(matrix=fitz.Matrix(scale, scale), alpha=False)
            b64 = base64.b64encode(pix.tobytes("png")).decode("ascii")
            img = tk.PhotoImage(data=b64)
            self.thumb_images.append(img)

            card = tk.Frame(
                self.thumb_inner,
                bg="#ffffff",
                bd=0,
                cursor="hand2",
                highlightthickness=1,
                highlightbackground="#cfeadd",
            )
            card.pack(fill=tk.X, padx=8, pady=5)

            thumb_view = tk.Canvas(
                card,
                width=max(1, int(img.width())),
                height=max(1, int(img.height())),
                bg="#ffffff",
                highlightthickness=0,
                bd=0,
                cursor="hand2",
            )
            thumb_view.create_image(0, 0, image=img, anchor="nw", tags=("bg",))
            thumb_view.pack(padx=6, pady=(6, 3))
            txt_label = tk.Label(
                card,
                text=f"Halaman {idx + 1}",
                bg="#ffffff",
                fg="#476959",
                font=("Segoe UI", 9, "bold"),
                cursor="hand2",
            )
            txt_label.pack(pady=(0, 6))

            for widget in (card, thumb_view, txt_label):
                widget.bind("<ButtonPress-1>", lambda e, p=idx: self.on_thumb_press(e, p))
                widget.bind("<B1-Motion>", self.on_thumb_drag_motion)
                widget.bind("<ButtonRelease-1>", self.on_thumb_release)
                widget.bind("<MouseWheel>", self.on_thumb_mousewheel)
                widget.bind("<Button-4>", self.on_thumb_wheel_up_linux)
                widget.bind("<Button-5>", self.on_thumb_wheel_down_linux)

            self.thumb_items.append(
                {
                    "frame": card,
                    "text": txt_label,
                    "canvas": thumb_view,
                    "scale": float(scale),
                }
            )

        self.on_thumb_inner_configure()
        self._refresh_all_thumbnail_overlays()
        self._update_thumbnail_selection()

    def on_canvas_configure(self, _event):
        if self.page_image is None:
            return
        self._recenter_page(initial=False)
        self.draw_segments_overlay()

    def _recenter_page(self, initial):
        if self.page_image is None:
            return

        canvas_w = max(1, int(self.canvas.winfo_width()))
        canvas_h = max(1, int(self.canvas.winfo_height()))
        img_w = int(self.page_image.width())
        img_h = int(self.page_image.height())
        pad = 40

        self.page_offset_x = float(max(pad, (canvas_w - img_w) // 2))
        self.page_offset_y = float(max(pad, (canvas_h - img_h) // 2))

        region_w = max(canvas_w, int(img_w + (2 * self.page_offset_x)))
        region_h = max(canvas_h, int(img_h + (2 * self.page_offset_y)))
        self.canvas.config(scrollregion=(0, 0, region_w, region_h))
        self.canvas.coords("bg", self.page_offset_x, self.page_offset_y)

        if not initial:
            return

        center_x = self.page_offset_x + (img_w / 2.0)
        center_y = self.page_offset_y + (img_h / 2.0)
        left = max(0.0, center_x - (canvas_w / 2.0))
        top = max(0.0, center_y - (canvas_h / 2.0))

        max_left = max(0.0, float(region_w - canvas_w))
        max_top = max(0.0, float(region_h - canvas_h))
        left = min(left, max_left)
        top = min(top, max_top)

        x_fraction = 0.0 if max_left <= 0 else left / max_left
        y_fraction = 0.0 if max_top <= 0 else top / max_top
        self.canvas.xview_moveto(x_fraction)
        self.canvas.yview_moveto(y_fraction)

    def apply_app_icon(self):
        icon_path = resolve_asset_path(os.path.join("assets", "app_icon.png"))
        if not icon_path:
            return
        try:
            self.icon_image = tk.PhotoImage(file=icon_path)
            self.root.iconphoto(True, self.icon_image)
        except Exception:
            self.icon_image = None

    def apply_line_color(self, color_name):
        if color_name not in COLOR_MAP:
            color_name = "Merah"
        self.line_color_name = color_name
        self.line_color_var.set(color_name)
        ga.LINE_COLOR = COLOR_MAP[color_name]
        self._refresh_color_toggle_styles()

    def on_color_change(self):
        self.apply_line_color(self.line_color_var.get())
        self.draw_segments_overlay()
        self._refresh_all_thumbnail_overlays()
        self.set_status(f"Warna garis aktif: {self.line_color_name}")

    def show_help_terms(self):
        terms = (
            "SIMANIS Desktop - edisi mandiri\n\n"
            "Fitur PDF, garis, ekspor, dan cetak dapat digunakan langsung tanpa aktivasi.\n"
            "Login akun SIMANIS hanya diperlukan untuk mengambil data penghadap dan PPAT.\n"
            "Pastikan data dokumen yang diproses adalah data yang berhak Anda kelola.\n\n"
            "Bantuan: +62 877 2417 0145\n"
            "https://wa.me/6287724170145"
        )
        messagebox.showinfo("SIMANIS | Bantuan", terms, parent=self.root)

    def _get_end_fill_rows(self, page_no):
        settings = elf.normalize_settings(getattr(self, "end_fill_settings", None))
        if self.doc is None or not settings["enabled"]:
            return []
        booklet = bool(getattr(self, "booklet_mode_active", False))
        source = getattr(self, "booklet_source_pdf_path", None) if booklet else None
        pairs = tuple(tuple(p) for p in getattr(self, "booklet_layout_pairs", [])) if booklet else ()
        key = (self.doc, tuple(settings.items()), source, pairs)
        if getattr(self, "_end_fill_cache_key", None) != key:
            if source and pairs:
                with fitz.open(source) as original:
                    source_rows = elf.build_document_leaders(original, settings)
                    rows = {}
                    width = float(self.booklet_source_page_w)
                    height = float(self.booklet_source_page_h)
                    for index, pair in enumerate(pairs):
                        projected = []
                        for side, src in enumerate(pair):
                            if not 0 <= src < original.page_count:
                                continue
                            page = original[src]
                            scale = min(width / page.rect.width, height / page.rect.height)
                            ox = side * width + (width - page.rect.width * scale) / 2
                            oy = (height - page.rect.height * scale) / 2
                            matrix = page.rotation_matrix * fitz.Matrix(scale, scale) * fitz.Matrix(1, 0, 0, 1, ox, oy)
                            for leader in source_rows.get(src, []):
                                projected.append(dict(leader, matrix=tuple(matrix), width=leader["width"] * scale))
                        if projected:
                            rows[index] = projected
            else:
                rows = elf.build_document_leaders(self.doc, settings)
            self._end_fill_cache = rows
            self._end_fill_cache_key = key
        return self._end_fill_cache.get(int(page_no), [])

    def _draw_end_fill_overlay(self, canvas, page_no, scale, offset_x=0, offset_y=0):
        canvas.delete("end_fill")
        color = "#000000"
        rotation = self.doc[int(page_no)].rotation_matrix
        for leader in self._get_end_fill_rows(page_no):
            for x0, y0, x1, y1 in elf.dash_strokes(leader):
                a, b = fitz.Point(x0, y0) * rotation, fitz.Point(x1, y1) * rotation
                canvas.create_line(offset_x + a.x * scale, offset_y + a.y * scale,
                                   offset_x + b.x * scale, offset_y + b.y * scale,
                                   fill=color, width=max(0.5, leader["width"] * scale),
                                   capstyle=tk.BUTT, tags=("end_fill",))

    def _set_end_fill_settings(self, settings):
        settings = elf.normalize_settings(settings)
        if settings != elf.normalize_settings(getattr(self, "end_fill_settings", None)):
            self._push_undo_state()
            self.end_fill_settings = settings
        count = sum(len(self._get_end_fill_rows(p)) for p in range(self.doc.page_count))
        self.draw_segments_overlay()
        self._refresh_all_thumbnail_overlays()
        self.set_status(f"Pengisi ---- aktif pada {count} ujung baris." if settings["enabled"]
                        else "Pengisi ---- dihapus.")
        return count

    def open_end_fill_dialog(self):
        if self.doc is None:
            messagebox.showinfo("Isi Ujung Baris", "Buka dokumen terlebih dahulu.", parent=self.root)
            return
        if self.booklet_mode_active:
            messagebox.showinfo("Isi Ujung Baris", "Klik Unbooklet untuk mengatur pengisi pada halaman asli.\nPengisi tetap ikut saat dibuat booklet.", parent=self.root)
            return
        current = elf.normalize_settings(getattr(self, "end_fill_settings", None))
        dialog = tk.Toplevel(self.root)
        dialog.title("Isi Ujung Baris — SIMANIS")
        dialog.configure(bg="#ffffff")
        dialog.resizable(False, False)
        dialog.transient(self.root)
        body = tk.Frame(dialog, bg="#ffffff", padx=24, pady=20)
        body.pack(fill=tk.BOTH, expand=True)
        tk.Label(body, text="Tutup sisa ujung baris", font=("Segoe UI", 15, "bold"),
                 fg="#123b2d", bg="#ffffff").pack(anchor="w")
        tk.Label(body, text="Tambahkan ---- pada ujung teks dan baris kosong.\nBerlaku untuk seluruh halaman dokumen.",
                 justify=tk.LEFT, font=("Segoe UI", 10), fg="#527464", bg="#ffffff").pack(anchor="w", pady=(6, 16))
        preview = tk.Canvas(body, width=420, height=50, bg="#f0f8f4", highlightthickness=0)
        preview.pack(fill=tk.X, pady=(0, 16))
        preview.create_text(12, 24, text="Isi akta berakhir di sini. --------------------------------", anchor="w",
                            font=("Segoe UI", 11), fill="#1e3b2d")
        automatic = tk.BooleanVar(value=current["automatic"])
        fill_blanks = tk.BooleanVar(value=current["fill_blank_lines"])
        margin = tk.StringVar(value=f'{current["right_margin_mm"]:g}')
        ttk.Checkbutton(body, text="Batas kanan otomatis mengikuti isi dokumen", variable=automatic,
                        command=lambda: entry.configure(state="disabled" if automatic.get() else "normal")).pack(anchor="w")
        ttk.Checkbutton(body, text="Isi juga baris kosong di area isi akta", variable=fill_blanks).pack(anchor="w", pady=(7, 0))
        row = tk.Frame(body, bg="#ffffff")
        row.pack(fill=tk.X, pady=(10, 14))
        tk.Label(row, text="Margin kanan manual (mm)", bg="#ffffff", fg="#294f3e", font=("Segoe UI", 10)).pack(side=tk.LEFT)
        entry = ttk.Entry(row, textvariable=margin, width=8)
        entry.pack(side=tk.RIGHT)
        entry.configure(state="disabled" if automatic.get() else "normal")
        tk.Label(body, text="Judul, footer, dan blok tanda tangan dilewati.\nPDF scan perlu lapisan teks/OCR terlebih dahulu.",
                 bg="#ffffff", fg="#688575", justify=tk.LEFT, font=("Segoe UI", 9)).pack(anchor="w")

        def apply(enabled):
            try:
                value = float(margin.get().replace(",", "."))
                if enabled and not automatic.get() and (not math.isfinite(value) or not 5 <= value <= 80):
                    raise ValueError
            except ValueError:
                if enabled and not automatic.get():
                    messagebox.showerror("Margin kanan", "Isi margin kanan antara 5 dan 80 mm.", parent=dialog)
                    return
                value = current["right_margin_mm"]
            count = self._set_end_fill_settings({"enabled": enabled, "automatic": automatic.get(),
                                                 "right_margin_mm": value, "fill_blank_lines": fill_blanks.get()})
            dialog.destroy()
            if enabled and count == 0:
                messagebox.showinfo("Isi Ujung Baris", "Tidak ada sisa ujung baris yang perlu diisi.\nPastikan PDF memiliki teks yang dapat dipilih; PDF scan memerlukan OCR terlebih dahulu.", parent=self.root)

        buttons = tk.Frame(body, bg="#ffffff")
        buttons.pack(fill=tk.X, pady=(20, 0))
        ttk.Button(buttons, text="Hapus pengisi", command=lambda: apply(False)).pack(side=tk.LEFT)
        ttk.Button(buttons, text="Terapkan ----", command=lambda: apply(True)).pack(side=tk.RIGHT)
        dialog.update_idletasks()
        x = self.root.winfo_rootx() + max(0, (self.root.winfo_width() - dialog.winfo_reqwidth()) // 2)
        y = self.root.winfo_rooty() + max(0, (self.root.winfo_height() - dialog.winfo_reqheight()) // 2)
        dialog.geometry(f"+{x}+{y}")
        dialog.grab_set()

    def _cap_cross_mode_label(self):
        return "bersilangan" if bool(getattr(self, "cap_cross_enabled", True)) else "tidak bersilangan"

    def _set_cap_cross_mode(self, enabled, status_prefix="Mode penutup diperbarui", silent=False):
        self.cap_cross_enabled = bool(enabled)
        self.draw_segments_overlay()
        self._refresh_all_thumbnail_overlays()
        if not silent:
            self.set_status(f"{status_prefix}: {self._cap_cross_mode_label()}")

    def _apply_cap_settings(
        self,
        top_dx,
        top_dy,
        bottom_dx,
        bottom_dy,
        status_prefix="Kemiringan penutup diperbarui",
        cross_mode=None,
    ):
        try:
            top_dx = float(top_dx)
            top_dy = float(top_dy)
            bottom_dx = float(bottom_dx)
            bottom_dy = float(bottom_dy)
            if top_dx <= 0 or bottom_dx <= 0:
                raise ValueError("Nilai panjang (DX) harus lebih dari 0.")
        except Exception as exc:
            return False, f"Nilai tidak valid: {exc}"

        if cross_mode is not None:
            self.cap_cross_enabled = bool(cross_mode)

        ga.CAP_TOP_DX = top_dx
        ga.CAP_TOP_DY = top_dy
        ga.CAP_BOTTOM_DX = bottom_dx
        ga.CAP_BOTTOM_DY = bottom_dy
        self.draw_segments_overlay()
        self._refresh_all_thumbnail_overlays()
        self.set_status(
            f"{status_prefix}: atas({ga.CAP_TOP_DX:.1f}, {ga.CAP_TOP_DY:.1f}) "
            f"bawah({ga.CAP_BOTTOM_DX:.1f}, {ga.CAP_BOTTOM_DY:.1f}) | "
            f"mode {self._cap_cross_mode_label()}"
        )
        return True, ""

    def open_cap_settings_custom_dialog(self):
        dlg = tk.Toplevel(self.root)
        dlg.title("Kemiringan - Custom")
        dlg.transient(self.root)
        dlg.grab_set()
        dlg.configure(bg="#f8fcfa")
        dlg.resizable(False, False)

        container = tk.Frame(dlg, bg="#f8fcfa", padx=14, pady=12)
        container.pack(fill=tk.BOTH, expand=True)

        tk.Label(
            container,
            text="Atur nilai custom untuk pembuka dan penutup akta.",
            bg="#f8fcfa",
            fg="#335545",
            font=("Segoe UI", 9),
            anchor="w",
            justify=tk.LEFT,
        ).pack(fill=tk.X, pady=(0, 8))

        form = tk.Frame(container, bg="#f8fcfa")
        form.pack(fill=tk.X)
        fields = [
            ("Panjang Pembuka (DX Atas)", "top_dx", ga.CAP_TOP_DX),
            ("Kemiringan Pembuka (DY Atas)", "top_dy", ga.CAP_TOP_DY),
            ("Panjang Penutup (DX Bawah)", "bottom_dx", ga.CAP_BOTTOM_DX),
            ("Kemiringan Penutup (DY Bawah)", "bottom_dy", ga.CAP_BOTTOM_DY),
        ]
        vars_map = {}
        for i, (label, key, value) in enumerate(fields):
            tk.Label(
                form,
                text=label,
                bg="#f8fcfa",
                fg="#0f2a1d",
                font=("Segoe UI", 9, "bold"),
                anchor="w",
            ).grid(row=i, column=0, sticky="w", pady=4, padx=(0, 10))
            v = tk.StringVar(value=str(value))
            vars_map[key] = v
            tk.Entry(
                form,
                textvariable=v,
                width=16,
                bg="#ffffff",
                fg="#0f2a1d",
                relief=tk.FLAT,
                highlightthickness=1,
                highlightbackground="#cbe1d6",
                highlightcolor="#65b58f",
                font=("Segoe UI", 9),
            ).grid(row=i, column=1, sticky="w", pady=4)

        tk.Label(
            container,
            text="Tips: nilai DY negatif cenderung naik, nilai DY positif cenderung turun.",
            bg="#f8fcfa",
            fg="#648b78",
            font=("Segoe UI", 8),
            anchor="w",
            justify=tk.LEFT,
        ).pack(fill=tk.X, pady=(8, 6))

        mode_var = tk.StringVar(value="cross" if self.cap_cross_enabled else "no_cross")
        mode_box = tk.Frame(container, bg="#f8fcfa")
        mode_box.pack(fill=tk.X, pady=(0, 8))
        tk.Label(
            mode_box,
            text="Mode ujung garis:",
            bg="#f8fcfa",
            fg="#0f2a1d",
            font=("Segoe UI", 9, "bold"),
            anchor="w",
        ).pack(side=tk.LEFT, padx=(0, 10))
        tk.Radiobutton(
            mode_box,
            text="Bersilangan",
            variable=mode_var,
            value="cross",
            bg="#f8fcfa",
            fg="#0f2a1d",
            activebackground="#f8fcfa",
            activeforeground="#0f2a1d",
            selectcolor="#e5ebe8",
            font=("Segoe UI", 9),
        ).pack(side=tk.LEFT, padx=(0, 8))
        tk.Radiobutton(
            mode_box,
            text="Tidak Bersilangan",
            variable=mode_var,
            value="no_cross",
            bg="#f8fcfa",
            fg="#0f2a1d",
            activebackground="#f8fcfa",
            activeforeground="#0f2a1d",
            selectcolor="#e5ebe8",
            font=("Segoe UI", 9),
        ).pack(side=tk.LEFT)

        btns = tk.Frame(container, bg="#f8fcfa")
        btns.pack(fill=tk.X, pady=(2, 0))

        def _save():
            ok, err = self._apply_cap_settings(
                vars_map["top_dx"].get().strip(),
                vars_map["top_dy"].get().strip(),
                vars_map["bottom_dx"].get().strip(),
                vars_map["bottom_dy"].get().strip(),
                status_prefix="Kemiringan custom disimpan",
                cross_mode=(mode_var.get() == "cross"),
            )
            if not ok:
                messagebox.showerror("Pengaturan Penutup", err, parent=dlg)
                return
            dlg.destroy()

        self._make_button(btns, "Simpan", _save, width=12, accent=True).pack(side=tk.RIGHT, padx=4)
        self._make_button(btns, "Batal", dlg.destroy, width=10, accent=False).pack(side=tk.RIGHT, padx=4)
        dlg.update_idletasks()
        dlg.geometry(f"+{self.root.winfo_rootx() + 120}+{self.root.winfo_rooty() + 120}")

    def open_cap_settings_dialog(self):
        dlg = tk.Toplevel(self.root)
        dlg.title("Pilih Kemiringan Penutup")
        dlg.transient(self.root)
        dlg.grab_set()
        dlg.configure(bg="#f8fcfa")
        dlg.resizable(False, False)

        container = tk.Frame(dlg, bg="#f8fcfa", padx=14, pady=12)
        container.pack(fill=tk.BOTH, expand=True)

        tk.Label(
            container,
            text="Pilih preset kemiringan (langsung klik, tanpa input).",
            bg="#f8fcfa",
            fg="#335545",
            font=("Segoe UI", 9),
            anchor="w",
        ).pack(fill=tk.X, pady=(0, 8))

        mode_var = tk.StringVar(value="cross" if self.cap_cross_enabled else "no_cross")
        mode_box = tk.Frame(container, bg="#f8fcfa")
        mode_box.pack(fill=tk.X, pady=(0, 8))
        tk.Label(
            mode_box,
            text="Mode ujung garis:",
            bg="#f8fcfa",
            fg="#0f2a1d",
            font=("Segoe UI", 9, "bold"),
            anchor="w",
        ).pack(side=tk.LEFT, padx=(0, 10))
        tk.Radiobutton(
            mode_box,
            text="Bersilangan",
            variable=mode_var,
            value="cross",
            bg="#f8fcfa",
            fg="#0f2a1d",
            activebackground="#f8fcfa",
            activeforeground="#0f2a1d",
            selectcolor="#e5ebe8",
            font=("Segoe UI", 9),
        ).pack(side=tk.LEFT, padx=(0, 8))
        tk.Radiobutton(
            mode_box,
            text="Tidak Bersilangan",
            variable=mode_var,
            value="no_cross",
            bg="#f8fcfa",
            fg="#0f2a1d",
            activebackground="#f8fcfa",
            activeforeground="#0f2a1d",
            selectcolor="#e5ebe8",
            font=("Segoe UI", 9),
        ).pack(side=tk.LEFT)

        presets = [
            ("Pilihan 1", 65.0, 0.0),
            ("Pilihan 2", 65.0, -10.0),
            ("Pilihan 3", 65.0, -20.0),
        ]

        def _is_active(dx, dy):
            return (
                abs(float(ga.CAP_TOP_DX) - dx) < 0.01
                and abs(float(ga.CAP_TOP_DY) - dy) < 0.01
                and abs(float(ga.CAP_BOTTOM_DX) - dx) < 0.01
                and abs(float(ga.CAP_BOTTOM_DY) - dy) < 0.01
            )

        def _apply_preset(title, dx, dy):
            ok, err = self._apply_cap_settings(
                dx,
                dy,
                dx,
                dy,
                status_prefix=f"{title} diterapkan",
                cross_mode=(mode_var.get() == "cross"),
            )
            if not ok:
                messagebox.showerror("Pengaturan Penutup", err, parent=dlg)
                return
            dlg.destroy()

        def _apply_mode_only():
            self._set_cap_cross_mode(mode_var.get() == "cross")

        for title, dx, dy in presets:
            active = _is_active(dx, dy)
            card = tk.Frame(
                container,
                bg="#effff7" if active else "#f8fcfa",
                highlightthickness=1,
                highlightbackground="#65b58f" if active else "#cbe1d6",
                bd=0,
                padx=10,
                pady=8,
            )
            card.pack(fill=tk.X, pady=4)

            tk.Label(
                card,
                text=title,
                bg=str(card.cget("bg")),
                fg="#0f2a1d",
                font=("Segoe UI", 10, "bold"),
                anchor="w",
            ).pack(side=tk.LEFT)
            tk.Label(
                card,
                text=f"DX {int(dx)}   |   DY {int(dy)} (Pembuka & Penutup)",
                bg=str(card.cget("bg")),
                fg="#335545",
                font=("Segoe UI", 9),
                anchor="w",
            ).pack(side=tk.LEFT, padx=(12, 0))

            btn = self._make_button(
                card,
                "Pilih",
                lambda t=title, dxv=dx, dyv=dy: _apply_preset(t, dxv, dyv),
                width=8,
                accent=active,
            )
            btn.pack(side=tk.RIGHT, padx=(8, 0))

        tk.Label(
            container,
            text="Butuh nilai lain? gunakan Custom.",
            bg="#f8fcfa",
            fg="#648b78",
            font=("Segoe UI", 8),
            anchor="w",
        ).pack(fill=tk.X, pady=(6, 4))

        btns = tk.Frame(container, bg="#f8fcfa")
        btns.pack(fill=tk.X, pady=(2, 0))
        ttk.Button(
            btns,
            text="Custom",
            command=lambda: (dlg.destroy(), self.open_cap_settings_custom_dialog()),
            style="DialogSmall.TButton",
            cursor="hand2",
        ).pack(side=tk.LEFT, padx=(0, 4))
        self._make_button(btns, "Terapkan Mode", _apply_mode_only, width=14, accent=False).pack(side=tk.LEFT, padx=(0, 4))
        self._make_button(btns, "Tutup", dlg.destroy, width=8, accent=False).pack(side=tk.RIGHT, padx=4)

        dlg.update_idletasks()
        dlg.geometry(f"+{self.root.winfo_rootx() + 120}+{self.root.winfo_rooty() + 120}")

    def on_close(self):
        self._hide_loading_dialog()
        if self.doc is not None:
            self.doc.close()
            self.doc = None
        self._cleanup_print_temp_files()
        self.root.destroy()

    def _cleanup_print_temp_files(self):
        dirs = list(getattr(self, "print_temp_dirs", []) or [])
        self.print_temp_dirs = []
        for d in dirs:
            try:
                shutil.rmtree(d, ignore_errors=True)
            except Exception:
                pass

    def _register_temp_dir(self, temp_dir):
        d = str(temp_dir or "").strip()
        if not d:
            return
        if d not in self.print_temp_dirs:
            self.print_temp_dirs.append(d)

    def _get_loaded_document_base_name(self):
        source_path = ""
        word_path = str(getattr(self, "current_source_word_path", "") or "").strip()
        if word_path:
            source_path = word_path
        elif bool(getattr(self, "booklet_mode_active", False)):
            src = str(getattr(self, "booklet_source_pdf_path", "") or "").strip()
            if src:
                source_path = src
        if not source_path:
            base_name = str(getattr(self, "document_base_name", "") or "").strip()
            if base_name:
                return base_name
        if not source_path:
            source_path = str(getattr(self, "pdf_path", "") or "").strip()

        base = os.path.splitext(os.path.basename(source_path))[0]
        suffix = "__garis_editor"
        if base.lower().endswith(suffix):
            base = base[: -len(suffix)]
        return base or "dokumen"

    def open_pdf_dialog(self):
        path = filedialog.askopenfilename(
            title="Pilih PDF",
            filetypes=[("PDF files", "*.pdf"), ("All files", "*.*")],
        )
        if not path:
            return
        try:
            self._run_with_loading("Memuat PDF...", self.load_pdf, path, False)
            self.set_status("PDF berhasil dimuat. Klik tombol 'Buat Garis' untuk memproses garis.")
        except Exception as exc:
            messagebox.showerror("Load PDF gagal", str(exc))
            self.set_status(f"Gagal load PDF: {exc}")

    def _word_startupinfo_hidden(self):
        startupinfo = None
        if sys.platform.startswith("win") and hasattr(subprocess, "STARTUPINFO"):
            try:
                startupinfo = subprocess.STARTUPINFO()
                startupinfo.dwFlags |= subprocess.STARTF_USESHOWWINDOW
                startupinfo.wShowWindow = 0
            except Exception:
                startupinfo = None
        return startupinfo

    def _libreoffice_candidates(self):
        out = []
        for p in [
            shutil.which("soffice"),
            shutil.which("soffice.exe"),
            os.path.join(os.environ.get("PROGRAMFILES", ""), "LibreOffice", "program", "soffice.exe"),
            os.path.join(os.environ.get("PROGRAMFILES(X86)", ""), "LibreOffice", "program", "soffice.exe"),
            r"C:\Program Files\LibreOffice\program\soffice.exe",
            r"C:\Program Files (x86)\LibreOffice\program\soffice.exe",
        ]:
            p = str(p or "").strip()
            if p and p not in out and os.path.exists(p):
                out.append(p)
        return out

    def _check_word_com_powershell(self):
        if not sys.platform.startswith("win"):
            return False, "Microsoft Word COM hanya tersedia di Windows."
        ps_script = (
            "$ErrorActionPreference='Stop';"
            "$word=$null;"
            "try{"
            "$word=New-Object -ComObject Word.Application;"
            "$word.Visible=$false;"
            "$word.DisplayAlerts=0;"
            "} finally {"
            "if($word -ne $null){$word.Quit();[void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($word)};"
            "}"
        )
        try:
            subprocess.run(
                ["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-Command", ps_script],
                check=True,
                capture_output=True,
                text=True,
                timeout=25,
                startupinfo=self._word_startupinfo_hidden(),
            )
            return True, ""
        except Exception as exc:
            return False, f"Word COM PowerShell tidak siap: {exc}"

    def _ensure_word_conversion_ready(self, silent=False):
        if bool(getattr(self, "word_engine_checked", False)):
            ready = bool(getattr(self, "word_engine_ready", False))
            if not ready and not silent:
                messagebox.showwarning("DOCX Engine", str(getattr(self, "word_engine_error", "") or "Engine DOCX tidak siap."))
            return ready

        self.word_engine_checked = True
        ok_word, msg_word = self._check_word_com_powershell()
        if ok_word:
            self.word_engine_ready = True
            self.word_engine_error = ""
            self.set_status("Engine DOCX siap: Microsoft Word (PowerShell).")
            return True

        soffice_list = self._libreoffice_candidates()
        if soffice_list:
            self.word_engine_ready = True
            self.word_engine_error = ""
            self.set_status("Engine DOCX siap: fallback LibreOffice terdeteksi.")
            return True

        detail = msg_word or "Microsoft Word/LibreOffice tidak terdeteksi."
        self.word_engine_ready = False
        self.word_engine_error = (
            "Engine konversi DOC/DOCX belum siap.\n"
            "Pastikan Microsoft Word bisa dibuka normal atau install LibreOffice.\n\n"
            f"Detail: {detail}"
        )
        if not silent:
            messagebox.showwarning("DOCX Engine", self.word_engine_error)
        self.set_status("Engine DOCX belum siap.")
        return False

    def open_docx_dialog(self):
        if not self._ensure_word_conversion_ready(False):
            return

        doc_path = filedialog.askopenfilename(
            title="Pilih DOC/DOCX",
            filetypes=[
                ("Word Document", "*.docx *.doc"),
                ("DOCX", "*.docx"),
                ("DOC", "*.doc"),
                ("All files", "*.*"),
            ],
        )
        if not doc_path:
            return

        try:
            self._run_with_loading("Konversi DOCX -> PDF...", self._load_word_document, doc_path)
        except Exception as exc:
            messagebox.showerror("Konversi DOCX gagal", str(exc))
            self.set_status(f"Gagal konversi DOCX: {exc}")
            return

    def _load_word_document(self, word_path):
        self.set_status("Konversi DOCX -> PDF...")
        pdf_path = self.convert_word_to_pdf(word_path)
        self.load_pdf(pdf_path, run_auto_detect=False)
        self.current_source_word_path = os.path.abspath(word_path)
        self.set_status("DOCX berhasil dimuat. Klik tombol 'Buat Garis' untuk memproses garis.")

    def trigger_auto_generate_lines(self):
        if self.doc is None:
            messagebox.showinfo("Info", "Load dokumen terlebih dahulu.")
            return

        if self.booklet_mode_active:
            messagebox.showinfo("Info", "Mode booklet aktif. Klik Unbooklet dulu sebelum membuat garis otomatis.")
            return

        previous_tab = self.active_ribbon_tab
        try:
            if "tools" in self.ribbon_tab_frames:
                self._set_active_ribbon_tab("tools")
                self.root.update_idletasks()
            self.run_auto_detect()
        finally:
            if previous_tab in self.ribbon_tab_frames:
                self._set_active_ribbon_tab(previous_tab)
                self.root.update_idletasks()

    def _iter_docx_block_items(self, document_obj):
        from docx.oxml.table import CT_Tbl  # type: ignore
        from docx.oxml.text.paragraph import CT_P  # type: ignore
        from docx.table import Table  # type: ignore
        from docx.text.paragraph import Paragraph  # type: ignore

        body = getattr(getattr(document_obj, "element", None), "body", None)
        if body is None:
            return
        for child in body.iterchildren():
            if isinstance(child, CT_P):
                yield "paragraph", Paragraph(child, document_obj)
            elif isinstance(child, CT_Tbl):
                yield "table", Table(child, document_obj)

    def _convert_docx_with_native_open_source(self, word_path, temp_pdf):
        try:
            from docx_to_pdf_native import DocxToPdfConverter  # type: ignore
        except Exception as exc:
            return False, f"Package docx-to-pdf-native belum siap: {exc}"

        try:
            converter = DocxToPdfConverter(os.path.abspath(word_path), os.path.abspath(temp_pdf))
            converter.convert()
        except Exception as exc:
            return False, f"docx-to-pdf-native gagal: {exc}"

        if not os.path.exists(temp_pdf) or os.path.getsize(temp_pdf) <= 0:
            return False, "docx-to-pdf-native menghasilkan PDF kosong."
        return True, "Konversi DOCX via engine open-source native selesai."

    def _convert_docx_with_open_source(self, word_path, temp_pdf):
        try:
            from docx import Document as DocxDocument  # type: ignore
            from reportlab.lib import colors  # type: ignore
            from reportlab.lib.pagesizes import A4  # type: ignore
            from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet  # type: ignore
            from reportlab.lib.units import mm  # type: ignore
            from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle  # type: ignore
        except Exception as exc:
            return False, (
                "Engine open-source DOCX belum siap. "
                "Install: python-docx dan reportlab. "
                f"Detail: {exc}"
            )

        try:
            docx_doc = DocxDocument(os.path.abspath(word_path))
        except Exception as exc:
            return False, f"Gagal membaca DOCX: {exc}"

        styles = getSampleStyleSheet()
        style_body = ParagraphStyle(
            "DocxBody",
            parent=styles["Normal"],
            fontName="Helvetica",
            fontSize=10.5,
            leading=14,
            spaceAfter=3,
        )
        style_heading = ParagraphStyle(
            "DocxHeading",
            parent=style_body,
            fontName="Helvetica-Bold",
            fontSize=12.5,
            leading=16,
            spaceBefore=6,
            spaceAfter=5,
        )
        style_table_cell = ParagraphStyle(
            "DocxTableCell",
            parent=style_body,
            fontSize=9.5,
            leading=12,
        )

        story = []
        table_count = 0
        para_count = 0

        for block_kind, block in self._iter_docx_block_items(docx_doc):
            if block_kind == "paragraph":
                raw = str(getattr(block, "text", "") or "")
                text = raw.strip()
                if not text:
                    story.append(Spacer(1, 2 * mm))
                    continue

                style_name = str(getattr(getattr(block, "style", None), "name", "") or "").lower()
                is_heading = style_name.startswith("heading") or style_name.startswith("judul")
                p_style = style_heading if is_heading else style_body
                story.append(Paragraph(xml_escape(text).replace("\n", "<br/>"), p_style))
                para_count += 1
            elif block_kind == "table":
                rows_data = []
                col_count = 0
                for row in block.rows:
                    row_cells = []
                    for cell in row.cells:
                        chunks = []
                        for p in cell.paragraphs:
                            t = str(getattr(p, "text", "") or "").strip()
                            if t:
                                chunks.append(t)
                        cell_text = "\n".join(chunks).strip()
                        if not cell_text:
                            cell_text = "-"
                        row_cells.append(Paragraph(xml_escape(cell_text).replace("\n", "<br/>"), style_table_cell))
                    if row_cells:
                        col_count = max(col_count, len(row_cells))
                        rows_data.append(row_cells)

                if rows_data and col_count > 0:
                    usable_width = float(A4[0]) - (22 * mm) - (22 * mm)
                    col_w = usable_width / float(col_count)
                    col_widths = [col_w] * col_count
                    tbl = Table(rows_data, colWidths=col_widths, repeatRows=1 if len(rows_data) > 1 else 0)
                    tbl.setStyle(
                        TableStyle(
                            [
                                ("GRID", (0, 0), (-1, -1), 0.35, colors.black),
                                ("VALIGN", (0, 0), (-1, -1), "TOP"),
                                ("LEFTPADDING", (0, 0), (-1, -1), 4),
                                ("RIGHTPADDING", (0, 0), (-1, -1), 4),
                                ("TOPPADDING", (0, 0), (-1, -1), 3),
                                ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
                            ]
                        )
                    )
                    story.append(Spacer(1, 1.5 * mm))
                    story.append(tbl)
                    story.append(Spacer(1, 2.5 * mm))
                    table_count += 1

        if not story:
            story.append(Paragraph("Dokumen kosong.", style_body))

        try:
            pdf_doc = SimpleDocTemplate(
                os.path.abspath(temp_pdf),
                pagesize=A4,
                leftMargin=22 * mm,
                rightMargin=22 * mm,
                topMargin=18 * mm,
                bottomMargin=18 * mm,
                title=os.path.basename(word_path),
            )
            pdf_doc.build(story)
        except Exception as exc:
            return False, f"Gagal membuat PDF open-source: {exc}"

        if not os.path.exists(temp_pdf) or os.path.getsize(temp_pdf) <= 0:
            return False, "Output PDF open-source kosong."

        return True, f"Konversi DOCX via engine open-source selesai (paragraf: {para_count}, tabel: {table_count})."

    def convert_word_to_pdf(self, word_path):
        if not os.path.exists(word_path):
            raise RuntimeError("File DOC/DOCX tidak ditemukan.")

        ext = os.path.splitext(word_path)[1].lower()
        if ext not in (".docx", ".doc"):
            raise RuntimeError("Format file harus .docx atau .doc.")

        base_name = os.path.splitext(os.path.basename(word_path))[0]
        out_pdf = os.path.join(os.path.dirname(word_path), f"{base_name}__garis_editor.pdf")
        temp_dir = tempfile.mkdtemp(prefix="garis_word_")
        errors = []

        def _finalize_temp_pdf(temp_pdf, success_message):
            if not os.path.exists(temp_pdf):
                return None
            if os.path.getsize(temp_pdf) <= 0:
                return None
            target_out = os.path.abspath(out_pdf)

            # Jika file output yang sama sedang dibuka oleh app ini, tutup handle dulu.
            try:
                current_pdf = str(getattr(self, "pdf_path", "") or "").strip()
                if self.doc is not None and current_pdf and os.path.abspath(current_pdf) == target_out:
                    self.doc.close()
                    self.doc = None
            except Exception:
                pass

            try:
                if os.path.exists(target_out):
                    os.remove(target_out)
                shutil.copy2(temp_pdf, target_out)
                self.set_status(success_message)
                return target_out
            except PermissionError:
                # File lama dikunci process lain (viewer/preview). Simpan ke nama baru.
                ts = datetime.now().strftime("%Y%m%d_%H%M%S")
                alt_out = os.path.join(os.path.dirname(target_out), f"{base_name}__garis_editor_{ts}.pdf")
                shutil.copy2(temp_pdf, alt_out)
                self.set_status(success_message + " (file lama terkunci, disimpan sebagai nama baru).")
                return alt_out

        try:
            startupinfo = self._word_startupinfo_hidden()

            # 1) Microsoft Word COM via PowerShell (utama)
            if sys.platform.startswith("win"):
                try:
                    temp_pdf = os.path.join(temp_dir, f"{base_name}.pdf")
                    src = os.path.abspath(word_path).replace("'", "''")
                    dst = os.path.abspath(temp_pdf).replace("'", "''")
                    ps_script = (
                        "$ErrorActionPreference='Stop';"
                        "$word=$null;$doc=$null;"
                        "try{"
                        "$word=New-Object -ComObject Word.Application;"
                        "$word.Visible=$false;"
                        "$word.DisplayAlerts=0;"
                        f"$doc=$word.Documents.Open('{src}',$false,$true);"
                        f"$doc.SaveAs2('{dst}',17);"
                        "} finally {"
                        "if($doc -ne $null){$doc.Close($false);[void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($doc)};"
                        "if($word -ne $null){$word.Quit();[void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($word)};"
                        "}"
                    )
                    subprocess.run(
                        ["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-Command", ps_script],
                        check=True,
                        capture_output=True,
                        text=True,
                        timeout=180,
                        startupinfo=startupinfo,
                    )
                    done = _finalize_temp_pdf(temp_pdf, "Konversi DOC/DOCX via Microsoft Word (PowerShell) selesai.")
                    if done:
                        return done
                except Exception as exc:
                    errors.append(f"Word COM PowerShell: {exc}")

            # 2) LibreOffice headless (fallback)
            for soffice in self._libreoffice_candidates():
                try:
                    cmd = [soffice, "--headless", "--convert-to", "pdf", "--outdir", temp_dir, os.path.abspath(word_path)]
                    subprocess.run(
                        cmd,
                        check=True,
                        capture_output=True,
                        timeout=180,
                        startupinfo=startupinfo,
                    )
                    temp_pdf = os.path.join(temp_dir, f"{base_name}.pdf")
                    done = _finalize_temp_pdf(temp_pdf, "Konversi DOC/DOCX via LibreOffice selesai.")
                    if done:
                        return done
                except Exception as exc:
                    errors.append(f"LibreOffice ({soffice}): {exc}")

            detail = ""
            if errors:
                detail = "\n\nDetail:\n- " + "\n- ".join([str(e)[:180] for e in errors[:4]])
            raise RuntimeError(
                "Tidak ada engine konversi DOC/DOCX->PDF yang tersedia.\n"
                "Pastikan Microsoft Word bisa dibuka normal atau install LibreOffice.\n"
                "Setelah itu jalankan ulang aplikasi."
                + detail
            )
        finally:
            shutil.rmtree(temp_dir, ignore_errors=True)

    def convert_docx_to_pdf(self, docx_path):
        return self.convert_word_to_pdf(docx_path)

    def load_pdf(self, path, run_auto_detect=True, booklet_source_path=None):
        if self.doc is not None:
            self.doc.close()
            self.doc = None
        self.clear_page_thumbnails()

        self.doc = fitz.open(path)
        self.pdf_path = os.path.abspath(path)
        self._end_fill_cache_key = None
        if not booklet_source_path:
            self.end_fill_settings = elf.normalize_settings()
        if not str(booklet_source_path or "").strip():
            self.document_base_name = os.path.splitext(os.path.basename(self.pdf_path))[0]
        source_path = str(booklet_source_path or "").strip()
        if not source_path:
            self.current_source_word_path = None
        if source_path:
            source_abs = os.path.abspath(source_path)
            if source_abs != self.pdf_path:
                self.booklet_mode_active = True
                self.booklet_source_pdf_path = source_abs
                self.booklet_preview_pdf_path = self.pdf_path
                snapshot = self.booklet_editor_snapshot if isinstance(self.booklet_editor_snapshot, dict) else None
                source_segments = {}
                if snapshot and isinstance(snapshot.get("segments_by_page", None), dict):
                    source_segments = snapshot.get("segments_by_page", {})
                projected = self._project_source_segments_to_booklet(source_segments)
                if isinstance(projected, dict):
                    self.segments_by_page = projected
                else:
                    self.segments_by_page = {}
            else:
                self.booklet_mode_active = False
                self.booklet_source_pdf_path = None
                self.booklet_preview_pdf_path = None
                self.booklet_layout_pairs = []
                self.booklet_source_page_count = 0
                self.booklet_source_page_w = 0.0
                self.booklet_source_page_h = 0.0
        else:
            self.booklet_mode_active = False
            self.booklet_source_pdf_path = None
            self.booklet_preview_pdf_path = None
            self.booklet_editor_snapshot = None
            self.booklet_editor_snapshot_source = None
            self.booklet_layout_pairs = []
            self.booklet_source_page_count = 0
            self.booklet_source_page_w = 0.0
            self.booklet_source_page_h = 0.0
        self._refresh_booklet_button_text()
        self.page_index = 0
        self.selected_idx = None
        self.drag_mode = None
        self._set_create_mode(False)
        if not self.booklet_mode_active:
            self.segments_by_page = {}
        self._reset_history()
        self.build_page_thumbnails()

        if run_auto_detect:
            self.run_auto_detect()
        else:
            self.render_page()

    def _normalize_segment(self, seg, page_no):
        return {
            "page": int(page_no),
            "x": float(seg.get("x", 0.0)),
            "y0": float(seg.get("y0", 0.0)),
            "y1": float(seg.get("y1", 0.0)),
            "draw_start_cap": bool(seg.get("draw_start_cap", False)),
            "draw_end_cap": bool(seg.get("draw_end_cap", False)),
            "is_stub": bool(seg.get("is_stub", False)),
            "break_merge_before": bool(seg.get("break_merge_before", False)),
            "break_merge_after": bool(seg.get("break_merge_after", False)),
        }

    def _get_page_segments(self, page_no=None):
        p = self.page_index if page_no is None else int(page_no)
        if p not in self.segments_by_page:
            self.segments_by_page[p] = []
        return self.segments_by_page[p]

    def _clamp_segment(self, seg, page_no=None):
        p = self.page_index if page_no is None else int(page_no)
        if self.doc is None:
            return

        page = self.doc[p]
        w = float(page.rect.width)
        h = float(page.rect.height)

        seg["x"] = max(0.0, min(w, float(seg["x"])))
        y0 = max(0.0, min(h, float(seg["y0"])))
        y1 = max(0.0, min(h, float(seg["y1"])))
        if y1 <= y0 + 1.0:
            y1 = min(h, y0 + 2.0)
        seg["y0"] = y0
        seg["y1"] = y1

    def _segment_endpoints_with_overhang(self, seg, page_no=None):
        p = self.page_index if page_no is None else int(page_no)
        y0 = float(seg.get("y0", 0.0))
        y1 = float(seg.get("y1", 0.0))
        if y1 < y0:
            y0, y1 = y1, y0

        ext = max(0.0, float(getattr(self, "line_end_overhang_pt", 0.0)))
        y0 -= ext
        y1 += ext

        if self.doc is not None and 0 <= p < int(self.doc.page_count):
            h = float(self.doc[p].rect.height)
            y0 = max(0.0, min(h, y0))
            y1 = max(0.0, min(h, y1))
            if y1 <= y0 + 0.2:
                y1 = min(h, y0 + 0.2)

        return y0, y1

    def _segment_line_and_cap_anchors(self, seg, page_no=None):
        """
        Return:
        - vertical line endpoints (may be overshot in cross mode)
        - cap anchor endpoints (always original overhang endpoints)
        """
        p = self.page_index if page_no is None else int(page_no)
        y0_anchor, y1_anchor = self._segment_endpoints_with_overhang(seg, p)
        y0_line = float(y0_anchor)
        y1_line = float(y1_anchor)

        if bool(getattr(self, "cap_cross_enabled", True)):
            ratio = max(0.0, min(1.0, float(getattr(self, "cap_cross_vertical_ratio", 0.10))))
            if bool(seg.get("draw_start_cap", False)):
                top_overshoot = math.hypot(float(ga.CAP_TOP_DX), float(ga.CAP_TOP_DY)) * ratio
                y0_line -= top_overshoot
            if bool(seg.get("draw_end_cap", False)):
                bottom_overshoot = math.hypot(float(ga.CAP_BOTTOM_DX), float(ga.CAP_BOTTOM_DY)) * ratio
                y1_line += bottom_overshoot

        if self.doc is not None and 0 <= p < int(self.doc.page_count):
            h = float(self.doc[p].rect.height)
            y0_line = max(0.0, min(h, y0_line))
            y1_line = max(0.0, min(h, y1_line))
            if y1_line <= y0_line + 0.2:
                y1_line = min(h, y0_line + 0.2)

        return y0_line, y1_line, y0_anchor, y1_anchor

    def _cap_cross_line_points(self, x_anchor, y_anchor, dx, dy):
        cross_enabled = bool(getattr(self, "cap_cross_enabled", True))
        ratio = max(0.0, min(0.45, float(getattr(self, "cap_cross_back_ratio", 0.16))))
        if not cross_enabled:
            ratio = 0.0
        vx = float(dx)
        vy = float(dy)
        x0 = float(x_anchor) - (vx * ratio)
        y0 = float(y_anchor) - (vy * ratio)
        x1 = float(x_anchor) + vx
        y1 = float(y_anchor) + vy
        return x0, y0, x1, y1

    def run_auto_detect(self):
        if self.doc is None:
            messagebox.showinfo("Info", "Buka PDF terlebih dahulu.")
            return

        def _runner():
            if self.legacy_auto_detect:
                self._run_auto_detect_legacy()
            else:
                self._run_auto_detect_scan_pipeline()

        self._run_with_loading("Initialize Auto Detect...", _runner)

    def _run_auto_detect_legacy(self):
        self.set_status("Auto detect garis sedang berjalan...")
        try:
            segments, _, _, chosen = ga.detect_segments_auto(
                self.doc,
                preferred_model=self.model_file,
                override_threshold=self.threshold,
                extra_model_files=None,
            )
        except Exception as exc:
            messagebox.showerror("Error Auto Detect", str(exc))
            self.set_status(f"Gagal auto detect: {exc}")
            return

        by_page = {}
        for seg in segments:
            p = int(seg.get("page", 0))
            by_page.setdefault(p, []).append(self._normalize_segment(seg, p))

        self.segments_by_page = by_page
        self.selected_idx = None
        self.drag_mode = None
        self._set_create_mode(False)

        self.render_page()
        self._refresh_all_thumbnail_overlays()
        total = sum(len(v) for v in self.segments_by_page.values())
        chosen_name = chosen.get("name", chosen.get("kind", "auto"))
        self.set_status(f"Auto detect selesai ({chosen_name}). Total segmen: {total}")

    def _apply_caps_for_page(self, segs):
        if not segs:
            return []
        with_caps = ga.apply_page_caps([dict(s) for s in segs])
        return [self._normalize_segment(s, int(s.get("page", self.page_index))) for s in with_caps]

    def _run_auto_detect_scan_pipeline(self):
        self.set_status("Auto detect garis (scan-pipeline) sedang berjalan...")
        model_path = resolve_model_path(self.scan_model_file)
        try:
            model = sla.load_scan_model(model_path)
        except Exception as exc:
            messagebox.showerror("Error Model Scan", str(exc))
            self.set_status(f"Gagal load model scan: {exc}")
            return

        by_page = {}
        total_pages = int(self.doc.page_count)
        for idx, page in enumerate(self.doc):
            wc = sla.page_text_word_count(page)
            segs = []
            if wc > 0:
                base_segs = sla.build_segments_from_text_page(
                    page.number,
                    page,
                    model=model,
                    no_merge=True,
                    outside_shift=self.outside_shift,
                )
                segs = sla.merge_text_no_merge_segments(base_segs)

            if not segs and ga.needs_raster_fallback(page):
                bgr = sla.page_to_bgr(page, zoom=self.scan_zoom)
                segs = sla.build_segments_from_scan_page(
                    page.number,
                    bgr,
                    model,
                    self.scan_zoom,
                    no_merge=False,
                    outside_shift=self.outside_shift,
                )

            if segs:
                normalized = [self._normalize_segment(s, page.number) for s in segs]
                normalized = self._apply_caps_for_page(normalized)
                by_page[int(page.number)] = normalized

            self.set_status(f"Auto detect scan-pipeline: {idx + 1}/{total_pages} halaman...")

        self.segments_by_page = by_page
        self.selected_idx = None
        self.drag_mode = None
        self._set_create_mode(False)

        self.render_page()
        self._refresh_all_thumbnail_overlays()
        total = sum(len(v) for v in self.segments_by_page.values())
        self.set_status(f"Auto detect selesai (scan-pipeline). Total segmen: {total}")

    def render_page(self):
        if self.doc is None:
            return

        self.page_index = max(0, min(self.page_index, self.doc.page_count - 1))
        page = self.doc[self.page_index]
        matrix = fitz.Matrix(self.zoom, self.zoom)
        pix = page.get_pixmap(matrix=matrix, alpha=False)
        png_bytes = pix.tobytes("png")
        b64 = base64.b64encode(png_bytes).decode("ascii")

        self.page_image = tk.PhotoImage(data=b64)
        if hasattr(self, "empty_state"):
            self.empty_state.place_forget()
        if hasattr(self, "page_count_var"):
            self.page_count_var.set(str(self.doc.page_count))
        if hasattr(self, "document_title_var"):
            name = self.document_base_name or os.path.basename(self.pdf_path or "Dokumen")
            self.document_title_var.set(name if len(name) <= 42 else name[:39] + "…")
        self.canvas.delete("all")
        self.canvas.create_image(0, 0, image=self.page_image, anchor="nw", tags=("bg",))
        self._recenter_page(initial=True)

        self.draw_segments_overlay()
        if self.page_label is not None:
            self.page_label.config(text=f"{self.page_index + 1} / {self.doc.page_count}")
        if hasattr(self, "zoom_top_var"):
            self.zoom_top_var.set(f"{self.zoom:.2f}x")
        self._update_thumbnail_selection()

    def draw_segments_overlay(self):
        self.canvas.delete("seg")
        if self.doc is None:
            return

        self._draw_end_fill_overlay(self.canvas, self.page_index, self.zoom,
                                    self.page_offset_x, self.page_offset_y)

        segs = self._get_page_segments()
        z = self.zoom
        base_color = "#ff0000" if self.line_color_name == "Merah" else "#111111"
        for idx, seg in enumerate(segs):
            x = self.page_offset_x + (float(seg["x"]) * z)
            y0_line_pdf, y1_line_pdf, y0_cap_pdf, y1_cap_pdf = self._segment_line_and_cap_anchors(seg, self.page_index)
            y0_line = self.page_offset_y + (y0_line_pdf * z)
            y1_line = self.page_offset_y + (y1_line_pdf * z)
            y0_cap = self.page_offset_y + (y0_cap_pdf * z)
            y1_cap = self.page_offset_y + (y1_cap_pdf * z)

            color = base_color
            width = 2
            if idx == self.selected_idx:
                color = "#00cc66"
                width = 3

            self.canvas.create_line(x, y0_line, x, y1_line, fill=color, width=width, tags=("seg",))

            if bool(seg.get("draw_start_cap", False)):
                sx0, sy0, sx1, sy1 = self._cap_cross_line_points(
                    x,
                    y0_cap,
                    ga.CAP_SIDE * ga.CAP_TOP_DX * z,
                    ga.CAP_TOP_DY * z,
                )
                self.canvas.create_line(
                    sx0,
                    sy0,
                    sx1,
                    sy1,
                    fill=color,
                    width=width,
                    tags=("seg",),
                )

            if bool(seg.get("draw_end_cap", False)):
                ex0, ey0, ex1, ey1 = self._cap_cross_line_points(
                    x,
                    y1_cap,
                    ga.CAP_SIDE * ga.CAP_BOTTOM_DX * z,
                    -ga.CAP_BOTTOM_DY * z,
                )
                self.canvas.create_line(
                    ex0,
                    ey0,
                    ex1,
                    ey1,
                    fill=color,
                    width=width,
                    tags=("seg",),
                )

            if idx == self.selected_idx:
                s = 4
                self.canvas.create_rectangle(
                    x - s,
                    y0_cap - s,
                    x + s,
                    y0_cap + s,
                    fill="#00cc66",
                    outline="",
                    tags=("seg",),
                )
                self.canvas.create_rectangle(
                    x - s,
                    y1_cap - s,
                    x + s,
                    y1_cap + s,
                    fill="#00cc66",
                    outline="",
                    tags=("seg",),
                )
        self._draw_thumbnail_overlay(self.page_index)

    def _event_canvas_xy(self, event):
        return self.canvas.canvasx(event.x), self.canvas.canvasy(event.y)

    def _canvas_to_pdf(self, cx, cy):
        return (float(cx) - self.page_offset_x) / self.zoom, (float(cy) - self.page_offset_y) / self.zoom

    def _pick_segment(self, cx, cy):
        segs = self._get_page_segments()
        best_idx = None
        best_mode = None
        best_score = None
        z = self.zoom

        for idx, seg in enumerate(segs):
            x = self.page_offset_x + (float(seg["x"]) * z)
            y0 = self.page_offset_y + (float(seg["y0"]) * z)
            y1 = self.page_offset_y + (float(seg["y1"]) * z)
            if y1 < y0:
                y0, y1 = y1, y0

            if cy < y0 - 12 or cy > y1 + 12:
                continue
            dx = abs(cx - x)
            if dx > 14:
                continue

            if abs(cy - y0) <= 10:
                mode = "top"
                score = dx + abs(cy - y0) * 0.2
            elif abs(cy - y1) <= 10:
                mode = "bottom"
                score = dx + abs(cy - y1) * 0.2
            else:
                mode = "move"
                score = dx

            if best_score is None or score < best_score:
                best_score = score
                best_idx = idx
                best_mode = mode

        return best_idx, best_mode

    def on_left_down(self, event):
        if self.doc is None:
            return

        cx, cy = self._event_canvas_xy(event)
        if self.create_mode:
            self.add_line_at(cx, cy)
            self._set_create_mode(False)
            return

        idx, mode = self._pick_segment(cx, cy)
        self.selected_idx = idx
        self.drag_mode = mode
        self._drag_start_snapshot = None
        self._drag_changed = False
        if idx is not None and mode in ("move", "top", "bottom"):
            self._drag_start_snapshot = self._snapshot_editor_state()
        self.last_canvas_x = cx
        self.last_canvas_y = cy
        self.draw_segments_overlay()

    def on_left_drag(self, event):
        if self.doc is None:
            return
        if self.selected_idx is None or self.drag_mode is None:
            return

        segs = self._get_page_segments()
        if not (0 <= self.selected_idx < len(segs)):
            return

        seg = segs[self.selected_idx]
        old_state = (float(seg["x"]), float(seg["y0"]), float(seg["y1"]))
        cx, cy = self._event_canvas_xy(event)

        if self.drag_mode == "move":
            dx = (cx - self.last_canvas_x) / self.zoom
            dy = (cy - self.last_canvas_y) / self.zoom
            seg["x"] = float(seg["x"]) + dx
            seg["y0"] = float(seg["y0"]) + dy
            seg["y1"] = float(seg["y1"]) + dy
        elif self.drag_mode == "top":
            _, py = self._canvas_to_pdf(cx, cy)
            seg["y0"] = min(float(seg["y1"]) - 1.0, py)
        elif self.drag_mode == "bottom":
            _, py = self._canvas_to_pdf(cx, cy)
            seg["y1"] = max(float(seg["y0"]) + 1.0, py)

        self._clamp_segment(seg)
        new_state = (float(seg["x"]), float(seg["y0"]), float(seg["y1"]))
        if new_state != old_state:
            self._drag_changed = True
        self.last_canvas_x = cx
        self.last_canvas_y = cy
        self.draw_segments_overlay()

    def on_left_up(self, _event):
        if self.drag_mode is not None and self._drag_changed and self._drag_start_snapshot is not None:
            self._push_undo_snapshot(self._drag_start_snapshot)
        self.drag_mode = None
        self._drag_start_snapshot = None
        self._drag_changed = False

    def on_right_click_add(self, event):
        if self.doc is None:
            return
        cx, cy = self._event_canvas_xy(event)
        self.add_line_at(cx, cy)

    def add_line_at(self, cx, cy):
        px, py = self._canvas_to_pdf(cx, cy)
        page = self.doc[self.page_index]
        h = float(page.rect.height)
        half = 25.0
        seg = {
            "page": int(self.page_index),
            "x": float(px),
            "y0": max(0.0, py - half),
            "y1": min(h, py + half),
            "draw_start_cap": False,
            "draw_end_cap": False,
            "is_stub": False,
            "break_merge_before": False,
            "break_merge_after": False,
        }
        self._clamp_segment(seg)
        segs = self._get_page_segments()
        self._push_undo_state()
        segs.append(seg)
        self.selected_idx = len(segs) - 1
        self.drag_mode = None
        self.draw_segments_overlay()
        self.set_status("Garis baru ditambahkan.")

    def enable_create_mode(self):
        if self.doc is None:
            return
        self._set_create_mode(True)
        self.set_status("Mode tambah aktif: klik kiri di posisi garis baru.")

    def delete_selected(self):
        if self.doc is None:
            return
        segs = self._get_page_segments()
        if self.selected_idx is None:
            return
        if not (0 <= self.selected_idx < len(segs)):
            return
        self._push_undo_state()
        del segs[self.selected_idx]
        self.selected_idx = None
        self.drag_mode = None
        self.draw_segments_overlay()
        self.set_status("Garis terpilih dihapus.")

    def merge_align_segments(self):
        if self.doc is None:
            return

        all_segments = []
        for page_no, segs in self.segments_by_page.items():
            for seg in segs:
                row = self._normalize_segment(seg, page_no)
                row["page"] = int(page_no)
                all_segments.append(row)

        if not all_segments:
            return

        merged = ga.postprocess_segments(all_segments)
        by_page = {}
        for seg in merged:
            p = int(seg.get("page", 0))
            by_page.setdefault(p, []).append(self._normalize_segment(seg, p))
        self._push_undo_state()
        self.segments_by_page = by_page
        self.selected_idx = None
        self.drag_mode = None
        self.render_page()
        self._refresh_all_thumbnail_overlays()
        self.set_status("Rapikan sejajar selesai.")

    def prev_page(self):
        if self.doc is None:
            return
        if self.page_index <= 0:
            return
        self.go_to_page(self.page_index - 1)

    def next_page(self):
        if self.doc is None:
            return
        if self.page_index >= self.doc.page_count - 1:
            return
        self.go_to_page(self.page_index + 1)

    def fit_page(self):
        if self.doc is None:
            return

        page = self.doc[self.page_index]
        canvas_w = max(1, int(self.canvas.winfo_width()))
        canvas_h = max(1, int(self.canvas.winfo_height()))
        pad = 80

        avail_w = max(100.0, float(canvas_w - pad))
        avail_h = max(100.0, float(canvas_h - pad))
        page_w = max(1.0, float(page.rect.width))
        page_h = max(1.0, float(page.rect.height))

        self.zoom = min(avail_w / page_w, avail_h / page_h)
        self.zoom = max(0.4, min(3.0, self.zoom))
        self.render_page()
        self.set_status(f"Fit page aktif: {self.zoom:.2f}x")

    def adjust_zoom(self, delta):
        if self.doc is None:
            return
        self.zoom = max(0.4, min(3.0, self.zoom + delta))
        self.render_page()
        self.set_status(f"Zoom: {self.zoom:.2f}x")

    def on_mousewheel(self, event):
        direction = -1 if event.delta > 0 else 1
        self.scroll_or_flip_page(direction)

    def on_shift_mousewheel(self, event):
        step = -1 if event.delta > 0 else 1
        self.canvas.xview_scroll(step, "units")

    def on_wheel_up_linux(self, _event):
        self.scroll_or_flip_page(-1)

    def on_wheel_down_linux(self, _event):
        self.scroll_or_flip_page(1)

    def scroll_or_flip_page(self, direction):
        if self.doc is None:
            return

        top, bottom = self.canvas.yview()
        if direction < 0:
            if top <= 0.0005 and self.page_index > 0:
                self.prev_page()
                self.canvas.yview_moveto(1.0)
                self.set_status("Pindah halaman (scroll ke atas).")
            else:
                self.canvas.yview_scroll(-3, "units")
        else:
            if bottom >= 0.9995 and self.page_index < (self.doc.page_count - 1):
                self.next_page()
                self.canvas.yview_moveto(0.0)
                self.set_status("Pindah halaman (scroll ke bawah).")
            else:
                self.canvas.yview_scroll(3, "units")

    def nudge_selected(self, dx, dy):
        if self.doc is None:
            return
        if self.selected_idx is None:
            return

        segs = self._get_page_segments()
        if not (0 <= self.selected_idx < len(segs)):
            return

        seg = segs[self.selected_idx]
        self._push_undo_state()
        seg["x"] = float(seg["x"]) + float(dx)
        seg["y0"] = float(seg["y0"]) + float(dy)
        seg["y1"] = float(seg["y1"]) + float(dy)
        self._clamp_segment(seg)
        self.draw_segments_overlay()
        self.set_status(f"Garis digeser: dx={dx:.1f}, dy={dy:.1f}")

    def save_project(self):
        if self.doc is None:
            return

        default_name = "garis_project.json"
        if self.pdf_path:
            base = os.path.splitext(os.path.basename(self.pdf_path))[0]
            default_name = f"{base}_garis_project.json"

        path = filedialog.asksaveasfilename(
            title="Simpan Project Editor",
            defaultextension=".json",
            initialfile=default_name,
            filetypes=[("JSON files", "*.json"), ("All files", "*.*")],
        )
        if not path:
            return

        payload = {
            "pdf_path": self.pdf_path,
            "end_fill_settings": elf.normalize_settings(getattr(self, "end_fill_settings", None)),
            "model_file": self.model_file,
            "booklet_layout": ({
                "source_pdf_path": self.booklet_source_pdf_path,
                "pairs": self.booklet_layout_pairs,
            } if self.booklet_mode_active else None),
            "threshold": self.threshold,
            "line_color": self.line_color_name,
            "cap_top_dx": float(ga.CAP_TOP_DX),
            "cap_top_dy": float(ga.CAP_TOP_DY),
            "cap_bottom_dx": float(ga.CAP_BOTTOM_DX),
            "cap_bottom_dy": float(ga.CAP_BOTTOM_DY),
            "cap_cross_enabled": bool(getattr(self, "cap_cross_enabled", True)),
            "cap_cross_back_ratio": float(getattr(self, "cap_cross_back_ratio", 0.16)),
            "zoom": self.zoom,
            "page_index": self.page_index,
            "segments_by_page": {
                str(p): [self._normalize_segment(seg, p) for seg in segs]
                for p, segs in self.segments_by_page.items()
            },
        }
        with open(path, "w", encoding="utf-8") as f:
            json.dump(payload, f, ensure_ascii=False, indent=2)
        self.set_status(f"Project tersimpan: {path}")

    def load_project(self):
        path = filedialog.askopenfilename(
            title="Load Project Editor",
            filetypes=[("JSON files", "*.json"), ("All files", "*.*")],
        )
        if not path:
            return

        with open(path, "r", encoding="utf-8") as f:
            payload = json.load(f)

        project_pdf = payload.get("pdf_path")
        if project_pdf and not os.path.isabs(project_pdf):
            project_pdf = os.path.join(os.path.dirname(path), project_pdf)
        if not project_pdf or not os.path.exists(project_pdf):
            project_pdf = filedialog.askopenfilename(
                title="PDF project tidak ditemukan, pilih PDF manual",
                filetypes=[("PDF files", "*.pdf"), ("All files", "*.*")],
            )
            if not project_pdf:
                return

        self.load_pdf(project_pdf, run_auto_detect=False)

        loaded = {}
        raw = payload.get("segments_by_page", {})
        for k, segs in raw.items():
            try:
                p = int(k)
            except Exception:
                continue
            loaded[p] = [self._normalize_segment(seg, p) for seg in segs]

        loaded_model = payload.get("model_file")
        if loaded_model:
            self.model_file = resolve_model_path(loaded_model)
        loaded_color = payload.get("line_color")
        if loaded_color:
            self.apply_line_color(loaded_color)
        if "cap_cross_enabled" in payload:
            self.cap_cross_enabled = bool(payload.get("cap_cross_enabled"))
        try:
            if "cap_cross_back_ratio" in payload:
                ratio = float(payload.get("cap_cross_back_ratio"))
                self.cap_cross_back_ratio = max(0.0, min(0.45, ratio))
        except Exception:
            pass
        try:
            top_dx = float(payload.get("cap_top_dx", ga.CAP_TOP_DX))
            top_dy = float(payload.get("cap_top_dy", ga.CAP_TOP_DY))
            bottom_dx = float(payload.get("cap_bottom_dx", ga.CAP_BOTTOM_DX))
            bottom_dy = float(payload.get("cap_bottom_dy", ga.CAP_BOTTOM_DY))
            if top_dx > 0 and bottom_dx > 0:
                ga.CAP_TOP_DX = top_dx
                ga.CAP_TOP_DY = top_dy
                ga.CAP_BOTTOM_DX = bottom_dx
                ga.CAP_BOTTOM_DY = bottom_dy
        except Exception:
            pass

        self.segments_by_page = loaded
        self.end_fill_settings = elf.normalize_settings(payload.get("end_fill_settings"))
        layout = payload.get("booklet_layout")
        if isinstance(layout, dict):
            source = str(layout.get("source_pdf_path") or "")
            if source and not os.path.isabs(source):
                source = os.path.join(os.path.dirname(path), source)
            try:
                with fitz.open(source) as original:
                    pairs = self._build_booklet_layout_pairs(original.page_count)
                    if layout.get("pairs") != [list(pair) for pair in pairs] or len(pairs) != self.doc.page_count:
                        raise ValueError("Susunan booklet tidak sesuai dokumen asli.")
                    self.booklet_source_page_count = original.page_count
                    self.booklet_source_page_w = original[0].rect.width
                    self.booklet_source_page_h = original[0].rect.height
                self.booklet_layout_pairs = pairs
                self.booklet_mode_active = True
                self.booklet_source_pdf_path = os.path.abspath(source)
                self.booklet_preview_pdf_path = self.pdf_path
                self.booklet_editor_snapshot_source = self.booklet_source_pdf_path
                self.booklet_editor_snapshot = {
                    "segments_by_page": self._project_booklet_segments_to_source(loaded),
                    "end_fill_settings": dict(self.end_fill_settings),
                    "page_index": 0,
                }
                self._refresh_booklet_button_text()
            except Exception:
                self.end_fill_settings["enabled"] = False
                messagebox.showwarning("Project booklet", "Dokumen asli booklet tidak ditemukan atau tidak sesuai.\nPengisi ---- dinonaktifkan; buka kembali PDF asli untuk membuat pengisi.", parent=self.root)
        self.page_index = int(payload.get("page_index", 0))
        self.page_index = max(0, min(self.page_index, self.doc.page_count - 1))
        self.zoom = float(payload.get("zoom", self.zoom))
        self.zoom = max(0.4, min(3.0, self.zoom))

        self.selected_idx = None
        self.drag_mode = None
        self._set_create_mode(False)
        self._reset_history()
        self.render_page()
        self._refresh_all_thumbnail_overlays()
        self.set_status(f"Project loaded: {path}")

    def _apply_manual_lines_to_doc(self, target_doc):
        if target_doc is None:
            return
        for p in range(int(target_doc.page_count)):
            page = target_doc[p]
            elf.draw_page_leaders(page, self._get_end_fill_rows(p), ga.LINE_COLOR)
            segs = self.segments_by_page.get(p, [])
            for seg in segs:
                x = float(seg["x"])
                y0_line, y1_line, y0_cap, y1_cap = self._segment_line_and_cap_anchors(seg, p)
                page.draw_line(
                    p1=(x, y0_line),
                    p2=(x, y1_line),
                    color=ga.LINE_COLOR,
                    width=ga.LINE_WIDTH,
                )

                if ga.ENABLE_START_END_CAPS and bool(seg.get("draw_start_cap", False)):
                    sx0, sy0, sx1, sy1 = self._cap_cross_line_points(
                        x,
                        y0_cap,
                        ga.CAP_SIDE * ga.CAP_TOP_DX,
                        ga.CAP_TOP_DY,
                    )
                    page.draw_line(
                        p1=(sx0, sy0),
                        p2=(sx1, sy1),
                        color=ga.LINE_COLOR,
                        width=ga.LINE_WIDTH,
                    )

                if ga.ENABLE_START_END_CAPS and bool(seg.get("draw_end_cap", False)):
                    ex0, ey0, ex1, ey1 = self._cap_cross_line_points(
                        x,
                        y1_cap,
                        ga.CAP_SIDE * ga.CAP_BOTTOM_DX,
                        -ga.CAP_BOTTOM_DY,
                    )
                    page.draw_line(
                        p1=(ex0, ey0),
                        p2=(ex1, ey1),
                        color=ga.LINE_COLOR,
                        width=ga.LINE_WIDTH,
                    )

    def export_pdf(self):
        if self.doc is None or not self.pdf_path:
            return

        base = self._get_loaded_document_base_name()
        default_name = f"{base}.pdf"
        out_path = filedialog.asksaveasfilename(
            title="Export PDF dengan garis manual",
            defaultextension=".pdf",
            initialfile=default_name,
            filetypes=[("PDF files", "*.pdf"), ("All files", "*.*")],
        )
        if not out_path:
            return

        out_doc = fitz.open(self.pdf_path)
        try:
            self._apply_manual_lines_to_doc(out_doc)
            if os.path.exists(out_path):
                os.remove(out_path)
            out_doc.save(out_path)
        finally:
            out_doc.close()

        self.set_status(f"Export selesai: {out_path}")
        messagebox.showinfo("Sukses", f"PDF berhasil diexport:\n{out_path}")

    def open_compress_pdf_dialog(self):
        if self.doc is None or not self.pdf_path:
            messagebox.showinfo("Compress PDF", "Buka dokumen terlebih dahulu.")
            return

        source_size_mb = 0.0
        try:
            source_size_mb = float(os.path.getsize(self.pdf_path)) / (1024.0 * 1024.0)
        except Exception:
            source_size_mb = 0.0
        suggested_target = round(max(0.3, source_size_mb * 0.6), 2) if source_size_mb > 0 else 5.0

        target_mb = simpledialog.askfloat(
            "Compress PDF",
            "Target ukuran output (MB):",
            parent=self.root,
            minvalue=0.1,
            initialvalue=suggested_target,
        )
        if target_mb is None:
            return
        target_mb = float(target_mb)
        if target_mb <= 0:
            messagebox.showerror("Compress PDF", "Target ukuran harus lebih dari 0 MB.")
            return

        base = self._get_loaded_document_base_name()
        out_path = filedialog.asksaveasfilename(
            title="Simpan PDF hasil compress",
            defaultextension=".pdf",
            initialfile=f"{base}_compressed.pdf",
            filetypes=[("PDF files", "*.pdf"), ("All files", "*.*")],
        )
        if not out_path:
            return

        try:
            result = self._run_with_loading(
                "Compress PDF...",
                self._compress_current_pdf_to_target,
                out_path,
                target_mb,
            )
        except Exception as exc:
            messagebox.showerror("Compress PDF", str(exc))
            self.set_status(f"Gagal compress PDF: {exc}")
            return

        actual_mb = float(result.get("final_size_bytes", 0)) / (1024.0 * 1024.0)
        target_txt = f"{float(result.get('target_mb', target_mb)):.2f} MB"
        actual_txt = f"{actual_mb:.2f} MB"
        method_txt = str(result.get("method", "auto")).strip()
        achieved = bool(result.get("target_achieved", False))

        if achieved:
            messagebox.showinfo(
                "Compress PDF",
                f"Berhasil compress PDF.\n\nTarget: {target_txt}\nHasil: {actual_txt}\nMetode: {method_txt}\n\nFile: {out_path}",
            )
        else:
            messagebox.showwarning(
                "Compress PDF",
                "Target ukuran belum tercapai, tetapi hasil paling kecil sudah dibuat.\n\n"
                f"Target: {target_txt}\nHasil: {actual_txt}\nMetode: {method_txt}\n\nFile: {out_path}",
            )

        self.set_status(f"Compress selesai: {actual_txt} (target {target_txt})")

    def _save_doc_optimized(self, doc_obj, out_path):
        if os.path.exists(out_path):
            os.remove(out_path)

        kwargs = {
            "garbage": 4,
            "clean": 1,
            "deflate": 1,
            "deflate_images": 1,
            "deflate_fonts": 1,
            "use_objstms": 1,
            "compression_effort": 9,
        }
        try:
            doc_obj.save(out_path, **kwargs)
            return
        except TypeError:
            pass

        fallback_kwargs = {
            "garbage": 4,
            "clean": 1,
            "deflate": 1,
            "deflate_images": 1,
            "deflate_fonts": 1,
        }
        doc_obj.save(out_path, **fallback_kwargs)

    def _render_rasterized_pdf(self, src_doc, out_path, zoom_scale, jpg_quality):
        page_count = int(src_doc.page_count)
        render_doc = fitz.open()
        try:
            for i in range(page_count):
                page = src_doc[i]
                pix = page.get_pixmap(matrix=fitz.Matrix(float(zoom_scale), float(zoom_scale)), alpha=False)
                jpg_bytes = pix.tobytes("jpg", jpg_quality=int(jpg_quality))
                new_page = render_doc.new_page(width=float(page.rect.width), height=float(page.rect.height))
                new_page.insert_image(new_page.rect, stream=jpg_bytes)
                if i == 0 or (i + 1) == page_count or (i + 1) % 4 == 0:
                    self._update_loading_dialog(
                        detail=f"Rasterisasi halaman {i + 1}/{page_count} (q={jpg_quality}, zoom={zoom_scale:.2f})"
                    )
            self._save_doc_optimized(render_doc, out_path)
        finally:
            render_doc.close()

    def _compress_current_pdf_to_target(self, out_path, target_mb):
        target_mb = float(target_mb)
        if target_mb <= 0:
            raise RuntimeError("Target ukuran tidak valid.")
        target_bytes = int(target_mb * 1024.0 * 1024.0)
        if target_bytes <= 0:
            raise RuntimeError("Target ukuran terlalu kecil.")

        started_at = time.time()
        time_budget_sec = 180.0
        tmp_dir = tempfile.mkdtemp(prefix="garis_compress_")
        self._register_temp_dir(tmp_dir)

        source_doc = fitz.open(self.pdf_path)
        try:
            self._apply_manual_lines_to_doc(source_doc)
            page_count = max(1, int(source_doc.page_count))

            candidates = []
            self._update_loading_dialog(detail="Mencoba kompresi lossless...")
            lossless_path = os.path.join(tmp_dir, "lossless.pdf")
            self._save_doc_optimized(source_doc, lossless_path)
            lossless_size = os.path.getsize(lossless_path)
            best_smallest = {
                "path": lossless_path,
                "size": int(lossless_size),
                "method": "lossless",
            }
            candidates.append(dict(best_smallest))
            best_under = dict(best_smallest) if int(lossless_size) <= target_bytes else None

            if best_under is None:
                if page_count >= 240:
                    scales = [1.00, 0.90]
                    qualities = [65, 50, 40, 32, 26]
                elif page_count >= 120:
                    scales = [1.10, 1.00, 0.90]
                    qualities = [75, 60, 48, 38, 30]
                else:
                    scales = [1.20, 1.05, 0.90]
                    qualities = [85, 70, 58, 45, 35, 28]

                total_tests = len(qualities) * len(scales)
                test_no = 0
                stop_early = False
                for scale in scales:
                    if stop_early:
                        break
                    for quality in qualities:
                        elapsed = float(time.time() - started_at)
                        if elapsed >= time_budget_sec:
                            self._update_loading_dialog(
                                detail=(
                                    "Batas waktu compress tercapai. "
                                    "Menyimpan kandidat terbaik saat ini..."
                                )
                            )
                            stop_early = True
                            break

                        test_no += 1
                        self._update_loading_dialog(
                            detail=f"Kompresi lanjutan {test_no}/{total_tests} (q={quality}, zoom={scale:.2f})"
                        )
                        c_path = os.path.join(tmp_dir, f"raster_z{int(scale * 100)}_q{quality}.pdf")
                        try:
                            self._render_rasterized_pdf(source_doc, c_path, scale, quality)
                            c_size = os.path.getsize(c_path)
                        except Exception as exc:
                            self._update_loading_dialog(
                                detail=f"Kandidat q={quality} zoom={scale:.2f} gagal: {exc}"
                            )
                            continue

                        row = {
                            "path": c_path,
                            "size": int(c_size),
                            "method": f"raster q={quality} zoom={scale:.2f}",
                        }
                        candidates.append(row)
                        if int(row["size"]) < int(best_smallest["size"]):
                            best_smallest = row
                        if int(row["size"]) <= target_bytes:
                            # Sudah memenuhi target. Stop agar proses tidak lama.
                            best_under = row
                            stop_early = True
                            break
        finally:
            source_doc.close()

        if not candidates:
            raise RuntimeError("Tidak ada kandidat hasil compress.")

        if best_under is not None:
            best = best_under
            target_achieved = True
        else:
            best = best_smallest
            target_achieved = False

        if os.path.exists(out_path):
            os.remove(out_path)
        shutil.copy2(str(best["path"]), out_path)

        return {
            "target_mb": target_mb,
            "target_bytes": int(target_bytes),
            "final_size_bytes": int(best["size"]),
            "method": str(best["method"]),
            "target_achieved": bool(target_achieved),
        }

    def _build_print_ready_pdf(self):
        if self.doc is None or not self.pdf_path:
            raise RuntimeError("Tidak ada dokumen yang bisa dicetak.")

        base = os.path.splitext(os.path.basename(self.pdf_path))[0] or "dokumen"
        tmp_dir = tempfile.mkdtemp(prefix="garis_print_")
        tmp_pdf = os.path.join(tmp_dir, f"{base}_print.pdf")

        out_doc = fitz.open(self.pdf_path)
        try:
            self._apply_manual_lines_to_doc(out_doc)
            out_doc.save(tmp_pdf)
        finally:
            out_doc.close()

        self._register_temp_dir(tmp_dir)
        return tmp_pdf

    def _dispatch_print_windows(self, pdf_path):
        if not sys.platform.startswith("win"):
            raise RuntimeError("Fitur print saat ini hanya didukung di Windows.")
        if not os.path.exists(pdf_path):
            raise RuntimeError("File sementara untuk print tidak ditemukan.")

        errors = []

        # 0) Prioritas: tampilkan dialog print (bukan silent print).
        acrobat_exe = ""
        try:
            import winreg  # type: ignore

            reg_candidates = [
                r"Acrobat.Document.DC\shell\open\command",
                r"AcroExch.Document.DC\shell\open\command",
                r"AcroExch.Document\shell\open\command",
                r"Applications\AcroRd32.exe\shell\open\command",
            ]
            for subkey in reg_candidates:
                try:
                    with winreg.OpenKey(winreg.HKEY_CLASSES_ROOT, subkey) as key:
                        cmd = str(winreg.QueryValueEx(key, "")[0])
                    if cmd.startswith("\"") and "\"" in cmd[1:]:
                        exe = cmd.split("\"", 2)[1]
                    else:
                        exe = cmd.split(" ", 1)[0]
                    exe = os.path.abspath(str(exe or "").strip().strip("\""))
                    if exe and os.path.exists(exe):
                        acrobat_exe = exe
                        break
                except Exception:
                    continue
        except Exception as exc:
            errors.append(f"Reg Acrobat lookup: {exc}")

        if acrobat_exe:
            try:
                startupinfo = None
                if hasattr(subprocess, "STARTUPINFO"):
                    startupinfo = subprocess.STARTUPINFO()
                    startupinfo.dwFlags |= subprocess.STARTF_USESHOWWINDOW
                    startupinfo.wShowWindow = 0
                subprocess.Popen(
                    [acrobat_exe, "/p", os.path.abspath(pdf_path)],
                    startupinfo=startupinfo,
                )
                return "acrobat:/p-dialog"
            except Exception as exc:
                errors.append(f"Acrobat /p: {exc}")

        # 0b) Fallback dialog: buka viewer default lalu trigger Ctrl+P.
        try:
            startupinfo = None
            if hasattr(subprocess, "STARTUPINFO"):
                startupinfo = subprocess.STARTUPINFO()
                startupinfo.dwFlags |= subprocess.STARTF_USESHOWWINDOW
                startupinfo.wShowWindow = 0
            src = os.path.abspath(pdf_path).replace("'", "''")
            ps_script = (
                "$ErrorActionPreference='Stop';"
                f"$p=Start-Process -FilePath '{src}' -PassThru;"
                "$ws=New-Object -ComObject WScript.Shell;"
                "$limit=(Get-Date).AddSeconds(8);"
                "while((Get-Date) -lt $limit){"
                "Start-Sleep -Milliseconds 350;"
                "if($p.HasExited){break};"
                "if($ws.AppActivate($p.Id)){Start-Sleep -Milliseconds 250;$ws.SendKeys('^p');exit 0};"
                "};"
                "throw 'Gagal memunculkan dialog print dari viewer default.';"
            )
            subprocess.run(
                ["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-Command", ps_script],
                check=True,
                capture_output=True,
                text=True,
                timeout=20,
                startupinfo=startupinfo,
            )
            return "viewer-dialog:ctrl+p"
        except Exception as exc:
            errors.append(f"Viewer open + Ctrl+P: {exc}")

        try:
            os.startfile(pdf_path, "print")
            return "startfile:print"
        except Exception as exc:
            errors.append(f"os.startfile(print): {exc}")

        try:
            import win32api  # type: ignore

            rc = win32api.ShellExecute(0, "print", pdf_path, None, os.path.dirname(pdf_path), 0)
            if int(rc) <= 32:
                raise RuntimeError(f"ShellExecute return code {rc}")
            return "shell:print"
        except Exception as exc:
            errors.append(f"ShellExecute(print): {exc}")

        printer_name = ""
        printer_driver = ""
        printer_port = ""
        try:
            import win32api  # type: ignore
            import win32print  # type: ignore

            printer_name = str(win32print.GetDefaultPrinter() or "").strip()
            if printer_name:
                try:
                    h_printer = win32print.OpenPrinter(printer_name)
                    try:
                        info = win32print.GetPrinter(h_printer, 2) or {}
                    finally:
                        win32print.ClosePrinter(h_printer)
                    printer_driver = str(info.get("pDriverName", "") or "").strip()
                    printer_port = str(info.get("pPortName", "") or "").strip()
                except Exception:
                    pass

                rc = win32api.ShellExecute(
                    0,
                    "printto",
                    pdf_path,
                    f"\"{printer_name}\"",
                    os.path.dirname(pdf_path),
                    0,
                )
                if int(rc) <= 32:
                    raise RuntimeError(f"ShellExecute return code {rc}")
                return "shell:printto"
        except Exception as exc:
            errors.append(f"ShellExecute(printto): {exc}")

        # Fallback terakhir: panggil Acrobat secara langsung.
        candidates = [
            r"C:\\Program Files\\Adobe\\Acrobat DC\\Acrobat\\Acrobat.exe",
            r"C:\\Program Files (x86)\\Adobe\\Acrobat DC\\Acrobat\\Acrobat.exe",
            r"C:\\Program Files\\Adobe\\Acrobat Reader DC\\Reader\\AcroRd32.exe",
            r"C:\\Program Files (x86)\\Adobe\\Acrobat Reader DC\\Reader\\AcroRd32.exe",
        ]
        try:
            import winreg  # type: ignore

            with winreg.OpenKey(winreg.HKEY_CLASSES_ROOT, r"Acrobat.Document.DC\\shell\\open\\command") as key:
                cmd = str(winreg.QueryValueEx(key, "")[0])
                if cmd.startswith("\"") and "\"" in cmd[1:]:
                    exe = cmd.split("\"", 2)[1]
                else:
                    exe = cmd.split(" ", 1)[0]
                if exe:
                    candidates.insert(0, exe)
        except Exception:
            pass

        seen = set()
        for exe in candidates:
            exe_path = os.path.abspath(str(exe or "").strip().strip("\""))
            if not exe_path:
                continue
            key = exe_path.lower()
            if key in seen:
                continue
            seen.add(key)
            if not os.path.exists(exe_path):
                continue
            if not printer_name:
                continue
            args = [exe_path, "/N", "/T", os.path.abspath(pdf_path), printer_name]
            if printer_driver:
                args.append(printer_driver)
            if printer_port:
                args.append(printer_port)
            try:
                startupinfo = None
                if hasattr(subprocess, "STARTUPINFO"):
                    startupinfo = subprocess.STARTUPINFO()
                    startupinfo.dwFlags |= subprocess.STARTF_USESHOWWINDOW
                    startupinfo.wShowWindow = 0
                subprocess.run(
                    args,
                    check=True,
                    capture_output=True,
                    text=True,
                    timeout=45,
                    startupinfo=startupinfo,
                )
                return f"acrobat:{os.path.basename(exe_path)}"
            except Exception as exc:
                errors.append(f"Acrobat /T ({exe_path}): {exc}")

        raise RuntimeError(" ; ".join(errors[:4]) or "Tidak menemukan metode print yang kompatibel.")

    def print_document(self):
        if self.doc is None or not self.pdf_path:
            messagebox.showinfo("Print", "Buka dokumen terlebih dahulu sebelum print.")
            return
        if not sys.platform.startswith("win"):
            messagebox.showerror("Print", "Fitur print saat ini hanya didukung di Windows.")
            return

        tmp_pdf = None
        try:
            tmp_pdf = self._run_with_loading(
                "Menyiapkan dokumen untuk print...",
                self._build_print_ready_pdf,
            )
            method = self._dispatch_print_windows(tmp_pdf)
        except Exception as exc:
            # Fallback UX: minimal buka PDF siap cetak agar user bisa Ctrl+P manual.
            opened = False
            try:
                if tmp_pdf and os.path.exists(tmp_pdf):
                    os.startfile(tmp_pdf)
                    opened = True
            except Exception:
                opened = False
            if opened:
                messagebox.showwarning(
                    "Print Otomatis Gagal",
                    "Perintah print otomatis gagal, dokumen siap-cetak sudah dibuka.\n"
                    "Silakan tekan Ctrl+P di viewer PDF.\n\n"
                    f"Detail: {exc}",
                )
                self.set_status("Print otomatis gagal, fallback ke buka PDF siap-cetak.")
            else:
                messagebox.showerror("Print gagal", str(exc))
                self.set_status(f"Gagal print: {exc}")
            return

        if "dialog" in str(method):
            self.set_status("Dialog print sudah dibuka.")
        else:
            self.set_status(f"Perintah print dikirim ke printer default ({method}).")

    def _convert_pdf_to_docx(self, pdf_path, docx_path):
        if not os.path.exists(pdf_path):
            raise RuntimeError("File PDF sumber tidak ditemukan untuk konversi DOCX.")

        out_abs = os.path.abspath(docx_path)
        out_dir = os.path.dirname(out_abs) or os.getcwd()
        os.makedirs(out_dir, exist_ok=True)
        errors = []

        # 1) pdf2docx (jika terpasang)
        try:
            from pdf2docx import Converter  # type: ignore

            cv = Converter(os.path.abspath(pdf_path))
            try:
                cv.convert(out_abs)
            finally:
                cv.close()
            if os.path.exists(out_abs) and os.path.getsize(out_abs) > 0:
                return out_abs
        except Exception as exc:
            errors.append(f"pdf2docx: {exc}")

        # 2) Word COM via PowerShell (Windows)
        if sys.platform.startswith("win"):
            try:
                src = os.path.abspath(pdf_path).replace("'", "''")
                dst = out_abs.replace("'", "''")
                ps_script = (
                    "$ErrorActionPreference='Stop';"
                    "$word=New-Object -ComObject Word.Application;"
                    "$word.Visible=$false;"
                    "$word.DisplayAlerts=0;"
                    f"$doc=$word.Documents.Open('{src}',$false,$true);"
                    f"$doc.SaveAs2('{dst}',16);"
                    "$doc.Close($false);"
                    "$word.Quit();"
                )
                subprocess.run(
                    ["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-Command", ps_script],
                    check=True,
                    capture_output=True,
                    text=True,
                )
                if os.path.exists(out_abs) and os.path.getsize(out_abs) > 0:
                    return out_abs
            except Exception as exc:
                errors.append(f"Word COM PowerShell: {exc}")

        # 3) Word COM pywin32
        try:
            import win32com.client  # type: ignore

            word = win32com.client.Dispatch("Word.Application")
            word.Visible = False
            word.DisplayAlerts = 0
            doc = word.Documents.Open(os.path.abspath(pdf_path), False, True)
            doc.SaveAs2(out_abs, FileFormat=16)
            doc.Close(False)
            word.Quit()
            if os.path.exists(out_abs) and os.path.getsize(out_abs) > 0:
                return out_abs
        except Exception as exc:
            errors.append(f"Word COM pywin32: {exc}")

        detail = ""
        if errors:
            detail = "\n\nDetail:\n- " + "\n- ".join([str(e)[:180] for e in errors[:4]])
        raise RuntimeError(
            "Tidak ada engine konversi PDF->DOCX yang tersedia.\n"
            "Install salah satu: pdf2docx atau Microsoft Word."
            + detail
        )

    def export_docx(self):
        messagebox.showinfo(
            "Informasi",
            "Fitur Save DOCX dinonaktifkan untuk menjaga layout dokumen tetap rapi.\n"
            "Gunakan Save PDF untuk hasil final.",
        )
        self.set_status("Save DOCX dinonaktifkan. Gunakan Save PDF.")

    def _debooklet_scan_pdf(
        self,
        input_pdf_path,
        output_pdf_path,
        split_direction="auto",
        rotate_degrees=0,
        swap_sides=False,
        reverse_spreads=False,
        force_portrait=True,
    ):
        src_doc = fitz.open(input_pdf_path)
        out_doc = fitz.open()
        try:
            spread_count = int(src_doc.page_count)
            if spread_count <= 0:
                raise RuntimeError("PDF scan tidak memiliki halaman.")

            first_rect = src_doc[0].rect
            first_w = float(first_rect.width)
            first_h = float(first_rect.height)
            split_mode = str(split_direction or "auto").strip().lower()
            if split_mode == "vertical":
                split_vertical = True
            elif split_mode == "horizontal":
                split_vertical = False
            else:
                split_vertical = first_w >= first_h
            if split_vertical:
                page_w = first_w / 2.0
                page_h = first_h
            else:
                page_w = first_w
                page_h = first_h / 2.0

            if page_w <= 1.0 or page_h <= 1.0:
                raise RuntimeError("Ukuran halaman PDF scan tidak valid.")

            rotate = int(rotate_degrees or 0) % 360
            if rotate not in (0, 90, 180, 270):
                rotate = 0

            total_leaf_pages = spread_count * 2
            layout_pairs = self._build_booklet_layout_pairs(total_leaf_pages)
            page_streams = [None] * total_leaf_pages

            for spread_no, pair in enumerate(layout_pairs):
                if spread_no >= spread_count:
                    break

                source_spread_no = (spread_count - 1 - spread_no) if bool(reverse_spreads) else spread_no
                page = src_doc[source_spread_no]
                rect = page.rect
                w = float(rect.width)
                h = float(rect.height)
                if split_vertical:
                    mid = w / 2.0
                    first_clip = fitz.Rect(0.0, 0.0, mid, h)
                    second_clip = fitz.Rect(mid, 0.0, w, h)
                else:
                    mid = h / 2.0
                    first_clip = fitz.Rect(0.0, 0.0, w, mid)
                    second_clip = fitz.Rect(0.0, mid, w, h)

                side_jobs = ((int(pair[0]), first_clip), (int(pair[1]), second_clip))
                if bool(swap_sides):
                    side_jobs = ((int(pair[0]), second_clip), (int(pair[1]), first_clip))

                for target_index, clip_rect in side_jobs:
                    if target_index < 0 or target_index >= total_leaf_pages:
                        continue
                    single_doc = fitz.open()
                    try:
                        out_w = page_w
                        out_h = page_h
                        if bool(force_portrait) and out_w > out_h:
                            out_w, out_h = out_h, out_w
                        new_page = single_doc.new_page(width=out_w, height=out_h)
                        new_page.show_pdf_page(
                            new_page.rect,
                            src_doc,
                            source_spread_no,
                            rotate=rotate,
                            clip=clip_rect,
                        )
                        page_streams[target_index] = single_doc.tobytes(garbage=4, deflate=True)
                    finally:
                        single_doc.close()

                if spread_no == 0 or (spread_no + 1) == spread_count or (spread_no + 1) % 4 == 0:
                    self._update_loading_dialog(
                        detail=f"Memotong dan mengurutkan spread {spread_no + 1}/{spread_count}"
                    )

            for page_no, stream in enumerate(page_streams):
                if stream:
                    part_doc = fitz.open(stream=stream, filetype="pdf")
                    try:
                        out_doc.insert_pdf(part_doc)
                    finally:
                        part_doc.close()
                else:
                    out_doc.new_page(width=page_w, height=page_h)

                if page_no == 0 or (page_no + 1) == total_leaf_pages or (page_no + 1) % 8 == 0:
                    self._update_loading_dialog(detail=f"Menyusun halaman A4 {page_no + 1}/{total_leaf_pages}")

            if os.path.exists(output_pdf_path):
                os.remove(output_pdf_path)
            out_doc.save(output_pdf_path, garbage=4, deflate=True)
        finally:
            out_doc.close()
            src_doc.close()

    def export_debooklet_scan_pdf(self):
        source_pdf_path = None
        if self.doc is not None and self.pdf_path:
            source_pdf_path = os.path.abspath(self.pdf_path)
        else:
            source_pdf_path = filedialog.askopenfilename(
                title="Pilih PDF scan A3 booklet",
                filetypes=[("PDF files", "*.pdf"), ("All files", "*.*")],
            )
            if not source_pdf_path:
                return
            source_pdf_path = os.path.abspath(source_pdf_path)

        base = os.path.splitext(os.path.basename(source_pdf_path))[0]
        default_name = f"{base}_a4_urut.pdf"
        out_path = filedialog.asksaveasfilename(
            title="Simpan PDF A4 urut",
            defaultextension=".pdf",
            initialfile=default_name,
            filetypes=[("PDF files", "*.pdf"), ("All files", "*.*")],
        )
        if not out_path:
            return
        out_path = os.path.abspath(out_path)

        if os.path.abspath(source_pdf_path) == os.path.abspath(out_path):
            messagebox.showerror("Scan A3 -> A4", "File output tidak boleh sama dengan file input.")
            return

        try:
            self._run_with_loading(
                "Membuat PDF A4 urut...",
                self._debooklet_scan_pdf,
                source_pdf_path,
                out_path,
            )
        except Exception as exc:
            messagebox.showerror("Scan A3 -> A4 gagal", str(exc))
            self.set_status(f"Gagal membuat PDF A4 urut: {exc}")
            return

        self.set_status(f"PDF A4 urut berhasil dibuat: {out_path}")
        open_now = messagebox.askyesno(
            "Scan A3 -> A4 selesai",
            f"PDF A4 urut berhasil dibuat:\n{out_path}\n\nBuka hasilnya sekarang?",
        )
        if open_now:
            try:
                self._run_with_loading("Membuka PDF A4 urut...", self.load_pdf, out_path, False)
            except Exception as exc:
                messagebox.showwarning(
                    "Scan A3 -> A4",
                    f"PDF berhasil dibuat tetapi gagal dibuka otomatis.\n\n{exc}\n\nFile:\n{out_path}",
                )

    def export_booklet_pdf(self):
        if self.doc is None or not self.pdf_path:
            return

        # Toggle: jika sedang melihat booklet preview, klik lagi untuk kembali ke dokumen asli.
        if self.booklet_mode_active:
            source_path = str(self.booklet_source_pdf_path or "").strip()
            if source_path and os.path.exists(source_path):
                snapshot = self.booklet_editor_snapshot if isinstance(self.booklet_editor_snapshot, dict) else None
                snapshot_source = str(self.booklet_editor_snapshot_source or "").strip()
                mapped_source_segments = self._project_booklet_segments_to_source(self.segments_by_page)
                if snapshot and isinstance(mapped_source_segments, dict):
                    updated_snapshot = dict(snapshot)
                    updated_snapshot["segments_by_page"] = mapped_source_segments
                    updated_snapshot["end_fill_settings"] = elf.normalize_settings(getattr(self, "end_fill_settings", None))
                    snapshot = updated_snapshot
                    self.booklet_editor_snapshot = updated_snapshot
                try:
                    self._run_with_loading("Kembali ke dokumen asli...", self.load_pdf, source_path, False, source_path)
                except Exception as exc:
                    messagebox.showerror("Booklet gagal", str(exc))
                    self.set_status(f"Gagal kembali ke dokumen asli: {exc}")
                    return
                if snapshot and os.path.abspath(source_path) == os.path.abspath(snapshot_source or source_path):
                    self._restore_editor_state(snapshot)
                self.set_status("Kembali ke dokumen asli.")
                return

            self.booklet_mode_active = False
            self.booklet_source_pdf_path = None
            self.booklet_preview_pdf_path = None
            self.booklet_editor_snapshot = None
            self.booklet_editor_snapshot_source = None
            self._refresh_booklet_button_text()
            messagebox.showwarning("Booklet", "Dokumen asli tidak ditemukan. Silakan buka ulang dokumen asli.")
            self.set_status("Dokumen asli booklet tidak ditemukan.")
            return

        source_pdf_path = os.path.abspath(self.pdf_path)
        self.booklet_editor_snapshot = self._snapshot_editor_state()
        self.booklet_editor_snapshot_source = source_pdf_path
        base = os.path.splitext(os.path.basename(source_pdf_path))[0]
        temp_dir = tempfile.mkdtemp(prefix="garis_booklet_")
        self._register_temp_dir(temp_dir)
        out_path = os.path.join(temp_dir, f"{base}_booklet_preview.pdf")

        def _build_booklet():
            src_doc = fitz.open(source_pdf_path)
            booklet_doc = fitz.open()
            try:
                total_pages = int(src_doc.page_count)
                if total_pages <= 0:
                    raise RuntimeError("Tidak ada halaman untuk dibuat booklet.")

                page_w = float(src_doc[0].rect.width)
                page_h = float(src_doc[0].rect.height)
                layout_pairs = self._build_booklet_layout_pairs(total_pages)
                self.booklet_layout_pairs = list(layout_pairs)
                self.booklet_source_page_count = int(total_pages)
                self.booklet_source_page_w = float(page_w)
                self.booklet_source_page_h = float(page_h)

                for left_idx, right_idx in layout_pairs:
                    new_page = booklet_doc.new_page(width=(page_w * 2.0), height=page_h)
                    left_rect = fitz.Rect(0.0, 0.0, page_w, page_h)
                    right_rect = fitz.Rect(page_w, 0.0, page_w * 2.0, page_h)

                    if 0 <= left_idx < total_pages:
                        new_page.show_pdf_page(left_rect, src_doc, int(left_idx))
                    if 0 <= right_idx < total_pages:
                        new_page.show_pdf_page(right_rect, src_doc, int(right_idx))

                if os.path.exists(out_path):
                    os.remove(out_path)
                booklet_doc.save(out_path)
            finally:
                booklet_doc.close()
                src_doc.close()

        try:
            self._run_with_loading("Membuat booklet...", _build_booklet)
        except Exception as exc:
            messagebox.showerror("Booklet gagal", str(exc))
            self.set_status(f"Gagal membuat booklet: {exc}")
            return

        # Langsung tampilkan hasil booklet di viewer yang sama.
        try:
            self._run_with_loading(
                "Membuka hasil booklet...",
                self.load_pdf,
                out_path,
                False,
                source_pdf_path,
            )
        except Exception as exc:
            messagebox.showwarning(
                "Booklet dibuat",
                f"Booklet berhasil dibuat tetapi gagal ditampilkan otomatis.\n\n{exc}\n\nFile:\n{out_path}",
            )
            self.set_status(f"Booklet selesai (gagal auto-tampil): {out_path}")
            return

        self.set_status("Mode booklet aktif. Klik Unbooklet untuk kembali ke dokumen asli.")

    def run(self):
        self.root.mainloop()


def parse_args():
    parser = argparse.ArgumentParser(description="Editor manual garis akta (drag & drop).")
    parser.add_argument("-i", "--input", default=None, help="PDF input yang ingin diedit.")
    parser.add_argument("--model-file", default="garis_local_model_bodyx_v9.json", help="Model local untuk auto detect.")
    parser.add_argument("--scan-model-file", default="scan_line_model_datatrain_v2.json", help="Model scan JSON untuk auto detect.")
    parser.add_argument("--scan-zoom", type=float, default=2.0, help="Zoom render untuk deteksi scan.")
    parser.add_argument("--outside-shift", type=float, default=4.0, help="Geser garis ke kiri (lebih luar).")
    parser.add_argument("--threshold", type=float, default=0.4, help="Threshold prediksi model local.")
    parser.add_argument("--line-color", choices=["Merah", "Hitam"], default="Hitam", help="Warna garis default.")
    parser.add_argument("--legacy-auto-detect", action="store_true", help="Pakai auto detect lama (garis_akta.detect_segments_auto).")
    parser.add_argument("--no-auto-detect", action="store_true", help="Jangan auto detect saat buka PDF.")
    parser.add_argument("--simanis-data", action="store_true", help="Buka pemilih data SIMANIS.")
    return parser.parse_args()


def main():
    args = parse_args()
    if args.simanis_data:
        close_boot_splash_if_any()
        from simanis_data import DataPicker
        root = tk.Tk()
        picker = DataPicker(root)
        try:
            root.mainloop()
        finally:
            if picker.api: picker.api.close()
        return 0
    app = GarisEditorApp(
        pdf_path=args.input,
        model_file=args.model_file,
        scan_model_file=args.scan_model_file,
        scan_zoom=args.scan_zoom,
        outside_shift=args.outside_shift,
        legacy_auto_detect=args.legacy_auto_detect,
        threshold=args.threshold,
        auto_detect=not args.no_auto_detect,
        line_color=args.line_color,
    )
    app.run()


if __name__ == "__main__":
    sys.exit(main())
