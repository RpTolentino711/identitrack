import docx

doc_path = r'c:\xampp\htdocs\identitrack\DOCKS\IdentiTrack_Master_Testing_Document_LUMI_Format.docx'
doc = docx.Document(doc_path)

# Dictionary of test cases indexed by Table Index (0 to 21, skipping 8 and 19 which are dividers)
test_cases = {
    0: {
        "module": "Campus Guard Incident & Violation Logging Module",
        "function": "Campus Guard Incident Reporting (FR-01)",
        "user_access": "Campus Security Guard",
        "system": "Mobile Web Application / Guard Portal",
        "preconditions": "Security guard is authenticated on Guard Portal; student ID or QR code is available.",
        "action": "Record student violation report, select offense category (Minor/Major), attach violation details, and submit.",
        "expected": [
            "Guard can input student ID/number or scan code to retrieve student profile.",
            "Violation entry form accepts offense category, date, location, and detailed description.",
            "System validates required fields and submits the violation incident log.",
            "Recorded incident is logged with status 'Pending SDO Review' in database.",
            "Success confirmation modal is displayed to the guard upon successful submission."
        ]
    },
    1: {
        "module": "Incident Evidence & Photo Attachment Module",
        "function": "Incident Evidence & Photo Attachment (FR-02)",
        "user_access": "Campus Security Guard",
        "system": "Mobile Web Application / Guard Portal",
        "preconditions": "Guard is filling out a violation report on the Guard Portal; camera or file storage access is granted.",
        "action": "Capture or upload photo evidence of the student infraction and attach it to the report.",
        "expected": [
            "Guard can capture photo using device camera or pick file from image library.",
            "Uploaded image is previewed before submitting the incident report.",
            "Evidence image is stored in uploads/evidence/ directory with secure filename.",
            "Image metadata (timestamp, file path) is linked to the student_violation record.",
            "SDO Admin can view the attached photo evidence when reviewing the pending incident."
        ]
    },
    2: {
        "module": "SDO Admin Review Queue Module",
        "function": "SDO Admin Guard Report Review (FR-03)",
        "user_access": "Student Discipline Office (SDO) Admin",
        "system": "Web Application (Admin Portal)",
        "preconditions": "SDO Admin is authenticated; pending guard incident logs exist in the review queue.",
        "action": "Review submitted guard incident reports, inspect evidence photos, and approve or dismiss the report.",
        "expected": [
            "SDO Admin dashboard displays pending guard-submitted violation logs in the queue.",
            "Admin can click a report to view complete details, student history, and attached photo evidence.",
            "Admin can approve the report to formally record the student infraction.",
            "Admin can dismiss/reject invalid reports with required administrative reason.",
            "Approved violations automatically update student offense counts and trigger appropriate warnings."
        ]
    },
    3: {
        "module": "Progressive Minor Offense Warning Module",
        "function": "Minor Offense Registration & Guardian Alert (FR-04)",
        "user_access": "SDO Admin / Automated System",
        "system": "Web Application & Notification Engine",
        "preconditions": "Approved minor offense is logged for a student; parent/guardian contact details exist in student profile.",
        "action": "Register minor offense count and dispatch instant SMS/Email warning alert to parent/guardian.",
        "expected": [
            "System increments student's active minor offense counter (1st minor offense).",
            "System formats official parent warning message with offense details and date.",
            "Multi-channel notification engine dispatches Email/SMS alert to registered guardian contact.",
            "Notification dispatch status and timestamp are logged in parent_notifications table.",
            "Student receiving 1st minor offense receives formal warning notification on student mobile app."
        ]
    },
    4: {
        "module": "Progressive Minor Offense Warning Module",
        "function": "2nd Minor Offense Guardian Warning Alert (FR-05)",
        "user_access": "SDO Admin / Automated System",
        "system": "Web Application & Notification Engine",
        "preconditions": "Student already has 1 active minor offense; new minor offense report is approved by SDO Admin.",
        "action": "Record 2nd minor offense count and send mandatory parent warning alert emphasizing escalation risk.",
        "expected": [
            "System updates student's active minor offense count to 2.",
            "System triggers 2nd minor offense warning template informing guardian of critical escalation.",
            "Parent/guardian receives automated Email/SMS warning detailing 2nd minor infraction.",
            "Student app displays 2nd Minor Offense Warning banner on student dashboard.",
            "System alerts student that a 3rd minor offense will trigger mandatory guidance counseling or UPCC panel referral."
        ]
    },
    5: {
        "module": "Student App Alert Notification Engine",
        "function": "3rd Minor Mixed Offense Student Warning Modal (FR-06)",
        "user_access": "Student / System",
        "system": "Mobile Application (Flutter PWA/Android)",
        "preconditions": "Student incurs 3rd minor offense (mixed offense categories); student opens mobile app.",
        "action": "Display mandatory un-dismissable warning modal in student app detailing offense accumulation.",
        "expected": [
            "Student mobile app detects 3rd minor offense status on login/dashboard load.",
            "Un-dismissable modal pops up showing complete record of 3 minor offenses.",
            "Modal displays required action steps (reporting to Guidance / SDO office).",
            "Modal details potential sanction escalation if minor offenses continue to accumulate.",
            "Acknowledging the modal logs student read-receipt timestamp in student_notifications table."
        ]
    },
    6: {
        "module": "Section 4 Minor Escalation Module",
        "function": "Section 4 Minor Escalation Trigger (FR-07)",
        "user_access": "System / SDO Admin",
        "system": "Web Application & Database Logic",
        "preconditions": "Student accumulates 3 minor offenses under Section 4 of the Student Code of Conduct.",
        "action": "Evaluate Section 4 escalation rule and convert accumulated minor offenses to equivalent Major Case status.",
        "expected": [
            "System detects 3rd minor offense under Section 4 code of conduct rules.",
            "Escalation engine automatically flags case as Escalated Major Offense under Section 4.",
            "Case status transitions from minor offense tracking to formal UPCC Major Case queue.",
            "Notification sent to Guidance Office and UPCC Panel regarding Section 4 escalation.",
            "Student app updates case status to reflect formal Major Case referral."
        ]
    },
    7: {
        "module": "Major Case Referral Module",
        "function": "Automatic Major Case Referral to UPCC (FR-08)",
        "user_access": "Automated System / SDO Admin",
        "system": "Web Application Backend",
        "preconditions": "Major offense is logged by Guard/SDO Admin OR minor offenses escalate via Section 4 rule.",
        "action": "Initiate automatic referral of major offense case to University Discipline & Misconduct Committee (UPCC).",
        "expected": [
            "System classifies infraction severity as Major Offense.",
            "Case entry created in upcc_cases table with status Referred to UPCC.",
            "UPCC Committee Members and Guidance Counselor receive new case alert notification.",
            "Automated generation of Form F-005 Notice to Explain (NTE) drafted for student issuance.",
            "Student dashboard displays active Major Case status and pending NTE requirement."
        ]
    },
    9: {
        "module": "Notice to Explain (NTE) Workflow Module",
        "function": "Form F-005 Notice to Explain Delivery (FR-09)",
        "user_access": "SDO Admin / Student",
        "system": "Mobile App & Web Portal",
        "preconditions": "Major case referred to UPCC; Form F-005 NTE issued by SDO Admin.",
        "action": "Deliver digital Form F-005 Notice to Explain to student app with 5-day deadline timer.",
        "expected": [
            "SDO Admin publishes Form F-005 NTE specifying violation charges and response deadline.",
            "Student app receives instant push/in-app notification of official NTE issuance.",
            "Student can view PDF format Form F-005 detailing incident facts and panel instructions.",
            "System initializes 5-calendar-day countdown timer for written explanation submission.",
            "Parent/guardian receives copy of Form F-005 NTE via registered email."
        ]
    },
    10: {
        "module": "Student Explanation Submission Module",
        "function": "Student Written Explanation Submission (FR-10)",
        "user_access": "Student",
        "system": "Mobile Application (Flutter PWA/Android)",
        "preconditions": "Active Form F-005 NTE issued to student; submission deadline timer active.",
        "action": "Student writes and submits official written explanation statement and optional supporting documents.",
        "expected": [
            "Student app provides text input area and file attachment support for written explanation.",
            "Student inputs statement accounting for reported incident details.",
            "System validates non-empty submission and uploads attached supporting evidence files.",
            "Case status updates to Written Explanation Submitted - Awaiting UPCC Review.",
            "Submissions timestamped and sealed for UPCC Committee review session access."
        ]
    },
    11: {
        "module": "UPCC Panel Hearing & Attendance Module",
        "function": "UPCC Panel Hearing Scheduler (FR-11)",
        "user_access": "UPCC Admin / SDO Admin",
        "system": "Web Application (Admin Portal)",
        "preconditions": "Student written explanation submitted or 5-day NTE window elapsed.",
        "action": "Schedule UPCC committee hearing date, time, venue, and panel composition.",
        "expected": [
            "UPCC Admin sets hearing schedule (Date, Time, Location) and assigns panel members.",
            "Official Hearing Notice generated and dispatched to student app and guardian email.",
            "Hearing schedule populated on UPCC Committee member dashboard calendars.",
            "Student app displays Hearing Schedule details with countdown timer to hearing date.",
            "System sends automated reminder notification 24 hours prior to scheduled hearing."
        ]
    },
    12: {
        "module": "Local Machine Learning Decision Engine",
        "function": "Local XGBoost AI Sanction Engine (FR-12)",
        "user_access": "UPCC Admin / System",
        "system": "Web Application Python AI Service (softeng_2-master)",
        "preconditions": "Student offense history, academic year, offense category, and prior record loaded into case record.",
        "action": "Execute local XGBoost model (sanction_xgb_model.json) to compute recommended sanction hours.",
        "expected": [
            "System feeds structured case feature vector (offense type, severity, repeat count) to local XGBoost model.",
            "XGBoost engine executes prediction locally without external API dependencies.",
            "Engine returns recommended sanction type (Community Service) and exact recommended service hours.",
            "Model confidence score and feature importance weights displayed on UPCC Panel review screen.",
            "Panel members can view AI-recommended sanction benchmark alongside historical precedent cases."
        ]
    },
    13: {
        "module": "UPCC Category Consensus Module",
        "function": "UPCC Panel Consensus & Category Voting (FR-13)",
        "user_access": "UPCC Committee Members / Panel Chair",
        "system": "Web Application (UPCC Portal)",
        "preconditions": "UPCC hearing in progress or concluded; panel members logged into consensus portal.",
        "action": "Panel members cast individual category votes and community service hour recommendations.",
        "expected": [
            "Each UPCC panelist inputs individual vote for offense category classification and sanction duration.",
            "System calculates live panel consensus ratio and highlights majority/unanimous vote.",
            "System enforces minimum panel quorum required for decision finalization.",
            "Panel Chair reviews aggregated votes and locks final committee recommendation.",
            "Final committee decision transcript stored securely in upcc_hearing_minutes table."
        ]
    },
    14: {
        "module": "Admin Decision Finalization Module",
        "function": "Admin Sanction Finalization & Issuance (FR-14)",
        "user_access": "SDO Admin / UPCC Chair",
        "system": "Web Application (Admin Portal)",
        "preconditions": "UPCC panel consensus reached; final sanction parameters approved.",
        "action": "Finalize official disciplinary resolution, assign community service hours, and issue formal decision order.",
        "expected": [
            "SDO Admin approves panel recommendation and enters final assigned community service hours.",
            "System updates case status to Sanction Finalized - Active Community Service.",
            "Official Disciplinary Decision Resolution PDF generated and issued to student and guardian.",
            "Active community service requirement created with exact assigned target hours (e.g., 20.0 hours).",
            "Student mobile app dashboard updates immediately displaying assigned service requirement and progress tracker."
        ]
    },
    15: {
        "module": "Account Access & Security Module",
        "function": "Student App OTP Login Authentication (FR-15)",
        "user_access": "Student",
        "system": "Mobile Application (Flutter PWA/Android) & API",
        "preconditions": "Student account exists; registered email or mobile number on file.",
        "action": "Request email/SMS One-Time Password (OTP), receive 6-digit code, and authenticate session.",
        "expected": [
            "Student enters registered Institutional Student ID / Email on mobile login screen.",
            "System generates cryptographically secure 6-digit OTP with 5-minute expiration timer.",
            "Multi-channel mailer sends OTP email to student's institutional address.",
            "Entering valid 6-digit OTP authenticates student and issues JWT session token.",
            "Invalid or expired OTP is rejected with clear error message and attempt rate limiting."
        ]
    },
    16: {
        "module": "Account Access & Security Module",
        "function": "Student Account Access Mode Enforcement (FR-16)",
        "user_access": "Student / System",
        "system": "Mobile Application & Web API",
        "preconditions": "Student user attempts login; account status may be Active, Suspended, or Archived.",
        "action": "Enforce role-based access control, session token validation, and account status restrictions.",
        "expected": [
            "System verifies active JWT session token on every API request.",
            "Active student accounts gain full access to student dashboard and service tracking.",
            "Suspended student accounts are restricted to view-only disciplinary notice screens.",
            "Invalid/revoked tokens trigger immediate redirection to login screen.",
            "Concurrent session handling invalidates stale session tokens securely."
        ]
    },
    17: {
        "module": "NFC & Barcode Kiosk Clock-In Module",
        "function": "Community Service Kiosk Clock-In/Clock-Out (FR-17)",
        "user_access": "Student / Service Supervisor / Kiosk System",
        "system": "Web/Kiosk Terminal & Barcode Scanner",
        "preconditions": "Student has active community service requirement; Kiosk terminal active at supervisor station.",
        "action": "Scan student ID barcode/NFC at kiosk station to start or end a community service session.",
        "expected": [
            "Kiosk scanner reads student ID barcode or NFC tag successfully.",
            "Clock-In records start timestamp, supervisor ID, location, and initializes active session.",
            "Student app dashboard reflects live Session In Progress status.",
            "Clock-Out records end timestamp, calculates elapsed session duration, and awaits supervisor verification.",
            "System prevents duplicate active session creation if a session is already running."
        ]
    },
    18: {
        "module": "Real-time Service Countdown Module",
        "function": "Live 1-Second Countdown Ticker Sync (FR-18)",
        "user_access": "Student / Mobile App",
        "system": "Mobile Application (Flutter PWA/Android)",
        "preconditions": "Active community service session running; student opens mobile dashboard.",
        "action": "Execute 1-second periodic timer syncing live remaining service seconds on dashboard.",
        "expected": [
            "Dashboard fetches community_service_remaining_sec from API on session start.",
            "Flutter 1-second periodic ticker decrements remaining time smoothly in real-time.",
            "Live timer displays remaining time formatted as HH:MM:SS (e.g., 04h 12m 30s).",
            "Ticker automatically stops when session is paused or completed.",
            "Dashboard updates dynamically without requiring manual page refresh."
        ]
    },
    20: {
        "module": "Isolated Service Hour Calculation Module",
        "function": "Strict Isolated Service Hour Calculation (FR-19)",
        "user_access": "System Administrator / SDO Admin",
        "system": "Web & Mobile Backend Logic",
        "preconditions": "Student has completed prior community service requirements and receives a new active requirement.",
        "action": "Compute completed service hours strictly filtered by active requirement_id.",
        "expected": [
            "System queries service sessions filtered exclusively by active requirement_id.",
            "Hours completed from previous closed/resolved requirements are strictly isolated (return 0.0h for new case).",
            "Student dashboard displays exactly 0.0 / Assigned Hours at start of new requirement.",
            "Approved service session hours accumulate exclusively toward the current active case.",
            "Admin portal community service view displays isolated hours for the selected case without cross-case residue."
        ]
    },
    21: {
        "module": "Student Appeal Workflow Module",
        "function": "Student Decision Appeal Request Submission (FR-20)",
        "user_access": "Student",
        "system": "Mobile Application (Flutter PWA/Android)",
        "preconditions": "Final disciplinary sanction issued; student within official 7-day appeal eligibility window.",
        "action": "Submit formal appeal request with grounds for appeal and attached supporting evidence.",
        "expected": [
            "Student app displays Submit Appeal button during active 7-day post-sanction window.",
            "Appeal submission form captures grounds for appeal (New Evidence, Procedural Error).",
            "File attachment tool allows uploading PDF/image evidence documents.",
            "System logs appeal in upcc_appeals table and updates case status to Appeal Pending SDO Review.",
            "Submitting appeal pauses community service countdown until appeal decision is rendered."
        ]
    }
}

