import os
import sys
import json
import re
import pandas as pd
import numpy as np
import joblib
from sklearn.preprocessing import LabelEncoder
from sklearn.feature_extraction.text import TfidfVectorizer
from scipy.sparse import hstack, csr_matrix
import xgboost as xgb

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
MODLE_DIR = os.path.join(BASE_DIR, "modle")
CSV_PATH = os.path.join(MODLE_DIR, "Student-Discipline-Office-Violations-Dataset.csv")

def clean_text(text):
    text = str(text).lower()
    text = re.sub(r'[^a-zA-Z0-9\s]', '', text)
    text = re.sub(r'\s+', ' ', text).strip()
    return text

def main():
    print(f"1. Loading dataset from {CSV_PATH}...")
    df = pd.read_csv(CSV_PATH)
    print(f"Initial shape: {df.shape}")

    # Standardize 'The student is charged with a Major Offense under Section VI.E.19.' to official Category 2 Sanction
    sec19_mask = df['Sanction'].str.contains('Section VI.E.19', case=False, na=False) | df['Sanction'].str.contains('charged with a Major Offense', case=False, na=False)
    print(f"Found {sec19_mask.sum()} Section VI.E.19 rows to update to Category 2 (Formative Intervention)...")
    
    cat2_label = "Category 2 (Formative Intervention: University Service, Counseling, Discipline Education Program, & Evaluation)"
    cat3_label = "Category 3 (Non-Readmission, denial of admission but is allowed to finish current term)"
    
    df.loc[sec19_mask, 'Sanction'] = cat2_label

    # Generate additional training examples for Cycle 2 / 2nd Escalation of Minor Offenses so the model learns Category 3 natively on 2nd offense
    minor_3rd = df[sec19_mask].copy()
    if len(minor_3rd) > 0:
        minor_3rd['Number of Offense'] = '2nd Offense'
        minor_3rd['Sanction'] = cat3_label
        # Also add Section 4 Minor Escalation category representation
        minor_sec4 = minor_3rd.copy()
        minor_sec4['Category'] = 'Section 4 Minor Escalation'
        minor_sec4_c1 = df[sec19_mask].copy()
        minor_sec4_c1['Category'] = 'Section 4 Minor Escalation'
        df = pd.concat([df, minor_3rd, minor_sec4, minor_sec4_c1], ignore_index=True)
        print(f"Augmented dataset with 2nd escalation precedents. New shape: {df.shape}")

    # Save updated CSV
    df.to_csv(CSV_PATH, index=False)
    print(f"Saved cleaned and standardized dataset to {CSV_PATH}")

    # Prepare features
    scenarios = df['Scenario'].fillna('').astype(str).apply(clean_text)
    categories = df['Category'].fillna('').astype(str).apply(clean_text)
    violations = df['Violation'].fillna('').astype(str).apply(clean_text)
    combined_texts = scenarios + " " + categories + " " + violations

    def extract_num(val):
        m = re.search(r'\d+', str(val))
        return float(m.group()) if m else 1.0

    num_offenses = df['Number of Offense'].apply(extract_num).values

    # Train TF-IDF Vectorizer
    print("2. Fitting TF-IDF Vectorizer (ngram_range=(1,2))...")
    tfidf = TfidfVectorizer(max_features=500, ngram_range=(1, 2))
    X_text = tfidf.fit_transform(combined_texts)
    X_num = csr_matrix(num_offenses.reshape(-1, 1))
    X = hstack([X_text, X_num]).tocsr()

    # Encode labels
    print("3. Fitting LabelEncoder...")
    le = LabelEncoder()
    y = le.fit_transform(df['Sanction'])
    num_classes = len(le.classes_)
    print(f"Classes ({num_classes}): {list(le.classes_)}")

    # Train XGBoost model
    print("4. Training XGBoost Classifier...")
    params = {
        'objective': 'multi:softprob',
        'num_class': num_classes,
        'learning_rate': 0.1,
        'max_depth': 6,
        'min_child_weight': 1,
        'eval_metric': 'mlogloss',
        'tree_method': 'hist',
        'seed': 42
    }
    dtrain = xgb.DMatrix(X, label=y)
    bst = xgb.train(params, dtrain, num_boost_round=100)

    # Save Python model artifacts
    xgb_path = os.path.join(MODLE_DIR, "sanction_xgb_model.json")
    le_path = os.path.join(MODLE_DIR, "label_encoder.pkl")
    tfidf_path = os.path.join(MODLE_DIR, "tfidf_vectorizer.pkl")

    bst.save_model(xgb_path)
    joblib.dump(le, le_path)
    joblib.dump(tfidf, tfidf_path)
    print(f"Saved model to {xgb_path}, encoder to {le_path}, tfidf to {tfidf_path}")

    # Compile model for Native PHP XGBoost Runner
    print("5. Compiling XGBoost booster into JSON for Native PHP high-speed inference...")
    dump = bst.get_dump(dump_format='json')
    trees = []
    for tree_idx, tree_json in enumerate(dump):
        t = json.loads(tree_json)
        class_idx = tree_idx % num_classes
        lefts = []
        rights = []
        splits = []
        conds = []
        weights = []
        defaults = []

        def traverse(node):
            n_id = len(lefts)
            lefts.append(-1)
            rights.append(-1)
            splits.append(0)
            conds.append(0.0)
            weights.append(0.0)
            defaults.append(0)

            if "leaf" in node:
                weights[n_id] = float(node["leaf"])
            else:
                f_name = node["split"]
                f_idx = int(re.sub(r'[^0-9]', '', f_name)) if re.sub(r'[^0-9]', '', f_name) else 0
                splits[n_id] = f_idx
                conds[n_id] = float(node["split_condition"])
                d_id = node.get("default_left", 0)
                defaults[n_id] = 1 if d_id == node.get("yes") else 0

                left_sub = traverse(node["children"][0])
                right_sub = traverse(node["children"][1])
                lefts[n_id] = left_sub
                rights[n_id] = right_sub
            return n_id

        traverse(t)
        trees.append({
            "class": class_idx,
            "lefts": lefts,
            "rights": rights,
            "splits": splits,
            "conds": conds,
            "weights": weights,
            "defaults": defaults
        })

    compiled_obj = {
        "num_classes": int(num_classes),
        "labels": [str(c) for c in le.classes_],
        "vocab": {str(k): int(v) for k, v in tfidf.vocabulary_.items()},
        "idf": [float(x) for x in tfidf.idf_],
        "trees": trees
    }

    compiled_path = os.path.join(MODLE_DIR, "softeng_2_compiled_model.json")
    with open(compiled_path, "w", encoding="utf-8") as f:
        json.dump(compiled_obj, f)
    print(f"Compiled model saved to {compiled_path}")

    print("\nModel training and compilation complete!")

if __name__ == "__main__":
    main()
