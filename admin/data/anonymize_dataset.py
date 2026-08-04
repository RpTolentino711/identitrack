import json, os, re

dataset_path = r'c:\xampp\htdocs\identitrack\storage\dataset\sanction_history_dataset.json'

if os.path.exists(dataset_path):
    with open(dataset_path, 'r', encoding='utf-8') as f:
        data = json.load(f)
    
    def mask_phone(phone):
        if not phone:
            return ''
        phone_str = re.sub(r'\D', '', str(phone))
        if len(phone_str) >= 7:
            return phone_str[:3] + '*****' + phone_str[-2:]
        return '*****'

    if 'major_cases' in data:
        for idx, item in enumerate(data['major_cases']):
            prog = item.get('program', 'Student') or 'Student'
            c_no = item.get('case_no', '') or f"Case #{idx+1}"
            
            # Anonymize student name and guardian names for Data Privacy Act RA 10173
            item['name'] = f"{prog} Student ({c_no})"
            if item.get('guardian'):
                item['guardian'] = f"Guardian of Case {c_no}"
            item['guardian_contact'] = mask_phone(item.get('guardian_contact'))
            item['student_contact'] = mask_phone(item.get('student_contact'))

    with open(dataset_path, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=2)

    print("Successfully anonymized student names and phone numbers in sanction_history_dataset.json!")
