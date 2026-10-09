file_path = r'c:\xampp\htdocs\identitrack\admin\offenses_student_view.php'

with open(file_path, 'r', encoding='utf-8') as f:
    lines = f.readlines()

print("Line 250 to 275:")
for idx, line in enumerate(lines[245:275], 246):
    print(f"{idx}: {line}", end='')

# Find where `$mail->Body = "` starts and where `";` ends
body_start = -1
body_end = -1

for idx, line in enumerate(lines):
    if "$mail->Body =" in line:
        body_start = idx
    if body_start != -1 and '";' in line and idx > body_start:
        body_end = idx
        break

print(f"\nMail body range: {body_start+1} to {body_end+1}")
clean_mail_body = '''                      $mail->Body = "
                      <!DOCTYPE html>
                      <html lang='en'>
                      <head>
                        <meta charset='UTF-8'>
                        <style>
                          body { margin: 0; padding: 0; background-color: #f1f5f9; }
                          .wrapper { width: 100%; table-layout: fixed; background-color: #f1f5f9; padding: 40px 0; }
                          .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.08); font-family: 'Inter', -apple-system, sans-serif; }
                          .header { background-image: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); padding: 50px 40px; text-align: center; }
                          .logo-img { display: block; width: 85px; height: auto; margin: 0 auto 20px auto; border-radius: 18px; box-shadow: 0 8px 16px rgba(0,0,0,0.15); }
                          .content { padding: 40px 50px; color: #374151; font-size: 15px; line-height: 1.6; }
                          h1 { color: #ffffff; margin: 0; font-size: 26px; font-weight: 800; letter-spacing: -0.5px; }
                          .badge { display: inline-block; padding: 6px 14px; background-color: rgba(255,255,255,0.15); color: #ffffff; font-size: 12px; font-weight: 600; border-radius: 100px; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 1px; }
                          .footer { padding: 30px; text-align: center; background-color: #f8fafc; border-top: 1px solid #f1f5f9; font-size: 13px; color: #94a3b8; }
                        </style>
                      </head>
                      <body>
                        <div class='wrapper'>
                          <div class='email-container'>
                            <div class='header'>
                              <div class='badge'>Official Notice</div>
                              <img src='https://identitrack.site/assets/logo.png' alt='IdentiTrack' class='logo-img'>
                              <h1>Student Discipline Office</h1>
                            </div>
                            <div class='content'>
                              <p style='font-weight:600;font-size:16px;color:#1e293b;margin-top:0;'>Dear Parent/Guardian,</p>
                              <p>Please review the attached official notice letter regarding the disciplinary record of <strong>{$studentName}</strong>.</p>
                              <p style='margin-top:24px;margin-bottom:0;'>If you have any questions, please coordinate with the Student Discipline Office or the University Panel on Community Conduct.</p>
                            </div>
                            <div class='footer'>
                              &copy; " . date('Y') . " IdentiTrack System. All rights reserved.<br>This is an automated notification. Please do not reply.
                            </div>
                          </div>
                        </div>
                      </body>
                      </html>
                      ";\n'''

if body_start != -1 and body_end != -1:
    lines[body_start:body_end+1] = [clean_mail_body]
    with open(file_path, 'w', encoding='utf-8') as f:
        f.writelines(lines)
    print("Fixed mail body in admin/offenses_student_view.php")
