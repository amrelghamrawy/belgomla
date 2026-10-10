import type { NextConfig } from "next";

// `/api/*` and `/sanctum/*` are forwarded to Laravel by app/api and app/sanctum route handlers
// (lib/server/backend-proxy.ts), which keep the tenant Host header; see rules/03 §4.
const baseDomain = process.env.TENANT_BASE_DOMAIN ?? "gomal.test";

const nextConfig: NextConfig = {
  cacheComponents: true,
  partialPrefetching: true,
  // The dev server is opened on tenant hosts (alnoor.gomal.test:3000), not localhost.
  allowedDevOrigins: [`*.${baseDomain}`],
};

export default nextConfig;
