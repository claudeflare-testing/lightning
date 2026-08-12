import { chromium } from 'playwright'
import { spawn } from 'node:child_process'
import { mkdirSync } from 'node:fs'
import http from 'node:http'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'
const PORT = 4321
const OUT = path.join(path.dirname(fileURLToPath(import.meta.url)), 'out')
const SHOTS = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'screenshots')
mkdirSync(SHOTS, { recursive: true })

const viewports = [
  { name: 'mobile', width: 390, height: 844 },
  { name: 'tablet', width: 820, height: 1180 },
  { name: 'fhd', width: 1920, height: 1080 },
  { name: 'qhd', width: 2560, height: 1440 },
  { name: 'uhd', width: 3840, height: 2160 },
]
const themes = ['dark', 'light']

// Static server minimale su out/ (con supporto _headers ignorato: solo file).
function serve() {
  const types = {
    '.html': 'text/html',
    '.js': 'text/javascript',
    '.css': 'text/css',
    '.png': 'image/png',
    '.txt': 'text/plain',
    '.svg': 'image/svg+xml',
  }
  return http
    .createServer((req, res) => {
      let p = decodeURIComponent(req.url.split('?')[0])
      if (p === '/') p = '/index.html'
      const fp = path.join(OUT, p)
      import('node:fs').then((fs) => {
        fs.readFile(fp, (err, data) => {
          if (err) {
            // SPA fallback
            fs.readFile(path.join(OUT, 'index.html'), (e2, idx) => {
              if (e2) { res.writeHead(404); res.end('nf'); return }
              res.writeHead(200, { 'content-type': 'text/html' }); res.end(idx)
            })
            return
          }
          res.writeHead(200, { 'content-type': types[path.extname(fp)] || 'application/octet-stream' })
          res.end(data)
        })
      })
    })
    .listen(PORT)
}

const server = serve()
const browser = await chromium.launch({ executablePath: CHROME })

for (const vp of viewports) {
  for (const theme of themes) {
    const ctx = await browser.newContext({
      viewport: { width: vp.width, height: vp.height },
      deviceScaleFactor: vp.name === 'uhd' || vp.name === 'qhd' ? 2 : 1,
      colorScheme: theme,
    })
    const page = await ctx.newPage()
    // demo = dati fittizi; forziamo il tema via localStorage prima del load.
    await page.addInitScript((t) => {
      try { localStorage.setItem('theme', t) } catch {}
    }, theme)
    await page.goto(`http://localhost:${PORT}/?demo`, { waitUntil: 'networkidle' })
    // lascia comparire i fulmini seminati + i tile
    await page.waitForTimeout(2500)
    const file = path.join(SHOTS, `${vp.name}-${theme}.png`)
    await page.screenshot({ path: file })
    console.log('shot', `${vp.name}-${theme}`)
    await ctx.close()
  }
}

await browser.close()
server.close()
console.log('done')
