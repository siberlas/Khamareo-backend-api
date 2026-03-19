#!/bin/bash
set -euo pipefail

# ── Khamareo VPS Initial Setup (Ubuntu 22.04/24.04) ────────
# Usage: ssh root@<VPS_IP> 'bash -s' < scripts/vps-setup.sh

echo "=== Khamareo VPS Setup ==="

# System updates
apt-get update && apt-get upgrade -y

# Create deploy user
if ! id "deploy" &>/dev/null; then
    adduser --disabled-password --gecos "" deploy
    usermod -aG sudo deploy
    mkdir -p /home/deploy/.ssh
    cp /root/.ssh/authorized_keys /home/deploy/.ssh/
    chown -R deploy:deploy /home/deploy/.ssh
    chmod 700 /home/deploy/.ssh
    chmod 600 /home/deploy/.ssh/authorized_keys
    echo "deploy ALL=(ALL) NOPASSWD: ALL" > /etc/sudoers.d/deploy
    echo ">>> User 'deploy' created"
fi

# Install Docker
if ! command -v docker &>/dev/null; then
    curl -fsSL https://get.docker.com | sh
    usermod -aG docker deploy
    echo ">>> Docker installed"
fi

# Install Docker Compose plugin
if ! docker compose version &>/dev/null; then
    apt-get install -y docker-compose-plugin
    echo ">>> Docker Compose installed"
fi

# Firewall (UFW)
apt-get install -y ufw
ufw default deny incoming
ufw default allow outgoing
ufw allow ssh
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
echo ">>> UFW configured (SSH + HTTP + HTTPS)"

# Fail2ban
apt-get install -y fail2ban
cat > /etc/fail2ban/jail.local << 'EOF'
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 5

[sshd]
enabled = true
port = ssh
logpath = /var/log/auth.log
maxretry = 3
EOF
systemctl enable fail2ban
systemctl restart fail2ban
echo ">>> Fail2ban configured"

# SSH hardening
sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
systemctl restart ssh || systemctl restart sshd
echo ">>> SSH hardened (key-only, no root)"

# Create app directory
mkdir -p /opt/khamareo/backups
chown -R deploy:deploy /opt/khamareo

# Swap (2GB for VPS with limited RAM)
if [ ! -f /swapfile ]; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
    sysctl vm.swappiness=10
    echo 'vm.swappiness=10' >> /etc/sysctl.conf
    echo ">>> 2GB swap created"
fi

# Automatic security updates
apt-get install -y unattended-upgrades
dpkg-reconfigure -plow unattended-upgrades

echo ""
echo "=== VPS Setup Complete ==="
echo ""
echo "Prochaines étapes :"
echo "  1. Se connecter en tant que 'deploy' : ssh deploy@<VPS_IP>"
echo "  2. Cloner le repo : git clone <REPO_URL> /opt/khamareo"
echo "  3. Copier .env.prod.template → .env.prod et remplir les secrets"
echo "  4. Générer les clés JWT dans config/jwt/"
echo "  5. Lancer : ./deploy.sh first-run"
