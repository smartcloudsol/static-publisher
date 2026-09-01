import { createHash } from "node:crypto";
import fs from "node:fs";

const [origin, outputPath] = process.argv.slice(2);
if (!origin || !outputPath) {
  throw new Error("Use: node create-content-sync-impact.mjs ORIGIN OUTPUT_PATH");
}

function stable(value) {
  if (Array.isArray(value)) return value.map(stable);
  if (value && typeof value === "object") {
    return Object.fromEntries(
      Object.entries(value)
        .sort(([left], [right]) => left.localeCompare(right))
        .map(([key, item]) => [key, stable(item)]),
    );
  }
  return value;
}

const unsigned = {
  schemaVersion: 1,
  generatedAt: new Date().toISOString(),
  jobId: "aws-content-sync-e2e",
  ruleId: "aws-content-sync-e2e",
  consumerId: "content-sync:aws-content-sync-e2e",
  coalesceKey: "content-sync:aws-content-sync-e2e",
  scopeFingerprint: "1".repeat(64),
  targetFingerprint: "2".repeat(64),
  baselineId: "aws-content-sync-e2e-baseline",
  fromSequence: 1,
  toSequence: 2,
  eventCount: 1,
  foldedCount: 1,
  directRenderUrls: [`${origin}/new/`],
  archiveFamilyUrls: [`${origin}/listing/`],
  sitemapUrls: [`${origin}/wp-sitemap.xml`],
  deleteUrls: [`${origin}/old/`],
  archiveFamilies: [
    {
      kind: "listing",
      url: `${origin}/listing/`,
      pageUrls: [`${origin}/listing/`],
      maxPages: 1,
      source: "explicit-listing",
    },
  ],
};
const impactHash = createHash("sha256")
  .update(JSON.stringify(stable(unsigned)))
  .digest("hex");
fs.writeFileSync(outputPath, JSON.stringify({ ...unsigned, impactHash }, null, 2));
