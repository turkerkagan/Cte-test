#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Excel Merge Engine
------------------
Birden fazla Excel tablosunu TC Kimlik No ve/veya Adı Soyadı üzerinden
birleştirerek tek bir kapsamlı XLSX tablosu haline getirir.
"""

import sys
import os
import json
import re
import html
import unicodedata
from collections import OrderedDict

try:
    import openpyxl
    from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
    from openpyxl.utils import get_column_letter
except ImportError:
    openpyxl = None

try:
    import xlrd
except ImportError:
    xlrd = None


def normalize_str(val):
    """Metin temizleme ve normalize etme."""
    if val is None:
        return ""
    s = str(val).strip()
    # Noktalı/noktasız I-i karakterlerini standartlaştırma
    s = s.replace('İ', 'I').replace('ı', 'i')
    s = unicodedata.normalize('NFKD', s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    return s.upper()


def normalize_tc(val):
    """TC Kimlik Numarasını 11 haneli sayısal metin olarak doğrula ve temizle."""
    if val is None:
        return ""
    # Ondalıklı sayı olarak geldiyse (örn: 12345678901.0)
    if isinstance(val, float):
        val = int(val)
    s = re.sub(r'\D', '', str(val).strip())
    if len(s) == 11:
        return s
    return ""


def clean_header_name(name):
    """Başlık adını sadeleştir."""
    if not name:
        return ""
    return str(name).replace('\n', ' ').replace('\r', ' ').strip()


def parse_html_table(filepath):
    """UYAP veya eski sistemlerin XLS uzantısıyla kaydettiği HTML tablolarını ayrıştır."""
    rows = []
    try:
        with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()

        # tr ve td/th etiketlerini ayıkla
        tr_matches = re.findall(r'<tr[^>]*>(.*?)</tr>', content, re.IGNORECASE | re.DOTALL)
        for tr in tr_matches:
            cell_matches = re.findall(r'<t[dh][^>]*>(.*?)</t[dh]>', tr, re.IGNORECASE | re.DOTALL)
            if cell_matches:
                row = []
                for cell in cell_matches:
                    # HTML etiketlerini ve boşlukları temizle
                    clean_cell = re.sub(r'<[^>]+>', '', cell)
                    clean_cell = html.unescape(clean_cell).strip()
                    row.append(clean_cell)
                rows.append(row)
    except Exception as e:
        pass
    return rows


def read_excel_file_unbounded(filepath):
    """
    Excel dosyasını formatına (.xlsx, .xls veya HTML table) göre oku.
    Dönüş: list of rows (her row bir list)
    """
    rows = []

    # 1. İlk olarak openpyxl dene (Modern XLSX)
    if openpyxl:
        try:
            wb = openpyxl.load_workbook(filepath, data_only=True)
            ws = wb.active
            for row in ws.iter_rows(values_only=True):
                # Boş satırları atla
                if any(c is not None and str(c).strip() != '' for c in row):
                    rows.append([c if c is not None else "" for c in row])
            if rows:
                return rows
        except Exception:
            rows = []

    # 2. xlrd dene (Eski XLS BIFF8 formatı)
    if xlrd:
        try:
            wb = xlrd.open_workbook(filepath)
            ws = wb.sheet_by_index(0)
            for r in range(ws.nrows):
                row = [ws.cell_value(r, c) for c in range(ws.ncols)]
                if any(c is not None and str(c).strip() != '' for c in row):
                    rows.append(row)
            if rows:
                return rows
        except Exception:
            rows = []

    # 3. HTML table formatı dene (UYAP Excel çıktıları genellikle HTML'dir)
    html_rows = parse_html_table(filepath)
    if html_rows:
        return html_rows

    return rows


def read_excel_file(filepath):
    import zipfile
    if zipfile.is_zipfile(filepath):
        with zipfile.ZipFile(filepath) as archive:
            entries = archive.infolist()
            if len(entries) > 10000 or sum(x.file_size for x in entries) > 50 * 1024 * 1024:
                raise ValueError("Sıkıştırılmış Excel sınırı aşıldı.")
    rows = read_excel_file_unbounded(filepath)
    if len(rows) > 5001 or any(len(row) > 200 for row in rows):
        raise ValueError("Her dosya en fazla 5000 veri satırı ve 200 sütun olabilir.")
    return rows


def excel_value(val):
    """Excel'e yazılacak değeri güvenli ve okunur biçimde hazırla."""
    if val is None:
        return ""
    if isinstance(val, float) and val.is_integer():
        return int(val)
    return val


