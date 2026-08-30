from pydantic_settings import BaseSettings
from typing import Optional

class Settings(BaseSettings):
    PROJECT_NAME: str = "IdentiTrack Production AI Server"
    VERSION: str = "1.0.0"
    API_PREFIX: str = "/api/v1"
    
    # Security & API Auth
    API_KEY: Optional[str] = "identitrack_production_secret_key_2026"
    
    # Provider Settings (production, ollama, classifier)
    AI_PROVIDER: str = "production"
    OLLAMA_URL: str = "http://localhost:11434"
    OLLAMA_MODEL: str = "llama3.2:latest"
    
    # Timeout Settings
    REQUEST_TIMEOUT: int = 30
    
    class Config:
        env_file = ".env"
        case_sensitive = True

settings = Settings()
