import { test } from 'node:test'
import assert from 'node:assert/strict'
import { coverageTab, eventsTab, linkedTabs, newestPriceRequest, pageTab } from '../view/frontend/web/js/live.js'

const priceProfile = {
  id: 'p2',
  sections: {
    c365: {
      summary: { priced_count: 2 },
      payload: {
        prices: {
          requested: [2, 7, 9],
          products: [
            { product_id: 2, tier_rows: [], has_uom_in_html: true },
            { product_id: 7, tier_rows: [{}, {}], has_uom_in_html: false },
          ],
        },
      },
      view: { tabs: [{ id: 'prices', label: 'Prices', blocks: [{ type: 'table' }] }] },
    },
  },
}

test('the newest customer-price POST is picked, with or without a store code', () => {
  const requests = [
    { id: 'a', method: 'GET', url: '/rest/default_nl/V1/customer-price', status: 200 },
    { id: 'b', method: 'POST', url: 'https://shop.test/rest/default_nl/V1/customer-price', status: 200 },
    { id: 'c', method: 'POST', url: '/rest/V1/customer-price', status: 200 },
  ]

  assert.equal(newestPriceRequest(requests).id, 'b')
  assert.equal(newestPriceRequest([{ id: 'x', method: 'POST', url: '/rest/V1/carts/mine' }]), null)
})

test('the price request tabs keep their ids, so the bar merges them below the stored ones', () => {
  const tabs = linkedTabs(priceProfile, { id: 'p2', status: 200 })

  assert.equal(tabs[0].id, 'prices')
  assert.equal(tabs[0].blocks[0].tone, 'ok')
  assert.match(tabs[0].blocks[0].text, /profile p2/)
  assert.equal(tabs[0].blocks[1].type, 'table')
})

test('coverage flags a product that was asked for and never answered', () => {
  const tab = coverageTab([
    { productId: '2', state: 'filled', uom: true },
    { productId: '7', state: 'filled', uom: false },
    { productId: '9', state: 'unavailable', uom: false },
  ], priceProfile)
  const rows = Object.fromEntries(tab.blocks[0].rows.map((row) => [row.product, row]))

  assert.equal(rows['2']._tone, '')
  assert.equal(rows['2'].uom, 'yes')
  assert.equal(rows['7'].tiers, '2')
  assert.deepEqual(rows['9'].answered, { text: 'no', tone: 'bad' })
  assert.equal(rows['9']._tone, 'warn')
  assert.equal(tab.count, 1)
})

test('coverage without a price request still lists the boxes, and says why', () => {
  const tab = coverageTab([{ productId: '5', state: 'waiting', uom: false }], null)

  assert.equal(tab.blocks[0].type, 'note')
  assert.equal(tab.blocks[1].rows[0].state, 'waiting')
})

test('events are listed newest first', () => {
  const tab = eventsTab([
    { at: 10, type: 'commerce365:prices-updated', productId: '', rows: 3, ids: ['1', '2', '3'] },
    { at: 50, type: 'field:qty', productId: '', rows: 0, ids: [], value: '4' },
  ])

  assert.equal(tab.blocks[0].rows[0].detail, '4')
  assert.equal(tab.blocks[0].rows[1].detail, '1, 2, 3')
  assert.equal(tab.count, 2)
})

test('the page tab says when customer prices are off', () => {
  const off = pageTab({ settings: null, boxes: [] })
  const on = pageTab({
    settings: { url: '/rest/default_nl/V1/customer-price', storeId: '1', productId: '2' },
    boxes: [{ productId: '2', state: 'filled', uom: false }, { productId: '3', state: 'waiting', uom: false }],
  })

  assert.match(off.blocks[0].items[1].value, /^off/)
  assert.equal(on.blocks[0].items[0].value, 'Product 2')
  assert.deepEqual(on.blocks[1].rows[1].state, { text: 'waiting', tone: 'warn' })
})
