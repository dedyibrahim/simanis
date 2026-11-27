Attribute VB_Name = "Module1"
Option Explicit

' SIMANIS Word 0.5.1 - collapsed drawing anchors preserve document text.
' Only shapes named SIGANDI_W_* are managed. Original text is never replaced.
Private Const PREFIX As String = "SIGANDI_W_"
Private Const BODY As String = "SIGANDI_AREA_ISI"
Private mBusy As Boolean
Private mCancel As Boolean
Private mDocument As Document
Private mText As String
Private Const VBOOK As String = "SGNDV_"
Private Const TBOOK As String = "SGNDT_"
Private mAnchors As Object
Private mRibbon As Object
Private mStrokeReady As Boolean
Private mStrokeColor As Long
Private mStrokeWeight As Single
Private mTiming As String
Private mFillCanvases As Object
#If VBA7 Then
    Private Declare PtrSafe Sub Sleep Lib "kernel32" (ByVal milliseconds As Long)
    Private Declare PtrSafe Function GetDC Lib "user32" (ByVal hwnd As LongPtr) As LongPtr
    Private Declare PtrSafe Function ReleaseDC Lib "user32" (ByVal hwnd As LongPtr, ByVal dc As LongPtr) As Long
    Private Declare PtrSafe Function CreateFontW Lib "gdi32" (ByVal height As Long, ByVal width As Long, ByVal angle As Long, ByVal orientation As Long, ByVal weight As Long, ByVal italic As Long, ByVal underline As Long, ByVal strike As Long, ByVal charset As Long, ByVal precision As Long, ByVal clip As Long, ByVal quality As Long, ByVal pitch As Long, ByVal face As LongPtr) As LongPtr
    Private Declare PtrSafe Function SelectObject Lib "gdi32" (ByVal dc As LongPtr, ByVal obj As LongPtr) As LongPtr
    Private Declare PtrSafe Function DeleteObject Lib "gdi32" (ByVal obj As LongPtr) As Long
    Private Declare PtrSafe Function GetTextExtentPoint32W Lib "gdi32" (ByVal dc As LongPtr, ByVal text As LongPtr, ByVal length As Long, ByRef size As TextExtent) As Long
#Else
    Private Declare Sub Sleep Lib "kernel32" (ByVal milliseconds As Long)
#End If
Private Type TextExtent
    cx As Long
    cy As Long
End Type

Public Function SigandiVersion() As String
    SigandiVersion = "SIMANIS Word 0.5.1 Preview"
End Function

Public Function SigandiTiming() As String
    SigandiTiming = mTiming
End Function

Private Sub PhaseTime(ByVal name As String, ByRef since As Single)
    Dim elapsed As Single
    elapsed = Timer - since
    If elapsed < 0 Then elapsed = elapsed + 86400
    mTiming = mTiming & name & "=" & Replace(Format$(elapsed, "0.000"), ",", ".") & ";"
    since = Timer
End Sub

Public Sub RibbonCancel(ByVal control As Object)
    mCancel = True
End Sub

Private Sub Progress(ByVal message As String)
    Application.StatusBar = message & " | Batalkan: tombol Batalkan Proses atau Ctrl+Break"
    Application.ScreenUpdating = True
    DoEvents
    If mCancel Then Err.Raise vbObjectError + 156, , "Proses dibatalkan. Garis yang sudah dibuat dapat dibatalkan dengan Ctrl+Z atau Hapus Garis SIMANIS."
    If Documents.Count = 0 Then Err.Raise vbObjectError + 157, , "Dokumen ditutup. Proses dihentikan."
    If Not (ActiveDocument Is mDocument) Then Err.Raise vbObjectError + 158, , "Dokumen aktif berubah. Proses dihentikan."
    If mDocument.Content.Text <> mText Then Err.Raise vbObjectError + 159, , "Teks berubah saat proses berlangsung. Proses dihentikan; tandai kembali area isi sebelum melanjutkan."
    ' Paint once per progress batch, rather than after every selection/shape.
    Application.ScreenUpdating = False
End Sub

Public Sub RibbonMark(ByVal control As Object)
    SigandiMarkBody
End Sub
Public Sub RibbonAll(ByVal control As Object)
    SigandiApply True, True
End Sub
Public Sub RibbonVertical(ByVal control As Object)
    SigandiApply True, False
End Sub
Public Sub RibbonFill(ByVal control As Object)
    SigandiApply False, True
End Sub
Public Sub RibbonRemove(ByVal control As Object)
    SigandiRemove
End Sub
Public Sub RibbonRefresh(ByVal control As Object)
    SigandiApply True, True
End Sub
Public Sub RibbonPDF(ByVal control As Object)
    SigandiExportPDF
End Sub
Public Sub RibbonHelp(ByVal control As Object)
    If mBusy Then Exit Sub
    MsgBox SigandiVersion() & vbCrLf & vbCrLf & _
        "1. Blok isi akta, tanpa judul dan tanda tangan." & vbCrLf & _
        "2. Klik Tandai Area Isi." & vbCrLf & _
        "3. Pilih Warna Garis, lalu klik Buat Semua atau pilih jenis garis." & vbCrLf & _
        "4. Setelah mengedit teks, klik Perbarui Garis untuk menghitung ulang posisi garis." & vbCrLf & _
        "5. Periksa hasil, lalu Simpan PDF." & vbCrLf & vbCrLf & _
        "Shortcut Ctrl+F7 tandai, Ctrl+F6 buat semua, Ctrl+F8 vertikal, " & _
        "Ctrl+F9 isi ----, Ctrl+F10 perbarui, Ctrl+F11 simpan PDF." & vbCrLf & vbCrLf & _
        "Baris kosong berupa paragraf ikut diisi. Spasi Before/After " & _
        "paragraf bukan baris kosong. Header/footer dilewati. Isi ---- dalam tabel dilewati; garis kiri mengikuti tabel. " & _
        "Blok tanda tangan Notaris/PPAT dilewati mulai label jabatannya." & vbCrLf & vbCrLf & _
        "Versi pratinjau untuk Word desktop Windows. Garis merupakan " & _
        "objek visual, bukan penguncian dokumen." & vbCrLf & vbCrLf & _
        "Bantuan SIMANIS: +62 877 2417 0145" & vbCrLf & _
        "https://wa.me/6287724170145", vbInformation, "SIMANIS | Panduan"
End Sub

Public Sub SigandiMarkBody(Optional ByVal quiet As Boolean = False)
    Dim d As Document
    On Error GoTo Failed
    If mBusy Then Exit Sub
    If Documents.Count = 0 Then Exit Sub
    Set d = ActiveDocument
    If Selection.StoryType <> wdMainTextStory Or Selection.Start = Selection.End Then
        MsgBox "Blok teks isi akta terlebih dahulu. Jangan sertakan judul atau area tanda tangan.", vbInformation, "SIMANIS | Area Isi"
        Exit Sub
    End If
    If d.ProtectionType <> wdNoProtection Or d.ReadOnly Then
        MsgBox "Dokumen hanya-baca atau diproteksi. Gunakan salinan yang dapat diedit.", vbInformation, "SIGANDI"
        Exit Sub
    End If
    d.Bookmarks.Add BODY, Selection.Range
    If quiet Then Application.StatusBar = "SIGANDI: area isi sudah ditandai.": Exit Sub
    MsgBox "Area isi sudah ditandai. Pilih Buat Semua, Garis Vertikal, atau Isi ----.", vbInformation, "SIGANDI"
    Exit Sub
Failed:
    MsgBox Err.Description, vbExclamation, "SIGANDI"
End Sub

Private Function GetBody(ByVal d As Document) As Range
    If d.Bookmarks.Exists(BODY) Then
        Set GetBody = d.Bookmarks(BODY).Range.Duplicate
    ElseIf Selection.StoryType = wdMainTextStory And Selection.Start < Selection.End Then
        Set GetBody = Selection.Range.Duplicate
        d.Bookmarks.Add BODY, GetBody
    End If
End Function

' Restore the original left border, preserving other borders and formatting.
' State is encoded in bookmark names: unlike Document.Variables, bookmarks
' participate in Word Undo and survive save/reopen together with the border.
Private Sub RestoreParagraphBorders(ByVal d As Document)
    Dim names As New Collection, item As Bookmark, name As Variant
    Dim values As Variant, para As Paragraph, i As Long, tracked As Range
    For Each item In d.Bookmarks
        If Left$(item.Name, Len(VBOOK)) = VBOOK Then names.Add item.Name
    Next item
    For Each name In names
        values = Split(Mid$(CStr(name), Len(VBOOK) + 1), "_")
        If d.Bookmarks.Exists(CStr(name)) And UBound(values) = 4 Then
            Set tracked = d.Bookmarks(CStr(name)).Range.Duplicate
            For Each para In tracked.Paragraphs
              If para.Range.Start >= tracked.Start And para.Range.Start < tracked.End Then
                With para.Borders(wdBorderLeft)
                    .LineStyle = CLng(values(1))
                    If CLng(values(1)) <> wdLineStyleNone Then
                        .LineWidth = CLng(values(2))
                        .Color = CLng(Replace(values(3), "N", "-"))
                    End If
                End With
                para.Borders.DistanceFromLeft = CLng(values(4)) / 20
              End If
            Next para
            d.Bookmarks(CStr(name)).Delete
        End If
        i = i + 1
        If mBusy And i Mod 25 = 0 Then Progress "SIGANDI: menyiapkan paragraf " & i
    Next name
End Sub

Private Function ParagraphScope(ByVal scope As Range) As Range
    Dim r As Range, para As Paragraph, lastContentEnd As Long
    Set r = scope.Duplicate
    lastContentEnd = scope.Start
    For Each para In scope.Paragraphs
        If Not para.Range.Information(wdWithInTable) And SignatureLabel(para.Range.Text) Then
            ' The empty separator immediately before the role is part of the
            ' signature block too. Interior blank paragraphs remain eligible.
            If lastContentEnd < r.End Then r.End = lastContentEnd
            Exit For
        End If
        If Len(CleanText(para.Range.Text)) > 0 Then lastContentEnd = para.Range.End
    Next para
    Set ParagraphScope = r
End Function

