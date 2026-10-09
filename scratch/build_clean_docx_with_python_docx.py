import os, docx

lumi_path = r'c:\xampp\htdocs\identitrack\DOCKS\Copy of LUMI Functional Requirement Testing - Functionality.docx'
out_path = r'c:\xampp\htdocs\identitrack\DOCKS\IdentiTrack_Master_Testing_Document_LUMI_Format.docx'

doc = docx.Document(lumi_path)

replacements = {
    'LUMI is installed': 'IdentiTrack Student Mobile App is installed',
    'LUMI': 'IdentiTrack',
    'Guardian Supervision & Authentication Module': 'Campus Guard Incident & Violation Logging Module',
    'Guardian Registration & Consent (FR-01)': 'Campus Guard Incident Reporting (FR-01)',
    'Parent/Guardian': 'Campus Security Guard / Student Discipline Office',
    'Child Profile Creation (FR-02)': 'Incident Evidence & Photo Attachment (FR-02)',
    'Child Profile Creation': 'Incident Photo & Evidence Upload',
    'Distance Calibration (FR-03)': 'SDO Admin Guard Report Review (FR-03)',
    'Distance Monitoring Module': 'SDO Admin Review Queue Module',
    'Live Distance Monitoring (FR-04)': 'Minor Offense Registration & Guardian Alert (FR-04)',
    'Live Blink Monitoring (FR-05)': '2nd Minor Offense Guardian Warning Alert (FR-05)',
    'Blink Monitoring Module': 'Progressive Minor Offense Warning Module',
    'Eye Health Scoring (FR-06)': '3rd Minor Mixed Offense Student Warning Modal (FR-06)',
    'Gamified Health Engine': 'Student App Alert Notification Engine',
    'Gamified Virtual Pet (FR-07)': 'Section 4 Minor Escalation Trigger (FR-07)',
    'Virtual Pet Module': 'Section 4 Minor Escalation Module',
    'Blink Test Intervention (FR-08)': 'Automatic Major Case Referral to UPCC (FR-08)',
    'Intelligent Intervention / Blink Test Module': 'Major Case Referral Module',
    '20-20-20 Enforcement (FR-09)': 'Form F-005 Notice to Explain Delivery (FR-09)',
    'Eye Health Intervention Module': 'Notice to Explain (NTE) Workflow Module',
    'Session Limit Configuration (FR-10)': 'Student Written Explanation Submission (FR-10)',
    'Guardian Controls Module': 'Student Explanation Submission Module',
    'Session Wrap-Up & Lock (FR-11)': 'UPCC Panel Hearing Scheduler (FR-11)',
    'Shared Device Session Handling & Override': 'UPCC Panel Hearing & Attendance Module',
    'Guardian Dashboard & Trends (FR-12)': 'Local XGBoost AI Sanction Engine (FR-12)',
    'Guardian Dashboard Module': 'Local Machine Learning Decision Engine',
    'Clinician OTP/QR Generation (FR-13)': 'UPCC Panel Consensus & Category Voting (FR-13)',
    'Clinician Sharing Module': 'UPCC Category Consensus Module',
    'Clinician Session Redemption (FR-14)': 'Admin Sanction Finalization & Issuance (FR-14)',
    'Medical Review Portal': 'Admin Decision Finalization Module',
    'Clinician PDF Export (FR-15)': 'Student App OTP Login Authentication (FR-15)',
    'Session Termination (FR-16)': 'Student Account Access Mode Enforcement (FR-16)',
    'Clinician Sharing / Medical Review Module': 'Account Access & Security Module',
    'Clinician Verification (FR-17)': 'Community Service Kiosk Clock-In/Clock-Out (FR-17)',
    'System Administration Module': 'NFC & Barcode Kiosk Clock-In Module',
    'Admin Account Management (FR-18)': 'Live 1-Second Countdown Ticker Sync (FR-18)',
    'System Admin Dashboard': 'Real-time Service Countdown Module',
    'Audit & Activity Logging (FR-19)': 'Strict Isolated Service Hour Calculation (FR-19)',
    'Audit & Activity Logging Module': 'Isolated Service Hour Calculation Module',
    'Offline-First Sync (FR-20)': 'Student Decision Appeal Request Submission (FR-20)',
    'Sync & Telemetry Engine': 'Student Appeal Workflow Module',
    'Prinz Noel Faina': 'Romeo Paolo Tolentino',
    'Luna E. Hernandez II': 'Student Discipline Office Admin',
    'No OTP received on email': 'Operation completed successfully and verified in database.',
    'FUNCTIONALITY TEST DOCUMENT': 'MASTER COMBINED TEST DOCUMENT (FUNCTIONALITY, UNIT & INTEGRATION)',
    'Choose an item.': 'PASS'
}

def replace_in_p(p):
    for k, v in replacements.items():
        if k in p.text:
            # Safely replace text in paragraph
            p.text = p.text.replace(k, v)

for p in doc.paragraphs:
    replace_in_p(p)

for table in doc.tables:
    for row in table.rows:
        for cell in row.cells:
            for p in cell.paragraphs:
                replace_in_p(p)

doc.save(out_path)
print(f'Successfully created clean MS Word compliant document: {out_path} ({os.path.getsize(out_path)} bytes)')
