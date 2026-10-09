path = r'c:\xampp\htdocs\identitrack\admin\AJAX\export_monthly_report_xlsx.php'
with open(path, 'r', encoding='utf-8') as f:
    c = f.read()

# 1. Update offenseRows query to include registered_category
old_off_query = """      uc.case_id,
      uc.case_kind,
      COALESCE(NULLIF(uc.decided_category,0), 0) AS decided_category,"""

new_off_query = """      uc.case_id,
      uc.case_kind,
      COALESCE(NULLIF(ot.major_category, 0), 0) AS registered_category,
      COALESCE(NULLIF(uc.decided_category,0), 0) AS decided_category,"""

c = c.replace(old_off_query, new_off_query)

# 2. Update caseRowsQuery query to include registered_category
old_case_query = """        uc.case_id,
        uc.case_kind,
        COALESCE(NULLIF(uc.decided_category,0), 0) AS decided_category,"""

new_case_query = """        uc.case_id,
        uc.case_kind,
        COALESCE((SELECT COALESCE(NULLIF(ot2.major_category, 0), 0) FROM upcc_case_offense uco2 JOIN offense o2 ON o2.offense_id = uco2.offense_id JOIN offense_type ot2 ON ot2.offense_type_id = o2.offense_type_id WHERE uco2.case_id = uc.case_id ORDER BY ot2.major_category DESC LIMIT 1), 0) AS registered_category,
        COALESCE(NULLIF(uc.decided_category,0), 0) AS decided_category,"""

c = c.replace(old_case_query, new_case_query)

