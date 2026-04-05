FROM ghcr.io/provision-org/agent-runtime:latest

# Install provisiond (workforce agent task daemon)
# Copy source and build inside the image for reproducibility
COPY packages/provisiond/package.json /opt/provisiond/package.json
COPY packages/provisiond/tsconfig.json /opt/provisiond/tsconfig.json
COPY packages/provisiond/src /opt/provisiond/src
RUN cd /opt/provisiond && \
    npm install --ignore-scripts 2>/dev/null && \
    npx tsc && \
    rm -rf node_modules src tsconfig.json && \
    echo "provisiond v$(node -e 'console.log(require("./package.json").version)')" built

COPY docker/agent-runtime/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
