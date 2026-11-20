#!/bin/sh
# Builds the image and publishes it to GitHub's container registry (ghcr.io).
#
#   ./release.sh 1.0.0
#
# Pushes ghcr.io/<GHCR_OWNER>/secret-santa:<version> and :latest.
# Needs a one-time `docker login ghcr.io` with a classic token that has write:packages.
set -eu

GHCR_OWNER="${GHCR_OWNER:-nickbeaus}" # GitHub account the image is published under
IMAGE_NAME="secret-santa"
VERSION="${1:-}"

if [ -z "$GHCR_OWNER" ]; then
    echo "Set GHCR_OWNER (your GitHub username) at the top of release.sh or in the environment." >&2
    exit 1
fi
if ! echo "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "Usage: ./release.sh <version>   e.g. ./release.sh 1.0.0" >&2
    exit 2
fi

# Registry names must be lowercase.
IMAGE="ghcr.io/$(echo "$GHCR_OWNER" | tr 'A-Z' 'a-z')/$IMAGE_NAME"
if docker manifest inspect "$IMAGE:$VERSION" >/dev/null 2>&1; then
    echo "$IMAGE:$VERSION is already published. Use a new version number." >&2
    exit 1
fi

cd "$(dirname "$0")"
docker build --pull \
    --label "org.opencontainers.image.title=Secret Santa" \
    --label "org.opencontainers.image.description=Secret Santa drawings with private reveal links and texting" \
    --label "org.opencontainers.image.version=$VERSION" \
    --label "org.opencontainers.image.created=$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    -t "$IMAGE:$VERSION" -t "$IMAGE:latest" .

docker push "$IMAGE:$VERSION"
docker push "$IMAGE:latest"

echo
echo "Published $IMAGE:$VERSION"
echo "Installs using it: set  image: $IMAGE:$VERSION  in compose.yaml, then  docker compose pull && docker compose up -d"
