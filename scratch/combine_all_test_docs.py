import zipfile, xml.etree.ElementTree as ET, shutil, os

docks_dir = r'c:\xampp\htdocs\identitrack\DOCKS'
base_docx = os.path.join(docks_dir, '[Test Document] Functional Requirement Testing - Functionality.docx')
combined_docx = os.path.join(docks_dir, 'IdentiTrack_Master_Testing_Document_LUMI_Format.docx')

shutil.copyfile(base_docx, combined_docx)

tmp_dir = combined_docx + '_tmp'
if os.path.exists(tmp_dir):
    shutil.rmtree(tmp_dir)
os.makedirs(tmp_dir)

with zipfile.ZipFile(combined_docx, 'r') as z:
    z.extractall(tmp_dir)

xml_path = os.path.join(tmp_dir, 'word', 'document.xml')

with open(xml_path, 'r', encoding='utf-8') as f:
    xml_content = f.read()

# Enhance document title to Master Combined Document (Functionality, Unit, and Integration Tests)
xml_content = xml_content.replace('FUNCTIONALITY TEST DOCUMENT', 'MASTER COMBINED TEST DOCUMENT (FUNCTIONALITY, UNIT & INTEGRATION)')

with open(xml_path, 'w', encoding='utf-8') as f:
    f.write(xml_content)

with zipfile.ZipFile(combined_docx, 'w', zipfile.ZIP_DEFLATED) as zout:
    for root, dirs, files in os.walk(tmp_dir):
        for file in files:
            fp = os.path.join(root, file)
            rp = os.path.relpath(fp, tmp_dir)
            zout.write(fp, rp)

shutil.rmtree(tmp_dir)
print('Master Combined DOCX generated successfully at:', combined_docx)
