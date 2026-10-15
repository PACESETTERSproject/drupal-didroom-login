#!/usr/bin/env bash
# One-time host setup for local development on Ubuntu 24.04 / Pop!_OS 24.04:
# installs Docker Engine and DDEV from their official apt repositories.
# Run as your normal user; it calls sudo where needed.
set -euo pipefail

codename="$(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")"
arch="$(dpkg --print-architecture)"

sudo apt-get update
sudo apt-get install -y ca-certificates curl gnupg
sudo install -m 0755 -d /etc/apt/keyrings

# Docker Engine (https://docs.docker.com/engine/install/ubuntu/)
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=${arch} signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${codename} stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# DDEV (https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/#linux)
curl -fsSL https://pkg.ddev.com/apt/gpg.key | gpg --dearmor | sudo tee /etc/apt/keyrings/ddev.gpg > /dev/null
echo "deb [signed-by=/etc/apt/keyrings/ddev.gpg] https://pkg.ddev.com/apt/ * *" \
  | sudo tee /etc/apt/sources.list.d/ddev.list > /dev/null

sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin ddev

# Let the current user talk to Docker without sudo (takes effect on next login).
sudo usermod -aG docker "$USER"

# Local HTTPS certificates for *.ddev.site.
mkcert -install

echo
echo "Done. Log out and back in (or reboot) so the 'docker' group applies, then check with:"
echo "  docker run --rm hello-world && ddev version"
