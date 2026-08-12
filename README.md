# lightning — branch `cloudflare` (deploy Cloudflare Pages)

⚠️ **Questo branch contiene SOLO il frontend statico** ed è la sorgente
dell'auto-deploy su Cloudflare Pages. Il progetto completo (backend Symfony/PHP +
frontend) sta sul branch **`main`**.

## Deploy (Cloudflare Pages)

Collega questo repo a Pages e imposta come **production branch** → `cloudflare`.

| Campo | Valore |
|-------|--------|
| Framework preset | *None* (Vite) |
| Build command | `npm run build` |
| Build output directory | `out` |
| Production branch | `cloudflare` |
| (se serve) | env `NODE_VERSION=22` |

Ogni push su `cloudflare` avvia una build. Header di sicurezza e CSP in
`public/_headers`; etichetta di build in `public/version.txt` (da incrementare a
ogni pubblicazione).

## Backend

Il frontend chiama l'API REST e l'hub Mercure del backend, che gira **altrove**
(tuo server: FrankenPHP + Redis + PostgreSQL, vedi branch `main`). Prima del
deploy aggiorna in `public/_headers` la direttiva CSP `connect-src` con l'origin
reale del backend, e nel codice l'URL dell'API se diverso da `/api`.

## Sviluppo locale

```bash
npm install
npm run dev            # http://localhost:5173 (proxy /api verso :8080)
npm run build          # genera out/
```

Demo senza backend: apri con `?demo` per popolare la mappa con dati **fittizi**.

Dati fulmini: **Blitzortung.org and contributors**.