Private Function ParagraphEligible(ByVal para As Paragraph, ByVal scope As Range) As Boolean
    Dim r As Range, text As String, core As String
    Set r = para.Range
    If r.Start >= scope.End Or r.End <= scope.Start Then Exit Function
    If r.StoryType <> wdMainTextStory Or r.Information(wdWithInTable) Then Exit Function
    If r.InlineShapes.Count > 0 Then Exit Function
    If r.Sections(1).PageSetup.TextColumns.Count <> 1 Or r.Sections(1).PageSetup.MirrorMargins Then Exit Function
    text = CleanText(r.Text)
    If r.ParagraphFormat.Alignment = wdAlignParagraphCenter Then Exit Function
    core = LCase$(text)
    If ((Left$(core, 6) = "pasal " Or Left$(core, 4) = "bab ") And Len(core) < 35) Then Exit Function
    If Len(text) > 0 And Len(text) < 100 And text = UCase$(text) And text <> LCase$(text) Then
        If r.Information(wdActiveEndPageNumber) = 1 And r.Information(wdVerticalPositionRelativeToPage) < 220 Then Exit Function
    End If
    If Len(text) = 0 And (InStr(r.Text, Chr(12)) > 0 Or InStr(r.Text, Chr(14)) > 0) Then Exit Function
    ParagraphEligible = True
End Function

Private Function ApplyParagraphBorders(ByVal d As Document, ByVal scope As Range) As Long
    Dim para As Paragraph, r As Range, i As Long, n As Long, id As String
    Dim style As Long, width As Long, color As Long, distance As Single, state As String
    Dim limit As Range
    Set limit = ParagraphScope(scope)
    For Each para In limit.Paragraphs
        i = i + 1
        If ParagraphEligible(para, limit) Then
            Set r = para.Range.Duplicate
            n = n + 1
            id = Format$(n, "000000")
            style = para.Borders(wdBorderLeft).LineStyle
            width = wdLineWidth050pt: color = wdColorAutomatic
            If style <> wdLineStyleNone Then
                width = para.Borders(wdBorderLeft).LineWidth
                color = para.Borders(wdBorderLeft).Color
            End If
            state = VBOOK & id & "_" & CStr(style) & "_" & CStr(width) & "_" & Replace(CStr(color), "-", "N") & "_" & CStr(CLng(para.Borders.DistanceFromLeft * 20))
            If Len(state) > 40 Then Err.Raise vbObjectError + 160, , "Pengaturan border asli di luar batas yang didukung."
            d.Bookmarks.Add state, r
            distance = 6
            If para.FirstLineIndent < 0 Then distance = distance - para.FirstLineIndent
            If distance > 31 Then distance = 31
            para.Borders.DistanceFromLeft = distance
            With para.Borders(wdBorderLeft)
                .LineStyle = wdLineStyleSingle
                .LineWidth = wdLineWidth050pt
                .Color = wdColorBlack
            End With
        End If
        If i Mod 12 = 0 Then Progress "SIGANDI: garis paragraf " & i & " / " & limit.Paragraphs.Count
    Next para
    ApplyParagraphBorders = n
End Function

Private Function CleanText(ByVal value As String) As String
    value = Replace(value, vbCr, "")
    value = Replace(value, Chr(11), "")
    value = Replace(value, Chr(12), "")
    value = Replace(value, Chr(7), "")
    value = Replace(value, vbTab, " ")
    value = Replace(value, ChrW(160), " ")
    CleanText = Trim$(value)
End Function

Private Function SignatureLabel(ByVal text As String) As Boolean
    Dim t As String
    t = LCase$(CleanText(text))
    Do While InStr(t, "  ") > 0
        t = Replace(t, "  ", " ")
    Loop
    If Len(t) > 90 Then Exit Function
    Do While Len(t) > 0
        If InStr(",.:; ", Right$(t, 1)) = 0 Then Exit Do
        t = Left$(t, Len(t) - 1)
    Loop
    SignatureLabel = (t = "notaris" Or t = "ppat" Or t = "pejabat pembuat akta tanah" _
        Or Left$(t, 11) = "notaris di " Or Left$(t, 8) = "ppat di " _
        Or Left$(t, Len("notaris berkedudukan di ")) = "notaris berkedudukan di ")
End Function

Private Function IsHeading(ByVal r As Range, ByVal text As String, ByVal page As Long, ByVal y As Single) As Boolean
    Dim t As String
    t = LCase$(text)
    Do While Len(t) > 0
        If InStr("-_ ", Left$(t, 1)) = 0 Then Exit Do
        t = Mid$(t, 2)
    Loop
    If Len(t) = 0 Then Exit Function
    If r.ParagraphFormat.Alignment = wdAlignParagraphCenter Then
        IsHeading = True
    ElseIf (Left$(t, 6) = "pasal " Or Left$(t, 4) = "bab ") And Len(t) < 35 Then
        IsHeading = True
    ElseIf page = 1 And y < 220 And Len(text) < 100 Then
        IsHeading = (text = UCase$(text) And text <> LCase$(text))
    End If
End Function

Private Function VisibleChar(ByVal c As Range) As Boolean
    Dim text As String
    text = CleanText(c.Text)
    If Len(text) = 0 Or c.Font.Hidden = True Then Exit Function
    If c.Font.Color = wdColorWhite Then
        If c.HighlightColorIndex = wdNoHighlight Then
            If c.Shading.BackgroundPatternColor = wdColorAutomatic Or _
               c.Shading.BackgroundPatternColor = wdColorWhite Then Exit Function
        End If
    End If
    VisibleChar = True
End Function

Private Function BaselineOffset(ByVal r As Range, ByVal size As Single) As Single
    Dim offset As Single, value As Single
    offset = size
    value = r.ParagraphFormat.LineSpacing
    Select Case r.ParagraphFormat.LineSpacingRule
        Case wdLineSpaceExactly: offset = value * 0.8
        Case wdLineSpaceAtLeast
            If value > size * 1.2 Then offset = value * 0.8
        Case wdLineSpaceMultiple
            If value > 0 And value < 1000 Then offset = size * value / 12
    End Select
    If offset < size * 0.65 Then offset = size * 0.65
    BaselineOffset = offset
End Function

' NumberPosition is the alignment point, not always the label's left edge.
' In right-aligned roman lists (i., ii., iii.) subtract the rendered label
' width before applying the usual 2 pt clearance in DrawParagraphRails.
Private Function NumberLabelLeft(ByVal para As Paragraph, ByVal margin As Single, ByVal fallback As Single) As Single
    Dim level As ListLevel, label As String, width As Single, face As String, fontSize As Single
    Dim labelFont As Font, extent As TextExtent, bold As Long, italic As Long
#If VBA7 Then
    Dim dc As LongPtr, fontHandle As LongPtr, previous As LongPtr
#End If
    NumberLabelLeft = fallback
    On Error GoTo Finish
    If para.Range.ListFormat.ListType = wdListNoNumbering Then Exit Function
    Set level = para.Range.ListFormat.ListTemplate.ListLevels(para.Range.ListFormat.ListLevelNumber)
    ' Left-aligned labels need no font measurement at all.
    If level.Alignment = wdListLevelAlignLeft Then Exit Function
    label = para.Range.ListFormat.ListString
    If Len(label) = 0 Then Exit Function
    face = para.Range.Characters(1).Font.Name
    fontSize = para.Range.Characters(1).Font.Size
    Set labelFont = level.Font
    If Len(labelFont.Name) > 0 Then face = labelFont.Name
    If labelFont.Size >= 5 And labelFont.Size <= 72 Then fontSize = labelFont.Size
    If fontSize < 5 Or fontSize > 72 Then fontSize = 12
    width = Len(label) * fontSize
    bold = 400
    If labelFont.Bold = True Or (labelFont.Bold = wdUndefined And para.Range.Characters(1).Font.Bold = True) Then bold = 700
    If labelFont.Italic = True Or (labelFont.Italic = wdUndefined And para.Range.Characters(1).Font.Italic = True) Then italic = 1
#If VBA7 Then
    ' Measure at 720 dpi: one GDI pixel is 0.1 point, independent of screen DPI.
    dc = GetDC(0)
    fontHandle = CreateFontW(-CLng(fontSize * 10), 0, 0, 0, bold, italic, 0, 0, 1, 0, 0, 0, 0, StrPtr(face))
    If dc <> 0 And fontHandle <> 0 Then
        previous = SelectObject(dc, fontHandle)
        If GetTextExtentPoint32W(dc, StrPtr(label), Len(label), extent) <> 0 Then width = extent.cx / 10
    End If
#End If
    ' Direct paragraph formatting can override the template's NumberPosition.
    ' fallback already contains the effective LeftIndent + FirstLineIndent.
    NumberLabelLeft = fallback
    If level.Alignment = wdListLevelAlignRight Then NumberLabelLeft = NumberLabelLeft - width
    If level.Alignment = wdListLevelAlignCenter Then NumberLabelLeft = NumberLabelLeft - width / 2
Finish:
#If VBA7 Then
    If previous <> 0 Then SelectObject dc, previous
    If fontHandle <> 0 Then DeleteObject fontHandle
    If dc <> 0 Then ReleaseDC 0, dc
#End If
End Function

