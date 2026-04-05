FROM ghcr.io/provision-org/agent-runtime:latest

# Install provisiond v0.2.0 (workforce agent task daemon)
# Pre-built bundle — no build step needed inside the container
COPY packages/provisiond/bundle/provisiond.mjs /opt/provisiond/provisiond.mjs
COPY packages/provisiond/package.json /opt/provisiond/package.json

COPY docker/agent-runtime/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
