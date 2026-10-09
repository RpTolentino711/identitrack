import re
import sys

# 1. UPDATE UPCC/case_view.php
upcc_path = r'c:\xampp\htdocs\identitrack\UPCC\case_view.php'
with open(upcc_path, 'r', encoding='utf-8') as f:
    upcc_content = f.read()

# Pattern for top card in UPCC/case_view.php
upcc_top_pattern = re.compile(
    r'<!-- Incident Report & Photo Evidence Attachment Card -->\s*<\?php\s*\$allEvidenceFiles = \[\];.*?<\?php endif; \?>',
    re.DOTALL
)

# If $allEvidenceFiles is already partially inserted by previous edit
if not upcc_top_pattern.search(upcc_content):
    upcc_top_pattern = re.compile(
        r'<!-- Incident Report & Photo Evidence Attachment Card -->\s*<\?php\s*\$caseEvidenceFile = \$case\[\'evidence_file\'\].*?<\?php endif; \?>',
        re.DOTALL
    )

upcc_top_replacement = """<!-- Incident Report & Photo Evidence Attachment Card -->
                         <?php 
                           $allEvidenceFiles = [];
                           if (!empty($case['evidence_file'])) {
                               $allEvidenceFiles[] = [
                                   'label' => 'Case Evidence File',
                                   'file'  => $case['evidence_file']
                               ];
                           }
                           if (!empty($offenses)) {
                               foreach ($offenses as $off) {
                                   $evPath = $off['evidence_file'] ?? ($off['incident_photo'] ?? null);
                                   if (!empty($evPath)) {
                                       $already = false;
                                       foreach ($allEvidenceFiles as $item) {
                                           if ($item['file'] === $evPath) {
                                               $already = true;
                                               break;
                                           }
                                       }
                                       if (!$already) {
                                           $allEvidenceFiles[] = [
                                               'label' => ($off['code'] ?? 'Offense') . ' Evidence',
                                               'file'  => $evPath
                                           ];
                                       }
                                   }
                               }
                           }
                         ?>
                         <?php if (!empty($allEvidenceFiles)): ?>
                           <div style="margin-bottom: 24px; background: rgba(59, 130, 246, 0.1); border: 1.5px solid rgba(59, 130, 246, 0.3); border-radius: 12px; padding: 16px;">
                             <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                               <span style="font-size: 12px; font-weight: 800; color: #60a5fa; text-transform: uppercase; letter-spacing: 1px; display: flex; align-items: center; gap: 6px;">
                                 <span>📷</span> Official Incident Report & Photo Evidence Attachment (<?= count($allEvidenceFiles) ?>)
                               </span>
                               <span style="font-size: 11px; color: var(--text-muted);">Attached during case / offense registration</span>
                             </div>
                             <div style="display: flex; gap: 16px; align-items: flex-start; flex-wrap: wrap;">
                               <?php foreach ($allEvidenceFiles as $evItem): 
                                 $evFile = $evItem['file'];
                                 $ext = strtolower(pathinfo($evFile, PATHINFO_EXTENSION));
                                 $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
                               ?>
                                 <?php if ($isImg): ?>
                                   <div style="display: flex; flex-direction: column; gap: 6px; align-items: flex-start;">
                                     <a href="../<?= htmlspecialchars($evFile) ?>" target="_blank" title="Click to view full resolution evidence photo (<?= htmlspecialchars($evItem['label']) ?>)" style="display: block; border-radius: 10px; overflow: hidden; border: 2px solid rgba(255,255,255,0.2); box-shadow: 0 4px 12px rgba(0,0,0,0.3); transition: transform 0.2s;">
                                       <img src="../<?= htmlspecialchars($evFile) ?>" style="max-width: 220px; max-height: 150px; width: auto; height: auto; display: block; object-fit: cover;">
                                     </a>
                                     <span style="font-size: 11px; font-weight: 700; color: #93c5fd;"><?= htmlspecialchars($evItem['label']) ?></span>
                                   </div>
                                 <?php else: ?>
                                   <div style="display: flex; flex-direction: column; gap: 6px;">
                                     <a href="../<?= htmlspecialchars($evFile) ?>" target="_blank" style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; background: rgba(239, 68, 68, 0.2); border: 1px solid rgba(239, 68, 68, 0.4); border-radius: 8px; text-decoration: none; color: #fca5a5; font-size: 12px; font-weight: 700;">
                                       <span>📄 View Document (<?= htmlspecialchars($evItem['label']) ?>)</span>
                                     </a>
                                   </div>
                                 <?php endif; ?>
                               <?php endforeach; ?>
                             </div>
                           </div>
                         <?php endif; ?>"""

