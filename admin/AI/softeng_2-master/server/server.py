from flask import Flask, request, jsonify
import joblib
from flask_cors import CORS
import numpy as np
import pandas as pd
from scipy.sparse import hstack, csr_matrix
import xgboost as xgb
import os
import re
import warnings

warnings.filterwarnings("ignore")

app = Flask(__name__)
CORS(app)  # Enable CORS for all routes

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

# Load the trained models and preprocessing objects
try:
    xgb_model_file = get_model_file_path("sanction_xgb_model.json")
    label_enc_file = get_model_file_path("label_encoder.pkl")
    tfidf_file = get_model_file_path("tfidf_vectorizer.pkl")

    if xgb_model_file:
        model = xgb.Booster()
        model.load_model(xgb_model_file)
    else:
        model = None

    label_encoder = joblib.load(label_enc_file) if label_enc_file else None
    tfidf_vectorizer = joblib.load(tfidf_file) if tfidf_file else None

    if all(obj is not None for obj in (model, label_encoder, tfidf_vectorizer)):
        print("Model and encoders loaded successfully!")
    else:
        print("Notice: ML model or encoders not fully present. /predict will return errors until the model files are restored.")
except Exception as e:
    print(f"Warning: Model files could not be loaded. Error: {e}")
    model = None
    label_encoder = None
    tfidf_vectorizer = None

def determine_severity_from_likelihood(likelihood):
    if likelihood >= 75:
        return "Critical"
    elif likelihood >= 50:
        return "High"
    elif likelihood >= 25:
        return "Medium"
    else:
        return "Low"

@app.route("/predict", methods=["POST"])
def predict():
    try:
        data = request.json or {}
        scenario = data.get("description") or ""
        category = data.get("category") or ""
        violation = data.get("violation") or ""
        num_offense_str = data.get("number_of_offense") or "1st offense"

        if not scenario:
            return jsonify({"error": "No description provided"}), 400

        if any(obj is None for obj in (model, label_encoder, tfidf_vectorizer)):
            return jsonify({"error": "ML model not available on server"}), 503

        # Parse number of offense
        try:
            num_extracted = re.search(r'\d+', str(num_offense_str))
            if num_extracted:
                num_offense = float(num_extracted.group())
            else:
                num_offense = float(num_offense_str)
        except ValueError:
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

        return jsonify({
            "category": category if category else "Uncategorized",
            "sanction": predicted_sanction,
            "sanction_confidence": likelihood_percentage,
            "severity": severity,
            "likelihood_percentage": likelihood_percentage,
            "confidence_score": likelihood_percentage,
            "model_status": "active"
        })

    except Exception as e:
        print(f"Prediction error: {str(e)}")
        return jsonify({"error": "Prediction failed on server"}), 500

@app.route("/health", methods=["GET"])
def health():
    return jsonify({
        "status": "online",
        "model_loaded": all(obj is not None for obj in (model, label_encoder, tfidf_vectorizer))
    })

if __name__ == "__main__":
    print("Starting ML Prediction Server on port 5000...")
    app.run(host="0.0.0.0", port=5000, debug=True)
