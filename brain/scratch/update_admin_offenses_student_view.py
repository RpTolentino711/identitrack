import re

file_path = r'c:\xampp\htdocs\identitrack\admin\offenses_student_view.php'

with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Update POST action handler
old_post = '''$scanFlashKey = trim((string)($_GET['scan_msg'] ?? ''));
if ($scanFlashKey === 'pending_guard_found') {
  $scanFlash = 'A pending guard report was found for this student.';
} elseif ($scanFlashKey === 'no_offense_record') {
  $scanFlash = 'No existing offense record found for this student.';
} elseif ($scanFlashKey === 'student_not_found') {
  $scanFlash = 'No student match found for the scanned ID.';
}'''

new_post = '''$scanFlashKey = trim((string)($_GET['scan_msg'] ?? ''));
if ($scanFlashKey === 'pending_guard_found') {
  $scanFlash = 'A pending guard report was found for this student.';
} elseif ($scanFlashKey === 'no_offense_record') {
  $scanFlash = 'No existing offense record found for this student.';
} elseif ($scanFlashKey === 'student_not_found') {
  $scanFlash = 'No student match found for the scanned ID.';
} elseif ($scanFlashKey === 'photo_updated') {
  $scanFlash = 'Photo evidence updated successfully for offense.';
}'''

# 2. Add POST handler for update_offense_photo inside POST block
old_action_block = '''if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  $reportId = (int)($_POST['report_id'] ?? 0);'''

new_action_block = '''if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  $reportId = (int)($_POST['report_id'] ?? 0);

  if ($action === 'update_offense_photo') {
    $offenseIdToUpdate = (int)($_POST['offense_id'] ?? 0);
    if ($offenseIdToUpdate > 0 && isset($_FILES['evidence_photo']) && $_FILES['evidence_photo']['error'] === UPLOAD_ERR_OK) {
      $tmpName = $_FILES['evidence_photo']['tmp_name'];
      $filename = $_FILES['evidence_photo']['name'];
      $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
      $allowed = ['jpg', 'jpeg', 'png', 'webp'];
      if (in_array($ext, $allowed, true)) {
        $uploadDir = __DIR__ . '/../uploads/incident_reports/';
        if (!is_dir($uploadDir)) {
          mkdir($uploadDir, 0777, true);
        }
        $newName = 'evidence_' . time() . '_' . uniqid() . '.' . $ext;
        $targetFile = $uploadDir . $newName;
        if (move_uploaded_file($tmpName, $targetFile)) {
          $dbPath = 'uploads/incident_reports/' . $newName;
          db_exec(
            "UPDATE offense SET evidence_file = :ev, updated_at = NOW() WHERE offense_id = :oid AND student_id = :sid",
            [':ev' => $dbPath, ':oid' => $offenseIdToUpdate, ':sid' => $studentId]
          );
          redirect('offenses_student_view.php?student_id=' . urlencode($studentId) . '&scan_msg=photo_updated');
        }
      }
    }
  }'''

# 3. Update SQL queries to fetch COALESCE(o.evidence_file, o.incident_photo) AS offense_photo
old_query_h = "o.level, o.incident_photo AS offense_photo,"
new_query_h = "o.level, COALESCE(o.evidence_file, o.incident_photo) AS offense_photo,"

old_query_l = "SELECT o.offense_id, o.date_committed, " + '"' + " . db_decrypt_col('description', 'o') . " + '"' + " AS description, ot.code, ot.name"
new_query_l = "SELECT o.offense_id, o.date_committed, " + '"' + " . db_decrypt_col('description', 'o') . " + '"' + " AS description, COALESCE(o.evidence_file, o.incident_photo) AS offense_photo, ot.code, ot.name"

norm_content = content.replace('\r\n', '\n')

norm_content = norm_content.replace(old_post.replace('\r\n', '\n'), new_post.replace('\r\n', '\n'), 1)
norm_content = norm_content.replace(old_action_block.replace('\r\n', '\n'), new_action_block.replace('\r\n', '\n'), 1)
norm_content = norm_content.replace(old_query_h, new_query_h, 1)

# Update escalation minor items to include upload button
old_esc_item = '''                          <div>
                            <div class="escalation-minor-name"><?php echo e((string)$minor['name']); ?></div>
                            <div class="escalation-minor-meta">'''

new_esc_item = '''                          <div>
                            <div class="escalation-minor-name"><?php echo e((string)$minor['name']); ?></div>
                            <div class="escalation-minor-meta">'''

