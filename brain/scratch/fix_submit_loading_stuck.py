import re

file_path = r'c:\xampp\htdocs\identitrack\admin\offense_new.php'

with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Clean up submit button inline onclick
old_btn = '''                  <button type="submit" id="btnRegisterOffense" class="btn btn-primary" onclick="if(!this.form.checkValidity())return true; const btn=this; setTimeout(() => { btn.disabled=true; btn.innerHTML='<svg viewBox=\\'0 0 24 24\\' fill=\\'none\\' stroke=\\'currentColor\\' stroke-width=\\'2\\' style=\\'animation: spin 1s linear infinite; width:18px;height:18px;margin-right:6px;\\'><path d=\\'M21 12a9 9 0 1 1-6.219-8.56\\'/></svg> Registering...'; }, 10); return true;">
                    <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Register Offense
                  </button>'''

new_btn = '''                  <button type="submit" id="btnRegisterOffense" class="btn btn-primary">
                    <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Register Offense
                  </button>'''

# 2. Update confirmProceedWithoutMinorPhoto and focusMinorPhotoInput
old_modal_js = '''    window.confirmProceedWithoutMinorPhoto = function() {
      const modal = document.getElementById('minorPhotoWarningModal');
      if (modal) modal.style.display = 'none';
      window.__minorPhotoConfirmed = true;
      const form = document.getElementById('offenseForm');
      if (form) form.submit();
    };

    window.focusMinorPhotoInput = function() {
      const modal = document.getElementById('minorPhotoWarningModal');
      if (modal) modal.style.display = 'none';
      const visibleInput = document.getElementById('visible_evidence_input');
      if (visibleInput) {
        visibleInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        visibleInput.focus();
        visibleInput.click();
      }
    };'''

new_modal_js = '''    window.confirmProceedWithoutMinorPhoto = function() {
      const modal = document.getElementById('minorPhotoWarningModal');
      if (modal) modal.style.display = 'none';
      window.__minorPhotoConfirmed = true;
      const form = document.getElementById('offenseForm');
      if (form) {
        const btn = document.getElementById('btnRegisterOffense');
        if (btn) {
          btn.disabled = true;
          btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite; width:18px;height:18px;margin-right:6px;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Registering...';
        }
        form.submit();
      }
    };

    window.focusMinorPhotoInput = function() {
      const modal = document.getElementById('minorPhotoWarningModal');
      if (modal) modal.style.display = 'none';
      const btn = document.getElementById('btnRegisterOffense');
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Register Offense';
      }
      const visibleInput = document.getElementById('visible_evidence_input');
      if (visibleInput) {
        visibleInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        visibleInput.focus();
        visibleInput.click();
      }
    };'''

# 3. Update form submit listener to set loading state ONLY when submission actually proceeds
old_form_submit = '''        if (!isConfirmedApproval) {
            e.preventDefault();
            e.stopPropagation();
            if (!reason) {
              openDismissalReasonModal();
            } else {
              document.getElementById('approvalStudentText').textContent = document.getElementById('studentIdInput')?.value || 'Student';
              document.getElementById('approvalDateText').textContent = (document.getElementById('date_committed')?.value || 'Now').replace('T', ' ');
              const typeSelect = document.getElementById('offense_type_id');
              const selectedTypeOption = typeSelect && typeSelect.selectedIndex >= 0 ? typeSelect.options[typeSelect.selectedIndex] : null;
              document.getElementById('approvalOffenseTypeText').textContent = selectedTypeOption ? selectedTypeOption.text : 'Selected Offense Type';
              document.getElementById('approvalReasonText').textContent = reason;

              const appModal = document.getElementById('dismissalApprovalModal');
              if (appModal) appModal.style.display = 'flex';
            }
            return false;
          }
        }

        return true;'''

new_form_submit = '''        if (!isConfirmedApproval) {
            e.preventDefault();
            e.stopPropagation();
            if (!reason) {
              openDismissalReasonModal();
            } else {
              document.getElementById('approvalStudentText').textContent = document.getElementById('studentIdInput')?.value || 'Student';
              document.getElementById('approvalDateText').textContent = (document.getElementById('date_committed')?.value || 'Now').replace('T', ' ');
              const typeSelect = document.getElementById('offense_type_id');
              const selectedTypeOption = typeSelect && typeSelect.selectedIndex >= 0 ? typeSelect.options[typeSelect.selectedIndex] : null;
              document.getElementById('approvalOffenseTypeText').textContent = selectedTypeOption ? selectedTypeOption.text : 'Selected Offense Type';
              document.getElementById('approvalReasonText').textContent = reason;

              const appModal = document.getElementById('dismissalApprovalModal');
              if (appModal) appModal.style.display = 'flex';
            }
            return false;
          }
        }

        const btn = document.getElementById('btnRegisterOffense');
        if (btn) {
          btn.disabled = true;
          btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite; width:18px;height:18px;margin-right:6px;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Registering...';
        }
        return true;'''

norm_content = content.replace('\r\n', '\n')

updated = False
if old_btn.replace('\r\n', '\n') in norm_content:
    norm_content = norm_content.replace(old_btn.replace('\r\n', '\n'), new_btn.replace('\r\n', '\n'), 1)
    print("SUCCESS: Submit button clean")
    updated = True
else:
    print("WARNING: Submit button not matched")

if old_modal_js.replace('\r\n', '\n') in norm_content:
    norm_content = norm_content.replace(old_modal_js.replace('\r\n', '\n'), new_modal_js.replace('\r\n', '\n'), 1)
    print("SUCCESS: Modal JS updated")
    updated = True
else:
    print("WARNING: Modal JS not matched")

if old_form_submit.replace('\r\n', '\n') in norm_content:
    norm_content = norm_content.replace(old_form_submit.replace('\r\n', '\n'), new_form_submit.replace('\r\n', '\n'), 1)
    print("SUCCESS: Form submit handler updated")
    updated = True
else:
    print("WARNING: Form submit handler not matched")

if updated:
    with open(file_path, 'w', encoding='utf-8') as f:
        f.write(norm_content)
    print("admin/offense_new.php updated successfully")
