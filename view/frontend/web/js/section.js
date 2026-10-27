/**
 * The live half of the Commerce365 section: the page's price boxes, the price events, and
 * the customer-price request this page made, loaded so the developer can stay on the page.
 *
 * Everything that decides what the panel says is in live.js. This file only reads the page
 * and the bar's context.
 */
import { coverageTab, eventsTab, isPriceProfile, linkedTabs, newestPriceRequest, pageTab } from './live.js'

const BOX = '[data-role="priceBox"][data-product-id]'
const outlined = new Map()

/** @type {{id: string, profile: object}|null} the price profile last loaded, kept so a refresh does not refetch it */
let loaded = null

function settings() {
  try {
    const island = document.getElementById('nft-globals')
    const globals = island ? JSON.parse(island.textContent || '{}') : {}

    return globals.commerce365 && globals.commerce365.url ? globals.commerce365 : null
  } catch {
    return null
  }
}

function boxes() {
  return Array.from(document.querySelectorAll(BOX)).map((box) => ({
    productId: String(box.dataset.productId),
    state: box.dataset.commerce365Price
      || (box.querySelector('.commerce365-price-placeholder') ? 'waiting' : 'static'),
    uom: Boolean(box.querySelector('.commerce365-price-per-uom')),
  }))
}

async function priceProfile(context) {
  if (isPriceProfile(context.profile)) return null

  const entry = newestPriceRequest(context.requests)

  if (!entry) return null
  if (!loaded || loaded.id !== entry.id) loaded = { id: entry.id, entry, profile: await context.loadProfile(entry.id) }

  return loaded
}

window.__siteationDebugBar.registerSection({
  id: 'c365',
  async read(context) {
    const found = await priceProfile(context)
    const page = boxes()
    const linked = found ? linkedTabs(found.profile, found.entry) : []
    const forCoverage = found ? found.profile : (isPriceProfile(context.profile) ? context.profile : null)
    const tabs = [
      ...linked,
      coverageTab(page, forCoverage),
      eventsTab((window.__c365DebugBar && window.__c365DebugBar.events) || []),
      pageTab({ settings: settings(), boxes: page }),
    ]
    const priced = found ? found.profile.sections.c365.summary.priced_count : undefined

    return { tabs, ...(priced ? { count: priced } : {}) }
  },
  watch(refresh) {
    let queued = 0
    const soon = () => {
      cancelAnimationFrame(queued)
      queued = requestAnimationFrame(() => refresh())
    }

    window.addEventListener('commerce365:prices-updated', soon)
    if (window.__c365DebugBar) window.__c365DebugBar.onEvent = soon
  },
  highlight(target, on) {
    document.querySelectorAll(`[data-role="priceBox"][data-product-id="${CSS.escape(target)}"]`).forEach((box) => {
      if (on) {
        outlined.set(box, box.style.outline)
        box.style.outline = '2px solid #7f9cf5'
        box.style.outlineOffset = '2px'
      } else if (outlined.has(box)) {
        box.style.outline = outlined.get(box)
        box.style.outlineOffset = ''
        outlined.delete(box)
      }
    })
  },
})
