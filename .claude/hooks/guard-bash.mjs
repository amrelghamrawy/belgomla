// PreToolUse guard for Bash / PowerShell: blocks the commands that skip the push gate
// (scripts/hooks/pre-push → scripts/preflight.sh). Prompts are requests; this is the wall.
// Exit 2 = block, stderr goes back to Claude as the reason.

import { readFileSync } from "node:fs";

const RULES = [
  [/--no-verify\b/, "`--no-verify` skips the pre-push gate. Fix what preflight reports instead."],
  [/\bSKIP_PREFLIGHT\s*=/, "SKIP_PREFLIGHT is the human's escape hatch, not Claude's."],
  [/core\.hooksPath/, "Changing core.hooksPath disables the tracked hooks in scripts/hooks/."],
];

let input;
try {
  input = JSON.parse(readFileSync(0, "utf8"));
} catch {
  process.exit(0); // not our payload: never block on a parse problem
}

const command = String(input?.tool_input?.command ?? "");
for (const [pattern, reason] of RULES) {
  if (pattern.test(command)) {
    process.stderr.write(`Blocked by .claude/hooks/guard-bash.mjs: ${reason}\n`);
    process.exit(2);
  }
}
process.exit(0);
