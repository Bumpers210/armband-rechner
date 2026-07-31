"use client";

import Image from "next/image";
import {
  useCallback,
  useEffect,
  useId,
  useRef,
  useState,
} from "react";
import { createPortal } from "react-dom";

import type { ProductImage } from "@/content/products";

type ProductImageGalleryProps = {
  images: ProductImage[];
  productName: string;
  variant: "card" | "detail";
};

function adjacentIndex(
  current: number,
  imageCount: number,
  direction: -1 | 1,
): number {
  return (current + direction + imageCount) % imageCount;
}

export function ProductImageGallery({
  images,
  productName,
  variant,
}: ProductImageGalleryProps) {
  const [activeIndex, setActiveIndex] = useState<number | null>(null);
  const dialogTitleId = useId();
  const dialogRef = useRef<HTMLDivElement>(null);
  const closeButtonRef = useRef<HTMLButtonElement>(null);
  const triggerRefs = useRef<Array<HTMLButtonElement | null>>([]);
  const lastTriggerIndex = useRef(0);
  const isOpen = activeIndex !== null;

  const openLightbox = useCallback((index: number) => {
    lastTriggerIndex.current = index;
    setActiveIndex(index);
  }, []);

  const closeLightbox = useCallback(() => {
    setActiveIndex(null);
    window.requestAnimationFrame(() => {
      triggerRefs.current[lastTriggerIndex.current]?.focus();
    });
  }, []);

  const showAdjacentImage = useCallback(
    (direction: -1 | 1) => {
      setActiveIndex((current) =>
        current === null
          ? null
          : adjacentIndex(current, images.length, direction),
      );
    },
    [images.length],
  );

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    closeButtonRef.current?.focus();

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault();
        closeLightbox();
        return;
      }

      if (event.key === "ArrowLeft" && images.length > 1) {
        event.preventDefault();
        showAdjacentImage(-1);
        return;
      }

      if (event.key === "ArrowRight" && images.length > 1) {
        event.preventDefault();
        showAdjacentImage(1);
        return;
      }

      if (event.key !== "Tab") {
        return;
      }

      const focusable = Array.from(
        dialogRef.current?.querySelectorAll<HTMLElement>(
          "button:not([disabled])",
        ) ?? [],
      );

      if (focusable.length === 0) {
        event.preventDefault();
        return;
      }

      const first = focusable[0];
      const last = focusable.at(-1);

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last?.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener("keydown", handleKeyDown);

    return () => {
      document.removeEventListener("keydown", handleKeyDown);
      document.body.style.overflow = previousOverflow;
    };
  }, [
    closeLightbox,
    images.length,
    isOpen,
    showAdjacentImage,
  ]);

  if (images.length === 0) {
    return null;
  }

  const mainImage = images.find((image) => image.isMain) ?? images[0];
  const mainIndex = images.indexOf(mainImage);
  const activeImage = activeIndex === null ? null : images[activeIndex];

  const lightbox =
    activeImage && typeof document !== "undefined"
      ? createPortal(
          <div
            className="product-lightbox-backdrop"
            data-lightbox-backdrop
            onClick={(event) => {
              if (event.target === event.currentTarget) {
                closeLightbox();
              }
            }}
          >
            <div
              ref={dialogRef}
              className="product-lightbox"
              role="dialog"
              aria-modal="true"
              aria-labelledby={dialogTitleId}
              data-lightbox-dialog
            >
              <h2 id={dialogTitleId} className="sr-only">
                Großansicht: {productName}
              </h2>
              <button
                ref={closeButtonRef}
                type="button"
                className="product-lightbox-close"
                onClick={closeLightbox}
                data-lightbox-close
              >
                Schließen
              </button>

              <figure className="product-lightbox-figure">
                <Image
                  src={activeImage.src}
                  alt={activeImage.alt}
                  width={activeImage.width}
                  height={activeImage.height}
                  sizes="100vw"
                  className="product-lightbox-image"
                  priority
                />
                <figcaption aria-live="polite">
                  Bild {images.indexOf(activeImage) + 1} von {images.length}
                </figcaption>
              </figure>

              {images.length > 1 ? (
                <div
                  className="product-lightbox-navigation"
                  aria-label="Bilder wechseln"
                >
                  <button
                    type="button"
                    onClick={() => showAdjacentImage(-1)}
                    data-lightbox-previous
                  >
                    Vorheriges Bild
                  </button>
                  <button
                    type="button"
                    onClick={() => showAdjacentImage(1)}
                    data-lightbox-next
                  >
                    Nächstes Bild
                  </button>
                </div>
              ) : null}
            </div>
          </div>,
          document.body,
        )
      : null;

  if (variant === "card") {
    return (
      <>
        <button
          ref={(element) => {
            triggerRefs.current[mainIndex] = element;
          }}
          type="button"
          className="product-card-media"
          onClick={() => openLightbox(mainIndex)}
          aria-label={`${mainImage.alt} vergrößern`}
          data-lightbox-open={mainIndex}
        >
          <Image
            src={mainImage.src}
            alt={mainImage.alt}
            width={mainImage.width}
            height={mainImage.height}
            sizes="(max-width: 767px) calc(100vw - 32px), (max-width: 1023px) 50vw, 33vw"
            className="product-card-image"
          />
          {images.length > 1 ? (
            <span className="product-card-image-count">
              {images.length} Bilder
            </span>
          ) : null}
        </button>
        {lightbox}
      </>
    );
  }

  return (
    <>
      <div className="product-detail-gallery">
        <button
          ref={(element) => {
            triggerRefs.current[mainIndex] = element;
          }}
          type="button"
          className="product-detail-image-trigger"
          onClick={() => openLightbox(mainIndex)}
          aria-label={`${mainImage.alt} vergrößern`}
          data-lightbox-open={mainIndex}
        >
          <Image
            src={mainImage.src}
            alt={mainImage.alt}
            width={mainImage.width}
            height={mainImage.height}
            sizes="(max-width: 767px) calc(100vw - 32px), 50vw"
            className="product-detail-image"
            priority
          />
        </button>

        {images.length > 1 ? (
          <div className="product-detail-thumbs" aria-label="Weitere Fotos">
            {images
              .map((image, index) => ({ image, index }))
              .filter(({ index }) => index !== mainIndex)
              .map(({ image, index }) => (
                <button
                  ref={(element) => {
                    triggerRefs.current[index] = element;
                  }}
                  key={image.src}
                  type="button"
                  className="product-detail-thumb-trigger"
                  onClick={() => openLightbox(index)}
                  aria-label={`${image.alt} vergrößern`}
                  data-lightbox-open={index}
                >
                  <Image
                    src={image.src}
                    alt={image.alt}
                    width={image.width}
                    height={image.height}
                    sizes="8rem"
                    className="product-detail-thumb"
                  />
                </button>
              ))}
          </div>
        ) : null}
      </div>
      {lightbox}
    </>
  );
}