# 3. Update populate_sheet_data_rows to use registered_category in resolvedCol and remove N/A from pendingCol and resolvedCol
old_data_loop = """              $decidedCat = (int)($r['decided_category'] ?? 0);
              $offenseNameUpper = strtoupper((string)($r['offense_name'] ?? ''));

              $isApprovedAppeal = ($appealStatus === 'APPROVED' || $caseStatus === 'CANCELLED' || ($offenseStatus === 'VOID' && $appealStatus === 'APPROVED'));
              $isDismissed = ($caseStatus === 'DISMISSED' || $offenseStatus === 'DISMISSED');

              if ($isApprovedAppeal) {
                  $isResolved = true;
                  $isPending  = false;
              } elseif ($isCaseRow || $offenseLevel === 'MAJOR') {
                  $isResolved = !$isDismissed && ($caseStatus === 'CLOSED' || $caseStatus === 'RESOLVED' || $offenseStatus === 'CLOSED' || $offenseStatus === 'RESOLVED');
                  $isPending  = !$isDismissed && !$isResolved;
              } else {
                  $isResolved = !$isDismissed && ($offenseStatus === 'RESOLVED' || $offenseStatus === 'COMPLETED' || $offenseStatus === 'SERVED' || $caseStatus === 'CLOSED' || $caseStatus === 'RESOLVED');
                  $isPending  = !$isDismissed && !$isResolved;
              }

              if ($isCaseRow) {
                  $isSec4Case = (strpos($offNameUpper, 'SECTION 4') !== false || strpos($offNameUpper, 'SECTION4') !== false || strpos(strtoupper((string)($r['case_kind'] ?? '')), 'SECTION4') !== false);
                  if ($isDismissed) {
                      $rowCategory = 'DISMISSED CASE';
                      $pendingCol = 'N/A (Dismissed)';
                      $resolvedCol = 'DISMISSED CASE';
                      $displayLevel = 'DISMISSED CASE';
                  } elseif ($isSec4Case) {
                      $caseIdx++;
                      $cOrd = ($caseIdx === 1) ? '1ST' : (($caseIdx === 2) ? '2ND' : (($caseIdx === 3) ? '3RD' : "{$caseIdx}TH"));
                      $rowCategory = "{$cOrd} CYCLE SECTION 4 ESCALATION";
                      $pendingCol = $isPending ? 'PENDING UPCC HEARING' : 'N/A (Resolved / Closed)';
                      $resolvedCol = $isResolved ? (($decidedCat > 0) ? "SECTION 4 MAJOR (CATEGORY {$decidedCat})" : "SECTION 4 MAJOR (RESOLVED)") : 'N/A (Pending Case)';
                      $displayLevel = $isApprovedAppeal ? (($decidedCat > 0) ? "APPROVED APPEAL (CATEGORY {$decidedCat})" : "APPROVED APPEAL (SANCTION VOIDED)") : (($isResolved && $decidedCat > 0) ? "{$cOrd} CYCLE SECTION 4 MAJOR (CATEGORY {$decidedCat})" : ($isPending ? "{$cOrd} CYCLE SECTION 4 MAJOR (CATEGORY 1 TO 5 PENDING UPCC)" : "{$cOrd} CYCLE SECTION 4 MAJOR (RESOLVED)"));
                  } else {
                      $rowCategory = 'AUTOMATIC MAJOR';
                      $pendingCol = $isPending ? 'PENDING UPCC HEARING' : 'N/A (Resolved / Closed)';
                      $resolvedCol = $isResolved ? (($decidedCat > 0) ? "AUTOMATIC MAJOR (CATEGORY {$decidedCat})" : "AUTOMATIC MAJOR (RESOLVED)") : 'N/A (Pending Case)';
                      $displayLevel = $isApprovedAppeal ? (($decidedCat > 0) ? "APPROVED APPEAL (CATEGORY {$decidedCat})" : "APPROVED APPEAL (SANCTION VOIDED)") : (($isResolved && $decidedCat > 0) ? "AUTOMATIC MAJOR (CATEGORY {$decidedCat})" : ($isPending ? "AUTOMATIC MAJOR (CATEGORY 1 TO 5 PENDING UPCC)" : "AUTOMATIC MAJOR (RESOLVED)"));
                  }
              } else {
                  if ($isDismissed) {
                      $rowCategory = 'DISMISSED OFFENSE';
                      $pendingCol = 'N/A (Dismissed)';
                      $resolvedCol = 'DISMISSED OFFENSE';
                      $displayLevel = 'DISMISSED OFFENSE';
                  } elseif ($offenseLevel === 'MINOR') {
                      $minorIdx++;
                      $cycleNum = (int)floor(($minorIdx - 1) / 3) + 1;
                      $cOrd = ($cycleNum === 1) ? '1ST' : (($cycleNum === 2) ? '2ND' : (($cycleNum === 3) ? '3RD' : "{$cycleNum}TH"));
                      $ordinal = ($minorIdx === 1) ? '1ST' : (($minorIdx === 2) ? '2ND' : (($minorIdx === 3) ? '3RD' : (($minorIdx === 4) ? '4TH' : (($minorIdx === 5) ? '5TH' : "{$minorIdx}TH"))));

                      $rowCategory = 'MINOR OFFENSE';
                      if ($hasSection4) {
                          $pendingCol = $isPending ? "ACTIVE MINOR (CYCLE {$cycleNum})" : 'N/A (Resolved)';
                          $resolvedCol = $isResolved ? "RESOLVED MINOR (CYCLE {$cycleNum})" : 'N/A (Pending)';
                      } else {
                          $pendingCol = $isPending ? 'ACTIVE MINOR OFFENSE' : 'N/A (Resolved)';
                          $resolvedCol = $isResolved ? 'RESOLVED MINOR OFFENSE' : 'N/A (Pending)';
                      }
                      $displayLevel = ($minorIdx % 3 === 0) ? "{$ordinal} MINOR WARNING (CYCLE {$cycleNum} - SECTION 4 TRIGGERED)" : "{$ordinal} MINOR WARNING (CYCLE {$cycleNum})";
                  } elseif ($offenseLevel === 'MAJOR') {
                      $rowCategory = 'AUTOMATIC MAJOR';
                      $pendingCol = $isPending ? 'PENDING UPCC HEARING' : 'N/A (Resolved / Closed)';
                      $resolvedCol = $isResolved ? (($decidedCat > 0) ? "AUTOMATIC MAJOR (CATEGORY {$decidedCat})" : "AUTOMATIC MAJOR (RESOLVED)") : 'N/A (Pending Case)';
                      $displayLevel = $isApprovedAppeal ? (($decidedCat > 0) ? "APPROVED APPEAL (CATEGORY {$decidedCat})" : "APPROVED APPEAL (SANCTION VOIDED)") : (($isResolved && $decidedCat > 0) ? "AUTOMATIC MAJOR (CATEGORY {$decidedCat})" : ($isPending ? "AUTOMATIC MAJOR (CATEGORY 1 TO 5 PENDING UPCC)" : "AUTOMATIC MAJOR (RESOLVED)"));
                  } else {
                      $rowCategory = 'OTHER';
                      $pendingCol = 'N/A';
                      $resolvedCol = 'N/A';
                      $displayLevel = $offenseLevel;
                  }
              }"""