def find_header_and_keys(rows):
    """
    Tablo satırları arasından başlık (header) satırını ve
    TC Kimlik No ile Adı Soyadı sütun indekslerini tespit eder.
    """
    if not rows:
        return None, None, None

    tc_headers = {
        'TC', 'TCNO', 'TCKIMLIK', 'TCKIMLIKNO', 'TCKIMLIKNUMARASI',
        'KIMLIKNO', 'KIMLIKNUMARASI',
    }
    name_headers = {
        'AD', 'ADI', 'ADSOYAD', 'ADISOYADI', 'ADSOYADI', 'ISIM',
        'ISIMSOYISIM', 'ISIMSOYAD', 'HUKUMLU', 'HUKUMLUADI',
        'HUKUMLUADISOYADI', 'HUKUMLUADSOYAD', 'TUTUKLU', 'TUTUKLUADI',
        'TUTUKLUADISOYADI', 'KISI', 'KISIADISOYADI', 'PERSONEL',
    }

    best_header_idx = -1
    best_tc_idx = -1
    best_name_idx = -1
    max_score = -1

    # İlk 15 satırı tara
    scan_limit = min(15, len(rows))
    for r_idx in range(scan_limit):
        row = rows[r_idx]
        tc_idx = -1
        name_idx = -1
        score = 0

        for c_idx, cell in enumerate(row):
            norm = normalize_str(cell)
            if not norm:
                continue

            canonical = re.sub(r'[^A-Z0-9]', '', norm)

            # TC kontrolü
            if tc_idx == -1 and canonical in tc_headers:
                tc_idx = c_idx
                score += 5

            # Ad Soyad kontrolü
            if name_idx == -1 and canonical in name_headers:
                name_idx = c_idx
                score += 5

        # Sütun sayısı doluluğu da puana katkı sağlar
        non_empty = sum(1 for c in row if str(c).strip() != '')
        score += min(non_empty, 5)

        if (tc_idx != -1 or name_idx != -1) and score > max_score:
            max_score = score
            best_header_idx = r_idx
            best_tc_idx = tc_idx
            best_name_idx = name_idx

    # Eğer açıkça bulunamadıysa ilk satırdaki ilk 11 haneli sayıları veya isim benzeri alanları tara
    if best_header_idx == -1 and len(rows) > 0:
        best_header_idx = 0

    return (
        best_header_idx,
        best_tc_idx if best_tc_idx >= 0 else None,
        best_name_idx if best_name_idx >= 0 else None,
    )


