import { DM_Sans } from "next/font/google";

import { DashboardTheme } from "@/components/dashboard/shell/DashboardTheme";

// D104: DM Sans sets the dashboard's Latin text and figures; Arabic falls through to IBM Plex Sans Arabic (D40).
const dmSans = DM_Sans({
  variable: "--font-dm",
  subsets: ["latin"],
  weight: ["400", "500", "700"],
  // No metric-matched Arial fallback: Arial carries Arabic glyphs and would win over IBM Plex Sans Arabic.
  adjustFontFallback: false,
  display: "swap",
});

export default function DashboardLayout({ children }: LayoutProps<"/">) {
  return <DashboardTheme fontClassName={dmSans.variable}>{children}</DashboardTheme>;
}
