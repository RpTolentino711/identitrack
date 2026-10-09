import re

file_path = r'c:\xampp\htdocs\identitrack\admin\offense_new.php'

with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Remove Enable Custom Notes checkbox from the HTML form
old_label_html = '''                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
                      <label for="description" id="descLabel" style="margin-bottom:0;">Description / Notes <span id="descOptional" style="<?php echo $isDescRequired ? 'color:var(--red); font-weight:800;' : ''; ?>"><?php echo $isDescRequired ? ($level === 'MAJOR' ? '* (REQUIRED FOR MAJOR OFFENSES)' : '* (Required)') : '(optional)'; ?></span></label>
                      <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:var(--blue); cursor:pointer; text-transform:none; letter-spacing:0;">
                        <input type="checkbox" id="enable_custom_notes" onchange="toggleCustomNotes(this)" <?php echo $isNotesEnabled ? 'checked' : ''; ?> <?php echo $isDescRequired ? 'disabled' : ''; ?> style="width:16px; height:16px; cursor:pointer;">
                        <span>Enable Custom Notes</span>
                      </label>
                    </div>
                    <textarea id="description" name="description" <?php echo $isDescRequired ? 'required' : ''; ?> <?php echo (!$isNotesEnabled && !$isDescRequired) ? 'disabled' : ''; ?>
                              placeholder="Describe the incident in detail..."><?php echo htmlspecialchars($postDesc); ?></textarea>'''

new_label_html = '''                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
                      <label for="description" id="descLabel" style="margin-bottom:0;">Description / Notes <span id="descOptional" style="color:var(--red); font-weight:800;">* (Required)</span></label>
                    </div>
                    <textarea id="description" name="description" required
                              placeholder="Describe the incident in detail..."><?php echo htmlspecialchars($postDesc); ?></textarea>'''

# 2. Update minorPhotoWarningModal text and button actions
old_modal_html = '''  <!-- MODAL: Minor Offense Photo Warning Confirmation -->
  <div id="minorPhotoWarningModal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(15,23,42,0.75); z-index:999999; align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; max-width:440px; width:92%; border-radius:16px; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); text-align:center;">
      <div style="font-size:42px; margin-bottom:12px;">📷</div>
      <h3 style="font-size:18px; font-weight:800; color:#1e293b; margin-bottom:8px;">No Incident Photo Uploaded</h3>
      <p style="font-size:13px; color:#475569; line-height:1.5; margin-bottom:20px;">
        You are about to register a Minor Offense without uploading an Incident Photo. Would you like to proceed or upload a photo now?
      </p>
      <div style="display:flex; gap:10px; justify-content:center;">
        <button type="button" class="btn" onclick="confirmProceedWithoutMinorPhoto()" style="flex:1; padding:10px 12px; font-weight:700; border-radius:8px;">Proceed Without Photo</button>
        <button type="button" class="btn btn-primary" onclick="focusMinorPhotoInput()" style="flex:1; padding:10px 12px; font-weight:700; background:#2563eb; color:#fff; border-radius:8px;">Upload Photo Now</button>
      </div>
    </div>
  </div>'''

new_modal_html = '''  <!-- MODAL: Minor Offense Photo Warning Confirmation -->
  <div id="minorPhotoWarningModal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(15,23,42,0.75); z-index:999999; align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; max-width:440px; width:92%; border-radius:16px; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); text-align:center;">
      <div style="font-size:42px; margin-bottom:12px;">📷</div>
      <h3 style="font-size:18px; font-weight:800; color:#1e293b; margin-bottom:8px;">No Incident Photo Uploaded</h3>
      <p style="font-size:13px; color:#475569; line-height:1.5; margin-bottom:20px;">
        You didn't upload an incident photo. Are you sure you want to register this offense without uploading a photo?
      </p>
      <div style="display:flex; gap:10px; justify-content:center;">
        <button type="button" class="btn btn-primary" onclick="confirmProceedWithoutMinorPhoto()" style="flex:1; padding:10px 12px; font-weight:700; background:#2563eb; color:#fff; border-radius:8px;">Yes, Register Offense</button>
        <button type="button" class="btn" onclick="focusMinorPhotoInput()" style="flex:1; padding:10px 12px; font-weight:700; background:#f1f5f9; color:#475569; border-radius:8px;">No, Upload Photo</button>
      </div>
    </div>
  </div>'''

