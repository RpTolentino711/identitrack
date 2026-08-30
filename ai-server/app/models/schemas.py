from pydantic import BaseModel, Field
from typing import Optional, Dict, Any

class OffenseAnalysisRequest(BaseModel):
    offense_description: str = Field(..., min_length=3, description="Detailed description of student violation")
    student_id: Optional[str] = Field(None, description="Optional anonymized student identifier")
    context: Optional[str] = Field(None, description="Optional context or prior offenses summary")
    request_id: Optional[str] = Field(None, description="Unique tracking UUID for audit logging")

class ClassificationDetail(BaseModel):
    type: str = Field(..., description="Offense level (Section 4 Minor Offense, Major Offense)")
    category: str = Field(..., description="Handbook category code or name")
    confidence: float = Field(..., ge=0.0, le=1.0, description="Certainty score between 0.0 and 1.0")

class HandbookDetail(BaseModel):
    section: str = Field(..., description="Applicable Handbook Section")
    rule: str = Field(..., description="Handbook Rule Name / Code")
    source: str = Field("NU Lipa Student Handbook", description="Authoritative source document")

class RecommendationDetail(BaseModel):
    intervention: str = Field(..., description="Prescribed disciplinary intervention or Category 1/2/3 sanction")
    reason: str = Field(..., description="Policy justification based on Student Handbook")

class OffenseAnalysisResponse(BaseModel):
    success: bool = Field(True, description="API execution status")
    request_id: str = Field(..., description="Unique audit log request ID")
    classification: ClassificationDetail
    handbook: HandbookDetail
    recommendation: RecommendationDetail
    ai_explanation: str = Field(..., description="Transparent decision support reasoning")
    uncertainty: bool = Field(False, description="Flag indicating if the AI is uncertain")
    requires_human_review: bool = Field(True, description="Mandatory Human-in-the-loop verification flag")

class HealthStatusResponse(BaseModel):
    status: str = "online"
    model: str
    version: str
