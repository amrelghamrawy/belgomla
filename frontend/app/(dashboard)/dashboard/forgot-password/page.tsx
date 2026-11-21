import { DashboardForgotPasswordForm } from "@/components/dashboard/DashboardForgotPasswordForm";
import { ar } from "@/lib/copy/ar";

export default function DashboardForgotPasswordPage() {
  return (
    <main className="flex flex-1 flex-col items-center justify-center gap-6 p-4">
      {/* D104: the platform's mark, as on the login page. */}
      <p className="flex items-center gap-2.5" aria-hidden>
        <span className="bg-primary text-primary-foreground font-heading flex size-10 items-center justify-center rounded-lg text-xl font-extrabold">
          {ar.appName.slice(0, 1)}
        </span>
        <span className="font-heading text-primary text-2xl font-extrabold">{ar.appName}</span>
      </p>
      <DashboardForgotPasswordForm />
    </main>
  );
}
