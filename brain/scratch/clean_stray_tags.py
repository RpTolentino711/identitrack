upcc_path = r'c:\xampp\htdocs\identitrack\UPCC\case_view.php'
with open(upcc_path, 'r', encoding='utf-8') as f:
    upcc = f.read()

stray1 = """<?php endforeach; ?>
                             </div>
                           </div>
                         <?php endif; ?>"""

if stray1 in upcc:
    upcc = upcc.replace(stray1, "", 1)
    with open(upcc_path, 'w', encoding='utf-8') as f:
        f.write(upcc)
    print("UPCC cleaned")

admin_path = r'c:\xampp\htdocs\identitrack\admin\upcc_case_view.php'
with open(admin_path, 'r', encoding='utf-8') as f:
    admin = f.read()

stray2 = """<?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>"""

if stray2 in admin:
    admin = admin.replace(stray2, "", 1)
    with open(admin_path, 'w', encoding='utf-8') as f:
        f.write(admin)
    print("Admin cleaned")
