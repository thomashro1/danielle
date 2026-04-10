(function () {
  function wrapTable(table) {
    if (!table || table.closest('.table-responsive')) {
      return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'table-responsive';
    table.parentNode.insertBefore(wrapper, table);
    wrapper.appendChild(table);
  }

  function forEachMatch(root, selector, callback) {
    if (!root) {
      return;
    }

    if (root.matches && root.matches(selector)) {
      callback(root);
    }

    if (root.querySelectorAll) {
      root.querySelectorAll(selector).forEach(callback);
    }
  }

  function enhanceButtons(root) {
    forEachMatch(root, 'button, .app-button', (button) => {
      if (button.classList.contains('bo-alert-item') || button.hasAttribute('data-alert-kind')) {
        return;
      }

      button.classList.add('btn');

      if (button.classList.contains('primary')) {
        button.classList.add('btn-primary');
      } else if (button.classList.contains('danger')) {
        button.classList.add('btn-outline-danger');
      } else {
        button.classList.add('btn-outline-secondary');
      }

      if (button.classList.contains('small')) {
        button.classList.add('btn-sm');
      }
    });
  }

  function enhanceInputs(root) {
    forEachMatch(root, 'input, select, textarea', (field) => {
      if (field.type === 'hidden') {
        return;
      }

      if (field.type === 'checkbox' || field.type === 'radio') {
        field.classList.add('form-check-input');
        const label = field.closest('label');
        if (label) {
          label.classList.add('bo-checkbox');
        }
        return;
      }

      if (field.tagName === 'SELECT') {
        field.classList.add('form-select');
        return;
      }

      field.classList.add('form-control');
    });
  }

  function enhanceTables(root) {
    forEachMatch(root, 'table', (table) => {
      table.classList.add('table', 'table-vcenter');
      wrapTable(table);
    });
  }

  function enhanceMessages(root) {
    forEachMatch(root, '#message, #listMessage, #pageInfo, [id$="Message"]', (node) => {
      node.classList.add('bo-feedback');
    });
  }

  function enhanceShell(root) {
    const shell = root.querySelector('.app-shell');
    const isEmbeddedFrame = window.self !== window.top;

    if (shell) {
      shell.classList.add(isEmbeddedFrame ? 'bo-embedded-shell' : 'bo-standalone-shell');
    }

    const pageId = document.body.dataset.boPage || '';
    if (pageId) {
      document.body.classList.add('bo-page-body', `bo-route-${pageId}`);
    }

    const authShell = root.querySelector('.auth-shell');
    if (authShell) {
      document.body.classList.add('bo-login-body');
    }

    if (isEmbeddedFrame) {
      document.body.classList.add('bo-embedded-frame');
    }
  }

  function boot() {
    document.documentElement.setAttribute('data-bs-theme', 'light');
    document.body.classList.add('antialiased');
    enhanceShell(document);
    enhanceButtons(document);
    enhanceInputs(document);
    enhanceTables(document);
    enhanceMessages(document);

    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (!(node instanceof Element)) {
            return;
          }
          enhanceButtons(node);
          enhanceInputs(node);
          enhanceTables(node);
          enhanceMessages(node);
        });
      });
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
