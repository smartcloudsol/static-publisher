import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const source = await readFile(new URL("../src/main.tsx", import.meta.url), "utf8");

test("standard jobs expose guarded content-sync with target and credentials", () => {
  assert.match(
    source,
    /value:\s*"content-sync",\s*label:\s*"content-sync"/,
    "the standard command selector must include content-sync",
  );
  assert.match(
    source,
    /commandSupportsDeploymentProfile[\s\S]{0,240}command === "content-sync"/,
    "content-sync must accept the base or a named deployment target",
  );
  assert.match(
    source,
    /const supportsAwsCreds =[\s\S]{0,220}command === "content-sync"/,
    "content-sync must carry optional temporary deployment credentials",
  );
  assert.match(
    source,
    /command !== "content-sync" \|\|[\s\S]{0,100}hasIncrementalAccess/,
    "standard content-sync must remain subscription-gated",
  );
  assert.match(
    source,
    /contentSyncRuleId/,
    "the standard job form must retain the selected content-sync rule ID",
  );
  assert.match(
    source,
    /contentSyncRuleId:\s*command === "content-sync"/,
    "the standard job request must submit the exact content-sync rule ID",
  );
  assert.match(
    source,
    /Standard content-sync requires an exact enabled rule ID for the selected target/,
    "the UI must explain exact target-and-rule resolution",
  );
  assert.match(
    source,
    /item\.command !== "content-sync" && \(/,
    "content-sync must not offer an out-of-band config replay without its runtime state",
  );
});
