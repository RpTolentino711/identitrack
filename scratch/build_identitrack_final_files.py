import zipfile, os, shutil

docks_dir = r'c:\xampp\htdocs\identitrack\DOCKS'
lumi_base = os.path.join(docks_dir, 'IdentiTrack_Functional_Requirement_Testing_LUMI_Format.docx')

targets = {
    'IdentiTrack_Functional_Test_Document_LUMI.docx': ('FUNCTIONALITY TEST DOCUMENT', 'IdentiTrack System Functional Testing'),
    'IdentiTrack_Unit_Test_Document_LUMI.docx': ('UNIT TEST DOCUMENT', 'IdentiTrack Core Component Unit Testing'),
    'IdentiTrack_Integration_Test_Document_LUMI.docx': ('INTEGRATION TEST DOCUMENT', 'IdentiTrack Multi-Module Integration Testing'),
}

for filename, (title, desc) in targets.items():
    dest = os.path.join(docks_dir, filename)
    shutil.copyfile(lumi_base, dest)
    
    tmp = dest + '_tmp'
    if os.path.exists(tmp):
        shutil.rmtree(tmp)
    os.makedirs(tmp)
    
    with zipfile.ZipFile(dest, 'r') as z:
        z.extractall(tmp)
        
    xml_p = os.path.join(tmp, 'word', 'document.xml')
    with open(xml_p, 'r', encoding='utf-8') as f:
        c = f.read()
        
    c = c.replace('FUNCTIONALITY TEST DOCUMENT', title)
    
    with open(xml_p, 'w', encoding='utf-8') as f:
        f.write(c)
        
    with zipfile.ZipFile(dest, 'w', zipfile.ZIP_DEFLATED) as zout:
        for root, dirs, files in os.walk(tmp):
            for file in files:
                fp = os.path.join(root, file)
                rp = os.path.relpath(fp, tmp)
                zout.write(fp, rp)
                
    shutil.rmtree(tmp)
    print(f'Created {filename} successfully ({os.path.getsize(dest)} bytes)')

print('All final testing document files generated!')
