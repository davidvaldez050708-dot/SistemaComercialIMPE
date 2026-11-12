FROM litespeedtech/openlitespeed:1.8.3-lsphp82@sha256:c668df49470522bc380de38d3d2a18297a43913aefda61071e766b9d22ce7c86
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    git mariadb-server python3 && rm -rf /var/lib/apt/lists/*
ENTRYPOINT []
