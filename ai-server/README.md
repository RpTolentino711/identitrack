# IdentiTrack Live Production AI Server

Portable, independent FastAPI AI decision-support server for **IdentiTrack**.

## Architecture & Integration Flow

```
LIVE INTERNET
     │
     ▼
┌─────────────────────┐
│ IDENTITRACK WEBSITE │ (PHP / MySQL)
└──────────┬──────────┘
           │
      Secure API (Bearer Auth)
           │
           ▼
┌─────────────────────┐
│ LIVE AI SERVER      │ (Python FastAPI / Docker / VPS)
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│ AI MODEL / KNOWLEDGE│ (Random Forest Classifier & Student Handbook Rules)
└─────────────────────┘
```

## Quick Start

### Local Development
```bash
cd ai-server
pip install -r requirements.txt
python app/main.py
```

### Docker Deployment
```bash
docker-compose up -d --build
```

## Endpoints

* `GET /api/v1/health` - Service health status
* `POST /api/v1/analyze-offense` - Structured offense analysis and decision support

## Capstone Disclaimer Notice
> "The AI component functions as a decision-support mechanism. It analyzes offense descriptions and provides recommendations based on the Student Handbook, but it does not independently impose disciplinary sanctions. Final decisions remain under the authority of authorized university personnel."
