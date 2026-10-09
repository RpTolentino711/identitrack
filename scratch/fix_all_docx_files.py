import os, shutil

docks_dir = r'c:\xampp\htdocs\identitrack\DOCKS'
master = os.path.join(docks_dir, 'IdentiTrack_Master_Testing_Document_LUMI_Format.docx')

targets = [
    os.path.join(docks_dir, '[Test Document] Functional Requirement Testing - Functionality.docx'),
    os.path.join(docks_dir, '[Test Document] Functional Requirement Testing - Unit.docx'),
    os.path.join(docks_dir, '[Test Document] Functional Requirement Testing - Integration.docx'),
]

for t in targets:
    shutil.copyfile(master, t)
    print(f'Overwritten clean docx file: {t}')

print('All DOCX files in DOCKS overwritten with clean MS Word compliant XML!')
