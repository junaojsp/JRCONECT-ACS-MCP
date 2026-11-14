FROM node:24-alpine
WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev --no-audit --no-fund
COPY server.js auth.js acs-monitor.js ./
# Git may preserve restrictive source file modes; ensure node can read scripts.
RUN chmod 644 /app/server.js /app/auth.js /app/acs-monitor.js /app/package.json
USER node
EXPOSE 3100
CMD ["node", "server.js"]
