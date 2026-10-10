import type { NextConfig } from "next";

// `/api/*`, `/sanctum/*` and `/storage/*` are forwarded to Laravel by the app/api, app/sanctum and app/storage route handlers
// (lib/server/backend-proxy.ts), which keep the tenant Host header; see rules/03 §4.
const baseDomain = process.env.TENANT_BASE_DOMAIN ?? "gomal.test";

const nextConfig: NextConfig = {
  cacheComponents: true,
  partialPrefetching: true,
  // The dev server is opened on tenant hosts (alnoor.gomal.test:3000), not localhost.
  allowedDevOrigins: [`*.${baseDomain}`],
  // D61: product images and logos are same-origin `/storage/...` paths, optimised by next/image.
  images: {
    localPatterns: [{ pathname: "/storage/**", search: "" }],
  },
};

export default nextConfig;
