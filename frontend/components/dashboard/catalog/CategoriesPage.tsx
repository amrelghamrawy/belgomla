"use client";

import {
  closestCenter,
  DndContext,
  type DragEndEvent,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from "@dnd-kit/core";
import {
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { useQueryClient } from "@tanstack/react-query";
import { GripVerticalIcon } from "lucide-react";
import { type FormEvent, useState } from "react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { queryKeys } from "@/lib/api/keys";
import type { DashboardCategory } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import {
  useCategoryStatus,
  useDashboardCategories,
  useReorderCategories,
  useSaveCategory,
} from "@/lib/dashboard/catalog";
import { activeOnly, applyOrder, flatten, reorderSiblings, subtreeIds } from "@/lib/dashboard/category-tree";
import { useListQuery } from "@/lib/dashboard/use-list-query";
import { cn } from "@/lib/utils";

import { FilterPanel } from "../common/FilterPanel";
import { NativeSelect } from "../common/NativeSelect";
import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";

const copy = ar.dashboard.categories;

type Editing = { mode: "create"; parentId: number | null } | { mode: "edit"; category: DashboardCategory };

/** The category tree: drag to reorder siblings, edit to rename or move, archive and restore (08 §6). */
export function CategoriesPage() {
  const [query, setQuery] = useListQuery(["archived"]);
  const categories = useDashboardCategories();
  const [editing, setEditing] = useState<Editing | null>(null);
  const showArchived = query.archived === "1";
  const tree = categories.data === undefined ? [] : showArchived ? categories.data : activeOnly(categories.data);

  return (
    <>
      <PageHeader
        title={copy.title}
        description={copy.description}
        actions={<Button onClick={() => setEditing({ mode: "create", parentId: null })}>{copy.add}</Button>}
      />
      <FilterPanel active={showArchived ? 1 : 0}>
        <div className="flex h-10 items-center gap-2">
          <Switch
            id="show-archived"
            checked={showArchived}
            onCheckedChange={(checked) => setQuery({ archived: checked ? "1" : null })}
          />
          <Label htmlFor="show-archived">{copy.showArchived}</Label>
        </div>
      </FilterPanel>
      {categories.isError && <ErrorBlock error={categories.error} onRetry={() => void categories.refetch()} />}
      {categories.isPending && <LoadingBlock />}
      {categories.data !== undefined && tree.length === 0 && <EmptyBlock>{copy.empty}</EmptyBlock>}
      {tree.length > 0 && (
        <SiblingList nodes={tree} depth={0} onEdit={(category) => setEditing({ mode: "edit", category })} />
      )}
      {editing !== null && categories.data !== undefined && (
        <CategoryDialog editing={editing} tree={categories.data} onClose={() => setEditing(null)} />
      )}
    </>
  );
}

function SiblingList({
  nodes,
  depth,
  onEdit,
}: {
  nodes: DashboardCategory[];
  depth: number;
  onEdit: (category: DashboardCategory) => void;
}) {
  const queryClient = useQueryClient();
  const reorder = useReorderCategories();
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );
  const ordered = [...nodes].sort((a, b) => a.sort_order - b.sort_order);

  const onDragEnd = ({ active, over }: DragEndEvent) => {
    if (over === null) {
      return;
    }

    const order = reorderSiblings(ordered, Number(active.id), Number(over.id));

    if (order === null) {
      return;
    }

    queryClient.setQueryData<DashboardCategory[]>(queryKeys.dashboardCategories, (tree) =>
      tree === undefined ? tree : applyOrder(tree, order),
    );
    reorder.mutate(order, {
      onSuccess: () => toast.success(copy.reordered),
      onError: (error) => toast.error(messageFor(error)),
    });
  };

  return (
    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
      <SortableContext items={ordered.map((node) => node.id)} strategy={verticalListSortingStrategy}>
        <ul className={cn("flex flex-col gap-1", depth > 0 && "ms-6 mt-1 border-s ps-2")}>
          {ordered.map((node) => (
            <CategoryRow key={node.id} node={node} depth={depth} onEdit={onEdit} />
          ))}
        </ul>
      </SortableContext>
    </DndContext>
  );
}

function CategoryRow({
  node,
  depth,
  onEdit,
}: {
  node: DashboardCategory;
  depth: number;
  onEdit: (category: DashboardCategory) => void;
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: node.id });
  const status = useCategoryStatus();
  const archived = node.status === "archived";

  const toggle = () =>
    status.mutate(
      { id: node.id, action: archived ? "restore" : "archive" },
      { onError: (error) => toast.error(messageFor(error)) },
    );

  return (
    <li
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition: transition }}
      className={cn(isDragging && "relative z-10 opacity-80")}
      data-testid={`category-${node.id}`}
    >
      <div className="bg-card flex items-center gap-2 rounded-md border p-2">
        <button
          type="button"
          className="text-muted-foreground hover:text-foreground grid size-9 shrink-0 cursor-grab touch-none place-items-center rounded"
          aria-label={copy.dragHandle(node.name)}
          {...attributes}
          {...listeners}
        >
          <GripVerticalIcon className="size-4" />
        </button>
        <span className={cn("min-w-0 flex-1 truncate", archived && "text-muted-foreground line-through")}>
          {node.name}
        </span>
        {archived && <Badge variant="secondary">{copy.archivedNote}</Badge>}
        <Button variant="ghost" size="sm" onClick={() => onEdit(node)}>
          {ar.dashboard.edit}
        </Button>
        <Button variant="ghost" size="sm" disabled={status.isPending} onClick={toggle}>
          {archived ? ar.dashboard.restore : ar.dashboard.archive}
        </Button>
      </div>
      {node.children.length > 0 && <SiblingList nodes={node.children} depth={depth + 1} onEdit={onEdit} />}
    </li>
  );
}

