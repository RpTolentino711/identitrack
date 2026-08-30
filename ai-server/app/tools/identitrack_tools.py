from typing import Dict, Any, List

class IdentiTrackTools:
    def search_handbook(self, query: str) -> List[Dict[str, Any]]:
        """Controlled tool: Search the Student Handbook knowledge base."""
        from app.services.handbook_service import handbook_service
        match = handbook_service.match_rule(query)
        if match and match.get("rule"):
            return [match["rule"]]
        return []

    def get_student_offenses(self, student_id: str) -> Dict[str, Any]:
        """Controlled tool: Read-only lookup of student offense history."""
        return {
            "student_id": student_id,
            "minor_offenses_count": 2,
            "major_offenses_count": 0,
            "status": "Under Review"
        }

    def get_community_service(self, student_id: str) -> Dict[str, Any]:
        """Controlled tool: Read-only lookup of community service requirements."""
        return {
            "student_id": student_id,
            "hours_required": 15.0,
            "hours_completed": 8.5,
            "hours_remaining": 6.5,
            "status": "ACTIVE"
        }

identitrack_tools = IdentiTrackTools()
