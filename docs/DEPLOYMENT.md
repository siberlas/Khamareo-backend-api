# Khamareo — Guide de déploiement production

## Architecture

```
Cloudflare (DNS + CDN + SSL)
├── khamareo.com ──────→ Cloudflare Pages (React SPA statique)
└── api.khamareo.com ──→ VPS Scaleway (Docker Compose)
                           ├── nginx (reverse proxy, port 80)
                           ├── PHP-FPM (Symfony API)
                           ├── PostgreSQL 16
                           └── Redis 7
```

---

## 1. VPS Scaleway — Setup initial

### 1.1 Prérequis

- VPS Ubuntu 22.04 ou 24.04
- Accès SSH root avec clé SSH

### 1.2 Installation automatique

```bash
# Depuis votre machine locale
ssh root@<VPS_IP> 'bash -s' < scripts/vps-setup.sh
```

Le script installe : Docker, Docker Compose, UFW (ports 22/80/443), Fail2ban, user `deploy`, swap 2GB, mises à jour auto.

### 1.3 Déploiement initial

```bash
# Se connecter en tant que deploy
ssh deploy@<VPS_IP>

# Cloner le repo
git clone <REPO_URL> /opt/khamareo
cd /opt/khamareo

# Configurer les secrets
cp .env.prod.template .env.prod
nano .env.prod  # Remplir tous les secrets

# Générer les clés JWT
mkdir -p config/jwt
openssl genpkey -out config/jwt/private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096
openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout

# Premier déploiement
./deploy.sh first-run
```

### 1.4 Déploiements suivants

```bash
ssh deploy@<VPS_IP>
cd /opt/khamareo
./deploy.sh
```

Le script `deploy.sh` :
1. Backup la base de données
2. Pull le code (`git pull`)
3. Build les images Docker
4. Redémarre les services
5. Exécute les migrations
6. Clear le cache
7. Vérifie le health check

### 1.5 Variables d'environnement

Fichier `.env.prod` (voir `.env.prod.template` pour la liste complète) :

| Variable | Description | Requis |
|----------|-------------|--------|
| `APP_ENV` | `prod` | Oui |
| `APP_SECRET` | `openssl rand -hex 32` | Oui |
| `POSTGRES_DB` | Nom de la base | Oui |
| `POSTGRES_USER` | Utilisateur PostgreSQL | Oui |
| `POSTGRES_PASSWORD` | Mot de passe fort (32+ car.) | Oui |
| `DATABASE_URL` | URL PostgreSQL (utilise les vars ci-dessus) | Oui |
| `REDIS_URL` | `redis://redis:6379` | Oui |
| `TRUSTED_PROXIES` | IPs Cloudflare (voir template) | Oui |
| `CORS_ALLOW_ORIGIN` | `^https://khamareo\.com$` | Oui |
| `JWT_PASSPHRASE` | Passphrase des clés JWT | Oui |
| `STRIPE_SECRET_KEY` | `sk_live_...` | Oui |
| `STRIPE_WEBHOOK_SECRET` | `whsec_...` | Oui |
| `MAILER_DSN` | `smtp://...@smtp-relay.brevo.com:587` | Oui |
| `CLOUDINARY_*` | Credentials Cloudinary | Oui |
| `COLISSIMO_*` | Credentials Colissimo | Oui |
| `SENTRY_DSN` | DSN Sentry PHP | Oui |

### 1.6 Services Docker (docker-compose.prod.yml)

| Service | Image | Port | Mémoire |
|---------|-------|------|---------|
| `php` | Build depuis Dockerfile | 9000 (interne) | 512M |
| `nginx` | nginx:1.25-alpine | 80 (exposé) | 128M |
| `db` | postgres:16-alpine | 5432 (interne) | 512M |
| `redis` | redis:7-alpine | 6379 (interne) | 192M |

Seul le port 80 de nginx est exposé. PostgreSQL et Redis ne sont pas accessibles de l'extérieur.

### 1.7 Logs

```bash
# Tous les logs
docker compose -f docker-compose.prod.yml logs -f

# Logs PHP uniquement
docker compose -f docker-compose.prod.yml logs -f php

# Logs nginx
docker compose -f docker-compose.prod.yml logs -f nginx
```

### 1.8 Backups

Backup automatique quotidien (crontab) :
```bash
# Ajouter au crontab de l'utilisateur deploy
crontab -e
# Ajouter :
0 3 * * * /opt/khamareo/scripts/backup.sh >> /var/log/khamareo-backup.log 2>&1
```

Backup manuel :
```bash
docker compose -f docker-compose.prod.yml exec -T db \
    pg_dump -U khamareo -Fc khamareo > backup_$(date +%Y%m%d).dump
```

Restauration :
```bash
docker compose -f docker-compose.prod.yml exec -T db \
    pg_restore -U khamareo -d khamareo --clean < backup.dump
```

---

## 2. JWT Keys

### Génération

```bash
# Clé privée (avec passphrase)
openssl genpkey -out config/jwt/private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096

# Clé publique
openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout
```

### Rotation

1. Générer de nouvelles clés
2. Remplacer dans `config/jwt/`
3. Redéployer (`./deploy.sh`)
4. Les utilisateurs connectés devront se reconnecter

---

## 3. Cloudflare Pages — Frontend

