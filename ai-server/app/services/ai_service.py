import json
import requests
from typing import Dict, Any
from app.config import settings
from app.services.handbook_service import handbook_service
from app.services.validation_service import validation_service
from app.models.schemas import OffenseAnalysisRequest, OffenseAnalysisResponse, ClassificationDetail, HandbookDetail, RecommendationDetail

class AIService:
    def analyze(self, request: OffenseAnalysisRequest) -> OffenseAnalysisResponse:
        sanitized_desc = validation_service.sanitize_input(request.offense_description)
        match_result = handbook_service.match_rule(sanitized_desc)
        rule = match_result["rule"]
        confidence = match_result["confidence"]
        uncertainty = match_result["uncertainty"]
        
        request_id = request.request_id or f"req_server_{id(request)}"

        # Optional LLM reasoning via Ollama if available
        llm_explanation = None
        if settings.AI_PROVIDER == "ollama":
            llm_explanation = self._call_ollama_reasoning(sanitized_desc, rule)

        if not llm_explanation:
            llm_explanation = (
                f"The offense description matches Student Handbook rule [{rule.get('rule_code', 'GENERAL')}] "
                f"({rule['title']}). Prescribed intervention category: {rule['intervention']}."
            )

        return OffenseAnalysisResponse(
            success=True,
            request_id=request_id,
            classification=ClassificationDetail(
                type=rule["severity"],
                category=rule["offense_type"],
                confidence=confidence
            ),
            handbook=HandbookDetail(
                section=rule["section"],
                rule=f"{rule['title']} ({rule.get('rule_code', 'SEC-GEN')})",
                source="NU Lipa Student Handbook"
            ),
            recommendation=RecommendationDetail(
                intervention=rule["intervention"],
                reason=f"Offense aligns with {rule['section']} guidelines for {rule['offense_type']}."
            ),
            ai_explanation=validation_service.sanitize_output(llm_explanation),
            uncertainty=uncertainty,
            requires_human_review=True
        )

    def _call_ollama_reasoning(self, description: str, rule: Dict[str, Any]) -> str:
        try:
            url = f"{settings.OLLAMA_URL}/api/generate"
            prompt = (
                f"System: You are IdentiTrack AI Decision Support for NU Lipa.\n"
                f"Offense: \"{description}\"\n"
                f"Handbook Rule: {rule['title']} ({rule['section']})\n"
                f"Explain in 2 concise sentences why this offense matches this handbook rule."
            )
            resp = requests.post(url, json={
                "model": settings.OLLAMA_MODEL,
                "prompt": prompt,
                "stream": False,
                "options": {"num_predict": 200, "temperature": 0.2}
            }, timeout=5)
            if resp.status_code == 200:
                data = resp.json()
                return data.get("response", "").strip()
        except Exception:
            pass
        return ""

ai_service = AIService()
