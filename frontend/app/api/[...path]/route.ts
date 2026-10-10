import { proxyToBackend } from "@/lib/server/backend-proxy";

// Forwards to Laravel with the tenant Host intact (lib/server/backend-proxy.ts). In production Nginx
// routes this path to PHP-FPM first, so this handler only serves `next dev` / a bare `next start`.
// Next calls handlers as (request, context): pass only the request, never the route context.
function proxy(request: Request): Promise<Response> {
  return proxyToBackend(request);
}

export { proxy as DELETE, proxy as GET, proxy as PATCH, proxy as POST, proxy as PUT };