### 3.1 Connexion

1. Cloudflare Dashboard → Pages → **Create a project**
2. Connecter le repo GitHub `khamareo-african-wellness`
3. Configuration :

| Paramètre | Valeur |
|-----------|--------|
| Framework preset | Vite |
| Build command | `npm run build` |
| Build output directory | `dist` |
| Node.js version | 18 ou 20 |

### 3.2 Variables d'environnement

Configurer dans Cloudflare Pages → Settings → Environment Variables :

| Variable | Valeur |
|----------|--------|
| `VITE_API_URL` | `https://api.khamareo.com` |
| `VITE_API_BASE_URL` | `https://api.khamareo.com/api` |
| `VITE_STRIPE_PUBLIC_KEY` | `pk_live_...` |
| `VITE_CLOUDINARY_CLOUD_NAME` | `dtdumh0gv` |
| `VITE_COLISSIMO_TOKEN` | `504CA89B7BD982B46CBB24B19F6CFAA3` |
| `VITE_SENTRY_DSN` | `https://8ecc80e4...@...sentry.io/...` |

> **Note** : Les variables `VITE_*` sont injectées au **build time**. Tout changement nécessite un rebuild.

### 3.3 Custom domain

1. Cloudflare Pages → Custom domains → Add `khamareo.com`
2. Cloudflare crée automatiquement le CNAME
3. Ajouter `www.khamareo.com` avec redirection 301 → `khamareo.com`

### 3.4 SPA Routing

Le fichier `public/_redirects` gère le routage :
```
/*  /index.html  200
```

---

## 4. Cloudflare DNS

### Records DNS

| Type | Name | Content | Proxy |
|------|------|---------|-------|
| A | `api` | `<VPS_IP>` | Proxied (orange) |
| CNAME | `khamareo.com` | Auto (Cloudflare Pages) | Auto |
| CNAME | `www` | `khamareo.com` | Proxied |

### SSL/TLS

- **Mode** : Full
- **Always Use HTTPS** : Activé
- **Automatic HTTPS Rewrites** : Activé
- **Minimum TLS Version** : 1.2
- **HSTS** : Activé (max-age=31536000)

### Page Rules

1. `api.khamareo.com/*` → **Cache Level: Bypass**
2. `www.khamareo.com/*` → **Forwarding URL (301)** → `https://khamareo.com/$1`

---

## 5. Stripe — Mode production

### Passage test → live

1. Dashboard Stripe → mode **Live**
2. Copier `pk_live_...` → Cloudflare Pages `VITE_STRIPE_PUBLIC_KEY`
3. Copier `sk_live_...` → `.env.prod` `STRIPE_SECRET_KEY`

### Webhook

1. Stripe → Developers → Webhooks → **Add endpoint**
2. URL : `https://api.khamareo.com/api/stripe/webhook`
3. Events : `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`
4. Copier `whsec_...` → `.env.prod` `STRIPE_WEBHOOK_SECRET`

---

## 6. Email — Brevo (ex-Sendinblue)

1. Créer un compte sur [brevo.com](https://www.brevo.com) (gratuit : 300 emails/jour)
2. SMTP & API → obtenir les identifiants SMTP
3. Format `MAILER_DSN` :
   ```
   smtp://LOGIN:PASSWORD@smtp-relay.brevo.com:587
   ```
4. Configurer dans `.env.prod` : `MAILER_DSN`
5. Vérifier le domaine `khamareo.com` dans Brevo (SPF + DKIM dans Cloudflare DNS)

---

## 7. Sentry — Monitoring

### Backend (Symfony)
- DSN : `.env.prod` → `SENTRY_DSN`
- Config : `config/packages/sentry.yaml`
- Sample rate : 20% transactions

### Frontend (React)
- DSN : Cloudflare Pages → `VITE_SENTRY_DSN`
- Config : `src/main.tsx`
- Sample rate : 20% transactions, 100% replay sur erreurs

---

## 8. Troubleshooting

### Cookie refresh token ne fonctionne pas

Le refresh token utilise un cookie `httpOnly`, `Secure`, `SameSite=None`. Vérifier :
1. Le backend est en HTTPS (via Cloudflare proxy)
2. `TRUSTED_PROXIES` contient les IPs Cloudflare
3. CORS autorise `credentials: true` et origin `https://khamareo.com`

### Erreurs CORS

Vérifier `CORS_ALLOW_ORIGIN` dans `.env.prod` :
```
^https://khamareo\.com$
```

### Rate limiters voient la mauvaise IP

Le `prod.conf` nginx forward `CF-Connecting-IP` comme `REMOTE_ADDR`. Vérifier que `TRUSTED_PROXIES` est correctement configuré avec les ranges IP Cloudflare.

### Health check échoue

```bash
# Tester directement sur le VPS
curl http://localhost/api/health

# Vérifier les logs
docker compose -f docker-compose.prod.yml logs php
docker compose -f docker-compose.prod.yml logs nginx
```

### Build Docker échoue

```bash
# Tester localement
docker build -t khamareo-backend .
```

### Redémarrage complet

```bash
docker compose -f docker-compose.prod.yml down
docker compose -f docker-compose.prod.yml up -d
```

Les données PostgreSQL et Redis sont persistées via les volumes Docker.
