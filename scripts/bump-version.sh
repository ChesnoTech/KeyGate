#!/bin/bash
# =============================================================
# Version Bump Script — OEM Activation System
# =============================================================
# Usage:
#   ./scripts/bump-version.sh patch    # 2.0.0 → 2.0.1
#   ./scripts/bump-version.sh minor    # 2.0.0 → 2.1.0
#   ./scripts/bump-version.sh major    # 2.0.0 → 3.0.0
#   ./scripts/bump-version.sh 2.5.0    # Set exact version
#
# This script:
#   1. Updates FINAL_PRODUCTION_SYSTEM/VERSION.php
#   2. Commits the change
#   3. Creates a git tag (v2.1.0)
#   4. Pushes the tag (which triggers the release workflow)
# =============================================================

set -e

VERSION_FILE="FINAL_PRODUCTION_SYSTEM/VERSION.php"

# Ensure we're in the repo root
if [ ! -f "$VERSION_FILE" ]; then
    echo "❌ $VERSION_FILE not found. Run from repo root."
    exit 1
fi

# Parse current version
CURRENT=$(grep "APP_VERSION'" "$VERSION_FILE" | head -1 | sed "s/.*'\([0-9.]*\)'.*/\1/")
if [ -z "$CURRENT" ]; then
    echo "❌ Could not parse current version from $VERSION_FILE"
    exit 1
fi

IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT"

# Determine new version
BUMP_TYPE="${1:-patch}"
case "$BUMP_TYPE" in
    patch)
        PATCH=$((PATCH + 1))
        ;;
    minor)
        MINOR=$((MINOR + 1))
        PATCH=0
        ;;
    major)
        MAJOR=$((MAJOR + 1))
        MINOR=0
        PATCH=0
        ;;
    [0-9]*)
        # Exact version specified
        IFS='.' read -r MAJOR MINOR PATCH <<< "$BUMP_TYPE"
        ;;
    *)
        echo "Usage: $0 {patch|minor|major|X.Y.Z}"
        exit 1
        ;;
esac

NEW_VERSION="${MAJOR}.${MINOR}.${PATCH}"
VERSION_CODE=$((MAJOR * 100 + MINOR * 10 + PATCH))
RELEASE_DATE=$(date +%Y-%m-%d)
TAG="v${NEW_VERSION}"

echo "═══════════════════════════════════════"
echo "  Version Bump: $CURRENT → $NEW_VERSION"
echo "  Version Code: $VERSION_CODE"
echo "  Tag: $TAG"
echo "  Date: $RELEASE_DATE"
echo "═══════════════════════════════════════"

# Check for uncommitted changes
if ! git diff --quiet HEAD 2>/dev/null; then
    echo "⚠️  You have uncommitted changes. Commit them first."
    echo ""
    git status --short
    echo ""
    read -p "Continue anyway? (y/N) " -r
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# Check tag doesn't already exist
if git rev-parse "$TAG" >/dev/null 2>&1; then
    echo "❌ Tag $TAG already exists"
    exit 1
fi

# Update VERSION.php
cat > "$VERSION_FILE" <<EOF
<?php
/**
 * Application Version — OEM Activation System
 *
 * This file is updated automatically by the upgrade system.
 * Do NOT edit manually unless you know what you are doing.
 */
define('APP_VERSION', '$NEW_VERSION');
define('APP_VERSION_CODE', $VERSION_CODE);
define('APP_VERSION_DATE', '$RELEASE_DATE');
EOF

echo "✅ Updated $VERSION_FILE"

# Commit
git add "$VERSION_FILE"
git commit -m "Bump version to $NEW_VERSION

Co-Authored-By: bump-version.sh <noreply@chesnotech.com>"

echo "✅ Committed version bump"

# Create tag
git tag -a "$TAG" -m "Release $NEW_VERSION"
echo "✅ Created tag $TAG"

# Push
echo ""
echo "Ready to push. This will trigger the release workflow."
read -p "Push commit + tag to origin? (Y/n) " -r
if [[ $REPLY =~ ^[Nn]$ ]]; then
    echo "Skipped push. Run manually:"
    echo "  git push origin $(git branch --show-current) && git push origin $TAG"
    exit 0
fi

BRANCH=$(git branch --show-current)
git push origin "$BRANCH"
git push origin "$TAG"

echo ""
echo "🚀 Pushed! Release workflow will:"
echo "   1. Run CI checks"
echo "   2. Build frontend"
echo "   3. Create upgrade package (upgrade-${TAG}.zip)"
echo "   4. Publish GitHub Release"
echo ""
echo "   Track it: gh run list --workflow=release.yml"
