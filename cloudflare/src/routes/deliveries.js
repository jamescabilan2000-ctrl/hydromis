import { Hono } from 'hono'
import { fail, ok, parseJson, requireAdmin, requireFields } from '../http.js'

const deliveries = new Hono()

const deliverySelect = `
  SELECT t.transaction_id, t.user_id, t.delivery_status, t.status, t.fulfillment_method,
         t.rider_id, t.assigned_rider, t.created_at, t.updated_at,
         u.full_name AS customer_name, u.contact_number, u.address AS destination,
         ru.full_name AS rider_name, ru.contact_number AS rider_contact_number,
         rl.rider_latitude, rl.rider_longitude, rl.accuracy, rl.speed, rl.heading,
         rl.last_update
  FROM transactions t
  JOIN users u ON u.user_id = t.user_id
  LEFT JOIN rider_users ru ON ru.rider_id = COALESCE(t.rider_id, t.assigned_rider)
  LEFT JOIN rider_locations rl ON rl.id = (
    SELECT recent.id FROM rider_locations recent
    WHERE recent.transaction_id = t.transaction_id
    ORDER BY recent.last_update DESC, recent.id DESC LIMIT 1
  )`

deliveries.get('/', async (c) => {
  const status = c.req.query('status')
  const query = status
    ? `${deliverySelect} WHERE t.delivery_status = ? ORDER BY t.created_at DESC LIMIT 50`
    : `${deliverySelect} WHERE t.delivery_status != 'delivered' ORDER BY t.created_at DESC LIMIT 50`
  const statement = status ? c.env.DB.prepare(query).bind(status) : c.env.DB.prepare(query)
  const result = await statement.all()
  return ok(c, { deliveries: result.results, count: result.results.length })
})

deliveries.get('/:transactionId', async (c) => {
  const row = await c.env.DB.prepare(`${deliverySelect} WHERE t.transaction_id = ? LIMIT 1`)
    .bind(c.req.param('transactionId'))
    .first()
  if (!row) return fail(c, 404, 'Delivery not found')
  return ok(c, { ...row, has_live_location: row.rider_latitude !== null })
})

deliveries.post('/:transactionId/location', requireAdmin, async (c) => {
  const body = await parseJson(c)
  const missing = requireFields(body, ['latitude', 'longitude'])
  if (missing.length) return fail(c, 400, 'Missing required fields', missing)

  const latitude = Number(body.latitude)
  const longitude = Number(body.longitude)
  if (!Number.isFinite(latitude) || latitude < -90 || latitude > 90 ||
      !Number.isFinite(longitude) || longitude < -180 || longitude > 180) {
    return fail(c, 400, 'Latitude or longitude is invalid')
  }

  const transactionId = c.req.param('transactionId')
  const transaction = await c.env.DB.prepare(
    'SELECT COALESCE(rider_id, assigned_rider) AS rider_id FROM transactions WHERE transaction_id = ?'
  ).bind(transactionId).first()
  if (!transaction) return fail(c, 404, 'Delivery not found')

  await c.env.DB.prepare(`
    INSERT INTO rider_locations
      (transaction_id, rider_id, rider_latitude, rider_longitude, accuracy, speed, heading, last_update)
    VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)`)
    .bind(transactionId, transaction.rider_id, latitude, longitude,
      body.accuracy ?? null, body.speed ?? null, body.heading ?? null)
    .run()

  return ok(c, { transaction_id: transactionId, latitude, longitude })
})

deliveries.patch('/:transactionId/status', requireAdmin, async (c) => {
  const body = await parseJson(c)
  const allowed = ['pending', 'assigned', 'preparing', 'on_way', 'delivered', 'cancelled']
  if (!allowed.includes(body?.status)) return fail(c, 400, 'Delivery status is invalid')

  const result = await c.env.DB.prepare(
    'UPDATE transactions SET delivery_status = ?, updated_at = CURRENT_TIMESTAMP WHERE transaction_id = ?'
  ).bind(body.status, c.req.param('transactionId')).run()
  if (!result.meta.changes) return fail(c, 404, 'Delivery not found')
  return ok(c, { transaction_id: c.req.param('transactionId'), delivery_status: body.status })
})

export default deliveries