' Collect before inserting shapes: layout must be measured on clean text.
' Row: start,end,x0,x1,y,size,page,right,blank,eligible,railX.
Private Function CollectRows(ByVal d As Document, ByVal scope As Range, ByVal measureEnds As Boolean) As Collection
    Dim rows As New Collection
    Dim p As Long, q As Long, first As Long, last As Long, k As Long
    Dim r As Range, c As Range, probe As Range, setup As PageSetup
    Dim x0 As Single, x1 As Single, y As Single, endY As Single
    Dim size As Single, edge As Single, page As Long, text As String, eligible As Boolean
    Dim bodyEnd As Long, label As String, para As Paragraph, raw As String, color As Long
    Dim railX As Single, labelX As Single, fullLine As Boolean, paraStart As Range, seenBody As Boolean
    Dim centerHeading As Boolean
    bodyEnd = scope.End
    ' Explicit area selection is the main boundary; standalone signing labels
    ' provide an additional conservative stop, including white placeholder text.
    For Each para In scope.Paragraphs
        Set r = para.Range
        label = CleanText(r.Text)
        If Not r.Information(wdWithInTable) And SignatureLabel(label) Then
            bodyEnd = r.Start
            Exit For
        End If
    Next para
    p = scope.Start
    Set para = scope.Paragraphs(1)
    Do While p < bodyEnd
        Do While para.Range.End <= p
            Set para = para.Next
        Loop
        ' A cell terminator is not a visual paragraph boundary. Skip the
        ' entire table before measuring lines; no filler can enter its cells.
        If para.Range.Information(wdWithInTable) Then
            Set r = para.Range.Tables(1).Range
            p = r.End
            If p > bodyEnd Then p = bodyEnd
            AppendTableRailRows rows, r.Tables(1)
            rows.Add Array(p, p, 0, 0, 0, 12, 0, 0, True, False, 0, False, False)
            seenBody = True
            GoTo NextVisualRow
        End If
        d.Range(p, p).Select
        Selection.EndKey Unit:=wdLine, Extend:=wdExtend
        q = Selection.End
        If q > bodyEnd Then q = bodyEnd
        If q <= p Then
            raw = d.Range(p, p + 1).Text
            If InStr(vbCr & Chr(11) & Chr(12) & Chr(14), raw) = 0 Then
                Err.Raise vbObjectError + 155, , "Word belum menyediakan tata letak baris. Aktifkan jendela dokumen pada Print Layout, lalu coba lagi."
            End If
            If raw = vbCr And Len(CleanText(para.Range.Text)) > 0 Then
                p = p + 1
                GoTo NextVisualRow
            End If
            q = p + 1
        End If
        Set r = d.Range(p, q)
        Set probe = d.Range(p, p)
        x0 = probe.Information(wdHorizontalPositionRelativeToPage)
        y = probe.Information(wdVerticalPositionRelativeToPage)
        If x0 < 0 Or y < 0 Then
            d.ActiveWindow.ScrollIntoView probe, True
            x0 = probe.Information(wdHorizontalPositionRelativeToPage)
            y = probe.Information(wdVerticalPositionRelativeToPage)
        End If
        page = probe.Information(wdActiveEndPageNumber)
        size = r.Characters(1).Font.Size
        If size < 5 Or size > 72 Then size = 12
        Set setup = r.Sections(1).PageSetup
        edge = setup.PageWidth - setup.RightMargin
        If Abs(r.ParagraphFormat.RightIndent) < 10000 Then edge = edge - r.ParagraphFormat.RightIndent
        If setup.GutterPos = wdGutterPosRight Then edge = edge - setup.Gutter
        x1 = x0
        fullLine = False
        text = ""
        first = -1: last = -1
        color = r.Font.Color
        If color = wdColorWhite And r.HighlightColorIndex = wdNoHighlight And _
           (r.Shading.BackgroundPatternColor = wdColorAutomatic Or r.Shading.BackgroundPatternColor = wdColorWhite) Then
            ' Entirely white placeholder: treat its actual line as blank.
        ElseIf r.Font.Hidden = True Then
            ' Hidden text has no ink.
        Else
            ' Only the first and last ink are needed. Scanning both edges
            ' avoids querying formatting for every letter in a long deed.
            raw = r.Text
            For k = 1 To Len(raw)
                If Len(CleanText(Mid$(raw, k, 1))) > 0 Then
                    Set c = d.Range(r.Start + k - 1, r.Start + k)
                    If VisibleChar(c) Then
                        first = c.Start
                        Exit For
                    End If
                End If
            Next k
            If first >= 0 Then
                If measureEnds Then
                For k = Len(raw) To 1 Step -1
                    If Len(CleanText(Mid$(raw, k, 1))) > 0 Then
                        Set c = d.Range(r.Start + k - 1, r.Start + k)
                        If VisibleChar(c) Then
                            last = c.Start
                            Exit For
                        End If
                    End If
                Next k
                End If
                text = CleanText(raw)
            End If
        End If
        If first >= 0 Then
            Set probe = d.Range(first, first)
            x0 = probe.Information(wdHorizontalPositionRelativeToPage)
            y = probe.Information(wdVerticalPositionRelativeToPage)
            page = probe.Information(wdActiveEndPageNumber)
            If measureEnds Then
            Set probe = d.Range(last + 1, last + 1)
            x1 = probe.Information(wdHorizontalPositionRelativeToPage)
            endY = probe.Information(wdVerticalPositionRelativeToPage)
            ' A caret after the last character can already be on the next
            ' wrapped line. Such a full line needs no end filler.
            If Abs(endY - y) > 2 Then fullLine = True
            End If
        End If
        ' Word reports the top of the line box, not the printed baseline.
        ' Include fixed/multiple line spacing and auto-numbered labels.
        Set paraStart = d.Range(para.Range.Start, para.Range.Start)
        ' Range.Information can report the margin rather than glyph X after
        ' floating shapes are deleted. Paragraph indents remain authoritative.
        railX = setup.LeftMargin + para.LeftIndent
        If p = para.Range.Start Then
            railX = railX + para.FirstLineIndent
            railX = NumberLabelLeft(para, setup.LeftMargin, railX)
        End If
        If first < 0 Then x0 = railX
        If fullLine Then x1 = edge
        y = y + BaselineOffset(r, size) - size * 0.88
        eligible = True
        If x0 < 0 Or y < 0 Or y < setup.TopMargin - 2 Or y + size > setup.PageHeight - setup.BottomMargin + 2 Then eligible = False
        If r.Information(wdWithInTable) Or r.InlineShapes.Count > 0 Then eligible = False
        If setup.TextColumns.Count <> 1 Or setup.MirrorMargins Then eligible = False
        centerHeading = False
        If IsHeading(r, text, page, y) Then
            ' Internal section headings get two black leaders around the text.
            ' The initial title block remains excluded until body text begins.
            centerHeading = eligible And first >= 0 And (seenBody Or page > 1 Or scope.Start > 0)
            eligible = False
        End If
        If eligible And first >= 0 Then seenBody = True
        If first < 0 And Not seenBody Then eligible = False
        ' A page/section break is structural and must not receive a blank rule.
        If InStr(r.Text, Chr(12)) > 0 Or InStr(r.Text, Chr(14)) > 0 Then eligible = False
        rows.Add Array(p, q, x0, x1, y, size, page, edge, first < 0, eligible, railX, centerHeading, False)
        p = q
        If rows.Count Mod 16 = 0 Then Progress "SIGANDI: membaca baris " & rows.Count & " (" & Format$((p - scope.Start) / (bodyEnd - scope.Start), "0%") & ")"
NextVisualRow:
    Loop
    Set CollectRows = rows
End Function

Private Sub AddRule(ByVal d As Document, ByVal pos As Long, ByVal kind As String, _
                    ByVal x0 As Single, ByVal y0 As Single, ByVal x1 As Single, ByVal y1 As Single, ByVal n As Long, ByVal page As Long, Optional ByVal standalone As Boolean = False)
    Dim sh As Shape, anchor As Range, canvas As Shape, setup As PageSetup
    Dim tableAnchor As Range, cellOffsetX As Single, cellOffsetY As Single
    If Not mStrokeReady Then
        mStrokeColor = SigandiLineColor()
        mStrokeWeight = SigandiLineWeight()
        mStrokeReady = True
    End If
    ' Word moves anchors to paragraph starts. Use a paragraph starting on the
    ' actual target page, including when the measured text continues a paragraph
    ' from the preceding page. Otherwise a cap/rail appears on the wrong page.
    If mAnchors.Exists(CStr(page)) Then
        Set anchor = mAnchors(CStr(page)).Duplicate
    Else
        Set anchor = d.Range(pos, pos + 1)
    End If
    ' AddCanvas can replace the characters in a noncollapsed anchor range
    ' (notably compatibility-mode deeds). Anchor at an insertion point only.
    ' Keep that point inside the paragraph so it stays on the measured page.
    anchor.Collapse wdCollapseStart
    If (kind = "C" Or standalone) And anchor.Information(wdWithInTable) Then
        ' Word still interprets these coordinates from the cell on pages
        ' containing only a table, even with page-relative positioning.
        Set tableAnchor = anchor.Cells(1).Range.Duplicate
        tableAnchor.Collapse wdCollapseStart
        cellOffsetX = tableAnchor.Information(wdHorizontalPositionRelativeToPage) - anchor.Cells(1).LeftPadding
        cellOffsetY = tableAnchor.Information(wdVerticalPositionRelativeToPage) - anchor.Cells(1).TopPadding
    End If
    If (kind = "H" Or kind = "V") And Not standalone Then
        If mFillCanvases Is Nothing Then Set mFillCanvases = CreateObject("Scripting.Dictionary")
        If mFillCanvases.Exists(CStr(page)) Then
            Set canvas = mFillCanvases(CStr(page))
        Else
            On Error Resume Next
            Set canvas = d.Shapes(PREFIX & "P_" & Format$(page, "00000"))
            On Error GoTo 0
            If canvas Is Nothing Then
            Set setup = anchor.Sections(1).PageSetup
            Set canvas = d.Shapes.AddCanvas(0, 0, setup.PageWidth, setup.PageHeight, anchor)
            With canvas
                .Name = PREFIX & "P_" & Format$(page, "00000")
                .AlternativeText = "SIGANDI Word | halaman " & CStr(page)
                .RelativeHorizontalPosition = wdRelativeHorizontalPositionPage
                .RelativeVerticalPosition = wdRelativeVerticalPositionPage
                .Left = 0: .Top = 0
                .LockAnchor = True: .LayoutInCell = False
                .WrapFormat.Type = wdWrapBehind
                .LayoutInCell = False
                .Line.Visible = False
                ' Word's PDF exporter gives a no-fill canvas an opaque white
                ' background. An explicit 100% transparent solid stays clear.
                .Fill.Visible = True: .Fill.Solid
                .Fill.ForeColor.RGB = RGB(255, 255, 255)
                .Fill.Transparency = 1
                .Width = setup.PageWidth: .Height = setup.PageHeight
                .ZOrder msoSendToBack
            End With
            End If
            mFillCanvases.Add CStr(page), Empty
            Set mFillCanvases(CStr(page)) = canvas
        End If
        Set sh = canvas.CanvasItems.AddLine(x0, y0, x1, y1)
    Else
        Set sh = d.Shapes.AddLine(x0, y0, x1, y1, anchor)
    End If
    With sh
        .Name = PREFIX & kind & "_" & Format$(n, "00000")
        .AlternativeText = "SIGANDI Word " & kind & " | halaman " & CStr(page) & " | SEG:" & _
            InvariantNumber(x0) & ";" & InvariantNumber(y0) & ";" & InvariantNumber(x1) & ";" & InvariantNumber(y1)
        If kind = "C" Or standalone Then
            .RelativeHorizontalPosition = wdRelativeHorizontalPositionPage
            .RelativeVerticalPosition = wdRelativeVerticalPositionPage
            .LockAnchor = True
            .LayoutInCell = False
            .WrapFormat.Type = wdWrapBehind
            .LayoutInCell = False
        End If
        If kind = "C" Or standalone Then
            If x0 < x1 Then .Left = x0 - cellOffsetX Else .Left = x1 - cellOffsetX
            If y0 < y1 Then .Top = y0 - cellOffsetY Else .Top = y1 - cellOffsetY
        End If
        .Line.ForeColor.RGB = mStrokeColor
        If kind = "H" Then .Line.ForeColor.RGB = RGB(0, 0, 0)
        .Line.Weight = mStrokeWeight
        If kind = "H" Then .Line.DashStyle = 4
    End With
