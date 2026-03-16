#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# Build & Run the Installer Simulation
#
# This script prepares the Docker build context by copying the
# application source, then launches the simulation.
#
# Usage:
#   ./build.sh              # Fast install mode (~15 min)
#   ./build.sh compile      # Compile mode (~2-4 hours)
#   ./build.sh --logs       # Build + follow logs
#   ./build.sh --clean      # Remove everything and start fresh
# ═══════════════════════════════════════════════════════════════════

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
APP_SOURCE="${PROJECT_ROOT}/FINAL_PRODUCTION_SYSTEM"
APP_DEST="${SCRIPT_DIR}/app"

echo ""
echo "╔═══════════════════════════════════════════════════════╗"
echo "║     OEM Installer Simulation — Build & Run           ║"
echo "╚═══════════════════════════════════════════════════════╝"
echo ""

# ── Parse arguments ─────────────────────────────────────────────
INSTALL_MODE="fast"
FOLLOW_LOGS=false
CLEAN=false

for arg in "$@"; do
    case "$arg" in
        compile)    INSTALL_MODE="compile" ;;
        fast)       INSTALL_MODE="fast" ;;
        --logs)     FOLLOW_LOGS=true ;;
        --clean)    CLEAN=true ;;
        --help|-h)
            echo "Usage: $0 [fast|compile] [--logs] [--clean]"
            echo ""
            echo "  fast      Use apt/yum packages (default, ~15 min)"
            echo "  compile   Compile from source (~2-4 hours)"
            echo "  --logs    Follow container logs after starting"
            echo "  --clean   Remove existing containers/volumes first"
            exit 0
            ;;
    esac
done

# ── Clean if requested ─────────────────────────────────────────
if [ "$CLEAN" = true ]; then
    echo "🧹 Cleaning up previous simulation..."
    cd "$SCRIPT_DIR"
    docker compose down -v 2>/dev/null || true
    rm -rf "$APP_DEST"
    echo "   Done."
    echo ""
fi

# ── Copy application source ────────────────────────────────────
echo "📦 Preparing application source..."

if [ ! -d "$APP_SOURCE" ]; then
    echo "ERROR: Application source not found at: ${APP_SOURCE}"
    exit 1
fi

# Clean and re-copy
rm -rf "$APP_DEST"
mkdir -p "$APP_DEST"

# Copy everything except frontend node_modules, .git, etc.
rsync -a --exclude='node_modules' --exclude='.git' --exclude='*.log' \
    "${APP_SOURCE}/" "${APP_DEST}/" 2>/dev/null || \
    cp -a "${APP_SOURCE}/." "${APP_DEST}/"

# Remove default config.php (installer will generate it)
rm -f "${APP_DEST}/config.php"

FILE_COUNT=$(find "$APP_DEST" -type f | wc -l)
echo "   Copied ${FILE_COUNT} files to build context"
echo ""

# ── Build and start ────────────────────────────────────────────
echo "🐳 Building Docker image..."
echo "   Install mode: ${INSTALL_MODE}"
echo ""

cd "$SCRIPT_DIR"
export INSTALL_MODE

docker compose build --no-cache

echo ""
echo "🚀 Starting simulation..."
echo ""

docker compose up -d

echo ""
echo "╔═══════════════════════════════════════════════════════╗"
echo "║  Simulation is running!                               ║"
echo "║                                                       ║"
echo "║  Monitor progress:                                    ║"
echo "║    docker compose logs -f simulation                  ║"
echo "║    docker exec oem-installer-sim journalctl -f        ║"
echo "║                                                       ║"
echo "║  Check result:                                        ║"
echo "║    docker exec oem-installer-sim cat /opt/simulation/result.json  ║"
echo "║                                                       ║"
echo "║  Access after completion:                             ║"
echo "║    OEM App:  http://localhost:8080                    ║"
echo "║    aaPanel:  http://localhost:8888                    ║"
echo "╚═══════════════════════════════════════════════════════╝"
echo ""

if [ "$FOLLOW_LOGS" = true ]; then
    echo "Following logs (Ctrl+C to stop watching, container keeps running)..."
    echo ""
    docker compose logs -f simulation
fi
