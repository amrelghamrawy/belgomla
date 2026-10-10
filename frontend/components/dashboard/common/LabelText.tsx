import { ar } from "@/lib/copy/ar";

type Props = {
  text: string;
  /** "required" adds a red "*", "optional" adds a muted "(اختياري)"; omit for fields that always hold a value. */
  need?: "required" | "optional";
};

/** A form label's text with the dashboard's required/optional marker, inside a FieldLabel or legend. */
export function LabelText({ text, need }: Props) {
  return (
    <>
      {text}
      {need === "required" && <span className="text-destructive"> *</span>}
      {need === "optional" && <span className="text-muted-foreground font-normal"> {ar.dashboard.optional}</span>}
    </>
  );
}
