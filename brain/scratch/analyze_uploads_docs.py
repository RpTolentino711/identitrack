import os
import docx

folder = r"c:\xampp\htdocs\identitrack\uploads"
docs = [
    "[Test Document] Non-functional Requirement Testing - Mobile Performance Assessment.docx",
    "[Test Document] Non-functional Requirement Testing - Web Accessibility Assessment.docx",
    "[Test Document] Non-functional Requirement Testing - Web Performance Assessment.docx"
]

for doc_name in docs:
    filepath = os.path.join(folder, doc_name)
    print(f"\n==================================================")
    print(f"FILE: {doc_name}")
    print(f"==================================================")
    if not os.path.exists(filepath):
        print("File not found!")
        continue
    
    doc = docx.Document(filepath)
    
    print(f"--- Paragraphs (Total: {len(doc.paragraphs)}) ---")
    for i, p in enumerate(doc.paragraphs):
        text = p.text.strip()
        if text:
            print(f"P{i+1}: {text}")
            
    print(f"\n--- Tables (Total: {len(doc.tables)}) ---")
    for t_idx, table in enumerate(doc.tables):
        print(f"\nTable {t_idx+1} ({len(table.rows)} rows, {len(table.columns)} cols):")
        for r_idx, row in enumerate(table.rows):
            row_data = [cell.text.strip().replace('\n', ' ') for cell in row.cells]
            # print unique cells if merged
            print(f"  Row {r_idx+1}: {' | '.join(row_data)}")
