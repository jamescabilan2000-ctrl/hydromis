export const ok = (c, data, status = 200) => c.json({ success: true, data }, status)

export const fail = (c, status, message, details) =>
  c.json({ success: false, error: { message, ...(details ? { details } : {}) } }, status)

export const parseJson = async (c) => {
  try {
    return await c.req.json()
  } catch {
    return null
  }
}

export const requireFields = (body, fields) =>
  fields.filter((field) => body?.[field] === undefined || body[field] === null || body[field] === '')

export const makeId = (prefix) => `${prefix}-${crypto.randomUUID().replaceAll('-', '').slice(0, 16).toUpperCase()}`

export const requireAdmin = async (c, next) => {
  const configured = c.env.ADMIN_API_KEY
  const supplied = c.req.header('Authorization')?.replace(/^Bearer\s+/i, '')

  if (!configured) return fail(c, 503, 'ADMIN_API_KEY is not configured')
  if (!supplied || supplied !== configured) return fail(c, 401, 'Valid administrator credentials are required')

  await next()
}