End Sub

Private Sub PreparePageAnchors(ByVal d As Document)
    Dim para As Paragraph, page As Long, start As Long, finish As Long, total As Long
    Dim fallback As Range
    Set mAnchors = CreateObject("Scripting.Dictionary")
    total = d.ComputeStatistics(wdStatisticPages)
    For page = 1 To total
        Set fallback = Nothing
        start = d.GoTo(wdGoToPage, wdGoToAbsolute, page).Start
        ' A trailing page containing only a footer has no main-story anchor.
        If start + 1 >= d.Content.End Then GoTo NextAnchorPage
        finish = d.Content.End
        If page < total Then finish = d.GoTo(wdGoToPage, wdGoToAbsolute, page + 1).Start
        Set para = d.Range(start + 1, start + 1).Paragraphs(1)
        If para.Range.Start < start Then Set para = para.Next
        Do While Not para Is Nothing
            If para.Range.Start >= finish Then Exit Do
            If para.Range.End - para.Range.Start > 1 Then
                If para.Range.Information(wdWithInTable) Then
                    If fallback Is Nothing Then Set fallback = d.Range(para.Range.Start + 1, para.Range.Start + 2)
                Else
                ' Live ranges track Word's drawing-anchor characters. Plain
                ' character offsets become stale as earlier shapes are added.
                mAnchors.Add CStr(page), Empty
                Set mAnchors(CStr(page)) = d.Range(para.Range.Start + 1, para.Range.Start + 2)
                Exit Do
                End If
            End If
            Set para = para.Next
        Loop
        If Not mAnchors.Exists(CStr(page)) And Not fallback Is Nothing Then
            mAnchors.Add CStr(page), Empty
            Set mAnchors(CStr(page)) = fallback
        End If
NextAnchorPage:
    Next page
End Sub

Private Sub DeleteRules(ByVal d As Document, ByVal vertical As Boolean, ByVal horizontal As Boolean)
    Dim i As Long, j As Long, name As String, names() As Variant, count As Long
    Dim sh As Shape, child As Shape, userContent As Boolean, owned As Boolean
    If d.Shapes.Count = 0 Then Exit Sub
    ReDim names(0 To d.Shapes.Count - 1)
    For i = 1 To d.Shapes.Count
        Set sh = d.Shapes(i)
        name = sh.Name
        If Left$(name, Len(PREFIX)) = PREFIX And sh.Type = msoCanvas Then
            userContent = False
            For Each child In sh.CanvasItems
                If Left$(child.Name, Len(PREFIX)) <> PREFIX Then userContent = True
            Next child
            If vertical And horizontal And Not userContent Then
                names(count) = name: count = count + 1
            Else
                For j = sh.CanvasItems.Count To 1 Step -1
                    If RuleKindMatches(sh.CanvasItems(j).Name, vertical, horizontal) Then sh.CanvasItems(j).Delete
                Next j
                If sh.CanvasItems.Count = 0 Then
                    names(count) = name: count = count + 1
                Else
                    owned = False
                    For Each child In sh.CanvasItems
                        If Left$(child.Name, Len(PREFIX)) = PREFIX Then owned = True
                    Next child
                    If Not owned Then sh.Name = "USER_PRESERVED_" & name
                End If
            End If
        ElseIf RuleKindMatches(name, vertical, horizontal) Then
            names(count) = name
            count = count + 1
        End If
    Next i
    If count > 0 Then
        ReDim Preserve names(0 To count - 1)
        d.Shapes.Range(names).Delete
    End If
End Sub

Private Function RuleKindMatches(ByVal name As String, ByVal vertical As Boolean, ByVal horizontal As Boolean) As Boolean
    RuleKindMatches = (vertical And (Left$(name, Len(PREFIX) + 2) = PREFIX & "V_" Or Left$(name, Len(PREFIX) + 2) = PREFIX & "C_")) Or _
                      (horizontal And Left$(name, Len(PREFIX) + 2) = PREFIX & "H_")
End Function

' Join real visual rows into continuous rails. A numbered first line keeps
' its short outer rail; continuation rows move inward to their actual text.
' Do not merge through headings, another indent level or a page boundary.
Private Function DrawParagraphRails(ByVal d As Document, ByVal rows As Collection) As Long
    Dim segments As New Collection, row As Variant, seg As Variant
    Dim active As Boolean, x As Single, y0 As Single, y1 As Single
    Dim page As Long, pos As Long, count As Long, font As Single
    Dim rail As Single, top As Single, bottom As Single, joins As Boolean
    Dim stub As Boolean, n As Long, i As Long, first As Variant, last As Variant, tableSegment As Boolean
    For Each row In rows
        joins = False
        If row(9) Or row(11) Or row(12) Then
            rail = row(10) - 2
            If rail < 5 Then rail = 5
            top = row(4) + 0.5
            bottom = row(4) + row(5) * 0.95
            If row(12) Then
                top = row(4): bottom = row(4) + row(5)
            End If
            If active Then
                joins = (row(6) = page And Abs(rail - x) < 2 And top - y1 < 36 And top >= y0)
            End If
        End If
        If active And Not joins Then
            stub = False
            If (row(9) Or row(11) Or row(12)) And row(6) = page And count = 1 Then stub = (rail > x + 6)
            segments.Add Array(pos, x, y0, y1, page, stub, tableSegment)
            active = False
        End If
        If row(9) Or row(11) Or row(12) Then
            If Not active Then
                pos = row(0): x = rail: y0 = top: page = row(6): count = 0
                tableSegment = row(12)
                active = True
            End If
            y1 = bottom
            count = count + 1
        End If
    Next row
    If active Then segments.Add Array(pos, x, y0, y1, page, False, tableSegment)
    page = 0
    For Each seg In segments
        n = n + 1
        AddRule d, seg(0), "V", seg(1), seg(2), seg(1), seg(3), n, seg(4), CBool(seg(6))
        If seg(4) <> page Then
            If page <> 0 Then DrawPageCaps d, first, last, n
            page = seg(4)
            first = Empty: last = Empty
        End If
        ' The first numbered line is still part of the deed. Its short outer
        ' rail must receive the opening cap, before the continuation indents.
        If IsEmpty(first) Then first = seg
        If Not seg(5) Then
            last = seg
        End If
        If IsEmpty(last) Then last = seg
        i = i + 1
        If i Mod 12 = 0 Then Progress "SIGANDI: menyambung garis paragraf " & i & " / " & segments.Count
    Next seg
    If page <> 0 Then DrawPageCaps d, first, last, n
    DrawParagraphRails = n
End Function

Private Sub DrawPageCaps(ByVal d As Document, ByVal first As Variant, ByVal last As Variant, ByRef n As Long)
    If IsEmpty(first) Or IsEmpty(last) Then Exit Sub
    DrawOneCap d, first(0), first(1), first(2), first(4), True, n
    DrawOneCap d, last(0), last(1), last(3), last(4), False, n
End Sub

Private Function InvariantNumber(ByVal value As Double) As String
    InvariantNumber = Replace(CStr(value), ",", ".")
End Function

Private Sub DrawOneCap(ByVal d As Document, ByVal pos As Long, ByVal x As Single, ByVal y As Single, _
                       ByVal page As Long, ByVal upper As Boolean, ByRef n As Long)
    Dim setup As PageSetup, dx As Single, dy As Single, room As Single, ratio As Single, stem As Single
    Dim key As String, role As String, sh As Shape
    Set setup = d.Range(pos, pos).Sections(1).PageSetup
    If upper Then key = "Top": role = "TOP" Else key = "Bottom": role = "BOTTOM"
    dx = SigandiCapValue(key & "DX"): dy = SigandiCapValue(key & "DY")
    If upper Then room = y - setup.TopMargin Else room = setup.PageHeight - setup.BottomMargin - y
    If room < 0 Then Exit Sub
    If dy > room Then dy = room
    If SigandiCapValue(key & "DY") > 0 And dy <= 1 Then Exit Sub
    If dx > setup.PageWidth - setup.RightMargin - x Then dx = setup.PageWidth - setup.RightMargin - x
    If dx < 1 Then Exit Sub
    ratio = 0
    If SigandiCrossMode() Then ratio = 0.16
    If upper Then dy = -dy
    n = n + 1
    AddRule d, pos, "C", x - ratio * dx, y - ratio * dy, x + dx, y + dy, n, page
    Set sh = d.Shapes(PREFIX & "C_" & Format$(n, "00000"))
    sh.AlternativeText = "SIGANDI Word C | halaman " & CStr(page) & " | BASE:" & role & ";" & InvariantNumber(x) & ";" & InvariantNumber(y)
    If ratio > 0 And Abs(dy) > 0.1 Then
        stem = Abs(dy) * 0.16
        If stem > 1.5 Then stem = 1.5
        n = n + 1
        If upper Then
            AddRule d, pos, "C", x, y - stem, x, y, n, page
        Else
            AddRule d, pos, "C", x, y, x, y + stem, n, page
        End If
        d.Shapes(PREFIX & "C_" & Format$(n, "00000")).AlternativeText = "SIGANDI Word C STEM | halaman " & CStr(page)
    End If
End Sub

