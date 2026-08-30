import sys
import os
import json
from typing import Optional, Dict, Any, List
from fastapi import FastAPI, HTTPException, Body
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

sys.path.append(r"c:\xampp\htdocs\identitrack")
from ai.model.predict import UPCCPredictor
from ai.model.train_model import train as train_rf_model

app = FastAPI(
    title="IdentiTrack Private UPCC AI Assistant API",
    version="1.2.0",
    description="Private, On-Premise Decision Support System for UPCC Panel Hearings (Random Forest + TF-IDF Cosine Similarity)"
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Global Predictor instance loaded into memory at startup
active_predictor = None

@app.on_event("startup")
def startup_event():
    global active_predictor
    active_predictor = UPCCPredictor(model_version="UPCC-RF-v1.0")

class SuggestRequest(BaseModel):
    case_id: str
    offense_name: Optional[str] = "GENERAL_VIOLATION"
    offense_level: Optional[str] = "MINOR"
    severity: Optional[str] = "Low"
    previous_offenses_count: Optional[int] = 0
    previous_related_count: Optional[int] = 0

class TrainRequest(BaseModel):
    dataset_version: Optional[str] = "UPCC-DATA-v1.0"

@app.get("/api/v1/upcc/ai/health")
def health_check():
    global active_predictor
    is_loaded = active_predictor is not None and active_predictor.rf is not None
    return {
        "status": "online" if is_loaded else "standby",
        "model_version": active_predictor.model_version if is_loaded else "None",
        "dataset_version": "UPCC-DATA-v1.0",
        "provider": "On-Premise Private Random Forest + TF-IDF Engine",
        "privacy": "🔒 100% On-Premise Campus Network (RA 10173 Compliant)"
    }

@app.post("/api/v1/upcc/ai/suggest")
def suggest_sanction(req: SuggestRequest):
    global active_predictor
    if active_predictor is None or active_predictor.rf is None:
        active_predictor = UPCCPredictor(model_version="UPCC-RF-v1.0")

    case_data = {
        "case_id": req.case_id,
        "offense_name": req.offense_name,
        "offense_level": req.offense_level,
        "severity": req.severity,
        "previous_offenses_count": req.previous_offenses_count,
        "previous_related_count": req.previous_related_count
    }

    result = active_predictor.predict_case(case_data)
    return result

@app.get("/api/v1/upcc/ai/similar-cases")
def get_similar_cases(offense_name: str, offense_level: str = "MINOR", severity: str = "Low"):
    global active_predictor
    if active_predictor is None:
        active_predictor = UPCCPredictor(model_version="UPCC-RF-v1.0")

    sim_res = active_predictor.similarity_engine.find_similar(
        offense_name=offense_name,
        offense_level=offense_level,
        severity=severity,
        top_k=8,
        min_similarity=0.70
    )
    return sim_res

@app.get("/api/v1/upcc/ai/model")
def get_current_model_info():
    metrics_path = r"c:\xampp\htdocs\identitrack\ai\storage\models\UPCC-RF-v1.0_metrics.json"
    if os.path.exists(metrics_path):
        with open(metrics_path, 'r', encoding='utf-8') as f:
            return json.load(f)
    return {
        "model_version": "UPCC-RF-v1.0",
        "status": "active",
        "dataset_version": "UPCC-DATA-v1.0"
    }

@app.post("/api/v1/upcc/ai/train")
def train_new_model():
    try:
        metrics = train_rf_model()
        return {
            "status": "success",
            "message": "New model trained successfully.",
            "metrics": metrics
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/api/v1/upcc/ai/models")
def list_models():
    model_dir = r"c:\xampp\htdocs\identitrack\ai\storage\models"
    models = []
    if os.path.exists(model_dir):
        for f in os.listdir(model_dir):
            if f.endswith("_metrics.json"):
                with open(os.path.join(model_dir, f), 'r', encoding='utf-8') as m_file:
                    models.append(json.load(m_file))
    return {"models": models}
