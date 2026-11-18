import react from "@vitejs/plugin-react";
import { defineConfig } from "vitest/config";

export default defineConfig({
  plugins: [react()],
  resolve: { tsconfigPaths: true },
  test: {
    environment: "jsdom",
    globals: true,
    setupFiles: ["./tests/unit/setup.ts"],
    include: ["tests/unit/**/*.test.{ts,tsx}"],
    restoreMocks: true,
    unstubGlobals: true,
    // rules/01-testing.md: the floor is the coverage measured when it was introduced; raise it, never lower it.
    coverage: {
      provider: "v8",
      include: ["app/**", "components/**", "lib/**"],
      exclude: ["**/*.d.ts"],
      reporter: ["text-summary"],
      thresholds: { lines: 85, statements: 85, branches: 81, functions: 79 },
    },
  },
});
