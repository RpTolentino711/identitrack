file_path = r'c:\xampp\htdocs\identitrack\admin\offenses_student_view.php'

with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Remove modal injected into $mail->Body string
bad_snippet = '''                      </body>
                      </html>
                      ";
                      
<!-- UPLOAD OFFENSE PHOTO EVIDENCE MODAL -->
<div id="uploadOffensePhotoModal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); z-index:9999; justify-content:center; align-items:center;">
  <div style="background:#ffffff; border-radius:16px; width:min(90vw, 440px); padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">
      <h3 style="margin:0; font-size:16px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
        <span>📷</span> Upload Incident Photo Evidence
      </h3>
      <button type="button" onclick="closeOffensePhotoUploadModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">✕</button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="update_offense_photo">
      <input type="hidden" name="offense_id" id="modal_upload_offense_id" value="">
      
      <div style="margin-bottom:16px;">
        <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:6px;">Select Incident Photo Image</label>
        <input type="file" name="evidence_photo" accept="image/*" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;">
        <div style="font-size:11px; color:#64748b; margin-top:4px;">Supported formats: JPG, PNG, WEBP.</div>
      </div>
      
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" onclick="closeOffensePhotoUploadModal()" style="padding:8px 16px; background:#f1f5f9; color:#475569; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Cancel</button>
        <button type="submit" style="padding:8px 20px; background:#2563eb; color:#ffffff; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Upload & Save</button>
      </div>
    </form>
  </div>
</div>
<script>
function openOffensePhotoUploadModal(offenseId) {
  document.getElementById('modal_upload_offense_id').value = offenseId;
  document.getElementById('uploadOffensePhotoModal').style.display = 'flex';
}
function closeOffensePhotoUploadModal() {
  document.getElementById('uploadOffensePhotoModal').style.display = 'none';
}
</script>
</body>'''

good_snippet = '''                      </body>
                      </html>
                      ";'''

if bad_snippet in content:
    content = content.replace(bad_snippet, good_snippet, 1)

# 2. Append modal HTML properly at the real end of the document
end_target = '''</body>
</html>'''

modal_at_end = '''<!-- UPLOAD OFFENSE PHOTO EVIDENCE MODAL -->
<div id="uploadOffensePhotoModal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); z-index:9999; justify-content:center; align-items:center;">
  <div style="background:#ffffff; border-radius:16px; width:min(90vw, 440px); padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">
      <h3 style="margin:0; font-size:16px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
        <span>📷</span> Upload Incident Photo Evidence
      </h3>
      <button type="button" onclick="closeOffensePhotoUploadModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">✕</button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="update_offense_photo">
      <input type="hidden" name="offense_id" id="modal_upload_offense_id" value="">
      
      <div style="margin-bottom:16px;">
        <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:6px;">Select Incident Photo Image</label>
        <input type="file" name="evidence_photo" accept="image/*" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;">
        <div style="font-size:11px; color:#64748b; margin-top:4px;">Supported formats: JPG, PNG, WEBP.</div>
      </div>
      
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" onclick="closeOffensePhotoUploadModal()" style="padding:8px 16px; background:#f1f5f9; color:#475569; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Cancel</button>
        <button type="submit" style="padding:8px 20px; background:#2563eb; color:#ffffff; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Upload & Save</button>
      </div>
    </form>
  </div>
</div>
<script>
function openOffensePhotoUploadModal(offenseId) {
  document.getElementById('modal_upload_offense_id').value = offenseId;
  document.getElementById('uploadOffensePhotoModal').style.display = 'flex';
}
function closeOffensePhotoUploadModal() {
  document.getElementById('uploadOffensePhotoModal').style.display = 'none';
}
</script>
</body>
</html>'''

if end_target in content:
    content = content.replace(end_target, modal_at_end, 1)

with open(file_path, 'w', encoding='utf-8') as f:
    f.write(content)
print("admin/offenses_student_view.php fixed successfully")
