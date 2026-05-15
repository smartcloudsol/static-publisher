import { MantineProvider, createTheme } from "@mantine/core";
import { Notifications } from "@mantine/notifications";
import { getStore } from "@smart-cloud/publisher-core";
import { __ } from "@wordpress/i18n";
import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import Main from "./main";

const theme = createTheme({
  respectReducedMotion: true,
  fontFamily: "'Source Sans 3', 'Segoe UI', sans-serif",
  headings: {
    fontFamily: "'Source Sans 3', 'Segoe UI', sans-serif",
    fontWeight: "700",
    sizes: {
      h1: { fontSize: "2rem", lineHeight: "1.15" },
      h2: { fontSize: "1.55rem", lineHeight: "1.2" },
      h3: { fontSize: "1.2rem", lineHeight: "1.25" },
    },
  },
});

const mountNode =
  document.getElementById("smartcloud-static-publisher-admin") ??
  document.getElementById("root");

if (!mountNode) {
  throw new Error(
    __(
      "Static Publisher admin mount node is missing.",
      "smartcloud-static-publisher",
    ),
  );
}

const rootMountNode = mountNode;

async function init() {
  const store = await getStore();
  createRoot(rootMountNode).render(
    <StrictMode>
      <MantineProvider theme={theme} defaultColorScheme="light">
        <Notifications position="top-right" zIndex={100002} />
        <Main store={store} />
      </MantineProvider>
    </StrictMode>,
  );
}

void init();
