import app from '../../../src/index.js'

export const onRequest = (context) => app.fetch(context.request, context.env, context)
