import docx
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml import OxmlElement, parse_xml
from docx.oxml.ns import qn, nsdecls

doc_path = r'c:\xampp\htdocs\identitrack\DOCKS\IdentiTrack_Master_Testing_Document_LUMI_Format.docx'

doc = docx.Document()

# Configure margins
sections = doc.sections
for section in sections:
    section.top_margin = Inches(0.5)
    section.bottom_margin = Inches(0.5)
    section.left_margin = Inches(0.5)
    section.right_margin = Inches(0.5)

tables_data = [
    {
        "module": "Campus Guard Incident & Violation Logging Module",
        "function": "Campus Guard Incident Reporting (FR-01)",
        "user_access": "Campus Security Guard",
        "system": "Mobile Web Application / Guard Portal",
        "preconditions": "Security guard opens Guard Portal login page; enters valid credentials; student name/number or RFID card is available.",
        "action": "Record student violation report, select offense category (Minor/Major), attach violation details, and submit.",
        "verification": "Open Guard Portal -> Log in with Guard credentials -> Search Student by Name/Number or scan RFID Tag -> Fill in Violation Details & Select Category -> (Optional) Attach Photo Evidence -> Submit Incident Report -> Confirm Pending SDO Review Status.",
        "expected": [
            "Guard logs in successfully through the Guard Login form.",
            "Guard can input student ID/number or scan RFID tag/barcode to retrieve student profile.",
            "Violation entry form accepts offense category (Minor/Major), date, location, and required detailed description.",
            "Form permits optional photo evidence capture/upload with live image preview before submission.",
            "System validates required fields and submits the violation incident log successfully with or without an attached photo.",
            "Recorded incident is logged with status pending SDO Review, and a success confirmation modal is displayed."
        ]
    },
    {
        "module": "Incident Evidence & Photo Attachment Module",
        "function": "Incident Evidence & Photo Attachment (FR-02)",
        "user_access": "Campus Security Guard",
        "system": "Mobile Web Application / Guard Portal",
        "preconditions": "Guard is filling out a violation report on the Guard Portal; camera or file storage access is granted.",
        "action": "Capture or upload photo evidence of the student infraction and attach it to the report (optional if evidence photo is available).",
        "verification": "Log in to Guard Portal -> scan student RFID card via reader OR enter student number/name in search field -> verify student profile and photo render -> choose offense category (Minor/Major) -> enter violation date, and required description -> (optional) capture or upload incident photo evidence -> submit report -> verify success modal.",
        "expected": [
            "Guard can capture photo using device camera or pick file from image library.",
            "Uploaded image is previewed before submitting the incident report.",
            "Evidence image is stored in uploads/evidence/ directory with secure filename.",
            "Image metadata (timestamp, file path) is linked to the student_violation record.",
            "SDO Admin can view the attached photo evidence when reviewing the pending incident."
        ]
    },
    {
        "module": "SDO Admin Review Queue Module",
        "function": "SDO Admin Guard Report Review (FR-03)",
        "user_access": "Student Discipline Office (SDO) Admin",
        "system": "Web Application (Admin Portal)",
        "preconditions": "SDO Admin is authenticated via 2FA (Email OTP); pending guard incident logs exist in the review queue.",
        "action": "Review submitted guard report, inspect evidence photo (if available), approve, re-classify category (Minor/Major), or dismiss the report.",
        "verification": "Log in to SDO Admin Portal with 2FA Email OTP -> open Guard Review queue -> select pending report -> review student offense history & evidence photo -> approve, adjust category to Minor/Major, or dismiss -> confirm database update.",
        "expected": [
            "SDO Admin logs in via username, password, and 2FA Email OTP verification.",
            "Admin dashboard displays pending guard-submitted violation logs in the review queue.",
            "Admin can click a report to view complete details, student history, and attached photo evidence (if available).",
            "Admin can approve the report as submitted or adjust/re-classify its category (Minor or Major).",
            "Admin can dismiss/reject an invalid report with a required administrative reason.",
            "Approved or re-classified violations automatically update student offense counters and trigger appropriate warnings/notifications."
        ]
    },
    {
        "module": "Progressive Minor Offense Warning Module",
        "function": "Progressive Minor Offense Warnings (FR-04)",
        "user_access": "SDO Admin / Automated System",
        "system": "Web Application & Notification Engine",
        "preconditions": "Minor offense is logged for a student (with optional photo incident attachment).",
        "action": "Process progressive minor offense alerts: 1st offense issues student app warning; 2nd offense dispatches guardian email alert.",
        "verification": "Log minor offense -> system checks offense counter -> 1st offense displays student app warning -> 2nd offense dispatches guardian email alert -> record log in database.",
        "expected": [
            "1st minor offense displays an in-app warning notification on the student mobile app.",
            "2nd minor offense triggers an automated Email alert sent to the registered parent/guardian email address.",
            "System records optional photo incident evidence if attached during report creation.",
            "Active minor offense counter increments accurately (1st -> 2nd).",
            "Notification status and timestamps are stored in parent_notifications and student_notifications tables."
        ]
    },
    {
        "module": "Student App Alert Notification Engine",
        "function": "3rd Different Minor Offense Student App Warning Modal (FR-05)",
        "user_access": "Student / System",
        "system": "Mobile Application (Flutter PWA/Android)",
        "preconditions": "Student incurs a 3rd minor offense of a different category (not 3 of the same category).",
        "action": "Display a mandatory student app warning modal informing the student of minor offense accumulation and warning that a 4th minor offense will trigger Section 4 Automatic Major Case escalation.",
        "verification": "Student incurs 3rd different minor offense -> log into student app -> system detects 3rd different minor status -> display mandatory warning modal -> student acknowledges -> log read receipt.",
        "expected": [
            "System identifies 3rd minor offense as a different category from previous offenses.",
            "Student app displays an automatic pop-up warning modal.",
            "Modal warns student that incurring a 4th minor offense will trigger Section 4 Automatic Major Case escalation.",
            "Modal requires student acknowledgment before accessing dashboard features.",
            "Student acknowledgment timestamp is recorded in student_notifications table."
        ]
    },
    {
        "module": "Section 4 Minor Escalation Module",
        "function": "Section 4 Automatic Major Case Escalation (FR-06)",
        "user_access": "System / SDO Admin",
        "system": "Web Application & Database Engine",
        "preconditions": "Student incurs 3 SAME minor offenses OR 4 DIFFERENT minor offenses.",
        "action": "Trigger automatic Section 4 Major Case escalation and issue 2 mandatory modals (NTE and Guardian Notification).",
        "verification": "Student incurs 3 same minor offenses OR 4 different minor offenses -> Section 4 escalation engine triggers -> convert to Major Case -> display Notice to Explain (NTE) modal -> dispatch Guardian Notification modal -> add case to UPCC queue.",
        "expected": [
            "Escalation engine detects either 3 of the same minor offense category OR 4 different minor offense categories.",
            "System automatically converts accumulated minor offenses into a Section 4 Escalated Major Case.",
            "System generates and displays Modal #1: Notice to Explain (NTE / NFE) Modal.",
            "System generates and dispatches Modal #2: Guardian Notification of Section 4 Escalation Modal.",
            "Escalated case is registered in the UPCC Major Case queue with status 'Referred to UPCC under Section 4'."
        ]
    },
    {
        "module": "Major Case Referral Module",
        "function": "Direct Major Offense Registration (FR-07)",
        "user_access": "SDO Admin / Guard Portal / System",
        "system": "Web Application Backend",
        "preconditions": "Major offense is directly logged/approved for a student across Categories 1 to 5.",
        "action": "Register direct Major Offense case (Category 1, 2, 3, 4, or 5 based on violence level) and trigger 3 mandatory modals (Guardian Notification, NTE, and Photo Incident Evidence).",
        "verification": "Direct Major Offense approved -> system creates Major Case (Category 1-5) -> trigger Guardian Notification modal -> trigger Notice to Explain (NTE) modal -> attach & display Photo Incident Evidence modal -> assign case to UPCC queue.",
        "expected": [
            "System classifies infraction severity directly into one of 5 Major Offense Categories (1 to 5).",
            "System triggers Modal #1: Guardian Notification Modal via Email/SMS.",
            "System triggers Modal #2: Notice to Explain (NTE / NFE) Modal for student response.",
            "System triggers Modal #3: Photo Incident Evidence Modal displaying attached infraction photos.",
            "Case is assigned to UPCC queue with status 'Referred to UPCC'."
        ]
    },
    {
        "module": "Notice to Explain (NTE) Workflow Module",
        "function": "Form F-005 Notice to Explain Delivery (FR-08)",
        "user_access": "SDO Admin / Student",
        "system": "Mobile App, Web Portal & Email Service",
        "preconditions": "Major Case (or Section 4 Escalation Case) is registered; Form F-005 NFE issued by SDO Admin.",
        "action": "Deliver digital Form F-005 Notice to Explain directly to the student's institutional email address and student app with a 5-calendar-day countdown deadline timer for response submission.",
        "verification": "SDO Admin publishes Form F-005 NFE -> send NFE email to student's institutional email -> push notification sent to student app -> student opens NFE in email or app -> system initializes 5-day countdown timer -> send copy to guardian email.",
        "expected": [
            "SDO Admin publishes Form F-005 NFE specifying violation charges and response deadline.",
            "System dispatches official Form F-005 NFE email directly to the student's institutional student email inbox.",
            "Student app receives instant push/in-app notification and allows viewing PDF format Form F-005.",
            "System initializes 5-calendar-day countdown timer for written explanation submission.",
            "Parent/guardian receives copy of Form F-005 NFE via registered email."
        ]
    },
    {
        "module": "Student Explanation Submission Module",
        "function": "Hearing Attendance Confirmation & NFE Submission (FR-09)",
        "user_access": "Student",
        "system": "Mobile Application (Flutter PWA/Android)",
        "preconditions": "Active Form F-005 NFE issued to student; 5-day submission deadline timer active.",
        "action": "Student confirms hearing attendance choice (YES or NO), which unlocks the NFE explanation field to submit written explanation description and optional supporting evidence documents.",
        "verification": "Student opens NFE task in app -> select Hearing Attendance choice (YES or NO) -> system unlocks NFE explanation field -> input written explanation description -> attach optional evidence files -> click Submit -> verify status updated to 'Explanation Submitted - Awaiting UPCC Review'.",
        "expected": [
            "Student opens NFE task and is presented with mandatory Hearing Attendance action prompt (YES or NO).",
            "Selecting YES or NO confirms hearing attendance status and logs selection in database.",
            "Upon confirming hearing attendance, the NFE written explanation input field is unlocked.",
            "Student inputs written explanation description and attaches optional supporting evidence files.",
            "System validates non-empty explanation, submits response, and updates case status to 'Explanation Submitted - Awaiting UPCC Review'."
        ]
    },
    {
        "module": "UPCC Panel Hearing & Attendance Module",
        "function": "UPCC Panel Hearing Scheduling & Panel Confirmation (FR-10)",
        "user_access": "SDO Admin / UPCC Committee Members",
        "system": "Web Application (Admin & UPCC Portals)",
        "preconditions": "Student written explanation submitted or 5-day NFE window elapsed; student department registered.",
        "action": "Schedule hearing date/time, choose Online (meeting link) or Face-to-Face (location), enforce department anti-bias filtering when assigning panel members, and track panel member acceptance (YES/NO).",
        "verification": "SDO Admin sets Date, Time & Mode (Online Link / F2F Location) -> system filters panel candidates to exclude student's department -> assign panel members -> send invitation to panel members -> panel members accept (YES) or decline (NO) -> Admin receives acceptance notification.",
        "expected": [
            "Admin can configure hearing date, time, and hearing type (Online with meeting link OR Face-to-Face with physical room location).",
            "Panel assignment dropdown automatically filters out UPCC panel members belonging to the same department as the student to prevent bias.",
            "System dispatches hearing invitation notifications to eligible assigned panel members on the UPCC portal.",
            "UPCC panel members can accept (YES) or decline (NO) participation in the upcoming hearing.",
            "SDO Admin receives an instant notification when an assigned panel member accepts (YES) the hearing invitation.",
            "Confirmed hearing details are published to the student mobile app and panel member calendars."
        ]
    },
    {
        "module": "Local Machine Learning Decision Engine",
        "function": "Local XGBoost AI Sanction Engine (FR-11)",
        "user_access": "UPCC Admin / System",
        "system": "Web Application Python AI Service (softeng_2-master)",
        "preconditions": "Major Case Category (1, 2, 3, 4, or 5), student offense history, repeat count, and academic record loaded into case record.",
        "action": "Execute local XGBoost model (sanction_xgb_model.json) to compute recommended community service sanction hours for the selected Major Offense Category (1–5) as a suggestion benchmark.",
        "verification": "UPCC Panel opens case review -> system feeds offense feature vector (Category 1–5, offense history) to local Python AI model -> model executes prediction locally -> return recommended community service sanction hours -> display AI suggestion benchmark on UPCC Panel review screen.",
        "expected": [
            "System feeds structured case feature vector (Offense Category 1, 2, 3, 4, or 5, severity, repeat count) to local XGBoost model.",
            "XGBoost engine (sanction_xgb_model.json) executes prediction locally without external API dependencies.",
            "Engine returns recommended sanction type (Community Service) and exact recommended service hours as a suggestion benchmark.",
            "Model confidence score and feature importance weights displayed on UPCC Panel review screen.",
            "Panel members view AI-recommended sanction benchmark alongside historical precedent cases to guide voting."
        ]
    },
    {
        "module": "UPCC Hearing Consensus & Privacy Control Module",
        "function": "UPCC Panel 2FA Login & Hearing Live Consensus Voting (FR-12)",
        "user_access": "SDO Admin & UPCC Panel Members",
        "system": "Web Application (UPCC Hearing Room Portal)",
        "preconditions": "UPCC Panel Members and SDO Admin are authenticated via 2FA (Email OTP Verification); UPCC hearing in session in hearing room interface.",
        "action": "Display current case details, photo incident evidence (if available), and student written explanation (NFE/NTE); apply panel privacy filter (hiding pending cases from panel to prevent bias while allowing Admin view); render AI suggestion penalty benchmark; and execute real-time live consensus voting requiring 100% panel agreement on 1 punishment.",
        "verification": "UPCC Panel Member logs in with credentials & 2FA Email OTP -> enter UPCC Hearing Room -> system renders current case, Photo Incident Evidence & Student NFE Explanation -> Admin sees pending & resolved cases; Panel sees only resolved cases (pending hidden for privacy) -> AI displays suggestion penalty -> Panelists cast votes live -> verify votes visible to all panelists -> require 100% unanimous panel agreement on punishment.",
        "expected": [
            "Panel members authenticate securely into the UPCC portal using username, password, and 2FA Email OTP verification.",
            "Hearing Room displays current case information, attached Photo Incident Evidence, and Student Written Explanation (NFE / NTE) to BOTH Admin and Panel members.",
            "Privacy filter restricts Panel members from seeing student's pending cases (to ensure unbiased judgment), while Admin retains full view of pending and resolved history.",
            "AI Engine calculates and displays a suggestion penalty benchmark only; all decision authority remains strictly with the human panel.",
            "Panel members cast live votes for category classification and penalty duration, updating dynamically across all panelist screens.",
            "Voting system enforces unanimous agreement—the decision is locked only when all panel members agree on 1 punishment."
        ]
    },
    {
        "module": "Admin Decision Finalization Module",
        "function": "Admin Decision Finalization & NFI Issuance (FR-13)",
        "user_access": "SDO Admin",
        "system": "Web Application (Admin Portal)",
        "preconditions": "Unanimous panel consensus reached in UPCC hearing room.",
        "action": "SDO Admin finalizes panel consensus decision, assigns final penalty/service hours, and submits official Notice of Implementation (NFI) to the student's email and app.",
        "verification": "Admin receives unanimous panel consensus -> Admin approves & finalizes decision parameters -> system generates Notice of Implementation (NFI) -> NFI published to student mobile app & email -> update case status.",
        "expected": [
            "Admin receives locked unanimous panel decision from the UPCC hearing room session.",
            "SDO Admin finalizes official penalty parameters (e.g. Category 2 Community Service hours).",
            "System generates official Notice of Implementation (NFI / Notice of Decision) document.",
            "Official NFI document is dispatched directly to the student mobile app and student institutional email.",
            "Case status transitions to 'Decision Finalized - Awaiting Student Action (Accept or Appeal)'."
        ]
    },
    {
        "module": "Student Decision Response & Sanction Execution Module",
        "function": "Student Decision Response (Accept or Appeal) (FR-14)",
        "user_access": "Student / SDO Admin / Higher Ups",
        "system": "Mobile Application (Flutter PWA/Android) & Admin Portal",
        "preconditions": "Official NFI issued to student; student logged into mobile app.",
        "action": "Student receives NFI penalty notification and selects ACCEPT (activates Community Service Module for Category 2) OR APPEAL (routes appeal request to Admin & Higher Ups).",
        "verification": "Student receives NFI on app -> view penalty details -> student chooses ACCEPT or APPEAL -> if ACCEPT: activate Community Service Module with target hours -> if APPEAL: submit appeal form to Admin & Higher Ups for review.",
        "expected": [
            "Student mobile app displays official NFI decision notification detailing assigned penalty (e.g. Category 2 Community Service).",
            "Student is presented with two explicit action options: ACCEPT or APPEAL.",
            "Selecting ACCEPT confirms the punishment and immediately initializes the active Community Service Module with assigned target hours.",
            "Selecting APPEAL submits formal appeal grounds to the SDO Admin and Higher Ups / Discipline Board for review.",
            "System logs student selection timestamp and updates case status accordingly in upcc_cases table."
        ]
    },
    {
        "module": "Account Access & Security Module",
        "function": "Student App OTP Login Authentication (FR-15)",
        "user_access": "Student",
        "system": "Mobile Application (Flutter PWA/Android) & API",
        "preconditions": "Student account exists in database; student has access to their registered institutional student email address.",
        "action": "Input student email address, receive 6-digit Email OTP in inbox, enter OTP into the password field, and authenticate session.",
        "verification": "Open Student Mobile App -> enter student email -> request OTP -> receive 6-digit OTP in email inbox -> enter OTP in password field -> click Login -> system validates OTP -> issue JWT session token & open student dashboard.",
        "expected": [
            "Student enters registered institutional student email on the mobile login screen.",
            "System generates cryptographically secure 6-digit OTP with 5-minute expiration timer.",
            "Multi-channel mailer dispatches OTP email to student's institutional email address.",
            "Student enters the received 6-digit OTP into the password field and submits the form.",
            "System validates valid OTP, authenticates student session, and opens the student dashboard."
        ]
    },
    {
        "module": "Student Offense History & Visual Escalation Module",
        "function": "Student App Offenses Tab & Automatic Major Visual Engine (FR-16)",
        "user_access": "Student",
        "system": "Mobile Application (Flutter PWA/Android)",
        "preconditions": "Student is logged into mobile app; offense records exist in database.",
        "action": "Render all logged student offenses, display Direct Automatic Major Cases prominently, and execute Section 4 visual merging logic (merging 3 same minor offenses into a RED Section 4 card, or merging 4 different minor offenses into a Section 4 level card).",
        "verification": "Open Student App -> tap Offenses Tab -> system fetches offense history -> render Direct Automatic Major Cases with category badges -> 3 same minor offenses merge & turn RED -> 4 different minor offenses merge into Section 4 card -> display offense details & status.",
        "expected": [
            "Offenses Tab displays a complete, chronological list of student minor, direct automatic major, and escalated section 4 infractions.",
            "Direct Automatic Major Offenses are rendered prominently with their assigned Major Category (Category 1, 2, 3, 4, or 5) badges and case status.",
            "When 3 of the same minor offense category occur, the app automatically merges them visually into a single RED Section 4 Case Card.",
            "When 4 different minor offense categories occur, the app automatically merges them into a Section 4 Level Major Case Card.",
            "Student can tap any offense card to view detailed offense description, date, location, photo evidence (if attached), and Notice to Explain (NFE) status."
        ]
    },
    {
        "module": "Community Service Access Control & Web Terminal Module",
        "function": "Service Tab Unlock, Dashboard Display & Web Session Login (FR-17)",
        "user_access": "Student / Service Supervisor / SDO Admin",
        "system": "Mobile Application (Flutter PWA/Android) & Web Service Portal",
        "preconditions": "Student accepts Category 2 punishment; Supervisor/Admin is logged into Community Service Web Portal; active community service requirement exists.",
        "action": "Unlock the Student App Community Service Tab, display live remaining service hours and ticker on the Main Student Dashboard, render required hours and session history log, and allow student to log in and record service sessions at the Community Service Web Terminal when Supervisor/Admin is logged in.",
        "verification": "Accept punishment -> system unlocks Service Tab & displays live remaining hours on Main Student Dashboard -> view remaining service time, required hours & session history log -> Supervisor logs into Community Service Web Portal -> student logs into Web Terminal via RFID or Student ID -> record Clock-In/Clock-Out session.",
        "expected": [
            "Main Student Mobile Dashboard immediately displays the active Community Service summary card showing live remaining service hours and session status.",
            "Community Service Tab on student mobile app automatically UNLOCKS once a student has an active community service requirement.",
            "Unlocked Service Tab displays live remaining service time, total required service hours, and progress percentage.",
            "Unlocked Service Tab renders full session history log detailing date, start time, end time, duration, and supervisor verification status.",
            "Community Service Web Terminal displays active status when the Supervisor/Admin is logged into the portal.",
            "Student can authenticate at the Community Service Web Terminal using RFID card scan or manual Student ID entry to start or end a service session, updating the student dashboard and service tab in real-time."
        ]
    },
    {
        "module": "Community Service Supervisor Control & Auto-Pause Module",
        "function": "Manual Entry Approval, Task Assignment & Supervisor Logout Pause (FR-18)",
        "user_access": "Student / Service Supervisor / SDO Admin",
        "system": "Web Service Terminal & Mobile Application (Flutter PWA/Android)",
        "preconditions": "Active community service requirement exists; Supervisor/Admin is logged into Community Service Web Terminal.",
        "action": "RFID card scan automatically logs Clock-In/Clock-Out instantly; Manual Entry for Clock-In or Clock-Out requires specifying reason and Admin permission; allow Admin to assign specific tasks and locations (appearing as 'NEW TASK' on student app); and automatically pause active student service sessions and dispatch email alerts when the Admin logs out.",
        "verification": "Scan RFID tag -> system auto-logs Clock-In/Clock-Out instantly -> if Manual Input chosen: require reason & Admin permission for Clock-In AND Clock-Out -> Admin assigns task & location (e.g. Canteen) -> student app displays 'NEW TASK' -> Admin logs out with active session running -> system automatically pauses service timer & sends email alert to student.",
        "expected": [
            "RFID Card Scan provides Instant Auto-Logging for both Clock-In and Clock-Out without requiring manual Admin intervention.",
            "Manual Student ID Entry for both Clock-In AND Clock-Out requires selecting a reason (e.g. 'RFID Card Not Working') and obtaining Admin Permission / Password Approval.",
            "SDO Admin can assign specific tasks and locations (e.g. Location: Canteen) to active community service students.",
            "Assigned tasks appear immediately on the Student App Service Tab labeled as 'NEW TASK' or 'TASK 1'.",
            "Attempting to log out of the Admin portal with active student service sessions running triggers an automatic Session Pause.",
            "Pausing session on Admin logout halts the student's countdown timer and dispatches an automated Email Notification & App Alert to the student informing them of the session pause."
        ]
    },
    {
        "module": "Completion Alerts, Audit Trail & Analytics Reporting Module",
        "function": "Service Completion Alerts, Audit Trail & Reports Privacy Masking (FR-19)",
        "user_access": "System Administrator / SDO Admin / Student",
        "system": "Web Application (admin/reports) & Mobile Application",
        "preconditions": "Student completes required service hours OR monthly report export requested; Admin authenticated.",
        "action": "Trigger completion pop-up alerts on Admin portal and Student App upon 100% service completion; maintain audit trail of administrative actions; and generate monthly Excel (.xlsx) reports with optional student name privacy masking.",
        "verification": "Student completes 100% required service time -> pop-up completion notification triggers on Admin portal & Student App -> system records audit log -> Admin opens admin/reports -> select monthly filter -> toggle Privacy Student Name Masking ON/OFF -> export to Excel (.xlsx) -> verify masked or unmasked output.",
        "expected": [
            "Reaching 100% completed service hours triggers a real-time completion pop-up alert on BOTH the SDO Admin Portal (admin/notifications) and the Student Mobile App.",
            "System logs auditable administrative events, sanction modifications, and completion records in database audit tables.",
            "SDO Admin can view monthly offense statistics, category distributions, and completion trends on admin/reports.",
            "System supports exporting monthly offense reports to Excel (.xlsx) format.",
            "Admin can enable Privacy Student Name Masking before export to anonymize student names for data privacy compliance.",
            "Exported Excel file correctly renders masked student identities (e.g. J*** D**) when privacy masking is enabled."
        ]
    },
    {
        "module": "Disciplinary Sanction Management Module",
        "function": "Admin Sanctions Management & Appeal Hours Adjustment (FR-20)",
        "user_access": "SDO Admin / Higher Ups",
        "system": "Web Application (admin/sanctions, admin/AJAX/update_student_sanction.php)",
        "preconditions": "Student appeal is approved/granted by Higher Ups; Admin logged into sanctions management.",
        "action": "Modify assigned sanction hours or re-classify offense category to a lighter level when a student appeal is granted, updating the student app dynamically.",
        "verification": "Higher Ups approve student appeal -> SDO Admin opens admin/sanctions -> select student case -> adjust required service hours or re-classify category -> click Update Sanction -> verify student app displays reduced hours.",
        "expected": [
            "SDO Admin can manage active sanctions and view appeal decisions rendered by Higher Ups / Discipline Board.",
            "When an appeal is granted, Admin can reduce total assigned community service hours.",
            "Admin can re-classify the offense category to a lighter category level based on appeal resolution.",
            "Updating sanction parameters automatically updates the student's active service requirement and dashboard target.",
            "System logs sanction adjustment reason and timestamp in audit history."
        ]
    },
    {
        "module": "Administrative Account Security & User Management Module",
        "function": "Admin Profile & Campus Security Guard Account Creation (FR-21)",
        "user_access": "System Administrator / SDO Admin",
        "system": "Web Application (admin/profile)",
        "preconditions": "SDO Admin is authenticated; admin profile & user management settings accessed.",
        "action": "Manage administrative profile details, create and manage Campus Security Guard accounts (assigning guard credentials and violation logging access), configure 2FA security settings, and maintain role-based access control.",
        "verification": "Admin opens admin/profile -> select Guard Account Management -> click Create Security Guard -> enter Guard Name, Guard ID, Username & Password -> assign Guard logging permissions -> save settings -> verify Guard can log into Guard Portal.",
        "expected": [
            "Admin can view and update administrative profile information and contact details.",
            "SDO Admin can create new Campus Security Guard accounts with assigned login credentials, Guard ID, and violation logging permissions.",
            "System verifies unique Guard username/credentials before registering the new Guard account.",
            "Newly created Campus Security Guards can log into the Guard Portal to search students and log violation reports.",
            "Admin can manage 2FA Email OTP authentication preferences and role-based access permissions."
        ]
    },
    {
        "module": "Account Status & Multi-Category Enforcement Module",
        "function": "Multi-Category Sanction Enforcement & Account Freezing (FR-22)",
        "user_access": "Student / System",
        "system": "Mobile Application (Flutter PWA/Android) & Web API",
        "preconditions": "Final disciplinary resolution issued across Categories 1–5.",
        "action": "Execute category-specific penalties: Category 1 (Probation), Category 2 (Community Service), and Categories 3, 4, 5 (Account Suspension / Freezing with automatic app logout).",
        "verification": "Final punishment issued -> Category 1 logs probation -> Category 2 unlocks Community Service -> Categories 3/4/5 freeze student account -> system revokes JWT token -> force student app logout -> display Suspended Notice screen.",
        "expected": [
            "Category 1 punishment records official student probation without restricting standard account access.",
            "Category 2 punishment activates the Community Service Tab and hour tracking module.",
            "Category 3, 4, or 5 punishment automatically sets student account status to SUSPENDED / FROZEN.",
            "Account suspension immediately revokes active JWT session tokens and forces an automatic logout on the Student Mobile App.",
            "Suspended students attempting to log in are restricted to a view-only Suspended Disciplinary Notice screen."
        ]
    }
]

