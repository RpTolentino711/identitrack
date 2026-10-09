import zipfile, xml.etree.ElementTree as ET, shutil, os

docks_dir = r'c:\xampp\htdocs\identitrack\DOCKS'
lumi_format_file = os.path.join(docks_dir, 'IdentiTrack_Functional_Requirement_Testing_LUMI_Format.docx')
unit_file = os.path.join(docks_dir, '[Test Document] Functional Requirement Testing - Unit.docx')
func_file = os.path.join(docks_dir, '[Test Document] Functional Requirement Testing - Functionality.docx')
integ_file = os.path.join(docks_dir, '[Test Document] Functional Requirement Testing - Integration.docx')

# Copy populated LUMI format docx into Functionality, Unit, and Integration docx files
shutil.copyfile(lumi_format_file, func_file)
shutil.copyfile(lumi_format_file, unit_file)
shutil.copyfile(lumi_format_file, integ_file)

# Helper function to replace header title in docx XML
def set_doc_title(docx_target, old_title, new_title):
    tmp = docx_target + '_tmp'
    if os.path.exists(tmp):
        shutil.rmtree(tmp)
    os.makedirs(tmp)
    with zipfile.ZipFile(docx_target, 'r') as z:
        z.extractall(tmp)
    xml_path = os.path.join(tmp, 'word', 'document.xml')
    with open(xml_path, 'r', encoding='utf-8') as f:
        c = f.read()
    c = c.replace(old_title, new_title)
    with open(xml_path, 'w', encoding='utf-8') as f:
        f.write(c)
    with zipfile.ZipFile(docx_target, 'w', zipfile.ZIP_DEFLATED) as zout:
        for root, dirs, files in os.walk(tmp):
            for file in files:
                fp = os.path.join(root, file)
                rp = os.path.relpath(fp, tmp)
                zout.write(fp, rp)
    shutil.rmtree(tmp)

set_doc_title(unit_file, 'FUNCTIONALITY TEST DOCUMENT', 'UNIT TEST DOCUMENT')
set_doc_title(integ_file, 'FUNCTIONALITY TEST DOCUMENT', 'INTEGRATION TEST DOCUMENT')

print('All 3 IdentiTrack DOCX files updated successfully!')
