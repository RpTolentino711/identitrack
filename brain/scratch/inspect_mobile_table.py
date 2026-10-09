import docx

doc_path = r"c:\xampp\htdocs\identitrack\uploads\[Test Document] Non-functional Requirement Testing - Mobile Performance Assessment.docx"
doc = docx.Document(doc_path)

table = doc.tables[0]
for r_idx, row in enumerate(table.rows):
    cells_text = [f"C{c_idx}: '{cell.text.strip()}'" for c_idx, cell in enumerate(row.cells)]
    print(f"Row {r_idx}: {len(row.cells)} cells -> {' | '.join(cells_text)}")
