FROM node:20-bookworm-slim AS builder

ARG NUXT_PUBLIC_API_BASE=/api
ARG NUXT_PUBLIC_ASSET_BASE=
ENV NUXT_PUBLIC_API_BASE=${NUXT_PUBLIC_API_BASE}
ENV NUXT_PUBLIC_ASSET_BASE=${NUXT_PUBLIC_ASSET_BASE}

WORKDIR /app
COPY frontend/package*.json ./
RUN npm ci
COPY frontend ./
RUN npm run generate

FROM nginx:1.27-alpine

COPY deploy/docker/frontend/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=builder /app/.output/public /usr/share/nginx/html

EXPOSE 80
