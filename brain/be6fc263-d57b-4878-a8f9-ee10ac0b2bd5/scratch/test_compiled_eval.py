import json
import joblib
import re
import math
import numpy as np
import scipy.sparse as sp
import xgboost as xgb

with open('admin/AI/softeng_2-master/server/modle/softeng_2_compiled_model.json', 'r', encoding='utf-8') as f:
    model_data = json.load(f)

vocab = model_data['vocab']
idf = model_data['idf']
labels = model_data['labels']
trees = model_data['trees']
num_classes = model_data['num_classes']

def clean_text(text):
    text = str(text).lower()
    text = re.sub(r'[^a-zA-Z0-9\s]', '', text)
    text = re.sub(r'\s+', ' ', text).strip()
    return text

def eval_native(scenario, category, violation, num_offense_str):
    combined = f"{clean_text(scenario)} {clean_text(category)} {clean_text(violation)}"
    words = combined.split()
    
    # 1. Term counts
    term_counts = {}
    for w in words:
        if w in vocab:
            idx = vocab[w]
            term_counts[idx] = term_counts.get(idx, 0) + 1
            
    # 2. Compute TF-IDF
    features = {}
    sum_sq = 0.0
    for idx, count in term_counts.items():
        val = count * idf[idx]
        features[idx] = val
        sum_sq += val * val
        
    norm = math.sqrt(sum_sq) if sum_sq > 0 else 1.0
    if norm > 0:
        for idx in features:
            features[idx] /= norm
            
    # Add numeric feature at index 500
    try:
        nm = re.search(r'\d+', str(num_offense_str))
        num_val = float(nm.group()) if nm else float(num_offense_str)
    except Exception:
        num_val = 1.0
        
    features[500] = num_val
    
    # 3. Evaluate 13,000 decision trees
    logits = [0.0] * num_classes
    
    for i, t in enumerate(trees):
        class_idx = i % num_classes
        curr = t
        while len(curr) > 1:
            feat_idx, split_cond, left, right = curr
            feat_val = features.get(feat_idx, 0.0)
            if feat_val <= split_cond:
                curr = left
            else:
                curr = right
        logits[class_idx] += curr[0]
        
    # 4. Softmax
    max_logit = max(logits)
    exp_scores = [math.exp(l - max_logit) for l in logits]
    sum_exp = sum(exp_scores)
    probs = [e / sum_exp for e in exp_scores]
    
    best_idx = max(range(len(probs)), key=lambda k: probs[k])
    return labels[best_idx], probs[best_idx] * 100.0

# Compare with XGBoost
m = xgb.Booster()
m.load_model('admin/AI/softeng_2-master/server/modle/sanction_xgb_model.json')
le = joblib.load('admin/AI/softeng_2-master/server/modle/label_encoder.pkl')
tfidf = joblib.load('admin/AI/softeng_2-master/server/modle/tfidf_vectorizer.pkl')

def eval_xgb(scenario, category, violation, num_offense_str):
    combined = f"{clean_text(scenario)} {clean_text(category)} {clean_text(violation)}"
    X_text = tfidf.transform([combined])
    try:
        nm = re.search(r'\d+', str(num_offense_str))
        num_val = float(nm.group()) if nm else float(num_offense_str)
    except Exception:
        num_val = 1.0
    X_num = sp.csr_matrix([[num_val]])
    X = sp.hstack([X_text, X_num]).tocsr()
    dmat = xgb.DMatrix(X)
    probs = m.predict(dmat)[0]
    best_idx = np.argmax(probs)
    return le.classes_[best_idx], probs[best_idx] * 100.0

test_cases = [
    ("cheating exam", "Major Offenses", "Cheating during major examination", "1st offense"),
    ("brawl fighting", "Major Offenses", "Physical altercation inside campus", "2nd offense"),
    ("littering trash", "Minor Offenses", "Improper waste disposal", "1st offense"),
    ("possession of deadly weapon knife", "Major Offenses", "Bringing weapons into university grounds", "1st offense")
]

for sc, cat, vio, n in test_cases:
    native_pred, native_conf = eval_native(sc, cat, vio, n)
    xgb_pred, xgb_conf = eval_xgb(sc, cat, vio, n)
    match = (native_pred == xgb_pred)
    print(f"Test: '{sc}' | '{n}'")
    print(f"  Native: {native_pred[:50]}... ({native_conf:.2f}%)")
    print(f"  XGBoost: {xgb_pred[:50]}... ({xgb_conf:.2f}%)")
    print(f"  MATCH: {match}\n")