new_upcc_content, count1 = upcc_top_pattern.subn(upcc_top_replacement, upcc_content, count=1)
print(f"UPCC top card replacements: {count1}")

# Pattern for individual offense card in UPCC/case_view.php
upcc_card_pattern = re.compile(
    r'(<div class="offense-body">\s*<\?php if \(!empty\(trim\(\(string\)\$offense\[\'description\'\]\)\)\): \?>\s*<div class="offense-row">\s*<div class="label">Description</div>\s*<div class="value"><\?= htmlspecialchars\(\(string\)\$offense\[\'description\'\]\) \?></div>\s*</div>\s*<\?php endif; \?>)',
    re.DOTALL
)

upcc_card_addition = r"""\1
                                             
                                             <?php 
                                               $offEv = $offense['evidence_file'] ?? ($offense['incident_photo'] ?? null);
                                               if (!empty($offEv)): 
                                                 $ext = strtolower(pathinfo($offEv, PATHINFO_EXTENSION));
                                                 $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
                                             ?>
                                             <div class="offense-row">
                                                 <div class="label">Photo Evidence</div>
                                                 <div class="value">
                                                     <?php if ($isImg): ?>
                                                         <a href="../<?= htmlspecialchars($offEv) ?>" target="_blank" title="Click to view full resolution evidence photo" style="display: inline-block; margin-top: 4px;">
                                                             <img src="../<?= htmlspecialchars($offEv) ?>" style="max-width: 200px; max-height: 130px; border-radius: 8px; border: 1.5px solid rgba(255,255,255,0.2); object-fit: cover; display: block; box-shadow: 0 2px 8px rgba(0,0,0,0.2);">
                                                         </a>
                                                     <?php else: ?>
                                                         <a href="../<?= htmlspecialchars($offEv) ?>" target="_blank" style="color: #60a5fa; font-weight: 700; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; text-decoration: underline;">
                                                             <span>📄 View Attached Evidence Document</span>
                                                         </a>
                                                     <?php endif; ?>
                                                 </div>
                                             </div>
                                             <?php endif; ?>"""

new_upcc_content, count2 = upcc_card_pattern.subn(upcc_card_addition, new_upcc_content, count=1)
print(f"UPCC card offense photo replacements: {count2}")

if count1 > 0 and count2 > 0:
    with open(upcc_path, 'w', encoding='utf-8') as f:
        f.write(new_upcc_content)
    print("UPCC/case_view.php updated successfully!")
else:
    print("Failed to match patterns in UPCC/case_view.php")


# 2. UPDATE admin/upcc_case_view.php
admin_path = r'c:\xampp\htdocs\identitrack\admin\upcc_case_view.php'
with open(admin_path, 'r', encoding='utf-8') as f:
    admin_content = f.read()

admin_top_pattern = re.compile(
    r'<!-- Incident Report & Photo Evidence Attachment Card -->\s*<\?php\s*\$caseEvidenceFile = \$case\[\'evidence_file\'\].*?<\?php endif; \?>',
    re.DOTALL
)

