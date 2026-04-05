FROM ghcr.io/provision-org/agent-runtime:latest

# Install provisiond (workforce agent task daemon)
# In production: downloaded from GitHub Releases
# In dev: built from source
ARG PROVISIOND_VERSION=dev
COPY packages/provisiond /tmp/provisiond-src
RUN mkdir -p /opt/provisiond && \
    cd /tmp/provisiond-src && \
    npm install --ignore-scripts 2>/dev/null && \
    npx esbuild src/index.ts --bundle --platform=node --target=node22 --format=esm --outfile=/opt/provisiond/provisiond.mjs && \
    cp package.json /opt/provisiond/package.json && \
    rm -rf /tmp/provisiond-src && \
    echo "provisiond v$(node -e 'console.log(require("/opt/provisiond/package.json").version)') built"

COPY docker/agent-runtime/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
