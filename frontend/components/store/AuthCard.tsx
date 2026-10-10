import type { ReactNode } from "react";

import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";

/** The frame of every account form: title, optional intro, the form, and links under it. */
export function AuthCard({
  title,
  description,
  children,
  footer,
}: {
  title: string;
  description?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <Card className="w-full max-w-sm">
      <CardHeader>
        <CardTitle>
          <h1 className="text-xl">{title}</h1>
        </CardTitle>
        {description !== undefined && <CardDescription>{description}</CardDescription>}
      </CardHeader>
      <CardContent>{children}</CardContent>
      {footer !== undefined && <CardFooter className="flex flex-col gap-2 text-sm">{footer}</CardFooter>}
    </Card>
  );
}

/** The page around an AuthCard: centred, with room for the phone keyboard. */
export function AuthPage({ children }: { children: ReactNode }) {
  return <main className="flex flex-1 items-start justify-center p-4 sm:items-center">{children}</main>;
}