def set_cell_text(cell, text):
    cell.text = text

for t_idx, tc_data in test_cases.items():
    if t_idx >= len(doc.tables):
        continue
    table = doc.tables[t_idx]
    
    # Row 0: Header | Module Name | <Value>
    # We want to set cell containing module name
    for row in table.rows:
        row_cells = row.cells
        r_text = " ".join([c.text for c in row_cells])
        
        if "Module Name" in r_text:
            # Usually cell 2 or cell 1
            for i, c in enumerate(row_cells):
                if c.text.strip() == "Module Name" and i + 1 < len(row_cells):
                    set_cell_text(row_cells[i+1], tc_data["module"])
                    break
        elif "Function Name" in r_text:
            for i, c in enumerate(row_cells):
                if c.text.strip() == "Function Name" and i + 1 < len(row_cells):
                    set_cell_text(row_cells[i+1], tc_data["function"])
                    break
        elif "User/Access Type" in r_text:
            for i, c in enumerate(row_cells):
                if c.text.strip() == "User/Access Type" and i + 1 < len(row_cells):
                    set_cell_text(row_cells[i+1], tc_data["user_access"])
                    break
        elif "Type of System" in r_text:
            for i, c in enumerate(row_cells):
                if c.text.strip() == "Type of System" and i + 1 < len(row_cells):
                    set_cell_text(row_cells[i+1], tc_data["system"])
                    break
        elif "Pre-conditions" in r_text:
            for i, c in enumerate(row_cells):
                if "Pre-conditions" in c.text and i + 1 < len(row_cells):
                    set_cell_text(row_cells[i+1], tc_data["preconditions"])
                    break
        elif "Action Description" in r_text:
            for i, c in enumerate(row_cells):
                if "Action Description" in c.text and i + 1 < len(row_cells):
                    set_cell_text(row_cells[i+1], tc_data["action"])
                    break

    # Now handle Expected Results in rows 8..12 (Runs 1..5)
    # Find row with header "Runs | Expected Results"
    in_runs = False
    run_counter = 0
    for r_idx, row in enumerate(table.rows):
        cells = row.cells
        txts = [c.text.strip() for c in cells]
        if "Runs" in txts and "Expected Results" in txts:
            in_runs = True
            run_counter = 0
            continue
        if in_runs:
            if run_counter < 5:
                # Find cell for Expected Results (usually index 1)
                exp_cell_idx = None
                for c_i, c in enumerate(cells):
                    if c.text.strip() == str(run_counter + 1):
                        exp_cell_idx = c_i + 1
                        break
                if exp_cell_idx is not None and exp_cell_idx < len(cells):
                    set_cell_text(cells[exp_cell_idx], tc_data["expected"][run_counter])
                run_counter += 1

doc.save(doc_path)
print("Saved updated docx successfully!")