' Reuse the existing cap bases. Changing a slope does not remeasure every
' visual text row. Live anchor ranges survive deleting old drawing objects.
Public Function SigandiUpdateCaps() As String
    Dim d As Document, sh As Shape, cap As Variant, caps As New Collection, names As New Collection
    Dim meta As String, fields As Variant, page As Long, x As Single, y As Single, upper As Boolean
    Dim n As Long, i As Long, at As Long, record As Boolean, saved As Range, anchor As Range
    Dim deleteNames() As Variant
    On Error GoTo Failed
    If mBusy Then SigandiUpdateCaps = "ERROR: Tunggu proses SIGANDI selesai.": Exit Function

    mStrokeReady = False
    Set mFillCanvases = Nothing
    Set d = ActiveDocument
    If d.ReadOnly Or d.ProtectionType <> wdNoProtection Then Err.Raise vbObjectError + 197, , "Dokumen harus dapat diedit."
    Set saved = Selection.Range.Duplicate
    Set mAnchors = CreateObject("Scripting.Dictionary")
    For Each sh In d.Shapes
        If Left$(sh.Name, Len(PREFIX) + 2) = PREFIX & "C_" Then
            names.Add sh.Name
            meta = sh.AlternativeText
            If InStr(meta, "STEM") = 0 Then
                at = InStr(meta, "halaman ")
                If at = 0 Then Err.Raise vbObjectError + 199, , "Metadata penutup tidak dikenal. Klik Perbarui Garis."
                page = Val(Mid$(meta, at + 8))
                at = InStr(meta, "BASE:")
                If at > 0 Then
                    fields = Split(Mid$(meta, at + 5), ";")
                    upper = (fields(0) = "TOP")
                    x = Val(fields(1)): y = Val(fields(2))
                Else
                    upper = (sh.VerticalFlip <> 0)
                    x = sh.Left: y = sh.Top
                    If upper Then y = y + sh.Height
                End If
                If Not mAnchors.Exists(CStr(page)) Then
                    Set anchor = sh.Anchor.Paragraphs(1).Range.Duplicate
                    If anchor.End - anchor.Start > 1 Then anchor.SetRange anchor.Start + 1, anchor.Start + 2
                    mAnchors.Add CStr(page), Empty
                    Set mAnchors(CStr(page)) = anchor
                End If
                caps.Add Array(x, y, page, upper)
            End If
        End If
    Next sh
    If caps.Count = 0 Then Err.Raise vbObjectError + 200, , "Belum ada penutup. Klik Garis Vertikal atau Buat Semua terlebih dahulu."
    Application.UndoRecord.StartCustomRecord "SIGANDI - Pengaturan Penutup"
    record = True
    mBusy = True: mCancel = False
    Set mDocument = d: mText = d.Content.Text
    ReDim deleteNames(0 To names.Count - 1)
    For i = 1 To names.Count
        deleteNames(i - 1) = CStr(names(i))
    Next i
    d.Shapes.Range(deleteNames).Delete
    For Each cap In caps
        Set anchor = mAnchors(CStr(cap(2)))
        DrawOneCap d, anchor.Start, cap(0), cap(1), cap(2), cap(3), n
        i = i + 1
        If i Mod 12 = 0 Then Progress "SIGANDI: memperbarui penutup"
    Next cap
    SigandiUpdateCaps = "OK"
Finish:
    On Error Resume Next
    If record Then Application.UndoRecord.EndCustomRecord
    If Not saved Is Nothing Then saved.Select
    Application.ScreenUpdating = True
    Application.StatusBar = ""
    mBusy = False
    mStrokeReady = False
    Set mFillCanvases = Nothing
    Set mAnchors = Nothing: Set mDocument = Nothing: mText = ""
    Exit Function
Failed:
    SigandiUpdateCaps = "ERROR: " & Err.Description
    Resume Finish
End Function

Public Sub RibbonCapApply(ByVal control As Object)
    Dim result As String
    result = SigandiUpdateCaps()
    If result = "OK" Then result = "Pengaturan penutup diterapkan."
    MsgBox result, vbInformation, "SIMANIS | Penutup"
End Sub

' Public quiet entry point used by the integration test on temporary copies.
' It also permits a later signed UI to call the same engine.
Public Function SigandiBuild(ByVal vertical As Boolean, ByVal horizontal As Boolean) As Long
    Dim d As Document, scope As Range, saved As Range, rows As Collection, row As Variant
    Dim recordOpen As Boolean, n As Long, x As Single, rail As Single
    Dim failure As Long, message As String, oldView As Long, processed As Long, oldCancel As Long
    Dim phase As Single
    If mBusy Then Err.Raise vbObjectError + 150, , "SIGANDI sedang memproses dokumen."

    mStrokeReady = False
    Set mFillCanvases = Nothing
    If Documents.Count = 0 Then Err.Raise vbObjectError + 151, , "Buka dokumen Word terlebih dahulu."
    Set d = ActiveDocument
    If d.ReadOnly Or d.ProtectionType <> wdNoProtection Then Err.Raise vbObjectError + 152, , "Gunakan dokumen yang dapat diedit."
    Set scope = GetBody(d)
    If scope Is Nothing Then Err.Raise vbObjectError + 153, , "Blok isi akta dan klik Tandai Area Isi terlebih dahulu."
    If scope.StoryType <> wdMainTextStory Then Err.Raise vbObjectError + 154, , "Pilih isi utama dokumen."
    Set scope = ParagraphScope(scope)
    If scope.Start >= scope.End Then Err.Raise vbObjectError + 155, , "Area yang dipilih hanya berisi blok tanda tangan. Tandai isi akta terlebih dahulu."
    ValidateTableScope scope
    Set saved = Selection.Range.Duplicate
    oldView = d.ActiveWindow.View.Type
    oldCancel = Application.EnableCancelKey
    Set mDocument = d
    mText = d.Content.Text
    mCancel = False
    mBusy = True
    mTiming = "": phase = Timer
    On Error GoTo Failed
    Application.UndoRecord.StartCustomRecord "SIGANDI - Perbarui Garis"
    recordOpen = True
    Application.EnableCancelKey = wdCancelInterrupt
    Application.ScreenUpdating = True
    DeleteRules d, vertical, horizontal
    If vertical Then
        RestoreParagraphBorders d
        RestoreTableBorders d
    End If
    PhaseTime "cleanup", phase
    d.ActiveWindow.View.Type = wdPrintView
    Progress "SIGANDI: menyiapkan area isi"
    If vertical Or horizontal Then
        d.Repaginate
        PhaseTime "paginate", phase
        Set rows = CollectRows(d, scope, horizontal)
        PhaseTime "measure", phase
        PreparePageAnchors d
        PhaseTime "anchors", phase
    End If
    If vertical Then n = n + DrawParagraphRails(d, rows)
    PhaseTime "vertical", phase
    If horizontal Then
        For Each row In rows
            If row(11) Then
                If row(2) - row(10) > 4 Then
                    n = n + 1
                    AddRule d, row(0), "H", row(10), row(4) + row(5) * 0.62, row(2) - 2.5, row(4) + row(5) * 0.62, n, row(6)
                End If
                If row(7) - row(3) > 4 Then
                    n = n + 1
                    AddRule d, row(0), "H", row(3) + 2.5, row(4) + row(5) * 0.62, row(7), row(4) + row(5) * 0.62, n, row(6)
                End If
            End If
            If row(9) Then
                x = row(2)
                If Not row(8) Then x = row(3) + 1.5
                If row(7) - x > 4 Then
                    n = n + 1
                    AddRule d, row(0), "H", x, row(4) + row(5) * 0.62, row(7), row(4) + row(5) * 0.62, n, row(6)
                End If
            End If
            processed = processed + 1
            If processed Mod 16 = 0 Then Progress "SIGANDI: pengisi baris " & processed & " / " & rows.Count
        Next row
    End If
    PhaseTime "fill", phase
    d.Bookmarks.Add BODY, scope
    SigandiBuild = n
Finish:
    On Error Resume Next
    If Not saved Is Nothing Then saved.Select
    d.ActiveWindow.View.Type = oldView
    Application.ScreenUpdating = True
    Application.ScreenRefresh
    Application.EnableCancelKey = oldCancel
    Application.StatusBar = ""
    If recordOpen Then Application.UndoRecord.EndCustomRecord
    mBusy = False
    mStrokeReady = False
    Set mDocument = Nothing
    Set mAnchors = Nothing
    Set mFillCanvases = Nothing
    mText = ""
    On Error GoTo 0
    If failure <> 0 Then Err.Raise failure, "SIGANDI", message
    Exit Function
Failed:
    failure = Err.Number: message = Err.Description
    Resume Finish
End Function

Public Function SigandiTryBuild(ByVal vertical As Boolean, ByVal horizontal As Boolean) As String
    On Error GoTo Failed
    SigandiTryBuild = CStr(SigandiBuild(vertical, horizontal))
    Exit Function
Failed:
    SigandiTryBuild = "ERROR: " & Err.Description
End Function

Public Sub SigandiApply(Optional ByVal vertical As Boolean = True, Optional ByVal horizontal As Boolean = True, Optional ByVal quiet As Boolean = False)
    Dim n As Long
    On Error GoTo Failed
    n = SigandiBuild(vertical, horizontal)
    If quiet Then Application.StatusBar = CStr(n) & " garis SIGANDI dibuat. Periksa hasil sebelum digunakan.": Exit Sub
    MsgBox n & " garis dibuat pada area isi." & vbCrLf & _
        "Periksa hasil sebelum mencetak. Setelah mengedit teks, klik Perbarui Garis.", vbInformation, "SIGANDI"
    Exit Sub
Failed:
    MsgBox Err.Description, vbExclamation, "SIGANDI"
End Sub

Public Sub SigandiRemove()
    Dim recordOpen As Boolean, message As String
    On Error GoTo Failed
    If Documents.Count = 0 Then Exit Sub
    If mBusy Then Exit Sub
    Application.UndoRecord.StartCustomRecord "SIGANDI - Hapus Garis"
    recordOpen = True
    DeleteRules ActiveDocument, True, True
    RestoreParagraphBorders ActiveDocument
    RestoreTableBorders ActiveDocument
    Application.UndoRecord.EndCustomRecord
    Application.ScreenUpdating = True
    Application.ScreenRefresh
    Exit Sub
Failed:
    message = Err.Description
    On Error Resume Next
    If recordOpen Then Application.UndoRecord.EndCustomRecord
    Application.ScreenUpdating = True
    Application.ScreenRefresh
    MsgBox message, vbExclamation, "SIGANDI"
End Sub

