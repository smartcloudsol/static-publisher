import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";
const source = await readFile(new URL("../src/admin-page.ts", import.meta.url), "utf8");
const { outputText } = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } });
const { resolvePublisherAdminPage, requestedPublisherAdminPage } = await import(`data:text/javascript;base64,${Buffer.from(outputText).toString("base64")}`);

test("Publisher explicit sections take priority over either hydrated configuration state", () => {
  for (const section of ["jobs", "configuration", "audit", "scheduler", "extraTargets"]) {
    assert.equal(resolvePublisherAdminPage(`?page=publisher&section=${section}`, false), section);
    assert.equal(resolvePublisherAdminPage(`?page=publisher&section=${section}`, true), section);
  }
});
test("Publisher missing and invalid sections preserve its historical saved-config default", () => {
  for (const search of ["", "?section=unknown", "?section=JOBS", "?section=", "?section=__proto__", "?section=constructor"]) {
    assert.equal(requestedPublisherAdminPage(search), undefined);
    assert.equal(resolvePublisherAdminPage(search, false), "configuration");
    assert.equal(resolvePublisherAdminPage(search, true), "jobs");
  }
});
