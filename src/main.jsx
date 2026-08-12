import React from 'react'
import { createRoot } from 'react-dom/client'
import LightningMap from './LightningMap.jsx'
import 'leaflet/dist/leaflet.css'
import './theme.css'

createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <LightningMap />
  </React.StrictMode>,
)
