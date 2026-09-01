import fs from "node:fs";
import http from "node:http";

const port = Number(process.env.PUBLISHER_FIXTURE_PORT || 18765);
const statePath = String(process.env.PUBLISHER_FIXTURE_STATE || "");

function state() {
  try {
    return fs.readFileSync(statePath, "utf8").trim() === "after"
      ? "after"
      : "before";
  } catch {
    return "before";
  }
}

function send(response, status, contentType, body) {
  response.writeHead(status, {
    "cache-control": "no-store",
    "content-type": `${contentType}; charset=utf-8`,
  });
  response.end(body);
}

const server = http.createServer((request, response) => {
  const origin = `http://127.0.0.1:${port}`;
  const url = new URL(request.url || "/", origin);
  const phase = state();
  const activePath = phase === "after" ? "/new/" : "/old/";
  const activeTitle = phase === "after" ? "New article" : "Old article";

  if (url.pathname === "/health") {
    send(response, 200, "text/plain", phase);
    return;
  }
  if (url.pathname === "/wp-sitemap.xml") {
    send(
      response,
      200,
      "application/xml",
      `<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>${origin}/wp-sitemap-posts-post-1.xml</loc></sitemap></sitemapindex>`,
    );
    return;
  }
  if (url.pathname === "/wp-sitemap-posts-post-1.xml") {
    send(
      response,
      200,
      "application/xml",
      `<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>${origin}${activePath}</loc></url><url><loc>${origin}/listing/</loc></url></urlset>`,
    );
    return;
  }
  if (url.pathname === "/404.html") {
    send(response, 404, "text/html", "<!doctype html><title>Not found</title><h1>Not found</h1>");
    return;
  }
  if (url.pathname === "/old/" && phase === "after") {
    send(response, 404, "text/html", "<!doctype html><title>Gone</title><h1>Gone</h1>");
    return;
  }
  if (url.pathname === "/new/" && phase === "before") {
    send(response, 404, "text/html", "<!doctype html><title>Missing</title><h1>Missing</h1>");
    return;
  }
  if (url.pathname === activePath) {
    send(response, 200, "text/html", `<!doctype html><title>${activeTitle}</title><main><h1>${activeTitle}</h1><p>Disposable content sync fixture.</p></main>`);
    return;
  }
  if (url.pathname === "/listing/") {
    send(response, 200, "text/html", `<!doctype html><title>Listing</title><main><h1>Listing</h1><a href="${activePath}">${activeTitle}</a></main>`);
    return;
  }
  if (url.pathname === "/") {
    send(response, 200, "text/html", `<!doctype html><title>Fixture</title><main><h1>Fixture</h1><a href="/listing/">Listing</a><a href="${activePath}">${activeTitle}</a></main>`);
    return;
  }
  send(response, 404, "text/html", "<!doctype html><title>Missing</title><h1>Missing</h1>");
});

server.listen(port, "127.0.0.1", () => {
  process.stdout.write(`fixture-ready:${port}\n`);
});

for (const signal of ["SIGINT", "SIGTERM"]) {
  process.on(signal, () => server.close(() => process.exit(0)));
}
