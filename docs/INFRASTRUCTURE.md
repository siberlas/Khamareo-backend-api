# Khamareo — Documentation Infrastructure

> Securite, Monitoring, Performance, SEO
> Derniere mise a jour : 2026-03-06

---

## Table des matieres

1. [Securite](#1-securite)
2. [Monitoring](#2-monitoring)
3. [Performance](#3-performance)
4. [SEO](#4-seo)
5. [Resume des fichiers modifies](#5-resume)

---

## 1. Securite

### 1.1 Content Security Policy (CSP)

**Fichier** : `khamareo-african-wellness/index.html`

Meta tag CSP restrictif qui controle toutes les sources de ressources :

```
default-src 'self'
script-src  'self' upload-widget.cloudinary.com js.stripe.com widget.cloudinary.com www.instagram.com
style-src   'self' 'unsafe-inline' fonts.googleapis.com
img-src     'self' data: blob: res.cloudinary.com *.stripe.com *.instagram.com
font-src    'self' fonts.gstatic.com
connect-src 'self' *.ngrok-free.dev *.ngrok-free.app api.cloudinary.com api.stripe.com *.instagram.com
frame-src   js.stripe.com www.instagram.com
object-src  'none'
base-uri    'self'
form-action 'self'
```

### 1.2 Protection XSS — DOMPurify (Frontend)

**Fichier** : `khamareo-african-wellness/src/utils/sanitize.ts`

Toute injection HTML via `dangerouslySetInnerHTML` passe par DOMPurify :

- **Tags autorises** : p, br, strong, b, em, i, u, s, h1-h6, ul, ol, li, a, img, blockquote, pre, code, table, span, div, hr, figure, figcaption
- **Attributs autorises** : href, target, rel, src, alt, title, class, style, width, height, loading
- **Data attributes** : bloques (`ALLOW_DATA_ATTR: false`)

**Pages protegees** : BlogDetail, ProductDetail, Hero, ProductViewModal, BlogEditor

### 1.3 Protection XSS — HTML Sanitizer (Backend)

**Fichier** : `khamareo-backend/config/packages/html_sanitizer.yaml`

Le contenu blog est sanitize cote serveur via `symfony/html-sanitizer` avant stockage :

- Remplace `strip_tags()` dans `BlogPostProcessor.php`
- Allowlist complete avec controle granulaire par element/attribut
- Injecte via `#[Autowire(service: 'html_sanitizer.sanitizer.blog.sanitizer')]`

### 1.4 Rate Limiting

**Fichiers** :
- `khamareo-backend/config/packages/rate_limiter.yaml`
- `khamareo-backend/src/Security/RateLimit/ApiRateLimiterSubscriber.php`

11 limiteurs configures, tous Redis-backed (fixed_window) :

| Limiteur | Limite | Fenetre | Routes protegees |
|----------|--------|---------|------------------|
| `login_limiter` | 10 req | 1 min | `/api/auth` |
| `registration_limiter` | 5 req | 15 min | `/api/users`, `/api/users/convert-guest` |
| `password_reset_limiter` | 5 req | 15 min | `/api/forgot-password`, `/api/reset-password` |
| `contact_limiter` | 3 req | 15 min | `/api/contact_messages` |
| `review_limiter` | 5 req | 15 min | `/api/reviews` |
| `checkout_limiter` | 10 req | 15 min | `/api/orders`, `/api/checkout` |
| `newsletter_limiter` | 5 req | 15 min | Newsletter endpoints |
| `search_limiter` | 30 req | 1 min | Search endpoints |
| `consent_limiter` | 30 req | 1 h | `/api/public/consent` |
| `retractation_limiter` | 5 req | 1 h | Retractation endpoints |
| `resend_confirmation_limiter` | 5 req | 15 min | `/api/resend-confirmation` |

**Cle** : contexte + IP + email (lowercase, trimmed)
**Reponse** : HTTP 429 avec header `Retry-After`
**Tests** : desactives via `config/packages/test/rate_limiter.yaml` (no_limit)

### 1.5 Authentification JWT

**Fichiers** :
- `config/packages/lexik_jwt_authentication.yaml`
- `config/packages/gesdinet_jwt_refresh_token.yaml`

| Parametre | Valeur |
|-----------|--------|
| JWT TTL | **900s (15 min)** |
| Refresh token TTL | 30 jours |
| Refresh token single-use | `true` |
| Refresh token cookie httpOnly | `true` |
| Refresh token cookie secure | `true` |
| Refresh token cookie sameSite | `none` |
| Token retire du body de reponse | `true` |

Le refresh token n'est **jamais** stocke en localStorage — uniquement en cookie httpOnly.

### 1.6 Validation mot de passe

**Fichiers** :
- `src/User/Entity/User.php` (plainPassword)
- `src/User/Dto/ChangePasswordRequest.php` (newPassword)

Contraintes identiques sur les deux :

```
- Minimum 8 caracteres
- Au moins 1 majuscule [A-Z]
- Au moins 1 minuscule [a-z]
- Au moins 1 chiffre [0-9]
```

### 1.7 CORS

**Fichier** : `.env.local` — `CORS_ALLOW_ORIGIN`

Domaines lovable.app et lovableproject.com supprimes. Seuls localhost et le domaine de prod sont autorises.

### 1.8 Header ngrok conditionnel

**Fichier** : `khamareo-african-wellness/src/services/api.ts`

Le header `ngrok-skip-browser-warning` n'est envoye qu'en mode dev :

```ts
...(import.meta.env.DEV && { "ngrok-skip-browser-warning": "true" })
```

---

## 2. Monitoring

### 2.1 Healthcheck API

**Fichier** : `src/Shared/Controller/HealthController.php`
**Route** : `GET /api/health` (PUBLIC_ACCESS)

| Check | Methode | Bloquant |
|-------|---------|----------|
| Database | `SELECT 1` | Oui (503 si KO) |
| Redis | Predis `PING` (timeout 1s) | Non (unavailable) |

**Reponse** :
```json
{
  "status": "ok",
  "timestamp": "2026-03-06T00:14:28+01:00",
  "checks": { "database": "ok", "redis": "ok" }
}
```

- HTTP 200 si `status: ok`
- HTTP 503 si `status: degraded`

### 2.2 Docker Healthchecks

**Fichier** : `docker-compose.yml`

| Service | Commande | Intervalle | Retries |
|---------|----------|------------|---------|
| **nginx** | `wget -qO- http://localhost/api/health` | 30s | 3 |
| **postgres** | `pg_isready -U khamareo` | 10s | 5 |
| **redis** | `redis-cli ping` | 10s | 5 |

### 2.3 Sentry — Backend (Symfony)

**Fichiers** :
- `config/packages/sentry.yaml`
- `config/bundles.php` (SentryBundle)
- `.env` : `SENTRY_DSN=`

| Parametre | Valeur |
|-----------|--------|
| DSN | Variable d'environnement `SENTRY_DSN` |
| Environment | `%kernel.environment%` |
| Send PII | `true` |
| Traces sample rate | `0.2` (20%) |

**Test** : `docker compose exec php bin/console sentry:test`

### 2.4 Sentry — Frontend (React)

**Fichiers** :
- `src/main.tsx` (initialisation)
- `src/App.tsx` (ErrorBoundary)
- `.env` : `VITE_SENTRY_DSN=https://...`

| Parametre | Valeur |
|-----------|--------|
| DSN | Variable `VITE_SENTRY_DSN` |
| Browser Tracing | Active |
| Session Replay | Desactive (0%) |
| Error Replay | **100%** (chaque erreur est rejouee) |
| Traces sample rate | 0.2 (20%) |
| Send PII | `true` |
| ErrorBoundary | Wrappe toute l'app |

Sentry est **inactif** si `VITE_SENTRY_DSN` est vide (pas d'impact en dev).

---

## 3. Performance

### 3.1 Code Splitting (Vite)

**Fichier** : `khamareo-african-wellness/vite.config.ts`

60+ routes chargees en lazy (`React.lazy`) + 6 chunks vendor separes :

| Chunk | Contenu |
|-------|---------|
| `vendor-react` | react, react-dom, react-router-dom |
| `vendor-ui` | @radix-ui (dialog, dropdown, tabs, tooltip) |
| `vendor-framer` | framer-motion |
| `vendor-charts` | recharts |
| `vendor-stripe` | @stripe/stripe-js, @stripe/react-stripe-js |
| `vendor-query` | @tanstack/react-query |

5 pages critiques restent en chargement eager : Index, Boutique, ProductDetail, Cart, NotFound.

### 3.2 TanStack Query — Configuration globale

**Fichier** : `khamareo-african-wellness/src/App.tsx`

```ts
staleTime: 2 * 60_000   // 2 min — evite les refetch sur navigation
gcTime: 10 * 60_000     // 10 min — garde en cache memoire
refetchOnWindowFocus: false
retry: 1
```

### 3.3 CartContext optimise

**Fichier** : `khamareo-african-wellness/src/contexts/CartContext.tsx`

La `value` du Provider est wrappee dans `useMemo` pour eviter les re-renders en cascade.

### 3.4 OPcache (PHP)

**Fichier** : `khamareo-backend/.docker/php/php.ini`

| Parametre | Valeur |
|-----------|--------|
| Memoire | 256 Mo |
| Interned strings | 16 Mo |
| Max fichiers | 20 000 |
| Validate timestamps | 1 (dev), 0 (prod) |
| Revalidate freq | 2s |

### 3.5 Redis Cache

**Fichier** : `config/packages/cache.yaml`

Tout le cache framework utilise Redis (`redis://redis:6379`) :

- `cache.app` → Redis
- `app.rate_limiter.storage` → Redis
- `doctrine.result_cache_pool` → Redis
- `doctrine.system_cache_pool` → Redis

### 3.6 Doctrine Cache (Production)

**Fichier** : `config/packages/doctrine.yaml` (section `when@prod`)

| Cache | Pool |
|-------|------|
| Metadata cache | `doctrine.system_cache_pool` (Redis) |
| Query cache | `doctrine.system_cache_pool` (Redis) |
| Result cache | `doctrine.result_cache_pool` (Redis) |
| Auto-generate proxies | `false` |

### 3.7 Gzip Compression (Nginx)

**Fichier** : `.docker/nginx/default.conf`

| Parametre | Valeur |
|-----------|--------|
| Niveau | 6 |
| Taille min | 1024 octets |
| Types | JSON, LD+JSON, JS, CSS, XML, texte |
| Vary | Active |

### 3.8 Upload size

**Fichier** : `.docker/nginx/default.conf`

`client_max_body_size 25M` — aligne avec `post_max_size` PHP.

### 3.9 Index SQL

**Fichiers** : Product.php, Order.php, ProductMedia.php

| Index | Table | Colonnes |
|-------|-------|----------|
| `idx_product_slug` | product | slug |
| `idx_product_active` | product | is_enabled, is_deleted |
| `idx_product_category` | product | category_id |
| `idx_order_status` | order | status |
| `idx_order_owner` | order | owner_id |
| `idx_order_created` | order | created_at |
| `idx_order_number` | order | order_number |
| `idx_pm_product_primary` | product_media | product_id, is_primary |

### 3.10 Parallax optimise

**Fichier** : `khamareo-african-wellness/src/components/animations/ParallaxSection.tsx`

Remplacement de `background-attachment: fixed` par `willChange: transform` pour eviter le jank mobile.

### 3.11 Supabase supprime

Package `@supabase/supabase-js` supprime (code mort).

---

## 4. SEO

### 4.1 Sitemap dynamique

**Fichier** : `src/Shared/Controller/SitemapController.php`
**Route** : `GET /sitemap.xml`

Generation dynamique depuis la base de donnees :

| Type | Priorite | Frequence | Source |
|------|----------|-----------|--------|
| Pages statiques (8) | 0.3 - 1.0 | daily/monthly/yearly | Hard-coded |
| Produits | 0.8 | weekly | `product` (enabled, not deleted) |
| Categories | 0.7 | weekly | `category` (enabled) |
| Articles blog | 0.7 | monthly | `blog_post` (published) |

**Total actuel** : 183 URLs
**Cache** : `Cache-Control: public, max-age=3600` (1h)

### 4.2 JSON-LD Structured Data

**Fichier** : `khamareo-african-wellness/src/components/seo/JsonLd.tsx`

| Schema | Page | Donnees |
|--------|------|---------|
| `Organization` | index.html (statique) | nom, logo, URL, Instagram |
| `Product` | ProductDetail.tsx | nom, prix, dispo, image, rating, avis |
| `BreadcrumbList` | ProductDetail, BlogDetail | fil d'Ariane avec positions |
| `Article` | BlogDetail.tsx | titre, date, auteur, image, editeur |

**Rich snippets Google attendus** :
- Etoiles + prix sur les fiches produit
- Date + auteur sur les articles blog
- Fil d'Ariane sous les resultats

### 4.3 Meta Tags dynamiques

**Fichier** : `khamareo-african-wellness/src/hooks/useSeo.ts`

Hook React qui met a jour par page :

| Meta | Source |
|------|--------|
| `<title>` | `[Titre] \| Khamareo` |
| `meta[description]` | Description custom ou defaut |
| `meta[robots]` | index,follow / noindex,nofollow |
| `og:title, og:description, og:image, og:type, og:url` | Dynamique |
| `twitter:title, twitter:description, twitter:image` | Dynamique |
| `link[rel=canonical]` | **Nouveau** — URL canonique par page |

### 4.4 Canonical URLs

Chaque page a maintenant un `<link rel="canonical">` :
- Par defaut : `https://khamareo.com` + pathname courante
- Surchargeable via `canonicalPath` dans `useSeo()`
- `og:url` synchronise avec le canonical

### 4.5 robots.txt

**Fichier** : `khamareo-african-wellness/public/robots.txt`

```
User-agent: *
Allow: /
Disallow: /admin
Disallow: /account
Disallow: /checkout

Sitemap: https://khamareo.com/sitemap.xml
```

### 4.6 Cache-Control sur endpoints publics

| Endpoint | max-age (client) | s-maxage (CDN) |
|----------|-------------------|----------------|
| `GET /api/catalog/products` | 2 min | 5 min |
| `GET /api/catalog/products/{slug}` | 5 min | 10 min |
| `GET /api/menu/categories/menu` | 10 min | 30 min |
| `GET /api/search/suggestions` | 1 min | — |
| `GET /sitemap.xml` | 1 h | — |

### 4.7 Meta tags statiques (index.html)

Deja presents avant les optimisations :
- Open Graph complet (site_name, title, description, type, url, image, locale)
- Twitter Card (summary_large_image, site, title, description, image)
- `<html lang="fr">`
- `<meta name="robots" content="index, follow">`

---

## 5. Resume

### Fichiers crees

| Fichier | Categorie |
|---------|-----------|
| `khamareo-african-wellness/src/utils/sanitize.ts` | Securite |
| `khamareo-backend/config/packages/html_sanitizer.yaml` | Securite |
| `khamareo-backend/config/packages/sentry.yaml` | Monitoring |
| `khamareo-backend/src/Shared/Controller/SitemapController.php` | SEO |
| `khamareo-african-wellness/src/components/seo/JsonLd.tsx` | SEO |

### Fichiers modifies

| Fichier | Categories |
|---------|------------|
| `khamareo-african-wellness/index.html` | Securite (CSP), SEO (JSON-LD) |
| `khamareo-african-wellness/src/main.tsx` | Monitoring (Sentry) |
| `khamareo-african-wellness/src/App.tsx` | Monitoring (ErrorBoundary) |
| `khamareo-african-wellness/src/hooks/useSeo.ts` | SEO (canonical) |
| `khamareo-african-wellness/src/pages/ProductDetail.tsx` | Securite (DOMPurify), SEO (JSON-LD) |
| `khamareo-african-wellness/src/pages/BlogDetail.tsx` | Securite (DOMPurify), SEO (JSON-LD) |
| `khamareo-african-wellness/src/components/Hero.tsx` | Securite (DOMPurify) |
| `khamareo-african-wellness/src/pages/admin/BlogEditor.tsx` | Securite (DOMPurify) |
| `khamareo-african-wellness/src/components/admin/ProductViewModal.tsx` | Securite (DOMPurify) |
| `khamareo-african-wellness/src/services/auth.ts` | Securite (refresh token) |
| `khamareo-african-wellness/src/services/api.ts` | Securite (ngrok header) |
| `khamareo-african-wellness/vite.config.ts` | Performance (chunks) |
| `khamareo-african-wellness/src/contexts/CartContext.tsx` | Performance (useMemo) |
| `khamareo-african-wellness/src/components/animations/ParallaxSection.tsx` | Performance |
| `khamareo-african-wellness/public/robots.txt` | SEO |
| `khamareo-backend/docker-compose.yml` | Monitoring (healthchecks) |
| `khamareo-backend/.docker/nginx/default.conf` | Performance (gzip, upload) |
| `khamareo-backend/.docker/php/php.ini` | Performance (OPcache) |
| `khamareo-backend/config/packages/rate_limiter.yaml` | Securite |
| `khamareo-backend/config/packages/cache.yaml` | Performance (Redis) |
| `khamareo-backend/config/packages/doctrine.yaml` | Performance (cache prod) |
| `khamareo-backend/config/packages/lexik_jwt_authentication.yaml` | Securite (TTL) |
| `khamareo-backend/config/packages/gesdinet_jwt_refresh_token.yaml` | Securite |
| `khamareo-backend/config/packages/security.yaml` | Securite, Monitoring |
| `khamareo-backend/config/bundles.php` | Monitoring (Sentry) |
| `khamareo-backend/src/Security/RateLimit/ApiRateLimiterSubscriber.php` | Securite |
| `khamareo-backend/src/Blog/State/BlogPostProcessor.php` | Securite |
| `khamareo-backend/src/User/Entity/User.php` | Securite, Performance (index) |
| `khamareo-backend/src/User/Dto/ChangePasswordRequest.php` | Securite |
| `khamareo-backend/src/Order/Entity/Order.php` | Performance (index) |
| `khamareo-backend/src/Media/Entity/ProductMedia.php` | Performance (index) |
| `khamareo-backend/src/Catalog/Entity/Product.php` | Performance (index) |
| `khamareo-backend/src/Catalog/Controller/ProductCatalogController.php` | SEO (Cache-Control) |
| `khamareo-backend/src/Catalog/Controller/CategoryMenuController.php` | SEO (Cache-Control) |
| `khamareo-backend/src/Shared/Controller/HealthController.php` | Monitoring |
