import re

# Fix UPCC/case_view.php
upcc_path = r'c:\xampp\htdocs\identitrack\UPCC\case_view.php'
with open(upcc_path, 'r', encoding='utf-8') as f:
    upcc = f.read()

stray_upcc = """                          <?php endif; ?>
                                <?php endforeach; ?>
                              </div>
                            </div>
                          <?php endif; ?>"""

if stray_upcc in upcc:
    upcc = upcc.replace(stray_upcc, "                          <?php endif; ?>", 1)
    with open(upcc_path, 'w', encoding='utf-8') as f:
        f.write(upcc)
    print("Fixed UPCC/case_view.php stray closing tags")
else:
    print("stray_upcc pattern not found, checking with regex")
    upcc = re.sub(
        r'(<\?php endif; \?>)\s*<\?php endforeach; \?>\s*</div>\s*</div>\s*<\?php endif; \?>',
        r'\1',
        upcc,
        count=1
    )
    with open(upcc_path, 'w', encoding='utf-8') as f:
        f.write(upcc)
    print("Fixed UPCC/case_view.php via regex")


# Fix admin/upcc_case_view.php
admin_path = r'c:\xampp\htdocs\identitrack\admin\upcc_case_view.php'
with open(admin_path, 'r', encoding='utf-8') as f:
    admin = f.read()

stray_admin = """              <?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>"""

if stray_admin in admin:
    admin = admin.replace(stray_admin, "              <?php endif; ?>", 1)
    with open(admin_path, 'w', encoding='utf-8') as f:
        f.write(admin)
    print("Fixed admin/upcc_case_view.php stray closing tags")
else:
    print("stray_admin pattern not found, checking with regex")
    admin = re.sub(
        r'(<\?php endif; \?>)\s*</div>\s*</div>\s*<\?php endif; \?>',
        r'\1',
        admin,
        count=1
    )
    with open(admin_path, 'w', encoding='utf-8') as f:
        f.write(admin)
    print("Fixed admin/upcc_case_view.php via regex")
