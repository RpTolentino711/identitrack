file_path = r'c:\xampp\htdocs\identitrack\admin\offenses_student_view.php'

with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

bad_closing = '''</body>
</html>
";
</body>

                      </html>
                      ";'''

good_closing = '''                      </body>
                      </html>
                      ";'''

if bad_closing in content:
    content = content.replace(bad_closing, good_closing, 1)
    with open(file_path, 'w', encoding='utf-8') as f:
        f.write(content)
    print("Fixed closing tags in admin/offenses_student_view.php")
else:
    # Try normalized
    norm_content = content.replace('\r\n', '\n')
    norm_bad = bad_closing.replace('\r\n', '\n')
    norm_good = good_closing.replace('\r\n', '\n')
    if norm_bad in norm_content:
        norm_content = norm_content.replace(norm_bad, norm_good, 1)
        with open(file_path, 'w', encoding='utf-8') as f:
            f.write(norm_content)
        print("Fixed closing tags (normalized)")
