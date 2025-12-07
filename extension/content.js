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
  };

  // State
  let clickedElement = null;
  let currentEditor = null;
  let saveTimeout = null;

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

    // Fetch the source code from Laravel
    fetchSource(sourceRef)
      .then(data => {
        renderEditor(clickedElement, sourceRef, data);
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
  function renderEditor(targetElement, sourceRef, data) {
    // Close any existing editor
    closeEditor();

    // Create the editor overlay
    const overlay = document.createElement('div');
    overlay.id = 'refine-editor-overlay';
    overlay.style.cssText = `
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 999999;
      display: flex;
      align-items: center;
      justify-content: center;
    `;

    // Create the editor container
    const editor = document.createElement('div');
    editor.id = 'refine-editor';
    editor.style.cssText = `
      background: #1e1e1e;
      border: 2px solid #3794ff;
      border-radius: 8px;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
      width: 90%;
      max-width: 1200px;
      height: 80%;
      display: flex;
      flex-direction: column;
      font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    `;

    // Create the header
    const header = document.createElement('div');
    header.style.cssText = `
      background: #2d2d2d;
      color: #cccccc;
      padding: 12px 16px;
      border-bottom: 1px solid #3794ff;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-radius: 6px 6px 0 0;
    `;

    const title = document.createElement('div');
    title.style.cssText = `
      font-size: 14px;
      font-weight: 600;
    `;
    title.textContent = `Editing: ${data.view_path} (Line ${data.line_number})`;

    const headerButtons = document.createElement('div');
    headerButtons.style.cssText = `
      display: flex;
      gap: 8px;
    `;

    // Save button
    const saveButton = document.createElement('button');
    saveButton.innerHTML = 'Save <span style="opacity: 0.7; font-size: 11px;">⏎</span>';
    saveButton.style.cssText = `
      background: #3794ff;
      color: white;
      border: none;
      padding: 6px 16px;
      border-radius: 4px;
      cursor: pointer;
      font-size: 13px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 4px;
    `;
    saveButton.onmouseover = () => saveButton.style.background = '#2080ff';
    saveButton.onmouseout = () => saveButton.style.background = '#3794ff';

    // Save & Close button
    const saveCloseButton = document.createElement('button');
    saveCloseButton.textContent = 'Save & Close';
    saveCloseButton.style.cssText = `
      background: #28a745;
      color: white;
      border: none;
      padding: 6px 16px;
      border-radius: 4px;
      cursor: pointer;
      font-size: 13px;
      font-weight: 500;
    `;
    saveCloseButton.onmouseover = () => saveCloseButton.style.background = '#218838';
    saveCloseButton.onmouseout = () => saveCloseButton.style.background = '#28a745';

    // Cancel button
    const cancelButton = document.createElement('button');
    cancelButton.textContent = 'Cancel';
    cancelButton.style.cssText = `
      background: #5a5a5a;
      color: white;
      border: none;
      padding: 6px 16px;
      border-radius: 4px;
      cursor: pointer;
      font-size: 13px;
      font-weight: 500;
    `;
    cancelButton.onmouseover = () => cancelButton.style.background = '#6a6a6a';
    cancelButton.onmouseout = () => cancelButton.style.background = '#5a5a5a';

    headerButtons.appendChild(saveButton);
    headerButtons.appendChild(saveCloseButton);
    headerButtons.appendChild(cancelButton);
    header.appendChild(title);
    header.appendChild(headerButtons);

    // Create iframe for Monaco editor
    const iframe = document.createElement('iframe');
    iframe.id = 'refine-editor-textarea';
    iframe.src = chrome.runtime.getURL('monaco-editor.html');
    iframe.style.cssText = `
      flex: 1;
      background: #1e1e1e;
      border: none;
      overflow: hidden;
    `;

    // Create the footer with file info
    const footer = document.createElement('div');
    footer.style.cssText = `
      background: #2d2d2d;
      color: #888;
      padding: 8px 16px;
      border-top: 1px solid #3a3a3a;
      font-size: 12px;
      border-radius: 0 0 6px 6px;
    `;
    footer.textContent = `${data.file_path} • ${data.total_lines} lines`;

    // Assemble the editor
    editor.appendChild(header);
    editor.appendChild(iframe);
    editor.appendChild(footer);
    overlay.appendChild(editor);

    // Append to body
    document.body.appendChild(overlay);
    currentEditor = overlay;

    // Track editor ready state
    let editorReady = false;

    // Set up message listener for iframe communication
    const messageHandler = (event) => {
      if (event.source !== iframe.contentWindow) return;

      const { type, payload } = event.data;

      if (type === 'EDITOR_READY') {
        editorReady = true;
      } else if (type === 'SAVE') {
        saveButton.click();
      } else if (type === 'CANCEL') {
        closeEditor();
      } else if (type === 'VALUE_RESPONSE') {
        // Handle the value response for save operations
        if (window.refineEditorCallback) {
          window.refineEditorCallback(payload.value);
          window.refineEditorCallback = null;
        }
      }
    };

    window.addEventListener('message', messageHandler);

    // Initialize Monaco editor in iframe once it's loaded
    iframe.onload = () => {
      iframe.contentWindow.postMessage({
        type: 'INIT_EDITOR',
        payload: {
          value: data.full_contents,
          language: 'blade',
          theme: 'vs-dark',
          fontSize: 14,
          lineHeight: 22,
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

    // Event handlers
    saveButton.onclick = async () => {
      const newContents = await getEditorValue();
      if (!newContents) return;

      saveButton.disabled = true;
      saveButton.textContent = 'Saving...';
      saveButton.style.background = '#5a5a5a';

      saveSource(sourceRef, newContents)
        .then(() => {
          showNotification('Saved successfully!', 'success');
          saveButton.disabled = false;
          saveButton.textContent = 'Save';
          saveButton.style.background = '#3794ff';

          // Force hard reload to bypass cache
          setTimeout(() => {
            hardReload();
          }, 500);
        })
        .catch(error => {
          showNotification('Save failed: ' + error.message, 'error');
          saveButton.disabled = false;
          saveButton.textContent = 'Save';
          saveButton.style.background = '#3794ff';
        });
    };

    saveCloseButton.onclick = async () => {
      const newContents = await getEditorValue();
      if (!newContents) return;

      saveCloseButton.disabled = true;
      saveCloseButton.textContent = 'Saving...';
      saveCloseButton.style.background = '#5a5a5a';

      saveSource(sourceRef, newContents)
        .then(() => {
          showNotification('Saved successfully!', 'success');
          closeEditor();

          // Force hard reload to bypass cache
          setTimeout(() => {
            hardReload();
          }, 500);
        })
        .catch(error => {
          showNotification('Save failed: ' + error.message, 'error');
          saveCloseButton.disabled = false;
          saveCloseButton.textContent = 'Save & Close';
          saveCloseButton.style.background = '#28a745';
        });
    };

    cancelButton.onclick = () => {
      window.removeEventListener('message', messageHandler);
      closeEditor();
    };

    // Close on overlay click
    overlay.onclick = (e) => {
      if (e.target === overlay) {
        window.removeEventListener('message', messageHandler);
        closeEditor();
      }
    };
  }

  /**
   * Close the current editor
   */
  function closeEditor() {
    if (currentEditor) {
      currentEditor.remove();
      currentEditor = null;
    }
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
})();
