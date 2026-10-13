FROM mongo:8.0
# Only this local demo image can apply peer-specific network partitions.
RUN apt-get update && apt-get install -y --no-install-recommends iptables \
    && rm -rf /var/lib/apt/lists/*
