FROM ghcr.io/provision-org/agent-runtime:latest

COPY docker/agent-runtime/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
