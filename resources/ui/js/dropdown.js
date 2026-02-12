/**
 * Pulsar UI — Dropdown v1.0.0
 * Toggle, click-outside-to-close, keyboard navigation.
 * Zero dependencies.
 */
'use strict';

(function () {
  function openDropdown(dropdown) {
    dropdown.classList.add('pui-dropdown--open');
    const trigger = dropdown.querySelector('.pui-dropdown__trigger');
    if (trigger) {
      trigger.setAttribute('aria-expanded', 'true');
    }
  }

  function closeDropdown(dropdown) {
    dropdown.classList.remove('pui-dropdown--open');
    const trigger = dropdown.querySelector('.pui-dropdown__trigger');
    if (trigger) {
      trigger.setAttribute('aria-expanded', 'false');
    }
  }

  function closeAllDropdowns() {
    document.querySelectorAll('.pui-dropdown--open').forEach(closeDropdown);
  }

  function getMenuItems(dropdown) {
    return Array.from(
      dropdown.querySelectorAll(
        '.pui-dropdown__content .pui-menu__item:not(.pui-menu__item--disabled)',
      ),
    );
  }

  // Toggle on trigger click
  document.addEventListener('click', function (e) {
    const trigger = e.target.closest('.pui-dropdown__trigger');
    if (trigger) {
      e.preventDefault();
      e.stopPropagation();
      const dropdown = trigger.closest('.pui-dropdown');
      if (!dropdown) return;

      const isOpen = dropdown.classList.contains('pui-dropdown--open');
      closeAllDropdowns();

      if (!isOpen) {
        openDropdown(dropdown);
      }
      return;
    }

    // Close all on outside click
    if (!e.target.closest('.pui-dropdown__content')) {
      closeAllDropdowns();
    }
  });

  // Keyboard navigation
  document.addEventListener('keydown', function (e) {
    const dropdown = document.querySelector('.pui-dropdown--open');
    if (!dropdown) return;

    const items = getMenuItems(dropdown);
    if (items.length === 0) return;

    const currentIndex = items.indexOf(document.activeElement);

    switch (e.key) {
      case 'Escape':
        e.preventDefault();
        closeDropdown(dropdown);
        dropdown.querySelector('.pui-dropdown__trigger')?.focus();
        break;

      case 'ArrowDown':
        e.preventDefault();
        if (currentIndex < items.length - 1) {
          items[currentIndex + 1].focus();
        } else {
          items[0].focus();
        }
        break;

      case 'ArrowUp':
        e.preventDefault();
        if (currentIndex > 0) {
          items[currentIndex - 1].focus();
        } else {
          items[items.length - 1].focus();
        }
        break;

      case 'Home':
        e.preventDefault();
        items[0].focus();
        break;

      case 'End':
        e.preventDefault();
        items[items.length - 1].focus();
        break;

      case 'Tab':
        closeDropdown(dropdown);
        break;
    }
  });

  window.PulsarDropdown = { open: openDropdown, close: closeDropdown };
})();
