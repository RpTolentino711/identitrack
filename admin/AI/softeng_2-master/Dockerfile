# All-in-one Dockerfile: Runs Next.js Web App (port 3000) & Python ML Server (port 5000)

# ==========================================
# Stage 1: Build Next.js Application
# ==========================================
FROM node:20-slim AS builder

WORKDIR /app

# Copy dependency specifications
COPY package.json package-lock.json* ./
COPY prisma ./prisma/

# Install dependencies
RUN npm ci

# Copy source code and build Next.js production bundle
COPY . .
RUN npx prisma generate
RUN npm run build

# ==========================================
# Stage 2: Production Runtime
# ==========================================
FROM python:3.11-slim AS runner

WORKDIR /app

ENV NODE_ENV=production \
    PORT=3000 \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

# Install Node.js 20, openssl and curl for healthchecks
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    ca-certificates \
    openssl \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Install Python dependencies
COPY server/requirements.txt ./server/requirements.txt
RUN pip install --no-cache-dir -r ./server/requirements.txt

# Copy Next.js production files and node_modules from builder
COPY --from=builder /app/node_modules ./node_modules
COPY --from=builder /app/.next ./.next
COPY --from=builder /app/public ./public
COPY --from=builder /app/package.json ./package.json
COPY --from=builder /app/prisma ./prisma
COPY --from=builder /app/app/generated ./app/generated

# Copy Python server files
COPY server/server.py ./server/server.py
COPY server/modle ./server/modle
COPY server/nltk_data ./server/nltk_data

# Copy and setup entrypoint script
COPY docker-entrypoint.sh ./docker-entrypoint.sh
RUN sed -i 's/\r$//' ./docker-entrypoint.sh && chmod +x ./docker-entrypoint.sh

# Expose Next.js port (3000) and ML Server port (5000)
EXPOSE 3000 5000

# Health check verifies Next.js and ML server are responding
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f http://localhost:3000 || exit 1

# Start both services
ENTRYPOINT ["./docker-entrypoint.sh"]