new_data_loop = """              $decidedCat = (int)($r['decided_category'] ?? 0);
              $regCat = (int)($r['registered_category'] ?? 0);
              $displayRegCat = ($regCat > 0) ? $regCat : (($decidedCat > 0) ? $decidedCat : 0);
              $offenseNameUpper = strtoupper((string)($r['offense_name'] ?? ''));

              $isApprovedAppeal = ($appealStatus === 'APPROVED' || $caseStatus === 'CANCELLED' || ($offenseStatus === 'VOID' && $appealStatus === 'APPROVED'));
              $isDismissed = ($caseStatus === 'DISMISSED' || $offenseStatus === 'DISMISSED');

              if ($isApprovedAppeal) {
                  $isResolved = true;
                  $isPending  = false;
              } elseif ($isCaseRow || $offenseLevel === 'MAJOR') {
                  $isResolved = !$isDismissed && ($caseStatus === 'CLOSED' || $caseStatus === 'RESOLVED' || $offenseStatus === 'CLOSED' || $offenseStatus === 'RESOLVED');
                  $isPending  = !$isDismissed && !$isResolved;
              } else {
                  $isResolved = !$isDismissed && ($offenseStatus === 'RESOLVED' || $offenseStatus === 'COMPLETED' || $offenseStatus === 'SERVED' || $caseStatus === 'CLOSED' || $caseStatus === 'RESOLVED');
                  $isPending  = !$isDismissed && !$isResolved;
              }

              if ($isCaseRow) {
                  $isSec4Case = (strpos($offNameUpper, 'SECTION 4') !== false || strpos($offNameUpper, 'SECTION4') !== false || strpos(strtoupper((string)($r['case_kind'] ?? '')), 'SECTION4') !== false);
                  if ($isDismissed) {
                      $rowCategory = 'DISMISSED CASE';
                      $pendingCol = '';
                      $resolvedCol = 'DISMISSED CASE';
                      $displayLevel = 'DISMISSED CASE';
                  } elseif ($isSec4Case) {
                      $caseIdx++;
                      $cOrd = ($caseIdx === 1) ? '1ST' : (($caseIdx === 2) ? '2ND' : (($caseIdx === 3) ? '3RD' : "{$caseIdx}TH"));
                      $rowCategory = "{$cOrd} CYCLE SECTION 4 ESCALATION";
                      $pendingCol = $isPending ? 'PENDING UPCC HEARING' : '';
                      $resolvedCol = $isResolved ? (($decidedCat > 0) ? "SECTION 4 MAJOR (CATEGORY {$decidedCat})" : "SECTION 4 MAJOR (RESOLVED)") : '';
                      $displayLevel = $isApprovedAppeal ? (($decidedCat > 0) ? "APPROVED APPEAL (CATEGORY {$decidedCat})" : "APPROVED APPEAL (SANCTION VOIDED)") : (($isResolved && $decidedCat > 0) ? "{$cOrd} CYCLE SECTION 4 MAJOR (CATEGORY {$decidedCat})" : ($isPending ? "{$cOrd} CYCLE SECTION 4 MAJOR (CATEGORY 1 TO 5 PENDING UPCC)" : "{$cOrd} CYCLE SECTION 4 MAJOR (RESOLVED)"));
                  } else {
                      $rowCategory = 'AUTOMATIC MAJOR';
                      $pendingCol = $isPending ? 'PENDING UPCC HEARING' : '';
                      $resolvedCol = $isResolved ? (($displayRegCat > 0) ? "AUTOMATIC MAJOR (CATEGORY {$displayRegCat})" : "AUTOMATIC MAJOR (RESOLVED)") : '';
                      $displayLevel = $isApprovedAppeal ? (($decidedCat > 0) ? "APPROVED APPEAL (CATEGORY {$decidedCat})" : "APPROVED APPEAL (SANCTION VOIDED)") : (($isResolved && $decidedCat > 0) ? "AUTOMATIC MAJOR (CATEGORY {$decidedCat})" : ($isPending ? "AUTOMATIC MAJOR (CATEGORY 1 TO 5 PENDING UPCC)" : "AUTOMATIC MAJOR (RESOLVED)"));
                  }
              } else {
                  if ($isDismissed) {
                      $rowCategory = 'DISMISSED OFFENSE';
                      $pendingCol = '';
                      $resolvedCol = 'DISMISSED OFFENSE';
                      $displayLevel = 'DISMISSED OFFENSE';
                  } elseif ($offenseLevel === 'MINOR') {
                      $minorIdx++;
                      $cycleNum = (int)floor(($minorIdx - 1) / 3) + 1;
                      $cOrd = ($cycleNum === 1) ? '1ST' : (($cycleNum === 2) ? '2ND' : (($cycleNum === 3) ? '3RD' : "{$cycleNum}TH"));
                      $ordinal = ($minorIdx === 1) ? '1ST' : (($minorIdx === 2) ? '2ND' : (($minorIdx === 3) ? '3RD' : (($minorIdx === 4) ? '4TH' : (($minorIdx === 5) ? '5TH' : "{$minorIdx}TH"))));

                      $rowCategory = 'MINOR OFFENSE';
                      if ($hasSection4) {
                          $pendingCol = $isPending ? "ACTIVE MINOR (CYCLE {$cycleNum})" : '';
                          $resolvedCol = $isResolved ? "RESOLVED MINOR (CYCLE {$cycleNum})" : '';
                      } else {
                          $pendingCol = $isPending ? 'ACTIVE MINOR OFFENSE' : '';
                          $resolvedCol = $isResolved ? 'RESOLVED MINOR OFFENSE' : '';
                      }
                      $displayLevel = ($minorIdx % 3 === 0) ? "{$ordinal} MINOR WARNING (CYCLE {$cycleNum} - SECTION 4 TRIGGERED)" : "{$ordinal} MINOR WARNING (CYCLE {$cycleNum})";
                  } elseif ($offenseLevel === 'MAJOR') {
                      $rowCategory = 'AUTOMATIC MAJOR';
                      $pendingCol = $isPending ? 'PENDING UPCC HEARING' : '';
                      $resolvedCol = $isResolved ? (($displayRegCat > 0) ? "AUTOMATIC MAJOR (CATEGORY {$displayRegCat})" : "AUTOMATIC MAJOR (RESOLVED)") : '';
                      $displayLevel = $isApprovedAppeal ? (($decidedCat > 0) ? "APPROVED APPEAL (CATEGORY {$decidedCat})" : "APPROVED APPEAL (SANCTION VOIDED)") : (($isResolved && $decidedCat > 0) ? "AUTOMATIC MAJOR (CATEGORY {$decidedCat})" : ($isPending ? "AUTOMATIC MAJOR (CATEGORY 1 TO 5 PENDING UPCC)" : "AUTOMATIC MAJOR (RESOLVED)"));
                  } else {
                      $rowCategory = 'OTHER';
                      $pendingCol = '';
                      $resolvedCol = '';
                      $displayLevel = $offenseLevel;
                  }
              }"""

if old_data_loop in c:
    c = c.replace(old_data_loop, new_data_loop)
    with open(path, 'w', encoding='utf-8', newline='') as f:
        f.write(c)
    print("SUCCESS")
else:
    print("MATCH FAILED FOR DATA LOOP")
