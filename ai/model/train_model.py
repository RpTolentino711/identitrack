import json
import os
import joblib
import numpy as np
import pandas as pd
from datetime import datetime
from sklearn.ensemble import RandomForestClassifier
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics import accuracy_score, precision_score, recall_score, f1_score, confusion_matrix
from sklearn.model_selection import train_test_split

MODEL_VERSION = "UPCC-RF-v1.0"
DATASET_VERSION = "UPCC-DATA-v1.0"
DATASET_PATH = rf"c:\xampp\htdocs\identitrack\ai\storage\datasets\{DATASET_VERSION}.json"
MODEL_DIR = r"c:\xampp\htdocs\identitrack\ai\storage\models"

def train():
    os.makedirs(MODEL_DIR, exist_ok=True)
    if not os.path.exists(DATASET_PATH):
        print(f"Error: Dataset not found at {DATASET_PATH}")
        return None

    with open(DATASET_PATH, 'r', encoding='utf-8') as f:
        ds = json.load(f)

    cases = ds['cases']
    df = pd.DataFrame(cases)

    # Feature Engineering
    df['text_feature'] = df['offense_name'] + " " + df['offense_level'] + " " + df['severity']
    
    tfidf = TfidfVectorizer(max_features=250, stop_words='english')
    X_text = tfidf.fit_transform(df['text_feature']).toarray()

    # Categorical / Structured features
    level_map = {'MINOR': 0, 'MAJOR': 1}
    severity_map = {'Low': 0, 'Moderate': 1, 'Severe': 2}
    
    df['level_code'] = df['offense_level'].map(lambda x: level_map.get(str(x).upper(), 0))
    df['severity_code'] = df['severity'].map(lambda x: severity_map.get(str(x).title(), 0))
    df['prev_count'] = df['previous_offenses_count'].fillna(0).astype(int)

    X_struct = df[['level_code', 'severity_code', 'prev_count']].values
    X = np.hstack((X_text, X_struct))
    y = df['decided_category'].values

    # Train / Test split without forced stratify to support rare classes
    X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42)

    rf = RandomForestClassifier(n_estimators=120, max_depth=12, random_state=42, class_weight='balanced')
    rf.fit(X_train, y_train)

    y_pred = rf.predict(X_test)

    acc = float(accuracy_score(y_test, y_pred))
    prec = float(precision_score(y_test, y_pred, average='macro', zero_division=0))
    rec = float(recall_score(y_test, y_pred, average='macro', zero_division=0))
    f1 = float(f1_score(y_test, y_pred, average='macro', zero_division=0))
    cm = confusion_matrix(y_test, y_pred).tolist()

    classes = rf.classes_.tolist()

    model_artifact = {
        'model_version': MODEL_VERSION,
        'dataset_version': DATASET_VERSION,
        'rf_classifier': rf,
        'tfidf_vectorizer': tfidf,
        'classes': classes,
        'trained_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
        'training_cases_count': len(df)
    }

    model_path = os.path.join(MODEL_DIR, f"{MODEL_VERSION}.pkl")
    joblib.dump(model_artifact, model_path)

    metrics = {
        'model_version': MODEL_VERSION,
        'dataset_version': DATASET_VERSION,
        'accuracy': round(acc, 4),
        'precision': round(prec, 4),
        'recall': round(rec, 4),
        'macro_f1': round(f1, 4),
        'confusion_matrix': cm,
        'classes': classes,
        'training_cases_count': len(df),
        'trained_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    }

    metrics_path = os.path.join(MODEL_DIR, f"{MODEL_VERSION}_metrics.json")
    with open(metrics_path, 'w', encoding='utf-8') as f:
        json.dump(metrics, f, indent=2)

    print(f"Model {MODEL_VERSION} trained successfully!")
    print(f"Metrics: Accuracy={acc:.4f}, F1={f1:.4f}, Training Cases={len(df)}")
    return metrics

if __name__ == "__main__":
    train()