Public Sub SigandiExportPDF()
    Dim dialog As FileDialog, path As String, name As String
    On Error GoTo Failed
    If mBusy Then Exit Sub

    If Documents.Count = 0 Then Exit Sub
    name = ActiveDocument.Name
    If InStrRev(name, ".") > 0 Then name = Left$(name, InStrRev(name, ".") - 1)
    Set dialog = Application.FileDialog(msoFileDialogSaveAs)
    dialog.Title = "SIGANDI - Simpan salinan PDF"
    dialog.InitialFileName = name & " - SIGANDI.pdf"
    If dialog.Show <> -1 Then Exit Sub
    path = dialog.SelectedItems(1)
    If LCase$(Right$(path, 4)) <> ".pdf" Then path = path & ".pdf"
    If Len(Dir$(path)) > 0 Then
        If MsgBox("File sudah ada. Ganti file PDF ini?" & vbCrLf & path, vbYesNo + vbQuestion, "SIGANDI") <> vbYes Then Exit Sub
    End If
    ActiveDocument.ExportAsFixedFormat OutputFileName:=path, ExportFormat:=wdExportFormatPDF, OpenAfterExport:=True
    Exit Sub
Failed:
    MsgBox Err.Description, vbExclamation, "SIGANDI"
End Sub


' Desktop-independent SIMANIS Word preferences.
Public Sub RibbonLoaded(ByVal ribbon As Object)
    Set mRibbon = ribbon
End Sub

Public Function SigandiLineColorIndex() As Long
    Dim value As Long
    value = Val(GetSetting("SIGANDI Word", "Garis", "Color", "0"))
    If value < 0 Or value > 3 Then value = 0
    SigandiLineColorIndex = value
End Function

Private Function ManagedStrokes(ByVal d As Document) As Collection
    Dim result As New Collection, sh As Shape, child As Shape
    For Each sh In d.Shapes
        If Left$(sh.Name, Len(PREFIX)) = PREFIX Then
            If sh.Type = msoCanvas Then
                For Each child In sh.CanvasItems
                    If Left$(child.Name, Len(PREFIX)) = PREFIX Then result.Add child
                Next child
            Else
                result.Add sh
            End If
        End If
    Next sh
    Set ManagedStrokes = result
End Function

Private Sub ApplyStrokeStyle(ByVal d As Document, ByVal changeColor As Boolean, ByVal value As Double)
    Dim names() As Variant, count As Long, sh As Shape, child As Shape
    ReDim names(0 To d.Shapes.Count)
    For Each sh In d.Shapes
        If Left$(sh.Name, Len(PREFIX)) = PREFIX Then
            If sh.Type = msoCanvas Then
                For Each child In sh.CanvasItems
                    If Left$(child.Name, Len(PREFIX)) = PREFIX Then
                        If changeColor Then
                            If Left$(child.Name, Len(PREFIX) + 2) = PREFIX & "H_" Then
                                child.Line.ForeColor.RGB = RGB(0, 0, 0)
                            Else
                                child.Line.ForeColor.RGB = CLng(value)
                            End If
                        Else
                            child.Line.Weight = CSng(value)
                        End If
                    End If
                Next child
            Else
                If changeColor And Left$(sh.Name, Len(PREFIX) + 2) = PREFIX & "H_" Then
                    sh.Line.ForeColor.RGB = RGB(0, 0, 0)
                Else
                    names(count) = sh.Name: count = count + 1
                End If
            End If
        End If
    Next sh
    If count > 0 Then
        ReDim Preserve names(0 To count - 1)
        If changeColor Then d.Shapes.Range(names).Line.ForeColor.RGB = CLng(value) Else d.Shapes.Range(names).Line.Weight = CSng(value)
    End If
    StyleTableBorders d, changeColor, value
End Sub

Public Function SigandiLineWeight() As Single
    Dim value As Single
    value = Val(Replace(GetSetting("SIGANDI Word", "Garis", "Weight", "0.6"), ",", "."))
    If value < 0.1 Or value > 4 Then value = 0.6
    SigandiLineWeight = value
End Function

Public Function SigandiSetLineWeight(ByVal value As Single) As String
    Dim d As Document, sh As Shape, count As Long, record As Boolean
    Dim strokes As Collection
    On Error GoTo Failed
    If mBusy Then SigandiSetLineWeight = "ERROR: Tunggu proses SIGANDI selesai.": Exit Function
    If value < 0.1 Or value > 4 Then Err.Raise vbObjectError + 203, , "Ketebalan harus 0,1 sampai 4 pt."
    If Documents.Count > 0 Then
        Set d = ActiveDocument
        Set strokes = ManagedStrokes(d)
        count = strokes.Count + TableBorderCount(d)
    End If
    If count > 0 Then

        If d.ReadOnly Or d.ProtectionType <> wdNoProtection Then Err.Raise vbObjectError + 197, , "Dokumen harus dapat diedit."
    End If
    SaveSetting "SIGANDI Word", "Garis", "Weight", CStr(value)
    If count > 0 Then
        Application.UndoRecord.StartCustomRecord "SIGANDI - Ketebalan Garis"
        record = True: mBusy = True
        ApplyStrokeStyle d, False, value
    End If
    SigandiSetLineWeight = "OK"
Finish:
    On Error Resume Next
    If record Then Application.UndoRecord.EndCustomRecord
    If Not mRibbon Is Nothing Then mRibbon.Invalidate
    mBusy = False
    Exit Function
Failed:
    SigandiSetLineWeight = "ERROR: " & Err.Description
    Resume Finish
End Function

Public Sub RibbonWeightText(ByVal control As Object, ByRef value)
    value = CStr(SigandiLineWeight())
End Sub

Public Sub RibbonWeightChanged(ByVal control As Object, ByVal text As String)
    Dim clean As String, i As Long, result As String
    clean = Replace(Trim$(text), ",", ".")
    If Len(clean) = 0 Or clean = "." Then GoTo Invalid
    If Len(clean) - Len(Replace(clean, ".", "")) > 1 Then GoTo Invalid
    For i = 1 To Len(clean)
        If InStr("0123456789.", Mid$(clean, i, 1)) = 0 Then GoTo Invalid
    Next i
    result = SigandiSetLineWeight(CSng(Val(clean)))
    If result <> "OK" Then MsgBox result, vbInformation, "SIMANIS | Ketebalan Garis"
    Exit Sub
Invalid:
    MsgBox "Masukkan ketebalan 0,1 sampai 4 pt.", vbInformation, "SIGANDI"
    If Not mRibbon Is Nothing Then mRibbon.InvalidateControl control.Id
End Sub

Public Function SigandiLineColor() As Long
    Select Case SigandiLineColorIndex()
        Case 1: SigandiLineColor = RGB(200, 32, 32)
        Case 2: SigandiLineColor = RGB(17, 132, 91)
        Case 3: SigandiLineColor = RGB(96, 96, 96)
        Case Else: SigandiLineColor = RGB(0, 0, 0)
    End Select
End Function

Public Function SigandiSetLineColor(ByVal index As Long) As String
    Dim d As Document, sh As Shape, record As Boolean, color As Long, count As Long
    Dim strokes As Collection
    On Error GoTo Failed
    If mBusy Then
        SigandiSetLineColor = "ERROR: Tunggu proses SIGANDI selesai."
        Exit Function
    End If
    If index < 0 Or index > 3 Then Err.Raise vbObjectError + 202, , "Pilihan warna tidak valid."
    If Documents.Count > 0 Then
        Set d = ActiveDocument
        Set strokes = ManagedStrokes(d)
        count = strokes.Count + TableBorderCount(d)
    End If
    If count > 0 Then

        If d.ReadOnly Or d.ProtectionType <> wdNoProtection Then Err.Raise vbObjectError + 197, , "Dokumen harus dapat diedit."
    End If
    SaveSetting "SIGANDI Word", "Garis", "Color", CStr(index)
    color = SigandiLineColor()
    If count > 0 Then
        Application.UndoRecord.StartCustomRecord "SIGANDI - Warna Garis"
        record = True
        mBusy = True
        ApplyStrokeStyle d, True, color
    End If
    SigandiSetLineColor = "OK"
Finish:
    On Error Resume Next
    If record Then Application.UndoRecord.EndCustomRecord
    If Not mRibbon Is Nothing Then mRibbon.Invalidate
    mBusy = False
    Exit Function
Failed:
    SigandiSetLineColor = "ERROR: " & Err.Description
    Resume Finish
End Function

Public Sub RibbonLineColorIndex(ByVal control As Object, ByRef index)
    index = SigandiLineColorIndex()
End Sub

Public Sub RibbonLineColorChanged(ByVal control As Object, ByVal id As String, ByVal index As Integer)
    Dim result As String
    result = SigandiSetLineColor(CLng(index))
    If result <> "OK" Then MsgBox result, vbInformation, "SIMANIS | Warna Garis"
End Sub

Private Function QuoteArg(ByVal value As String) As String
    QuoteArg = Chr(34) & value & Chr(34)
End Function







' Numeric preferences live in the user's application settings, not the deed.
Public Function SigandiCapValue(ByVal name As String) As Double
    Dim fallback As String, value As Double
    fallback = "7"
    If name = "TopDX" Or name = "BottomDX" Then fallback = "42"
    value = Val(Replace(GetSetting("SIGANDI Word", "Garis", name, fallback), ",", "."))
    If name = "TopDX" Or name = "BottomDX" Then
        If value < 1 Or value > 150 Then value = 42
    Else
        If value < 0 Or value > 30 Then value = 7
    End If
    SigandiCapValue = value
End Function

Public Function SigandiCrossMode() As Boolean
    SigandiCrossMode = (GetSetting("SIGANDI Word", "Garis", "Cross", "1") = "1")
End Function

Public Function SigandiSetCapSettings(ByVal cross As Boolean, ByVal topDX As Double, ByVal topDY As Double, _
                                      ByVal bottomDX As Double, ByVal bottomDY As Double) As String
    On Error GoTo Failed
    If topDX < 1 Or topDX > 150 Or bottomDX < 1 Or bottomDX > 150 Or _
       topDY < 0 Or topDY > 30 Or bottomDY < 0 Or bottomDY > 30 Then
        Err.Raise vbObjectError + 194, , "Panjang harus 1-150 pt dan kemiringan 0-30 pt."
    End If
    SaveSetting "SIGANDI Word", "Garis", "Cross", IIf(cross, "1", "0")
    SaveSetting "SIGANDI Word", "Garis", "TopDX", CStr(topDX)
    SaveSetting "SIGANDI Word", "Garis", "TopDY", CStr(topDY)
    SaveSetting "SIGANDI Word", "Garis", "BottomDX", CStr(bottomDX)
    SaveSetting "SIGANDI Word", "Garis", "BottomDY", CStr(bottomDY)
    If Not mRibbon Is Nothing Then mRibbon.Invalidate
    SigandiSetCapSettings = "OK"
    Exit Function