def merge_excel_files(file_configs, output_filepath):
    """
    file_configs: list of dicts:
        [{"path": "/path/to/file.xlsx", "label": "Dosya 1", "filename": "liste.xlsx"}, ...]
    """
    # Kayıtlar: key -> dict of attributes
    # key: TC kimlik varsa TC, yoksa normalize isim
    merged_records = OrderedDict()
    tc_index = {}
    name_index = {}
    all_extra_columns = []  # [(col_id, col_title, source_label), ...]
    file_summaries = []

    for f_idx, f_conf in enumerate(file_configs):
        f_path = f_conf.get('path')
        f_label = f_conf.get('label') or f"Dosya {f_idx + 1}"
        f_name = f_conf.get('filename') or os.path.basename(f_path)

        rows = read_excel_file(f_path)
        if not rows:
            file_summaries.append({
                "filename": f_name,
                "label": f_label,
                "status": "error",
                "message": "Dosya okunamadı veya tablo bulunamadı."
            })
            continue

        h_idx, tc_idx, name_idx = find_header_and_keys(rows)

        if h_idx is None or h_idx >= len(rows):
            file_summaries.append({
                "filename": f_name,
                "label": f_label,
                "status": "error",
                "message": "Başlık satırı tespit edilemedi."
            })
            continue


        if tc_idx is None and name_idx is None:
            file_summaries.append({
                "filename": f_name,
                "label": f_label,
                "status": "error",
                "message": "TC Kimlik No veya Ad Soyad sütunu bulunamadı. Başlıkları kontrol edin."
            })
            continue

        header_row = rows[h_idx]
        data_rows = rows[h_idx + 1:]

        # Bu dosyaya ait ekstra sütunları belirle
        file_cols = []
        for c_idx, cell in enumerate(header_row):
            if c_idx == tc_idx or c_idx == name_idx:
                continue
            col_name = clean_header_name(cell)
            if not col_name:
                col_name = f"Sütun {c_idx + 1}"
            col_id = f"f{f_idx}_c{c_idx}"
            file_cols.append((c_idx, col_id, col_name))
            all_extra_columns.append((col_id, col_name, f_label))

        row_count = 0
        matched_count = 0

        for r in data_rows:
            # Satırda en az bir dolu veri olmalı
            if not any(str(c).strip() != '' for c in r):
                continue

            row_count += 1

            tc_val = ""
            if tc_idx is not None and tc_idx < len(r):
                tc_val = normalize_tc(r[tc_idx])

            name_val = ""
            if name_idx is not None and name_idx < len(r):
                name_val = str(r[name_idx]).strip()

            # Eğer TC ve Ad Soyad ikisi de yoksa, ilk sütunlardan tespit etmeye çalış
            if not tc_val and not name_val:
                for val in r:
                    possible_tc = normalize_tc(val)
                    if possible_tc:
                        tc_val = possible_tc
                        break

            if not tc_val and not name_val:
                continue

            # TC ve ad-soyad birbirinin alternatifi olarak kullanılabilir. Bir dosyada
            # yalnız isim, diğerinde TC+isim olduğunda da aynı kişi bulunmalıdır.
            normalized_name = normalize_str(name_val)
            primary_key = tc_index.get(tc_val) if tc_val else None

            if primary_key is None and normalized_name:
                name_candidates = name_index.get(normalized_name, set())
                if len(name_candidates) == 1:
                    candidate_key = next(iter(name_candidates))
                    candidate_tc = merged_records[candidate_key]["tc"]
                    # Aynı ad-soyada sahip farklı TC'leri yanlışlıkla birleştirme.
                    if not tc_val or not candidate_tc or candidate_tc == tc_val:
                        primary_key = candidate_key

            if primary_key is None:
                primary_key = f"TC:{tc_val}" if tc_val else f"NAME:{normalized_name}"
                # Aynı anahtarın olağan dışı biçimde tekrar kullanılması halinde ezme.
                if primary_key in merged_records:
                    primary_key = f"{primary_key}#{len(merged_records) + 1}"

            if primary_key not in merged_records:
                merged_records[primary_key] = {
                    "tc": tc_val,
                    "ad_soyad": name_val,
                    "data": {}
                }
            else:
                matched_count += 1
                # Eksik olan TC veya Ad Soyad varsa tamamla
                if not merged_records[primary_key]["tc"] and tc_val:
                    merged_records[primary_key]["tc"] = tc_val
                if not merged_records[primary_key]["ad_soyad"] and name_val:
                    merged_records[primary_key]["ad_soyad"] = name_val

            if tc_val:
                tc_index[tc_val] = primary_key
            if normalized_name:
                name_index.setdefault(normalized_name, set()).add(primary_key)

            # Sütun verilerini doldur
            for c_idx, col_id, col_name in file_cols:
                val = ""
                if c_idx < len(r):
                    val = excel_value(r[c_idx])
                merged_records[primary_key]["data"][col_id] = val

        file_summaries.append({
            "filename": f_name,
            "label": f_label,
            "status": "success",
            "tc_col": header_row[tc_idx] if (tc_idx is not None and tc_idx < len(header_row)) else "Tespit Edilemedi",
            "name_col": header_row[name_idx] if (name_idx is not None and name_idx < len(header_row)) else "Tespit Edilemedi",
            "total_rows": row_count,
            "matched_rows": matched_count,
            "column_count": len(file_cols)
        })

    successful_files = sum(1 for item in file_summaries if item.get("status") == "success")
    if successful_files < 2:
        return {
            "success": False,
            "error": "Birleştirme için TC Kimlik No veya Ad Soyad sütunu bulunan en az 2 okunabilir dosya gerekir.",
            "summaries": file_summaries,
        }

    if not merged_records:
        return {
            "success": False,
            "error": "Dosyalarda birleştirilebilecek kişi kaydı bulunamadı.",
            "summaries": file_summaries,
        }

    # Sonuç XLSX dosyasını oluştur
    if not openpyxl:
        return {
            "success": False,
            "error": "openpyxl kütüphanesi bulunamadı.",
            "summaries": file_summaries
        }

    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Birlesmis_Veriler"
    ws.views.sheetView[0].showGridLines = True

    # Stiller
    header_fill = PatternFill(start_color="1E3A8A", end_color="1E3A8A", fill_type="solid") # Lacivert / CTE Mavi
    header_font = Font(name="Calibri", size=11, bold=True, color="FFFFFF")

    source_fill = PatternFill(start_color="0284C7", end_color="0284C7", fill_type="solid") # Gök Mavisi
    source_font = Font(name="Calibri", size=9, bold=True, color="FFFFFF")

    border_thin = Border(
        left=Side(style='thin', color='D1D5DB'),
        right=Side(style='thin', color='D1D5DB'),
        top=Side(style='thin', color='D1D5DB'),
        bottom=Side(style='thin', color='D1D5DB')
    )

    zebra_fill = PatternFill(start_color="F8FAFC", end_color="F8FAFC", fill_type="solid")

    # 1. Satır: Kaynak Dosya Bilgisi Grubu
    # 2. Satır: Sütun Başlıkları
    row1 = ["Ortak Bilgiler", "Ortak Bilgiler", "Ortak Bilgiler"]
    row2 = ["SIRA NO", "TC KİMLİK NO", "ADI SOYADI"]

    for col_id, col_name, src_label in all_extra_columns:
        row1.append(src_label)
        row2.append(col_name)

    # 1. Satır Yaz
    ws.append(row1)
    for col_num in range(1, len(row1) + 1):
        cell = ws.cell(row=1, column=col_num)
        cell.fill = source_fill
        cell.font = source_font
        cell.alignment = Alignment(horizontal="center", vertical="center")
        cell.border = border_thin

    # 2. Satır Yaz
    ws.append(row2)
    for col_num in range(1, len(row2) + 1):
        cell = ws.cell(row=2, column=col_num)
        cell.fill = header_fill
        cell.font = header_font
        cell.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)
        cell.border = border_thin

    ws.row_dimensions[1].height = 22
    ws.row_dimensions[2].height = 28

    # Verileri Yaz
    curr_row = 3
    sira = 1
    preview_data = []

    for key, rec in merged_records.items():
        data_row = [sira, rec["tc"], rec["ad_soyad"]]
        for col_id, col_name, src_label in all_extra_columns:
            data_row.append(rec["data"].get(col_id, ""))

        ws.append(data_row)

        is_zebra = (sira % 2 == 0)
        for col_num in range(1, len(data_row) + 1):
            cell = ws.cell(row=curr_row, column=col_num)
            cell.font = Font(name="Calibri", size=10)
            cell.border = border_thin
            if is_zebra:
                cell.fill = zebra_fill

            # Hizalama
            if col_num == 1:
                cell.alignment = Alignment(horizontal="center", vertical="center")
            elif col_num == 2:
                cell.alignment = Alignment(horizontal="center", vertical="center")
                cell.number_format = '@'  # Metin formatı (TC sıfırları kaybolmasın)
            else:
                cell.alignment = Alignment(horizontal="left", vertical="center")

        if sira <= 20:
            preview_data.append(data_row)

        curr_row += 1
        sira += 1

    # Sütun genişliklerini otomatik ayarla
    for col in ws.columns:
        max_len = 0
        col_letter = get_column_letter(col[0].column)
        for cell in col:
            val_str = str(cell.value or '')
            if len(val_str) > max_len:
                max_len = len(val_str)
        ws.column_dimensions[col_letter].width = min(max(max_len + 4, 12), 42)

    ws.freeze_panes = "D3"
    ws.auto_filter.ref = f"A2:{get_column_letter(len(row2))}{ws.max_row}"

    # Dosyayı kaydet
    os.makedirs(os.path.dirname(output_filepath), exist_ok=True)
    if len(merged_records) > 5000 or len(row2) > 1000:
        raise ValueError("Birleşik çıktı en fazla 5000 kayıt ve 1000 sütun olabilir.")
    for row in ws:
        for cell in row:
            if isinstance(cell.value, str):
                cell.data_type = 's'
    wb.save(output_filepath)

    return {
        "success": True,
        "output_file": output_filepath,
        "total_records": len(merged_records),
        "total_columns": len(row2),
        "columns": row2,
        "preview_rows": preview_data,
        "summaries": file_summaries
    }


def main():
    if len(sys.argv) < 2:
        print(json.dumps({"success": False, "error": "Geçersiz parametre"}))
        sys.exit(1)

    input_json_path = sys.argv[1]
    if not os.path.exists(input_json_path):
        print(json.dumps({"success": False, "error": "Girdi yapılandırma dosyası bulunamadı"}))
        sys.exit(1)

    try:
        with open(input_json_path, 'r', encoding='utf-8') as f:
            config = json.load(f)

        files = config.get('files', [])
        output = config.get('output', 'output.xlsx')

        result = merge_excel_files(files, output)
        print(json.dumps(result, ensure_ascii=False, indent=2))
    except Exception as e:
        import traceback
        print(json.dumps({
            "success": False,
            "error": str(e),
            "traceback": traceback.format_exc()
        }, ensure_ascii=False))


if __name__ == '__main__':
    main()
