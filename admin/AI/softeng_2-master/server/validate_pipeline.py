"""Validate the reconstructed inference pipeline against the trained models.

Reproduces the training-time test evaluation (STUDENT DISCIPLINE SMOTE copy.ipynb)
using only artifacts shipped in modle/ + the reconstructed serving code in
server.py. If preprocessing/feature engineering matches training, test
accuracy should be in the ~0.7+ range; a mismatched pipeline collapses to near
random (1/7 and 1/6).

Usage: python validate_pipeline.py [path/to/dataset.csv]
"""
import os
import sys
import string
import re
import warnings

import joblib
import nltk
import numpy as np
import pandas as pd
from nltk.corpus import stopwords
from nltk.stem import WordNetLemmatizer
from scipy.sparse import hstack
from sklearn.model_selection import train_test_split
from sklearn.metrics import accuracy_score
from sklearn.preprocessing import LabelEncoder

warnings.filterwarnings("ignore")

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
MODEL_DIR = os.path.join(BASE_DIR, "modle")
# Use ONLY the project-local corpus dir; the Store-Python's redirected
# AppData path trips nltk's pathsec validation otherwise.
nltk.data.path[:] = [os.path.join(BASE_DIR, "nltk_data")]

RANDOM_STATE = 42


class TextPreprocessor:
    def __init__(self):
        self.lemmatizer = WordNetLemmatizer()
        self.stop_words = set(stopwords.words("english"))

    def clean_text(self, text):
        if pd.isna(text):
            return ""

        text = str(text).lower()
        text = text.replace("-", " ")
        text = text.translate(str.maketrans("", "", string.punctuation))
        text = re.sub(r"\d+", "", text)
        text = re.sub(r"\s+", " ", text).strip()

        tokens = nltk.word_tokenize(text)

        cleaned_tokens = []
        for token in tokens:
            lemma = self.lemmatizer.lemmatize(token, pos="n")
            lemma = self.lemmatizer.lemmatize(lemma, pos="v")
            if lemma not in self.stop_words and len(lemma) > 2:
                cleaned_tokens.append(lemma)

        return " ".join(cleaned_tokens)


def convert_offense_to_numeric(offense):
    if pd.isna(offense):
        return 1
    offense_str = str(offense).lower()
    if "1st" in offense_str or "first" in offense_str:
        return 1
    elif "2nd" in offense_str or "second" in offense_str:
        return 2
    elif "3rd" in offense_str or "third" in offense_str:
        return 3
    else:
        try:
            return int(re.findall(r"\d+", offense_str)[0])
        except (IndexError, ValueError):
            return 1


def build_numeric_features(scenarios, violations, offenses):
    scenarios = pd.Series(scenarios).fillna("").astype(str)
    violations = pd.Series(violations).fillna("").astype(str)
    offense_num = pd.Series(offenses).map(convert_offense_to_numeric)

    s_lower = scenarios.str.lower()
    v_lower = violations.str.lower()

    def contains(series, pattern):
        return series.str.contains(pattern, regex=True, na=False).astype(int).to_numpy()

    return np.column_stack([
        offense_num.to_numpy(dtype=float),
        scenarios.str.len().to_numpy(dtype=float),
        violations.str.len().to_numpy(dtype=float),
        scenarios.str.split().str.len().to_numpy(dtype=float),
        violations.str.split().str.len().to_numpy(dtype=float),
        contains(v_lower, "major|serious|severe"),
        contains(v_lower, "minor|light|small"),
        contains(v_lower, "academic|cheat|plagiarism|exam"),
        contains(v_lower, "conduct|behavior|discipline"),
    ]).astype(np.float64)


def group_sanctions(sanction):
    sanction_str = str(sanction).lower()
    if "category 1" in sanction_str:
        return "Category 1: Probation & Counseling"
    elif "category 2" in sanction_str:
        return "Category 2: Formative Intervention"
    elif "category 3" in sanction_str:
        return "Category 3: Non-Readmission"
    elif "category 4" in sanction_str:
        return "Category 4: Exclusion"
    elif "violation slip" in sanction_str:
        return "Violation Slip & Warning"
    elif "major offense" in sanction_str:
        return "Major Offense Charge"
    else:
        return "Other Sanctions"


def main():
    preprocessor = TextPreprocessor()
    tfidf_vectorizer = joblib.load(os.path.join(MODEL_DIR, "tfidf_vectorizer.pkl"))
    category_model = joblib.load(os.path.join(MODEL_DIR, "category_model.pkl"))
    sanction_model = joblib.load(os.path.join(MODEL_DIR, "sanction_model.pkl"))
    category_encoder = joblib.load(os.path.join(MODEL_DIR, "category_encoder.pkl"))
    sanction_encoder = joblib.load(os.path.join(MODEL_DIR, "sanction_encoder.pkl"))

    df = pd.read_csv(sys.argv[1] if len(sys.argv) > 1 else os.path.join(
        os.path.dirname(MODEL_DIR), "..", "..",
        "XGBoostTraining_V4", "Student Discipline Office Violations Dataset.csv"))
    print(f"Dataset: {len(df)} rows")

    df["Scenario_cleaned"] = df["Scenario"].apply(preprocessor.clean_text)
    df["Violation_cleaned"] = df["Violation"].apply(preprocessor.clean_text)
    df["combined_text"] = df["Scenario_cleaned"] + " " + df["Violation_cleaned"]

    # Label encoding must match training: fit on the trained encoders' classes
    cat_le = LabelEncoder().fit(category_encoder.classes_)
    y_cat = cat_le.transform(df["Category"])
    san_le = LabelEncoder().fit(sanction_encoder.classes_)
    y_san = san_le.transform(df["Sanction"].apply(group_sanctions))

    numeric = build_numeric_features(df["Scenario"], df["Violation"], df["Number of Offense"])
    tfidf_feats = tfidf_vectorizer.transform(df["combined_text"])
    X = hstack([tfidf_feats, numeric]).tocsr()
    print(f"Feature matrix: {X.shape}")

    # Same split as training: stratified by category target
    train_idx, test_idx = train_test_split(
        np.arange(len(df)), test_size=0.2, random_state=RANDOM_STATE, stratify=y_cat
    )
    X_test = X[test_idx]

    cat_pred = category_model.predict(X_test)
    san_pred = sanction_model.predict(X_test)

    cat_acc = accuracy_score(y_cat[test_idx], cat_pred)
    san_acc = accuracy_score(y_san[test_idx], san_pred)
    print(f"Category test accuracy: {cat_acc:.4f}  (random baseline ~0.14)")
    print(f"Sanction test accuracy: {san_acc:.4f}  (random baseline ~0.17)")

    if cat_acc > 0.5 and san_acc > 0.5:
        print("VALIDATION PASSED: reconstructed inference pipeline matches training.")
    else:
        print("VALIDATION FAILED: pipeline mismatch.")
        sys.exit(1)

    # Also exercise the single-'description' path the web app uses
    d = "A student was caught cheating during the final examination"
    combined = preprocessor.clean_text(d) + " " + preprocessor.clean_text(d)
    feats = hstack([tfidf_vectorizer.transform([combined]),
                    build_numeric_features([d], [d], ["1st offense"])]).tocsr()
    print("\nSingle-description smoke test:", d)
    print("  -> category:", category_encoder.inverse_transform(category_model.predict(feats))[0])
    print("  -> sanction:", sanction_encoder.inverse_transform(sanction_model.predict(feats))[0])


if __name__ == "__main__":
    main()
