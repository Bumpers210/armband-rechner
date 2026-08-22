"use client";

import { useEffect } from "react";

export function CheckoutQueryCleanup() {
  useEffect(() => {
    if (window.location.search) {
      window.history.replaceState(
        null,
        "",
        `${window.location.pathname}${window.location.hash}`,
      );
    }
  }, []);

  return null;
}