Failed:
    SigandiSetCapSettings = "ERROR: " & Err.Description
End Function

Public Sub RibbonCapText(ByVal control As Object, ByRef value)
    value = CStr(SigandiCapValue(Replace(control.Id, "Sigandi", "")))
End Sub

Public Sub RibbonCapChanged(ByVal control As Object, ByVal text As String)
    Dim name As String, v As Double, a As Double, b As Double, c As Double, d As Double, message As String
    Dim clean As String, i As Long
    If mBusy Then Exit Sub
    clean = Replace(Trim$(text), ",", ".")
    For i = 1 To Len(clean)
        If InStr("0123456789.", Mid$(clean, i, 1)) = 0 Then GoTo Invalid
    Next i
    If Len(clean) = 0 Or Len(clean) - Len(Replace(clean, ".", "")) > 1 Then GoTo Invalid
    v = Val(clean)
    a = SigandiCapValue("TopDX"): b = SigandiCapValue("TopDY")
    c = SigandiCapValue("BottomDX"): d = SigandiCapValue("BottomDY")
    name = Replace(control.Id, "Sigandi", "")
    Select Case name
        Case "TopDX": a = v
        Case "TopDY": b = v
        Case "BottomDX": c = v
        Case "BottomDY": d = v
    End Select
    message = SigandiSetCapSettings(SigandiCrossMode(), a, b, c, d)
    If message <> "OK" Then MsgBox message, vbInformation, "SIMANIS | Pengaturan Garis"
    Exit Sub
Invalid:
    MsgBox "Masukkan angka panjang atau kemiringan dalam point (pt).", vbInformation, "SIGANDI"
    If Not mRibbon Is Nothing Then mRibbon.InvalidateControl control.Id
End Sub

Public Sub RibbonCrossPressed(ByVal control As Object, ByRef pressed)
    pressed = SigandiCrossMode()
End Sub
Public Sub RibbonCrossChanged(ByVal control As Object, ByVal pressed As Boolean)
    Dim result As String
    If mBusy Then Exit Sub
    result = SigandiSetCapSettings(pressed, SigandiCapValue("TopDX"), SigandiCapValue("TopDY"), SigandiCapValue("BottomDX"), SigandiCapValue("BottomDY"))
End Sub
Public Sub RibbonCapPreset(ByVal control As Object, ByVal id As String, ByVal index As Integer)
    Dim result As String
    If mBusy Then Exit Sub
    Select Case index
        Case 0: result = SigandiSetCapSettings(SigandiCrossMode(), 42, 4, 42, 4)
        Case 1: result = SigandiSetCapSettings(SigandiCrossMode(), 42, 7, 42, 7)
        Case 2: result = SigandiSetCapSettings(SigandiCrossMode(), 50, 10, 50, 10)
    End Select
End Sub

Public Sub RibbonCapPresetIndex(ByVal control As Object, ByRef index)
    Dim a As Double, b As Double, c As Double, d As Double
    a = SigandiCapValue("TopDX"): b = SigandiCapValue("TopDY")
    c = SigandiCapValue("BottomDX"): d = SigandiCapValue("BottomDY")
    index = 3
    If a = 42 And c = 42 And b = 4 And d = 4 Then index = 0
    If a = 42 And c = 42 And b = 7 And d = 7 Then index = 1
    If a = 50 And c = 50 And b = 10 And d = 10 Then index = 2
End Sub

' Page settings use the actual document as the default. No margin value is
' labelled a compulsory legal standard; office presets are explicitly saved.
Public Sub RibbonPageSetup(ByVal control As Object)
    Dim record As Boolean, result As Long
    On Error GoTo Failed
    If mBusy Or Documents.Count = 0 Then Exit Sub

    Application.UndoRecord.StartCustomRecord "SIGANDI - Margin Akta"
    record = True
    result = Dialogs(wdDialogFilePageSetup).Show
    If result = -1 Then
        DeleteRules ActiveDocument, True, True
        RestoreParagraphBorders ActiveDocument
    RestoreTableBorders ActiveDocument
        MsgBox "Pengaturan halaman diterapkan. Klik Perbarui Garis untuk tata letak yang baru.", vbInformation, "SIMANIS | Halaman Akta"
    End If
Finish:
    If record Then Application.UndoRecord.EndCustomRecord
    Exit Sub
Failed:
    MsgBox Err.Description, vbExclamation, "SIMANIS | Halaman Akta"
    Resume Finish
End Sub

Public Sub RibbonSavePagePreset(ByVal control As Object)
    Dim setup As PageSetup, key As Variant, value As Double
    If mBusy Or Documents.Count = 0 Then Exit Sub
    Set setup = Selection.Sections(1).PageSetup
    For Each key In Array("PageWidth", "PageHeight", "TopMargin", "BottomMargin", "LeftMargin", "RightMargin")
        value = CallByName(setup, CStr(key), VbGet)
        SaveSetting "SIGANDI Word", "Margin Kantor", CStr(key), CStr(value / CentimetersToPoints(1))
    Next key
    SaveSetting "SIGANDI Word", "Margin Kantor", "Saved", "1"
    MsgBox "Ukuran kertas dan empat margin bagian aktif disimpan sebagai preset kantor.", vbInformation, "SIMANIS | Preset Margin"
End Sub

Public Function SigandiApplyPageSettings(ByVal width As Double, ByVal height As Double, ByVal top As Double, _
       ByVal bottom As Double, ByVal left As Double, ByVal right As Double, ByVal allSections As Boolean) As String
    Dim d As Document, section As Section, target As Section, record As Boolean, message As String
    On Error GoTo Failed
    If mBusy Then Err.Raise vbObjectError + 195, , "Tunggu proses SIGANDI selesai."

    If width < 10 Or width > 60 Or height < 10 Or height > 60 Or top < 0 Or bottom < 0 Or left < 0 Or right < 0 Or _
       left + right >= width - 1 Or top + bottom >= height - 1 Then
        Err.Raise vbObjectError + 196, , "Ukuran kertas/margin tidak valid atau area isi terlalu kecil."
    End If
    Set d = ActiveDocument
    If d.ReadOnly Or d.ProtectionType <> wdNoProtection Then Err.Raise vbObjectError + 197, , "Dokumen harus dapat diedit."
    Set target = Selection.Sections(1)
    Application.UndoRecord.StartCustomRecord "SIGANDI - Terapkan Preset Margin"
    record = True
    DeleteRules d, True, True
    RestoreParagraphBorders d
    For Each section In d.Sections
        If allSections Or section.Index = target.Index Then
            With section.PageSetup
                If width <= height Then .Orientation = wdOrientPortrait Else .Orientation = wdOrientLandscape
                .PageWidth = CentimetersToPoints(width): .PageHeight = CentimetersToPoints(height)
                .TopMargin = CentimetersToPoints(top): .BottomMargin = CentimetersToPoints(bottom)
                .LeftMargin = CentimetersToPoints(left): .RightMargin = CentimetersToPoints(right)
            End With
        End If
    Next section
    SigandiApplyPageSettings = "OK"
Finish:
    If record Then Application.UndoRecord.EndCustomRecord
    Application.ScreenUpdating = True
    Exit Function
Failed:
    SigandiApplyPageSettings = "ERROR: " & Err.Description
    Resume Finish
End Function

Public Sub RibbonApplyPagePreset(ByVal control As Object)
    Dim result As String, whole As Boolean
    If mBusy Or Documents.Count = 0 Then Exit Sub
    If GetSetting("SIGANDI Word", "Margin Kantor", "Saved", "0") <> "1" Then
        MsgBox "Atur margin dan ukuran kertas, lalu klik Simpan Preset Kantor terlebih dahulu.", vbInformation, "SIGANDI"
        Exit Sub
    End If
    whole = (control.Id = "SigandiPageApplyAll")
    result = SigandiApplyPageSettings(Val(Replace(GetSetting("SIGANDI Word", "Margin Kantor", "PageWidth"), ",", ".")), _
        Val(Replace(GetSetting("SIGANDI Word", "Margin Kantor", "PageHeight"), ",", ".")), _
        Val(Replace(GetSetting("SIGANDI Word", "Margin Kantor", "TopMargin"), ",", ".")), _
        Val(Replace(GetSetting("SIGANDI Word", "Margin Kantor", "BottomMargin"), ",", ".")), _
        Val(Replace(GetSetting("SIGANDI Word", "Margin Kantor", "LeftMargin"), ",", ".")), _
        Val(Replace(GetSetting("SIGANDI Word", "Margin Kantor", "RightMargin"), ",", ".")), whole)
    If result = "OK" Then result = "Preset kantor diterapkan. Klik Perbarui Garis untuk tata letak baru."
    MsgBox result, vbInformation, "SIMANIS | Preset Margin"
End Sub

Public Sub RibbonMarginHelp(ByVal control As Object)
    MsgBox "UU Jabatan Notaris mengatur bentuk akta, ruang/sela kosong, dan perubahan di sisi kiri (ruang renvoi). " & _
        "Pasal 38, 42 dan 49 tidak menetapkan empat margin dalam satuan cm." & vbCrLf & vbCrLf & _
        "SIGANDI memakai margin dokumen saat ini. Sesuaikan ukuran kertas dan ruang renvoi dengan format kantor, lalu simpan preset." & vbCrLf & vbCrLf & _
        "Rujukan: UU 30/2004 sebagaimana diubah UU 2/2014." & vbCrLf & _
        "https://peraturan.bpk.go.id/Details/38565/uu-no-2-tahun-2014", vbInformation, "SIMANIS | Rujukan Margin Akta"
End Sub


' Table rails use Word's native outer-left cell border, so merged rows,
' indentation, row height and page breaks follow Word's own table layout.
' Original borders are recorded per outer cell in undo-aware bookmarks.
Private Sub ValidateTableScope(ByVal scope As Range)
    Dim t As Table
    For Each t In scope.Tables
        If t.NestingLevel = 1 Then
            If t.Range.Start < scope.Start Or t.Range.End > scope.End Then
                Err.Raise vbObjectError + 211, , "Sertakan seluruh tabel ketika menandai area isi. Isi sel tidak diberi pengisi ----."
            End If
        End If
    Next t
