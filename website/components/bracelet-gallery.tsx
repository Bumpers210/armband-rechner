import Image from "next/image";

import { siteContent } from "@/content/site-content";

export function BraceletGallery() {
  return (
    <div className="v2-gallery-grid">
      {siteContent.gallery.items.map((item, index) => (
        <figure
          className={`v2-gallery-item v2-gallery-item--${item.layout}`}
          key={item.id}
        >
          <div
            className={`v2-gallery-media v2-gallery-media--${item.image.orientation}`}
          >
            <Image
              src={item.image.src}
              alt={item.image.alt}
              width={item.image.width}
              height={item.image.height}
              sizes="(max-width: 767px) calc(100vw - 32px), (max-width: 1023px) 50vw, 58vw"
              className="v2-gallery-image"
              style={{ objectPosition: item.image.objectPosition }}
            />
          </div>
          <figcaption>
            <span className="v2-gallery-number" aria-hidden="true">
              {String(index + 1).padStart(2, "0")}
            </span>
            <span>{item.caption}</span>
          </figcaption>
        </figure>
      ))}
    </div>
  );
}
