import zipfile, xml.etree.ElementTree as ET, json

file_path = r'c:\xampp\htdocs\identitrack\admin\data\NU_LIPA DISCIPLINE INFRACTIONS DATA BASE AY 25-26.xlsx'

with zipfile.ZipFile(file_path) as z:
    shared_strings = []
    if 'xl/sharedStrings.xml' in z.namelist():
        tree = ET.fromstring(z.read('xl/sharedStrings.xml'))
        for elem in tree.iter('{http://schemas.openxmlformats.org/spreadsheetml/2006/main}t'):
            shared_strings.append(elem.text)
    
    # Inspect Major Offenses (sheet3.xml)
    sheet_tree = ET.fromstring(z.read('xl/worksheets/sheet3.xml'))
    ns = {'ns': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
    rows = sheet_tree.findall('.//ns:row', ns)
    
    print(f"=== MAJOR OFFENSES SHEET ({len(rows)} rows) ===")
    major_records = []
    for r in rows:
        r_idx = r.attrib.get('r')
        cells = {}
        for c in r.findall('ns:c', ns):
            col_letter = ''.join([ch for ch in c.attrib.get('r') if ch.isalpha()])
            t = c.attrib.get('t')
            v = c.find('ns:v', ns)
            val = v.text if v is not None else ''
            if t == 's' and val.isdigit():
                val = shared_strings[int(val)] if int(val) < len(shared_strings) else val
            cells[col_letter] = val.strip()
        if any(v for v in cells.values()):
            major_records.append((r_idx, cells))
    
    print("Header:", major_records[0] if major_records else 'None')
    print("\nSample Major Offense Records:")
    for r in major_records[1:15]:
        print(r)
