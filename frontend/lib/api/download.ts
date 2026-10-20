import type { DownloadedFile } from "./client";

/**
 * Hands a downloaded file to the browser's own download (V2 Task 06): an object URL on a temporary `<a download>`.
 * Works on http too (dev and LAN stores), unlike the File System Access API.
 */
export function saveDownload(file: DownloadedFile): void {
  const url = URL.createObjectURL(file.blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = file.fileName;
  link.style.display = "none";
  document.body.append(link);

  try {
    link.click();
  } finally {
    link.remove();
    // Revoked on the next tick: the click starts the download first.
    setTimeout(() => URL.revokeObjectURL(url), 0);
  }
}
