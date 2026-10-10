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
import { rectSortingStrategy, SortableContext, sortableKeyboardCoordinates, useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { GripVerticalIcon, TrashIcon, UploadIcon } from "lucide-react";
import Image from "next/image";
import { useRef } from "react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import type { DashboardProduct, ProductImage } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { useDeleteProductImage, useReorderProductImages, useUploadProductImage } from "@/lib/dashboard/catalog";

const copy = ar.dashboard.productForm;

/** The gallery: upload (the first becomes main), delete, drag to reorder (BR-P07). */
export function ProductImages({ product }: { product: DashboardProduct }) {
  const input = useRef<HTMLInputElement>(null);
  const upload = useUploadProductImage(product.id);
  const reorder = useReorderProductImages(product.id);
  const images = [...(product.images ?? [])].sort((a, b) => a.sort_order - b.sort_order);
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  const onFiles = (files: FileList | null) => {
    Array.from(files ?? []).forEach((file, index) =>
      upload.mutate(
        { file, isMain: images.length === 0 && index === 0 },
        { onError: (error) => toast.error(messageFor(error)) },
      ),
    );

    if (input.current !== null) {
      input.current.value = "";
    }
  };

  const onDragEnd = ({ active, over }: DragEndEvent) => {
    if (over === null || active.id === over.id) {
      return;
    }

    const ids = images.map((image) => image.id);
    const from = ids.indexOf(Number(active.id));
    const to = ids.indexOf(Number(over.id));
    ids.splice(to, 0, ...ids.splice(from, 1));
    reorder.mutate(ids, { onError: (error) => toast.error(messageFor(error)) });
  };

  return (
    <div className="flex flex-col gap-4">
      <div>
        <input
          ref={input}
          type="file"
          accept="image/png,image/jpeg,image/webp"
          multiple
          className="sr-only"
          id="product-image-upload"
          onChange={(event) => onFiles(event.target.files)}
        />
        <Button type="button" variant="outline" disabled={upload.isPending} onClick={() => input.current?.click()}>
          <UploadIcon />
          {upload.isPending ? ar.saving : copy.upload}
        </Button>
      </div>
      <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
        <SortableContext items={images.map((image) => image.id)} strategy={rectSortingStrategy}>
          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            {images.map((image, index) => (
              <ImageTile key={image.id} productId={product.id} image={image} position={index + 1} />
            ))}
          </ul>
        </SortableContext>
      </DndContext>
    </div>
  );
}

function ImageTile({ productId, image, position }: { productId: number; image: ProductImage; position: number }) {
  const { attributes, listeners, setNodeRef, transform, transition } = useSortable({ id: image.id });
  const remove = useDeleteProductImage(productId);

  return (
    <li
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition: transition }}
      className="bg-card relative overflow-hidden rounded-md border"
    >
      <Image src={image.url} alt="" width={200} height={200} className="aspect-square w-full object-cover" />
      <div className="flex items-center justify-between gap-1 p-1">
        <button
          type="button"
          className="text-muted-foreground grid size-9 cursor-grab touch-none place-items-center"
          aria-label={copy.imageDrag(position)}
          {...attributes}
          {...listeners}
        >
          <GripVerticalIcon className="size-4" />
        </button>
        {image.is_main && <Badge>{copy.main}</Badge>}
        <Button
          type="button"
          variant="ghost"
          size="icon"
          aria-label={copy.removeImage}
          disabled={remove.isPending}
          onClick={() => remove.mutate(image.id, { onError: (error) => toast.error(messageFor(error)) })}
        >
          <TrashIcon />
        </Button>
      </div>
    </li>
  );
}
