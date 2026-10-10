import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

// rules/03 §3: RTL layouts use logical utilities only (ms/me/ps/pe/start/end/text-start/rounded-s/border-e…).
const PHYSICAL =
  "/(^|[\\s:!-])((m|p|scroll-m|scroll-p)[lr]|left|right|rounded-[lr]|rounded-[tb][lr]|border-[lr]|text-(left|right)|float-(left|right)|clear-(left|right))(-|\\s|$)/";
const message =
  "Physical direction class in an RTL app: use the logical one (ms-/me-/ps-/pe-/start-/end-/text-start/…), rules/03 §3.";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  {
    rules: {
      "no-restricted-syntax": [
        "error",
        { selector: `JSXAttribute[name.name='className'] Literal[value=${PHYSICAL}]`, message },
        { selector: `JSXAttribute[name.name='className'] TemplateElement[value.raw=${PHYSICAL}]`, message },
        { selector: `CallExpression[callee.name=/^(cn|cva)$/] Literal[value=${PHYSICAL}]`, message },
      ],
      "no-console": "error",
      "@typescript-eslint/no-explicit-any": "error",
    },
  },
  globalIgnores([
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
    "lib/api/schema.d.ts",
    "playwright-report/**",
    "test-results/**",
  ]),
]);

export default eslintConfig;