# 3. Update updateDescRequirement and checkFormValidity in JS
old_js_validation = '''    function updateDescRequirement() {
      const levelSelect = document.getElementById('levelSelect');
      const lvl = (levelSelect ? levelSelect.value : '').toUpperCase();
      const typeSelect = document.getElementById('offense_type_id');
      const typeId = typeSelect ? typeSelect.value : '';
      const descOpt = document.getElementById('descOptional');
      const descInput = document.getElementById('description');
      const notesCheckbox = document.getElementById('enable_custom_notes');
      const isRequired = (lvl === 'MAJOR' || lvl === 'DISMISSED' || ['22', '23', '24'].includes(typeId));

      if (notesCheckbox) {
        if (isRequired) {
          notesCheckbox.checked = true;
          notesCheckbox.disabled = true;
        } else {
          notesCheckbox.disabled = false;
        }
      }

      if (descOpt) {
        if (isRequired) {
          if (lvl === 'MAJOR') {
            descOpt.innerHTML = ' <span style="color:#dc2626; font-weight:800; font-size:13px;">* (REQUIRED FOR MAJOR OFFENSES)</span>';
          } else {
            descOpt.innerHTML = ' <span style="color:#dc2626; font-weight:800; font-size:13px;">* (Required)</span>';
          }
        } else {
          descOpt.innerHTML = ' <span style="color:#64748b; font-weight:normal;">(optional)</span>';
        }
      }
      if (descInput) {
        if (isRequired) {
          descInput.setAttribute('required', 'required');
          descInput.disabled = false;
        } else {
          descInput.removeAttribute('required');
          if (notesCheckbox && !notesCheckbox.checked) {
            descInput.disabled = true;
          } else {
            descInput.disabled = false;
          }
        }
      }

      checkFormValidity();
    }

    function checkFormValidity() {
      const levelSelect = document.getElementById('levelSelect');
      const lvl = (levelSelect ? levelSelect.value : '').toUpperCase();
      const typeSelect = document.getElementById('offense_type_id');
      const typeId = typeSelect ? typeSelect.value : '';
      const descInput = document.getElementById('description');
      const submitBtn = document.getElementById('btnRegisterOffense') || document.querySelector('.form-actions button[type="submit"]');

      const isRequired = (lvl === 'MAJOR' || lvl === 'DISMISSED' || ['22', '23', '24'].includes(typeId));
      const descVal = (descInput ? descInput.value : '').trim();

      if (submitBtn) {
        if (isRequired && descVal === '') {
          submitBtn.disabled = true;
          submitBtn.style.opacity = '0.55';
          submitBtn.style.cursor = 'not-allowed';
          submitBtn.title = 'Please provide incident Description / Notes before registering a Major Offense.';
        } else {
          submitBtn.disabled = false;
          submitBtn.style.opacity = '1';
          submitBtn.style.cursor = 'pointer';
          submitBtn.title = '';
        }
      }
    }'''

new_js_validation = '''    function updateDescRequirement() {
      const levelSelect = document.getElementById('levelSelect');
      const lvl = (levelSelect ? levelSelect.value : '').toUpperCase();
      const descOpt = document.getElementById('descOptional');
      const descInput = document.getElementById('description');

      if (descOpt) {
        if (lvl === 'MAJOR') {
          descOpt.innerHTML = ' <span style="color:#dc2626; font-weight:800; font-size:13px;">* (REQUIRED FOR MAJOR OFFENSES)</span>';
        } else {
          descOpt.innerHTML = ' <span style="color:#dc2626; font-weight:800; font-size:13px;">* (Required)</span>';
        }
      }
      if (descInput) {
        descInput.setAttribute('required', 'required');
        descInput.disabled = false;
      }

      checkFormValidity();
    }

    function checkFormValidity() {
      const descInput = document.getElementById('description');
      const submitBtn = document.getElementById('btnRegisterOffense') || document.querySelector('.form-actions button[type="submit"]');
      const descVal = (descInput ? descInput.value : '').trim();

      if (submitBtn) {
        if (descVal === '') {
          submitBtn.disabled = true;
          submitBtn.style.opacity = '0.55';
          submitBtn.style.cursor = 'not-allowed';
          submitBtn.title = 'Please provide incident Description / Notes before registering an offense.';
        } else {
          submitBtn.disabled = false;
          submitBtn.style.opacity = '1';
          submitBtn.style.cursor = 'pointer';
          submitBtn.title = '';
        }
      }
    }'''

norm_content = content.replace('\r\n', '\n')

updated = False
if old_label_html.replace('\r\n', '\n') in norm_content:
    norm_content = norm_content.replace(old_label_html.replace('\r\n', '\n'), new_label_html.replace('\r\n', '\n'), 1)
    print("SUCCESS: Form label updated")
    updated = True
else:
    print("WARNING: Label block not matched")

if old_modal_html.replace('\r\n', '\n') in norm_content:
    norm_content = norm_content.replace(old_modal_html.replace('\r\n', '\n'), new_modal_html.replace('\r\n', '\n'), 1)
    print("SUCCESS: Modal text updated")
    updated = True
else:
    print("WARNING: Modal block not matched")

if old_js_validation.replace('\r\n', '\n') in norm_content:
    norm_content = norm_content.replace(old_js_validation.replace('\r\n', '\n'), new_js_validation.replace('\r\n', '\n'), 1)
    print("SUCCESS: JS validation updated")
    updated = True
else:
    print("WARNING: JS validation block not matched")

if updated:
    with open(file_path, 'w', encoding='utf-8') as f:
        f.write(norm_content)
    print("admin/offense_new.php updated successfully")