function CategoryDialog({
  editing,
  tree,
  onClose,
}: {
  editing: Editing;
  tree: DashboardCategory[];
  onClose: () => void;
}) {
  const save = useSaveCategory();
  const current = editing.mode === "edit" ? editing.category : null;
  const [name, setName] = useState(current?.name ?? "");
  const [parentId, setParentId] = useState<number | null>(
    current?.parent_id ?? (editing.mode === "create" ? editing.parentId : null),
  );
  const [error, setError] = useState<string | null>(null);
  // A category cannot move under itself or its own subtree (the backend answers 422 category_cycle).
  const blocked = current === null ? new Set<number>() : subtreeIds(current);
  const parents = flatten(activeOnly(tree)).filter(({ node }) => !blocked.has(node.id));

  const submit = (event: FormEvent) => {
    event.preventDefault();

    if (name.trim() === "") {
      setError(ar.validation.required);
      return;
    }

    save.mutate(
      { id: current?.id, name: name.trim(), parent_id: parentId },
      {
        onSuccess: () => {
          toast.success(ar.dashboard.saved);
          onClose();
        },
        onError: (failure) => setError(messageFor(failure)),
      },
    );
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{current === null ? copy.addTitle : copy.editTitle}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} noValidate className="flex flex-col gap-4">
          <Field>
            <FieldLabel htmlFor="category-name">{copy.name}</FieldLabel>
            <Input id="category-name" value={name} maxLength={100} onChange={(event) => setName(event.target.value)} />
          </Field>
          <Field>
            <FieldLabel htmlFor="category-parent">{copy.parent}</FieldLabel>
            <NativeSelect
              id="category-parent"
              value={parentId ?? ""}
              onChange={(event) => setParentId(event.target.value === "" ? null : Number(event.target.value))}
            >
              <option value="">{copy.root}</option>
              {parents.map(({ node, depth }) => (
                <option key={node.id} value={node.id}>
                  {`${"— ".repeat(depth)}${node.name}`}
                </option>
              ))}
            </NativeSelect>
          </Field>
          {error !== null && <FieldError role="alert">{error}</FieldError>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              {ar.cancel}
            </Button>
            <Button type="submit" disabled={save.isPending}>
              {save.isPending ? ar.saving : ar.save}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