End Sub

Private Function NativeTableWeight(ByVal points As Single) As Long
    ' Word table borders support discrete widths, unlike drawn line shapes.
    Dim widths As Variant, item As Variant, best As Single
    widths = Array(0.25, 0.5, 0.75, 1, 1.5, 2.25, 3, 4.5)
    best = 100
    For Each item In widths
        If Abs(CSng(item) - points) < best Then
            best = Abs(CSng(item) - points)
            NativeTableWeight = CLng(CSng(item) * 8)
        End If
    Next item
End Function

Private Function TableBorderCount(ByVal d As Document) As Long
    Dim b As Bookmark
    For Each b In d.Bookmarks
        If Left$(b.Name, Len(TBOOK)) = TBOOK Then TableBorderCount = TableBorderCount + 1
    Next b
End Function

Private Sub RestoreTableBorders(ByVal d As Document)
    Dim names As New Collection, b As Bookmark, item As Variant, state As Variant, cell As Cell
    For Each b In d.Bookmarks
        If Left$(b.Name, Len(TBOOK)) = TBOOK Then names.Add b.Name
    Next b
    For Each item In names
        If d.Bookmarks.Exists(CStr(item)) Then
            state = Split(Mid$(CStr(item), Len(TBOOK) + 1), "_")
            Set cell = d.Bookmarks(CStr(item)).Range.Cells(1)
            With cell.Borders(wdBorderLeft)
                If CLng(state(2)) > 0 And CLng(state(2)) <= 48 Then .LineWidth = CLng(state(2))
                .Color = CLng(Replace(state(3), "N", "-"))
                ' Width/color setters can enable a previously invisible border.
                ' Restore the original style last, including wdLineStyleNone.
                .LineStyle = CLng(state(1))
            End With
            d.Bookmarks(CStr(item)).Delete
        End If
    Next item
End Sub

Private Sub StyleTableBorders(ByVal d As Document, ByVal changeColor As Boolean, ByVal value As Double)
    Dim b As Bookmark, cell As Cell
    For Each b In d.Bookmarks
        If Left$(b.Name, Len(TBOOK)) = TBOOK Then
            Set cell = b.Range.Cells(1)
            If changeColor Then
                cell.Borders(wdBorderLeft).Color = CLng(value)
            Else
                cell.Borders(wdBorderLeft).LineWidth = NativeTableWeight(CSng(value))
            End If
        End If
    Next b
End Sub

' Parameterless macros are callable by native template key bindings.
Public Sub SigandiShortcutMark()
    SigandiMarkBody True
End Sub
Public Sub SigandiShortcutAll()
    SigandiApply True, True, True
End Sub
Public Sub SigandiShortcutVertical()
    SigandiApply True, False, True
End Sub
Public Sub SigandiShortcutFill()
    SigandiApply False, True, True
End Sub
Public Sub SigandiShortcutRefresh()
    SigandiApply True, True, True
End Sub
Public Sub SigandiShortcutPDF()
    SigandiExportPDF
End Sub


' One external rail per physical table page. Cell height includes blank space
' and merged rows; no border, padding, column width or table text is changed.
Private Sub AppendTableRailRows(ByVal rows As Collection, ByVal t As Table)
    Dim bounds As Object, cell As Cell, a As Range, z As Range, setup As PageSetup
    Dim startPage As Long, endPage As Long, tableStartPage As Long, pg As Long
    Dim x As Single, y As Single, bottom As Single, height As Single, size As Single
    Dim item As Variant, firstPage As Long, lastPage As Long, start As Long
    Const gap As Single = 6
    Set bounds = CreateObject("Scripting.Dictionary")
    Set setup = t.Range.Sections(1).PageSetup
    tableStartPage = t.Range.Information(wdActiveEndPageNumber)
    Set a = t.Range.Duplicate: a.Collapse wdCollapseStart
    tableStartPage = a.Information(wdActiveEndPageNumber)
    firstPage = 999999: lastPage = 0
    For Each cell In t.Range.Cells
        If cell.ColumnIndex = 1 Then
            Set a = cell.Range.Duplicate: a.Collapse wdCollapseStart
            Set z = cell.Range.Duplicate
            z.SetRange cell.Range.End - 1, cell.Range.End - 1
            a.Select
            startPage = a.Information(wdActiveEndPageNumber)
            endPage = z.Information(wdActiveEndPageNumber)
            x = a.Information(wdHorizontalPositionRelativeToPage) - cell.LeftPadding - gap
            y = a.Information(wdVerticalPositionRelativeToPage) - cell.TopPadding
            height = cell.Height
            size = a.Font.Size
            If size < 5 Or size > 72 Then size = 12
            For pg = startPage To endPage
                If pg = startPage Then bottom = y Else bottom = setup.TopMargin
                If pg = endPage Then
                    If startPage = endPage And height > 0 And height < 10000 Then
                        height = y + cell.Height
                    Else
                        height = z.Information(wdVerticalPositionRelativeToPage) + size * 1.2 + cell.BottomPadding
                    End If
                Else
                    height = setup.PageHeight - setup.BottomMargin
                End If
                If pg > tableStartPage And Not bounds.Exists(CStr(pg)) Then bottom = setup.TopMargin
                If bottom < setup.TopMargin Then bottom = setup.TopMargin
                If height > setup.PageHeight - setup.BottomMargin Then height = setup.PageHeight - setup.BottomMargin
                start = a.Start
                If bounds.Exists(CStr(pg)) Then
                    item = bounds(CStr(pg))
                    If bottom < item(2) Then item(2) = bottom
                    If height > item(3) Then item(3) = height
                    bounds(CStr(pg)) = item
                Else
                    bounds.Add CStr(pg), Array(start, x, bottom, height)
                End If
                If pg < firstPage Then firstPage = pg
                If pg > lastPage Then lastPage = pg
            Next pg
        End If
    Next cell
    For pg = firstPage To lastPage
        If bounds.Exists(CStr(pg)) Then
            item = bounds(CStr(pg))
            rows.Add Array(item(0), item(0), 0, 0, item(2), item(3) - item(2), pg, 0, True, False, item(1) + 2, False, True)
        End If
    Next pg
End Sub

' SIMANIS authenticated data insertion. Existing drawing IDs remain compatible.
Public Sub RibbonSimanisData(ByVal control As Object)
    SimanisPickData
End Sub

Private Function SimanisDataHelper() As String
    Dim shell As Object, fso As Object, path As String
    Set shell = CreateObject("WScript.Shell")
    Set fso = CreateObject("Scripting.FileSystemObject")
    On Error Resume Next
    path = shell.RegRead("HKEY_CURRENT_USER\Software\SIMANIS\WordDataExe")
    On Error GoTo 0
    If Not fso.FileExists(path) Then path = Environ$("LOCALAPPDATA") & "\Programs\SIMANIS\SIMANIS-WordData.exe"
    If Not fso.FileExists(path) Then Exit Function
    If Left$(path, 2) = "\\" Or InStr(path, Chr(34)) > 0 Then Exit Function
    SimanisDataHelper = path
End Function

Public Sub SimanisPickData()
    Dim helper As String, shell As Object, fso As Object, process As Object, stream As Object
    Dim path As String, text As String, original As String, started As Date
    Dim doc As Document, target As Range, recording As Boolean
    On Error GoTo Failed
    If mBusy Then Exit Sub
    If Documents.Count = 0 Then Exit Sub
    Set doc = ActiveDocument
    If doc.ReadOnly Or doc.ProtectionType <> wdNoProtection Then
        MsgBox "Dokumen terkunci atau hanya baca. Buka dokumen yang dapat diedit.", vbInformation, "SIMANIS"
        Exit Sub
    End If
    helper = SimanisDataHelper()
    If Len(helper) = 0 Then
        MsgBox "Pasang helper SIMANIS Word Data terbaru terlebih dahulu.", vbInformation, "SIMANIS"
        Exit Sub
    End If
    Set target = Selection.Range.Duplicate
    target.Collapse wdCollapseEnd
    original = doc.Content.Text
    Set shell = CreateObject("WScript.Shell")
    Set fso = CreateObject("Scripting.FileSystemObject")
    path = fso.BuildPath(fso.GetSpecialFolder(2), fso.GetTempName)
    mBusy = True
    Set process = shell.Exec(QuoteArg(helper) & " --output " & QuoteArg(path))
    started = Now
    Do While process.Status = 0
        DoEvents
        Sleep 50
        If DateDiff("n", started, Now) > 30 Then
            process.Terminate
            Err.Raise vbObjectError + 230, , "Pemilihan data melewati 30 menit. Coba lagi."
        End If
    Loop
    If process.ExitCode <> 0 Then Err.Raise vbObjectError + 231, , "Helper SIMANIS berhenti sebelum selesai."
    If Not fso.FileExists(path) Then GoTo Finished
    If fso.GetFile(path).Size > 250000 Then Err.Raise vbObjectError + 232, , "Pilihan data terlalu panjang."
    Set stream = fso.OpenTextFile(path, 1, False, -1)
    text = stream.ReadAll
    stream.Close
    fso.DeleteFile path, True
    If Len(Trim$(text)) = 0 Then GoTo Finished
    If Documents.Count = 0 Then Err.Raise vbObjectError + 233, , "Dokumen tujuan telah ditutup."
    If Not (ActiveDocument Is doc) Then Err.Raise vbObjectError + 234, , "Dokumen aktif berubah. Pilih kembali data dari dokumen tujuan."
    If doc.Content.Text <> original Then Err.Raise vbObjectError + 235, , "Dokumen berubah selama pemilihan. Pilih kembali posisi kursor dan ulangi."
    Application.UndoRecord.StartCustomRecord "Masukkan data SIMANIS"
    recording = True
    target.InsertAfter Replace(Replace(text, vbCrLf, vbCr), vbLf, vbCr)
    target.Collapse wdCollapseEnd
    target.Select
    Application.UndoRecord.EndCustomRecord
    recording = False
Finished:
    On Error Resume Next
    If recording Then Application.UndoRecord.EndCustomRecord
    If Len(path) > 0 Then
        If fso.FileExists(path) Then fso.DeleteFile path, True
    End If
    mBusy = False
    Exit Sub
Failed:
    MsgBox Err.Description, vbExclamation, "SIMANIS | Data Word"
    Resume Finished
End Sub
