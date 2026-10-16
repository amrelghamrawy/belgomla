"use client";

import { useMemo, useSyncExternalStore } from "react";

import type { TenantSettings } from "@/lib/api/types";

type Slot = TenantSettings["working_hours"][number];
export type Range = Pick<Slot, "from" | "to">;

/** A run of consecutive days with the same hours; `ranges` is empty when the store is closed those days. */
export interface HoursGroup {
  days: number[];
  ranges: Range[];
}

/** Where the store stands at a moment: open until `closesAt`, or closed until `opensAt` (null = never opens). */
export type OpenStatus =
  { open: true; closesAt: string } | { open: false; opensAt: { day: number; from: string; inDays: number } | null };

/** The Egyptian week starts on Saturday (day 6, with 0 = Sunday as the API sends it). */
const WEEK = [6, 0, 1, 2, 3, 4, 5];
const TIME_ZONE = "Africa/Cairo";
const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

function minutes(time: string): number {
  const [hours = 0, mins = 0] = time.split(":").map(Number);

  return hours * 60 + mins;
}

function rangesOf(slots: Slot[], day: number): Range[] {
  return slots
    .filter((slot) => slot.day === day)
    .map(({ from, to }) => ({ from, to }))
    .sort((a, b) => minutes(a.from) - minutes(b.from));
}

function sameRanges(a: Range[], b: Range[]): boolean {
  return a.length === b.length && a.every((range, i) => range.from === b[i]?.from && range.to === b[i]?.to);
}

/** Saturday → Friday, folding neighbouring days with identical hours into one row and keeping closed days. */
export function groupWorkingHours(slots: Slot[]): HoursGroup[] {
  const groups: HoursGroup[] = [];

  for (const day of WEEK) {
    const ranges = rangesOf(slots, day);
    const last = groups.at(-1);

    if (last && sameRanges(last.ranges, ranges)) {
      last.days.push(day);
    } else {
      groups.push({ days: [day], ranges });
    }
  }

  return groups;
}

/** The weekday (0 = Sunday) and minute of the day on Cairo's wall clock. */
export function cairoClock(date: Date): { day: number; minutes: number } {
  const parts = new Intl.DateTimeFormat("en-US", {
    timeZone: TIME_ZONE,
    weekday: "short",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).formatToParts(date);
  const part = (type: string) => parts.find((p) => p.type === type)?.value ?? "";

  return { day: WEEKDAYS.indexOf(part("weekday")), minutes: minutes(`${part("hour")}:${part("minute")}`) };
}

/** A range whose end is not after its start runs past midnight (20:00–02:00). */
function overnight(range: Range): boolean {
  return minutes(range.to) <= minutes(range.from);
}

export function openStatus(slots: Slot[], clock: { day: number; minutes: number }): OpenStatus {
  const now = clock.minutes;
  const yesterday = (clock.day + 6) % 7;
  const current =
    rangesOf(slots, clock.day).find(
      (range) => now >= minutes(range.from) && (overnight(range) || now < minutes(range.to)),
    ) ?? rangesOf(slots, yesterday).find((range) => overnight(range) && now < minutes(range.to));

  if (current) {
    return { open: true, closesAt: current.to };
  }

  for (let inDays = 0; inDays < 8; inDays++) {
    const day = (clock.day + inDays) % 7;
    const next = rangesOf(slots, day).find((range) => inDays > 0 || minutes(range.from) > now);

    if (next) {
      return { open: false, opensAt: { day, from: next.from, inDays } };
    }
  }

  return { open: false, opensAt: null };
}

function subscribe(onTick: () => void): () => void {
  const timer = setInterval(onTick, 30_000);

  return () => clearInterval(timer);
}

const currentMinute = () => Math.floor(Date.now() / 60_000);
const noMinute = () => null;

/** The current time to the minute, or null while server-rendering and hydrating (so the markup matches). */
export function useMinuteClock(): Date | null {
  const minute = useSyncExternalStore(subscribe, currentMinute, noMinute);

  return useMemo(() => (minute === null ? null : new Date(minute * 60_000)), [minute]);
}
