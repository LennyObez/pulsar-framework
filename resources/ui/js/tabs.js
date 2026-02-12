/**
 * Pulsar UI — Tabs v1.0.0
 * Tab switching with keyboard arrow keys.
 * Zero dependencies.
 */
'use strict';

(function () {
  function initTabs(tabsContainer) {
    var tabList = tabsContainer.querySelector('.pui-tabs__list');
    if (!tabList) return;

    var tabs = Array.from(tabList.querySelectorAll('.pui-tabs__tab'));
    if (tabs.length === 0) return;

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function (e) {
        e.preventDefault();
        activateTab(tabsContainer, tab);
      });
    });

    tabList.addEventListener('keydown', function (e) {
      var currentTab = document.activeElement;
      if (!currentTab || !currentTab.classList.contains('pui-tabs__tab')) return;

      var index = tabs.indexOf(currentTab);
      if (index === -1) return;

      var newIndex = -1;

      switch (e.key) {
        case 'ArrowRight':
        case 'ArrowDown':
          e.preventDefault();
          newIndex = (index + 1) % tabs.length;
          break;

        case 'ArrowLeft':
        case 'ArrowUp':
          e.preventDefault();
          newIndex = (index - 1 + tabs.length) % tabs.length;
          break;

        case 'Home':
          e.preventDefault();
          newIndex = 0;
          break;

        case 'End':
          e.preventDefault();
          newIndex = tabs.length - 1;
          break;
      }

      if (newIndex >= 0) {
        tabs[newIndex].focus();
        activateTab(tabsContainer, tabs[newIndex]);
      }
    });
  }

  function activateTab(container, selectedTab) {
    var tabs = Array.from(container.querySelectorAll('.pui-tabs__list .pui-tabs__tab'));
    var panels = Array.from(container.querySelectorAll('.pui-tabs__panel'));

    tabs.forEach(function (tab) {
      var isSelected = tab === selectedTab;
      tab.setAttribute('aria-selected', String(isSelected));
      tab.setAttribute('tabindex', isSelected ? '0' : '-1');
      tab.classList.toggle('pui-tabs__tab--active', isSelected);
    });

    var panelId = selectedTab.getAttribute('aria-controls');
    panels.forEach(function (panel) {
      panel.hidden = panel.id !== panelId;
    });
  }

  // Auto-initialize
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.pui-tabs').forEach(initTabs);
  });

  window.PulsarTabs = { init: initTabs, activate: activateTab };
})();
