from fastapi import APIRouter, HTTPException, Depends, Header
from pydantic import BaseModel, Field
from typing import Optional, List, Dict, Any
from app.config import settings
from app.routes.analyze import verify_api_key
from app.services.handbook_service import handbook_service
from app.tools.identitrack_tools import identitrack_tools

router = APIRouter()

class ChatRequest(BaseModel):
    message: str = Field(..., description="User prompt or follow-up message")
    conversation_uuid: Optional[str] = Field(None, description="Active conversation session UUID")
    history: Optional[List[Dict[str, Any]]] = Field(default=[], description="Prior session message history")
    context: Optional[Dict[str, Any]] = Field(default={}, description="Optional case or student context")

class ChatResponse(BaseModel):
    success: bool = True
    conversation_uuid: str
    reply: str
    sources: List[Dict[str, Any]] = []
    tool_calls: List[Dict[str, Any]] = []
    engine: str

@router.post("/chat", response_model=ChatResponse)
def chat_endpoint(
    request: ChatRequest,
    authenticated: bool = Depends(verify_api_key)
):
    msg_lower = request.message.lower()
    sources = []
    tool_calls = []

    # 1. RAG Handbook Tool Call Execution
    if any(k in msg_lower for k in ["rule", "handbook", "policy", "section", "offense", "cheat", "uniform", "id"]):
        tool_calls.append({"tool_name": "search_handbook", "status": "executed"})
        rules = identitrack_tools.search_handbook(request.message)
        if rules:
            sources = rules

    # 2. Conversational Intent Generation
    conv_uuid = request.conversation_uuid or f"conv_server_{id(request)}"

    if any(k in msg_lower for k in ["hi", "hello", "hey", "who are you"]):
        reply = (
            "👋 **Hello! I am IdentiTrack AI**, your live conversational decision-support assistant for NU Lipa.\n\n"
            "I can assist you in reviewing Student Handbook policies, calculating community service hours, "
            "and checking offense escalation rules.\n\nHow can I help you today?"
        )
    elif "3" in msg_lower or "three" in msg_lower or "minor" in msg_lower:
        reply = (
            "📌 **Section 4 Minor Offense Escalation Policy**:\n\n"
            "Accumulating **3 minor offenses** automatically triggers escalation to a **Category 2 Major Offense**.\n\n"
            "• **1st Minor**: Written Reprimand & Category 1 Warning (10 Hours CS).\n"
            "• **2nd Minor**: Category 1 Sanction & 15 Hours CS.\n"
            "• **3rd Minor**: **AUTOMATIC MAJOR ESCALATION**.\n\n"
            "Would you like me to check a specific student's offense history?"
        )
    else:
        reply = f"🧠 **IdentiTrack AI Assistant**:\n\nI have analyzed your query."
        if sources:
            reply += f" Based on **{sources[0]['title']} ({sources[0]['section']})**: {sources[0]['description']}\n\n"
        reply += "Feel free to ask follow-up questions or request a full case analysis!"

    return ChatResponse(
        success=True,
        conversation_uuid=conv_uuid,
        reply=reply,
        sources=sources,
        tool_calls=tool_calls,
        engine=f"{settings.AI_PROVIDER}:{settings.OLLAMA_MODEL}"
    )

@router.post("/search-handbook")
def search_handbook_endpoint(
    query: str,
    authenticated: bool = Depends(verify_api_key)
):
    results = identitrack_tools.search_handbook(query)
    return {"success": True, "query": query, "results": results}

@router.get("/models")
def get_models_endpoint():
    return {
        "success": True,
        "models": [
            {"id": "production-decision-support-v1", "name": "IdentiTrack Decision Support Model"},
            {"id": settings.OLLAMA_MODEL, "name": f"Local LLM ({settings.OLLAMA_MODEL})"}
        ]
    }
