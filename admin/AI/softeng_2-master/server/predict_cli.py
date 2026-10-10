import sys
import os
import json
import re
import warnings

warnings.filterwarnings("ignore")

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

def get_model_file_path(filename):
    for folder in ["modle", "models", ""]:
        candidate = os.path.join(BASE_DIR, folder, filename) if folder else os.path.join(BASE_DIR, filename)
        if os.path.exists(candidate):
            return candidate
    return None

def clean_text(text):
    text = str(text).lower()
    text = re.sub(r'[^a-zA-Z0-9\s]', '', text)
    text = re.sub(r'\s+', ' ', text).strip()
    return text

def determine_severity_from_likelihood(likelihood):
    if likelihood >= 75:
        return "Critical"
    elif likelihood >= 50:
        return "High"
    elif likelihood >= 25:
        return "Medium"
    else:
        return "Low"

def main():
    try:
        if len(sys.argv) > 1:
            raw_input = sys.argv[1]
            if os.path.isfile(raw_input):
                with open(raw_input, "r", encoding="utf-8") as f:
                    data = json.load(f)
            else:
                data = json.loads(raw_input)
        else:
            raw_input = sys.stdin.read()
            if not raw_input or not raw_input.strip():
                print(json.dumps({"error": "No input payload provided"}))
                sys.exit(1)
            data = json.loads(raw_input)
        scenario = data.get("description") or ""
        category = data.get("category") or ""
        violation = data.get("violation") or ""
        num_offense_str = data.get("number_of_offense") or "1st offense"

        if not scenario:
            scenario = violation or "Disciplinary Violation"

        import joblib
        from scipy.sparse import hstack, csr_matrix
        import numpy as np
        import xgboost as xgb

        xgb_model_file = get_model_file_path("sanction_xgb_model.json")
        label_enc_file = get_model_file_path("label_encoder.pkl")
        tfidf_file = get_model_file_path("tfidf_vectorizer.pkl")

        if not xgb_model_file or not label_enc_file or not tfidf_file:
            print(json.dumps({"error": "Model files missing on server"}))
            sys.exit(1)

        model = xgb.Booster()
        model.load_model(xgb_model_file)
        label_encoder = joblib.load(label_enc_file)
        tfidf_vectorizer = joblib.load(tfidf_file)

        # Parse number of offense
        s_lower = str(num_offense_str).lower()
        if "cycle 1" in s_lower or "1st" in s_lower or "first" in s_lower:
            num_offense = 1.0
        elif "cycle 2" in s_lower or "2nd" in s_lower or "second" in s_lower:
            num_offense = 2.0
        elif "cycle 3" in s_lower or "3rd" in s_lower or "third" in s_lower:
            num_offense = 3.0
        else:
            try:
                num_extracted = re.search(r'(\d+)\s*(?:st|nd|rd|th)?\s*offense', s_lower)
                if not num_extracted:
                    num_extracted = re.search(r'cycle\s*(\d+)', s_lower)
                if not num_extracted:
                    num_extracted = re.search(r'\b(\d+)\b', s_lower)
                num_offense = float(num_extracted.group(1)) if num_extracted else 1.0
            except Exception:
                num_offense = 1.0

        scenario_clean = clean_text(scenario)
        category_clean = clean_text(category)
        violation_clean = clean_text(violation)

        combined_text = f"{scenario_clean} {category_clean} {violation_clean}"

        X_text = tfidf_vectorizer.transform([combined_text])
        X_numeric = csr_matrix([[num_offense]])
        X = hstack([X_text, X_numeric]).tocsr()

        dmat = xgb.DMatrix(X)
        pred_probs = model.predict(dmat)

        if pred_probs.ndim > 1:
            pred_label = np.argmax(pred_probs, axis=1)
            confidence = float(np.max(pred_probs, axis=1)[0])
        else:
            pred_label = [int(pred_probs[0] > 0.5)]
            confidence = float(pred_probs[0] if pred_label[0] == 1 else 1 - pred_probs[0])
            
        predicted_sanction = label_encoder.inverse_transform(pred_label)[0]

        likelihood_percentage = round(confidence * 100, 2)
        severity = determine_severity_from_likelihood(likelihood_percentage)

        cat_match = re.search(r'Category\s*(\d)', str(predicted_sanction), re.IGNORECASE)
        category_num = int(cat_match.group(1)) if cat_match else (1 if 'violation slip' in str(predicted_sanction).lower() else 2)

        res = {
            "category": category if category else "Uncategorized",
            "sanction": predicted_sanction,
            "category_num": category_num,
            "category_label": f"Category {category_num}",
            "sanction_confidence": likelihood_percentage,
            "severity": severity,
            "likelihood_percentage": likelihood_percentage,
            "confidence_score": likelihood_percentage,
            "model_status": "active"
        }
        print(json.dumps(res))

    except Exception as e:
        print(json.dumps({"error": f"CLI Prediction error: {str(e)}"}))
        sys.exit(1)

if __name__ == "__main__":
    main()
