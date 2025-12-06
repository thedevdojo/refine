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
        'X-CSRF-TOKEN': getCSRFToken(),
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
   * Get the CSRF token from the page (Laravel specific)
   */
  function getCSRFToken() {
    // Try meta tag first
    const metaTag = document.querySelector('meta[name="csrf-token"]');
    if (metaTag) {
      return metaTag.getAttribute('content');
    }

    // Fallback: try to find it in a form
    const tokenInput = document.querySelector('input[name="_token"]');
    if (tokenInput) {
      return tokenInput.value;
    }

    return '';
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
    saveButton.textContent = 'Save';
    saveButton.style.cssText = `
      background: #3794ff;
      color: white;
      border: none;
      padding: 6px 16px;
      border-radius: 4px;
      cursor: pointer;
      font-size: 13px;
      font-weight: 500;
    `;
    saveButton.onmouseover = () => saveButton.style.background = '#2080ff';
    saveButton.onmouseout = () => saveButton.style.background = '#3794ff';

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
    headerButtons.appendChild(cancelButton);
    header.appendChild(title);
    header.appendChild(headerButtons);

    // Create the textarea
    const textarea = document.createElement('textarea');
    textarea.id = 'refine-editor-textarea';
    textarea.value = data.full_contents;
    textarea.style.cssText = `
      flex: 1;
      background: #1e1e1e;
      color: #d4d4d4;
      border: none;
      padding: 16px;
      font-size: 14px;
      font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
      line-height: 1.6;
      resize: none;
      outline: none;
      tab-size: 4;
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
    editor.appendChild(textarea);
    editor.appendChild(footer);
    overlay.appendChild(editor);

    // Event handlers
    saveButton.onclick = () => {
      const newContents = textarea.value;
      saveButton.disabled = true;
      saveButton.textContent = 'Saving...';
      saveButton.style.background = '#5a5a5a';

      saveSource(sourceRef, newContents)
        .then(() => {
          showNotification('Saved successfully!', 'success');
          closeEditor();

          // Reload the page to show changes
          setTimeout(() => {
            window.location.reload();
          }, 500);
        })
        .catch(error => {
          showNotification('Save failed: ' + error.message, 'error');
          saveButton.disabled = false;
          saveButton.textContent = 'Save';
          saveButton.style.background = '#3794ff';
        });
    };

    cancelButton.onclick = closeEditor;

    // Close on overlay click
    overlay.onclick = (e) => {
      if (e.target === overlay) {
        closeEditor();
      }
    };

    // Keyboard shortcuts
    textarea.onkeydown = (e) => {
      // Cmd/Ctrl + S to save
      if ((e.metaKey || e.ctrlKey) && e.key === 's') {
        e.preventDefault();
        saveButton.click();
      }

      // Escape to cancel
      if (e.key === 'Escape') {
        e.preventDefault();
        closeEditor();
      }

      // Handle tab key for indentation
      if (e.key === 'Tab') {
        e.preventDefault();
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const value = textarea.value;

        textarea.value = value.substring(0, start) + '    ' + value.substring(end);
        textarea.selectionStart = textarea.selectionEnd = start + 4;
      }
    };

    // Append to body
    document.body.appendChild(overlay);
    currentEditor = overlay;

    // Focus the textarea and scroll to the target line
    textarea.focus();
    scrollToLine(textarea, data.line_number);
  }

  /**
   * Scroll the textarea to a specific line number
   */
  function scrollToLine(textarea, lineNumber) {
    const lines = textarea.value.split('\n');
    const targetLine = Math.max(0, lineNumber - 1);

    // Calculate the character position of the target line
    let charPosition = 0;
    for (let i = 0; i < targetLine && i < lines.length; i++) {
      charPosition += lines[i].length + 1; // +1 for newline
    }

    // Set cursor to the beginning of the target line
    textarea.setSelectionRange(charPosition, charPosition);

    // Scroll to make the line visible (approximate)
    const lineHeight = parseInt(getComputedStyle(textarea).lineHeight);
    const scrollPosition = (targetLine - 5) * lineHeight; // Center the line
    textarea.scrollTop = Math.max(0, scrollPosition);
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
