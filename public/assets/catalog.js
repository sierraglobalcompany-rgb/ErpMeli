(() => {
  'use strict';

  document.querySelectorAll('[data-catalog-gallery]').forEach((gallery) => {
    const media = gallery.closest('.catalog-product-media');
    const mainImage = media?.querySelector('[data-catalog-main-image]');
    if (!mainImage) return;
    gallery.addEventListener('click', (event) => {
      if (!(event.target instanceof Element)) return;
      const button = event.target.closest('[data-catalog-gallery-image]');
      if (!button || !gallery.contains(button)) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      const imageUrl = button.dataset.catalogGalleryImage || '';
      if (!imageUrl) return;
      mainImage.src = imageUrl;
      mainImage.dataset.catalogCurrentImage = imageUrl;
      gallery.querySelectorAll('[data-catalog-gallery-image]').forEach((thumb) => {
        const selected = thumb === button;
        thumb.classList.toggle('active', selected);
        thumb.setAttribute('aria-pressed', selected ? 'true' : 'false');
      });
    }, true);
  });

  document.querySelectorAll('[data-catalog-lightbox-open]').forEach((button) => {
    const media = button.closest('.catalog-product-media');
    const mainImage = media?.querySelector('[data-catalog-main-image]');
    if (!mainImage) return;
    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();
      const images = Array.from(media.querySelectorAll('[data-catalog-gallery-image]'))
        .map((thumb) => thumb.dataset.catalogGalleryImage || '')
        .filter(Boolean);
      const current = mainImage.dataset.catalogCurrentImage || mainImage.currentSrc || mainImage.src || images[0] || '';
      const unique = Array.from(new Set(images.length ? images : [current].filter(Boolean)));
      openLightbox(unique, Math.max(0, unique.indexOf(current)), button);
    }, true);
  });

  function openLightbox(images, startIndex, opener) {
    if (!images.length) return;
    let index = Math.max(0, Math.min(images.length - 1, startIndex || 0));
    const overlay = document.createElement('div');
    overlay.className = 'catalog-lightbox';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Galería ampliada del producto');
    overlay.innerHTML = '<div class="catalog-lightbox-inner"><button type="button" class="catalog-lightbox-close" aria-label="Cerrar imagen ampliada">×</button><button type="button" class="catalog-lightbox-nav catalog-lightbox-prev" aria-label="Imagen anterior">‹</button><img src="" alt="Imagen ampliada del producto"><button type="button" class="catalog-lightbox-nav catalog-lightbox-next" aria-label="Imagen siguiente">›</button><div class="catalog-lightbox-count"></div></div>';
    const image = overlay.querySelector('img');
    const count = overlay.querySelector('.catalog-lightbox-count');
    const previous = overlay.querySelector('.catalog-lightbox-prev');
    const next = overlay.querySelector('.catalog-lightbox-next');
    const close = overlay.querySelector('.catalog-lightbox-close');
    const render = () => {
      if (image) image.src = images[index];
      if (count) count.textContent = images.length > 1 ? `${index + 1} / ${images.length}` : '';
      if (previous) previous.hidden = images.length < 2;
      if (next) next.hidden = images.length < 2;
    };
    const move = (direction) => {
      index = (index + direction + images.length) % images.length;
      render();
    };
    const closeLightbox = () => {
      document.removeEventListener('keydown', onKey);
      document.body.classList.remove('catalog-lightbox-open');
      overlay.remove();
      opener?.focus?.();
    };
    const onKey = (event) => {
      if (event.key === 'Escape') closeLightbox();
      if (event.key === 'ArrowLeft') move(-1);
      if (event.key === 'ArrowRight') move(1);
    };
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) closeLightbox();
    });
    close?.addEventListener('click', closeLightbox);
    previous?.addEventListener('click', () => move(-1));
    next?.addEventListener('click', () => move(1));
    document.addEventListener('keydown', onKey);
    document.body.appendChild(overlay);
    document.body.classList.add('catalog-lightbox-open');
    render();
    close?.focus?.();
  }

  document.querySelectorAll('[data-catalog-mobile-search]').forEach((button) => {
    button.addEventListener('click', () => {
      const actions = button.closest('.catalog-mobile-actions');
      const panel = actions?.querySelector('.catalog-mobile-panel');
      if (panel instanceof HTMLDetailsElement) {
        actions.querySelectorAll('details[open]').forEach((openPanel) => {
          if (openPanel !== panel) openPanel.removeAttribute('open');
        });
        panel.setAttribute('open', '');
        window.setTimeout(() => panel.querySelector('input[name="q"]')?.focus(), 50);
      }
    });
  });

  document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;
    document.querySelectorAll('.catalog-action-menu[open]').forEach((menu) => {
      if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
  });

})();
