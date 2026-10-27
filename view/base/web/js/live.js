/**
 * The live half of the Commerce365 section, as plain functions of plain data.
 *
 * section.js reads the page and the bar's context into the shapes documented here and
 * hands them over, so everything that decides what the panel says can be tested with
 * node --test and no browser.
 */

/** The REST route Commerce365 prices load from, with or without a store code. */
const PRICE_PATH = /\/rest(?:\/[a-z0-9_]+)?\/V1\/customer-price$/i

/**
 * @typedef {object} Box one price box on the page
 * @property {string} productId
 * @property {string} state filled, unavailable, waiting (still a placeholder) or static (rendered by the server)
 * @property {boolean} uom shows a price per unit of measure
 */

/**
 * @typedef {object} PriceEvent as events.js records it
 * @property {number} at ms since navigation
 * @property {string} type
 * @property {string} productId
 * @property {number} rows
 * @property {Array<string>} ids
 * @property {string} [value]
 */

/**
 * The newest customer-price request the bar has seen on this page.
 *
 * @param {Array<{id: string, method: string, url: string, status: number}>} requests newest first
 * @returns {object|null}
 */
export function newestPriceRequest(requests) {
  return (requests || []).find((entry) => entry && entry.method === 'POST' && PRICE_PATH.test(entry.url || '')) || null
}

/**
 * @param {object|null} profile
 * @returns {boolean}
 */
export function isPriceProfile(profile) {
  return Boolean(profile && profile.sections && profile.sections.c365 && profile.sections.c365.payload
    && profile.sections.c365.payload.prices)
}

/**
 * The stored tabs of the price request, for a page profile that has none of its own.
 *
 * @param {object} priceProfile a customer-price profile, as the bar's profile URL serves it
 * @param {object} entry the request list entry it came from
 * @returns {Array<object>} tabs with the same ids as the stored view, so the bar merges them in
 */
export function linkedTabs(priceProfile, entry) {
  const view = priceProfile && priceProfile.sections && priceProfile.sections.c365 && priceProfile.sections.c365.view
  const tabs = view && Array.isArray(view.tabs) ? view.tabs : []
  const lead = {
    type: 'note',
    tone: entry.status >= 200 && entry.status < 300 ? 'ok' : 'bad',
    text: `From the customer-price request this page made (${entry.status}, profile ${priceProfile.id}).`,
  }

  return tabs.map((tab) => ({ ...tab, blocks: [lead, ...(tab.blocks || [])] }))
}

function tierCount(product, cacheRow) {
  const own = product.tier_rows || []

  return own.length ? own.length : ((cacheRow && cacheRow.tiers) || []).length
}

/**
 * Products offered for pricing against the boxes that show a price.
 *
 * @param {Array<Box>} boxes
 * @param {object|null} priceProfile
 * @returns {object} a tab
 */
export function coverageTab(boxes, priceProfile) {
  const payload = isPriceProfile(priceProfile) ? priceProfile.sections.c365.payload : null
  const prices = payload ? payload.prices : null
  // A list item has no tier rows of its own; the price cache row it was priced from does
  const cached = (payload && payload.cached) || {}
  const answered = new Map((prices ? prices.products : []).map((product) => [String(product.product_id), product]))
  const requested = new Set((prices ? prices.requested : []).map(String))
  const byProduct = new Map()

  boxes.forEach((box) => {
    const states = byProduct.get(box.productId) || []
    states.push(box.state)
    byProduct.set(box.productId, states)
  })

  const ids = new Set([...byProduct.keys(), ...requested])
  const rows = Array.from(ids).map((id) => {
    const states = byProduct.get(id) || []
    const product = answered.get(id)
    const shown = states.includes('filled') || states.includes('static')
    const gap = (requested.has(id) && !product) || states.includes('unavailable') || states.includes('waiting')

    return {
      product: id,
      boxes: String(states.length),
      state: states.length ? Array.from(new Set(states)).join(', ') : 'no box',
      requested: requested.has(id) ? 'yes' : 'no',
      answered: product ? 'yes' : (requested.has(id) ? { text: 'no', tone: 'bad' } : ''),
      shown: shown ? 'yes' : { text: 'no', tone: states.length ? 'warn' : '' },
      tiers: product ? String(tierCount(product, cached[id])) : '',
      uom: product && product.has_uom_in_html ? 'yes' : '',
      _tone: gap ? 'warn' : '',
      _target: id,
    }
  })

  const blocks = []

  if (!prices) {
    blocks.push({ type: 'note', text: 'No customer-price request yet, so only the boxes on the page are listed.' })
  }

  blocks.push({
    type: 'table',
    columns: ['product', { key: 'boxes', label: 'Boxes', num: true }, 'state', 'requested',
      'answered', 'shown', { key: 'tiers', label: 'Tiers', num: true }, 'uom'],
    rows,
    empty: 'No price boxes on this page, and nothing was requested.',
  })

  return { id: 'coverage', label: 'Coverage', count: rows.filter((row) => row._tone).length || '', blocks }
}

/**
 * @param {Array<PriceEvent>} events oldest first
 * @returns {object} a tab
 */
export function eventsTab(events) {
  const rows = (events || []).slice().reverse().map((event) => ({
    at: `${event.at} ms`,
    type: event.type,
    product: event.productId || '',
    rows: event.rows ? String(event.rows) : '',
    detail: event.value !== undefined ? event.value : (event.ids || []).join(', '),
    _target: event.productId || '',
  }))

  return {
    id: 'events',
    label: 'Events',
    count: rows.length || '',
    blocks: [{
      type: 'table',
      columns: [{ key: 'at', label: 'At', num: true }, { key: 'type', label: 'Event', mono: true }, 'product',
        { key: 'rows', label: 'Rows', num: true }, { key: 'detail', label: 'Products or value', mono: true }],
      rows,
      empty: 'No Commerce365 events on this page yet.',
    }],
  }
}

/**
 * @param {object} page
 * @param {object|null} page.settings the commerce365 key of #nft-globals, or null
 * @param {Array<Box>} page.boxes
 * @returns {object} a tab
 */
export function pageTab({ settings, boxes }) {
  const counts = boxes.reduce((all, box) => ({ ...all, [box.state]: (all[box.state] || 0) + 1 }), {})
  const productId = settings && settings.productId ? String(settings.productId) : ''

  const blocks = [{
    type: 'facts',
    items: [
      { label: 'Page', value: productId ? `Product ${productId}` : 'List or other' },
      {
        label: 'Customer prices',
        value: settings ? 'on, loaded by AJAX' : 'off: logged out, or AJAX pricing disabled',
        tone: settings ? 'ok' : '',
      },
      { label: 'Store', value: settings ? String(settings.storeId) : '-', mono: true },
      { label: 'Price URL', value: settings ? String(settings.url) : '-', mono: true },
      { label: 'Price boxes', value: String(boxes.length) },
      ...['filled', 'waiting', 'unavailable', 'static'].filter((state) => counts[state]).map((state) => ({
        label: state,
        value: String(counts[state]),
        tone: state === 'filled' ? 'ok' : (state === 'static' ? '' : 'warn'),
      })),
    ],
  }, {
    type: 'table',
    columns: ['product', 'state', 'uom'],
    rows: boxes.map((box) => ({
      product: box.productId,
      state: box.state === 'filled' || box.state === 'static' ? box.state : { text: box.state, tone: 'warn' },
      uom: box.uom ? 'yes' : '',
      _target: box.productId,
    })),
    empty: 'No price boxes on this page.',
  }]

  return { id: 'page', label: 'Page', blocks }
}