admin_top_replacement = """<!-- Incident Report & Photo Evidence Attachment Card -->
              <?php 
                $allEvidenceFiles = [];
                if (!empty($case['evidence_file'])) {
                    $allEvidenceFiles[] = [
                        'label' => 'Case Evidence File',
                        'file'  => $case['evidence_file']
                    ];
                }
                if (!empty($offenses)) {
                    foreach ($offenses as $off) {
                        $evPath = $off['evidence_file'] ?? ($off['incident_photo'] ?? null);
                        if (!empty($evPath)) {
                            $already = false;
                            foreach ($allEvidenceFiles as $item) {
                                if ($item['file'] === $evPath) {
                                    $already = true;
                                    break;
                                }
                            }
                            if (!$already) {
                                $allEvidenceFiles[] = [
                                    'label' => ($off['code'] ?? 'Offense') . ' Evidence',
                                    'file'  => $evPath
                                ];
                            }
                        }
                    }
                }
              ?>
              <?php if (!empty($allEvidenceFiles)): ?>
                <div style="margin-top: 12px; margin-bottom: 16px; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;">
                  <div style="background: #f8fafc; padding: 10px 16px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 11px; font-weight: 700; color: #2563eb; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 6px;">
                      <span>📷</span> Official Incident Report & Photo Evidence Attachment (<?= count($allEvidenceFiles) ?>)
                    </span>
                    <span style="font-size: 11px; color: #94a3b8;">Attached during case / offense registration</span>
                  </div>
                  <div style="padding: 16px; background: #fff; display: flex; gap: 16px; align-items: flex-start; flex-wrap: wrap;">
                  <?php foreach ($allEvidenceFiles as $evItem): 
                    $evFile = $evItem['file'];
                    $ext = strtolower(pathinfo($evFile, PATHINFO_EXTENSION));
                    $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
                  ?>
                    <?php if ($isImg): ?>
                      <div style="display: flex; flex-direction: column; gap: 6px; align-items: flex-start;">
                        <a href="../<?= htmlspecialchars($evFile) ?>" target="_blank" title="Click to view full resolution evidence photo (<?= htmlspecialchars($evItem['label']) ?>)" style="display: block; border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,0.06); transition: transform 0.2s;">
                          <img src="../<?= htmlspecialchars($evFile) ?>" style="max-width: 220px; max-height: 150px; width: auto; height: auto; display: block; object-fit: cover;">
                        </a>
                        <span style="font-size: 11px; font-weight: 700; color: #1e40af;"><?= htmlspecialchars($evItem['label']) ?></span>
                      </div>
                    <?php else: ?>
                      <div style="display: flex; flex-direction: column; gap: 6px;">
                        <a href="../<?= htmlspecialchars($evFile) ?>" target="_blank" style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 8px; text-decoration: none; color: #334155; font-size: 12px; font-weight: 700;">
                          <span>📄 View Document (<?= htmlspecialchars($evItem['label']) ?>)</span>
                        </a>
                      </div>
                    <?php endif; ?>
                  <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>"""

new_admin_content, acount1 = admin_top_pattern.subn(admin_top_replacement, admin_content, count=1)
print(f"Admin top card replacements: {acount1}")

admin_card_pattern = re.compile(
    r'(<\?php if \(!empty\(trim\(\(string\)\(\$off\[\'description\'\] \?\? \'\'\)\)\)\): \?>\s*<div>\s*<strong style="color:var\(--ink-700\); font-size:11px; text-transform:uppercase; letter-spacing:0\.4px;">Description:</strong>\s*<div style="color:var\(--ink-800\); margin-top:2px; font-style:italic;">"<\?= htmlspecialchars\(\(string\)\$off\[\'description\'\]\) \?>"</div>\s*</div>\s*<\?php endif; \?>)',
    re.DOTALL
)

admin_card_addition = r"""\1
                      
                      <?php 
                        $offEv = $off['evidence_file'] ?? ($off['incident_photo'] ?? null);
                        if (!empty($offEv)): 
                          $ext = strtolower(pathinfo($offEv, PATHINFO_EXTENSION));
                          $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
                      ?>
                        <div style="margin-top: 4px;">
                          <strong style="color:var(--ink-700); font-size:11px; text-transform:uppercase; letter-spacing:0.4px;">Photo Evidence:</strong>
                          <div style="margin-top: 4px;">
                            <?php if ($isImg): ?>
                              <a href="../<?= htmlspecialchars($offEv) ?>" target="_blank" title="Click to view full resolution evidence photo" style="display: inline-block;">
                                <img src="../<?= htmlspecialchars($offEv) ?>" style="max-width: 200px; max-height: 130px; border-radius: 8px; border: 1px solid #cbd5e1; object-fit: cover; display: block; box-shadow: 0 2px 6px rgba(0,0,0,0.08);">
                              </a>
                            <?php else: ?>
                              <a href="../<?= htmlspecialchars($offEv) ?>" target="_blank" style="color: #2563eb; font-weight: 700; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; text-decoration: underline;">
                                <span>📄 View Attached Evidence Document</span>
                              </a>
                            <?php endif; ?>
                          </div>
                        </div>
                      <?php endif; ?>"""

new_admin_content, acount2 = admin_card_pattern.subn(admin_card_addition, new_admin_content, count=1)
print(f"Admin card offense photo replacements: {acount2}")

if acount1 > 0 and acount2 > 0:
    with open(admin_path, 'w', encoding='utf-8') as f:
        f.write(new_admin_content)
    print("admin/upcc_case_view.php updated successfully!")
else:
    print("Failed to match patterns in admin/upcc_case_view.php")
