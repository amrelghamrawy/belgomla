import { proxyToBackend } from "@/lib/server/backend-proxy";

// Product images and logos (D61): same-origin `/storage/...` forwarded to Laravel, like app/api. In production
// Nginx serves the path from the storage folder, so this handler only serves `next dev` / a bare `next start`.
function proxy(request: Request): Promise<Response> {
  return proxyToBackend(request);
}

export { proxy as GET };
