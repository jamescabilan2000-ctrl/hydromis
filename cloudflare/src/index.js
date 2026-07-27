import { Hono } from 'hono'
import { cors } from 'hono/cors'
import { secureHeaders } from 'hono/secure-headers'
import { fail, makeId, ok, parseJson, requireAdmin, requireFields } from './http.js'
import deliveries from './routes/deliveries.js'
import notifications from './routes/notifications.js'
import users from './routes/users.js'

const app = new Hono()

app.use('*', secureHeaders())
app.use('/api/*', cors({ origin: '*', allowMethods: ['GET', 'POST', 'PATCH', 'OPTIONS'] }))

app.get('/api/health', async (c) => {
  const tables = await c.env.DB.prepare(
    "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
  ).all()
  return ok(c, {
    service: 'HydroMIS Hono API',
    environment: c.env.APP_ENV,
    database: 'connected',
    tables: tables.results.map((row) => row.name),
    timestamp: new Date().toISOString()
  })
})

app.get('/api/dashboard', requireAdmin, async (c) => {
  const [usersResult, transactionsResult, activeResult, pendingPaymentsResult] = await c.env.DB.batch([
    c.env.DB.prepare('SELECT COUNT(*) AS count FROM users'),
    c.env.DB.prepare('SELECT COUNT(*) AS count FROM transactions'),
    c.env.DB.prepare("SELECT COUNT(*) AS count FROM transactions WHERE delivery_status NOT IN ('delivered', 'cancelled')"),
    c.env.DB.prepare("SELECT COUNT(*) AS count FROM payments WHERE payment_status = 'pending'")
  ])
  return ok(c, {
    users: usersResult.results[0].count,
    transactions: transactionsResult.results[0].count,
    active_deliveries: activeResult.results[0].count,
    pending_payments: pendingPaymentsResult.results[0].count
  })
})

app.route('/api/users', users)
app.route('/api/deliveries', deliveries)
app.route('/api/notifications', notifications)

app.post('/api/transactions', async (c) => {
  const body = await parseJson(c)
  const missing = requireFields(body, ['user_id', 'amount', 'description'])
  if (missing.length) return fail(c, 400, 'Missing required fields', missing)

  const user = await c.env.DB.prepare('SELECT status FROM users WHERE user_id = ?').bind(body.user_id).first()
  if (!user) return fail(c, 404, 'User not found')
  if (user.status !== 'approved') return fail(c, 403, 'User account is not approved')

  const transactionId = makeId('TRX')
  await c.env.DB.prepare(`
    INSERT INTO transactions
      (transaction_id, user_id, amount, description, quantity, fulfillment_method, payment_method)
    VALUES (?, ?, ?, ?, ?, ?, ?)`)
    .bind(transactionId, body.user_id, Number(body.amount), body.description,
      Number(body.quantity || 1), body.fulfillment_method || 'delivery', body.payment_method || 'cash')
    .run()
  return ok(c, { transaction_id: transactionId, status: 'pending' }, 201)
})

app.notFound((c) => c.req.path.startsWith('/api/')
  ? fail(c, 404, 'API route not found')
  : c.env.ASSETS.fetch(c.req.raw))

app.onError((error, c) => {
  console.error(error)
  return fail(c, 500, 'Unexpected server error')
})

export default app
