import { Hono } from 'hono'
import { fail, makeId, ok, parseJson, requireAdmin, requireFields } from '../http.js'

const users = new Hono()

users.get('/', requireAdmin, async (c) => {
  const status = c.req.query('status')
  const statement = status
    ? c.env.DB.prepare('SELECT user_id, full_name, address, contact_number, email, status, loyalty_points, created_at FROM users WHERE status = ? ORDER BY created_at DESC LIMIT 100').bind(status)
    : c.env.DB.prepare('SELECT user_id, full_name, address, contact_number, email, status, loyalty_points, created_at FROM users ORDER BY created_at DESC LIMIT 100')
  const result = await statement.all()
  return ok(c, { users: result.results, count: result.results.length })
})

users.post('/', async (c) => {
  const body = await parseJson(c)
  const missing = requireFields(body, ['full_name', 'address', 'contact_number'])
  if (missing.length) return fail(c, 400, 'Missing required fields', missing)

  const userId = makeId('USR')
  try {
    await c.env.DB.prepare(`
      INSERT INTO users (user_id, full_name, address, contact_number, email)
      VALUES (?, ?, ?, ?, ?)`)
      .bind(userId, body.full_name.trim(), body.address.trim(), body.contact_number.trim(), body.email?.trim() || null)
      .run()
  } catch (error) {
    if (String(error).includes('UNIQUE')) return fail(c, 409, 'Email is already registered')
    throw error
  }
  return ok(c, { user_id: userId, status: 'pending' }, 201)
})

users.patch('/:userId/status', requireAdmin, async (c) => {
  const body = await parseJson(c)
  if (!['pending', 'approved', 'denied'].includes(body?.status)) return fail(c, 400, 'Account status is invalid')
  const result = await c.env.DB.prepare('UPDATE users SET status = ? WHERE user_id = ?')
    .bind(body.status, c.req.param('userId')).run()
  if (!result.meta.changes) return fail(c, 404, 'User not found')
  return ok(c, { user_id: c.req.param('userId'), status: body.status })
})

export default users
