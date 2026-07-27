# HydroMIS Cloudflare migration

This folder is the Cloudflare-native replacement for the PHP runtime. It uses a
Hono Worker, D1, and static assets that can also be deployed to Cloudflare Pages.

## Local development

```powershell
npm install
npm run db:local
npm run dev
```

Mutating staff/admin routes require an `ADMIN_API_KEY` Wrangler secret. Public
registration, order creation, delivery tracking, and health routes are available
without it for compatibility with the current customer workflow.

## Deployment

After creating the D1 database, replace `local-hydromis` in `wrangler.jsonc` with
the returned database ID, apply remote migrations, set `ADMIN_API_KEY`, and deploy.
