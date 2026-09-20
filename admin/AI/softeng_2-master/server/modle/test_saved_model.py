import pandas as pd
import numpy as np
import re
import joblib
from scipy.sparse import hstack, csr_matrix
import xgboost as xgb
import os
import sys

def clean_text(text):
    text = str(text).lower()
    text = re.sub(r'[^a-zA-Z0-9\s]', '', text)
    text = re.sub(r'\s+', ' ', text).strip()
    return text

def main():
    print("Loading vectorizer and label encoder...")
    
    try:
        tfidf = joblib.load('tfidf_vectorizer.pkl')
        le = joblib.load('label_encoder.pkl')
    except Exception as e:
        print(f"Error loading pkl files: {e}")
        sys.exit(1)

    print("Loading XGBoost model...")
    model = xgb.Booster()
    try:
        model.load_model('sanction_xgb_model.json')
    except Exception as e:
        print(f"Error loading model: {e}")
        sys.exit(1)

    print("\n" + "="*50)
    print("Model and preprocessors loaded successfully!")
    print("="*50 + "\n")
    
    while True:
        print("-" * 50)
        scenario = input("Enter Scenario (or 'quit' to exit): ").strip()
        if scenario.lower() == 'quit':
            print("Exiting...")
            break
            
        category = input("Enter Category: ").strip()
        violation = input("Enter Violation: ").strip()
        
        while True:
            try:
                num_offense_str = input("Enter Number of Offense (numeric): ").strip()
                # Handle cases like "1st", "2nd" if user types them by extracting numbers
                num_extracted = re.search(r'\d+', num_offense_str)
                if num_extracted:
                    num_offense = float(num_extracted.group())
                else:
                    num_offense = float(num_offense_str)
                break
            except ValueError:
                print("Invalid input. Please enter a valid numeric value.")

        # Text cleaning and combining
        scenario_clean = clean_text(scenario)
        category_clean = clean_text(category)
        violation_clean = clean_text(violation)
        
        combined_text = f"{scenario_clean} {category_clean} {violation_clean}"
        
        # Transform features
        X_text = tfidf.transform([combined_text])
        X_numeric = csr_matrix([[num_offense]])
        
        X = hstack([X_text, X_numeric]).tocsr()
        
        # Predict
        dmat = xgb.DMatrix(X)
        pred_probs = model.predict(dmat)
        
        if pred_probs.ndim > 1:
            pred_label = np.argmax(pred_probs, axis=1)
            confidence = np.max(pred_probs, axis=1)[0]
        else:
            pred_label = [int(pred_probs[0] > 0.5)]
            confidence = pred_probs[0] if pred_label[0] == 1 else 1 - pred_probs[0]
            
        predicted_sanction = le.inverse_transform(pred_label)[0]
        
        print("\n" + "="*50)
        print(f"📝 COMBINED TEXT (cleaned): {combined_text}")
        print(f"⚠️ PREDICTED SANCTION: {predicted_sanction}")
        print(f"📊 CONFIDENCE SCORE: {confidence:.2%}")
        print("="*50 + "\n")
        
if __name__ == "__main__":
    main()
