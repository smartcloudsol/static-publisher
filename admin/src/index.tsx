import { Button, MantineProvider, createTheme } from "@mantine/core";
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

const hubPage = (new URLSearchParams(window.location.search).get("page") ?? "").startsWith("smartcloud-wpsuite");
const mountNode =
  document.getElementById("smartcloud-static-publisher-admin") ??
  (hubPage ? null : document.getElementById("root"));

async function init() {
  const store = await getStore();
  createRoot(mountNode!).render(
    <StrictMode>
      <MantineProvider theme={theme} defaultColorScheme="light">
        <Notifications position="top-right" zIndex={100002} />
        <Main store={store} />
      </MantineProvider>
    </StrictMode>,
  );
}

// The detail application remains mounted while its shared product panel is collapsed.
// Its single state owner also handles the compact launch dialog.
if (mountNode) void init();

type SurfaceRegistry = Record<string, (element: HTMLElement) => () => void>;
const surfaceWindow = window as Window & { smartcloudWpSuiteSurfaces?: SurfaceRegistry };
surfaceWindow.smartcloudWpSuiteSurfaces ??= {};
surfaceWindow.smartcloudWpSuiteSurfaces["static-publishing"] = (element) => {
  const root = createRoot(element);
  root.render(<MantineProvider theme={theme} defaultColorScheme="light">
    {mountNode ? <Button onClick={() => window.dispatchEvent(new Event("wpsuite-publisher-launch"))}>
      {__("Publish…", "smartcloud-static-publisher")}
    </Button> : <Button component="a" href="admin.php?page=smartcloud-static-publisher&section=jobs">
      {__("Open publishing jobs", "smartcloud-static-publisher")}
    </Button>}
  </MantineProvider>);
  return () => root.unmount();
};
window.dispatchEvent(new Event("smartcloud-wpsuite-surface-ready"));
