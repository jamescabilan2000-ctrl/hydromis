import { Hono } from 'hono'
import { fail, ok, requireAdmin } from '../http.js'

const notifications = new Hono()

notifications.get('/:userId', async (c) => {
  const result = await c.env.DB.prepare(`
    SELECT id, transaction_id, title, message, notification_type, created_at
    FROM user_notifications
    WHERE user_id = ? AND is_read = 0
    ORDER BY created_at ASC LIMIT 20`)
    .bind(c.req.param('userId'))
    .all()
  return ok(c, { notifications: result.results })
})

notifications.post('/:userId/:notificationId/read', requireAdmin, async (c) => {
  const result = await c.env.DB.prepare(
    'UPDATE user_notifications SET is_read = 1 WHERE id = ? AND user_id = ?'
  ).bind(c.req.param('notificationId'), c.req.param('userId')).run()
  if (!result.meta.changes) return fail(c, 404, 'Notification not found')
  return ok(c, { read: true })
})

export default notifications