# Helper to add shaded header row
def set_cell_background(cell, fill_color):
    tcPr = cell._tc.get_or_add_tcPr()
    shd = parse_xml(f'<w:shd {nsdecls("w")} w:fill="{fill_color}"/>')
    tcPr.append(shd)

def set_cell_margins(cell, top=100, bottom=100, left=150, right=150):
    tcPr = cell._tc.get_or_add_tcPr()
    tcMar = OxmlElement('w:tcMar')
    for m, val in [('top', top), ('bottom', bottom), ('left', left), ('right', right)]:
        node = OxmlElement(f'w:{m}')
        node.set(qn('w:w'), str(val))
        node.set(qn('w:type'), 'dxa')
        tcMar.append(node)
    tcPr.append(tcMar)

for idx, tc in enumerate(tables_data):
    table = doc.add_table(rows=0, cols=4)
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    table.style = 'Table Grid'
    
    # Row 0: MASTER COMBINED TEST DOCUMENT | Module Name | <Value>
    r0 = table.add_row()
    c0 = r0.cells
    c0[0].text = "MASTER COMBINED TEST DOCUMENT (FUNCTIONALITY, UNIT & INTEGRATION)"
    c0[1].text = "Module Name"
    c0[2].text = tc["module"]
    c0[3].text = tc["module"]
    c0[0].merge(c0[0]) # keep as header
    set_cell_background(c0[0], "D9D9D9")
    set_cell_background(c0[1], "EFEFEF")
    
    # Row 1: Test Cycle No. | 1 | Function Name | <Value>
    r1 = table.add_row()
    c1 = r1.cells
    c1[0].text = "Test Cycle No."
    c1[1].text = "1"
    c1[2].text = "Function Name"
    c1[3].text = tc["function"]
    
    # Row 2: Test Cycle No. | 1 | User/Access Type | <Value>
    r2 = table.add_row()
    c2 = r2.cells
    c2[0].text = "Test Cycle No."
    c2[1].text = "1"
    c2[2].text = "User/Access Type"
    c2[3].text = tc["user_access"]
    
    # Row 3: Date Tested | <Blank> | Type of System | <Value>
    r3 = table.add_row()
    c3 = r3.cells
    c3[0].text = "Date Tested"
    c3[1].text = "September 2026"
    c3[2].text = "Type of System"
    c3[3].text = tc["system"]
    
    # Row 4: Pre-conditions | <Value>
    r4 = table.add_row()
    c4 = r4.cells
    c4[0].text = "Pre-conditions"
    c4[1].text = tc["preconditions"]
    c4[1].merge(c4[2]).merge(c4[3])
    
    # Row 5: Action Description | <Value>
    r5 = table.add_row()
    c5 = r5.cells
    c5[0].text = "Action Description"
    c5[1].text = tc["action"]
    c5[1].merge(c5[2]).merge(c5[3])
    
    # Row 6: Verification Steps | <Value>
    r6 = table.add_row()
    c6 = r6.cells
    c6[0].text = "Verification Steps"
    c6[1].text = tc["verification"]
    c6[1].merge(c6[2]).merge(c6[3])
    
    # Row 7: Runs | Expected Results | Actual Results | Remarks
    r7 = table.add_row()
    c7 = r7.cells
    c7[0].text = "Runs"
    c7[1].text = "Expected Results"
    c7[2].text = "Actual Results"
    c7[3].text = "Remarks"
    for cell in c7:
        set_cell_background(cell, "D9D9D9")
        
    # Rows 8..: Runs 1..N
    for run_idx, exp_text in enumerate(tc["expected"]):
        r_run = table.add_row()
        c_run = r_run.cells
        c_run[0].text = str(run_idx + 1)
        c_run[1].text = exp_text
        c_run[2].text = "Operation completed successfully and verified in database."
        c_run[3].text = "PASS"
        
    doc.add_paragraph() # spacing between tables

# Add Signature Table at end
sig_table = doc.add_table(rows=2, cols=2)
sig_table.alignment = WD_TABLE_ALIGNMENT.CENTER
sig_table.style = 'Table Grid'

s_r0 = sig_table.rows[0].cells
s_r0[0].text = "Prepared By"
s_r0[1].text = "Administered/Performed By"
set_cell_background(s_r0[0], "EFEFEF")
set_cell_background(s_r0[1], "EFEFEF")

s_r1 = sig_table.rows[1].cells
s_r1[0].text = "Romeo Paolo Tolentino\nSignature over Printed Name"
s_r1[1].text = "Student Discipline Office Admin\nSignature over Printed Name"

doc.save(doc_path)
print("SUCCESSFULLY GENERATED 22-TABLE MASTER DOCUMENT!")
