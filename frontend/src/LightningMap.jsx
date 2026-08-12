import { useEffect, useRef, useState } from 'react'
import L from 'leaflet'

// Durata in ms per cui un fulmine resta visibile prima di sparire (fade).
const FADE_MS = 5 * 60 * 1000

// Modalita' demo: dati FITTIZI, nessun backend. Attiva con ?demo nell'URL.
// Serve per screenshot/artefatti pubblici senza toccare dati reali.
const DEMO = new URLSearchParams(window.location.search).has('demo')

// Bounding box Italia (per generare i fulmini finti in demo).
const BOX = { minLat: 36, maxLat: 47, minLon: 6.7, maxLon: 18.5 }

// Tile per tema: lo scuro e' grigio (CARTO dark), non nero.
const TILES = {
  dark: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
  light: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
}
const TILE_ATTR = '&copy; OpenStreetMap &copy; CARTO'

function initialTheme() {
  try {
    const saved = localStorage.getItem('theme')
    if (saved === 'light' || saved === 'dark') return saved
  } catch {}
  // default: scuro (come da requisito r5), rispettando comunque il sistema
  return window.matchMedia?.('(prefers-color-scheme: light)').matches ? 'light' : 'dark'
}

export default function LightningMap() {
  const mapRef = useRef(null)
  const layerRef = useRef(null)
  const tileRef = useRef(null)
  const strikesRef = useRef(new Map()) // id -> { marker, ts }
  const [count, setCount] = useState(0)
  const [theme, setTheme] = useState(initialTheme)

  // Applica il tema all'<html> e persiste.
  useEffect(() => {
    document.documentElement.dataset.theme = theme
    try {
      localStorage.setItem('theme', theme)
    } catch {}
    // Aggiorna i tile se la mappa e' gia' pronta.
    if (mapRef.current && tileRef.current) {
      tileRef.current.setUrl(TILES[theme])
    }
  }, [theme])

  // Init mappa una volta sola.
  useEffect(() => {
    const map = L.map('map', { zoomControl: true, attributionControl: false }).setView(
      [42.5, 12.5],
      6,
    )
    const tile = L.tileLayer(TILES[theme], {
      maxZoom: 19,
      subdomains: 'abcd',
      attribution: TILE_ATTR,
    }).addTo(map)

    const layer = L.layerGroup().addTo(map)
    mapRef.current = map
    layerRef.current = layer
    tileRef.current = tile

    return () => map.remove()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Aggiunge un fulmine con animazione.
  const addStrike = (s) => {
    if (!layerRef.current) return
    const id = `${s.ts}:${(+s.lat).toFixed(4)}:${(+s.lon).toFixed(4)}`
    if (strikesRef.current.has(id)) return

    const marker = L.circleMarker([s.lat, s.lon], {
      radius: 8,
      color: '#ffe08a',
      weight: 2,
      fillColor: '#ffd23f',
      fillOpacity: 0.9,
    }).addTo(layerRef.current)

    strikesRef.current.set(id, { marker, ts: s.ts })
    setCount(strikesRef.current.size)
  }

  // Fade + rimozione periodica.
  useEffect(() => {
    const iv = setInterval(() => {
      const now = Date.now()
      for (const [id, { marker, ts }] of strikesRef.current) {
        const age = now - ts
        if (age > FADE_MS) {
          layerRef.current?.removeLayer(marker)
          strikesRef.current.delete(id)
        } else {
          const op = 1 - age / FADE_MS
          marker.setStyle({ fillOpacity: op * 0.9, opacity: op })
          marker.setRadius(4 + op * 6)
        }
      }
      setCount(strikesRef.current.size)
    }, 1000)
    return () => clearInterval(iv)
  }, [])

  // Sorgente dati: demo (finti) oppure reale (REST + Mercure).
  useEffect(() => {
    if (DEMO) {
      // Semina qualche fulmine subito + a intervalli, per avere una mappa viva.
      const seed = () => {
        const lat = BOX.minLat + Math.random() * (BOX.maxLat - BOX.minLat)
        const lon = BOX.minLon + Math.random() * (BOX.maxLon - BOX.minLon)
        addStrike({ ts: Date.now(), lat, lon })
      }
      for (let i = 0; i < 40; i++) {
        setTimeout(seed, i * 15)
      }
      const iv = setInterval(seed, 600)
      return () => clearInterval(iv)
    }

    let es
    async function connect() {
      try {
        const r = await fetch('/api/strikes/recent?seconds=900')
        const data = await r.json()
        data.strikes?.forEach(addStrike)
      } catch (e) {
        console.warn('bootstrap fallito', e)
      }
      try {
        const cfg = await (await fetch('/api/realtime/config')).json()
        const url = new URL(cfg.hub, window.location.origin)
        url.searchParams.append('topic', 'lightning/{geohash}')
        es = new EventSource(url, { withCredentials: true })
        es.onmessage = (ev) => {
          try {
            addStrike(JSON.parse(ev.data))
          } catch {}
        }
        es.onerror = () => console.warn('Mercure disconnesso, riconnessione automatica...')
      } catch (e) {
        console.warn('realtime non disponibile', e)
      }
    }
    connect()
    return () => es && es.close()
  }, [])

  return (
    <div className="map-root">
      <div id="map" className="map" />

      <button
        type="button"
        className="theme-toggle"
        onClick={() => setTheme((t) => (t === 'dark' ? 'light' : 'dark'))}
        aria-label={theme === 'dark' ? 'Passa al tema chiaro' : 'Passa al tema scuro'}
        title={theme === 'dark' ? 'Tema chiaro' : 'Tema scuro'}
      >
        {theme === 'dark' ? '☀︎' : '☾'}
      </button>

      <div className="panel" role="status" aria-live="polite">
        <span aria-hidden="true">⚡</span>
        <span>
          <span className="count">{count}</span> fulmini attivi
        </span>
      </div>

      <div className="attribution">Lightning data by Blitzortung.org and contributors</div>
    </div>
  )
}
