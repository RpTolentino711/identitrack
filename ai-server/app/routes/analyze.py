from fastapi import APIRouter, HTTPException, Depends, Header
from typing import Optional
from app.config import settings
from app.models.schemas import OffenseAnalysisRequest, OffenseAnalysisResponse, HealthStatusResponse
from app.services.ai_service import ai_service

router = APIRouter()

def verify_api_key(
    authorization: Optional[str] = Header(None),
    x_api_key: Optional[str] = Header(None)
):
    if not settings.API_KEY:
        return True
    
    provided_key = None
    if authorization and authorization.startswith("Bearer "):
        provided_key = authorization.replace("Bearer ", "").strip()
    elif x_api_key:
        provided_key = x_api_key.strip()
        
    if provided_key != settings.API_KEY:
        raise HTTPException(status_code=401, detail="Unauthorized: Invalid API Key")
    return True

@router.get("/health", response_model=HealthStatusResponse)
def health_check():
    return HealthStatusResponse(
        status="online",
        model=settings.OLLAMA_MODEL if settings.AI_PROVIDER == "ollama" else "Production-Decision-Support-v1",
        version=settings.VERSION
    )

@router.post("/analyze-offense", response_model=OffenseAnalysisResponse)
def analyze_offense(
    request: OffenseAnalysisRequest,
    authenticated: bool = Depends(verify_api_key)
):
    try:
        response = ai_service.analyze(request)
        return response
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"AI Analysis Error: {str(e)}")
