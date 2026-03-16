# ═══════════════════════════════════════════════════════════════════
# OEM Activation System — Full Production Deployment Simulation
#
# Starts from a CLEAN Ubuntu 22.04 server — no LAMP pre-installed.
# Installs aaPanel, then uses aaPanel's API to configure everything
# exactly like a human admin would.
#
# Flow:
#   1. Fresh Ubuntu 22.04 + systemd
#   2. Install aaPanel (official installer)
#   3. Use aaPanel API to install Apache + PHP 8.3 + MariaDB
#   4. Use aaPanel API to create website + database
#   5. Deploy OEM app files
#   6. Run the web installer (6-step wizard via curl)
#   7. Post-install verification (30+ tests)
#
# Usage:
#   docker compose up --build        (~10-20 min)
# ═══════════════════════════════════════════════════════════════════

FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
ENV container=docker
ENV LC_ALL=C
ENV LANG=C.UTF-8
ENV TZ=UTC

# ── Step 1: Minimal base system (like a fresh Ubuntu 22 VPS) ─────
RUN apt-get update && apt-get install -y \
    systemd systemd-sysv \
    wget curl ca-certificates gnupg lsb-release \
    iproute2 iputils-ping net-tools dnsutils \
    python3 \
    jq bc procps psmisc \
    software-properties-common \
    dos2unix \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Pre-set timezone to prevent interactive prompts
RUN echo "Etc/UTC" > /etc/timezone && \
    ln -sf /usr/share/zoneinfo/Etc/UTC /etc/localtime

# ── Trim systemd for Docker ──────────────────────────────────────
RUN (cd /lib/systemd/system/sysinit.target.wants/ && \
        ls | grep -v systemd-tmpfiles-setup | xargs -r rm -f) && \
    rm -f /lib/systemd/system/multi-user.target.wants/* && \
    rm -f /etc/systemd/system/*.wants/* && \
    rm -f /lib/systemd/system/local-fs.target.wants/* && \
    rm -f /lib/systemd/system/sockets.target.wants/*udev* && \
    rm -f /lib/systemd/system/sockets.target.wants/*initctl* && \
    rm -f /lib/systemd/system/basic.target.wants/* && \
    rm -f /lib/systemd/system/anaconda.target.wants/* && \
    rm -f /lib/systemd/system/plymouth* && \
    rm -f /lib/systemd/system/systemd-update-utmp*

# ── Pre-download aaPanel installer (DNS works at build time) ─────
RUN mkdir -p /opt/aapanel && \
    wget -q -T 30 -O /opt/aapanel/install.sh \
        "https://www.aapanel.com/script/install_7.0_en.sh" 2>/dev/null || \
    wget -q -T 30 -O /opt/aapanel/install.sh \
        "https://raw.githubusercontent.com/aapanel/aaPanel/main/install_7.0_en.sh" 2>/dev/null || \
    echo "#!/bin/bash\necho 'aaPanel installer not downloaded'" > /opt/aapanel/install.sh

# ── Copy simulation scripts ──────────────────────────────────────
COPY scripts/ /opt/simulation/
RUN dos2unix /opt/simulation/*.sh 2>/dev/null; chmod +x /opt/simulation/*.sh

# ── Copy application source ──────────────────────────────────────
COPY app/ /opt/oem-app/

EXPOSE 80 443 8888 888 3306

STOPSIGNAL SIGRTMIN+3
VOLUME ["/sys/fs/cgroup", "/run", "/run/lock"]

# ── Simulation runs as a systemd service ─────────────────────────
RUN printf '[Unit]\n\
Description=OEM Full Deployment Simulation\n\
After=network.target\n\
\n\
[Service]\n\
Type=oneshot\n\
RemainAfterExit=yes\n\
Environment=DEBIAN_FRONTEND=noninteractive\n\
Environment=TZ=UTC\n\
EnvironmentFile=-/opt/simulation/.env\n\
ExecStart=/opt/simulation/orchestrator.sh\n\
StandardOutput=journal+console\n\
StandardError=journal+console\n\
TimeoutStartSec=14400\n\
\n\
[Install]\n\
WantedBy=multi-user.target\n' > /etc/systemd/system/oem-simulation.service && \
    systemctl enable oem-simulation.service

CMD ["/opt/simulation/entrypoint.sh"]
