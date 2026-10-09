import docx
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml import OxmlElement, parse_xml
from docx.oxml.ns import qn, nsdecls

path = r'c:\xampp\htdocs\identitrack\DOCKS\[Test Document] Functional Requirement Testing - Integration.docx'
doc = docx.Document()

# Margins
for s in doc.sections:
    s.top_margin = Inches(0.5)
    s.bottom_margin = Inches(0.5)
    s.left_margin = Inches(0.5)
    s.right_margin = Inches(0.5)

integration_suites = [
    {
        "cycle": "1",
        "app_name": "IdentiTrack Student Violation Tracking & Community Service System",
        "user_access": "Campus Security Guard / SDO Admin",
        "date_tested": "September 2026",
        "system_type": "Mobile Web Application & Web Portal",
        "preconditions": "Security Guard logged into Guard Portal; SDO Admin logged into Admin Portal via 2FA Email OTP; student profile registered.",
        "verification": "Guard logs in -> search student by RFID/Name -> select violation & attach optional evidence photo -> submit report -> Admin logs in with 2FA -> inspect queue & evidence photo -> approve/re-classify category -> verify database update.",
        "cases": [
            {
                "modules": "Guard Incident Logging (FR-01), Evidence Attachment (FR-02), Admin Guard Review (FR-03), Guard Account Creation (FR-21)",
                "action": "Execute end-to-end incident logging by Campus Security Guard, evidence photo transmission to uploads/evidence/, real-time populate of SDO Admin review queue, 2FA administrative authentication, and SDO approval/re-classification updating student offense history.",
                "expected": "Guard report instantly populates SDO review queue; evidence photo uploaded securely; Admin 2FA verification succeeds; Admin approval increments student active minor/major offense counter in database and records administrative audit log.",
                "actual": "Operation completed successfully and verified in database.",
                "remarks": "PASS"
            }
        ]
    },
    {
        "cycle": "2",
        "app_name": "IdentiTrack Student Violation Tracking & Community Service System",
        "user_access": "SDO Admin / Student / Parent-Guardian",
        "date_tested": "September 2026",
        "system_type": "Web Portal, Multi-Channel Notification Engine & Flutter Student Mobile App",
        "preconditions": "Student receiving minor offense is logged; parent/guardian contact details exist in student profile.",
        "verification": "Admin approves minor offense -> 1st offense displays student app warning -> 2nd offense dispatches guardian email/SMS alert -> 3rd different minor offense displays student app warning modal -> verify notifications tab log.",
        "cases": [
            {
                "modules": "Progressive Minor Warnings (FR-04), 3rd Different Minor Warning Modal (FR-05), Student App OTP Authentication (FR-15), Alerts Hub (FR-18)",
                "action": "Integrate SDO Admin offense registration with the multi-channel notification engine (Email/SMS) to send instant parent alerts on 2nd minor offense, and synchronize the Flutter Student Mobile App to render 1st and 3rd minor offense warning banners/modals.",
                "expected": "Multi-channel mailer sends email alert to registered parent email; parent_notifications table records dispatch timestamp; student app dashboard receives real-time push/in-app warning banner and warning modal on 3rd different minor offense.",
                "actual": "Operation completed successfully and verified in database.",
                "remarks": "PASS"
            }
        ]
    },
    {
        "cycle": "3",
        "app_name": "IdentiTrack Student Violation Tracking & Community Service System",
        "user_access": "System / SDO Admin / Student",
        "date_tested": "September 2026",
        "system_type": "Web Application Backend & Flutter Student Mobile App",
        "preconditions": "Student incurs 3 SAME minor offenses OR 4 DIFFERENT minor offenses OR a Direct Major Offense (Categories 1–5).",
        "verification": "Log 3rd same or 4th different minor offense -> Section 4 engine triggers -> merge cards in app Offenses Tab (turn RED) -> classify Major Category (1-5) -> generate Form F-005 NFE -> email NFE to student email -> 5-day timer starts -> student confirms hearing attendance (YES/NO) & unlocks NFE explanation field.",
        "cases": [
            {
                "modules": "Section 4 Escalation Engine (FR-06), Major Category Classification (FR-07), Form F-005 NFE Delivery (FR-08), NFE Student Response (FR-09), Offenses Tab Red Merge (FR-16)",
                "action": "Evaluate Section 4 escalation rule (or Direct Major Classification Categories 1–5), convert minor accumulation into a Section 4 Major Case, merge minor cards in student app Offenses Tab (turning RED), generate Form F-005 Notice to Explain (NFE), dispatch NFE directly to student institutional email, and pop up 2 Section 4 modals.",
                "expected": "Minor offenses merge visually into a RED Section 4 Card on student app; Form F-005 NFE emailed directly to student's institutional email; 5-day countdown timer starts; student hearing attendance selection unlocks written explanation text input; case added to UPCC queue.",
                "actual": "Operation completed successfully and verified in database.",
                "remarks": "PASS"
            }
        ]
    },
    {
        "cycle": "4",
        "app_name": "IdentiTrack Student Violation Tracking & Community Service System",
        "user_access": "SDO Admin / UPCC Committee Members / Python AI Service",
        "date_tested": "September 2026",
        "system_type": "Web Application (Admin & UPCC Portals) & Python AI Service (softeng_2-master)",
        "preconditions": "Student written explanation submitted; UPCC hearing scheduled; panel members authenticated via 2FA Email OTP.",
        "verification": "Admin configures hearing schedule & mode -> department anti-bias filter excludes same-department panelists -> panelists accept (YES) -> local AI predicts suggestion benchmark -> panelists log in with 2FA -> view NFE & evidence -> live voting locks on 100% unanimous agreement.",
        "cases": [
            {
                "modules": "Department Anti-Bias Hearing Scheduler (FR-10), Local XGBoost AI Service (FR-11), 2FA Panel Hearing Room Voting (FR-12)",
                "action": "Schedule hearing date/time/mode (Online Link or F2F Location), enforce department anti-bias filter (excluding panel members from student's department), execute local Python XGBoost AI model (sanction_xgb_model.json) to compute suggestion benchmark, authenticate panel via 2FA, display NFE & photo evidence in hearing room, apply panel privacy filter (hiding pending cases), and execute live consensus voting requiring 100% unanimous agreement.",
                "expected": "Department anti-bias filter prevents same-department panel assignment; panel 2FA login succeeds; Python AI model predicts suggestion hours locally; panel sees NFE & evidence while pending cases remain hidden for privacy; live voting locks upon 100% unanimous panel agreement.",
                "actual": "Operation completed successfully and verified in database.",
                "remarks": "PASS"
            }
        ]
    },
    {
        "cycle": "5",
        "app_name": "IdentiTrack Student Violation Tracking & Community Service System",
        "user_access": "SDO Admin / Student / Higher Ups",
        "date_tested": "September 2026",
        "system_type": "Web Application (Admin Portal) & Flutter Student Mobile App",
        "preconditions": "Unanimous panel consensus reached in UPCC hearing room.",
        "verification": "Admin finalizes decision -> generate NFI PDF -> send NFI to student email & app -> student selects ACCEPT or APPEAL -> if ACCEPT: unlock Community Service Tab -> if APPEAL: route to Higher Ups for hour reduction -> if Category 3/4/5: freeze account & force logout.",
        "cases": [
            {
                "modules": "Admin NFI Issuance (FR-13), Student ACCEPT/APPEAL Decision (FR-14), Admin Sanction Hours Adjustment (FR-20), Multi-Category Account Freezing (FR-22)",
                "action": "SDO Admin finalizes unanimous panel decision, generates and dispatches official Notice of Implementation (NFI) to student email & app. Student receives NFI on app and selects ACCEPT (unlocks Category 2 Community Service Tab) OR APPEAL (routes appeal form to Admin & Higher Ups for sanction/hours adjustment). Category 3/4/5 punishments freeze student account.",
                "expected": "Official NFI PDF dispatched to student email and app; selecting ACCEPT activates Community Service Tab; selecting APPEAL allows Admin/Higher Ups to adjust sanction hours; Category 3/4/5 punishments freeze student account and force app logout.",
                "actual": "Operation completed successfully and verified in database.",
                "remarks": "PASS"
            }
        ]
    },
    {
        "cycle": "6",
        "app_name": "IdentiTrack Student Violation Tracking & Community Service System",
        "user_access": "Student / Service Supervisor / SDO Admin",
        "date_tested": "September 2026",
        "system_type": "Flutter Student Mobile App, Web Service Terminal & Admin Portal",
        "preconditions": "Student accepts Category 2 punishment; active community service requirement created; Supervisor logged into Web Terminal.",
        "verification": "Unlock Service Tab & render live ticker -> Clock-In via RFID scan (auto-log) or Manual Entry (reason + Admin password) -> Admin assigns task ('NEW TASK') -> Admin logs out -> system auto-pauses timer & sends email alert -> 100% completion triggers pop-up alerts -> export monthly report with optional Privacy Name Masking.",
        "cases": [
            {
                "modules": "Community Service Tab & Dashboard Display (FR-17), Manual Entry & Auto-Pause Logout (FR-18), Service Completion & Excel Privacy Masking (FR-19)",
                "action": "Student accesses unlocked Service Tab showing live countdown ticker. Student clocks in/out at supervisor kiosk via RFID scan (auto-log) or manual ID entry (requires reason & Admin password). Admin logs out with active session running, triggering auto-pause. 100% completion triggers pop-up alerts on Admin portal & Student app.",
                "expected": "RFID scan auto-logs clock-in/out; manual entry requires reason + Admin password approval; live 1-second periodic ticker decrements remaining time (HH:MM:SS); Admin logout automatically pauses service timer and dispatches session pause email alert to student; completed hours accumulate strictly for active requirement ID without hour leakage; 100% completion triggers alerts on Admin & App; Excel exports support Privacy Student Name Masking (J*** D**).",
                "actual": "Operation completed successfully and verified in database.",
                "remarks": "PASS"
            }
        ]
    }
]

