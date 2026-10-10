import { DashboardShell } from "@/components/dashboard/shell/DashboardShell";

export default function DashboardShellLayout({ children }: LayoutProps<"/dashboard">) {
  return <DashboardShell>{children}</DashboardShell>;
}
