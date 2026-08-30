import sys
import json
import os
import joblib
import numpy as np
import pandas as pd
sys.path.append(r"c:\xampp\htdocs\identitrack")
from ai.model.similarity import HistoricalSimilarityEngine

MODEL_DIR = r"c:\xampp\htdocs\identitrack\ai\storage\models"
DEFAULT_MODEL_VERSION = "UPCC-RF-v1.0"

class UPCCPredictor:
    def __init__(self, model_version=DEFAULT_MODEL_VERSION):
        self.model_version = model_version
        self.model_path = os.path.join(MODEL_DIR, f"{model_version}.pkl")
        self.rf = None
        self.tfidf = None
        self.classes = []
        self.similarity_engine = HistoricalSimilarityEngine()
        self._load_model()

    def _load_model(self):
        if not os.path.exists(self.model_path):
            print(f"Model file not found: {self.model_path}")
            return
        artifact = joblib.load(self.model_path)
        self.rf = artifact['rf_classifier']
        self.tfidf = artifact['tfidf_vectorizer']
        self.classes = artifact['classes']

    def predict_case(self, case_data, min_similarity=0.70, min_cases=3):
        if self.rf is None or self.tfidf is None:
            return {
                "status": "ai_unavailable",
                "recommendation": None,
                "message": "AI model is not loaded."
            }

        offense_name = case_data.get('offense_name', 'GENERAL_VIOLATION')
        offense_level = str(case_data.get('offense_level', 'MINOR')).upper()
        severity = case_data.get('severity', 'Low')
        prev_count = int(case_data.get('previous_offenses_count', 0))

        # 1. Similarity Engine Lookup
        sim_res = self.similarity_engine.find_similar(
            offense_name=offense_name,
            offense_level=offense_level,
            severity=severity,
            top_k=8,
            min_similarity=min_similarity
        )

        # 2. Check Insufficient Evidence Safeguard
        if not sim_res['sufficient_evidence'] or sim_res['similar_cases_count'] < min_cases:
            return {
                "status": "insufficient_evidence",
                "case_id": case_data.get('case_id', 'UNKNOWN'),
                "recommendation": None,
                "confidence": 0.0,
                "similar_cases": sim_res['similar_cases_count'],
                "best_similarity": sim_res['best_similarity'],
                "historical_distribution": sim_res['historical_distribution'],
                "message": "The system could not find enough sufficiently similar verified UPCC cases to provide a reliable historical recommendation.",
                "handbook_compatible": True,
                "handbook_reference": "Section IV" if offense_level == "MINOR" else "Section V",
                "model_version": self.model_version,
                "dataset_version": "UPCC-DATA-v1.0"
            }

        # 3. Random Forest Prediction & Confidence
        text_feature = f"{offense_name} {offense_level} {severity}"
        X_text = self.tfidf.transform([text_feature]).toarray()
        
        level_code = 1 if offense_level == "MAJOR" else 0
        severity_code = 2 if severity == "Severe" else (1 if severity == "Moderate" else 0)
        X_struct = np.array([[level_code, severity_code, prev_count]])
        
        X_vec = np.hstack((X_text, X_struct))

        probs = self.rf.predict_proba(X_vec)[0]
        max_idx = np.argmax(probs)
        rf_rec = self.classes[max_idx]
        confidence = float(probs[max_idx])

        # Align with most common historical outcome if RF and Similarity match
        recommendation = sim_res['most_common_category'] or rf_rec

        # 4. Handbook Rule Validation
        handbook_compatible = True
        handbook_section = "Section IV" if offense_level == "MINOR" else "Section V"
        
        # Handbook Conflict Check: Minor offense cannot jump to Category 4/5 on 1st/2nd attempt
        if offense_level == "MINOR" and prev_count < 2 and recommendation in ["Category 4", "Category 5"]:
            return {
                "status": "handbook_conflict",
                "case_id": case_data.get('case_id', 'UNKNOWN'),
                "recommendation": None,
                "message": "The model-generated recommendation conflicts with the currently applicable Student Handbook Section IV rules.",
                "handbook_compatible": False,
                "handbook_reference": handbook_section,
                "model_version": self.model_version,
                "dataset_version": "UPCC-DATA-v1.0"
            }

        return {
            "status": "success",
            "case_id": case_data.get('case_id', 'UNKNOWN'),
            "recommendation": recommendation,
            "confidence": round(confidence, 2),
            "similar_cases": sim_res['similar_cases_count'],
            "similar_cases_list": sim_res['similar_cases'],
            "best_similarity": sim_res['best_similarity'],
            "historical_distribution": sim_res['historical_distribution'],
            "most_common_historical": sim_res['most_common_category'],
            "handbook_compatible": handbook_compatible,
            "handbook_reference": handbook_section,
            "model_version": self.model_version,
            "dataset_version": "UPCC-DATA-v1.0"
        }

if __name__ == "__main__":
    predictor = UPCCPredictor()
    test_case = {
        "case_id": "UPCC-2026-001",
        "offense_name": "BRINGING IN VAPE",
        "offense_level": "MAJOR",
        "severity": "Moderate",
        "previous_offenses_count": 1
    }
    res = predictor.predict_case(test_case)
    print("Prediction Test Result:")
    print(json.dumps(res, indent=2))
