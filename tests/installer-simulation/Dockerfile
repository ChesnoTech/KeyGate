# ═══════════════════════════════════════════════════════════════════
# OEM Activation System — Production Installer Simulation
#
# Simulates a real deployment:
#   Ubuntu 22.04 → aaPanel → LAMP stack → deploy app → web installer
#
# Usage:
#   docker compose up --build          (fast install, ~15 min)
#   INSTALL_MODE=compile docker compose up --build  (compile, ~2-4 hrs)
# ═══════════════════════════════════════════════════════════════════

FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
ENV container=docker
ENV LC_ALL=C
ENV LANG=C.UTF-8
ENV TZ=UTC

# ── Step 1: Base system (like a fresh Ubuntu 22 server) ──────────
RUN apt-get update && apt-get install -y \
    systemd systemd-sysv \
    wget curl ca-certificates gnupg lsb-release \
    iproute2 iputils-ping net-tools dnsutils \
    python3 python3-pip \
    jq bc procps psmisc \
    software-properties-common \
    dos2unix \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# ── Pre-install LAMP stack (DNS works at build time) ──────────────
# Pre-set timezone to prevent interactive prompts
RUN echo "Etc/UTC" > /etc/timezone && \
    ln -sf /usr/share/zoneinfo/Etc/UTC /etc/localtime

# Install Apache
RUN apt-get update && apt-get install -y \
    apache2 libapache2-mod-fcgid \
    && a2enmod rewrite proxy_fcgi setenvif ssl headers \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP 8.3 from PPA
RUN add-apt-repository -y ppa:ondrej/php && apt-get update && \
    apt-get install -y \
        php8.3 php8.3-fpm php8.3-cli \
        php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
        php8.3-gd php8.3-zip php8.3-intl php8.3-bcmath \
        php8.3-opcache php8.3-readline \
        libapache2-mod-php8.3 \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install MariaDB
RUN apt-get update && apt-get install -y \
    mariadb-server mariadb-client \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ── Trim systemd for Docker (keeps services working) ─────────────
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

# ── Copy simulation scripts ─────────────────────────────────────
COPY scripts/ /opt/simulation/
RUN dos2unix /opt/simulation/*.sh && chmod +x /opt/simulation/*.sh

# ── Copy application source ─────────────────────────────────────
COPY app/ /opt/oem-app/

EXPOSE 80 443 8888 888

STOPSIGNAL SIGRTMIN+3
VOLUME ["/sys/fs/cgroup", "/run", "/run/lock"]

# systemd as PID 1, then our simulation runs as a service
RUN echo '[Unit]\n\
Description=OEM Installer Simulation\n\
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
WantedBy=multi-user.target' > /etc/systemd/system/oem-simulation.service && \
    systemctl enable oem-simulation.service

CMD ["/opt/simulation/entrypoint.sh"]
