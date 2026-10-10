"use client";

import { useEffect, useRef } from "react";

import { Button } from "@/components/ui/button";
import { ar } from "@/lib/copy/ar";

type LoadMoreProps = {
  hasMore: boolean;
  loading: boolean;
  onLoad: () => void;
};

/**
 * Infinite scroll: loads the next page when this sentinel comes near the screen. The button is the fallback for
 * keyboards, screen readers and browsers without IntersectionObserver.
 */
export function LoadMore({ hasMore, loading, onLoad }: LoadMoreProps) {
  const sentinel = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const element = sentinel.current;

    if (element === null || !hasMore || loading || typeof IntersectionObserver === "undefined") {
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          onLoad();
        }
      },
      { rootMargin: "600px 0px" },
    );

    observer.observe(element);

    return () => observer.disconnect();
  }, [hasMore, loading, onLoad]);

  if (!hasMore) {
    return null;
  }

  return (
    <div ref={sentinel} className="flex justify-center py-4">
      <Button variant="outline" className="h-11" onClick={onLoad} disabled={loading}>
        {loading ? ar.loading : ar.loadMore}
      </Button>
    </div>
  );
}
