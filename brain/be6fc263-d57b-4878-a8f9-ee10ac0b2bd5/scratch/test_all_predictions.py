import json
import os
import re
import joblib
from scipy.sparse import hstack, csr_matrix
import numpy as np
import xgboost as xgb

BASE_DIR = r"c:\xampp\htdocs\identitrack\admin\AI\softeng_2-master\server"
modle_dir = os.path.join(BASE_DIR, "modle")

xgb_file = os.path.join(modle_dir, "sanction_xgb_model.json")
label_file = os.path.join(modle_dir, "label_encoder.pkl")
tfidf_file = os.path.join(modle_dir, "tfidf_vectorizer.pkl")

model = xgb.Booster()
model.load_model(xgb_file)
label_encoder = joblib.load(label_file)
tfidf_vectorizer = joblib.load(tfidf_file)

def clean_text(text):
    text = str(text).lower()
    text = re.sub(r'[^a-zA-Z0-9\s]', '', text)
    text = re.sub(r'\s+', ' ', text).strip()
    return text

def test_predict(scenario, category, violation, num_offense_str):
    try:
        num_extracted = re.search(r'\d+', str(num_offense_str))
        num_offense = float(num_extracted.group()) if num_extracted else 1.0
    except:
        num_offense = 1.0

    combined_text = f"{clean_text(scenario)} {clean_text(category)} {clean_text(violation)}"
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
    return predicted_sanction, round(confidence * 100, 2)

scenarios = [
    ("Caught cheating by prof", "Automatic Major Offenses", "Cheating or academic dishonesty", "2nd Offense"),
    ("First time cheating", "Automatic Major Offenses", "Cheating or academic dishonesty", "1st Offense"),
    ("Littering in hallway", "Minor Offenses", "Littering", "1st Offense"),
    ("Accumulated 3 minor offenses", "Section 4 Minor Escalation", "Littering, No ID, Improper Attire", "Cycle 1 (3 Minors)"),
    ("Accumulated 6 minor offenses", "Section 4 Minor Escalation", "Littering, No ID, Improper Attire", "Cycle 2 (6 Minors)"),
    ("Brawl in hallway", "Automatic Major Offenses", "Brawl within the University premises", "1st Offense"),
    ("Brought deadly knife", "Automatic Major Offenses", "Bringing in, carrying, possession or use of deadly weapons", "1st Offense"),
    ("Bomb joke in campus", "Criminal offense and a critical emergency security incident", "Bomb jokes", "1st Offense"),
]

for s in scenarios:
    sanct, conf = test_predict(*s)
    print(f"INPUT: {s}")
    print(f"OUTPUT -> Sanction: {sanct} | Confidence: {conf}%\n")
