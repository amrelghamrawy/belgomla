"use client"

import * as React from "react"
import { cn } from "cn"

/**
 * `stack` (D104, dashboard lists): below the md breakpoint each row becomes a card of label/value pairs instead of
 * a table that scrolls sideways. Each cell takes its column's header text as `data-label` (kept in sync as rows
 * change), which globals.css shows above the value.
 */
function Table({
  className,
  stack = false,
  ...props
}: React.ComponentProps<"table"> & { stack?: boolean }) {
  const ref = React.useRef<HTMLTableElement>(null)

  React.useEffect(() => {
    const table = ref.current

    if (!stack || table === null) {
      return
    }

    const label = () => {
      const heads = [...table.querySelectorAll("thead th")].map((th) => th.textContent?.trim() ?? "")

      table.querySelectorAll("tbody tr").forEach((row) => {
        ;[...row.children].forEach((cell, index) => {
          const text = heads[index] ?? ""

          if (cell.getAttribute("data-label") !== text) {
            cell.setAttribute("data-label", text)
          }
        })
      })
    }

    label()
    // Attribute writes are not childList mutations, so labelling never re-triggers itself.
    const observer = new MutationObserver(label)
    observer.observe(table, { childList: true, subtree: true, characterData: true })

    return () => observer.disconnect()
  }, [stack])

  return (
    <div
      data-slot="table-container"
      className="relative w-full overflow-x-auto"
    >
      <table
        ref={ref}
        data-slot="table"
        data-stack={stack ? "" : undefined}
        className={cn("w-full caption-bottom text-sm", className)}
        {...props}
      />
    </div>
  )
}

function TableHeader({ className, ...props }: React.ComponentProps<"thead">) {
  return (
    <thead
      data-slot="table-header"
      className={cn("[&_tr]:border-b", className)}
      {...props}
    />
  )
}

function TableBody({ className, ...props }: React.ComponentProps<"tbody">) {
  return (
    <tbody
      data-slot="table-body"
      className={cn("[&_tr:last-child]:border-0", className)}
      {...props}
    />
  )
}

function TableFooter({ className, ...props }: React.ComponentProps<"tfoot">) {
  return (
    <tfoot
      data-slot="table-footer"
      className={cn(
        "border-t bg-muted/50 font-medium [&>tr]:last:border-b-0",
        className
      )}
      {...props}
    />
  )
}

function TableRow({ className, ...props }: React.ComponentProps<"tr">) {
  return (
    <tr
      data-slot="table-row"
      className={cn(
        "border-b transition-colors hover:bg-muted/50 has-aria-expanded:bg-muted/50 data-[state=selected]:bg-muted",
        className
      )}
      {...props}
    />
  )
}

function TableHead({ className, ...props }: React.ComponentProps<"th">) {
  return (
    <th
      data-slot="table-head"
      className={cn(
        "h-10 px-2 text-start align-middle font-medium whitespace-nowrap text-foreground [&:has([role=checkbox])]:pe-0",
        className
      )}
      {...props}
    />
  )
}

function TableCell({ className, ...props }: React.ComponentProps<"td">) {
  return (
    <td
      data-slot="table-cell"
      className={cn(
        "p-2 align-middle whitespace-nowrap [&:has([role=checkbox])]:pe-0",
        className
      )}
      {...props}
    />
  )
}

function TableCaption({
  className,
  ...props
}: React.ComponentProps<"caption">) {
  return (
    <caption
      data-slot="table-caption"
      className={cn("mt-4 text-sm text-muted-foreground", className)}
      {...props}
    />
  )
}

export {
  Table,
  TableHeader,
  TableBody,
  TableFooter,
  TableHead,
  TableRow,
  TableCell,
  TableCaption,
}
