import zipfile, xml.etree.ElementTree as ET, json, os

file_path = r'c:\xampp\htdocs\identitrack\admin\data\NU_LIPA DISCIPLINE INFRACTIONS DATA BASE AY 25-26.xlsx'
out_path = r'c:\xampp\htdocs\identitrack\storage\dataset\sanction_history_dataset.json'

with zipfile.ZipFile(file_path) as z:
    shared_strings = []
    if 'xl/sharedStrings.xml' in z.namelist():
        tree = ET.fromstring(z.read('xl/sharedStrings.xml'))
        for elem in tree.iter('{http://schemas.openxmlformats.org/spreadsheetml/2006/main}t'):
            shared_strings.append(elem.text)
    
    # 1. Parse MAJOR OFFENSES
    sheet_tree = ET.fromstring(z.read('xl/worksheets/sheet3.xml'))
    ns = {'ns': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
    rows = sheet_tree.findall('.//ns:row', ns)
    
    major_cases = []
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
        
        name = cells.get('C', '')
        if name and name != 'NAME':
            major_cases.append({
                'no': cells.get('A', ''),
                'case_no': cells.get('B', ''),
                'name': name,
                'program': cells.get('D', ''),
                'offense': cells.get('E', ''),
                'sanction': cells.get('F', ''),
                'cs_hours': cells.get('G', ''),
                'office': cells.get('H', ''),
                'status_remarks': cells.get('I', ''),
                'guardian': cells.get('J', ''),
                'guardian_contact': cells.get('K', ''),
                'student_contact': cells.get('L', '')
            })

    # 2. Parse 24-25 & 25-26 sheets summary
    sheet1_rows = len(ET.fromstring(z.read('xl/worksheets/sheet1.xml')).findall('.//ns:row', ns)) - 1
    sheet2_rows = len(ET.fromstring(z.read('xl/worksheets/sheet2.xml')).findall('.//ns:row', ns)) - 1

dataset_output = {
    'summary': {
        'total_major_cases': len(major_cases),
        'infraction_logs_24_25': sheet1_rows,
        'infraction_logs_25_26': sheet2_rows,
        'total_campus_records': len(major_cases) + sheet1_rows + sheet2_rows
    },
    'major_cases': major_cases
}

os.makedirs(os.path.dirname(out_path), exist_ok=True)
with open(out_path, 'w', encoding='utf-8') as f:
    json.dump(dataset_output, f, indent=2)

print(f"Successfully exported official NU Lipa dataset!")
print(f"Total Major Disciplinary Cases: {len(major_cases)}")
print(f"Total Infraction Forms (2024-2026): {sheet1_rows + sheet2_rows}")
print(f"Total Combined Records: {len(major_cases) + sheet1_rows + sheet2_rows}")
