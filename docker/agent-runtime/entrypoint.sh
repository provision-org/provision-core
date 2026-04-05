#!/bin/bash
set -e

echo "Starting agent runtime..."

# Start virtual display
export DISPLAY=:99
Xvfb :99 -screen 0 1440x900x24 -ac &
sleep 1

# Start VNC server (no password for local dev)
x11vnc -display :99 -forever -shared -rfbport 5900 -nopw -q &

# Start noVNC web client
websockify --web /usr/share/novnc 6080 localhost:5900 &

echo "Agent runtime ready."
echo "  VNC viewer: http://localhost:6080"

# Auto-start OpenClaw gateway if config exists (container restart)
if [ -f /root/.openclaw/openclaw.json ] && [ -f /root/.openclaw/.env ]; then
    echo "Found OpenClaw config, starting gateway..."
    source /root/.openclaw/.env
    nohup openclaw gateway >> /root/.openclaw/logs/gateway.log 2>&1 &
    sleep 5
    echo "Gateway started."
fi

# Start provisiond (workforce agent daemon) if configured
if [ -f /etc/provisiond/config.json ]; then
    echo "Starting provisiond v$(node -e 'console.log(require("/opt/provisiond/package.json").version)' 2>/dev/null || echo '?')..."
    nohup node /opt/provisiond/provisiond.mjs --config /etc/provisiond/config.json >> /var/log/provisiond.log 2>&1 &
    echo "provisiond started. Log: /var/log/provisiond.log"
fi

# Keep container running
tail -f /dev/null
