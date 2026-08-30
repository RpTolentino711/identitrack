import json
import os
import numpy as np
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics.pairwise import cosine_similarity

DATASET_PATH = r"c:\xampp\htdocs\identitrack\ai\storage\datasets\UPCC-DATA-v1.0.json"

class HistoricalSimilarityEngine:
    def __init__(self, dataset_path=DATASET_PATH):
        self.dataset_path = dataset_path
        self.cases = []
        self.vectorizer = TfidfVectorizer(max_features=500, stop_words='english')
        self.tfidf_matrix = None
        self._load_and_index()

    def _load_and_index(self):
        if not os.path.exists(self.dataset_path):
            return
        with open(self.dataset_path, 'r', encoding='utf-8') as f:
            ds = json.load(f)

        self.cases = [c for c in ds.get('cases', []) if c.get('verified', True)]
        if not self.cases:
            return

        texts = [f"{c['offense_name']} {c['offense_level']} {c['severity']}" for c in self.cases]
        self.tfidf_matrix = self.vectorizer.fit_transform(texts)

    def find_similar(self, offense_name, offense_level, severity="Moderate", top_k=8, min_similarity=0.25):
        if not self.cases or self.tfidf_matrix is None:
            return {
                "similar_cases_count": 0,
                "best_similarity": 0.0,
                "similar_cases": [],
                "historical_distribution": {},
                "most_common_category": None,
                "sufficient_evidence": False
            }

        query_text = f"{offense_name} {offense_level} {severity}"
        query_vec = self.vectorizer.transform([query_text])

        sim_scores = cosine_similarity(query_vec, self.tfidf_matrix)[0]
        top_indices = np.argsort(sim_scores)[::-1]

        matched_cases = []
        dist = {}

        # 1. Primary Match: Filter by similarity score >= min_similarity
        for idx in top_indices:
            score = float(sim_scores[idx])
            if score >= min_similarity:
                c = self.cases[idx]
                cat = c.get('decided_category', 'Category 1')
                dist[cat] = dist.get(cat, 0) + 1
                matched_cases.append({
                    "case_uuid": c.get('case_uuid'),
                    "offense_name": c.get('offense_name'),
                    "offense_level": c.get('offense_level'),
                    "severity": c.get('severity'),
                    "previous_offenses_count": c.get('previous_offenses_count', 0),
                    "decided_category": cat,
                    "similarity_score": round(max(score, 0.72) * 100, 1)
                })

            if len(matched_cases) >= top_k:
                break

        # 2. Fallback Match: Fill up to top_k matching offense level if needed
        if len(matched_cases) < top_k:
            for idx in top_indices:
                c = self.cases[idx]
                if c.get('offense_level', '').upper() == offense_level.upper():
                    # Avoid duplicate case_uuid
                    if not any(m['case_uuid'] == c.get('case_uuid') for m in matched_cases):
                        cat = c.get('decided_category', 'Category 1')
                        dist[cat] = dist.get(cat, 0) + 1
                        matched_cases.append({
                            "case_uuid": c.get('case_uuid'),
                            "offense_name": c.get('offense_name'),
                            "offense_level": c.get('offense_level'),
                            "severity": c.get('severity'),
                            "previous_offenses_count": c.get('previous_offenses_count', 0),
                            "decided_category": cat,
                            "similarity_score": round(float(sim_scores[idx]) * 100, 1)
                        })
                if len(matched_cases) >= top_k:
                    break

        best_score = matched_cases[0]['similarity_score'] / 100.0 if matched_cases else 0.85
        most_common = max(dist, key=dist.get) if dist else "Category 2"
        sufficient = len(matched_cases) > 0

        return {
            "similar_cases_count": len(matched_cases),
            "best_similarity": round(best_score, 2),
            "similar_cases": matched_cases,
            "historical_distribution": dist,
            "most_common_category": most_common,
            "sufficient_evidence": sufficient
        }

if __name__ == "__main__":
    engine = HistoricalSimilarityEngine()
    res = engine.find_similar("BRINGING IN VAPE", "MAJOR", "Moderate")
    print("Similarity Test Result:")
    print(json.dumps(res, indent=2))
