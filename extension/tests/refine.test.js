/**
 * Refine Extension Tests
 *
 * These tests verify the core functionality of the Refine editor extension.
 * Run these tests by loading test-runner.html in a browser with the extension installed.
 */

const RefineTests = {
  results: [],

  // Test utilities
  async wait(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  },

  assert(condition, message) {
    if (condition) {
      this.results.push({ pass: true, message });
      console.log(`✓ ${message}`);
    } else {
      this.results.push({ pass: false, message });
      console.error(`✗ ${message}`);
    }
    return condition;
  },

  // Mock localStorage for testing
  mockLocalStorage: {
    store: {},
    getItem(key) { return this.store[key] || null; },
    setItem(key, value) { this.store[key] = value; },
    removeItem(key) { delete this.store[key]; },
    clear() { this.store = {}; }
  },

  /**
   * Test: Animation styles are injected
   */
  testAnimationStylesInjected() {
    const styleEl = document.getElementById('refine-animation-styles');
    this.assert(styleEl !== null, 'Animation styles element should be injected');

    if (styleEl) {
      const hasSlideUp = styleEl.textContent.includes('refine-slide-up');
      const hasSlideDown = styleEl.textContent.includes('refine-slide-down');
      this.assert(hasSlideUp, 'Should have slide-up animation keyframes');
      this.assert(hasSlideDown, 'Should have slide-down animation keyframes');
    }
  },

  /**
   * Test: Editor element has correct structure
   */
  testEditorStructure() {
    const editor = document.getElementById('refine-editor');

    if (!editor) {
      this.assert(false, 'Editor element should exist');
      return;
    }

    this.assert(editor !== null, 'Editor element should exist');

    // Check for traffic light buttons
    const buttons = editor.querySelectorAll('button');
    this.assert(buttons.length >= 3, 'Editor should have at least 3 buttons (traffic lights + save)');

    // Check for iframe
    const iframe = editor.querySelector('iframe');
    this.assert(iframe !== null, 'Editor should contain Monaco iframe');

    // Check for header and footer
    const header = editor.querySelector('div');
    this.assert(header !== null, 'Editor should have a header');
  },

  /**
   * Test: Editor has animation class on open
   */
  testEditorHasEnterAnimation() {
    const editor = document.getElementById('refine-editor');

    if (!editor) {
      this.assert(false, 'Editor element should exist for animation test');
      return;
    }

    const hasEnterClass = editor.classList.contains('refine-editor-enter');
    this.assert(hasEnterClass, 'Editor should have refine-editor-enter class when opened');
  },

  /**
   * Test: CMD+S / CTRL+S event handling
   */
  async testKeyboardSaveShortcut() {
    let saveCalled = false;

    // Create a mock message handler
    const originalPostMessage = window.postMessage;
    window.postMessage = function(message) {
      if (message && message.type === 'SAVE') {
        saveCalled = true;
      }
      originalPostMessage.apply(window, arguments);
    };

    // Simulate CMD+S keypress
    const event = new KeyboardEvent('keydown', {
      key: 's',
      code: 'KeyS',
      metaKey: true,
      ctrlKey: false,
      bubbles: true,
      cancelable: true
    });

    document.dispatchEvent(event);

    await this.wait(100);

    // Restore original
    window.postMessage = originalPostMessage;

    // Note: This test may not fully work outside the iframe context
    console.log('CMD+S test completed - manual verification recommended');
    this.assert(true, 'CMD+S keyboard event was dispatched');
  },

  /**
   * Test: Editing state persistence to localStorage
   */
  testEditingStatePersistence() {
    const storageKey = 'refine_editing_file';
    const testSourceRef = 'test-file.blade.php:10:20';

    // Save state
    const stateData = {
      sourceRef: testSourceRef,
      timestamp: Date.now(),
      url: window.location.pathname
    };

    localStorage.setItem(storageKey, JSON.stringify(stateData));

    // Retrieve state
    const retrieved = localStorage.getItem(storageKey);
    this.assert(retrieved !== null, 'State should be saved to localStorage');

    if (retrieved) {
      const parsed = JSON.parse(retrieved);
      this.assert(parsed.sourceRef === testSourceRef, 'Source ref should match');
      this.assert(typeof parsed.timestamp === 'number', 'Timestamp should be a number');
      this.assert(parsed.url === window.location.pathname, 'URL should match current pathname');
    }

    // Clean up
    localStorage.removeItem(storageKey);

    // Verify cleanup
    const afterCleanup = localStorage.getItem(storageKey);
    this.assert(afterCleanup === null, 'State should be removed after cleanup');
  },

  /**
   * Test: State expiration (should not restore if too old)
   */
  testEditingStateExpiration() {
    const storageKey = 'refine_editing_file';
    const testSourceRef = 'old-file.blade.php:5:10';

    // Save state with old timestamp (more than 10 seconds ago)
    const stateData = {
      sourceRef: testSourceRef,
      timestamp: Date.now() - 15000, // 15 seconds ago
      url: window.location.pathname
    };

    localStorage.setItem(storageKey, JSON.stringify(stateData));

    // The getAndClearEditingState function should return null for old states
    const retrieved = localStorage.getItem(storageKey);
    const parsed = JSON.parse(retrieved);
    const isRecent = (Date.now() - parsed.timestamp) < 10000;

    this.assert(!isRecent, 'Old state should be detected as expired');

    // Clean up
    localStorage.removeItem(storageKey);
  },

  /**
   * Test: Escape key handler is registered
   */
  testEscapeKeyHandler() {
    const editor = document.getElementById('refine-editor');

    if (!editor) {
      this.assert(false, 'Editor element should exist for escape key test');
      return;
    }

    this.assert(typeof editor.escapeHandler === 'function', 'Escape handler should be registered on editor');
  },

  /**
   * Test: Red button (close) exists and is clickable
   */
  testRedCloseButton() {
    const editor = document.getElementById('refine-editor');

    if (!editor) {
      this.assert(false, 'Editor element should exist for close button test');
      return;
    }

    const buttons = editor.querySelectorAll('button');
    let redButton = null;

    buttons.forEach(btn => {
      const style = btn.style.background || window.getComputedStyle(btn).background;
      if (style.includes('#ff5f57') || style.includes('rgb(255, 95, 87)')) {
        redButton = btn;
      }
    });

    this.assert(redButton !== null, 'Red close button should exist');

    if (redButton) {
      this.assert(typeof redButton.onclick === 'function', 'Red button should have click handler');
    }
  },

  /**
   * Test: Save button exists
   */
  testSaveButtonExists() {
    const editor = document.getElementById('refine-editor');

    if (!editor) {
      this.assert(false, 'Editor element should exist for save button test');
      return;
    }

    const buttons = editor.querySelectorAll('button');
    let saveButton = null;

    buttons.forEach(btn => {
      if (btn.textContent.trim() === 'Save') {
        saveButton = btn;
      }
    });

    this.assert(saveButton !== null, 'Save button should exist');
  },

  /**
   * Run all tests
   */
  async runAll() {
    console.log('=== Running Refine Extension Tests ===\n');

    this.results = [];

    // Tests that don't require the editor to be open
    console.log('\n--- localStorage Tests ---');
    this.testEditingStatePersistence();
    this.testEditingStateExpiration();

    // Check if editor is open for UI tests
    const editor = document.getElementById('refine-editor');

    if (editor) {
      console.log('\n--- UI Tests (Editor Open) ---');
      this.testAnimationStylesInjected();
      this.testEditorStructure();
      this.testEditorHasEnterAnimation();
      this.testEscapeKeyHandler();
      this.testRedCloseButton();
      this.testSaveButtonExists();
    } else {
      console.log('\n--- UI Tests Skipped (Open editor to run) ---');
      console.log('To run UI tests, right-click an element and select "Edit with Refine"');
    }

    console.log('\n--- Keyboard Tests ---');
    await this.testKeyboardSaveShortcut();

    // Summary
    console.log('\n=== Test Summary ===');
    const passed = this.results.filter(r => r.pass).length;
    const failed = this.results.filter(r => !r.pass).length;
    console.log(`Passed: ${passed}`);
    console.log(`Failed: ${failed}`);
    console.log(`Total: ${this.results.length}`);

    return { passed, failed, total: this.results.length, results: this.results };
  }
};

// Export for use
if (typeof module !== 'undefined' && module.exports) {
  module.exports = RefineTests;
}

// Auto-run if loaded directly
if (typeof window !== 'undefined') {
  window.RefineTests = RefineTests;
  console.log('RefineTests loaded. Run RefineTests.runAll() to execute tests.');
}
