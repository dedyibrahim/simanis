FROM node:20-bookworm-slim AS builder

WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN if [ -f train-nlp.js ]; then node train-nlp.js; fi

FROM node:20-bookworm-slim

ENV NODE_ENV=production \
    DOCKER_MODE=true

WORKDIR /app
COPY --from=builder /app /app

EXPOSE 8020
CMD ["node", "index.js"]