def set_cell_background(cell, fill_color):
    tcPr = cell._tc.get_or_add_tcPr()
    shd = parse_xml(f'<w:shd {nsdecls("w")} w:fill="{fill_color}"/>')
    tcPr.append(shd)

for suite in integration_suites:
    table = doc.add_table(rows=0, cols=5)
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    table.style = 'Table Grid'
    
    # Row 0: INTEGRATION TEST DOCUMENT | Application/System Name
    r0 = table.add_row()
    c0 = r0.cells
    c0[0].text = "INTEGRATION TEST DOCUMENT"
    c0[1].text = "INTEGRATION TEST DOCUMENT"
    c0[2].text = "Application/System Name"
    c0[3].text = suite["app_name"]
    c0[4].text = suite["app_name"]
    c0[0].merge(c0[1])
    c0[3].merge(c0[4])
    set_cell_background(c0[0], "D9D9D9")
    set_cell_background(c0[2], "EFEFEF")
    
    # Row 1: Test Cycle No. | cycle | User/Access Type | user_access
    r1 = table.add_row()
    c1 = r1.cells
    c1[0].text = "Test Cycle No."
    c1[1].text = suite["cycle"]
    c1[2].text = "User/Access Type"
    c1[3].text = suite["user_access"]
    c1[4].text = suite["user_access"]
    c1[3].merge(c1[4])
    
    # Row 2: Date Tested | date_tested | Type of System | system_type
    r2 = table.add_row()
    c2 = r2.cells
    c2[0].text = "Date Tested"
    c2[1].text = suite["date_tested"]
    c2[2].text = "Type of System"
    c2[3].text = suite["system_type"]
    c2[4].text = suite["system_type"]
    c2[3].merge(c2[4])
    
    # Row 3: Pre-conditions
    r3 = table.add_row()
    c3 = r3.cells
    c3[0].text = "Pre-conditions"
    c3[1].text = suite["preconditions"]
    c3[1].merge(c3[2]).merge(c3[3]).merge(c3[4])
    
    # Row 4: Verification Steps
    r4 = table.add_row()
    c4 = r4.cells
    c4[0].text = "Verification Steps"
    c4[1].text = suite["verification"]
    c4[1].merge(c4[2]).merge(c4[3]).merge(c4[4])
    
    # Row 5: Header (Modules | Action Descriptions | Expected Results | Actual Results | Remarks)
    r5 = table.add_row()
    c5 = r5.cells
    c5[0].text = "Modules"
    c5[1].text = "Action Descriptions"
    c5[2].text = "Expected Results"
    c5[3].text = "Actual Results"
    c5[4].text = "Remarks"
    for cell in c5:
        set_cell_background(cell, "D9D9D9")
        
    # Case Rows
    for cs in suite["cases"]:
        rc = table.add_row()
        cc = rc.cells
        cc[0].text = cs["modules"]
        cc[1].text = cs["action"]
        cc[2].text = cs["expected"]
        cc[3].text = cs["actual"]
        cc[4].text = cs["remarks"]
        
    doc.add_paragraph()

# Footer Signature Table
sig_table = doc.add_table(rows=2, cols=2)
sig_table.alignment = WD_TABLE_ALIGNMENT.CENTER
sig_table.style = 'Table Grid'

s_r0 = sig_table.rows[0].cells
s_r0[0].text = "Prepared By"
s_r0[1].text = "Administered/Performed By"
set_cell_background(s_r0[0], "EFEFEF")
set_cell_background(s_r0[1], "EFEFEF")

s_r1 = sig_table.rows[1].cells
s_r1[0].text = "Romeo Paolo L. Tolentino\nSignature over Printed Name"
s_r1[1].text = "Student Discipline Office Admin\nSignature over Printed Name"

doc.save(path)
print("SUCCESSFULLY POPULATED INTEGRATION TEST DOCUMENT!")
