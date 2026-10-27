/**
 * Records the storefront's Commerce365 price events, from before the theme's scripts run.
 *
 * Nebula_Commerce365Compat dispatches `commerce365:prices-updated` on window after every
 * customer-price response. This wraps window.dispatchEvent to keep a bounded list of those,
 * and of quantity and unit changes on the product page, for the section's Events tab. A
 * classic script, because the bar loads head scripts blocking and in order.
 *
 * Never changes an event and never throws into the page.
 */
(function () {
  if (window.__c365DebugBar) return

  var MAX = 500
  var state = { events: [] }
  window.__c365DebugBar = state

  function push(entry) {
    entry.at = Math.round(performance.now())
    state.events.push(entry)
    if (state.events.length > MAX) state.events.shift()
    if (typeof state.onEvent === 'function') state.onEvent(entry)
  }

  var dispatch = window.dispatchEvent
  window.dispatchEvent = function (event) {
    try {
      var type = event && event.type
      if (typeof type === 'string' && type.indexOf('commerce365:') === 0) {
        var detail = event.detail || {}
        var rows = Array.isArray(detail.rows) ? detail.rows : []
        push({
          type: type,
          productId: detail.productId ? String(detail.productId) : '',
          rows: rows.length,
          ids: rows.slice(0, 50).map(function (row) { return String(row && row.productId) }),
        })
      }
    } catch (error) {
      // Watching an event must never break it.
    }
    return dispatch.apply(this, arguments)
  }

  // The product page's per unit line follows the quantity and unit fields.
  document.addEventListener('change', function (event) {
    try {
      var field = event.target
      if (!field || !field.matches || !field.matches('#qty, [name="qty"], [data-commerce365-uom-field]')) return
      push({ type: 'field:' + (field.name || field.id || 'field'), productId: '', rows: 0, ids: [], value: String(field.value) })
    } catch (error) {
      // As above.
    }
  }, { capture: true, passive: true })
})()
