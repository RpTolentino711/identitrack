import re
from typing import Dict, Any

HANDBOOK_DATABASE = [
    {
        "section": "Section IV",
        "rule_code": "SEC4-UNIFORM",
        "title": "Uniform & Grooming Non-Compliance",
        "description": "Failure to wear prescribed school uniform, unauthorized hair color/dye, or improper civilian attire on campus.",
        "offense_type": "Grooming / Dress Code",
        "severity": "Minor Offense",
        "intervention": "Category 1 Warning & 10–15 Hours Community Service",
        "keywords": ["uniform", "dress code", "hair dye", "hair color", "attire", "grooming", "civilian"]
    },
    {
        "section": "Section IV",
        "rule_code": "SEC4-ID-TAG",
        "title": "ID Card Non-Wearing / Lending",
        "description": "Failure to wear valid student ID inside campus premises or lending ID card to another individual.",
        "offense_type": "Identification Violation",
        "severity": "Minor Offense",
        "intervention": "Category 1 Reprimand & 10 Hours Community Service",
        "keywords": ["id", "card", "lending", "wearing id", "no id", "identification"]
    },
    {
        "section": "Section V",
        "rule_code": "SEC5-CHEATING",
        "title": "Academic Dishonesty / Examination Cheating",
        "description": "Using unauthorized materials, mobile devices, cheat sheets, or communicating with others during examinations or major quizzes.",
        "offense_type": "Academic Integrity",
        "severity": "Major Offense",
        "intervention": "Category 2 Sanction (Disciplinary Probation & 25–40 Hours Community Service)",
        "keywords": ["cheat", "cheating", "phone exam", "exam", "quiz", "dishonesty", "leak", "crib sheet"]
    },
    {
        "section": "Section V",
        "rule_code": "SEC5-ALCOHOL-DRUGS",
        "title": "Possession / Consumption of Drugs or Alcohol",
        "description": "Bringing, consuming, or distributing illegal drugs, alcoholic beverages, e-cigarettes, or tobacco inside university premises.",
        "offense_type": "Substance & Campus Safety",
        "severity": "Major Offense",
        "intervention": "Category 3 Sanction (Disciplinary Probation, 30–50 Hours Community Service, or Suspension)",
        "keywords": ["alcohol", "liquor", "drugs", "vape", "vaping", "smoke", "smoking", "substance", "marijuana", "beer"]
    },
    {
        "section": "Section V",
        "rule_code": "SEC5-PERJURY",
        "title": "Falsification / Lying During Administrative Investigation",
        "description": "Submitting false evidence, forged documents, or making deliberately deceptive statements during a UPCC hearing.",
        "offense_type": "Administrative Deceit",
        "severity": "Major Offense",
        "intervention": "Category 2 Sanction (Probation & Parental Notification)",
        "keywords": ["lie", "lying", "false statement", "forgery", "fake", "deceit", "untruth", "perjury"]
    }
]

class HandbookService:
    def match_rule(self, description: str) -> Dict[str, Any]:
        desc_lower = description.lower()
        best_match = None
        highest_score = 0
        
        for rule in HANDBOOK_DATABASE:
            score = 0
            for kw in rule["keywords"]:
                if kw in desc_lower:
                    score += 2
            if rule["title"].lower() in desc_lower:
                score += 5
            
            if score > highest_score:
                highest_score = score
                best_match = rule
                
        if best_match and highest_score >= 2:
            confidence = min(0.96, 0.70 + (highest_score * 0.05))
            return {
                "matched": True,
                "rule": best_match,
                "confidence": round(confidence, 2),
                "uncertainty": False
            }
            
        # Check general major offense keywords
        major_pattern = r'\b(cheat|drug|alcohol|vape|weapon|knife|steal|fight|assault|forge|perjury)\b'
        is_major = bool(re.search(major_pattern, desc_lower))
        
        return {
            "matched": False,
            "rule": {
                "section": "Section V (Major)" if is_major else "Section IV (Minor)",
                "rule_code": "SEC-GENERAL",
                "title": "General Disciplinary Regulations",
                "description": "General Student Handbook Code of Conduct Violation",
                "offense_type": "Academic Integrity / Safety" if is_major else "General Student Conduct",
                "severity": "Major Offense" if is_major else "Section 4 Minor Offense",
                "intervention": "Category 2 Sanction (Probation & CS)" if is_major else "Category 1 Warning (10–15h CS)"
            },
            "confidence": 0.65 if is_major else 0.60,
            "uncertainty": True
        }

handbook_service = HandbookService()
