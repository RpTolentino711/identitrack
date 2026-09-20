# Dedicated Dockerfile for the Python ML Server (server.py)
FROM python:3.11-slim

# Prevent Python from writing pyc files and buffer outputs
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

# Install curl for healthcheck
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install python dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy server code and model encoders
COPY server.py .
COPY modle/ ./modle/
COPY nltk_data/ ./nltk_data/

# Expose Flask default port
EXPOSE 5000

# Health check
HEALTHCHECK --interval=20s --timeout=5s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:5000/health || exit 1

# Start the Flask ML server
CMD ["python", "server.py"]