# Update individual minor cards rendering
old_minor_card = '''                      <?php $mPhoto = get_evidence_photo_url($minor['offense_photo'] ?? ''); ?>
                      <?php if ($mPhoto !== ''): ?>
                        <div style="margin-top:10px; margin-bottom:10px; padding:10px 14px; background:#fffbeb; border:1px solid #fde68a; border-radius:10px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
                          <div style="display:flex; align-items:center; gap:8px;">
                            <div style="width:32px; height:32px; border-radius:8px; background:#fef3c7; display:grid; place-items:center; color:#b45309; flex-shrink:0;">
                              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;">
                                <path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                              </svg>
                            </div>
                            <div>
                              <div style="font-size:11.5px; font-weight:800; color:#b45309; text-transform:uppercase; letter-spacing:0.4px;">Photo Evidence Attached</div>
                              <div style="font-size:11px; color:#d97706;">Click thumbnail to inspect full image</div>
                            </div>
                          </div>
                          <a href="<?= htmlspecialchars($mPhoto) ?>" target="_blank" style="display:block;" title="Click to view full photo evidence">
                            <img src="<?= htmlspecialchars($mPhoto) ?>" alt="Photo Evidence" style="width:54px; height:54px; object-fit:cover; border-radius:8px; border:1.5px solid #fde68a; box-shadow:0 3px 8px rgba(180,83,9,0.15); transition:transform 0.15s ease;" onmouseover="this.style.transform='scale(1.08)';" onmouseout="this.style.transform='scale(1)';" />
                          </a>
                        </div>
                      <?php endif; ?>'''

new_minor_card = '''                      <?php $mPhoto = get_evidence_photo_url($minor['offense_photo'] ?? ''); ?>
                      <?php if ($mPhoto !== ''): ?>
                        <div style="margin-top:10px; margin-bottom:10px; padding:10px 14px; background:#fffbeb; border:1px solid #fde68a; border-radius:10px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
                          <div style="display:flex; align-items:center; gap:8px;">
                            <div style="width:32px; height:32px; border-radius:8px; background:#fef3c7; display:grid; place-items:center; color:#b45309; flex-shrink:0;">
                              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;">
                                <path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                              </svg>
                            </div>
                            <div>
                              <div style="font-size:11.5px; font-weight:800; color:#b45309; text-transform:uppercase; letter-spacing:0.4px;">Photo Evidence Attached</div>
                              <div style="font-size:11px; color:#d97706;">Click thumbnail to inspect full image</div>
                            </div>
                          </div>
                          <div style="display:flex; align-items:center; gap:8px;">
                            <a href="<?= htmlspecialchars($mPhoto) ?>" target="_blank" style="display:block;" title="Click to view full photo evidence">
                              <img src="<?= htmlspecialchars($mPhoto) ?>" alt="Photo Evidence" style="width:54px; height:54px; object-fit:cover; border-radius:8px; border:1.5px solid #fde68a; box-shadow:0 3px 8px rgba(180,83,9,0.15); transition:transform 0.15s ease;" onmouseover="this.style.transform='scale(1.08)';" onmouseout="this.style.transform='scale(1)';" />
                            </a>
                            <button type="button" onclick="openOffensePhotoUploadModal(<?= (int)$minor['offense_id'] ?>)" class="btn btn-sm" style="background:#fef3c7; color:#b45309; border:1px solid #fcd34d; font-size:11px; font-weight:700; padding:4px 8px; border-radius:6px; cursor:pointer;" title="Replace Photo Evidence">📷 Replace</button>
                          </div>
                        </div>
                      <?php else: ?>
                        <div style="margin-top:6px; margin-bottom:6px;">
                          <button type="button" onclick="openOffensePhotoUploadModal(<?= (int)($minor['offense_id'] ?? 0) ?>)" class="btn btn-sm" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; font-size:11px; font-weight:700; padding:4px 10px; border-radius:6px; cursor:pointer; display:inline-flex; align-items:center; gap:4px;">📷 Upload Photo Evidence</button>
                        </div>
                      <?php endif; ?>'''

norm_content = norm_content.replace(old_minor_card.replace('\r\n', '\n'), new_minor_card.replace('\r\n', '\n'), 1)

# Append modal at the bottom before </body>
modal_html = '''
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
</body>
'''

norm_content = norm_content.replace('</body>', modal_html, 1)

with open(file_path, 'w', encoding='utf-8') as f:
    f.write(norm_content)
print("admin/offenses_student_view.php updated successfully")
