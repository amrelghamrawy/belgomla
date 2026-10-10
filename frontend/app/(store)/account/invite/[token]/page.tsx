import { Suspense } from "react";

import { AuthPage } from "@/components/store/AuthCard";
import { InvitationForm } from "@/components/store/InvitationForm";

export default function InvitationPage() {
  return (
    <AuthPage>
      {/* Reads the token from the route (useParams suspends under cacheComponents). */}
      <Suspense>
        <InvitationForm />
      </Suspense>
    </AuthPage>
  );
}
