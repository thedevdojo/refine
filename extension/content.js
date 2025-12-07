/**
 * Refine Content Script
 *
 * Injected into all local development pages. Handles:
 * 1. Capturing the clicked element
 * 2. Finding the nearest data-source attribute
 * 3. Fetching source code from Laravel
 * 4. Rendering the floating editor UI
 * 5. Saving changes back to Laravel
 */

(function() {
  'use strict';

  // Configuration
  const CONFIG = {
    apiBaseUrl: window.location.origin + '/refine',
    sourceAttribute: 'data-source',
    maxTraversalDepth: 20,
    saveDebounceMs: 300,
    storageKey: 'refine_editing_file',
  };

  // State
  let clickedElement = null;
  let currentEditor = null;
  let saveTimeout = null;
  let gsapLoaded = false;
  let messageHandler = null;
  let isMinimized = false;
  let isMaximized = false;

  // Inject CSS animations for the editor
  function injectAnimationStyles() {
    if (document.getElementById('refine-animation-styles')) return;

    const style = document.createElement('style');
    style.id = 'refine-animation-styles';
    style.textContent = `
      @keyframes refine-slide-up {
        from {
          transform: translateY(calc(100% + 20px));
          opacity: 0;
        }
        to {
          transform: translateY(0);
          opacity: 1;
        }
      }

      @keyframes refine-slide-down {
        from {
          transform: translateY(0);
          opacity: 1;
        }
        to {
          transform: translateY(calc(100% + 20px));
          opacity: 0;
        }
      }

      .refine-editor-enter {
        animation: refine-slide-up 0.45s cubic-bezier(0.16, 1, 0.3, 1) forwards;
      }

      .refine-editor-exit {
        animation: refine-slide-down 0.3s cubic-bezier(0.4, 0, 1, 1) forwards;
      }
    `;
    document.head.appendChild(style);
  }

  // Load GSAP (optional enhancement)
  function loadGSAP() {
    return new Promise((resolve) => {
      if (gsapLoaded && typeof window.gsap !== 'undefined') {
        resolve();
        return;
      }

      // Check if already loaded
      if (typeof window.gsap !== 'undefined') {
        gsapLoaded = true;
        resolve();
        return;
      }

      const script = document.createElement('script');
      script.src = chrome.runtime.getURL('gsap.min.js');
      script.onload = () => {
        gsapLoaded = true;
        // Small delay to ensure GSAP is fully initialized
        setTimeout(resolve, 50);
      };
      script.onerror = () => {
        console.warn('GSAP failed to load, using CSS animations');
        resolve();
      };
      document.head.appendChild(script);
    });
  }

  /**
   * Save the current editing state to localStorage
   */
  function saveEditingState(sourceRef) {
    try {
      localStorage.setItem(CONFIG.storageKey, JSON.stringify({
        sourceRef: sourceRef,
        timestamp: Date.now(),
        url: window.location.pathname
      }));
    } catch (e) {
      console.warn('Failed to save editing state:', e);
    }
  }

  /**
   * Get and clear the saved editing state
   */
  function getAndClearEditingState() {
    try {
      const data = localStorage.getItem(CONFIG.storageKey);
      if (!data) return null;

      localStorage.removeItem(CONFIG.storageKey);
      const state = JSON.parse(data);

      // Only restore if it's recent (within 10 seconds) and same page
      const isRecent = (Date.now() - state.timestamp) < 10000;
      const samePage = state.url === window.location.pathname;

      if (isRecent && samePage) {
        return state.sourceRef;
      }
      return null;
    } catch (e) {
      console.warn('Failed to get editing state:', e);
      return null;
    }
  }

  /**
   * Check for saved editing state and restore editor on page load
   */
  function checkAndRestoreEditor() {
    const sourceRef = getAndClearEditingState();
    if (sourceRef) {
      // Delay slightly to let page render
      setTimeout(() => {
        openEditorWithSourceRef(sourceRef);
      }, 300);
    }
  }

  /**
   * Listen for messages from the background script
   */
  chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    if (request.action === 'refine-open-editor') {
      openEditor();
    }
  });

  /**
   * Initialize the editor for the last clicked element
   */
  function openEditor() {
    if (!clickedElement) {
      showNotification('No element selected. Right-click an element first.', 'error');
      return;
    }

    // Find the nearest element with a source reference
    const sourceElement = findNearestSourceElement(clickedElement);

    if (!sourceElement) {
      showNotification('No source reference found. Is Refine enabled in Laravel?', 'error');
      return;
    }

    const sourceRef = sourceElement.getAttribute(CONFIG.sourceAttribute);
    openEditorWithSourceRef(sourceRef);
  }

  /**
   * Open editor with a specific source reference (used for restoring state)
   */
  function openEditorWithSourceRef(sourceRef) {
    // Fetch the source code from Laravel
    fetchSource(sourceRef)
      .then(data => {
        renderEditor(sourceRef, data);
      })
      .catch(error => {
        showNotification('Failed to load source: ' + error.message, 'error');
      });
  }

  /**
   * Traverse up the DOM to find the nearest element with a data-source attribute
   */
  function findNearestSourceElement(element) {
    let current = element;
    let depth = 0;

    while (current && depth < CONFIG.maxTraversalDepth) {
      if (current.hasAttribute && current.hasAttribute(CONFIG.sourceAttribute)) {
        return current;
      }

      current = current.parentElement;
      depth++;
    }

    return null;
  }

  /**
   * Fetch source code from the Laravel API
   */
  async function fetchSource(sourceRef) {
    const url = `${CONFIG.apiBaseUrl}/fetch?ref=${encodeURIComponent(sourceRef)}`;

    const response = await fetch(url, {
      method: 'GET',
      headers: {
        'Accept': 'application/json',
      },
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    const json = await response.json();

    if (!json.success) {
      throw new Error(json.error || 'Unknown error');
    }

    return json.data;
  }

  /**
   * Save updated source code back to Laravel
   */
  async function saveSource(sourceRef, contents) {
    const url = `${CONFIG.apiBaseUrl}/save`;

    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        ref: sourceRef,
        contents: contents,
      }),
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    const json = await response.json();

    if (!json.success) {
      throw new Error(json.error || 'Unknown error');
    }

    return json;
  }

  /**
   * Render the floating editor UI
   */
  async function renderEditor(sourceRef, data) {
    // Close any existing editor
    await closeEditor();

    // Inject animation styles
    injectAnimationStyles();

    // Create the editor container (positioned at bottom with padding)
    const editor = document.createElement('div');
    editor.id = 'refine-editor';
    editor.style.cssText = `
      position: fixed;
      bottom: 7px;
      left: 7px;
      right: 7px;
      height: calc(55vh - 7px);
      background: #1a1a1c;
      z-index: 999999;
      display: flex;
      flex-direction: column;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      box-shadow: 0 8px 40px rgba(0, 0, 0, 0.45), 0 2px 12px rgba(0, 0, 0, 0.3);
      border-radius: 7px;
      transform: translateY(calc(100% + 20px));
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, 0.08);
    `;

    // Create the header
    const header = document.createElement('div');
    header.style.cssText = `
      background: #2a2a2c;
      color: #ffffff;
      padding: 7px;
      padding-left:15px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      min-height: 20px;
      flex-shrink: 0;
    `;

    // Left side of header (traffic lights + title)
    const headerLeft = document.createElement('div');
    headerLeft.style.cssText = `
      display: flex;
      align-items: center;
      gap: 14px;
    `;

    // Traffic light buttons
    const trafficLights = document.createElement('div');
    trafficLights.style.cssText = `
      display: flex;
      align-items: center;
      gap: 8px;
    `;

    // Red button (close)
    const redButton = document.createElement('button');
    redButton.style.cssText = `
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: #ff5f57;
      border: none;
      cursor: pointer;
      padding: 0;
      outline: none;
      transition: all 0.15s ease;
      box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.12);
    `;
    redButton.onmouseover = () => {
      redButton.style.background = '#ff4136';
      redButton.style.transform = 'scale(1.1)';
    };
    redButton.onmouseout = () => {
      redButton.style.background = '#ff5f57';
      redButton.style.transform = 'scale(1)';
    };

    // Yellow button (minimize)
    const yellowButton = document.createElement('button');
    yellowButton.style.cssText = `
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: #febc2e;
      border: none;
      cursor: pointer;
      padding: 0;
      outline: none;
      box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.12);
      transition: all 0.15s ease;
    `;
    yellowButton.onmouseover = () => {
      yellowButton.style.background = '#f5a623';
      yellowButton.style.transform = 'scale(1.1)';
    };
    yellowButton.onmouseout = () => {
      yellowButton.style.background = '#febc2e';
      yellowButton.style.transform = 'scale(1)';
    };

    // Green button (maximize)
    const greenButton = document.createElement('button');
    greenButton.style.cssText = `
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: #28c840;
      border: none;
      cursor: pointer;
      padding: 0;
      outline: none;
      box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.12);
      transition: all 0.15s ease;
    `;
    greenButton.onmouseover = () => {
      greenButton.style.background = '#1db954';
      greenButton.style.transform = 'scale(1.1)';
    };
    greenButton.onmouseout = () => {
      greenButton.style.background = '#28c840';
      greenButton.style.transform = 'scale(1)';
    };

    trafficLights.appendChild(redButton);
    trafficLights.appendChild(yellowButton);
    trafficLights.appendChild(greenButton);

    // Title
    const title = document.createElement('div');
    title.style.cssText = `
      font-size: 12px;
      font-weight: 500;
      color: #8e8e93;
      letter-spacing: -0.01em;
    `;
    title.textContent = `${data.view_path}`;

    // Line number badge
    const lineBadge = document.createElement('span');
    lineBadge.style.cssText = `
      font-size: 10px;
      font-weight: 600;
      color: #636366;
      background: rgba(255, 255, 255, 0.06);
      padding: 2px 6px;
      border-radius: 4px;
      margin-left: 8px;
      letter-spacing: 0.02em;
    `;
    lineBadge.textContent = `Line ${data.line_number}`;
    title.appendChild(lineBadge);

    headerLeft.appendChild(trafficLights);
    headerLeft.appendChild(title);

    // Right side of header (save button)
    const headerButtons = document.createElement('div');
    headerButtons.style.cssText = `
      display: flex;
      align-items: center;
      gap: 8px;
    `;

    // Save button
    const saveButton = document.createElement('button');
    saveButton.textContent = 'Save';
    saveButton.style.cssText = `
      background: rgba(255, 255, 255, 0.08);
      color: #ffffff;
      border: none;
      padding: 5px 14px;
      border-radius: 6px;
      cursor: pointer;
      font-size: 12px;
      font-weight: 500;
      transition: all 0.15s ease;
      letter-spacing: -0.01em;
    `;
    saveButton.onmouseover = () => {
      saveButton.style.background = 'rgba(255, 255, 255, 0.14)';
    };
    saveButton.onmouseout = () => {
      saveButton.style.background = 'rgba(255, 255, 255, 0.08)';
    };

    headerButtons.appendChild(saveButton);
    header.appendChild(headerLeft);
    header.appendChild(headerButtons);

    // Create iframe for Monaco editor
    const iframe = document.createElement('iframe');
    iframe.id = 'refine-editor-textarea';
    iframe.src = chrome.runtime.getURL('monaco-editor.html');
    iframe.style.cssText = `
      flex: 1;
      background: #1a1a1c;
      border: none;
      overflow: hidden;
    `;

    // Create the footer with file info
    const footer = document.createElement('div');
    footer.style.cssText = `
      background: #2a2a2c;
      color: #636366;
      padding: 8px 16px;
      font-size: 11px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-shrink: 0;
      letter-spacing: -0.01em;
    `;

    const footerLeft = document.createElement('span');
    footerLeft.textContent = data.file_path;

    const footerRight = document.createElement('span');
    footerRight.style.cssText = `
      color: #48484a;
    `;
    footerRight.textContent = `${data.total_lines} lines`;

    footer.appendChild(footerLeft);
    footer.appendChild(footerRight);

    // Assemble the editor
    editor.appendChild(header);
    editor.appendChild(iframe);
    editor.appendChild(footer);

    // Append to body
    document.body.appendChild(editor);
    currentEditor = editor;

    // Trigger slide-up animation after element is in DOM
    // Use requestAnimationFrame to ensure the browser has rendered the initial state
    requestAnimationFrame(() => {
      // Add the animation class to trigger CSS animation
      editor.classList.add('refine-editor-enter');
    });

    // Track editor ready state
    let editorReady = false;

    // Store original height for restore
    const originalHeight = 'calc(55vh - 7px)';
    const minimizedHeight = header.offsetHeight + 'px';

    // Minimize function
    const minimizeEditor = () => {
      if (isMinimized) return;
      isMinimized = true;

      // Set transition and animate to header height
      editor.style.transition = 'height 0.25s cubic-bezier(0.4, 0, 0.2, 1)';
      editor.style.height = minimizedHeight;

      // Hide content after animation starts
      setTimeout(() => {
        iframe.style.display = 'none';
        footer.style.display = 'none';
      }, 50);
    };

    // Restore from minimized
    const restoreEditor = () => {
      if (!isMinimized) return;
      isMinimized = false;

      // Show content first
      iframe.style.display = '';
      footer.style.display = '';

      // Animate back to original height
      editor.style.transition = 'height 0.25s cubic-bezier(0.4, 0, 0.2, 1)';

      // Use maximized height if maximized, otherwise original
      if (isMaximized) {
        editor.style.height = 'calc(100vh - 6px)';
      } else {
        editor.style.height = originalHeight;
      }

      // Focus the editor after restoring
      setTimeout(() => {
        iframe.contentWindow.postMessage({ type: 'FOCUS' }, '*');
      }, 100);
    };

    // Maximize/restore function
    const toggleMaximize = () => {
      // If minimized, restore first
      if (isMinimized) {
        restoreEditor();
        return;
      }

      isMaximized = !isMaximized;
      editor.style.transition = 'all 0.25s cubic-bezier(0.4, 0, 0.2, 1)';

      if (isMaximized) {
        editor.style.height = 'calc(100vh - 6px)';
        editor.style.bottom = '3px';
        editor.style.left = '3px';
        editor.style.right = '3px';
      } else {
        editor.style.height = originalHeight;
        editor.style.bottom = '7px';
        editor.style.left = '7px';
        editor.style.right = '7px';
      }
    };

    // Set up message listener for iframe communication
    messageHandler = (event) => {
      if (event.source !== iframe.contentWindow) return;

      const { type, payload } = event.data;

      if (type === 'EDITOR_READY') {
        editorReady = true;
      } else if (type === 'SAVE') {
        saveButton.click();
      } else if (type === 'ESCAPE') {
        if (isMaximized) {
          // If maximized, restore to half view
          toggleMaximize();
        } else if (!isMinimized) {
          // If not maximized and not minimized, minimize
          minimizeEditor();
        }
      } else if (type === 'VALUE_RESPONSE') {
        // Handle the value response for save operations
        if (window.refineEditorCallback) {
          window.refineEditorCallback(payload.value);
          window.refineEditorCallback = null;
        }
      }
    };

    window.addEventListener('message', messageHandler);

    // Escape key handler - exit maximize first, then minimize
    const escapeHandler = (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        if (isMaximized) {
          // If maximized, restore to half view
          toggleMaximize();
        } else if (!isMinimized) {
          // If not maximized and not minimized, minimize
          minimizeEditor();
        }
      }
    };
    document.addEventListener('keydown', escapeHandler);
    editor.escapeHandler = escapeHandler;

    // Click outside handler - minimize the editor
    const clickOutsideHandler = (e) => {
      if (!editor.contains(e.target) && !isMinimized) {
        minimizeEditor();
      }
    };
    document.addEventListener('mousedown', clickOutsideHandler);
    editor.clickOutsideHandler = clickOutsideHandler;

    // Initialize Monaco editor in iframe once it's loaded
    iframe.onload = () => {
      iframe.contentWindow.postMessage({
        type: 'INIT_EDITOR',
        payload: {
          value: data.full_contents,
          language: 'html',
          theme: 'vs-dark',
          fontSize: 14,
          lineHeight: 24,
          minimap: true,
          lineNumber: data.line_number
        }
      }, '*');
    };

    // Helper function to get editor value
    const getEditorValue = () => {
      return new Promise((resolve) => {
        if (!editorReady) {
          showNotification('Editor not ready yet', 'error');
          resolve(null);
          return;
        }

        window.refineEditorCallback = resolve;
        iframe.contentWindow.postMessage({ type: 'GET_VALUE' }, '*');
      });
    };

    // Header click to restore from minimized
    header.onclick = (e) => {
      // Don't trigger if clicking on traffic lights or save button
      if (e.target === redButton || e.target === yellowButton ||
          e.target === greenButton || e.target === saveButton ||
          trafficLights.contains(e.target) || headerButtons.contains(e.target)) {
        return;
      }
      if (isMinimized) {
        restoreEditor();
      }
    };

    // Update header cursor when minimized
    header.style.cursor = 'default';

    // Event handlers
    redButton.onclick = (e) => {
      e.stopPropagation();
      closeEditor();
    };

    yellowButton.onclick = (e) => {
      e.stopPropagation();
      if (isMinimized) {
        restoreEditor();
      } else {
        minimizeEditor();
      }
    };

    greenButton.onclick = (e) => {
      e.stopPropagation();
      toggleMaximize();
    };

    saveButton.onclick = async () => {
      const newContents = await getEditorValue();
      if (!newContents) return;

      saveButton.disabled = true;
      saveButton.textContent = 'Saving...';
      saveButton.style.opacity = '0.6';

      saveSource(sourceRef, newContents)
        .then(() => {
          showNotification('Saved successfully!', 'success');
          saveButton.disabled = false;
          saveButton.textContent = 'Save';
          saveButton.style.opacity = '1';

          // Save the editing state so we can restore after reload
          saveEditingState(sourceRef);

          // Force hard reload to bypass cache
          setTimeout(() => {
            hardReload();
          }, 500);
        })
        .catch(error => {
          showNotification('Save failed: ' + error.message, 'error');
          saveButton.disabled = false;
          saveButton.textContent = 'Save';
          saveButton.style.opacity = '1';
        });
    };
  }

  /**
   * Close the current editor with animation
   */
  function closeEditor() {
    return new Promise((resolve) => {
      if (!currentEditor) {
        resolve();
        return;
      }

      // Reset state
      isMinimized = false;
      isMaximized = false;

      const editorToClose = currentEditor;

      // Remove escape key handler
      if (editorToClose.escapeHandler) {
        document.removeEventListener('keydown', editorToClose.escapeHandler);
      }

      // Remove click outside handler
      if (editorToClose.clickOutsideHandler) {
        document.removeEventListener('mousedown', editorToClose.clickOutsideHandler);
      }

      // Remove message handler
      if (messageHandler) {
        window.removeEventListener('message', messageHandler);
        messageHandler = null;
      }

      // Clear current editor reference immediately to prevent double-close
      currentEditor = null;

      // Remove enter animation class and add exit animation class
      editorToClose.classList.remove('refine-editor-enter');
      editorToClose.classList.add('refine-editor-exit');

      // Wait for animation to complete before removing element
      editorToClose.addEventListener('animationend', () => {
        editorToClose.remove();
        resolve();
      }, { once: true });

      // Fallback: remove after animation duration if animationend doesn't fire
      setTimeout(() => {
        if (editorToClose.parentNode) {
          editorToClose.remove();
          resolve();
        }
      }, 400);
    });
  }

  /**
   * Force a hard reload that bypasses browser cache
   */
  function hardReload() {
    // Clear browser cache for this page using multiple methods

    // Method 1: Delete service worker cache if present
    if ('caches' in window) {
      caches.keys().then(names => {
        names.forEach(name => caches.delete(name));
      });
    }

    // Method 2: Add cache-busting parameter and force reload
    const url = new URL(window.location.href);

    // Remove any existing refine reload parameter
    url.searchParams.delete('_refine_reload');

    // Add new timestamp
    url.searchParams.set('_refine_reload', Date.now());

    // Method 3: Use location.replace to bypass history
    window.location.replace(url.toString());
  }

  /**
   * Show a notification to the user
   */
  function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.style.cssText = `
      position: fixed;
      top: 20px;
      right: 20px;
      background: ${type === 'error' ? '#ff4444' : type === 'success' ? '#44ff44' : '#3794ff'};
      color: ${type === 'success' ? '#000' : '#fff'};
      padding: 12px 20px;
      border-radius: 6px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
      font-family: system-ui, -apple-system, sans-serif;
      font-size: 14px;
      font-weight: 500;
      z-index: 9999999;
      max-width: 400px;
      animation: refine-slide-in 0.3s ease-out;
    `;
    notification.textContent = message;

    // Add animation keyframes
    if (!document.getElementById('refine-notification-styles')) {
      const style = document.createElement('style');
      style.id = 'refine-notification-styles';
      style.textContent = `
        @keyframes refine-slide-in {
          from {
            transform: translateX(400px);
            opacity: 0;
          }
          to {
            transform: translateX(0);
            opacity: 1;
          }
        }
      `;
      document.head.appendChild(style);
    }

    document.body.appendChild(notification);

    // Auto-remove after 3 seconds
    setTimeout(() => {
      notification.style.opacity = '0';
      notification.style.transform = 'translateX(400px)';
      notification.style.transition = 'all 0.3s ease-out';
      setTimeout(() => notification.remove(), 300);
    }, 3000);
  }

  /**
   * Track the last clicked element
   */
  document.addEventListener('mousedown', (e) => {
    // Only track right-clicks
    if (e.button === 2) {
      clickedElement = e.target;
    }
  }, true);

  /**
   * Prevent the context menu from interfering with element tracking
   */
  document.addEventListener('contextmenu', (e) => {
    clickedElement = e.target;
  }, true);

  // Log that Refine is active
  console.log('Refine: Content script loaded and ready');

  // Check for saved editing state and restore editor if needed
  checkAndRestoreEditor();
})();
