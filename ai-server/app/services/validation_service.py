import html
from typing import Dict, Any

class ValidationService:
    def sanitize_input(self, text: str) -> str:
        if not text:
            return ""
        # Strip script tags and HTML entities
        clean = html.escape(text.strip())
        return clean

    def sanitize_output(self, content: str) -> str:
        if not content:
            return ""
        # Prevent XSS rendering on website
        clean = html.escape(content.strip())
        return clean

validation_service = ValidationService()
