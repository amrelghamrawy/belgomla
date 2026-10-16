import {
  BabyIcon,
  BackpackIcon,
  BookOpenIcon,
  BrushIcon,
  CalculatorIcon,
  EraserIcon,
  FileTextIcon,
  FlaskConicalIcon,
  FolderIcon,
  Gamepad2Icon,
  GiftIcon,
  GlobeIcon,
  GraduationCapIcon,
  HighlighterIcon,
  LanguagesIcon,
  LaptopIcon,
  LibraryIcon,
  type LucideIcon,
  NotebookPenIcon,
  PackageIcon,
  PaletteIcon,
  PaperclipIcon,
  PencilIcon,
  PenIcon,
  PrinterIcon,
  PuzzleIcon,
  RulerIcon,
  ScissorsIcon,
  ShapesIcon,
  ShirtIcon,
  SigmaIcon,
} from "lucide-react";

import type { CategoryIcon } from "@/lib/api/types";

/** Every CategoryIcon value has a drawing: a new value in the spec is a type error here until it gets one. */
const ICONS: Record<CategoryIcon, LucideIcon> = {
  "book-open": BookOpenIcon,
  library: LibraryIcon,
  "graduation-cap": GraduationCapIcon,
  "notebook-pen": NotebookPenIcon,
  pencil: PencilIcon,
  pen: PenIcon,
  highlighter: HighlighterIcon,
  eraser: EraserIcon,
  ruler: RulerIcon,
  scissors: ScissorsIcon,
  palette: PaletteIcon,
  brush: BrushIcon,
  calculator: CalculatorIcon,
  backpack: BackpackIcon,
  folder: FolderIcon,
  paperclip: PaperclipIcon,
  "file-text": FileTextIcon,
  printer: PrinterIcon,
  "flask-conical": FlaskConicalIcon,
  globe: GlobeIcon,
  languages: LanguagesIcon,
  sigma: SigmaIcon,
  puzzle: PuzzleIcon,
  "gamepad-2": Gamepad2Icon,
  shapes: ShapesIcon,
  baby: BabyIcon,
  gift: GiftIcon,
  shirt: ShirtIcon,
  laptop: LaptopIcon,
  package: PackageIcon,
};

/**
 * The drawing of a category icon (D73). Decorative: the category name is always next to it. A value this build does
 * not know (a newer backend) draws the generic package rather than breaking the page.
 */
export function CategoryGlyph({ icon, className }: { icon: CategoryIcon; className?: string }) {
  const Icon = (ICONS as Partial<Record<string, LucideIcon>>)[icon] ?? PackageIcon;

  return <Icon aria-hidden data-icon={icon} className={className} />;
}
