file_path = r'c:\xampp\htdocs\identitrack\admin\offenses_student_view.php'

with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# Replace any occurrence of the modal HTML inside PHP string literals
# Notice how the modal HTML starts with <!-- UPLOAD OFFENSE PHOTO EVIDENCE MODAL -->
import re

pattern = re.compile(r'<!-- UPLOAD OFFENSE PHOTO EVIDENCE MODAL -->.*?<\/script>\s*', re.DOTALL)

# Find all matches
matches = list(pattern.finditer(content))

if len(matches) > 1:
    # If there are multiple occurrences (e.g. one misplaced inside $mail->Body and one at the end)
    # Remove the first occurrence (misplaced inside string literal)
    first_match = matches[0]
    content = content[:first_match.start()] + "</body>\n</html>\n\";\n" + content[first_match.end():]
    with open(file_path, 'w', encoding='utf-8') as f:
        f.write(content)
    print("Fixed misplaced modal snippet")
else:
    print(f"Matches count: {len(matches)}")
