# Mappa Fulmini Live — Blitzortung

Interfaccia web per visualizzare in tempo reale i fulmini dal progetto
comunitario **Blitzortung.org**, con archivio storico **privato**.

Stack: **Symfony 7.4 LTS · PHP 8.4 · FrankenPHP (con Mercure) · Redis ·
PostgreSQL/PostGIS · React + Leaflet**. Pensato per **Debian Trixie**.

> ⚠️ **Uso e licenza.** I dati sono di Blitzortung.org e dei suoi contributori.
> Questa app li **mostra** ai propri utenti; **non** li ridistribuisce come
> dataset/feed pubblico. L'archivio storico è privato e sta dietro
> autenticazione. Attribuzione sempre visibile in mappa. Per qualsiasi uso che
> vada oltre il privato/amatoriale, chiedere il permesso sul forum di
> Blitzortung. L'accesso ai dati grezzi è legittimo solo per i partecipanti
> (operatori di stazione).

---

## Architettura

```
  Blitzortung  ──wss──►  WORKER (ReactPHP/Pawl)  ──┬──►  Redis  (buffer live 15')
  (1 sola conn.)          decode LZW + filtro box   ├──►  Mercure ──► browser (SSE)
                                                     └──►  Redis queue ──► CONSUMER ──► PostgreSQL (archivio privato)

  Browser (React+Leaflet)  ──REST──►  /api/strikes/recent   (bootstrap)
                           ──SSE───►   Mercure hub            (real-time)
```

Due processi long-running (systemd), **mai** dentro una richiesta web:

- `app:blitzortung:worker` — tiene la connessione, decodifica, distribuisce.
- `app:archive:consume` — scarica la coda Redis su Postgres in batch.

---

## Setup su Debian Trixie

### 1. Pacchetti di sistema

```bash
sudo apt update
sudo apt install -y php8.4-cli php8.4-mbstring php8.4-redis php8.4-pgsql \
                    php8.4-xml php8.4-intl redis-server postgresql \
                    composer nodejs npm git
# FrankenPHP (binario statico ufficiale)
curl -fsSL https://frankenphp.dev/install.sh | sh
sudo mv frankenphp /usr/local/bin/
```

### 2. Database

```bash
sudo -u postgres psql -c "CREATE USER lightning WITH PASSWORD 'password';"
sudo -u postgres psql -c "CREATE DATABASE lightning OWNER lightning;"
# opzionale ma consigliato per query per raggio:
sudo -u postgres psql -d lightning -c "CREATE EXTENSION IF NOT EXISTS postgis;"
```

### 3. Backend

```bash
cd backend
cp .env.example .env.local     # <-- adatta DB, Redis, MERCURE_JWT_SECRET, box
composer install
php bin/console doctrine:migrations:migrate   # crea la tabella strike
```

### 4. Frontend

```bash
cd ../frontend
npm install
npm run dev        # dev server su http://localhost:5173 (proxy /api)
# per la produzione:
npm run build      # output in dist/, da servire con Caddy/Nginx o CDN
```

### 5. Avvio in sviluppo

```bash
# terminale 1 — API PHP (dev server integrato)
cd backend && php -S localhost:8080 -t public

# terminale 2 — worker
cd backend && php bin/console app:blitzortung:worker

# terminale 3 — consumer archivio
cd backend && php bin/console app:archive:consume

# terminale 4 — frontend
cd frontend && npm run dev
```

Apri http://localhost:5173

### 6. Produzione (systemd + FrankenPHP)

```bash
# app + hub Mercure
sudo MERCURE_JWT_SECRET='...' frankenphp run --config backend/Caddyfile

# servizi long-running
sudo cp backend/deploy/*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now blitzortung-worker archive-consumer
```

---

## Note tecniche

- **Il decoder** (`src/Service/BlitzortungDecoder.php`) è il pezzo delicato:
  lo stream WS è compresso LZW, non JSON in chiaro. Testato con round-trip.
- **L'endpoint WS è non ufficiale**: host multipli con failover e backoff
  esponenziale già inclusi nel worker. Può cambiare senza preavviso.
- **Bounding box**: di default filtra l'Italia. Cambia `BOX_*` in `.env.local`.
- **Redis** è solo buffer volatile (TTL). L'unica fonte di verità storica è
  Postgres.
- **Archivio da partecipante**: se hai le credenziali di stazione, puoi
  arricchire l'archivio anche dall'area `data.blitzortung.org/Data/Protected/`
  (non incluso qui: aggiungi un command dedicato).
