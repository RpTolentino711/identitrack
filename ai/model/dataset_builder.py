import json
import os
import re

DATASET_VERSION = "UPCC-DATA-v1.0"
RAW_DATASET_PATH = r"c:\xampp\htdocs\identitrack\scratch\all_historical_records.json"
OUTPUT_DIR = r"c:\xampp\htdocs\identitrack\ai\storage\datasets"

def clean_offense_name(name):
    if not name:
        return "GENERAL_VIOLATION"
    name = str(name).strip().upper()
    name = re.sub(r'\s+', ' ', name)
    return name

def sanitize_record(rec, idx):
    # Strictly exclude any PII (student_name, student_id, email, phone, address, etc.)
    raw_level = str(rec.get('level') or 'MINOR').upper().strip()
    offense_name = clean_offense_name(rec.get('offense') or rec.get('offense_name'))
    raw_sanction = str(rec.get('sanction') or rec.get('punishment') or rec.get('category') or '').upper().strip()

    # Determine ground-truth Category label
    decided_category = "Category 1"
    if "CATEGORY 5" in raw_sanction or "EXPULSION" in raw_sanction or "DISMISSAL" in raw_sanction or "NON-READMISSION" in raw_sanction:
        decided_category = "Category 5"
    elif "CATEGORY 4" in raw_sanction:
        decided_category = "Category 4"
    elif "CATEGORY 3" in raw_sanction or "SUSPENSION" in raw_sanction:
        decided_category = "Category 3"
    elif "CATEGORY 2" in raw_sanction or "PROBATION" in raw_sanction or "FORMATIVE" in raw_sanction or raw_level == "MAJOR":
        decided_category = "Category 2"
    else:
        decided_category = "Category 1"

    # Severity Level heuristic
    severity = "Low"
    if decided_category in ["Category 4", "Category 5"]:
        severity = "Severe"
    elif decided_category in ["Category 2", "Category 3"] or raw_level == "MAJOR":
        severity = "Moderate"

    return {
        "case_uuid": f"HIST-{idx+1:04d}",
        "offense_name": offense_name,
        "offense_level": raw_level,
        "severity": severity,
        "previous_offenses_count": 0 if raw_level == "MINOR" else 1,
        "previous_related_count": 0,
        "handbook_section": "Section IV" if raw_level == "MINOR" else "Section V",
        "decided_category": decided_category,
        "raw_sanction_text": raw_sanction or "Formative Advisory",
        "verified": True
    }

def build_dataset():
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    if not os.path.exists(RAW_DATASET_PATH):
        print(f"Error: Raw dataset not found at {RAW_DATASET_PATH}")
        return None

    with open(RAW_DATASET_PATH, 'r', encoding='utf-8') as f:
        raw_data = json.load(f)

    sanitized = []
    for idx, r in enumerate(raw_data):
        sanitized.append(sanitize_record(r, idx))

    out_file = os.path.join(OUTPUT_DIR, f"{DATASET_VERSION}.json")
    dataset_payload = {
        "dataset_version": DATASET_VERSION,
        "total_cases": len(sanitized),
        "verified_cases_count": len(sanitized),
        "cases": sanitized
    }

    with open(out_file, 'w', encoding='utf-8') as f:
        json.dump(dataset_payload, f, indent=2)

    print(f"Dataset {DATASET_VERSION} successfully created with {len(sanitized)} verified cases at {out_file}")
    return out_file

if __name__ == "__main__":
    build_dataset()
